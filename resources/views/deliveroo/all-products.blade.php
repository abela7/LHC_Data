@extends('layouts.app')

@section('title', __('deliveroo.all_products.page_title'))
@section('section', 'Deliveroo')
@section('heading', __('deliveroo.all_products.heading'))

@section('content')
    @php
        $pricedPct = $totalCatalogue > 0 ? (int) round(($pricedTotal / $totalCatalogue) * 100) : 0;
        $hasFilters = $brandFilter !== ''
            || $categoryFilter !== ''
            || $familyFilter !== ''
            || $priceStatusFilter !== ''
            || $hasImageFilter !== ''
            || $search !== '';
    @endphp

    <section class="deliveroo-overview">
        <div class="deliveroo-hero-shell">
            <article class="deliveroo-hero">
                <div class="deliveroo-page-head">
                    <div class="deliveroo-page-head-copy">
                        <p class="eyebrow">{{ __('deliveroo.all_products.eyebrow') }}</p>
                        <h2>{{ __('deliveroo.all_products.page_title') }}</h2>
                        <p class="page-note">
                            {{ __('deliveroo.all_products.lead') }}
                        </p>
                    </div>

                    <div class="deliveroo-pricing-progress">
                        <div class="deliveroo-pricing-progress-header">
                            <span class="deliveroo-pricing-progress-label">{{ __('deliveroo.all_products.metrics_priced') }}</span>
                            <span class="deliveroo-pricing-progress-count">{{ number_format($pricedTotal) }} / {{ number_format($totalCatalogue) }}</span>
                        </div>
                        <div class="deliveroo-pricing-progress-track" aria-hidden="true">
                            <div class="deliveroo-pricing-progress-fill" style="width: {{ $pricedPct }}%"></div>
                        </div>
                    </div>
                </div>

                <div class="deliveroo-hero-actions">
                    <a href="{{ route('deliveroo-products.index') }}" class="button">{{ __('deliveroo.all_products.back_to_index') }}</a>
                    <a href="{{ route('deliveroo-products.catalogue-pdf') }}" class="button button-primary">{{ __('deliveroo.all_products.download_pdf') }}</a>
                    <a href="{{ route('deliveroo-products.create') }}" class="button">{{ __('deliveroo.manual_product.add_from_index') }}</a>
                </div>

                <ul class="deliveroo-hero-metrics">
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--primary">
                        <span class="deliveroo-hero-metric-label">{{ __('deliveroo.all_products.metrics_total') }}</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($totalCatalogue) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--priced">
                        <span class="deliveroo-hero-metric-label">{{ __('deliveroo.all_products.metrics_priced') }}</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($pricedTotal) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">{{ __('deliveroo.all_products.metrics_showing') }}</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($products->count()) }}</span>
                    </li>
                </ul>
            </article>
        </div>

        <section class="deliveroo-search-panel">
            <div class="deliveroo-search-panel-copy">
                <p class="eyebrow">{{ __('deliveroo.all_products.filters_eyebrow') }}</p>
                <h3>{{ __('deliveroo.all_products.filters_heading') }}</h3>
                <p class="page-note">{{ __('deliveroo.all_products.pagination_note') }}</p>
            </div>

            <form method="GET" action="{{ route('deliveroo-products.all') }}" class="deliveroo-search-form">
                <label class="deliveroo-search-field">
                    <span class="sr-only">{{ __('deliveroo.all_products.filter_search_label') }}</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="{{ __('deliveroo.all_products.filter_search_placeholder') }}"
                        autocomplete="off"
                    >
                </label>

                <div class="deliveroo-all-products-filters">
                    <label class="deliveroo-all-products-select-label">
                        <span>{{ __('deliveroo.all_products.filter_category_label') }}</span>
                        <select name="category" class="deliveroo-all-products-select">
                            <option value="">{{ __('deliveroo.all_products.filter_category_all') }}</option>
                            @foreach ($categoriesForFilter as $category)
                                <option value="{{ $category }}" @selected($categoryFilter === $category)>{{ $category }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="deliveroo-all-products-select-label">
                        <span>{{ __('deliveroo.all_products.filter_brand_label') }}</span>
                        <select name="brand" class="deliveroo-all-products-select">
                            <option value="">{{ __('deliveroo.all_products.filter_brand_all') }}</option>
                            @foreach ($brandsForFilter as $b)
                                <option value="{{ $b['slug'] }}" @selected($brandFilter === $b['slug'])>{{ $b['label'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="deliveroo-all-products-select-label">
                        <span>{{ __('deliveroo.all_products.filter_family_label') }}</span>
                        <input
                            type="text"
                            name="family"
                            value="{{ $familyFilter }}"
                            list="deliveroo-family-list"
                            class="deliveroo-all-products-select"
                            placeholder="{{ __('deliveroo.all_products.filter_family_placeholder') }}"
                        >
                        <datalist id="deliveroo-family-list">
                            @foreach ($familiesForFilter as $familyName)
                                <option value="{{ $familyName }}"></option>
                            @endforeach
                        </datalist>
                    </label>

                    <label class="deliveroo-all-products-select-label">
                        <span>{{ __('deliveroo.all_products.filter_price_status_label') }}</span>
                        <select name="price_status" class="deliveroo-all-products-select">
                            <option value="">{{ __('deliveroo.all_products.filter_price_status_all') }}</option>
                            <option value="priced" @selected($priceStatusFilter === 'priced')>{{ __('deliveroo.all_products.filter_price_status_priced') }}</option>
                            <option value="unpriced" @selected($priceStatusFilter === 'unpriced')>{{ __('deliveroo.all_products.filter_price_status_unpriced') }}</option>
                        </select>
                    </label>

                    <label class="deliveroo-all-products-select-label">
                        <span>{{ __('deliveroo.all_products.filter_has_image_label') }}</span>
                        <select name="has_image" class="deliveroo-all-products-select">
                            <option value="">{{ __('deliveroo.all_products.filter_has_image_all') }}</option>
                            <option value="yes" @selected($hasImageFilter === 'yes')>{{ __('deliveroo.all_products.filter_has_image_yes') }}</option>
                            <option value="no" @selected($hasImageFilter === 'no')>{{ __('deliveroo.all_products.filter_has_image_no') }}</option>
                        </select>
                    </label>

                    <label class="deliveroo-all-products-select-label">
                        <span>{{ __('deliveroo.all_products.per_page_label') }}</span>
                        <select name="per_page" class="deliveroo-all-products-select">
                            @foreach ($allowedPerPage as $n)
                                <option value="{{ $n }}" @selected((int) $perPage === $n)>{{ number_format($n) }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="button-row">
                    <button type="submit" class="button button-primary">{{ __('deliveroo.all_products.apply') }}</button>
                    @if ($hasFilters)
                        <a href="{{ route('deliveroo-products.all', ['per_page' => $perPage]) }}" class="button">{{ __('deliveroo.all_products.clear') }}</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">{{ __('deliveroo.all_products.heading') }}</p>
                    <h3>{{ __('deliveroo.all_products.detail_total', ['count' => number_format($products->total())]) }}</h3>
                    <p class="page-note">
                        {{ __('deliveroo.all_products.page_indicator', ['current' => $products->currentPage(), 'last' => $products->lastPage()]) }}
                    </p>
                </div>
            </div>

            @if ($products->isEmpty())
                <article class="card">
                    <div class="brand-empty-state py-12">
                        <h3>{{ __('deliveroo.all_products.empty') }}</h3>
                        <p class="page-note mt-2">{{ __('deliveroo.all_products.empty_hint') }}</p>
                    </div>
                </article>
            @else
                <div class="deliveroo-product-grid deliveroo-product-grid--catalogue">
                    @foreach ($products as $product)
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
                                <p class="deliveroo-product-line">
                                    <span>{{ $categoryBySlug[$product->brand_slug] ?? 'Other' }}</span>
                                </p>

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

                <div class="deliveroo-all-products-pagination mt-8">
                    {{ $products->links() }}
                </div>
            @endif
        </section>
    </section>
@endsection
