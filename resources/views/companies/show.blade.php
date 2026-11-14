@extends('layouts.app')

@section('title', $company->name)
@section('crumb', $company->name)

@section('content')
@php $loaded = collect($files)->filter()->count(); @endphp
<section class="page">
    <a class="back" href="{{ route('dashboard') }}"><x-icon name="arrow-left" :size="16"/> Volver al panel</a>

    <div class="page-head">
        <div>
            <span class="eyebrow">ESPACIO DE EMPRESA</span>
            <h1>{{ $company->name }}</h1>
            <p>Gestiona los periodos y ejecuta la auditoría de nómina de esta empresa.</p>
        </div>
        <div class="pill"><x-icon name="building" :size="16"/> Espacio aislado</div>
    </div>

    {{-- Formularios auxiliares (los controles viven dentro del formulario principal y los referencian con form="...") --}}
    <form id="period-form" class="aux-form" method="POST" action="{{ route('periods.store', $company) }}">@csrf</form>
    @foreach ($files as $slot => $file)
        @if ($file)
            <form id="remove-{{ $slot }}" class="aux-form" method="POST" action="{{ route('uploads.destroy', [$company, $slot]) }}">@csrf @method('DELETE')</form>
        @endif
    @endforeach

    <form id="workspace" method="POST" action="{{ route('uploads.store', $company) }}" enctype="multipart/form-data">
        @csrf

        <div class="two-col">
            <div class="card">
                <div class="card-head">
                    <div><h2>Periodo de nómina</h2><p>Selecciona o crea el periodo que vas a auditar.</p></div>
                    <span class="card-icon"><x-icon name="calendar"/></span>
                </div>
                <select name="period_id" aria-label="Periodo">
                    <option value="">Seleccionar periodo</option>
                    @foreach ($periods as $period)
                        <option value="{{ $period->id }}" @selected($selectedPeriod === $period->id)>{{ $period->label }}</option>
                    @endforeach
                </select>
                <div class="inline-form">
                    <input type="month" name="period_key" form="period-form" value="{{ old('period_key', now()->format('Y-m')) }}" aria-label="Mes del periodo">
                    <button class="btn soft" type="submit" form="period-form"><x-icon name="plus" :size="15"/> Crear periodo</button>
                </div>
                <div class="muted-line"><x-icon name="clock" :size="15"/> {{ $periods->count() }} periodos registrados</div>
            </div>

            <div class="card">
                <div class="card-head">
                    <div><h2>Homologación inteligente</h2><p>Las equivalencias se conservan por empresa.</p></div>
                    <span class="card-icon"><x-icon name="sparkles"/></span>
                </div>
                <div class="hint"><x-icon name="check" :size="17"/><span>El sistema reconoce encabezados comunes y permite corregir manualmente los desconocidos.</span></div>
                <div class="muted-line"><x-icon name="columns" :size="15"/> {{ count($company->column_mapping ?? []) }} encabezados homologados guardados</div>
                <button class="btn soft block" type="submit" formaction="{{ route('companies.mapping', $company) }}">Guardar homologaciones actuales</button>
            </div>
        </div>

        <div class="section-head">
            <div><h2>Archivos de auditoría</h2><p>El archivo principal será la referencia para contrastar los otros dos.</p></div>
            <span class="badge blue">{{ $loaded }}/3 archivos</span>
        </div>

        <div class="file-grid">
            @foreach ($slots as $slot => $meta)
                @php $file = $files[$slot]; @endphp
                <div class="file-card {{ $file ? ($file['supported'] ? 'ready' : 'pending') : '' }}">
                    <div class="file-card-top"><span>{{ $meta['number'] }}</span><x-icon name="sheet" :size="19"/></div>
                    <h3>{{ $meta['title'] }}</h3>
                    <p>{{ $meta['desc'] }}</p>
                    <label class="dropzone">
                        <x-icon name="upload" :size="20"/>
                        <b>{{ $file ? 'Reemplazar archivo' : 'Seleccionar archivo' }}</b>
                        <small>Excel, CSV, ODS o PDF de planilla PILA</small>
                        <input type="file" name="files[{{ $slot }}]" onchange="uploadFile(this)">
                    </label>
                    @if ($file)
                        <div class="file-status">
                            <x-icon name="{{ $file['supported'] ? 'check' : 'alert' }}" :size="15"/>
                            <span>{{ $file['name'] }}
                                <small>{{ $file['supported']
                                    ? $file['rows'].' filas · '.count($file['headers']).' columnas'.(count($file['sheets'] ?? []) > 1 ? ' · '.count($file['sheets']).' hojas' : '').' · listo para comparar'
                                    : 'Adjunto · extracción automática pendiente' }}</small>
                            </span>
                            <button class="icon-btn small" type="submit" form="remove-{{ $slot }}" title="Retirar archivo" aria-label="Retirar archivo"><x-icon name="trash" :size="14"/></button>
                        </div>
                        @if ($slot !== 'main' && $file['supported'])
                            <label class="tolerance">Tolerancia de redondeo ($)
                                <input type="number" name="tolerance[{{ $slot }}]" value="{{ rtrim(rtrim(number_format($file['tolerance'] ?? 1, 2, '.', ''), '0'), '.') }}" min="0" step="any">
                                <small>Diferencias menores a este valor no se reportan{{ ($file['extension'] ?? '') === 'pdf' ? ' (la PILA redondea aportes a la centena)' : '' }}.</small>
                            </label>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>

        <div class="card">
            <div class="card-head">
                <div><h2>Revisión de columnas</h2><p>Confirma cómo debe interpretar cada encabezado antes de comparar.</p></div>
                <span class="card-icon"><x-icon name="columns"/></span>
            </div>

            @if ($loaded)
                <div class="mapping-grid">
                    @foreach ($files as $slot => $file)
                        @continue(! $file)
                        <div class="mapping-col">
                            <b>{{ $slots[$slot]['short'] }}</b>
                            @if (! $file['supported'])
                                <div class="muted-box">Archivo adjunto sin columnas detectadas. Conviértelo a Excel o CSV para auditarlo.</div>
                            @endif
                            @foreach ($file['headers'] as $i => $header)
                                @php
                                    $current = $mappings[$slot][$header] ?? '';
                                    $sheet = str_contains($header, ' · ') ? \Illuminate\Support\Str::beforeLast($header, ' · ') : null;
                                @endphp
                                @if ($sheet && ($loop->first || ! str_starts_with($file['headers'][$i - 1], $sheet.' · ')))
                                    <div class="mapping-sheet">Hoja: {{ $sheet }}</div>
                                @endif
                                <div class="mapping-row">
                                    <span title="{{ $header }}">{{ \App\Services\Audit\ColumnMapper::baseHeader($header) }}</span>
                                    <select name="fields[{{ $slot }}][{{ $i }}]" class="{{ $current ? 'mapped' : '' }}"
                                            onchange="this.classList.toggle('mapped', !!this.value)" aria-label="Campo para {{ $header }}">
                                        <option value="">No utilizar</option>
                                        @foreach ($options as $group => $groupOptions)
                                            <optgroup label="{{ $group }}">
                                                @foreach ($groupOptions as $value => $label)
                                                    <option value="{{ $value }}" @selected($current === $value)>{{ $label }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @else
                <div class="muted-box">Carga los archivos para configurar las columnas detectadas.</div>
            @endif

            <div class="card-foot">
                <span><x-icon name="save" :size="15"/> La homologación se guarda para esta empresa.</span>
                <button class="btn soft" type="submit" formaction="{{ route('companies.mapping', $company) }}">Guardar equivalencias</button>
            </div>
        </div>

        <div class="run-bar">
            <div>
                <b>¿Todo listo para auditar?</b>
                <p>Se compararán empleados, conceptos, valores y cantidades.</p>
            </div>
            <button class="btn primary" id="run-btn" type="submit" formaction="{{ route('audits.store', $company) }}">
                <x-icon name="sparkles" :size="17"/> Ejecutar auditoría
            </button>
        </div>
    </form>
</section>
@endsection

@push('scripts')
<script>
    const form = document.getElementById('workspace');
    function uploadFile(input) {
        if (!input.files.length) return;
        form.querySelectorAll('input[type=file]').forEach(el => { if (el !== input) el.disabled = true; });
        form.action = @json(route('uploads.store', $company));
        input.closest('.file-card').classList.add('loading');
        form.submit();
    }
    form.addEventListener('submit', e => {
        const target = e.submitter;
        // Los archivos solo se envían al cargarlos, no al guardar o ejecutar.
        if (target && target.formAction && !target.formAction.endsWith('/archivos')) {
            form.querySelectorAll('input[type=file]').forEach(el => el.disabled = true);
        }
        if (target && target.id === 'run-btn') {
            target.disabled = true;
            target.innerHTML = '<svg class="spin" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Analizando archivos…';
            // Mantiene el destino del botón aunque quede deshabilitado.
            form.action = target.formAction;
        }
    });
</script>
@endpush
