@extends('layouts.app')

@section('title', 'Panel principal')
@section('crumb', 'Panel principal')

@section('content')
<section class="page">
    <div class="page-head">
        <div>
            <span class="eyebrow">CENTRO DE CONTROL</span>
            <h1>Panel principal</h1>
            <p>Administra tus empresas y ejecuta auditorías de nómina desde un solo lugar.</p>
        </div>
        <button class="btn primary" type="button" onclick="openCompanyModal()"><x-icon name="plus" :size="17"/> Nueva empresa</button>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="stat-icon teal"><x-icon name="building"/></div>
            <div><small>Empresas registradas</small><strong>{{ str_pad($companies->count(), 2, '0', STR_PAD_LEFT) }}</strong><span>Espacios independientes</span></div>
        </div>
        <div class="stat">
            <div class="stat-icon blue"><x-icon name="clipboard"/></div>
            <div><small>Auditorías ejecutadas</small><strong>{{ str_pad($auditCount, 2, '0', STR_PAD_LEFT) }}</strong><span>Procesos guardados</span></div>
        </div>
        <div class="stat">
            <div class="stat-icon amber"><x-icon name="alert"/></div>
            <div><small>Hallazgos identificados</small><strong>{{ str_pad($findingCount, 2, '0', STR_PAD_LEFT) }}</strong><span>En todos los periodos</span></div>
        </div>
    </div>

    <div class="section-head">
        <div>
            <h2>Empresas</h2>
            <p>Selecciona una empresa para ingresar a su espacio de auditoría.</p>
        </div>
        <a class="btn link" href="{{ route('companies.index') }}">Ver todas <x-icon name="arrow-right" :size="15"/></a>
    </div>

    @if ($companies->count())
        <div class="company-grid">
            @foreach ($companies as $company)
                @include('partials.company-card', ['company' => $company, 'subtitle' => $company->tax_id ? 'NIT / identificación: '.$company->tax_id : 'Identificación pendiente', 'cta' => 'Ingresar al espacio'])
            @endforeach
        </div>
    @else
        <div class="empty">
            <x-icon name="building" :size="30"/>
            <h3>Aún no hay empresas registradas</h3>
            <p>Registra la primera empresa para comenzar a organizar sus periodos y auditorías.</p>
            <button class="btn primary" type="button" onclick="openCompanyModal()"><x-icon name="plus" :size="16"/> Registrar empresa</button>
        </div>
    @endif

    <div class="info-banner">
        <div class="stat-icon teal"><x-icon name="sparkles"/></div>
        <div>
            <b>Motor de auditoría configurable</b>
            <p>Las homologaciones de columnas se guardan por empresa y se pueden reutilizar en periodos posteriores.</p>
        </div>
    </div>
</section>
@endsection
