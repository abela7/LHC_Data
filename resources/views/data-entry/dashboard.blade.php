@extends('layouts.app')

@section('title', 'Data Entry')
@section('section', 'Data Entry')
@section('heading', 'Dashboard')

@section('content')
    <section class="de-dashboard">
        <header class="de-dashboard-hero">
            <p class="de-dashboard-eyebrow">Data entry</p>
            <h1>What do you want to work on?</h1>
            <p>Pick a workspace below. You can drill into brands, families, styles, and SKUs from each one.</p>
        </header>

        <div class="de-dashboard-grid">
            <a href="{{ route('brand-catalogue.index') }}" class="de-dashboard-card de-dashboard-card-catalogue">
                <span class="de-dashboard-card-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    </svg>
                </span>
                <strong>Brand catalogue</strong>
                <span>Build brands, styles, variants, and SKUs. Publish families to retail.</span>
                <em>Open catalogue</em>
            </a>

            <a href="{{ route('body-care-brand-catalogue') }}" class="de-dashboard-card de-dashboard-card-body">
                <span class="de-dashboard-card-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"></path>
                    </svg>
                </span>
                <strong>Body care catalogue</strong>
                <span>Same catalogue tools, scoped to the body care department.</span>
                <em>Open body care</em>
            </a>

            <a href="{{ route('retail-products.index') }}" class="de-dashboard-card de-dashboard-card-retail">
                <span class="de-dashboard-card-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="9" cy="21" r="1"></circle>
                        <circle cx="20" cy="21" r="1"></circle>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                    </svg>
                </span>
                <strong>Sellable products</strong>
                <span>Prices, barcodes, photos, and SKU operations for the shop floor.</span>
                <em>Open sellable</em>
            </a>
        </div>

        <footer class="de-dashboard-foot">
            <form method="POST" action="{{ route('access.switch') }}">
                @csrf
                <button type="submit" class="de-dashboard-switch">Switch to admin or change mode</button>
            </form>
        </footer>
    </section>
@endsection
