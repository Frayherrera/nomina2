<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditFlowTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/panel')->assertRedirect('/login');
        $this->get('/')->assertOk()->assertSee('Ingresar de forma segura');
    }

    public function test_login_works(): void
    {
        $user = User::factory()->create(['password' => 'secreto123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'mala'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'secreto123'])->assertRedirect('/panel');
    }

    public function test_full_audit_flow(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post('/empresas', ['name' => 'Empresa ABC S.A.S.', 'tax_id' => '900.123.456-1'])->assertSessionHas('notice');
        $company = Company::firstOrFail();
        $this->get('/panel')->assertSee('Empresa ABC S.A.S.');

        $this->post("/empresas/{$company->id}/periodos", ['period_key' => '2026-01'])->assertRedirect();
        $period = $company->periods()->firstOrFail();
        $this->assertSame('Enero de 2026', $period->label);

        $this->post("/empresas/{$company->id}/archivos", ['files' => ['main' => $this->csv('principal.csv',
            "Cédula,Nombre,Concepto,Valor,Días\n100,Ana,Sueldo,2000000,30\n100,Ana,Auxilio,200000,30\n200,Luis,Sueldo,1500000,30\n")]]);
        // "200.000" en CSV debe leerse como doscientos mil (formato colombiano).
        $this->post("/empresas/{$company->id}/archivos", ['files' => ['compare1' => $this->csv('banco.csv',
            "Documento;Trabajador;Descripción;Importe;Dias\n100;Ana;Sueldo;2.000.000;30\n100;Ana;Auxilio;200.000;30\n200;Luis;Sueldo;1.450.000;30\n")]]);
        // Sin columna de cantidad: no debe generar hallazgos de cantidad.
        $this->post("/empresas/{$company->id}/archivos", ['files' => ['compare2' => $this->csv('pila.csv',
            "Identificación,Empleado,Concepto,Total\n100,Ana,Sueldo,2000000\n100,Ana,Auxilio,200000\n")]]);

        $page = $this->get("/empresas/{$company->id}")->assertOk();
        $page->assertSee('3/3 archivos')->assertSee('3 filas');

        $fields = ['employeeId', 'employee', 'concept', 'value', 'quantity'];
        $response = $this->post("/empresas/{$company->id}/auditorias", [
            'period_id' => $period->id,
            'fields' => ['main' => $fields, 'compare1' => $fields, 'compare2' => array_slice($fields, 0, 4)],
        ]);

        $audit = Audit::firstOrFail();
        $response->assertRedirect("/auditorias/{$audit->id}");

        // banco.csv: diferencia de 50.000 en Luis; pila.csv: Luis no aparece.
        $this->assertSame(2, $audit->total);
        $this->assertSame(1, $audit->differences);
        $this->assertSame(1, $audit->missing);
        $this->assertSame('Cédula', array_search('employeeId', $company->fresh()->column_mapping, true));

        $this->get("/auditorias/{$audit->id}")->assertOk()->assertSee('HALL-0001')->assertSee('Luis');
        $this->get("/auditorias/{$audit->id}?filter=differences")->assertSee('HALL-0001')->assertDontSee('HALL-0002');
        $this->get('/auditorias')->assertSee('Empresa ABC S.A.S.');

        $pdf = $this->get("/auditorias/{$audit->id}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_users_cannot_see_other_users_companies(): void
    {
        $owner = User::factory()->create();
        $company = $owner->companies()->create(['name' => 'Privada']);

        $this->actingAs(User::factory()->create())
            ->get("/empresas/{$company->id}")->assertNotFound();
    }

    public function test_audit_requires_period_and_three_files(): void
    {
        $user = User::factory()->create();
        $company = $user->companies()->create(['name' => 'X']);

        $this->actingAs($user)->post("/empresas/{$company->id}/auditorias", [])
            ->assertSessionHas('error', 'Selecciona un periodo y carga los tres archivos requeridos.');
    }
}
