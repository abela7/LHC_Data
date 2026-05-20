@extends('layouts.app')

@section('title', 'Pictures')
@section('section', 'Data')
@section('heading', 'Pictures')

@section('content')
    {{-- ═══ HERO HEADER ═══ --}}
    <section class="pw-hero">
        <div class="pw-hero-text">
            <p class="pw-eyebrow">Picture Workspace</p>
            <h2 class="pw-title">Picture Browser</h2>
            <p class="pw-subtitle">
                Browse local shop photos and validate detected products and brands.
                @if (($dataSource ?? 'observed') === 'mapped')
                    <span class="pw-badge-inline">Using restored JSON mapping</span>
                @endif
            </p>
        </div>
        <div class="pw-hero-stats">
            <div class="pw-stat-card">
                <span class="pw-stat-value">{{ number_format($stats['pictures']) }}</span>
                <span class="pw-stat-label">Pictures</span>
            </div>
            <div class="pw-stat-card">
                <span class="pw-stat-value">{{ number_format($stats['products']) }}</span>
                <span class="pw-stat-label">Product Hits</span>
            </div>
            <div class="pw-stat-card">
                <span class="pw-stat-value">{{ number_format($stats['rows']) }}</span>
                <span class="pw-stat-label">Data Rows</span>
            </div>
        </div>
    </section>

    {{-- ═══ SEARCH / FILTER BAR ═══ --}}
    <form method="GET" action="{{ route('pictures.index') }}" class="pw-filter-bar" id="pw-filter-form">
        <div class="pw-filter-main">
            <div class="pw-search-wrap">
                <span class="pw-search-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.35-4.35"/></svg>
                </span>
                <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search picture ID, product, or brand..." class="pw-search-input">
            </div>
            <button type="submit" class="pw-btn pw-btn-primary">Search</button>
            @if ($filters['search'] !== '' || $filters['brand'] !== '' || $filters['category'] !== '' || $filters['picture_from'] !== '' || $filters['picture_to'] !== '')
                <a href="{{ route('pictures.index') }}" class="pw-btn pw-btn-ghost">Clear</a>
            @endif
        </div>
        <details class="pw-filter-details" @if($filters['brand'] !== '' || $filters['category'] !== '' || $filters['picture_from'] !== '' || $filters['picture_to'] !== '') open @endif>
            <summary class="pw-filter-toggle">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                Filters
                @php
                    $activeFilters = collect($filters)->except('search')->filter(fn($v) => $v !== '')->count();
                @endphp
                @if ($activeFilters > 0)
                    <span class="pw-filter-badge">{{ $activeFilters }}</span>
                @endif
            </summary>
            <div class="pw-filter-grid">
                <label class="pw-field">
                    <span class="pw-field-label">Real Brand</span>
                    <select name="brand" class="pw-select">
                        <option value="">All brands</option>
                        @foreach ($brandOptions as $brand)
                            <option value="{{ $brand === '' ? '__blank__' : $brand }}" @selected($filters['brand'] === ($brand === '' ? '__blank__' : $brand))>
                                {{ $brand === '' ? '[blank]' : $brand }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="pw-field">
                    <span class="pw-field-label">Category</span>
                    <select name="category" class="pw-select">
                        <option value="">All categories</option>
                        @foreach ($categoryOptions as $category)
                            <option value="{{ $category->slug }}" @selected($filters['category'] === $category->slug)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="pw-field">
                    <span class="pw-field-label">Picture From</span>
                    <input type="text" name="picture_from" value="{{ $filters['picture_from'] }}" placeholder="e.g. 381" class="pw-input">
                </label>
                <label class="pw-field">
                    <span class="pw-field-label">Picture To</span>
                    <input type="text" name="picture_to" value="{{ $filters['picture_to'] }}" placeholder="e.g. 459" class="pw-input">
                </label>
            </div>
        </details>
    </form>

    {{-- ═══ TOOLBAR ROW ═══ --}}
    <div class="pw-toolbar-row">
        <p class="pw-result-count">
            <strong>{{ $pictures->total() }}</strong> picture{{ $pictures->total() !== 1 ? 's' : '' }} found
            @if ($filters['picture_from'] !== '' || $filters['picture_to'] !== '')
                <span class="pw-range-tag">Range: {{ $filters['picture_from'] !== '' ? $filters['picture_from'] : 'start' }} — {{ $filters['picture_to'] !== '' ? $filters['picture_to'] : 'end' }}</span>
            @endif
        </p>
        <div class="pw-view-toggle" id="pw-view-toggle">
            <a href="{{ route('pictures.product-hits') }}" class="pw-vt-btn" title="All product hits" style="text-decoration:none;width:auto;padding:0 12px;">
                All hits
            </a>
            <button type="button" class="pw-vt-btn is-active" data-view="grid" title="Grid view">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            </button>
            <button type="button" class="pw-vt-btn" data-view="list" title="List view">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            </button>
        </div>
    </div>

    {{-- ═══ PICTURE GRID ═══ --}}
    <div class="pw-grid" id="pw-grid">
        @forelse ($pictures as $picture)
            @php
                $productPreview = collect($picture->product_entries)->take(3);
                $brandPreview = collect($picture->brand_entries)->take(3);
                $hasMoreProducts = count($picture->product_entries) > $productPreview->count();
                $hasMoreBrands = count($picture->brand_entries) > $brandPreview->count();
            @endphp
            <article class="pw-card" data-picture-id="{{ $picture->picture_id }}">
                {{-- Image --}}
                @if ($picture->image_url)
                    <div class="pw-card-media">
                        <img src="{{ $picture->image_url }}" alt="{{ $picture->picture_id }}" loading="lazy">
                        <span class="pw-card-id">{{ $picture->picture_id }}</span>
                        <button
                            type="button"
                            class="pw-card-zoom-btn"
                            data-picture-preview-trigger
                            data-picture-id="{{ $picture->picture_id }}"
                            data-image-url="{{ $picture->image_url }}"
                            aria-haspopup="dialog"
                            aria-controls="picture-preview-modal"
                            title="Zoom picture"
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.35-4.35"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                        </button>
                    </div>
                @else
                    <div class="pw-card-media pw-card-media-empty">
                        <div class="pw-card-missing">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                            <span>No photo</span>
                            <small>{{ $picture->picture_id }}</small>
                        </div>
                    </div>
                @endif

                {{-- Body --}}
                <div class="pw-card-body">
                    <div class="pw-card-header">
                        <a href="{{ route('pictures.show', ['pictureId' => $picture->picture_id]) }}" class="pw-card-title">
                            {{ $picture->picture_id }}
                        </a>
                        <a href="{{ route('pictures.show', ['pictureId' => $picture->picture_id]) }}" class="pw-btn pw-btn-sm">Review</a>
                    </div>

                    {{-- Stats chips --}}
                    <div class="pw-card-chips">
                        <span class="pw-chip pw-chip-product">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
                            {{ $picture->product_count }} product{{ $picture->product_count !== 1 ? 's' : '' }}
                        </span>
                        <span class="pw-chip pw-chip-brand">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                            {{ $picture->brand_count }} brand{{ $picture->brand_count !== 1 ? 's' : '' }}
                        </span>
                    </div>

                    {{-- Products --}}
                    @if ($productPreview->isNotEmpty())
                        <div class="pw-card-section">
                            <p class="pw-section-label">Products</p>
                            <div class="pw-product-list">
                                @foreach ($productPreview as $productEntry)
                                    <div class="pw-product-item">
                                        @if ($productEntry->product_url)
                                            <a href="{{ $productEntry->product_url }}" class="pw-product-name">{{ $productEntry->product_name }}</a>
                                        @else
                                            <span class="pw-product-name">{{ $productEntry->product_name }}</span>
                                        @endif
                                        @if ($productEntry->brand_name !== '')
                                            <span class="pw-product-brand">{{ $productEntry->brand_name }}</span>
                                        @endif
                                    </div>
                                @endforeach
                                @if ($hasMoreProducts)
                                    <a href="{{ route('pictures.show', ['pictureId' => $picture->picture_id]) }}" class="pw-more-link">+{{ count($picture->product_entries) - $productPreview->count() }} more</a>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- Brands --}}
                    @if ($brandPreview->isNotEmpty())
                        <div class="pw-card-section">
                            <p class="pw-section-label">Brands</p>
                            <div class="pw-brand-row">
                                @foreach ($brandPreview as $brandEntry)
                                    @if (! empty($brandEntry->url))
                                        <a href="{{ $brandEntry->url }}" class="pw-brand-tag">{{ $brandEntry->name }}</a>
                                    @else
                                        <span class="pw-brand-tag">{{ $brandEntry->name }}</span>
                                    @endif
                                @endforeach
                                @if ($hasMoreBrands)
                                    <span class="pw-brand-tag pw-brand-tag-more">+{{ count($picture->brand_entries) - $brandPreview->count() }}</span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </article>
        @empty
            <div class="pw-empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                <h3>No pictures found</h3>
                <p>Try adjusting your search or clearing all filters.</p>
                <a href="{{ route('pictures.index') }}" class="pw-btn pw-btn-primary" style="margin-top:12px">Clear all filters</a>
            </div>
        @endforelse
    </div>

    {{-- ═══ PAGINATION ═══ --}}
    @if ($pictures->hasPages())
        <div class="pw-pagination">
            {{ $pictures->links() }}
        </div>
    @endif

    {{-- ═══ LIGHTBOX MODAL ═══ --}}
    <div
        class="pw-lightbox-overlay"
        id="picture-preview-modal"
        data-picture-preview-modal
        aria-hidden="true"
        hidden
    >
        <button type="button" class="pw-lightbox-backdrop" data-picture-preview-close aria-label="Close"></button>

        <section class="pw-lightbox" role="dialog" aria-modal="true" id="pw-lightbox-viewport">
            <img src="" alt="" data-picture-preview-image id="pw-lightbox-img">
        </section>
    </div>
@endsection
