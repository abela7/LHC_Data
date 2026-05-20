@extends('layouts.app')

@section('title', 'Picture Product Hits')
@section('section', 'Pictures')
@section('heading', 'Product Hits')

@section('content')
    @php
        $hasFilters = $filters['search'] !== '' || $filters['brand'] !== '' || $filters['category'] !== '';
    @endphp

    <section class="deliveroo-overview">
        <div class="deliveroo-hero-shell">
            <article class="deliveroo-hero">
                <div class="deliveroo-page-head">
                    <div class="deliveroo-page-head-copy">
                        <p class="eyebrow">Picture Source Review</p>
                        <h2>All Picture Product Hits</h2>
                        <p class="page-note">
                            One row per product text found in a picture. This is evidence, not final sellable structure.
                        </p>
                    </div>
                    <div class="button-row">
                        <a href="{{ route('pictures.index') }}" class="button">Picture browser</a>
                        <a href="{{ route('retail-products.brands') }}" class="button button-primary">Combined brands</a>
                    </div>
                </div>

                <ul class="deliveroo-hero-metrics">
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--primary">
                        <span class="deliveroo-hero-metric-label">Product Hits</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['product_hits']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Raw Rows</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['raw_rows']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Pictures</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['pictures']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Brands</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['brands']) }}</span>
                    </li>
                </ul>
            </article>
        </div>

        <section class="deliveroo-search-panel">
            <div class="deliveroo-search-panel-copy">
                <p class="eyebrow">Review Filter</p>
                <h3>Find picture evidence</h3>
                <p class="page-note">Filter by picture ID, product text, observed brand, clean brand, line, or category.</p>
            </div>

            <form method="GET" action="{{ route('pictures.product-hits') }}" class="deliveroo-search-form">
                <label class="deliveroo-search-field">
                    <span class="sr-only">Search picture product hits</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ $filters['search'] }}"
                        placeholder="Search all picture product hits..."
                        autocomplete="off"
                    >
                </label>

                <div class="deliveroo-all-products-filters">
                    <label class="deliveroo-all-products-select-label">
                        <span>Clean Brand</span>
                        <select name="brand" class="deliveroo-all-products-select">
                            <option value="">All brands</option>
                            @foreach ($brandOptions as $brand)
                                <option value="{{ $brand === '' ? '__blank__' : $brand }}" @selected($filters['brand'] === ($brand === '' ? '__blank__' : $brand))>
                                    {{ $brand === '' ? '[blank]' : $brand }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="deliveroo-all-products-select-label">
                        <span>Category</span>
                        <select name="category" class="deliveroo-all-products-select">
                            <option value="">All categories</option>
                            @foreach ($categoryOptions as $category)
                                <option value="{{ $category->slug }}" @selected($filters['category'] === $category->slug)>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Apply</button>
                    @if ($hasFilters)
                        <a href="{{ route('pictures.product-hits') }}" class="button">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">One Page List</p>
                    <h3>{{ number_format($hits->count()) }} product hit{{ $hits->count() === 1 ? '' : 's' }}</h3>
                    <p class="page-note">
                        {{ number_format($stats['duplicate_hits']) }} hit{{ $stats['duplicate_hits'] === 1 ? '' : 's' }} contain more than one raw row.
                    </p>
                </div>
            </div>

            <article class="card" style="padding:0; overflow:hidden;">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:5rem">#</th>
                                <th>Product Text</th>
                                <th>Clean Brand</th>
                                <th>Observed Brand</th>
                                <th>Category</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($hits as $index => $hit)
                                @php
                                    $realBrands = collect($hit->real_brand_entries ?? []);
                                    $observedBrands = collect(explode(',', (string) $hit->observed_brands))->map(fn ($value) => trim($value))->filter()->values();
                                    $categories = collect(explode(',', (string) $hit->categories))->map(fn ($value) => trim($value))->filter()->values();
                                    $detailUrl = route('pictures.show', ['pictureId' => $hit->picture_id]);
                                @endphp
                                <tr class="clickable-row" data-href="{{ $detailUrl }}">
                                    <td>{{ number_format($index + 1) }}</td>
                                    <td>
                                        <strong>{{ $hit->product_name }}</strong>
                                        <div class="page-note">{{ $hit->picture_id }}</div>
                                        @if ((int) $hit->raw_row_count > 1)
                                            <div class="page-note">Raw row IDs: {{ $hit->row_ids }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        @forelse ($realBrands as $brand)
                                            <a href="{{ route('retail-products.brands.show', $brand->key) }}" class="brand-chip">
                                                {{ $brand->name }}
                                            </a>
                                        @empty
                                            <span class="page-note">-</span>
                                        @endforelse
                                    </td>
                                    <td>
                                        @forelse ($observedBrands as $brand)
                                            <span class="pill">{{ $brand }}</span>
                                        @empty
                                            <span class="page-note">-</span>
                                        @endforelse
                                    </td>
                                    <td>
                                        @forelse ($categories as $category)
                                            <span class="pill">{{ $category }}</span>
                                        @empty
                                            <span class="page-note">-</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">
                                        <div class="brand-empty-state py-12">
                                            <h3>No product hits found</h3>
                                            <p class="page-note mt-2">Try clearing the filters.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.clickable-row[data-href]').forEach(function (row) {
                row.addEventListener('click', function (event) {
                    if (event.target.closest('a, button, input, select, textarea')) return;

                    window.location.href = row.dataset.href;
                });
            });
        });
    </script>
@endsection
