@extends('layouts.app')

@section('title', 'Resultados')
@section('crumb', 'Resultados')

@php use App\Models\AuditFinding; @endphp

@section('content')
<section class="page">
    <a class="back" href="{{ $audit->company ? route('companies.show', $audit->company) : route('audits.history') }}"><x-icon name="arrow-left" :size="16"/> Volver a la empresa</a>

    <div class="page-head">
        <div>
            <span class="eyebrow">ANÁLISIS COMPLETADO</span>
            <h1>Hallazgos de auditoría</h1>
            <p>{{ $audit->company_name }} · {{ $audit->period_label }} · {{ $audit->created_at->format('d/m/Y H:i') }}</p>
        </div>
        <a class="btn primary" href="{{ route('audits.pdf', $audit) }}"><x-icon name="download" :size="16"/> Exportar PDF</a>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="stat-icon blue"><x-icon name="clipboard"/></div>
            <div><small>Hallazgos</small><strong>{{ $counts['total'] }}</strong></div>
        </div>
        <div class="stat">
            <div class="stat-icon amber"><x-icon name="alert"/></div>
            <div><small>Diferencias de valor</small><strong>{{ $counts['differences'] }}</strong></div>
        </div>
        <div class="stat">
            <div class="stat-icon teal"><x-icon name="clock"/></div>
            <div><small>Revisión manual</small><strong>{{ $counts['review'] }}</strong></div>
        </div>
    </div>

    @if ($audit->files)
        <div class="files-used">
            @foreach ($audit->files as $slot => $file)
                <span class="badge blue"><x-icon name="sheet" :size="13"/> {{ \App\Services\Audit\FileWorkspace::SLOTS[$slot]['short'] ?? $slot }}: {{ $file['name'] }} ({{ $file['rows'] }} filas)</span>
            @endforeach
        </div>
    @endif

    <div class="card">
        <div class="card-head wrap">
            <div><h2>Detalle de hallazgos</h2><p>Todos los casos requieren validación del auditor antes de corregir la nómina.</p></div>
            <form class="search" method="GET">
                <input type="hidden" name="filter" value="{{ $filter }}">
                <x-icon name="search" :size="16"/>
                <input name="q" value="{{ request('q') }}" placeholder="Buscar empleado o concepto" aria-label="Buscar">
            </form>
        </div>

        <div class="filters">
            @foreach (['all' => 'Todos', 'differences' => 'Con diferencia', 'review' => 'Revisión manual'] as $key => $label)
                <a class="filter {{ $filter === $key ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['filter' => $key]) }}">{{ $label }}</a>
            @endforeach
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Hallazgo</th><th>Empleado</th><th>Concepto</th><th class="num">Principal</th><th class="num">Comparado</th><th class="num">Diferencia</th><th>Estado</th></tr>
                </thead>
                <tbody>
                @foreach ($items as $f)
                    <tr>
                        <td class="finding">
                            <b>{{ $f->code }}</b>
                            <small>{{ $f->file_name }}</small>
                            <div class="finding-desc">{{ $f->description }}<br><i>{{ $f->suggestion }}</i></div>
                        </td>
                        <td>{{ $f->employee }}<small>{{ $f->employee_id }}</small></td>
                        <td>{{ $f->concept }}</td>
                        <td class="num">{{ AuditFinding::display($f->main_value) }}</td>
                        <td class="num">{{ AuditFinding::display($f->compared_value) }}</td>
                        <td class="num {{ $f->difference ? 'negative' : '' }}">{{ number_format($f->difference, 0, ',', '.') }}</td>
                        <td><span class="badge amber"><x-icon name="clock" :size="12"/> {{ $f->status }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if ($items->isEmpty())
                <div class="muted-box">{{ $counts['total'] ? 'No hay hallazgos para este filtro.' : 'No se identificaron diferencias en las reglas ejecutadas.' }}</div>
            @endif
        </div>
    </div>
</section>
@endsection
