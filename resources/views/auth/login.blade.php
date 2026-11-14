@extends('layouts.guest')

@section('title', 'Iniciar sesión · AUDIT PRO')

@section('content')
<form class="login-card" method="POST" action="{{ route('login.attempt') }}">
    @csrf
    <div class="login-mark"><x-icon name="shield" :size="30"/></div>
    <h1>AUDIT PRO</h1>
    <p>Inicia sesión para continuar</p>

    @if ($errors->any())
        <div class="alert">{{ $errors->first() }}</div>
    @endif

    <div class="form-stack">
        <label>Correo electrónico
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="tu@correo.com">
        </label>
        <label>Contraseña
            <input type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
        </label>
        <label class="check"><input type="checkbox" name="remember" value="1"> Mantener la sesión iniciada</label>
    </div>

    <button class="btn primary block" type="submit">Ingresar <x-icon name="chevron-right" :size="17"/></button>
    <a class="back-link" href="{{ route('welcome') }}"><x-icon name="arrow-left" :size="14"/> Volver</a>
</form>
@endsection
