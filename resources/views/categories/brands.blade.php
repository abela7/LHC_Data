@extends('layouts.app')

@section('title', $category->name.' Brands')

@section('content')
    @php
        $featuredBrand = $brands->first();
        $categoryInitials = collect(explode(' ', $category->name))
            ->filter()
            ->take(2)
            ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');
    @endphp

    <section class="brand-detail-hero brand-page-hero-compact">
        <div class="brand-detail-hero-main">
            <div class="brand-avatar brand-avatar-lg">{{ $categoryInitials }}</div>
            <div class="brand-detail-hero-copy">
                <p class="eyebrow">Category Brands</p>
                <h2>{{ $category->name }} Brands</h2>
                <p class="brand-hero-note">This page shows the cleaned real brands currently sitting inside {{ $category->name }}. Use it to validate the category -> brand split before you move into brand cleanup or scraper setup.</p>
                <div class="brand-hero-tags">
                    <span class="pill">{{ number_format($stats['real_brands']) }} real brands</span>
                    <span class="pill">{{ number_format($stats['products']) }} grouped products</span>
                    <span class="pill">{{ number_format($stats['pictures']) }} pictures</span>
                </div>
            </div>
        </div>

        <aside class="brand-detail-panel brand-detail-panel-compact">
            <p class="helper-title">Quick actions</p>
            <div class="button-row">
                <a href="{{ route('categories.show', ['category' => $category->slug]) }}" class="button">Back to category</a>
                <a href="{{ route('dashboard', ['category' => $category->slug]) }}" class="button">Filter imported rows</a>
                @if ($featuredBrand)
                    <a href="{{ route('real-brands.show', ['brand' => $featuredBrand->canonical_brand]) }}" class="button button-primary">Open top brand</a>
                @endif
            </div>
            <p class="brand-detail-panel-copy">{{ number_format($stats['rows']) }} observation rows currently feed this category brand list.</p>
        </aside>
    </section>

    <section class="stats-grid brand-stats-grid">
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">real brands</p>
            <p class="stat-value">{{ number_format($stats['real_brands']) }}</p>
            <p class="brand-stat-foot">Distinct cleaned brands currently present in {{ $category->name }}.</p>
        </article>
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">products</p>
            <p class="stat-value">{{ number_format($stats['products']) }}</p>
            <p class="brand-stat-foot">Grouped products currently assigned to these brands.</p>
        </article>
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">pictures</p>
            <p class="stat-value">{{ number_format($stats['pictures']) }}</p>
            <p class="brand-stat-foot">Source shop photos currently supporting this category brand split.</p>
        </article>
    </section>

    <article class="card brand-toolbar-card">
        <div class="card-head">
            <div>
                <h3>Browse brands in {{ $category->name }}</h3>
                <p>{{ $brands->total() }} real brands on this result set</p>
            </div>
            <div class="header-actions">
                <a href="{{ route('categories.show', ['category' => $category->slug]) }}" class="button">Open category products</a>
            </div>
        </div>

        <form method="GET" action="{{ route('categories.brands', ['category' => $category->slug]) }}" class="stack-form">
            <div class="brand-toolbar-grid">
                <label class="brand-search-field">
                    <span>Search real brand</span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search real brand">
                </label>

                <div class="view-switch-wrap">
                    <span>View</span>
                    <div class="view-switch">
                        <a href="{{ route('categories.brands', array_filter(['category' => $category->slug, 'search' => $filters['search'], 'view' => 'grid'])) }}" @class(['view-switch-link', 'is-active' => $viewMode === 'grid'])>Grid</a>
                        <a href="{{ route('categories.brands', array_filter(['category' => $category->slug, 'search' => $filters['search'], 'view' => 'list'])) }}" @class(['view-switch-link', 'is-active' => $viewMode === 'list'])>List</a>
                    </div>
                    <input type="hidden" name="view" value="{{ $viewMode }}">
                </div>
            </div>

            <div class="button-row">
                <button type="submit" class="button button-primary">Apply filters</button>
                <a href="{{ route('categories.brands', ['category' => $category->slug]) }}" class="button">Reset</a>
            </div>
        </form>
    </article>

    @if ($viewMode === 'grid')
        <section class="brand-grid">
            @forelse ($brands as $brand)
                @php
                    $brandInitials = collect(preg_split('/\s+/', trim($brand->canonical_brand)) ?: [])
                        ->filter()
                        ->take(2)
                        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
                        ->implode('');
                @endphp

                <article class="brand-card brand-card-featured">
                    <div class="brand-card-top">
                        <div class="brand-avatar">{{ $brandInitials }}</div>
                        <div class="brand-card-heading">
                            <p class="brand-card-kicker">Real brand</p>
                            <h3>
                                <a href="{{ route('real-brands.show', ['brand' => $brand->canonical_brand]) }}" class="product-card-title-link">
                                    {{ $brand->canonical_brand }}
                                </a>
                            </h3>
                            <p class="brand-card-subtitle">{{ number_format((int) $brand->observed_brand_count) }} observed label{{ (int) $brand->observed_brand_count === 1 ? '' : 's' }} feeding this brand inside {{ $category->name }}.</p>
                        </div>
                    </div>

                    <div class="brand-card-stats">
                        <div>
                            <span>{{ number_format((int) $brand->product_count) }}</span>
                            <small>Products</small>
                        </div>
                        <div>
                            <span>{{ number_format((int) $brand->picture_count) }}</span>
                            <small>Pictures</small>
                        </div>
                        <div>
                            <span>{{ number_format((int) $brand->line_count) }}</span>
                            <small>Lines</small>
                        </div>
                    </div>

                    <p class="brand-card-note">
                        @if ((int) $brand->line_count > 0)
                            This brand has {{ number_format((int) $brand->line_count) }} line{{ (int) $brand->line_count === 1 ? '' : 's' }} already showing in {{ $category->name }}.
                        @else
                            No line split detected yet for this brand in {{ $category->name }}.
                        @endif
                    </p>

                    <div class="button-row">
                        <a href="{{ route('real-brands.show', ['brand' => $brand->canonical_brand]) }}" class="button button-primary">Open brand</a>
                        @if ($brand->official_source_url)
                            <a href="{{ $brand->official_source_url }}" target="_blank" rel="noreferrer" class="button">Official site</a>
                        @endif
                    </div>
                </article>
            @empty
                <article class="card brand-empty-state">
                    <h3>No brands found</h3>
                    <p>Try clearing the search or review the category product assignments first.</p>
                </article>
            @endforelse
        </section>
    @else
        <article class="card">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Real brand</th>
                            <th>Products</th>
                            <th>Pictures</th>
                            <th>Observed labels</th>
                            <th>Lines</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($brands as $brand)
                            <tr>
                                <td>
                                    <div class="brand-list-cell">
                                        <strong>{{ $brand->canonical_brand }}</strong>
                                        <small>
                                            Under {{ $category->name }}
                                            @if ($brand->official_source_url)
                                                · <a href="{{ $brand->official_source_url }}" target="_blank" rel="noreferrer">Official site</a>
                                            @endif
                                        </small>
                                    </div>
                                </td>
                                <td>{{ number_format((int) $brand->product_count) }}</td>
                                <td>{{ number_format((int) $brand->picture_count) }}</td>
                                <td>{{ number_format((int) $brand->observed_brand_count) }}</td>
                                <td>{{ number_format((int) $brand->line_count) }}</td>
                                <td>
                                    <a href="{{ route('real-brands.show', ['brand' => $brand->canonical_brand]) }}" class="button">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">No brands found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    @endif

    <div class="pagination-wrap">
        {{ $brands->links() }}
    </div>
@endsection
