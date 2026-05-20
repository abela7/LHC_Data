@extends('layouts.app')

@section('title', 'PDF vs Picture Brands')

@section('content')
    <section class="brand-hero">
        <div class="brand-hero-copy">
            <p class="eyebrow">Brand Comparison</p>
            <h2>PDF vs Picture Import Brands</h2>
            <p class="brand-hero-note">Compare the cleaned PDF 1 brand list with the picture-import real brand registry in one place.</p>
            <div class="brand-hero-tags">
                <span class="pill">Source: {{ $pdfSource }}</span>
                <span class="pill">{{ number_format($stats['both']) }} overlap</span>
                <span class="pill">{{ number_format($stats['pdf_only']) }} PDF only</span>
            </div>
        </div>
        <aside class="brand-hero-panel">
            <p class="helper-title">Quick stats</p>
            <div class="brand-hero-panel-stats">
                <div>
                    <span>{{ number_format($stats['picture_brands']) }}</span>
                    <small>Picture brands</small>
                </div>
                <div>
                    <span>{{ number_format($stats['pdf_brands']) }}</span>
                    <small>PDF brands</small>
                </div>
                <div>
                    <span>{{ number_format($stats['both']) }}</span>
                    <small>Shared</small>
                </div>
                <div>
                    <span>{{ number_format($stats['pdf_only']) }}</span>
                    <small>PDF only</small>
                </div>
            </div>
            <div class="button-row">
                <a href="{{ route('real-brands.index') }}" class="button">Open real brands</a>
                <a href="{{ route('pdf-products.index', ['source' => $pdfSource]) }}" class="button">Open PDF products</a>
            </div>
        </aside>
    </section>

    <article class="card brand-toolbar-card">
        <div class="card-head">
            <div>
                <h3>Filter brands</h3>
                <p>Search both lists from one field.</p>
            </div>
        </div>

        <form method="GET" action="{{ route('real-brands.pdf-picture-compare') }}" class="stack-form">
            <div class="brand-toolbar-grid">
                <label class="brand-search-field">
                    <span>Search brand</span>
                    <input type="text" name="search" value="{{ $search }}" placeholder="Search brand">
                </label>
            </div>

            <div class="button-row">
                <button type="submit" class="button button-primary">Apply filter</button>
                <a href="{{ route('real-brands.pdf-picture-compare') }}" class="button">Reset</a>
            </div>
        </form>
    </article>

    <section class="split-grid brand-detail-grid">
        <article class="card compact-card-shell">
            <div class="card-head">
                <h3>Both On PDF And Picture Import</h3>
                <p>{{ number_format($bothBrands->count()) }} brands</p>
            </div>

            @if ($bothBrands->isEmpty())
                <p class="empty-state">No shared brands match the current filter.</p>
            @else
                <div class="catalogue-pill-grid">
                    @foreach ($bothBrands as $brand)
                        <a href="{{ route('real-brands.show', ['brand' => $brand]) }}" class="catalogue-pill-link">
                            <span class="catalogue-pill-title">{{ $brand }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </article>

        <article class="card compact-card-shell">
            <div class="card-head">
                <h3>Only On PDF</h3>
                <p>{{ number_format($pdfOnlyBrands->count()) }} brands</p>
            </div>

            @if ($pdfOnlyBrands->isEmpty())
                <p class="empty-state">No PDF-only brands match the current filter.</p>
            @else
                <div class="catalogue-pill-grid">
                    @foreach ($pdfOnlyBrands as $brand)
                        <div class="catalogue-pill-link is-static">
                            <span class="catalogue-pill-title">{{ $brand }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </article>
    </section>
@endsection
