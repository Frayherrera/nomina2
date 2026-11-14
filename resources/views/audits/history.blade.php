@extends('layouts.app')

@section('title', 'Historial')
@section('crumb', 'Historial')

@section('content')
<section class="page">
    <div class="page-head">
        <div>
            <span class="eyebrow">TRAZABILIDAD</span>
            <h1>Historial de auditorías</h1>
            <p>Consulta los procesos ejecutados sin volver a cargar los archivos.</p>
        </div>
    </div>

    <div class="card">
        <div class="history">
            @forelse ($audits as $audit)
                <div class="history-row">
                    <div class="stat-icon blue"><x-icon name="file"/></div>
                    <a class="history-info" href="{{ route('audits.show', $audit) }}">
                        <b>{{ $audit->company_name }}</b>
                        <span>{{ $audit->period_label }} · {{ $audit->created_at->format('d/m/Y H:i') }}</span>
                        <small>{{ $audit->total }} hallazgos identificados · {{ $audit->differences }} diferencias · {{ $audit->missing }} faltantes · {{ $audit->duplicates }} duplicados</small>
                    </a>
                    <a class="icon-btn" href="{{ route('audits.pdf', $audit) }}" title="Descargar PDF" aria-label="Descargar PDF"><x-icon name="download" :size="17"/></a>
                </div>
            @empty
                <div class="muted-box">Todavía no hay auditorías guardadas.</div>
            @endforelse
        </div>
    </div>
</section>
@endsection
