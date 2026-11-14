<?php

namespace Tests\Unit;

use App\Services\Audit\ColumnMapper;
use App\Services\Audit\PayrollAuditEngine;
use App\Services\Audit\PilaPdfReader;
use App\Services\Audit\SpreadsheetReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Formato por columnas (comprobante de nómina, reporte de SIIGO, planilla PILA).
 * Datos sintéticos.
 */
class ColumnFormatAuditTest extends TestCase
{
    private function workbook(array $sheets): string
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $book->createSheet()->setTitle($title)->fromArray($rows, null, 'A1', true);
        }
        $path = tempnam(sys_get_temp_dir(), 'nom').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    /** Comprobante: títulos, encabezado en dos filas, fila TOTAL, firmas y segunda hoja de prestaciones. */
    private function comprobante(): string
    {
        return $this->workbook([
            'NOMINA.' => [
                [null, null, null, 'EMPRESA DEMO SAS'],
                [null, null, null, 'NIT: 900000001'],
                ['Periodo:', 'FEBRERO', null, 'REMUNERADO', null, null, null, null, 'DESCUENTO', null, null, 'NETO A PAGAR'],
                ['N° DOCUMENTO', 'NOMBRE', 'CARGO', 'Salario básico', 'Días trabajados', 'Total salario', 'Auxilio de transporte', 'Total Remunerado', 'Salud 4%', 'Prestamo', 'Anticipo', null],
                [1001, 'ANA GOMES', 'AUX', 1750905, 30, 1750905, 249095, 2000000, 70036.2, 300000, 100000, 1529927.6],
                [1002, 'LUIS PEREZ', 'GERENTE', 2500000, 30, 2500000, 249095, 2749095, 100000, 0, 0, 2649095],
                ['TOTAL NOMINA', null, null, 4250905, null, 4250905, 498190, 4749095, 170036.2, 300000, 100000, 4179022.6],
                ['Cédula:'],
            ],
            'Provisión Prestaciones' => [
                ['N° DOCUMENTO', 'NOMBRE', 'PRIMA DE SERVICIO', 'VACACIONES'],
                [1001, 'ANA GOMES', 166600, 72954.38],
                [1002, 'LUIS PEREZ', 229000, 104250],
            ],
        ]);
    }

    private function siigo(): string
    {
        return $this->workbook([
            'Reporte Neto a pagar' => [
                ['Nómina detallada por concepto'],
                ['Datos empleados', 'Datos empleados', 'Ingresos', 'Ingresos', 'Ingresos', 'Deducciones', 'Deducciones'],
                ['Nombre', 'Identificación', 'Sueldo', 'Aux. de transporte/Aux. de conectividad digital', 'Total Ingresos', 'Fondo de salud', 'Prestamos', 'Neto a Pagar'],
                ['ANA GOMEZ', '1001', 1750905, 249095, 2000000, 70036, 400000, 1529928],
                ['LUIS PEREZ', '1002', 2450000, 249095, 2699095, 98000, null, 2601095],
                ['PEDRO DIAZ', '1003', 1000000, 0, 1000000, 40000, null, 960000],
            ],
            'Prestaciones Sociales' => [
                ['Nombre', 'Identificación', 'Base Prima', 'Prima', 'Vacaciones'],
                ['ANA GOMEZ', '1001', 2000000, 166600, 73013],
                ['LUIS PEREZ', '1002', 2749095, 229000, 104250],
            ],
        ]);
    }

    private function load(string $path, string $ext = 'xlsx'): array
    {
        $data = app(SpreadsheetReader::class)->read($path, $ext);

        return ColumnMapper::apply($data['rows'], ColumnMapper::suggest($data['headers'], []));
    }

    public function test_reads_payroll_workbook_with_titles_totals_and_several_sheets(): void
    {
        $data = app(SpreadsheetReader::class)->read($this->comprobante(), 'xlsx');

        $this->assertCount(2, $data['rows'], 'La fila TOTAL y las firmas se descartan.');
        $this->assertSame(['NOMINA', 'Provisión Prestaciones'], $data['sheets']);
        $this->assertContains('NETO A PAGAR', $data['headers']);
        $this->assertContains('Provisión Prestaciones · VACACIONES', $data['headers']);

        $mapping = ColumnMapper::suggest($data['headers'], []);
        $this->assertSame('employeeId', $mapping['N° DOCUMENTO']);
        $this->assertSame('c:sueldo', $mapping['Total salario']);
        $this->assertSame('c:salario_base', $mapping['Salario básico']);
        $this->assertSame('c:prestamos', $mapping['Prestamo']);
        $this->assertSame('c:prestamos', $mapping['Anticipo']);
        $this->assertSame('c:neto', $mapping['NETO A PAGAR']);
        $this->assertSame('c:vacaciones', $mapping['Provisión Prestaciones · VACACIONES']);
    }

    public function test_audits_column_format_files(): void
    {
        $findings = (new PayrollAuditEngine)->run($this->load($this->comprobante()), 'comprobante.xlsx', [
            ['name' => 'siigo.xlsx', 'rows' => $this->load($this->siigo()), 'tolerance' => 1],
        ]);

        $byType = collect($findings)->groupBy('type')->map->count()->all();
        $concepts = collect($findings)->where('type', 'difference')->pluck('concept')->sort()->values()->all();

        // Nombre distinto (GOMES/GOMEZ) y empleado que solo está en SIIGO.
        $this->assertSame(1, $byType['name']);
        $this->assertSame(1, $byType['missing_main']);
        // Ana: vacaciones. Luis: sueldo, total devengado, salud y neto.
        // Préstamo + Anticipo (400.000) = Prestamos de SIIGO; el redondeo de 0,2 / 0,4 queda dentro de la tolerancia.
        $this->assertSame(['Neto a pagar', 'Salud (aporte empleado)', 'Sueldo devengado', 'Total devengado', 'Vacaciones'], $concepts);
        $this->assertArrayNotHasKey('missing_compare', $byType, 'Salario básico no existe en SIIGO: no se reporta como faltante.');
    }

    public function test_parses_pila_planilla_text(): void
    {
        $text = <<<'TXT'
                   ANA MARIA GOMEZ
CC 1001                                            01          00                                                0 30 30 30 30 (230301) PORVENIR        $ 1.750.905   $ 280.200 (EPS037) NUEVA EPS            $ 1.750.905     $ 70.100        6,960      $ 1.750.905   $ 121.900 (CCF16) COMFACOR             $ 1.750.905    $ 70.100         $0         $0          $0           $0      $ 542.300
                   SANCHEZ

CC 1002            LUIS PEREZ                      01          00       X                                        0 5 5 5 5 (230301) PORVENIR             $ 291.818     $ 46.700 (EPS041) NUEVA EPS S.A.        $ 291.818      $ 11.700        6,960       $ 291.818     $ 20.400 (CCF16) COMFACOR              $ 291.818     $ 11.700         $0         $0          $0           $0       $ 90.500
TXT;
        $data = (new PilaPdfReader)->parse($text);

        $this->assertCount(2, $data['rows']);
        [$ana, $luis] = $data['rows'];
        $this->assertSame('ANA MARIA GOMEZ SANCHEZ', $ana['Nombre']);
        $this->assertSame(30, $ana['Días cotizados']);
        $this->assertSame(280200, $ana['Aporte Pensión']);
        $this->assertSame(70100, $ana['Aporte Salud']);
        $this->assertSame(121900, $ana['Aporte Riesgos']);
        $this->assertSame(70100, $ana['Aporte Caja']);
        $this->assertSame(0, $ana['Aporte SENA']);
        $this->assertSame(542300, $ana['Total aportes']);
        $this->assertSame('LUIS PEREZ', $luis['Nombre']);
        $this->assertSame(5, $luis['Días cotizados']);
        $this->assertSame(291818, $luis['IBC Salud']);

        $mapping = ColumnMapper::suggest($data['headers'], []);
        $this->assertSame('c:aporte_caja', $mapping['Aporte Caja']);
        $this->assertSame('', $mapping['Administradora caja']);
    }

    public function test_pila_is_compared_with_equivalent_concepts_and_rounding_tolerance(): void
    {
        $main = [
            ['employeeId' => '1001', 'employee' => 'ANA', 'concept' => 'Sueldo devengado', 'value' => 1750905, 'code' => 'sueldo'],
            ['employeeId' => '1001', 'employee' => 'ANA', 'concept' => 'Salud (aporte empleado)', 'value' => 70036.2, 'code' => 'salud_empleado'],
        ];
        $pila = [
            ['employeeId' => '1001', 'employee' => 'ANA', 'concept' => 'IBC salud', 'value' => 1700000, 'code' => 'ibc_salud'],
            ['employeeId' => '1001', 'employee' => 'ANA', 'concept' => 'Aporte total salud', 'value' => 70100, 'code' => 'aporte_salud'],
        ];

        $findings = (new PayrollAuditEngine)->run($main, 'p.xlsx', [['name' => 'pila.pdf', 'rows' => $pila, 'tolerance' => 100]]);

        $this->assertCount(1, $findings);
        $this->assertSame('IBC salud vs. Sueldo devengado', $findings[0]['concept']);
        $this->assertEquals(-50905, $findings[0]['difference']);
    }
}
