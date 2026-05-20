@extends('layouts.app')

@section('title', 'All Source Products')
@section('section', 'Sources')
@section('heading', 'All Products')

@section('content')
    @php
        $hasFilters = $search !== '' || $source !== '';
        $shownLabel = $source !== '' ? ($sourceLabels[$source] ?? ucfirst($source)) : 'All sources';
    @endphp

    <section class="deliveroo-overview">
        <div class="deliveroo-hero-shell">
            <article class="deliveroo-hero">
                <div class="deliveroo-page-head">
                    <div class="deliveroo-page-head-copy">
                        <p class="eyebrow">Unified Source View</p>
                        <h2>All Source Products</h2>
                        <p class="page-note">
                            Browse every product row currently staged from Deliveroo, PDFs, store pictures, Mamado, and Janson.
                            Use this as the quick master list before deduping into real sellable products.
                        </p>
                    </div>
                    <div class="button-row">
                        <a href="{{ route('source-products.picture-brands') }}" class="button">Picture Brands</a>
                        <a href="{{ route('source-products.picture-only') }}" class="button">Need Match</a>
                    </div>
                </div>

                <ul class="deliveroo-hero-metrics">
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--primary">
                        <span class="deliveroo-hero-metric-label">Total</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['total']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Deliveroo</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['deliveroo']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">PDFs</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['pdf']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Pictures</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['pictures']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Mamado</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['mamado']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Janson</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['janson']) }}</span>
                    </li>
                </ul>
            </article>
        </div>

        <section class="deliveroo-search-panel">
            <div class="deliveroo-search-panel-copy">
                <p class="eyebrow">Database Search</p>
                <h3>Find products from every source</h3>
                <p class="page-note">Search by brand, family, variant, product name, item code, or source reference.</p>
            </div>

            <form method="GET" action="{{ route('source-products.index') }}" class="deliveroo-search-form">
                <label class="deliveroo-search-field">
                    <span class="sr-only">Search all source products</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search all source products..."
                        autocomplete="off"
                    >
                </label>

                <div class="deliveroo-all-products-filters">
                    <label class="deliveroo-all-products-select-label">
                        <span>Source</span>
                        <select name="source" class="deliveroo-all-products-select">
                            <option value="">All sources</option>
                            @foreach ($sourceLabels as $sourceKey => $sourceLabel)
                                <option value="{{ $sourceKey }}" @selected($source === $sourceKey)>{{ $sourceLabel }}</option>
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
                        <a href="{{ route('source-products.index', ['per_page' => $perPage]) }}" class="button">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">{{ $shownLabel }}</p>
                    <h3>{{ number_format($products->total()) }} product{{ $products->total() === 1 ? '' : 's' }}</h3>
                    <p class="page-note">Page {{ $products->currentPage() }} of {{ $products->lastPage() }}.</p>
                </div>
            </div>

            @if ($products->isEmpty())
                <article class="card">
                    <div class="brand-empty-state py-12">
                        <h3>No source products found</h3>
                        <p class="page-note mt-2">Try another search or source filter.</p>
                    </div>
                </article>
            @else
                <article class="card" style="padding:0; overflow:hidden;">
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:4rem">#</th>
                                    <th>Source</th>
                                    <th>Brand</th>
                                    <th>Family / Variant</th>
                                    <th>Product</th>
                                    <th>Code / Ref</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th style="width:8rem">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($products as $index => $product)
                                    @php
                                        $detailUrl = match ($product->source) {
                                            'deliveroo' => route('deliveroo-products.official-product', ['brand' => $product->source_key, 'product' => $product->source_id]),
                                            'pdf' => route('pdf-products.pages.show', $product->source_key),
                                            'pictures' => route('products.show', $product->source_id),
                                            'mamado' => route('mamado-products.show', $product->source_id),
                                            'janson' => route('source-products.index', ['source' => 'janson', 'search' => $product->item_code, 'per_page' => $perPage]),
                                            default => null,
                                        };
                                        $price = $product->price !== null ? trim(($product->currency ?: 'GBP').' '.number_format((float) $product->price, 2)) : '';
                                    @endphp
                                    <tr class="clickable-row" @if($detailUrl) data-href="{{ $detailUrl }}" @endif>
                                        <td style="color:#9a9590;font-variant-numeric:tabular-nums;">{{ $products->firstItem() + $index }}</td>
                                        <td>
                                            <span class="brand-chip">{{ $product->source_label }}</span>
                                        </td>
                                        <td>{{ $product->brand ?: 'Unknown' }}</td>
                                        <td>
                                            @if ($product->family_name)
                                                <strong>{{ $product->family_name }}</strong>
                                            @else
                                                <span class="page-note">No family</span>
                                            @endif
                                            @if ($product->variant_name)
                                                <div class="page-note">{{ $product->variant_name }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="clickable-row-name">{{ $product->product_name }}</span>
                                            @if ($product->image_count > 0)
                                                <div class="page-note">{{ number_format($product->image_count) }} image{{ $product->image_count === 1 ? '' : 's' }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($product->item_code)
                                                <code class="sw-chip-code">{{ $product->item_code }}</code>
                                            @endif
                                            @if ($product->source_ref)
                                                <div class="page-note" style="margin-top:4px;">{{ Str::limit($product->source_ref, 54) }}</div>
                                            @endif
                                        </td>
                                        <td>{{ $price !== '' ? $price : '-' }}</td>
                                        <td>
                                            @if ($product->status)
                                                <span class="pill{{ str_contains((string) $product->status, 'review') ? ' pill-warn' : '' }}">
                                                    {{ Str::headline($product->status) }}
                                                </span>
                                            @else
                                                <span class="page-note">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($detailUrl)
                                                <a href="{{ $detailUrl }}" class="button button-primary clickable-row-stop">Open</a>
                                            @else
                                                <span class="page-note">-</span>
                                            @endif
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
