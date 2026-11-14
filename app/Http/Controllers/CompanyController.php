<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Audit\ColumnMapper;
use App\Services\Audit\FileWorkspace;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        return view('companies.index', ['companies' => $request->user()->companies()->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validateWithBag('company', [
            'name' => ['required', 'string', 'max:150'],
            'tax_id' => ['nullable', 'string', 'max:50'],
        ], [], ['name' => 'nombre de la empresa', 'tax_id' => 'NIT']);

        $request->user()->companies()->create($data);

        return back()->with('notice', 'Empresa registrada correctamente.');
    }

    /** Espacio de la empresa: periodos, archivos, homologación y ejecución. */
    public function show(Request $request, Company $company, FileWorkspace $workspace)
    {
        $this->authorizeCompany($company);

        $files = $workspace->files($company);
        $saved = $company->column_mapping ?? [];

        // La homologación en edición (old input) tiene prioridad; luego la guardada / sugerida.
        $mappings = [];
        foreach ($files as $slot => $file) {
            if ($file && $file['supported']) {
                $mappings[$slot] = array_merge(
                    ColumnMapper::suggest($file['headers'], $saved),
                    session("mapping_draft.{$company->id}.$slot", [])
                );
            }
        }

        $periods = $company->periods()->get();

        return view('companies.show', [
            'company' => $company,
            'periods' => $periods,
            'selectedPeriod' => (int) ($request->query('period') ?? session("period.{$company->id}") ?? $periods->first()?->id),
            'files' => $files,
            'slots' => FileWorkspace::SLOTS,
            'mappings' => $mappings,
            'fields' => ColumnMapper::FIELDS,
        ]);
    }

    /** Guarda las homologaciones actuales para reutilizarlas en próximos periodos. */
    public function saveMapping(Request $request, Company $company, FileWorkspace $workspace)
    {
        $this->authorizeCompany($company);

        $mapping = UploadController::rememberState($request, $company, $workspace);
        $company->update(['column_mapping' => $this->mergeMapping($company, $mapping)]);

        return back()->with('notice', 'Homologaciones guardadas para esta empresa. Se reutilizarán en próximos periodos.');
    }

    /**
     * Combina la homologación guardada con la enviada por los tres archivos.
     *
     * @return array<string,string>
     */
    public function mergeMapping(Company $company, array $input): array
    {
        $merged = $company->column_mapping ?? [];
        foreach (array_keys(FileWorkspace::SLOTS) as $slot) {
            foreach ((array) ($input[$slot] ?? []) as $header => $field) {
                $merged[(string) $header] = array_key_exists($field, ColumnMapper::FIELDS) ? $field : '';
            }
        }

        return $merged;
    }
}
