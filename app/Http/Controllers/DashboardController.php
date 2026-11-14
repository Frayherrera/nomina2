<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('dashboard', [
            'companies' => $user->companies()->get(),
            'auditCount' => $user->audits()->count(),
            'findingCount' => (int) $user->audits()->sum('total'),
        ]);
    }
}
