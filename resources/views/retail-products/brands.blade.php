@extends('layouts.app')

@section('title', 'Retail Brands')
@section('section', 'Retail Products')
@section('heading', 'Brands')

@section('content')
    <section class="deliveroo-overview">
        <div class="deliveroo-hero-shell">
            <article class="deliveroo-hero">
                <div class="deliveroo-page-head">
                    <div class="deliveroo-page-head-copy">
                        <p class="eyebrow">Combined Source Brands</p>
                        <h2>Picture + Janson + Mamado brand map</h2>
                        <p class="page-note">
                            Brands are grouped by normalized name. Open a brand to see supplier families and shop-photo evidence in one place.
                        </p>
                    </div>
                    <div class="button-row">
                        <a href="{{ route('retail-products.index', ['source' => 'all']) }}" class="button">All products</a>
                    </div>
                </div>

                <ul class="deliveroo-hero-metrics">
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--primary">
                        <span class="deliveroo-hero-metric-label">Combined brands</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['brands']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Source overlaps</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['matched_brands']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Picture brands</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['picture_brands']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">SKU candidates</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['products']) }}</span>
                    </li>
                </ul>
            </article>
        </div>

        <section class="deliveroo-search-panel">
            <div class="deliveroo-search-panel-copy">
                <p class="eyebrow">Brand Search</p>
                <h3>Find a combined brand</h3>
                <p class="page-note">Matched brands show Picture, Janson, and Mamado counts in one row.</p>
            </div>

            <form method="GET" action="{{ route('retail-products.brands') }}" class="deliveroo-search-form">
                <label class="deliveroo-search-field">
                    <span class="sr-only">Search brands</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search brand..."
                        autocomplete="off"
                    >
                </label>
                <div class="button-row">
                    <button type="submit" class="button button-primary">Search</button>
                    @if ($search !== '')
                        <a href="{{ route('retail-products.brands') }}" class="button">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">Brands</p>
                    <h3>{{ number_format($brands->count()) }} result{{ $brands->count() === 1 ? '' : 's' }}</h3>
                    <p class="page-note">Use this as the brand-by-brand work list for retail products.</p>
                </div>
            </div>

            @if ($brands->isEmpty())
                <article class="card">
                    <div class="brand-empty-state py-12">
                        <h3>No brands found</h3>
                        <p class="page-note mt-2">Try another search.</p>
                    </div>
                </article>
            @else
                <article class="card" style="padding:0; overflow:hidden;">
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Brand</th>
                                    <th>Sources</th>
                                    <th>SKU candidates</th>
                                    <th>Families</th>
                                    <th>Picture</th>
                                    <th>Janson</th>
                                    <th>Mamado</th>
                                    <th>Review</th>
                                    <th style="width:8rem">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($brands as $brand)
                                    @php
                                        $detailUrl = route('retail-products.brands.show', $brand['key']);
                                    @endphp
                                    <tr class="clickable-row" data-href="{{ $detailUrl }}">
                                        <td>
                                            <strong>{{ $brand['display_name'] }}</strong>
                                            @if (count($brand['source_names']) > 1)
                                                <div class="page-note">{{ implode(' / ', $brand['source_names']) }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            @foreach ($brand['sources'] as $source)
                                                <span class="pill {{ $source === 'Mamado' ? 'pill-success' : ($source === 'Picture' ? 'pill-warn' : '') }}">{{ $source }}</span>
                                            @endforeach
                                            @if ($brand['is_matched'])
                                                <span class="pill">Overlap</span>
                                            @endif
                                        </td>
                                        <td>{{ number_format($brand['products']) }}</td>
                                        <td>{{ number_format($brand['families']) }}</td>
                                        <td>
                                            {{ number_format($brand['picture_products']) }}
                                            @if ($brand['pictures'] > 0)
                                                <div class="page-note">{{ number_format($brand['pictures']) }} photo{{ $brand['pictures'] === 1 ? '' : 's' }}</div>
                                            @endif
                                        </td>
                                        <td>{{ number_format($brand['janson_products']) }}</td>
                                        <td>{{ number_format($brand['mamado_products']) }}</td>
                                        <td>{{ number_format($brand['review_count']) }}</td>
                                        <td><a href="{{ $detailUrl }}" class="button button-primary clickable-row-stop">Open</a></td>
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

                    window.location.href = row.dataset.href;
                });
            });
        });
    </script>
@endsection
