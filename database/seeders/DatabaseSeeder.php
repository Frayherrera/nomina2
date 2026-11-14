<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Crea (o actualiza) el usuario auditor inicial.
     * Credenciales configurables con AUDITOR_NAME, AUDITOR_EMAIL y AUDITOR_PASSWORD en .env.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => env('AUDITOR_EMAIL', 'auditor@auditpro.test')],
            [
                'name' => env('AUDITOR_NAME', 'Jesús Emiro Pérez'),
                'role' => 'Auditor principal',
                'password' => env('AUDITOR_PASSWORD', 'AuditPro2026!'),
            ]
        );
    }
}
