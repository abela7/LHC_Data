@extends('layouts.app')

@section('title', $product->product_name)

@section('content')
    {{-- ── Header ── --}}
    <section class="page-head">
        <div>
            <p class="eyebrow">Product Detail</p>
            <h2>{{ $product->product_name }}</h2>
            <p class="page-note">Row {{ $product->id }} &middot; {{ $displayBrand }} &middot; {{ $product->category?->name ?? 'Uncategorized' }}</p>
        </div>
        <div class="header-actions">
            <a href="{{ route('products.index') }}" class="button">Back to Products</a>
        </div>
    </section>

    {{-- ── Main layout: photo sidebar + editable form ── --}}
    <div class="pshow-layout">

        {{-- Left: photo + quick info --}}
        <aside class="pshow-sidebar">
            {{-- Shelf photo --}}
            <div class="card pshow-photo-card">
                @if ($imageUrl)
                    <button type="button" class="pshow-photo-trigger" data-picture-preview-trigger data-picture-id="{{ $product->picture_id }}" data-image-url="{{ $imageUrl }}">
                        <img src="{{ $imageUrl }}" alt="{{ $product->picture_id }}" class="pshow-photo">
                    </button>
                @else
                    <div class="pshow-photo-empty">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        <strong>No photo</strong>
                        <span>{{ $product->picture_id }}</span>
                    </div>
                @endif
                <div class="pshow-photo-foot">
                    <a href="{{ route('pictures.show', ['pictureId' => $product->picture_id]) }}" class="pshow-picture-link">
                        {{ $product->picture_id }} &rarr; Open picture page
                    </a>
                </div>
            </div>

            {{-- Quick read-only stats --}}
            <div class="card pshow-info-card">
                <p class="air-section-label">Quick info</p>
                <div class="pshow-info-grid">
                    <div class="pshow-info-item">
                        <span class="pshow-info-label">Product ID</span>
                        <span class="pshow-info-value">{{ $productId ?? '-' }}</span>
                    </div>
                    <div class="pshow-info-item">
                        <span class="pshow-info-label">Confidence</span>
                        <span class="pshow-info-value">
                            @if ($enrichment?->confidence)
                                <span class="air-confidence air-confidence--{{ strtolower($enrichment->confidence) }}">{{ $enrichment->confidence }}</span>
                            @else
                                -
                            @endif
                        </span>
                    </div>
                    <div class="pshow-info-item">
                        <span class="pshow-info-label">Related rows</span>
                        <span class="pshow-info-value">{{ $stats['related_rows'] }}</span>
                    </div>
                    <div class="pshow-info-item">
                        <span class="pshow-info-label">Pictures</span>
                        <span class="pshow-info-value">{{ $stats['pictures'] }}</span>
                    </div>
                </div>
            </div>

            {{-- Related rows --}}
            @if ($relatedRows->count() > 1)
                <div class="card pshow-info-card">
                    <p class="air-section-label">Related rows</p>
                    <div class="pshow-related-list">
                        @foreach ($relatedRows as $row)
                            <div class="pshow-related-row{{ $row->id === $product->id ? ' pshow-related-row--current' : '' }}">
                                @if ($row->id === $product->id)
                                    <span class="pshow-related-id">Row {{ $row->id }}</span>
                                @else
                                    <a href="{{ route('products.show', $row) }}" class="pshow-related-id">Row {{ $row->id }}</a>
                                @endif
                                <a href="{{ route('pictures.show', ['pictureId' => $row->picture_id]) }}" class="pshow-related-pic">{{ $row->picture_id }}</a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </aside>

        {{-- Right: editable form --}}
        <div class="pshow-main">
            <form method="POST" action="{{ route('products.update', $product) }}" class="pshow-form">
                @csrf
                @method('PATCH')

                {{-- Group 1: Identity --}}
                <fieldset class="air-fieldset">
                    <legend class="air-fieldset-legend">Product identity</legend>
                    <div class="air-field-grid">
                        <label class="air-field air-field--full">
                            <span>Product name</span>
                            <input type="text" name="product_name" value="{{ old('product_name', $product->product_name) }}" required>
                        </label>
                        <label class="air-field">
                            <span>Observed brand</span>
                            <input type="text" name="brand" value="{{ old('brand', $product->brand) }}">
                        </label>
                        <label class="air-field">
                            <span>Canonical brand</span>
                            <input type="text" name="canonical_brand" value="{{ old('canonical_brand', $product->canonical_brand) }}">
                        </label>
                        <label class="air-field">
                            <span>Brand line</span>
                            <input type="text" name="brand_line" value="{{ old('brand_line', $product->brand_line) }}">
                        </label>
                    </div>
                </fieldset>

                {{-- Group 2: Category --}}
                <fieldset class="air-fieldset">
                    <legend class="air-fieldset-legend">Classification</legend>
                    <div class="air-field-grid">
                        <label class="air-field">
                            <span>Category</span>
                            <select name="category_id">
                                <option value="">-</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected(old('category_id', $product->category_id) == $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="air-field">
                            <span>Subcategory</span>
                            <input type="text" name="subcategory_name" value="{{ old('subcategory_name', $enrichment?->subcategory_name) }}">
                        </label>
                        <label class="air-field">
                            <span>Confidence</span>
                            <select name="confidence">
                                <option value="">-</option>
                                @foreach (['A', 'B', 'C', 'D'] as $grade)
                                    <option value="{{ $grade }}" @selected(old('confidence', $enrichment?->confidence) === $grade)>{{ $grade }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                </fieldset>

                {{-- Group 3: Variants & product type --}}
                <fieldset class="air-fieldset">
                    <legend class="air-fieldset-legend">Variants & type</legend>
                    <div class="air-field-grid">
                        <label class="air-field">
                            <span>Has variant</span>
                            <select name="has_variant">
                                <option value="">-</option>
                                @foreach (['Yes', 'No', 'Unknown'] as $opt)
                                    <option value="{{ $opt }}" @selected(old('has_variant', $enrichment?->has_variant) === $opt)>{{ $opt }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="air-field air-field--wide">
                            <span>Variant types</span>
                            <input type="text" name="variant_types" value="{{ old('variant_types', $enrichment?->variant_types) }}">
                        </label>
                        <label class="air-field">
                            <span>Has product type</span>
                            <select name="has_product_type">
                                <option value="">-</option>
                                @foreach (['Yes', 'No', 'Unknown'] as $opt)
                                    <option value="{{ $opt }}" @selected(old('has_product_type', $enrichment?->has_product_type) === $opt)>{{ $opt }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="air-field air-field--wide">
                            <span>Product type details</span>
                            <input type="text" name="product_type_details" value="{{ old('product_type_details', $enrichment?->product_type_details) }}">
                        </label>
                    </div>
                </fieldset>

                {{-- Group 4: Sources --}}
                <fieldset class="air-fieldset">
                    <legend class="air-fieldset-legend">Sources</legend>
                    <div class="air-field-grid">
                        <label class="air-field">
                            <span>Official site</span>
                            <select name="official_site">
                                <option value="">-</option>
                                @foreach (['Yes', 'No', 'Unknown'] as $opt)
                                    <option value="{{ $opt }}" @selected(old('official_site', $enrichment?->official_site) === $opt)>{{ $opt }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="air-field air-field--wide">
                            <span>Official site URL</span>
                            <input type="text" name="official_site_url" value="{{ old('official_site_url', $enrichment?->official_site_url) }}" placeholder="https://...">
                        </label>
                        <label class="air-field air-field--full">
                            <span>Best source URL</span>
                            <input type="text" name="best_source_url" value="{{ old('best_source_url', $enrichment?->best_source_url) }}" placeholder="https://...">
                        </label>
                    </div>
                </fieldset>

                {{-- Group 5: Notes --}}
                <fieldset class="air-fieldset air-fieldset--verdict">
                    <legend class="air-fieldset-legend">Review notes</legend>
                    <div class="air-field-grid">
                        <label class="air-field air-field--wide">
                            <span>Confidence reason</span>
                            <input type="text" name="confidence_reason" value="{{ old('confidence_reason', $enrichment?->confidence_reason) }}">
                        </label>
                        <label class="air-field air-field--full">
                            <span>Notes</span>
                            <textarea name="notes" rows="3">{{ old('notes', $enrichment?->notes) }}</textarea>
                        </label>
                    </div>
                </fieldset>

                {{-- Save --}}
                <div class="pshow-form-actions">
                    <a href="{{ route('products.index') }}" class="button">Cancel</a>
                    <button type="submit" class="button button-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ── Picture preview lightbox ── --}}
    <div class="photo-carousel-modal" hidden aria-hidden="true" data-picture-preview-modal>
        <button class="photo-carousel-backdrop" data-picture-preview-close aria-label="Close preview"></button>
        <div class="picture-lightbox">
            <div class="picture-lightbox-toolbar">
                <div class="picture-lightbox-meta">
                    <p class="eyebrow">Preview</p>
                    <h3 data-picture-preview-title></h3>
                </div>
                <button class="picture-lightbox-close" data-picture-preview-close aria-label="Close">&times;</button>
            </div>
            <div class="picture-lightbox-media">
                <img data-picture-preview-image src="" alt="">
            </div>
        </div>
    </div>
@endsection
