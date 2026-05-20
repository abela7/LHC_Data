@extends('layouts.app')

@section('title', 'Deliveroo Products')
@section('section', 'Deliveroo')
@section('heading', 'Official Products')

@section('content')
    @php
        $pricedPercent = $stats['products'] > 0 ? (int) round(($stats['priced'] / $stats['products']) * 100) : 0;
    @endphp

    <section class="deliveroo-overview">
        <div class="deliveroo-hero-shell">
            <article class="deliveroo-hero">
                <div class="deliveroo-page-head">
                    <div class="deliveroo-page-head-copy">
                        <p class="eyebrow">Brand Source Check</p>
                        <h2>Deliveroo Products</h2>
                        <p class="page-note">
                            Official-only product prep for Deliveroo. Use this page to search the current sellable catalogue, open each brand collection, and manage pricing from a clean handoff list of {{ number_format($stats['products']) }} products.
                        </p>
                    </div>

                    <div class="deliveroo-pricing-progress">
                        <div class="deliveroo-pricing-progress-header">
                            <span class="deliveroo-pricing-progress-label">Pricing progress</span>
                            <span class="deliveroo-pricing-progress-count">{{ number_format($stats['priced']) }} / {{ number_format($stats['products']) }}</span>
                        </div>
                        <div class="deliveroo-pricing-progress-track" aria-hidden="true">
                            <div class="deliveroo-pricing-progress-fill" style="width: {{ $pricedPercent }}%"></div>
                        </div>
                    </div>
                </div>

                <div class="deliveroo-hero-actions">
                    <a href="{{ route('deliveroo-products.all') }}" class="button button-primary">{{ __('deliveroo.all_products.nav_all_products') }}</a>
                    <a href="{{ route('deliveroo-products.catalogue-pdf') }}" class="button">{{ __('deliveroo.all_products.download_pdf') }}</a>
                    <a href="{{ route('deliveroo-products.create') }}" class="button">{{ __('deliveroo.manual_product.add_from_index') }}</a>
                </div>

                <p class="deliveroo-hero-lead">
                    Search by brand, family, variant, or product name. Open a brand to review the official products and price them for Deliveroo.
                </p>

                <ul class="deliveroo-hero-metrics">
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--primary">
                        <span class="deliveroo-hero-metric-label">Products</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['products']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Brands</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['brands']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Families</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['families']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Images</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['images']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--priced">
                        <span class="deliveroo-hero-metric-label">Priced</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['priced']) }}</span>
                    </li>
                </ul>
            </article>
        </div>

        <section class="deliveroo-search-panel">
            <div class="deliveroo-search-panel-copy">
                <p class="eyebrow">Database Search</p>
                <h3>Find products from the Deliveroo database</h3>
                <p class="page-note">
                    Search the real Deliveroo records by brand, family, variant, description, or official product name.
                </p>
            </div>

            <form method="GET" action="{{ route('deliveroo-products.index') }}" class="deliveroo-search-form">
                <label class="deliveroo-search-field">
                    <span class="sr-only">Search Deliveroo products</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ $searchTerm }}"
                        placeholder="Search brand or product..."
                        autocomplete="off"
                    >
                </label>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Search</button>
                    @if ($searchTerm !== '')
                        <a href="{{ route('deliveroo-products.index') }}" class="button">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        @if ($searchTerm !== '')
            <section class="deliveroo-section">
                <div class="deliveroo-section-head">
                    <div class="deliveroo-section-head-copy">
                        <p class="eyebrow">Search Results</p>
                        <h3>Matches for "{{ $searchTerm }}"</h3>
                        <p class="page-note">
                            {{ number_format($searchStats['results']) }} product{{ $searchStats['results'] === 1 ? '' : 's' }} across {{ number_format($searchStats['brands']) }} brand{{ $searchStats['brands'] === 1 ? '' : 's' }}.
                        </p>
                    </div>
                </div>

                @if ($searchResults->isEmpty())
                    <article class="card">
                        <div class="brand-empty-state py-12">
                            <h3>No products found</h3>
                            <p class="page-note mt-2">Try another brand, family, or product name.</p>
                        </div>
                    </article>
                @else
                    <div class="deliveroo-product-grid deliveroo-product-grid--catalogue">
                        @foreach ($searchResults as $product)
                            @php
                                $primaryImage = collect($product->image_urls ?? [])->first();
                                $optionCount = count($product->option_values ?? []);
                                $isPriced = $product->price !== null;
                            @endphp

                            <article class="deliveroo-product-card{{ $isPriced ? ' is-priced' : '' }}">
                                <a href="{{ route('deliveroo-products.official-product', ['brand' => $product->brand_slug, 'product' => $product]) }}" class="deliveroo-product-media">
                                    @if ($primaryImage)
                                        <img src="{{ $primaryImage }}" alt="{{ $product->official_name }}">
                                    @else
                                        <div class="deliveroo-product-media-empty">No image</div>
                                    @endif
                                </a>

                                <div class="deliveroo-product-body">
                                    <p class="deliveroo-brand-kicker">{{ $product->brand_label }}</p>

                                    @if ($product->family_name || $product->variant_name)
                                        <p class="deliveroo-product-line">
                                            @if ($product->family_name)
                                                <span>{{ $product->family_name }}</span>
                                            @endif
                                            @if ($product->family_name && $product->variant_name)
                                                <span>/</span>
                                            @endif
                                            @if ($product->variant_name)
                                                <span>{{ $product->variant_name }}</span>
                                            @endif
                                        </p>
                                    @endif

                                    <h3>{{ $product->official_name }}</h3>

                                    @if ($isPriced)
                                        <div class="deliveroo-card-price">
                                            <span>{{ $product->price_display }}</span>
                                            @if ($product->price_notes)
                                                <small>{{ $product->price_notes }}</small>
                                            @endif
                                        </div>
                                    @else
                                        <div class="deliveroo-card-needs-price">To price</div>
                                    @endif

                                    <div class="deliveroo-product-stats">
                                        <span>{{ count($product->image_urls ?? []) }} images</span>
                                        @if ($optionCount > 0)
                                            <span>{{ $optionCount }} options</span>
                                        @endif
                                    </div>

                                    <div class="button-row">
                                        <a href="{{ route('deliveroo-products.official-product', ['brand' => $product->brand_slug, 'product' => $product]) }}" class="button button-primary">
                                            View Product
                                        </a>
                                        <a href="{{ route('deliveroo-products.official-brand', ['brand' => $product->brand_slug]) }}" class="button">
                                            Open Brand
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        @foreach ($categories as $category)
            <section class="deliveroo-section">
                <div class="deliveroo-section-head">
                    <div class="deliveroo-section-head-copy">
                        <p class="eyebrow">{{ $searchTerm !== '' ? 'Matching Brands' : 'Official Category' }}</p>
                        <h3>{{ $category['label'] }}</h3>
                        <p class="page-note">
                            {{ number_format($category['product_count']) }} products across {{ number_format($category['brands']->count()) }} brands.
                        </p>
                    </div>
                </div>

                <div class="deliveroo-brand-grid">
                    @foreach ($category['brands'] as $brand)
                        <article class="deliveroo-brand-tile">
                            <div class="deliveroo-brand-tile-head">
                                <div class="deliveroo-brand-mark">{{ $brand['mark'] }}</div>

                                <div class="deliveroo-brand-heading">
                                    <p class="deliveroo-brand-kicker">{{ $category['label'] }}</p>
                                    <h4>{{ $brand['label'] }}</h4>
                                </div>

                                <span class="deliveroo-count-pill">{{ number_format($brand['product_count']) }}</span>
                            </div>

                            <div class="deliveroo-brand-stats">
                                <div>
                                    <span>Products</span>
                                    <strong>{{ number_format($brand['product_count']) }}</strong>
                                </div>
                                <div>
                                    <span>Families</span>
                                    <strong>{{ number_format($brand['family_count']) }}</strong>
                                </div>
                                <div>
                                    <span>Images</span>
                                    <strong>{{ number_format($brand['image_count']) }}</strong>
                                </div>
                                <div>
                                    <span>Priced</span>
                                    <strong>{{ number_format($brand['priced_count']) }}</strong>
                                </div>
                            </div>

                            <div class="deliveroo-family-preview">
                                @foreach ($brand['family_preview'] as $family)
                                    <span class="deliveroo-family-chip">{{ $family }}</span>
                                @endforeach

                                @if ($brand['more_families'] > 0)
                                    <span class="deliveroo-family-chip deliveroo-family-chip--muted">+{{ number_format($brand['more_families']) }} more</span>
                                @endif
                            </div>

                            <a href="{{ route('deliveroo-products.official-brand', ['brand' => $brand['slug']]) }}" class="button button-primary deliveroo-brand-link">
                                Open Official Products
                            </a>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    </section>
@endsection
