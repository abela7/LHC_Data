@extends('layouts.app')

@section('title', $brand['display_name'].' Retail Families')
@section('section', 'Retail Products')
@section('heading', $brand['display_name'])

@section('content')
    <section class="deliveroo-overview">
        <div class="deliveroo-hero-shell">
            <article class="deliveroo-hero">
                <div class="deliveroo-page-head">
                    <div class="deliveroo-page-head-copy">
                        <p class="eyebrow">Combined Brand</p>
                        <h2>{{ $brand['display_name'] }}</h2>
                        <p class="page-note">
                            Product families from Janson and Mamado are listed together with store-photo evidence.
                        </p>
                    </div>
                    <div class="button-row">
                        <a href="{{ route('retail-products.brands') }}" class="button">Back to brands</a>
                        <a href="{{ route('retail-products.index', ['source' => 'all', 'brand' => $brand['display_name']]) }}" class="button button-primary">Open products</a>
                        @if ($brand['picture_products'] > 0)
                            <a href="{{ route('source-products.picture-brands.show', $brand['key']) }}" class="button">Picture evidence</a>
                        @endif
                    </div>
                </div>

                <ul class="deliveroo-hero-metrics">
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--primary">
                        <span class="deliveroo-hero-metric-label">SKU candidates</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['products']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Families</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['families']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Picture products</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['picture_products']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Janson SKUs</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['janson_products']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Mamado SKUs</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['mamado_products']) }}</span>
                    </li>
                </ul>
            </article>
        </div>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">Family List</p>
                    <h3>{{ number_format($families->count()) }} combined famil{{ $families->count() === 1 ? 'y' : 'ies' }}</h3>
                    <p class="page-note">{{ number_format($stats['both_source_families']) }} families have both Janson and Mamado evidence.</p>
                </div>
            </div>

            @if ($families->isEmpty())
                <article class="card">
                    <div class="brand-empty-state py-12">
                        <h3>No families found</h3>
                    </div>
                </article>
            @else
                <article class="card" style="padding:0; overflow:hidden;">
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Family</th>
                                    <th>Department</th>
                                    <th>Type</th>
                                    <th>Sources</th>
                                    <th>SKU candidates</th>
                                    <th>Picture</th>
                                    <th>Janson</th>
                                    <th>Mamado</th>
                                    <th>Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($families as $family)
                                    <tr>
                                        <td>
                                            <strong>{{ $family['family_name'] }}</strong>
                                            @if ($family['families'] > 1)
                                                <div class="page-note">{{ number_format($family['families']) }} internal family records grouped</div>
                                            @endif
                                        </td>
                                        <td>{{ $family['department'] ?: '-' }}</td>
                                        <td>{{ $family['product_type'] ?: '-' }}</td>
                                        <td>
                                            @foreach ($family['sources'] as $source)
                                                <span class="pill {{ $source === 'Mamado' ? 'pill-success' : ($source === 'Picture' ? 'pill-warn' : '') }}">{{ $source }}</span>
                                            @endforeach
                                            @if ($family['review_count'] > 0)
                                                <span class="pill pill-warn">{{ number_format($family['review_count']) }} review</span>
                                            @endif
                                        </td>
                                        <td>{{ number_format($family['products']) }}</td>
                                        <td>
                                            {{ number_format($family['picture_products']) }}
                                            @if ($family['pictures'] > 0)
                                                <div class="page-note">{{ implode(', ', array_slice($family['picture_ids'], 0, 3)) }}</div>
                                            @endif
                                        </td>
                                        <td>{{ number_format($family['janson_products']) }}</td>
                                        <td>{{ number_format($family['mamado_products']) }}</td>
                                        <td>
                                            <div class="button-row" style="gap:.35rem;">
                                                @foreach ($family['family_links'] as $link)
                                                    <a href="{{ route('retail-products.families.show', $link['id']) }}" class="button button-primary">
                                                        {{ implode('+', $link['sources']) }}
                                                    </a>
                                                @endforeach
                                                @if ($family['picture_products'] > 0)
                                                    <a href="{{ route('source-products.picture-brands.show', $brand['key']) }}" class="button">
                                                        Pictures
                                                    </a>
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
@endsection
