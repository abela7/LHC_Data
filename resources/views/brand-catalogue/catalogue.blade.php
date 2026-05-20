@extends('layouts.app')

@section('title', $catalogue->name)

@section('content')
    @include('brand-catalogue._page-label', ['label' => 'Catalogue Workspace', 'context' => $catalogue->name])

    @include('partials.catalogue-breadcrumb', [
        'items' => [
            ['label' => 'Brand Catalogue', 'url' => route('brand-catalogue.index')],
            ['label' => $catalogue->name, 'current' => true],
        ],
    ])

    <div class="sr-hero">
        <header class="sr-hero-head">
            <p class="sr-hero-eyebrow">Catalogue workspace</p>
            <h1 class="sr-hero-title">{{ $catalogue->name }}</h1>
        </header>

        <div class="sr-stats" aria-label="Catalogue totals">
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $catalogueLineCount }}</span>
                <span class="sr-stat-label">Brand lines</span>
            </div>
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $catalogueBrands->count() }}</span>
                <span class="sr-stat-label">Master brands</span>
            </div>
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $catalogue->brands->sum('product_types_count') }}</span>
                <span class="sr-stat-label">Product types</span>
            </div>
        </div>

        <nav class="sr-hero-toolbar" aria-label="Catalogue actions">
            <a href="{{ route('brand-catalogue.product-type-structure', $catalogue) }}" class="bc-action-btn bc-action-view">Type structure</a>
            <a href="{{ route('brand-catalogue.view', $catalogue) }}" class="bc-action-btn bc-action-view">Browse view</a>
            <a href="{{ route('brand-catalogue.export-json', $catalogue) }}" class="bc-action-btn bc-action-export" target="_blank" rel="noopener">Export JSON</a>
            <button type="button" class="sr-edit-trigger" onclick="document.getElementById('cat-edit').toggleAttribute('open')">Edit catalogue</button>
        </nav>
    </div>
    <section class="bc-product-finder" aria-labelledby="bc-product-finder-title">
        <div class="bc-product-finder-head">
            <div>
                <p class="bc-product-finder-kicker">Shop-floor first search</p>
                <h2 id="bc-product-finder-title">Find a product family fast</h2>
            </div>
            @if ($productFinder['searched'])
                <span class="bc-product-finder-pill">
                    {{ number_format(array_sum($productFinder['counts'])) }} match{{ array_sum($productFinder['counts']) === 1 ? '' : 'es' }}
                </span>
            @endif
        </div>

        <form method="GET" action="{{ route('brand-catalogue.show', $catalogue) }}" class="bc-product-finder-form">
            <label>
                <span>Brand</span>
                <select name="product_brand">
                    <option value="">All brands</option>
                    @foreach ($catalogueBrands as $brand)
                        <option value="{{ $brand->id }}" @selected((int) ($productFinder['selected_brand_id'] ?? 0) === (int) $brand->id)>
                            {{ $brand->name }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="bc-product-finder-query">
                <span>Product search</span>
                <input type="search"
                       name="product_search"
                       value="{{ $productFinder['query'] }}"
                       placeholder="Example: Kuknus Peruvian Remi Deep, Cherish Boho, UV-PINK..."
                       autocomplete="off">
            </label>
            <div class="bc-product-finder-actions">
                <button type="submit">Search product</button>
                @if ($productFinder['searched'])
                    <a href="{{ route('brand-catalogue.show', $catalogue) }}">Clear</a>
                @endif
            </div>
        </form>

        @if ($productFinder['searched'])
            @php
                $finderSections = [
                    [
                        'key' => 'submitted',
                        'step' => 1,
                        'title' => 'Submitted shop-floor products',
                        'hint' => 'Real products you or your team saw in the shop. Check these first.',
                        'items' => $productFinder['submitted'],
                        'collapsed' => false,
                    ],
                    [
                        'key' => 'retail',
                        'step' => 2,
                        'title' => 'Existing family products',
                        'hint' => 'Already published sellable family pages.',
                        'items' => $productFinder['retail'],
                        'collapsed' => true,
                    ],
                    [
                        'key' => 'catalogue',
                        'step' => 3,
                        'title' => 'Imported catalogue fallback',
                        'hint' => 'Only when no submitted or published family match exists.',
                        'items' => $productFinder['catalogue'],
                        'collapsed' => true,
                    ],
                ];
            @endphp

            <div class="bc-product-finder-results">
                @foreach ($finderSections as $section)
                    @php
                        $sectionCount = $section['items']->count();
                        $sectionTag = $section['collapsed'] ? 'details' : 'section';
                    @endphp
                    <{{ $sectionTag }}
                        class="bc-finder-section bc-finder-section-{{ $section['key'] }}"
                    >
                        @if ($section['collapsed'])
                            <summary class="bc-finder-section-summary">
                                <span class="bc-finder-step" aria-hidden="true">{{ $section['step'] }}</span>
                                <span class="bc-finder-section-copy">
                                    <span class="bc-finder-section-title">{{ $section['title'] }}</span>
                                    <span class="bc-finder-section-hint">{{ $section['hint'] }}</span>
                                </span>
                                <span class="bc-finder-count">{{ number_format($sectionCount) }}</span>
                            </summary>
                        @else
                            <header class="bc-finder-section-summary is-static">
                                <span class="bc-finder-step" aria-hidden="true">{{ $section['step'] }}</span>
                                <span class="bc-finder-section-copy">
                                    <h3 class="bc-finder-section-title">{{ $section['title'] }}</h3>
                                    <p class="bc-finder-section-hint">{{ $section['hint'] }}</p>
                                </span>
                                <span class="bc-finder-count">{{ number_format($sectionCount) }}</span>
                            </header>
                        @endif

                        <div class="bc-finder-section-body">
                            @if ($section['items']->isEmpty())
                                <p class="bc-finder-empty">No matches in this section.</p>
                            @else
                                <ul class="bc-finder-list">
                                    @foreach ($section['items'] as $item)
                                        <li>
                                            <article class="bc-finder-card bc-finder-card--{{ $item['type'] }} {{ $item['is_floor'] ? 'is-floor' : '' }}">
                                                <div class="bc-finder-card-media">
                                                    <div class="bc-finder-thumb">
                                                        @if ($item['image_url'])
                                                            <img src="{{ $item['image_url'] }}" alt="" loading="lazy">
                                                        @else
                                                            <span class="bc-finder-thumb-fallback">{{ $item['is_floor'] ? 'Shop' : 'LHC' }}</span>
                                                        @endif
                                                    </div>
                                                    <div class="bc-finder-card-badges">
                                                        <span class="bc-finder-badge">{{ $item['badge'] }}</span>
                                                        @if ($item['has_family'])
                                                            <span class="bc-finder-badge is-ready">Family ready</span>
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="bc-finder-card-body">
                                                    <h4 class="bc-finder-card-title">{{ $item['title'] }}</h4>
                                                    @if ($item['subtitle'])
                                                        <p class="bc-finder-card-path">{{ $item['subtitle'] }}</p>
                                                    @endif
                                                    @if ($item['meta'])
                                                        <p class="bc-finder-card-meta">{{ $item['meta'] }}</p>
                                                    @endif
                                                    @if ($item['primary_url'] || $item['secondary_url'])
                                                        <div class="bc-finder-card-actions">
                                                            @if ($item['primary_url'])
                                                                <a class="bc-finder-btn bc-finder-btn-primary" href="{{ $item['primary_url'] }}">{{ $item['primary_label'] }}</a>
                                                            @endif
                                                            @if ($item['secondary_url'])
                                                                <a class="bc-finder-btn bc-finder-btn-secondary" href="{{ $item['secondary_url'] }}">{{ $item['secondary_label'] }}</a>
                                                            @endif
                                                        </div>
                                                    @endif
                                                </div>
                                            </article>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </{{ $sectionTag }}>
                @endforeach
            </div>
        @endif
    </section>

    <details id="cat-edit" class="sr-edit-drawer">
        <summary class="sr-edit-drawer-summary">Edit catalogue</summary>
        <form method="POST" action="{{ route('brand-catalogue.update', $catalogue) }}" class="sr-edit-form">
            @csrf
            @method('PATCH')
            <div class="sr-edit-grid">
                <label><span>Name</span><input type="text" name="name" value="{{ $catalogue->name }}" required></label>
                <label><span>Sort</span><input type="number" name="sort_order" value="{{ $catalogue->sort_order }}" min="0"></label>
            </div>
            <label><span>Note</span><textarea name="note" rows="2">{{ $catalogue->note }}</textarea></label>
            <div class="button-row"><button type="submit" class="button button-primary">Save</button></div>
        </form>
    </details>

    <form method="POST" action="{{ route('brand-catalogue.brand-lines.store', $catalogue) }}" class="sr-add-bar">
        @csrf
        <div class="sr-add-bar-icon">+</div>
        <input type="text" name="name" placeholder="Brand line..." required class="sr-add-input">
        <input type="url" name="url" placeholder="Link (https://...)" class="sr-add-note">
        <input type="number" name="sort_order" value="0" min="0" class="sr-add-sort" title="Sort order">
        <button type="submit" class="sr-add-btn">Add brand line</button>
    </form>

    <details class="sr-edit-drawer">
        <summary class="sr-edit-drawer-summary">Add master brand only when needed</summary>
        <form method="POST" action="{{ route('brand-catalogue.brands.store', $catalogue) }}" class="sr-edit-form">
            @csrf
            <div class="sr-edit-grid">
                <label><span>Master Brand</span><input type="text" name="name" placeholder="Sleek Hair" required></label>
                <label><span>Sort</span><input type="number" name="sort_order" value="0" min="0"></label>
            </div>
            <label><span>Note</span><textarea name="note" rows="2" placeholder="Only for umbrella brands with multiple lines or collections."></textarea></label>
            <label><span>Link</span><input type="url" name="url" placeholder="https://..."></label>
            <div class="button-row"><button type="submit" class="button button-primary">Add master brand</button></div>
        </form>
    </details>

    @if ($catalogueBrands->isEmpty())
        <div class="sr-empty"><p>No master brands yet - add one above</p></div>
    @else
        <div class="bc-card-list">
            @foreach ($catalogueBrands as $brand)
                <div class="bc-card-row">
                    <a href="{{ route('brand-catalogue.brands.show', [$catalogue, $brand]) }}" class="bc-card-link">
                        <span class="sr-node-order">{{ $brand->sort_order }}</span>
                        <div class="bc-card-body">
                            <span class="bc-card-name">{{ $brand->name }}</span>
                            <span class="bc-card-meta">
                                <span class="bc-vtype-badge bc-vtype-text">master brand</span>
                                <span>{{ $brand->lines->count() }} line{{ $brand->lines->count() === 1 ? '' : 's' }}</span>
                                <span>{{ $brand->product_types_count }} product type{{ $brand->product_types_count === 1 ? '' : 's' }}</span>
                                @if ($brand->url)
                                    <span class="bc-card-url-hint">has link</span>
                                @endif
                            </span>
                        </div>
                        <svg class="bc-card-arrow" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
@endsection
