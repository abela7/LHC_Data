@extends('layouts.access')

@section('title', 'Welcome')

@section('content')
    <section class="access-gate">
        <header class="access-gate-head">
            <p class="access-gate-eyebrow">VHC Catalogue</p>
            <h1>How are you using the app today?</h1>
            <p class="access-gate-lead">Choose a mode. Data entry keeps the interface focused on catalogue and sellable products.</p>
        </header>

        @if (session('status'))
            <div class="access-flash access-flash-success">{{ session('status') }}</div>
        @endif

        <div class="access-gate-options">
            <form method="POST" action="{{ route('access.data-entry') }}" class="access-gate-card">
                @csrf
                <div class="access-gate-card-icon access-gate-card-icon-entry" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 20h9"></path>
                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                    </svg>
                </div>
                <h2>Data entry</h2>
                <p>Brand catalogue, body care catalogue, and sellable products — clean mobile dashboard.</p>
                <button type="submit" class="access-gate-btn access-gate-btn-primary">Continue as data entry</button>
            </form>

            <a href="{{ route('access.admin') }}" class="access-gate-card access-gate-card-link">
                <div class="access-gate-card-icon access-gate-card-icon-admin" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>
                <h2>Admin</h2>
                <p>Full catalogue staging tools, imports, intake, settings, and review queues.</p>
                <span class="access-gate-btn access-gate-btn-secondary">Enter admin mode</span>
            </a>
        </div>
    </section>
@endsection
