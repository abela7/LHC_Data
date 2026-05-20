@extends('layouts.app')

@section('title', 'Real Brand Review')

@section('content')
    {{-- Header row: title + stats inline + seed button --}}
    <div class="br-header">
        <div class="br-header-left">
            <div>
                <p class="eyebrow">Brand Review</p>
                <h2 class="page-title">Brand Mappings</h2>
            </div>
            <div class="br-stat-pills">
                <span class="br-stat"><strong>{{ number_format($stats['observed_brands']) }}</strong> observed</span>
                <span class="br-stat"><strong>{{ number_format($stats['real_brands']) }}</strong> real</span>
                <span class="br-stat"><strong>{{ number_format($stats['products_with_real_brand']) }}</strong> mapped rows</span>
                @if ($filters['picture_from'] !== '' || $filters['picture_to'] !== '')
                    <span class="br-stat br-stat-filter">range {{ $filters['picture_from'] !== '' ? $filters['picture_from'] : 'start' }}&ndash;{{ $filters['picture_to'] !== '' ? $filters['picture_to'] : 'end' }}</span>
                @endif
            </div>
        </div>
        <form method="POST" action="{{ route('brand-mappings.seed-missing') }}">
            @csrf
            <button type="submit" class="button">Seed missing</button>
        </form>
    </div>

    {{-- Duplicate groups: collapsible, compact --}}
    @if (count($candidateGroups) > 0)
        <details class="br-dupes-panel">
            <summary class="br-dupes-summary">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                {{ count($candidateGroups) }} possible duplicate groups
            </summary>
            <div class="br-dupes-grid">
                @foreach ($candidateGroups as $group)
                    <div class="br-dupe-item">
                        <span class="br-dupe-key">{{ $group['normalized_key'] }}</span>
                        <span class="br-dupe-variants">{{ implode(' &middot; ', $group['brands']) }}</span>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    {{-- Search bar --}}
    <form method="GET" action="{{ route('brand-review.index') }}" class="br-search-bar">
        <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search brands..." class="br-search-input">
        <input type="text" name="picture_from" value="{{ $filters['picture_from'] }}" placeholder="Pic from" class="br-search-range">
        <input type="text" name="picture_to" value="{{ $filters['picture_to'] }}" placeholder="Pic to" class="br-search-range">
        <button type="submit" class="button button-primary">Search</button>
        @if ($filters['search'] !== '' || $filters['picture_from'] !== '' || $filters['picture_to'] !== '')
            <a href="{{ route('brand-review.index') }}" class="button">Clear</a>
        @endif
    </form>

    {{-- Mapping count --}}
    <p class="br-result-count">{{ $mappings->total() }} brands &middot; page {{ $mappings->currentPage() }} of {{ $mappings->lastPage() }}</p>

    {{-- Mapping list --}}
    <div class="br-mapping-list">
        @forelse ($mappings as $mapping)
            <details class="br-mapping-row">
                <summary class="br-mapping-summary">
                    <div class="br-mapping-observed">
                        <span class="br-mapping-name">{{ $mapping->observed_brand }}</span>
                        @if ($mapping->canonical_brand && $mapping->canonical_brand !== $mapping->observed_brand)
                            <span class="br-mapping-arrow">&rarr;</span>
                            <span class="br-mapping-real">{{ $mapping->canonical_brand }}</span>
                        @endif
                        @if ($mapping->brand_line)
                            <span class="br-mapping-line">/ {{ $mapping->brand_line }}</span>
                        @endif
                    </div>
                    <div class="br-mapping-pills">
                        <span class="pill">{{ number_format((int) $mapping->row_count) }}r</span>
                        <span class="pill">{{ number_format((int) $mapping->product_count) }}p</span>
                        <span class="pill">{{ number_format((int) $mapping->picture_count) }}pic</span>
                        <span class="br-edit-icon">
                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/></svg>
                        </span>
                    </div>
                </summary>

                <div class="br-mapping-detail">
                    {{-- Edit form --}}
                    <form method="POST" action="{{ route('brand-mappings.update', $mapping) }}" class="br-mapping-form">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="page" value="{{ $mappings->currentPage() }}">
                        <input type="hidden" name="search" value="{{ $filters['search'] }}">
                        <input type="hidden" name="picture_from" value="{{ $filters['picture_from'] }}">
                        <input type="hidden" name="picture_to" value="{{ $filters['picture_to'] }}">

                        <div class="br-mapping-form-row">
                            <label class="br-field-grow">
                                <span>Real brand</span>
                                <input type="text" name="canonical_brand" value="{{ old('canonical_brand', $mapping->canonical_brand) }}" required>
                            </label>
                            <label>
                                <span>Line / sub-brand</span>
                                <input type="text" name="brand_line" value="{{ old('brand_line', $mapping->brand_line) }}" placeholder="Optional">
                            </label>
                            <label class="br-field-grow">
                                <span>Source URL</span>
                                <input type="url" name="official_source_url" value="{{ old('official_source_url', $mapping->official_source_url) }}" placeholder="https://...">
                            </label>
                        </div>
                        <div class="br-mapping-form-bottom">
                            <label class="br-field-grow">
                                <span>Notes</span>
                                <input type="text" name="notes" value="{{ old('notes', $mapping->notes) }}" placeholder="Optional note">
                            </label>
                            <button type="submit" class="button button-primary">Save</button>
                        </div>
                    </form>

                    {{-- Products from this brand --}}
                    @php $products = $productsPerBrand->get($mapping->observed_brand, collect()); @endphp
                    @if ($products->isNotEmpty())
                        <div class="br-products-section">
                            <p class="br-products-title">{{ $products->count() }} products from {{ $products->pluck('picture_id')->unique()->count() }} pictures</p>
                            <div class="br-products-table">
                                @foreach ($products->groupBy('picture_id') as $pictureId => $pictureProducts)
                                    <div class="br-picture-group">
                                        <a href="{{ route('pictures.show', ['pictureId' => $pictureId]) }}" class="br-picture-link" title="View picture {{ $pictureId }}">
                                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z"/></svg>
                                            {{ $pictureId }}
                                        </a>
                                        <div class="br-picture-products">
                                            @foreach ($pictureProducts as $product)
                                                <span class="br-product-name">{{ $product->product_name }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </details>
        @empty
            <div class="scaffold-empty">
                <p>No observed brand mappings found.</p>
            </div>
        @endforelse
    </div>

    <div class="pagination-wrap">
        {{ $mappings->links() }}
    </div>
@endsection
