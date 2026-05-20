@extends('layouts.app')

@section('title', 'Brand Source Comparison')
@section('section', 'Sources')
@section('heading', 'Brand Comparison')

@section('content')
    @php
        $hasFilters = $search !== '';
    @endphp

    <section class="deliveroo-overview">
        <div class="deliveroo-hero-shell">
            <article class="deliveroo-hero">
                <div class="deliveroo-page-head">
                    <div class="deliveroo-page-head-copy">
                        <p class="eyebrow">Brand Lists</p>
                        <h2>Picture / Janson / Mamado Brands</h2>
                        <p class="page-note">A plain three-column table. Each column lists all brands from that source.</p>
                    </div>
                    <div class="button-row">
                        <a href="{{ route('pictures.index') }}" class="button">Open Pictures</a>
                        <a href="{{ route('source-products.index', ['source' => 'pictures']) }}" class="button">Picture Rows</a>
                        <a href="{{ route('source-products.picture-only') }}" class="button">Need Match</a>
                    </div>
                </div>

                <ul class="deliveroo-hero-metrics">
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--primary">
                        <span class="deliveroo-hero-metric-label">Rows</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['rows']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Picture Brands</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['picture_brands']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Janson Brands</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['janson_brands']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Mamado Brands</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['mamado_brands']) }}</span>
                    </li>
                </ul>
            </article>
        </div>

        <section class="deliveroo-search-panel">
            <div class="deliveroo-search-panel-copy">
                <p class="eyebrow">Search</p>
                <h3>Search all three brand lists</h3>
                <p class="page-note">Search by brand name, observed label, or sample picture product.</p>
            </div>

            <form method="GET" action="{{ route('source-products.picture-brands') }}" class="deliveroo-search-form">
                <label class="deliveroo-search-field">
                    <span class="sr-only">Search brand lists</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search picture, Janson, or Mamado brands..."
                        autocomplete="off"
                    >
                </label>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Apply</button>
                    @if ($hasFilters)
                        <a href="{{ route('source-products.picture-brands') }}" class="button">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">Table</p>
                    <h3>All brands in three columns</h3>
                    <p class="page-note">This is not grouped as cards. It is a direct side-by-side table list.</p>
                </div>
            </div>

            @if ($brandRows->isEmpty())
                <article class="card">
                    <div class="brand-empty-state py-12">
                        <h3>No brands found</h3>
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
                                    <th style="width:32%">Picture Brands</th>
                                    <th style="width:32%">Janson Brands</th>
                                    <th style="width:32%">Mamado Brands</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($brandRows as $index => $row)
                                    <tr>
                                        <td style="color:#9a9590;font-variant-numeric:tabular-nums;">{{ $index + 1 }}</td>

                                        <td>
                                            @if ($row['picture'])
                                                <a href="{{ $row['picture']['url'] }}" class="clickable-row-name">
                                                    <strong>{{ $row['picture']['display_name'] }}</strong>
                                                </a>
                                                <div class="page-note">
                                                    {{ number_format($row['picture']['products']) }} products ·
                                                    {{ number_format($row['picture']['hits']) }} hits ·
                                                    {{ number_format($row['picture']['pictures']) }} pictures
                                                </div>
                                                @if ($row['picture']['source_brand_labels'])
                                                    <div class="page-note">Observed: {{ Str::limit(implode(', ', $row['picture']['source_brand_labels']), 90) }}</div>
                                                @endif
                                                @if ($row['picture']['sample_products'])
                                                    <div class="page-note">Sample: {{ Str::limit(implode(', ', array_slice($row['picture']['sample_products'], 0, 3)), 90) }}</div>
                                                @endif
                                            @else
                                                <span class="page-note">-</span>
                                            @endif
                                        </td>

                                        <td>
                                            @if ($row['janson'])
                                                <a href="{{ $row['janson']['url'] }}" class="clickable-row-name">
                                                    <strong>{{ $row['janson']['display_name'] }}</strong>
                                                </a>
                                                <div class="page-note">{{ number_format($row['janson']['products']) }} SKU candidates</div>
                                                @if (count($row['janson']['names']) > 1)
                                                    <div class="page-note">Also: {{ Str::limit(implode(', ', array_slice($row['janson']['names'], 1)), 90) }}</div>
                                                @endif
                                            @else
                                                <span class="page-note">-</span>
                                            @endif
                                        </td>

                                        <td>
                                            @if ($row['mamado'])
                                                <a href="{{ $row['mamado']['url'] }}" class="clickable-row-name">
                                                    <strong>{{ $row['mamado']['display_name'] }}</strong>
                                                </a>
                                                <div class="page-note">{{ number_format($row['mamado']['products']) }} SKU candidates</div>
                                                @if (count($row['mamado']['names']) > 1)
                                                    <div class="page-note">Also: {{ Str::limit(implode(', ', array_slice($row['mamado']['names'], 1)), 90) }}</div>
                                                @endif
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
            @endif
        </section>
    </section>
@endsection
