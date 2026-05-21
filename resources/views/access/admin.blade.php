@extends('layouts.access')

@section('title', 'Admin access')

@section('content')
    <section class="access-gate access-gate-narrow">
        <header class="access-gate-head">
            <p class="access-gate-eyebrow">Admin mode</p>
            <h1>Enter password</h1>
            <p class="access-gate-lead">This is a simple UI lock — not full security.</p>
        </header>

        @if ($errors->any())
            <div class="access-flash access-flash-error">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('access.admin.submit') }}" class="access-admin-form">
            @csrf
            <label class="access-admin-field">
                <span>Password</span>
                <input type="password" name="password" autocomplete="current-password" required autofocus placeholder="Admin password">
            </label>
            <button type="submit" class="access-gate-btn access-gate-btn-primary access-gate-btn-full">Unlock admin</button>
        </form>

        <p class="access-gate-back">
            <a href="{{ route('access.choose') }}">← Back to mode selection</a>
        </p>
    </section>
@endsection
