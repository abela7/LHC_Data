@extends('layouts.app')

@section('title', $productName)

@section('content')
    @php
        $brandInitials = collect(preg_split('/\s+/', trim($canonicalBrand)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');
        $rangeQuery = array_filter([
            'picture_from' => $filters['picture_from'],
            'picture_to' => $filters['picture_to'],
        ]);
    @endphp

    <section class="brand-detail-hero product-detail-hero-compact">
        <div class="brand-detail-hero-main">
            <div class="brand-avatar brand-avatar-lg">{{ $brandInitials }}</div>
            <div class="brand-detail-hero-copy">
                <p class="eyebrow">Product Detail</p>
                <h2>{{ $productName }}</h2>
                <p class="brand-hero-note">This page shows the exact shop pictures used for this product, along with the observed labels and line context under the real brand <strong>{{ $canonicalBrand }}</strong>.</p>
                <div class="brand-hero-tags">
                    <span class="pill">{{ number_format($stats['pictures']) }} pictures</span>
                    <span class="pill">{{ number_format($stats['rows']) }} rows</span>
                    <span class="pill">{{ number_format($stats['observed_brands']) }} observed labels</span>
                    @if ($stats['lines'] > 0)
                        <span class="pill">{{ number_format($stats['lines']) }} lines</span>
                    @endif
                    @if ($rangeQuery !== [])
                        <span class="pill">{{ $filters['picture_from'] !== '' ? $filters['picture_from'] : 'start' }} to {{ $filters['picture_to'] !== '' ? $filters['picture_to'] : 'end' }}</span>
                    @endif
                </div>
            </div>
        </div>

        <aside class="brand-detail-panel brand-detail-panel-compact">
            <p class="helper-title">Quick actions</p>
            <div class="button-row">
                <a href="{{ route('real-brands.show', array_merge(['brand' => $canonicalBrand], $rangeQuery)) }}" class="button">Back to {{ $canonicalBrand }}</a>
                <a href="{{ route('brand-review.index', array_merge(['search' => $canonicalBrand], $rangeQuery)) }}" class="button button-primary">Review brand mapping</a>
            </div>
            <p class="brand-detail-panel-copy">The photo gallery below uses the local shop images that originally produced this product entry. This is the evidence layer before scraper enrichment.</p>
        </aside>
    </section>

    <section class="stats-grid brand-stats-grid brand-detail-stats product-detail-stats-compact">
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">pictures</p>
            <p class="stat-value">{{ number_format($stats['pictures']) }}</p>
            <p class="brand-stat-foot">Distinct local shop photos linked to this product.</p>
        </article>
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">observation rows</p>
            <p class="stat-value">{{ number_format($stats['rows']) }}</p>
            <p class="brand-stat-foot">Imported rows currently pointing at this product name.</p>
        </article>
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">observed labels</p>
            <p class="stat-value">{{ number_format($stats['observed_brands']) }}</p>
            <p class="brand-stat-foot">Observed brand labels represented across the linked pictures.</p>
        </article>
        <article class="card stat-card brand-stat-card">
            <p class="stat-label">lines</p>
            <p class="stat-value">{{ number_format($stats['lines']) }}</p>
            <p class="brand-stat-foot">Mapped brand lines or sub-lines currently linked to this product.</p>
        </article>
    </section>

    <article class="card">
        <div class="card-head">
            <h3>Shop photos used for this product</h3>
            <p>{{ $pictureCards->count() }} picture{{ $pictureCards->count() === 1 ? '' : 's' }}</p>
        </div>

        <div class="product-photo-grid product-photo-grid-compact">
            @forelse ($pictureCards as $picture)
                <article class="product-photo-card">
                    <div class="product-photo-media">
                        @if ($picture->image_url)
                            <img src="{{ $picture->image_url }}" alt="{{ $picture->picture_id }} for {{ $productName }}">
                        @else
                            <div class="product-photo-missing">
                                <span>{{ $picture->picture_id }}</span>
                                <small>Local image not found</small>
                            </div>
                        @endif
                    </div>

                    <div class="product-photo-body">
                        <div class="product-photo-head">
                            <h4>{{ $picture->picture_id }}</h4>
                            <span class="pill">{{ number_format($picture->row_count) }} row{{ $picture->row_count === 1 ? '' : 's' }}</span>
                        </div>

                        <div class="product-chip-block product-chip-block-tight">
                            <p class="product-chip-label">Observed labels</p>
                            <div class="brand-chip-row">
                                @forelse ($picture->observed_brands as $label)
                                    <span class="brand-chip brand-chip-soft">{{ $label }}</span>
                                @empty
                                    <span class="brand-chip brand-chip-muted">No observed label</span>
                                @endforelse
                            </div>
                        </div>

                        <div class="product-chip-block product-chip-block-tight">
                            <p class="product-chip-label">Lines</p>
                            <div class="brand-chip-row">
                                @forelse ($picture->lines as $line)
                                    <span class="brand-chip">{{ $line }}</span>
                                @empty
                                    <span class="brand-chip brand-chip-muted">No line</span>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </article>
            @empty
                <div class="brand-empty-state">
                    <h3>No pictures linked</h3>
                    <p>This product currently has no picture evidence attached.</p>
                </div>
            @endforelse
        </div>
    </article>

    <section class="split-grid brand-detail-grid">
        <article class="card">
            <div class="card-head">
                <h3>Observed labels under {{ $canonicalBrand }}</h3>
                <p>{{ $mappingSummary->count() }} mapping rows</p>
            </div>

            <div class="mapping-grid mapping-grid-compact">
                @forelse ($mappingSummary as $mapping)
                    <article class="mapping-card">
                        <p class="helper-title">Observed label</p>
                        <h4>{{ $mapping->observed_brand }}</h4>
                        <div class="mapping-meta">
                            <span class="pill">{{ $mapping->brand_line ?: 'No line' }}</span>
                        </div>
                        @if ($mapping->notes)
                            <p class="mapping-note">{{ $mapping->notes }}</p>
                        @endif
                    </article>
                @empty
                    <article class="mapping-card">
                        <h4>No mapping rows yet</h4>
                    </article>
                @endforelse
            </div>
        </article>

        <article class="card">
            <div class="card-head">
                <h3>Observation rows</h3>
                <p>{{ $rows->count() }} rows</p>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Picture</th>
                            <th>Observed brand</th>
                            <th>Real brand</th>
                            <th>Line</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ $row->picture_id }}</td>
                                <td>{{ $row->brand !== '' ? $row->brand : '-' }}</td>
                                <td>{{ $row->canonical_brand !== '' ? $row->canonical_brand : '-' }}</td>
                                <td>{{ $row->brand_line ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </article>
    </section>
@endsection
