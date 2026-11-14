<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\Company;

abstract class Controller
{
    /** Cada usuario solo accede a sus propias empresas (datos aislados por usuario). */
    protected function authorizeCompany(Company $company): void
    {
        abort_unless($company->user_id === auth()->id(), 404);
    }

    protected function authorizeAudit(Audit $audit): void
    {
        abort_unless($audit->user_id === auth()->id(), 404);
    }
}
