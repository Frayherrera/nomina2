<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Audit\FileWorkspace;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    /**
     * Recibe uno o varios de los tres archivos (files[main], files[compare1], files[compare2]).
     * Conserva como borrador la homologación que el usuario tenía en pantalla.
     */
    public function store(Request $request, Company $company, FileWorkspace $workspace)
    {
        $this->authorizeCompany($company);

        $request->validate([
            'files' => ['required', 'array'],
            'files.*' => ['file', 'max:51200'],
        ], ['files.required' => 'Selecciona un archivo.'], ['files.*' => 'archivo']);

        self::rememberState($request, $company, $workspace);

        $messages = [];
        foreach ($request->file('files', []) as $slot => $file) {
            if (! array_key_exists($slot, FileWorkspace::SLOTS)) {
                continue;
            }
            session()->forget("mapping_draft.{$company->id}.$slot");
            $messages[] = $workspace->store($company, $slot, $file)['message'];
        }

        return redirect()->route('companies.show', $company)->with('notice', implode(' ', $messages));
    }

    public function destroy(Request $request, Company $company, string $slot, FileWorkspace $workspace)
    {
        $this->authorizeCompany($company);
        abort_unless(array_key_exists($slot, FileWorkspace::SLOTS), 404);

        $workspace->remove($company, $slot);
        session()->forget("mapping_draft.{$company->id}.$slot");

        return redirect()->route('companies.show', $company)->with('notice', 'Archivo retirado.');
    }

    /** Guarda en sesión el periodo seleccionado y la homologación en edición. */
    public static function rememberState(Request $request, Company $company, FileWorkspace $workspace): array
    {
        if ($request->filled('period_id')) {
            session(["period.{$company->id}" => (int) $request->input('period_id')]);
        }

        $workspace->rememberTolerances($company, $request);
        $mapping = $workspace->mappingFromRequest($company, $request);
        foreach ($mapping as $slot => $slotMapping) {
            session(["mapping_draft.{$company->id}.$slot" => $slotMapping]);
        }

        return $mapping;
    }
}
