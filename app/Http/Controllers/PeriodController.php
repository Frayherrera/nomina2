<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PeriodController extends Controller
{
    public function store(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $data = $request->validate([
            'period_key' => [
                'required', 'date_format:Y-m',
                Rule::unique('periods')->where('company_id', $company->id),
            ],
        ], ['period_key.unique' => 'Ese periodo ya existe para esta empresa.'], ['period_key' => 'periodo']);

        $label = Carbon::createFromFormat('Y-m-d', $data['period_key'].'-01')
            ->locale('es')->translatedFormat('F \d\e Y');

        $period = $company->periods()->create([
            'period_key' => $data['period_key'],
            'label' => mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1),
        ]);

        session(["period.{$company->id}" => $period->id]);

        return redirect()->route('companies.show', $company)->with('notice', 'Periodo creado.');
    }
}
