@php use App\Models\AuditFinding; @endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe de auditoría</title>
    <style>
        @page { margin: 120px 40px 50px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #3a5560; }
        header { position: fixed; top: -120px; left: -40px; right: -40px; height: 95px; background: #e0f2f6; padding: 0 40px; }
        header .logo { position: absolute; top: 28px; left: 40px; width: 34px; height: 34px; border-radius: 8px; background: #4291b8; color: #fff; font-weight: bold; font-size: 16px; text-align: center; line-height: 34px; }
        header .title { position: absolute; top: 26px; left: 86px; }
        header .title b { font-size: 20px; color: #264e60; display: block; }
        header .title span { font-size: 8px; color: #487c87; letter-spacing: .5px; }
        header .right { position: absolute; top: 40px; right: 40px; font-size: 8px; color: #487c87; }
        footer { position: fixed; bottom: -35px; left: 0; right: 0; height: 20px; border-top: 1px solid #dcebed; padding-top: 5px; font-size: 7.5px; color: #7e959d; }
        footer .page:after { content: "Página " counter(page); }
        h1 { font-size: 18px; color: #274657; margin: 0 0 4px; }
        .meta { color: #68838e; font-size: 8.5px; margin: 0 0 2px; }
        .cards { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 14px -8px 16px; }
        .cards td { background: #fff; border: 1px solid #dcebed; border-radius: 6px; padding: 8px 10px; border-left-width: 4px; }
        .cards small { display: block; font-size: 7px; font-weight: bold; color: #66818d; text-transform: uppercase; }
        .cards strong { font-size: 15px; color: #27495a; }
        .finding { border: 1px solid #dcebed; border-left: 4px solid #439d94; border-radius: 5px; padding: 7px 10px; margin-bottom: 6px; page-break-inside: avoid; }
        .finding b { color: #2b6778; font-size: 9px; }
        .finding table { width: 100%; margin-top: 4px; font-size: 8px; color: #58737e; }
        .finding td { padding: 1px 6px 1px 0; vertical-align: top; }
        .label { color: #8aa0a8; }
        .neg { color: #c0392b; font-weight: bold; }
        .ok { color: #377e71; font-size: 11px; }
    </style>
</head>
<body>
<header>
    <div class="logo">A</div>
    <div class="title"><b>AUDIT PRO</b><span>INFORME DE AUDITORÍA DE NÓMINA</span></div>
    <div class="right">Documento generado por el sistema de auditoría</div>
</header>
<footer>
    AUDIT PRO · Información confidencial
    <span class="page" style="float:right"></span>
</footer>

<h1>{{ $audit->company_name }}</h1>
<p class="meta">Periodo {{ $audit->period_label }} · Ejecutada el {{ $audit->created_at->format('d/m/Y H:i') }}</p>
<p class="meta">Responsable: {{ $auditor?->name }}</p>

<table class="cards">
    <tr>
        <td style="border-left-color:#539db2"><small>Hallazgos</small><strong>{{ $audit->total }}</strong></td>
        <td style="border-left-color:#e2ad66"><small>Diferencias</small><strong>{{ $audit->differences }}</strong></td>
        <td style="border-left-color:#4ca689"><small>Faltantes</small><strong>{{ $audit->missing }}</strong></td>
        <td style="border-left-color:#70a4bc"><small>Duplicados</small><strong>{{ $audit->duplicates }}</strong></td>
    </tr>
</table>

@forelse ($findings as $f)
    <div class="finding">
        <b>{{ $f->code }} · {{ $f->concept }} · {{ $f->employee }}</b>
        <table>
            <tr>
                <td><span class="label">Identificación:</span> {{ $f->employee_id ?: 'No disponible' }}</td>
                <td><span class="label">Archivo:</span> {{ $f->file_name ?: 'No especificado' }}</td>
                <td><span class="label">Estado:</span> {{ $f->status }}</td>
            </tr>
            <tr>
                <td><span class="label">Valor principal:</span> {{ AuditFinding::display($f->main_value) }}</td>
                <td><span class="label">Valor comparado:</span> {{ AuditFinding::display($f->compared_value) }}</td>
                <td><span class="label">Diferencia:</span> <span class="{{ $f->difference ? 'neg' : '' }}">{{ number_format($f->difference, 0, ',', '.') }}</span></td>
            </tr>
            <tr><td colspan="3"><span class="label">Hallazgo:</span> {{ $f->description }}</td></tr>
            <tr><td colspan="3"><span class="label">Acción sugerida:</span> {{ $f->suggestion }}</td></tr>
        </table>
    </div>
@empty
    <p class="ok">No se identificaron diferencias en las reglas ejecutadas.</p>
@endforelse
</body>
</html>
