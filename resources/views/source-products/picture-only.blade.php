@extends('layouts.app')

@section('title', 'Picture Source Match Needed')
@section('section', 'Sources')
@section('heading', 'Source Match Needed')

@section('content')
    @php
        $hasFilters = $search !== '' || $catalogue !== '' || $brand !== '' || $productType !== '';
    @endphp

    <section class="deliveroo-overview">
        <div class="deliveroo-hero-shell">
            <article class="deliveroo-hero">
                <div class="deliveroo-page-head">
                    <div class="deliveroo-page-head-copy">
                        <p class="eyebrow">Shop Photo Evidence</p>
                        <h2>Picture Source Match Needed</h2>
                        <p class="page-note">
                            These products are visible in shop photos but do not yet have a safe PDF, Mamado, or Janson match attached.
                            Use this list to structure, verify, and enrich them into final sellable products.
                        </p>
                    </div>
                    <div class="button-row">
                        <a href="{{ route('pictures.index') }}" class="button">Open Pictures</a>
                        <a href="{{ route('source-products.picture-brands') }}" class="button">Picture Brands</a>
                        <a href="{{ route('source-products.index', ['source' => 'pictures']) }}" class="button">All Picture Sources</a>
                    </div>
                </div>

                <ul class="deliveroo-hero-metrics">
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--primary">
                        <span class="deliveroo-hero-metric-label">Need Match</span>
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
                        <span class="deliveroo-hero-metric-label">Catalogues</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['catalogues']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Variant Review</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['review_pending_variants']) }}</span>
                    </li>
                </ul>
            </article>
        </div>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">Breakdown</p>
                    <h3>Where the products needing source match sit</h3>
                </div>
            </div>

            <div class="deliveroo-family-grid">
                @foreach ($breakdown as $row)
                    <article class="deliveroo-family-card">
                        <div>
                            <p class="eyebrow">{{ $row->catalogue_name }}</p>
                            <h3>{{ number_format((int) $row->product_count) }} products</h3>
                            <p class="page-note">{{ number_format((int) $row->brand_count) }} brand{{ (int) $row->brand_count === 1 ? '' : 's' }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="deliveroo-search-panel">
            <div class="deliveroo-search-panel-copy">
                <p class="eyebrow">Review Queue</p>
                <h3>Find products needing source match</h3>
                <p class="page-note">Search by brand, family, SKU name, product type, or picture note.</p>
            </div>

            <form method="GET" action="{{ route('source-products.picture-only') }}" class="deliveroo-search-form">
                <label class="deliveroo-search-field">
                    <span class="sr-only">Search products needing source match</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search products needing source match..."
                        autocomplete="off"
                    >
                </label>

                <div class="deliveroo-all-products-filters">
                    <label class="deliveroo-all-products-select-label">
                        <span>Catalogue</span>
                        <select name="catalogue" class="deliveroo-all-products-select">
                            <option value="">All catalogues</option>
                            @foreach ($catalogueOptions as $option)
                                <option value="{{ $option }}" @selected($catalogue === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="deliveroo-all-products-select-label">
                        <span>Brand</span>
                        <select name="brand" class="deliveroo-all-products-select">
                            <option value="">All brands</option>
                            @foreach ($brandOptions as $option)
                                <option value="{{ $option }}" @selected($brand === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="deliveroo-all-products-select-label">
                        <span>Product type</span>
                        <select name="product_type" class="deliveroo-all-products-select">
                            <option value="">All product types</option>
                            @foreach ($productTypeOptions as $option)
                                <option value="{{ $option }}" @selected($productType === $option)>{{ $option }}</option>
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
                        <a href="{{ route('source-products.picture-only', ['per_page' => $perPage]) }}" class="button">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">Source Match Queue</p>
                    <h3>{{ number_format($products->total()) }} product{{ $products->total() === 1 ? '' : 's' }}</h3>
                    <p class="page-note">Page {{ $products->currentPage() }} of {{ $products->lastPage() }}.</p>
                </div>
            </div>

            @if ($products->isEmpty())
                <article class="card">
                    <div class="brand-empty-state py-12">
                        <h3>No products needing source match found</h3>
                        <p class="page-note mt-2">Try another search or clear the filters.</p>
                    </div>
                </article>
            @else
                <article class="card" style="padding:0; overflow:hidden;">
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:4rem">#</th>
                                    <th>Picture</th>
                                    <th>Brand</th>
                                    <th>Family / SKU</th>
                                    <th>Variants</th>
                                    <th>Catalogue / Type</th>
                                    <th>Status</th>
                                    <th style="width:12rem">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($products as $index => $product)
                                    <tr class="clickable-row" data-href="{{ $product->sku_url }}">
                                        <td style="color:#9a9590;font-variant-numeric:tabular-nums;">{{ $products->firstItem() + $index }}</td>
                                        <td>
                                            @if ($product->picture_url)
                                                <a href="{{ $product->picture_url }}" class="button clickable-row-stop">{{ $product->first_picture_id }}</a>
                                            @else
                                                <span class="page-note">No picture link</span>
                                            @endif
                                            @if (count($product->picture_ids) > 1)
                                                <div class="page-note">{{ count($product->picture_ids) }} photo hits</div>
                                            @endif
                                        </td>
                                        <td>
                                            <strong>{{ $product->brand_name }}</strong>
                                            @if ($product->line_name && $product->line_name !== $product->brand_name)
                                                <div class="page-note">{{ $product->line_name }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            <strong>{{ $product->family_name }}</strong>
                                            <div class="page-note">{{ $product->sku_name }}</div>
                                            @if ($product->observed_as)
                                                <div class="page-note">Observed: {{ Str::limit($product->observed_as, 90) }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($product->variant_summary === 'Review pending')
                                                <span class="pill pill-warn">Review pending</span>
                                            @else
                                                {{ $product->variant_summary }}
                                            @endif
                                        </td>
                                        <td>
                                            <span class="brand-chip">{{ $product->catalogue_name }}</span>
                                            <div class="page-note" style="margin-top:4px;">{{ $product->product_type_name }}</div>
                                        </td>
                                        <td>
                                            <span class="pill pill-warn">Needs source match</span>
                                            <div class="page-note">No safe PDF/Mamado/Janson match yet</div>
                                        </td>
                                        <td>
                                            <div class="button-row" style="gap:6px;">
                                                <a href="{{ $product->sku_url }}" class="button button-primary clickable-row-stop">SKU</a>
                                                @if ($product->picture_url)
                                                    <a href="{{ $product->picture_url }}" class="button clickable-row-stop">Photo</a>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </article>

                <div class="pagination-wrap">
                    {{ $products->links() }}
                </div>
            @endif
        </section>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.clickable-row[data-href]').forEach(function (row) {
                row.addEventListener('click', function (event) {
                    if (event.target.closest('.clickable-row-stop')) return;

                    if (event.ctrlKey || event.metaKey) {
                        window.open(row.dataset.href, '_blank');
                    } else {
                        window.location.href = row.dataset.href;
                    }
                });
            });
        });
    </script>
@endsection
