<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\Company;
use App\Services\Audit\ColumnMapper;
use App\Services\Audit\FileWorkspace;
use App\Services\Audit\PayrollAuditEngine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AuditController extends Controller
{
    /** Ejecuta la auditoría con los tres archivos cargados y la homologación en pantalla. */
    public function store(Request $request, Company $company, FileWorkspace $workspace, PayrollAuditEngine $engine,
        CompanyController $companies)
    {
        $this->authorizeCompany($company);
        $mapping = UploadController::rememberState($request, $company, $workspace);

        $period = $company->periods()->find($request->input('period_id'));
        $files = $workspace->files($company);

        if (! $period || in_array(null, $files, true)) {
            return back()->with('error', 'Selecciona un periodo y carga los tres archivos requeridos.');
        }

        if (collect($files)->contains(fn ($f) => ! $f['supported'] || ! $f['headers'])) {
            return back()->with('error', 'Los tres archivos se pueden adjuntar en cualquier formato, pero la auditoría automática actualmente requiere archivos de hoja de cálculo legibles (Excel o CSV). Convierte los archivos no compatibles o déjalos adjuntos para revisión manual.');
        }

        // Guarda las homologaciones antes de comparar (se reutilizan por empresa).
        $company->update(['column_mapping' => $companies->mergeMapping($company, $mapping)]);

        $mapFor = function (string $slot) use ($files, $mapping, $company) {
            return (array) ($mapping[$slot] ?? ColumnMapper::suggest($files[$slot]['headers'], $company->column_mapping ?? []));
        };

        foreach (array_keys(FileWorkspace::SLOTS) as $slot) {
            $assigned = array_filter($mapFor($slot));
            if (! in_array('employeeId', $assigned, true) || ! in_array('concept', $assigned, true)) {
                return back()->with('error', 'Cada archivo debe tener homologadas al menos las columnas "Identificación del empleado" y "Concepto" ('.FileWorkspace::SLOTS[$slot]['short'].').');
            }
        }

        try {
            $mainRows = ColumnMapper::apply($workspace->rows($files['main']), $mapFor('main'));
            $comparisons = collect(['compare1', 'compare2'])->map(fn ($slot) => [
                'name' => $files[$slot]['name'],
                'rows' => ColumnMapper::apply($workspace->rows($files[$slot]), $mapFor($slot)),
            ])->all();

            $findings = $engine->run($mainRows, $files['main']['name'], $comparisons);
            $summary = PayrollAuditEngine::summarize($findings);

            $audit = DB::transaction(function () use ($request, $company, $period, $files, $findings, $summary) {
                $audit = new Audit([
                    'company_id' => $company->id,
                    'period_id' => $period->id,
                    'company_name' => $company->name,
                    'period_label' => $period->label,
                    'files' => collect($files)->map(fn ($f) => ['name' => $f['name'], 'rows' => $f['rows']])->all(),
                    ...$summary,
                ]);
                $audit->user()->associate($request->user());
                $audit->save();

                $now = now();
                foreach (array_chunk($findings, 500) as $chunk) {
                    $audit->findings()->insert(array_map(fn ($f) => [
                        ...$f,
                        'employee' => Str::limit($f['employee'], 250, ''),
                        'concept' => Str::limit($f['concept'], 250, ''),
                        'audit_id' => $audit->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk));
                }

                return $audit;
            });
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Error al ejecutar la auditoría: '.$e->getMessage());
        }

        return redirect()->route('audits.show', $audit)
            ->with('notice', "Auditoría finalizada: {$summary['total']} hallazgos identificados.");
    }

    public function show(Request $request, Audit $audit)
    {
        $this->authorizeAudit($audit);

        $filter = $request->query('filter', 'all');
        $search = ColumnMapper::normalize($request->query('q', ''));

        $all = $audit->findings()->get();
        $items = $all->filter(function ($f) use ($filter, $search) {
            $matchesFilter = match ($filter) {
                'differences' => $f->difference != 0,
                'review' => $f->status === 'Revisión manual',
                default => true,
            };
            $matchesSearch = $search === ''
                || str_contains(ColumnMapper::normalize($f->employee), $search)
                || str_contains(ColumnMapper::normalize($f->employee_id), $search)
                || str_contains(ColumnMapper::normalize($f->concept), $search);

            return $matchesFilter && $matchesSearch;
        });

        return view('audits.show', [
            'audit' => $audit,
            'items' => $items,
            'counts' => [
                'total' => $all->count(),
                'differences' => $all->where('difference', '!=', 0)->count(),
                'review' => $all->where('status', 'Revisión manual')->count(),
            ],
            'filter' => $filter,
        ]);
    }

    public function history(Request $request)
    {
        return view('audits.history', ['audits' => $request->user()->audits()->get()]);
    }

    public function pdf(Audit $audit)
    {
        $this->authorizeAudit($audit);

        $pdf = Pdf::loadView('audits.pdf', [
            'audit' => $audit,
            'findings' => $audit->findings()->get(),
            'auditor' => $audit->user,
        ])->setPaper('a4', 'landscape');

        $name = 'AUDIT-PRO-'.ColumnMapper::normalize($audit->company_name).'-'.ColumnMapper::normalize($audit->period_label).'.pdf';

        return $pdf->download($name);
    }
}
