<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel') · AUDIT PRO</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=1">
</head>
<body>
@php
    $nav = [
        ['route' => 'dashboard', 'match' => 'dashboard', 'label' => 'Panel principal', 'icon' => 'dashboard'],
        ['route' => 'companies.index', 'match' => 'companies.*', 'label' => 'Empresas', 'icon' => 'building'],
        ['route' => 'audits.history', 'match' => 'audits.*', 'label' => 'Historial de auditorías', 'icon' => 'clipboard'],
    ];
    $user = auth()->user();
@endphp
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-mark"><x-icon name="shield" :size="23"/></div>
            <div><b>AUDIT PRO</b><small>INTELLIGENT AUDIT</small></div>
            <button class="icon-btn mobile-only" type="button" onclick="toggleSidebar(false)" aria-label="Cerrar menú"><x-icon name="x"/></button>
        </div>
        <div class="nav-label">ESPACIO DE TRABAJO</div>
        <nav>
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}" class="nav-item {{ request()->routeIs($item['match']) ? 'active' : '' }}">
                    <x-icon :name="$item['icon']"/> {{ $item['label'] }}
                </a>
            @endforeach
        </nav>
        <div class="sidebar-foot">
            <div class="private-box">
                <x-icon name="lock" :size="17"/>
                <span>Entorno privado<br><b>Datos aislados por usuario</b></span>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout" type="submit"><x-icon name="logout"/> Cerrar sesión</button>
            </form>
        </div>
    </aside>
    <div class="backdrop" onclick="toggleSidebar(false)"></div>

    <main class="main">
        <header class="topbar">
            <button class="icon-btn mobile-only" type="button" onclick="toggleSidebar(true)" aria-label="Abrir menú"><x-icon name="menu"/></button>
            <div class="crumbs">AUDIT PRO <x-icon name="chevron-right" :size="14"/> <span>@yield('crumb', 'Panel principal')</span></div>
            <div class="user-chip">
                <div class="avatar">{{ $user->initials() }}</div>
                <div><b>{{ $user->name }}</b><small>{{ $user->role }}</small></div>
            </div>
        </header>

        @if (session('notice'))
            <div class="toast"><span>{{ session('notice') }}</span><button type="button" onclick="this.parentElement.remove()" aria-label="Cerrar"><x-icon name="x" :size="15"/></button></div>
        @endif
        @if (session('error'))
            <div class="toast error"><span>{{ session('error') }}</span><button type="button" onclick="this.parentElement.remove()" aria-label="Cerrar"><x-icon name="x" :size="15"/></button></div>
        @endif
        @if ($errors->any() && ! $errors->company->any())
            <div class="toast error"><span>{{ $errors->first() }}</span><button type="button" onclick="this.parentElement.remove()" aria-label="Cerrar"><x-icon name="x" :size="15"/></button></div>
        @endif

        @yield('content')
    </main>
</div>

@include('partials.company-modal')

<script>
    function toggleSidebar(open) { document.body.classList.toggle('sidebar-open', open); }
    function openCompanyModal() { document.getElementById('company-modal').classList.add('show'); document.getElementById('company-name').focus(); }
    function closeCompanyModal() { document.getElementById('company-modal').classList.remove('show'); }
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCompanyModal(); });
</script>
@stack('scripts')
</body>
</html>
