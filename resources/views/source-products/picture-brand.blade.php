@extends('layouts.app')

@section('title', $brand['display_name'].' Picture Products')
@section('section', 'Sources')
@section('heading', 'Picture Brand')

@section('content')
    @php
        $hasFilters = $search !== '';
    @endphp

    <section class="deliveroo-overview">
        <div class="deliveroo-hero-shell">
            <article class="deliveroo-hero">
                <div class="deliveroo-page-head">
                    <div class="deliveroo-page-head-copy">
                        <p class="eyebrow">Picture Source Brand</p>
                        <h2>{{ $brand['display_name'] }}</h2>
                        <p class="page-note">
                            Products observed from store photos only. Use this as shop evidence before linking or publishing sellable products.
                        </p>
                        <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:6px;">
                            @if ($brand['has_janson'] && $brand['has_mamado'])
                                <span class="pill">Janson + Mamado</span>
                            @elseif ($brand['has_janson'])
                                <span class="pill">Janson only</span>
                            @elseif ($brand['has_mamado'])
                                <span class="pill">Mamado only</span>
                            @else
                                <span class="pill pill-warn">No supplier match</span>
                            @endif
                            @if ($brand['janson_products'] > 0)
                                <span class="brand-chip">Janson {{ number_format($brand['janson_products']) }}</span>
                            @endif
                            @if ($brand['mamado_products'] > 0)
                                <span class="brand-chip">Mamado {{ number_format($brand['mamado_products']) }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="button-row">
                        <a href="{{ route('source-products.picture-brands') }}" class="button">All Picture Brands</a>
                        <a href="{{ route('source-products.index', ['source' => 'pictures', 'search' => $brand['display_name']]) }}" class="button">Raw Rows</a>
                        <a href="{{ route('pictures.index', ['brand' => $brand['display_name']]) }}" class="button">Pictures</a>
                    </div>
                </div>

                <ul class="deliveroo-hero-metrics">
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--primary">
                        <span class="deliveroo-hero-metric-label">Products</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['products']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Product Hits</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['picture_hits']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Pictures</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['pictures']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Observed Labels</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['brand_labels']) }}</span>
                    </li>
                </ul>
            </article>
        </div>

        <section class="deliveroo-search-panel">
            <div class="deliveroo-search-panel-copy">
                <p class="eyebrow">Brand Search</p>
                <h3>Find a product in this picture brand</h3>
                <p class="page-note">Search product text, observed labels, lines, or picture IDs.</p>
            </div>

            <form method="GET" action="{{ route('source-products.picture-brands.show', $brand['key']) }}" class="deliveroo-search-form">
                <label class="deliveroo-search-field">
                    <span class="sr-only">Search products for {{ $brand['display_name'] }}</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search this brand..."
                        autocomplete="off"
                    >
                </label>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Apply</button>
                    @if ($hasFilters)
                        <a href="{{ route('source-products.picture-brands.show', $brand['key']) }}" class="button">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">Observed Products</p>
                    <h3>{{ number_format($products->count()) }} product{{ $products->count() === 1 ? '' : 's' }}</h3>
                    <p class="page-note">Grouped by observed product name, with every picture ID kept visible for review.</p>
                </div>
            </div>

            @if ($products->isEmpty())
                <article class="card">
                    <div class="brand-empty-state py-12">
                        <h3>No picture products found</h3>
                        <p class="page-note mt-2">Try another search or clear the filter.</p>
                    </div>
                </article>
            @else
                <article class="card" style="padding:0; overflow:hidden;">
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:4rem">#</th>
                                    <th>Observed Product</th>
                                    <th>Brand Labels / Lines</th>
                                    <th>Picture Evidence</th>
                                    <th style="width:12rem">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($products as $index => $product)
                                    @php
                                        $detailUrl = $product['first_observed_product_id'] ? route('products.show', $product['first_observed_product_id']) : null;
                                    @endphp
                                    <tr class="clickable-row" @if($detailUrl) data-href="{{ $detailUrl }}" @endif>
                                        <td style="color:#9a9590;font-variant-numeric:tabular-nums;">{{ $index + 1 }}</td>
                                        <td>
                                            <strong>{{ $product['product_name'] }}</strong>
                                            <div class="page-note">
                                                {{ number_format($product['picture_hits']) }} hit{{ $product['picture_hits'] === 1 ? '' : 's' }}
                                                across {{ number_format($product['pictures']) }} picture{{ $product['pictures'] === 1 ? '' : 's' }}
                                            </div>
                                        </td>
                                        <td>
                                            @if ($product['observed_brand_labels'])
                                                <div>{{ implode(', ', $product['observed_brand_labels']) }}</div>
                                            @endif
                                            @if ($product['brand_lines'])
                                                <div class="page-note">Lines: {{ implode(', ', $product['brand_lines']) }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                                @foreach (array_slice($product['picture_ids'], 0, 8) as $pictureId)
                                                    <a href="{{ route('pictures.show', $pictureId) }}" class="brand-chip clickable-row-stop">{{ $pictureId }}</a>
                                                @endforeach
                                                @if (count($product['picture_ids']) > 8)
                                                    <span class="pill">+{{ count($product['picture_ids']) - 8 }} more</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td>
                                            <div class="button-row" style="gap:6px;">
                                                @if ($detailUrl)
                                                    <a href="{{ $detailUrl }}" class="button button-primary clickable-row-stop">Product</a>
                                                @endif
                                                @if ($product['first_picture_id'])
                                                    <a href="{{ route('pictures.show', $product['first_picture_id']) }}" class="button clickable-row-stop">Photo</a>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </article>
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
