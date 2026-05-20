@extends('layouts.app')

@section('title', 'Real Brands')

@section('content')
    @php
        $featuredBrand = $brands->first();
        $rangeQuery = array_filter([
            'picture_from' => $filters['picture_from'],
            'picture_to' => $filters['picture_to'],
        ]);
    @endphp

    <section class="brand-hero">
        <div class="brand-hero-copy">
            <p class="eyebrow">Brand Workspace</p>
            <h2>Real Brand Registry</h2>
            <p class="brand-hero-note">Clean brand layer for review before scraper work.</p>
            <div class="brand-hero-tags">
                <span class="pill">Sorted by product coverage</span>
                <span class="pill">Built from shop photos</span>
                <span class="pill">Variant names preserved</span>
                @if ($rangeQuery !== [])
                    <span class="pill">{{ $filters['picture_from'] !== '' ? $filters['picture_from'] : 'start' }} to {{ $filters['picture_to'] !== '' ? $filters['picture_to'] : 'end' }}</span>
                @endif
            </div>
        </div>
        <aside class="brand-hero-panel">
            <p class="helper-title">Current Focus</p>
            @if ($featuredBrand)
                <h3>{{ $featuredBrand->canonical_brand }}</h3>
                <p class="brand-hero-panel-copy">{{ number_format((int) $featuredBrand->product_count) }} products from {{ number_format((int) $featuredBrand->picture_count) }} pictures.</p>
                <div class="brand-hero-panel-stats">
                    <div>
                        <span>{{ number_format((int) $featuredBrand->product_count) }}</span>
                        <small>Products</small>
                    </div>
                    <div>
                        <span>{{ number_format((int) $featuredBrand->picture_count) }}</span>
                        <small>Pictures</small>
                    </div>
                    <div>
                        <span>{{ number_format((int) $featuredBrand->observed_brand_count) }}</span>
                        <small>Observed Labels</small>
                    </div>
                </div>
                <div class="button-row">
                    <a href="{{ route('real-brands.show', array_merge(['brand' => $featuredBrand->canonical_brand], $rangeQuery)) }}" class="button button-primary">Open {{ $featuredBrand->canonical_brand }}</a>
                    <a href="{{ route('brand-review.index', array_merge(['search' => $featuredBrand->canonical_brand], $rangeQuery)) }}" class="button">Review mapping</a>
                    @if ($featuredBrand->official_source_url)
                        <a href="{{ $featuredBrand->official_source_url }}" target="_blank" rel="noreferrer" class="button">Official site</a>
                    @endif
                </div>
            @else
                <p class="brand-hero-panel-copy">No real brands have been built yet.</p>
            @endif
        </aside>
    </section>

    <section class="stats-grid brand-stats-grid">
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">real brands</p>
            <p class="stat-value">{{ number_format($stats['real_brands']) }}</p>
            <p class="brand-stat-foot">Ready for review.</p>
        </article>
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">unique products</p>
            <p class="stat-value">{{ number_format($stats['products']) }}</p>
            <p class="brand-stat-foot">Distinct products.</p>
        </article>
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">pictures</p>
            <p class="stat-value">{{ number_format($stats['pictures']) }}</p>
            <p class="brand-stat-foot">Source photos.</p>
        </article>
    </section>

    <article class="card brand-toolbar-card">
        <div class="card-head">
            <div>
                <h3>Browse brands</h3>
                <p>{{ $brands->total() }} real brands on this result set</p>
            </div>
            <div class="header-actions">
                <a href="{{ route('brand-review.index', $rangeQuery) }}" class="button">Open brand review</a>
            </div>
        </div>

        <form method="GET" action="{{ route('real-brands.index') }}" class="stack-form">
            <div class="brand-toolbar-grid">
                <label class="brand-search-field">
                    <span>Search real brand</span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search real brand">
                </label>

                <label>
                    <span>Picture from</span>
                    <input type="text" name="picture_from" value="{{ $filters['picture_from'] }}" placeholder="381 or picture381">
                </label>

                <label>
                    <span>Picture to</span>
                    <input type="text" name="picture_to" value="{{ $filters['picture_to'] }}" placeholder="459 or picture459">
                </label>

                <div class="view-switch-wrap">
                    <span>View</span>
                    <div class="view-switch">
                        <a href="{{ route('real-brands.index', array_filter(array_merge(['search' => $filters['search'], 'view' => 'grid'], $rangeQuery))) }}" @class(['view-switch-link', 'is-active' => $viewMode === 'grid'])>Grid</a>
                        <a href="{{ route('real-brands.index', array_filter(array_merge(['search' => $filters['search'], 'view' => 'list'], $rangeQuery))) }}" @class(['view-switch-link', 'is-active' => $viewMode === 'list'])>List</a>
                    </div>
                    <input type="hidden" name="view" value="{{ $viewMode }}">
                </div>
            </div>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Apply filters</button>
                    <a href="{{ route('real-brands.index') }}" class="button">Reset</a>
                </div>
            </form>

        <div class="helper-block">
            <p class="helper-title">Add real brand</p>
            <form method="POST" action="{{ route('real-brands.store') }}" class="stack-form">
                @csrf

                <div class="form-grid">
                    <label>
                        <span>Real brand name</span>
                        <input type="text" name="canonical_brand" value="{{ old('canonical_brand') }}" placeholder="Add a new real brand">
                    </label>

                    <label class="grow">
                        <span>Official site URL</span>
                        <input type="url" name="official_source_url" value="{{ old('official_source_url') }}" placeholder="https://brand.example.com/">
                    </label>
                </div>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Add brand</button>
                </div>
            </form>
        </div>
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
                            <h3>{{ $brand->canonical_brand }}</h3>
                            <p class="brand-card-subtitle">{{ number_format((int) $brand->observed_brand_count) }} observed label{{ (int) $brand->observed_brand_count === 1 ? '' : 's' }} feeding this brand.</p>
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
                            This brand currently includes {{ number_format((int) $brand->line_count) }} line{{ (int) $brand->line_count === 1 ? '' : 's' }} that may matter during scraper setup.
                        @else
                            No line split detected yet. This brand is currently a flat product bucket.
                        @endif
                    </p>

                    <div class="button-row">
                        <a href="{{ route('real-brands.show', array_merge(['brand' => $brand->canonical_brand], $rangeQuery)) }}" class="button button-primary">Open brand</a>
                        <a href="{{ route('brand-review.index', array_merge(['search' => $brand->canonical_brand], $rangeQuery)) }}" class="button">Review mapping</a>
                        @if ($brand->official_source_url)
                            <a href="{{ $brand->official_source_url }}" target="_blank" rel="noreferrer" class="button">Official site</a>
                        @endif
                    </div>
                </article>
            @empty
                <article class="card brand-empty-state">
                    <h3>No real brands found</h3>
                    <p>Try clearing the search or seed brand mappings first.</p>
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
                                    Sorted by product coverage
                                    @if ($brand->official_source_url)
                                                <a href="{{ $brand->official_source_url }}" target="_blank" rel="noreferrer">Official site</a>
                                            @endif
                                        </small>
                                    </div>
                                </td>
                                <td>{{ number_format((int) $brand->product_count) }}</td>
                                <td>{{ number_format((int) $brand->picture_count) }}</td>
                                <td>{{ number_format((int) $brand->observed_brand_count) }}</td>
                                <td>{{ number_format((int) $brand->line_count) }}</td>
                                <td>
                                    <a href="{{ route('real-brands.show', array_merge(['brand' => $brand->canonical_brand], $rangeQuery)) }}" class="button">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">No real brands found.</td>
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
