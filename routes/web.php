<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PeriodController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'welcome'])->name('welcome');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/panel', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/empresas', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('/empresas', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('/empresas/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::post('/empresas/{company}/homologaciones', [CompanyController::class, 'saveMapping'])->name('companies.mapping');

    Route::post('/empresas/{company}/periodos', [PeriodController::class, 'store'])->name('periods.store');

    Route::post('/empresas/{company}/archivos', [UploadController::class, 'store'])->name('uploads.store');
    Route::delete('/empresas/{company}/archivos/{slot}', [UploadController::class, 'destroy'])->name('uploads.destroy');

    Route::post('/empresas/{company}/auditorias', [AuditController::class, 'store'])->name('audits.store');
    Route::get('/auditorias', [AuditController::class, 'history'])->name('audits.history');
    Route::get('/auditorias/{audit}', [AuditController::class, 'show'])->name('audits.show');
    Route::get('/auditorias/{audit}/pdf', [AuditController::class, 'pdf'])->name('audits.pdf');
});
