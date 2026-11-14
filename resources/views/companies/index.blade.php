@extends('layouts.app')

@section('title', 'Empresas')
@section('crumb', 'Empresas')

@section('content')
<section class="page">
    <div class="page-head">
        <div>
            <span class="eyebrow">ADMINISTRACIÓN</span>
            <h1>Empresas registradas</h1>
            <p>Cada empresa conserva sus archivos, periodos, homologaciones e informes.</p>
        </div>
        <button class="btn primary" type="button" onclick="openCompanyModal()"><x-icon name="plus" :size="17"/> Nueva empresa</button>
    </div>

    @if ($companies->count())
        <div class="company-grid">
            @foreach ($companies as $company)
                @include('partials.company-card', ['company' => $company, 'subtitle' => $company->tax_id ?: 'Sin NIT registrado', 'cta' => 'Abrir empresa'])
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
</section>
@endsection
