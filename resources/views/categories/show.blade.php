@extends('layouts.app')

@section('title', $category->name)

@section('content')
    @php
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
                <p class="eyebrow">Category Detail</p>
                <h2>{{ $category->name }}</h2>
                <p class="brand-hero-note">{{ $category->description ?: 'Validate products before subcategory or scraper work.' }}</p>
                <div class="brand-hero-tags">
                    <span class="pill">{{ number_format($stats['products']) }} products</span>
                    <a href="{{ route('categories.brands', ['category' => $category->slug]) }}" class="pill">{{ number_format($stats['real_brands']) }} real brands</a>
                    <span class="pill">{{ number_format($stats['pictures']) }} pictures</span>
                </div>
            </div>
        </div>

        <aside class="brand-detail-panel brand-detail-panel-compact">
            <p class="helper-title">Quick actions</p>
            <div class="button-row">
                <a href="{{ route('categories.index') }}" class="button">Back to categories</a>
                <a href="{{ route('dashboard', ['category' => $category->slug]) }}" class="button button-primary">Filter imported rows</a>
                <a href="{{ route('categories.brands', ['category' => $category->slug]) }}" class="button">Open brands</a>
            </div>
            <p class="brand-detail-panel-copy">{{ number_format($stats['rows']) }} rows currently sit in this category.</p>
        </aside>
    </section>

    <section class="stats-grid brand-stats-grid brand-detail-stats brand-detail-stats-compact">
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">observation rows</p>
            <p class="stat-value">{{ number_format($stats['rows']) }}</p>
            <p class="brand-stat-foot">Rows in this category.</p>
        </article>
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">products</p>
            <p class="stat-value">{{ number_format($stats['products']) }}</p>
            <p class="brand-stat-foot">Grouped products.</p>
        </article>
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">pictures</p>
            <p class="stat-value">{{ number_format($stats['pictures']) }}</p>
            <p class="brand-stat-foot">Source photos.</p>
        </article>
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">real brands</p>
            <p class="stat-value">{{ number_format($stats['real_brands']) }}</p>
            <p class="brand-stat-foot">Real brands.</p>
        </article>
    </section>

    <section class="split-grid brand-detail-grid">
        <article class="card brand-search-card brand-search-card-compact">
            <div class="card-head">
                <h3>Find products in {{ $category->name }}</h3>
                <p>{{ $products->total() }} grouped products on this result set</p>
            </div>

            <form method="GET" action="{{ route('categories.show', ['category' => $category->slug]) }}" class="stack-form">
                <div class="brand-toolbar-grid">
                    <label class="brand-search-field">
                        <span>Search product or brand</span>
                        <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search product or brand">
                    </label>

                    <div class="view-switch-wrap">
                        <span>View</span>
                        <div class="view-switch">
                            <a
                                href="{{ route('categories.show', array_filter(['category' => $category->slug, 'search' => $filters['search'], 'view' => 'grid'])) }}"
                                @class(['view-switch-link', 'is-active' => $viewMode === 'grid'])
                            >
                                Grid
                            </a>
                            <a
                                href="{{ route('categories.show', array_filter(['category' => $category->slug, 'search' => $filters['search'], 'view' => 'list'])) }}"
                                @class(['view-switch-link', 'is-active' => $viewMode === 'list'])
                            >
                                List
                            </a>
                        </div>
                        <input type="hidden" name="view" value="{{ $viewMode }}">
                    </div>
                </div>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Search products</button>
                    <a href="{{ route('categories.show', ['category' => $category->slug]) }}" class="button">Clear</a>
                </div>
            </form>

            <div class="brand-search-note">
                <p>Check whether each grouped product really belongs in {{ $category->name }}.</p>
            </div>
        </article>
    </section>

    <article class="card compact-card-shell">
        <div class="card-head">
            <h3>Products under {{ $category->name }}</h3>
            <p>{{ $products->total() }} products &middot; {{ ucfirst($viewMode) }} view</p>
        </div>

        @if ($viewMode === 'grid')
            <div class="product-grid-compact brand-product-grid">
                @forelse ($products as $product)
                    @php
                        $pictureIds = $product->picture_ids !== '' ? explode(', ', $product->picture_ids) : [];
                        $observedLabels = $product->observed_brands !== '' ? explode(', ', $product->observed_brands) : [];
                        $lines = $product->lines !== '' ? explode(', ', $product->lines) : [];
                        $brandLabel = $product->canonical_brand !== '' ? $product->canonical_brand : '[blank brand]';
                    @endphp

                    <article class="product-card product-card-compact brand-product-card">
                        <div class="product-card-head">
                            <div>
                                <p class="product-card-kicker">Real brand</p>
                                @if ($product->canonical_brand !== '')
                                    <h4>
                                        <a href="{{ route('real-brands.show', ['brand' => $product->canonical_brand]) }}" class="product-card-title-link">
                                            {{ $product->canonical_brand }}
                                        </a>
                                    </h4>
                                @else
                                    <h4>{{ $brandLabel }}</h4>
                                @endif
                            </div>
                            <div class="product-card-stats">
                                <div>
                                    <span>{{ number_format((int) $product->picture_count) }}</span>
                                    <small>Pictures</small>
                                </div>
                                <div>
                                    <span>{{ number_format((int) $product->row_count) }}</span>
                                    <small>Rows</small>
                                </div>
                            </div>
                        </div>

                        <div class="product-chip-block">
                            <p class="product-chip-label">Product name</p>
                            <div class="picture-summary-block">
                                @if ($product->canonical_brand !== '')
                                    <a href="{{ route('real-brands.products.show', ['brand' => $product->canonical_brand, 'name' => $product->product_name]) }}" class="picture-summary-link">
                                        {{ $product->product_name }}
                                    </a>
                                @else
                                    <p class="picture-summary-text">{{ $product->product_name }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="product-chip-block">
                            <p class="product-chip-label">Picture ids</p>
                            <div class="brand-chip-row">
                                @forelse ($pictureIds as $pictureId)
                                    <span class="brand-chip">{{ $pictureId }}</span>
                                @empty
                                    <span class="brand-chip brand-chip-muted">No picture ids</span>
                                @endforelse
                            </div>
                        </div>

                        <div class="product-meta-grid product-meta-grid-compact">
                            <div>
                                <p class="product-chip-label">Observed labels</p>
                                <div class="brand-chip-row">
                                    @forelse ($observedLabels as $label)
                                        <span class="brand-chip brand-chip-soft">{{ $label }}</span>
                                    @empty
                                        <span class="brand-chip brand-chip-muted">No observed labels</span>
                                    @endforelse
                                </div>
                            </div>

                            <div>
                                <p class="product-chip-label">Lines</p>
                                <div class="brand-chip-row">
                                    @forelse ($lines as $line)
                                        <span class="brand-chip">{{ $line }}</span>
                                    @empty
                                        <span class="brand-chip brand-chip-muted">No line split</span>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="brand-empty-state">
                        <h3>No products found</h3>
                        <p>Try clearing the search.</p>
                    </div>
                @endforelse
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Real brand</th>
                            <th>Product name</th>
                            <th>Pictures</th>
                            <th>Rows</th>
                            <th>Picture ids</th>
                            <th>Observed labels</th>
                            <th>Lines</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($products as $product)
                            @php
                                $pictureIds = $product->picture_ids !== '' ? explode(', ', $product->picture_ids) : [];
                                $observedLabels = $product->observed_brands !== '' ? explode(', ', $product->observed_brands) : [];
                                $lines = $product->lines !== '' ? explode(', ', $product->lines) : [];
                            @endphp

                            <tr>
                                <td>
                                    @if ($product->canonical_brand !== '')
                                        <a href="{{ route('real-brands.show', ['brand' => $product->canonical_brand]) }}">{{ $product->canonical_brand }}</a>
                                    @else
                                        [blank brand]
                                    @endif
                                </td>
                                <td>
                                    @if ($product->canonical_brand !== '')
                                        <a href="{{ route('real-brands.products.show', ['brand' => $product->canonical_brand, 'name' => $product->product_name]) }}">{{ $product->product_name }}</a>
                                    @else
                                        {{ $product->product_name }}
                                    @endif
                                </td>
                                <td>{{ number_format((int) $product->picture_count) }}</td>
                                <td>{{ number_format((int) $product->row_count) }}</td>
                                <td>
                                    <div class="brand-chip-row">
                                        @forelse ($pictureIds as $pictureId)
                                            <span class="brand-chip">{{ $pictureId }}</span>
                                        @empty
                                            <span class="brand-chip brand-chip-muted">No picture ids</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td>
                                    <div class="brand-chip-row">
                                        @forelse ($observedLabels as $label)
                                            <span class="brand-chip brand-chip-soft">{{ $label }}</span>
                                        @empty
                                            <span class="brand-chip brand-chip-muted">No observed labels</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td>
                                    <div class="brand-chip-row">
                                        @forelse ($lines as $line)
                                            <span class="brand-chip">{{ $line }}</span>
                                        @empty
                                            <span class="brand-chip brand-chip-muted">No line split</span>
                                        @endforelse
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">No products found. Try clearing the search.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        <div class="pagination-wrap">
            {{ $products->links() }}
        </div>
    </article>
@endsection
