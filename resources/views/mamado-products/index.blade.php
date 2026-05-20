@extends('layouts.app')

@section('title', 'Mamado Products')
@section('section', 'Mamado')
@section('heading', 'Products')

@section('content')
    @php
        $variantPct = $stats['products'] > 0 ? (int) round((($stats['products'] - $stats['variantReviewPending']) / $stats['products']) * 100) : 0;
        $hasFilters = $search !== '' || $brand !== '' || $family !== '' || $status !== '' || $sourceOrder !== '' || $hasImages || $viewMode !== '';
    @endphp

    @if (($brand !== '' && $family === '') || ($viewMode === 'families' && $brand === '' && $family === ''))
        <div class="deliveroo-brand-page">
            <header class="deliveroo-page-head">
                <div class="deliveroo-page-head-copy">
                    @if ($viewMode === 'families' && $brand === '')
                        <h2>Mamado Product Families</h2>
                    @else
                        <h2>{{ $brand }} Products</h2>
                    @endif
                    <p class="page-note">
                        @if ($viewMode === 'families' && $brand === '')
                            All Mamado products grouped by family. Open a family to see the source products underneath it.
                        @else
                            This brand is now grouped by product family. Open a family to work on the actual sellable Mamado source products under it.
                        @endif
                    </p>
                </div>

                <div class="deliveroo-page-head-actions button-row">
                    @if ($viewMode !== 'families' || $brand !== '')
                        <a href="{{ route('mamado-products.brands') }}" class="button">Back to Mamado Brands</a>
                    @endif
                    <a href="{{ route('mamado-products.index') }}" class="button">Back to Mamado Products</a>
                </div>
            </header>

            <section class="deliveroo-summary-bar">
                <article class="deliveroo-summary-item">
                    <strong>{{ number_format($brandFamilyGroups->sum('product_count')) }}</strong>
                    <span>Products</span>
                </article>
                <article class="deliveroo-summary-item">
                    <strong>{{ number_format($brandFamilyGroups->count()) }}</strong>
                    <span>Families</span>
                </article>
                <article class="deliveroo-summary-item">
                    <strong>{{ number_format($brandFamilyGroups->sum('image_count')) }}</strong>
                    <span>Image URLs</span>
                </article>
                <article class="deliveroo-summary-item deliveroo-summary-item--priced">
                    <strong>{{ number_format($brandFamilyGroups->sum('review_pending_count')) }}</strong>
                    <span>Variant Review</span>
                </article>
            </section>

            <section class="deliveroo-section">
                <div class="deliveroo-section-head">
                    <div class="deliveroo-section-head-copy">
                        <p class="eyebrow">Brand Progress</p>
                        <h3>Family overview</h3>
                    <p class="page-note">
                            {{ number_format($brandFamilyGroups->sum('product_count') - $brandFamilyGroups->sum('review_pending_count')) }} / {{ number_format($brandFamilyGroups->sum('product_count')) }} products have staged variants{{ $viewMode === 'families' && $brand === '' ? '.' : ' for this brand.' }}
                        </p>
                    </div>
                    <div class="deliveroo-section-head-meta">
                        @php
                            $brandReadyPct = $brandFamilyGroups->sum('product_count') > 0 ? round((($brandFamilyGroups->sum('product_count') - $brandFamilyGroups->sum('review_pending_count')) / $brandFamilyGroups->sum('product_count')) * 100) : 0;
                        @endphp
                        <div class="deliveroo-section-progress">
                            <span class="deliveroo-section-progress-label">{{ $brandReadyPct }}%</span>
                            <div class="deliveroo-section-progress-track">
                                <div class="deliveroo-section-progress-fill" style="width: {{ $brandReadyPct }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            @if ($brandFamilyGroups->isEmpty())
                <article class="card">
                    <div class="brand-empty-state py-12">
                        <h3>No product families loaded yet</h3>
                        <p class="page-note mt-2">This brand has no Mamado family records yet.</p>
                    </div>
                </article>
            @else
                <section class="deliveroo-section">
                    <div class="deliveroo-product-grid deliveroo-product-grid--catalogue">
                        @foreach ($brandFamilyGroups as $familyGroup)
                            @php
                                $familyReadyPct = $familyGroup['product_count'] > 0 ? round((($familyGroup['product_count'] - $familyGroup['review_pending_count']) / $familyGroup['product_count']) * 100) : 0;
                            @endphp

                            <article class="deliveroo-product-card{{ $familyGroup['review_pending_count'] === 0 ? ' is-priced' : '' }}">
                                <a href="{{ route('mamado-products.index', array_filter([
                                    'brand' => $brand,
                                    'family' => $familyGroup['name'] === 'Unstaged family' ? null : $familyGroup['name'],
                                    'status' => $status ?: null,
                                    'source_order' => $sourceOrder ?: null,
                                    'per_page' => $perPage,
                                ])) }}" class="deliveroo-product-media">
                                    @if ($familyGroup['primary_image'])
                                        <img src="{{ $familyGroup['primary_image'] }}" alt="{{ $familyGroup['name'] }}">
                                    @else
                                        <div class="deliveroo-product-media-empty">No image</div>
                                    @endif
                                </a>

                                <div class="deliveroo-product-body">
                                    <p class="deliveroo-product-line">
                                        <span>{{ $brand !== '' ? $brand : 'Mamado' }}</span>
                                        <span>/</span>
                                        <span>Family</span>
                                    </p>

                                    <h3>{{ $familyGroup['name'] }}</h3>

                                    <div class="deliveroo-card-price">
                                        <span>{{ number_format($familyGroup['product_count'] - $familyGroup['review_pending_count']) }} / {{ number_format($familyGroup['product_count']) }} variants ready</span>
                                        <small>{{ $familyReadyPct }}% complete</small>
                                    </div>

                                    <div class="deliveroo-product-stats">
                                        <span>{{ number_format($familyGroup['product_count']) }} products</span>
                                        <span>{{ number_format($familyGroup['image_count']) }} images</span>
                                    </div>

                                    @if ($familyGroup['variant_preview'] !== [])
                                        <div class="deliveroo-card-chip-row">
                                            @foreach ($familyGroup['variant_preview'] as $variantName)
                                                <span class="deliveroo-chip">{{ $variantName }}</span>
                                            @endforeach
                                            @if ($familyGroup['more_variants'] > 0)
                                                <span class="deliveroo-chip">+{{ $familyGroup['more_variants'] }} more</span>
                                            @endif
                                        </div>
                                    @endif

                                    <div class="button-row">
                                        <a href="{{ route('mamado-products.index', array_filter([
                                            'brand' => $brand,
                                            'family' => $familyGroup['name'] === 'Unstaged family' ? null : $familyGroup['name'],
                                            'view' => null,
                                            'status' => $status ?: null,
                                            'source_order' => $sourceOrder ?: null,
                                            'per_page' => $perPage,
                                        ])) }}" class="button button-primary">
                                            Open Product
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    @elseif ($brand !== '' && $family !== '')
        @php
            $familyProducts = $familyStats['products'] ?? $products->total();
            $familyReview = $familyStats['variant_review'] ?? 0;
            $familyReadyPct = $familyProducts > 0 ? round((($familyProducts - $familyReview) / $familyProducts) * 100) : 0;
        @endphp

        <div class="deliveroo-brand-page deliveroo-family-page">
            <header class="deliveroo-page-head">
                <div class="deliveroo-page-head-copy">
                    <p class="eyebrow">{{ $brand }} Family</p>
                    <h2>{{ $family }}</h2>
                    <p class="page-note">
                        These are the sellable Mamado source products under this family. Open any product for full details.
                    </p>
                </div>

                <div class="deliveroo-page-head-actions" aria-label="Family actions">
                    <div class="deliveroo-page-head-toolbar">
                        <a href="{{ route('mamado-products.index', ['brand' => $brand]) }}" class="button">Back to {{ $brand }}</a>
                        <a href="{{ route('mamado-products.index') }}" class="button">Back to Mamado Products</a>
                    </div>
                    <nav class="deliveroo-page-head-crumb" aria-label="Breadcrumb">
                        <a href="{{ route('mamado-products.index') }}" class="deliveroo-crumb-link">Mamado</a>
                        <span class="deliveroo-crumb-sep" aria-hidden="true">/</span>
                        <a href="{{ route('mamado-products.index', ['brand' => $brand]) }}" class="deliveroo-crumb-link">{{ $brand }}</a>
                    </nav>
                </div>
            </header>

            <section class="deliveroo-summary-bar deliveroo-summary-bar--triple" aria-label="Family stats">
                <article class="deliveroo-summary-item">
                    <strong>{{ number_format($familyProducts) }}</strong>
                    <span>Products</span>
                </article>
                <article class="deliveroo-summary-item">
                    <strong>{{ number_format($familyStats['images'] ?? 0) }}</strong>
                    <span>Image URLs</span>
                </article>
                <article class="deliveroo-summary-item deliveroo-summary-item--priced">
                    <strong>{{ number_format($familyReview) }}</strong>
                    <span>Variant Review</span>
                </article>
            </section>

            <section class="deliveroo-section">
                <div class="deliveroo-section-head">
                    <div class="deliveroo-section-head-copy">
                        <p class="eyebrow">Family Progress</p>
                        <h3>Variant overview</h3>
                        <p class="page-note">{{ number_format($familyProducts - $familyReview) }} / {{ number_format($familyProducts) }} products have staged variants in this family.</p>
                    </div>
                    <div class="deliveroo-section-head-meta">
                        <div class="deliveroo-section-progress">
                            <span class="deliveroo-section-progress-label">{{ $familyReadyPct }}%</span>
                            <div class="deliveroo-section-progress-track">
                                <div class="deliveroo-section-progress-fill" style="width: {{ $familyReadyPct }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            @if ($products->isEmpty())
                <article class="card">
                    <div class="brand-empty-state py-12">
                        <h3>No source products found</h3>
                        <p class="page-note mt-2">This family does not currently contain matching Mamado products.</p>
                    </div>
                </article>
            @else
                <section class="deliveroo-section">
                    <div class="deliveroo-catalogue-grid-host" data-view="grid" data-cols="4">
                        <div class="deliveroo-product-grid--family-products">
                            @foreach ($products as $product)
                                @php
                                    $primaryImage = collect($product->image_urls ?? [])->first();
                                    $isReady = $product->status !== 'variant_review_pending';
                                @endphp

                                <article class="deliveroo-product-card{{ $isReady ? ' is-priced' : '' }}">
                                    <a href="{{ route('mamado-products.show', $product) }}" class="deliveroo-product-media">
                                        @if ($primaryImage)
                                            <img src="{{ $primaryImage }}" alt="{{ $product->sellable_name ?: $product->item_description }}">
                                        @else
                                            <div class="deliveroo-product-media-empty">No image</div>
                                        @endif
                                    </a>

                                    <div class="deliveroo-product-body">
                                        <p class="deliveroo-product-line">
                                            <span>{{ $product->family_name }}</span>
                                            @if ($product->variant_name)
                                                <span>/</span>
                                                <span>{{ $product->variant_name }}</span>
                                            @else
                                                <span>/</span>
                                                <span>Review pending</span>
                                            @endif
                                        </p>

                                        <h3>{{ $product->sellable_name ?: $product->item_description }}</h3>

                                        <div class="deliveroo-card-price">
                                            <span>{{ $product->gross_unit_price_display }}</span>
                                            <small>Gross unit price</small>
                                        </div>

                                        @if ($product->status === 'variant_review_pending')
                                            <div class="deliveroo-card-needs-price">Review pending</div>
                                        @endif

                                        <div class="deliveroo-product-stats">
                                            <span>{{ count($product->image_urls ?? []) }} images</span>
                                            @if ($product->source_order_number)
                                                <span>Order {{ $product->source_order_number }}</span>
                                            @endif
                                        </div>

                                        <div class="button-row deliveroo-product-card-actions">
                                            <a href="{{ route('mamado-products.show', $product) }}" class="button button-primary">
                                                View Product
                                            </a>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endif
        </div>
    @else
        <section class="deliveroo-overview">
            <div class="deliveroo-hero-shell">
                <article class="deliveroo-hero">
                    <div class="deliveroo-page-head">
                        <div class="deliveroo-page-head-copy">
                            <p class="eyebrow">Supplier Source Check</p>
                            <h2>Mamado Products</h2>
                            <p class="page-note">
                                Mamado order-history products staged into brand, family, and variant records for website/POS enrichment.
                                Search, open brands, review families, and resolve products that need variant review.
                            </p>
                        </div>

                        <div class="deliveroo-pricing-progress">
                            <div class="deliveroo-pricing-progress-header">
                                <span class="deliveroo-pricing-progress-label">Variant progress</span>
                                <span class="deliveroo-pricing-progress-count">{{ number_format($stats['products'] - $stats['variantReviewPending']) }} / {{ number_format($stats['products']) }}</span>
                            </div>
                            <div class="deliveroo-pricing-progress-track" aria-hidden="true">
                                <div class="deliveroo-pricing-progress-fill" style="width: {{ $variantPct }}%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="deliveroo-hero-actions">
                        <a href="{{ route('mamado-products.brands') }}" class="button button-primary">Assigned Brands</a>
                        <a href="{{ route('mamado-products.index', ['status' => 'variant_review_pending']) }}" class="button">Variant Review</a>
                    </div>

                    <ul class="deliveroo-hero-metrics">
                        <li class="deliveroo-hero-metric deliveroo-hero-metric--primary">
                            <a href="{{ route('mamado-products.index') }}" style="display:block;color:inherit;text-decoration:none;">
                                <span class="deliveroo-hero-metric-label">Products</span>
                                <span class="deliveroo-hero-metric-value">{{ number_format($stats['products']) }}</span>
                            </a>
                        </li>
                        <li class="deliveroo-hero-metric">
                            <a href="{{ route('mamado-products.brands') }}" style="display:block;color:inherit;text-decoration:none;">
                                <span class="deliveroo-hero-metric-label">Brands</span>
                                <span class="deliveroo-hero-metric-value">{{ number_format($stats['brands']) }}</span>
                            </a>
                        </li>
                        <li class="deliveroo-hero-metric">
                            <a href="{{ route('mamado-products.index', ['view' => 'families']) }}" style="display:block;color:inherit;text-decoration:none;">
                                <span class="deliveroo-hero-metric-label">Families</span>
                                <span class="deliveroo-hero-metric-value">{{ number_format($stats['families']) }}</span>
                            </a>
                        </li>
                        <li class="deliveroo-hero-metric">
                            <a href="{{ route('mamado-products.index', ['has_images' => 1]) }}" style="display:block;color:inherit;text-decoration:none;">
                                <span class="deliveroo-hero-metric-label">Images</span>
                                <span class="deliveroo-hero-metric-value">{{ number_format($stats['images']) }}</span>
                            </a>
                        </li>
                        <li class="deliveroo-hero-metric deliveroo-hero-metric--priced">
                            <span class="deliveroo-hero-metric-label">Variant Review</span>
                            <span class="deliveroo-hero-metric-value">{{ number_format($stats['variantReviewPending']) }}</span>
                        </li>
                    </ul>
                </article>
            </div>

            <section class="deliveroo-search-panel">
                <div class="deliveroo-search-panel-copy">
                    <p class="eyebrow">Database Search</p>
                    <h3>Find Mamado source products</h3>
                    <p class="page-note">Use the same catalogue workflow as Deliveroo: filter, open a family, then review each sellable source product.</p>
                </div>

                <form method="GET" action="{{ route('mamado-products.index') }}" class="deliveroo-search-form">
                    @if ($hasImages)
                        <input type="hidden" name="has_images" value="1">
                    @endif
                    @if ($viewMode !== '')
                        <input type="hidden" name="view" value="{{ $viewMode }}">
                    @endif
                    <label class="deliveroo-search-field">
                        <span class="sr-only">Search Mamado products</span>
                        <input type="search" name="search" value="{{ $search }}" placeholder="Search item code, description, brand, family..." autocomplete="off">
                    </label>

                    <div class="deliveroo-all-products-filters">
                        <label class="deliveroo-all-products-select-label">
                            <span>Order</span>
                            <select name="source_order" class="deliveroo-all-products-select">
                                <option value="">All orders</option>
                                @foreach ($sourceOrders as $orderNumber)
                                    <option value="{{ $orderNumber }}" @selected($sourceOrder === $orderNumber)>Order {{ $orderNumber }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="deliveroo-all-products-select-label">
                            <span>Brand</span>
                            <select name="brand" class="deliveroo-all-products-select">
                                <option value="">All brands</option>
                                @foreach ($brandOptions as $brandOption)
                                    <option value="{{ $brandOption }}" @selected($brand === $brandOption)>{{ $brandOption }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="deliveroo-all-products-select-label">
                            <span>Family</span>
                            <select name="family" class="deliveroo-all-products-select">
                                <option value="">All families</option>
                                @foreach ($familyOptions as $familyOption)
                                    <option value="{{ $familyOption }}" @selected($family === $familyOption)>{{ $familyOption }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="deliveroo-all-products-select-label">
                            <span>Status</span>
                            <select name="status" class="deliveroo-all-products-select">
                                <option value="">All statuses</option>
                                @foreach ($statusOptions as $statusOption)
                                    <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ Str::headline($statusOption) }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="deliveroo-all-products-select-label">
                            <span>Per page</span>
                            <select name="per_page" class="deliveroo-all-products-select">
                                @foreach ($allowedPerPage as $option)
                                    <option value="{{ $option }}" @selected($perPage === $option)>{{ number_format($option) }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <div class="button-row">
                        <button type="submit" class="button button-primary">Apply</button>
                        @if ($hasFilters)
                            <a href="{{ route('mamado-products.index', ['per_page' => $perPage]) }}" class="button">Clear</a>
                        @endif
                    </div>
                </form>
            </section>

            <section class="deliveroo-section">
                <div class="deliveroo-section-head">
                    <div class="deliveroo-section-head-copy">
                        <p class="eyebrow">Mamado Products</p>
                        <h3>
                            {{ number_format($products->total()) }} product{{ $products->total() === 1 ? '' : 's' }}
                            @if ($hasImages)
                                with images
                            @endif
                        </h3>
                        <p class="page-note">Page {{ $products->currentPage() }} of {{ $products->lastPage() }}.</p>
                    </div>
                </div>

                @if ($products->isEmpty())
                    <article class="card">
                        <div class="brand-empty-state py-12">
                            <h3>No Mamado products found</h3>
                            <p class="page-note mt-2">Try another search or filter.</p>
                        </div>
                    </article>
                @else
                    <div class="deliveroo-product-grid deliveroo-product-grid--catalogue">
                        @foreach ($products as $product)
                            @php
                                $primaryImage = collect($product->image_urls ?? [])->first();
                                $hasVariant = filled($product->variant_name);
                                $isReady = $product->status !== 'variant_review_pending';
                            @endphp

                            <article class="deliveroo-product-card{{ $isReady ? ' is-priced' : '' }}">
                                <a href="{{ route('mamado-products.show', $product) }}" class="deliveroo-product-media">
                                    @if ($primaryImage)
                                        <img src="{{ $primaryImage }}" alt="{{ $product->sellable_name ?: $product->item_description }}">
                                    @else
                                        <div class="deliveroo-product-media-empty">No image</div>
                                    @endif
                                </a>

                                <div class="deliveroo-product-body">
                                    <p class="deliveroo-brand-kicker">{{ $product->brand_label ?: 'Mamado source' }}</p>
                                    <p class="deliveroo-product-line">
                                        @if ($product->family_name)
                                            <span>{{ $product->family_name }}</span>
                                        @endif
                                        @if ($product->family_name && $hasVariant)
                                            <span>/</span>
                                        @endif
                                        @if ($hasVariant)
                                            <span>{{ $product->variant_name }}</span>
                                        @elseif ($product->status === 'variant_review_pending')
                                            <span>Review pending</span>
                                        @endif
                                    </p>

                                    <h3>{{ $product->sellable_name ?: $product->item_description }}</h3>

                                    <div class="deliveroo-card-price">
                                        <span>{{ $product->gross_unit_price_display }}</span>
                                        <small>Gross unit price</small>
                                    </div>

                                    @if ($product->status === 'variant_review_pending')
                                        <div class="deliveroo-card-needs-price">Review pending</div>
                                    @endif

                                    <div class="deliveroo-product-stats">
                                        <span>{{ count($product->image_urls ?? []) }} images</span>
                                        @if ($product->source_order_number)
                                            <span>Order {{ $product->source_order_number }}</span>
                                        @endif
                                    </div>

                                    <div class="button-row">
                                        <a href="{{ route('mamado-products.show', $product) }}" class="button button-primary">View Product</a>
                                        @if ($product->brand_label)
                                            <a href="{{ route('mamado-products.index', ['brand' => $product->brand_label]) }}" class="button">Open Brand</a>
                                        @endif
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
    @endif
@endsection
