@extends('layouts.guest')

@section('content')
<div class="login-card">
    <div class="login-mark"><x-icon name="shield" :size="30"/></div>
    <h1>AUDIT PRO</h1>
    <p>Plataforma inteligente de auditoría empresarial</p>
    <a class="btn primary block" href="{{ route('login') }}">Ingresar de forma segura <x-icon name="chevron-right" :size="17"/></a>
    <small>Acceso privado · Plataforma de auditoría de nómina</small>
</div>
@endsection
