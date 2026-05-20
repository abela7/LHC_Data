@extends('layouts.app')

@section('title', $canonicalBrand)

@section('content')
    @php
        $brandInitials = collect(preg_split('/\s+/', trim($canonicalBrand)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');
        $rangeQuery = array_filter([
            'picture_from' => $filters['picture_from'],
            'picture_to' => $filters['picture_to'],
        ]);
        $pictureIndexMap = $brandPictureCards
            ->pluck('picture_id')
            ->values()
            ->flip();
    @endphp

    <section class="brand-detail-hero brand-page-hero-compact">
        <div class="brand-detail-hero-main">
            <div class="brand-avatar brand-avatar-lg">{{ $brandInitials }}</div>
            <div class="brand-detail-hero-copy">
                <p class="eyebrow">Real Brand Detail</p>
                <h2>{{ $canonicalBrand }}</h2>
                <p class="brand-hero-note">Working notebook for one brand. Check the linked labels, pictures, and products before scraper work.</p>
                <div class="brand-hero-tags">
                    <span class="pill">{{ number_format($stats['products']) }} products</span>
                    @if ($brandPictureCards->isNotEmpty())
                        <button
                            type="button"
                            class="pill pill-button"
                            data-brand-carousel-trigger
                            aria-haspopup="dialog"
                            aria-controls="brand-picture-carousel"
                        >
                            {{ number_format($stats['pictures']) }} pictures
                        </button>
                    @else
                        <span class="pill">{{ number_format($stats['pictures']) }} pictures</span>
                    @endif
                    <span class="pill">{{ number_format($stats['observed_brands']) }} observed labels</span>
                    @if ($stats['lines'] > 0)
                        <span class="pill">{{ number_format($stats['lines']) }} lines</span>
                    @endif
                    @if ($rangeQuery !== [])
                        <span class="pill">{{ $filters['picture_from'] !== '' ? $filters['picture_from'] : 'start' }} to {{ $filters['picture_to'] !== '' ? $filters['picture_to'] : 'end' }}</span>
                    @endif
                </div>
            </div>
        </div>

        <aside class="brand-detail-panel brand-detail-panel-compact">
            <p class="helper-title">Quick actions</p>
            <div class="button-row">
                <a href="{{ route('real-brands.index', $rangeQuery) }}" class="button">Back to brands</a>
                <a href="{{ route('brand-review.index', array_merge(['search' => $canonicalBrand], $rangeQuery)) }}" class="button button-primary">Review brand mapping</a>
                @if ($officialSourceUrl)
                    <a href="{{ $officialSourceUrl }}" target="_blank" rel="noreferrer" class="button">Official site</a>
                @endif
            </div>
            <p class="brand-detail-panel-copy">{{ number_format($stats['rows']) }} rows currently sit under this brand.</p>

            <form method="POST" action="{{ route('real-brands.update', ['brand' => $canonicalBrand]) }}" class="stack-form">
                @csrf
                @method('PATCH')

                <label>
                    <span>Official site URL</span>
                    <input type="url" name="official_source_url" value="{{ old('official_source_url', $officialSourceUrl) }}" placeholder="https://brand.example.com/">
                </label>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Save official site</button>
                </div>
            </form>
        </aside>
    </section>

    <section class="stats-grid brand-stats-grid brand-detail-stats brand-detail-stats-compact">
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">observation rows</p>
            <p class="stat-value">{{ number_format($stats['rows']) }}</p>
            <p class="brand-stat-foot">Raw rows imported from picture JSON for this brand.</p>
        </article>
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">products</p>
            <p class="stat-value">{{ number_format($stats['products']) }}</p>
            <p class="brand-stat-foot">Distinct product names under this cleaned brand.</p>
        </article>
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">pictures</p>
            <p class="stat-value">{{ number_format($stats['pictures']) }}</p>
            <p class="brand-stat-foot">Source shop photos contributing evidence.</p>
            @if ($brandPictureCards->isNotEmpty())
                <button
                    type="button"
                    class="brand-stat-inline-link"
                    data-brand-carousel-trigger
                    aria-haspopup="dialog"
                    aria-controls="brand-picture-carousel"
                >
                    Open carousel
                </button>
            @endif
        </article>
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">observed labels</p>
            <p class="stat-value">{{ number_format($stats['observed_brands']) }}</p>
            <p class="brand-stat-foot">Different observed brand labels currently mapped into this real brand.</p>
        </article>
    </section>

    <section class="split-grid brand-detail-grid">
        <article class="card compact-card-shell">
            <div class="card-head">
                <h3>Observed labels and official sources</h3>
            <p>{{ $mappingSummary->count() }} mappings</p>
            </div>

            <div class="mapping-grid mapping-grid-compact">
                @forelse ($mappingSummary as $mapping)
                    <article class="mapping-card">
                        <p class="helper-title">Observed label</p>
                        <h4>{{ $mapping->observed_brand }}</h4>
                        <div class="mapping-meta">
                            <span class="pill">{{ $mapping->brand_line ?: 'No line' }}</span>
                        </div>
                        @if ($mapping->notes)
                            <p class="mapping-note">{{ $mapping->notes }}</p>
                        @endif
                        @if ($mapping->official_source_url)
                            <a href="{{ $mapping->official_source_url }}" target="_blank" rel="noreferrer" class="button">Open official source</a>
                        @endif
                    </article>
                @empty
                    <article class="mapping-card">
                        <h4>No mapping rows yet</h4>
                        <p class="mapping-note">Add one from brand review.</p>
                    </article>
                @endforelse
            </div>
        </article>

        <article class="card brand-search-card brand-search-card-compact">
            <div class="card-head">
                <h3>Find products in this brand</h3>
                <p>{{ $products->total() }} products on this result set</p>
            </div>

            <form method="GET" action="{{ route('real-brands.show', ['brand' => $canonicalBrand]) }}" class="stack-form">
                <div class="brand-toolbar-grid">
                    <label class="brand-search-field">
                        <span>Search product name</span>
                        <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search product name">
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
                            <a
                                href="{{ route('real-brands.show', array_filter(array_merge(['brand' => $canonicalBrand, 'search' => $filters['search'], 'view' => 'grid'], $rangeQuery))) }}"
                                @class(['view-switch-link', 'is-active' => $viewMode === 'grid'])
                            >
                                Grid
                            </a>
                            <a
                                href="{{ route('real-brands.show', array_filter(array_merge(['brand' => $canonicalBrand, 'search' => $filters['search'], 'view' => 'list'], $rangeQuery))) }}"
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
                    <a href="{{ route('real-brands.show', ['brand' => $canonicalBrand]) }}" class="button">Clear</a>
                </div>
            </form>

            <div class="brand-search-note">
                <p>Decide whether names stay, merge, or become families.</p>
            </div>
        </article>
    </section>

    <article class="card compact-card-shell">
        <div class="card-head">
            <h3>Products under {{ $canonicalBrand }}</h3>
            <p>{{ $products->total() }} products &middot; {{ ucfirst($viewMode) }} view</p>
        </div>

        @if ($viewMode === 'grid')
            <div class="product-grid-compact brand-product-grid">
                @forelse ($products as $product)
                    @php
                        $pictureIds = $product->picture_ids !== '' ? explode(', ', $product->picture_ids) : [];
                        $observedLabels = $product->observed_brands !== '' ? explode(', ', $product->observed_brands) : [];
                        $lines = $product->lines !== '' ? explode(', ', $product->lines) : [];
                    @endphp

                    <article class="product-card product-card-compact brand-product-card">
                        <div class="product-card-head">
                            <div>
                                <p class="product-card-kicker">Product name</p>
                                <h4>
                                    <a href="{{ route('real-brands.products.show', array_merge(['brand' => $canonicalBrand, 'name' => $product->product_name], $rangeQuery)) }}" class="product-card-title-link">
                                        {{ $product->product_name }}
                                    </a>
                                </h4>
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
                            <p class="product-chip-label">Picture ids</p>
                            <div class="brand-chip-row">
                                @forelse ($pictureIds as $pictureId)
                                    @if ($brandPictureCards->isNotEmpty() && $pictureIndexMap->has($pictureId))
                                        <button
                                            type="button"
                                            class="brand-chip brand-chip-button"
                                            data-brand-carousel-trigger
                                            data-carousel-index="{{ $pictureIndexMap->get($pictureId) }}"
                                            aria-haspopup="dialog"
                                            aria-controls="brand-picture-carousel"
                                        >
                                            {{ $pictureId }}
                                        </button>
                                    @else
                                        <span class="brand-chip">{{ $pictureId }}</span>
                                    @endif
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

                        <div class="brand-product-link-row">
                            <a href="{{ route('real-brands.products.show', array_merge(['brand' => $canonicalBrand, 'name' => $product->product_name], $rangeQuery)) }}" class="brand-product-link">Open product</a>
                        </div>
                    </article>
                @empty
                    <div class="brand-empty-state">
                        <h3>No products found</h3>
                        <p>Try clearing the search to show the full brand bucket again.</p>
                    </div>
                @endforelse
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Product name</th>
                            <th>Pictures</th>
                            <th>Rows</th>
                            <th>Picture ids</th>
                            <th>Observed labels</th>
                            <th>Lines</th>
                            <th></th>
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
                                    <div class="brand-list-cell">
                                        <strong>{{ $product->product_name }}</strong>
                                        <small>Under {{ $canonicalBrand }}</small>
                                    </div>
                                </td>
                                <td>{{ number_format((int) $product->picture_count) }}</td>
                                <td>{{ number_format((int) $product->row_count) }}</td>
                                <td>
                                    <div class="brand-chip-row">
                                        @forelse ($pictureIds as $pictureId)
                                            @if ($brandPictureCards->isNotEmpty() && $pictureIndexMap->has($pictureId))
                                                <button
                                                    type="button"
                                                    class="brand-chip brand-chip-button"
                                                    data-brand-carousel-trigger
                                                    data-carousel-index="{{ $pictureIndexMap->get($pictureId) }}"
                                                    aria-haspopup="dialog"
                                                    aria-controls="brand-picture-carousel"
                                                >
                                                    {{ $pictureId }}
                                                </button>
                                            @else
                                                <span class="brand-chip">{{ $pictureId }}</span>
                                            @endif
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
                                <td>
                                    <a href="{{ route('real-brands.products.show', array_merge(['brand' => $canonicalBrand, 'name' => $product->product_name], $rangeQuery)) }}" class="button">Open</a>
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

    @if ($brandPictureCards->isNotEmpty())
        <div
            class="photo-carousel-modal"
            id="brand-picture-carousel"
            data-brand-carousel
            aria-hidden="true"
            hidden
        >
            <button type="button" class="photo-carousel-backdrop" data-carousel-close aria-label="Close picture carousel"></button>

            <section class="photo-carousel-panel" role="dialog" aria-modal="true" aria-labelledby="brand-picture-carousel-title">
                <div class="photo-carousel-head">
                    <div>
                        <p class="eyebrow">Brand photo evidence</p>
                        <h3 id="brand-picture-carousel-title">{{ $canonicalBrand }}</h3>
                        <p class="photo-carousel-subtitle">
                            <span data-carousel-current>1</span> of {{ $brandPictureCards->count() }} local shop pictures
                        </p>
                    </div>

                    <div class="photo-carousel-actions">
                        <button type="button" class="button" data-carousel-prev>Previous</button>
                        <button type="button" class="button" data-carousel-next>Next</button>
                        <button type="button" class="button button-primary" data-carousel-close>Close</button>
                    </div>
                </div>

                <div class="photo-carousel-stage">
                    @foreach ($brandPictureCards as $pictureCard)
                        <article class="photo-carousel-slide" data-carousel-slide @if (! $loop->first) hidden @endif>
                            <div class="photo-carousel-media">
                                @if ($pictureCard->image_url)
                                    <img src="{{ $pictureCard->image_url }}" alt="{{ $pictureCard->picture_id }} shop photo for {{ $canonicalBrand }}">
                                @else
                                    <div class="product-photo-missing">
                                        <span>No local photo found</span>
                                        <small>{{ $pictureCard->picture_id }}</small>
                                    </div>
                                @endif
                            </div>

                            <div class="photo-carousel-foot">
                                <div>
                                    <p class="product-card-kicker">Picture</p>
                                    <h4>{{ $pictureCard->picture_id }}</h4>
                                </div>

                                <div class="brand-chip-row">
                                    <span class="brand-chip">{{ number_format($pictureCard->product_count) }} products</span>
                                    <span class="brand-chip brand-chip-soft">{{ number_format($pictureCard->row_count) }} rows</span>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        </div>
    @endif
@endsection
