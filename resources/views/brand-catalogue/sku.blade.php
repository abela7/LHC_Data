@extends('layouts.app')

@section('title', $sku->name)
@section('section', 'Catalogue')
@section('heading', $brand->name . ' Product')

@section('content')
    @include('brand-catalogue._page-label', ['label' => 'Sellable SKU Page', 'context' => $sku->name])

    @php
        $primaryImage = $gallery->first();
        $selectedOptionIds = $sku->optionValues->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sortedOptions = $sku->optionValues->sortBy(fn ($option) => sprintf('%04d:%s:%04d:%s', $option->variant->sort_order, $option->variant->name, $option->sort_order, $option->label));
        $sourceIsExternal = filled($sku->url) && ! str_starts_with((string) $sku->url, 'manual:');
    @endphp

    <section class="dp-page brand-sku-product-page">
        @include('partials.catalogue-breadcrumb', [
            'items' => [
                ['label' => 'Brand Catalogue', 'url' => route('brand-catalogue.index')],
                ['label' => $catalogue->name, 'url' => route('brand-catalogue.show', $catalogue)],
                ['label' => $brand->name, 'url' => route('brand-catalogue.brands.show', [$catalogue, $brand])],
                ['label' => $line->name, 'url' => route('brand-catalogue.lines.show', [$catalogue, $brand, $line])],
                ['label' => $productType->name, 'url' => route('brand-catalogue.product-types.show', [$catalogue, $brand, $line, $productType])],
                ['label' => $style->name, 'url' => route('brand-catalogue.styles.show', [$catalogue, $brand, $line, $productType, $style])],
                ['label' => $sku->name, 'current' => true],
            ],
        ])

        <div class="dp-layout">
            <div class="dp-gallery">
                <div class="dp-hero" data-deliveroo-main-frame>
                    @if ($primaryImage)
                        <img
                            src="{{ $primaryImage }}"
                            alt="{{ $sku->name }}"
                            class="dp-hero-img"
                            data-deliveroo-main-image
                        >
                        <button type="button" class="dp-hero-zoom" data-deliveroo-lightbox-open aria-label="View full size">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                        </button>
                    @else
                        <div class="dp-hero-empty">No image available</div>
                    @endif
                </div>

                @if ($gallery->count() > 1)
                    <div class="dp-thumbs">
                        @foreach ($gallery as $idx => $imageUrl)
                            <button
                                type="button"
                                class="dp-thumb{{ $idx === 0 ? ' is-active' : '' }}"
                                data-deliveroo-thumb="{{ $imageUrl }}"
                                data-gallery-index="{{ $idx }}"
                                aria-label="View image {{ $idx + 1 }}"
                            >
                                <img src="{{ $imageUrl }}" alt="" loading="lazy">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="dp-info">
                <div class="dp-info-head">
                    <span class="dp-brand-tag">{{ $brand->name }}</span>
                    <h1 class="dp-title">{{ $sku->name }}</h1>
                </div>

                <div class="dp-chips">
                    <div class="dp-chip">
                        <span class="dp-chip-label">Family</span>
                        <span class="dp-chip-value">{{ $style->name }}</span>
                    </div>
                    <div class="dp-chip">
                        <span class="dp-chip-label">Line</span>
                        <span class="dp-chip-value">{{ $line->name }}</span>
                    </div>
                    <div class="dp-chip">
                        <span class="dp-chip-label">Type</span>
                        <span class="dp-chip-value">{{ $productType->name }}</span>
                    </div>
                    <div class="dp-chip">
                        <span class="dp-chip-label">Images</span>
                        <span class="dp-chip-value">{{ number_format($stats['images']) }}</span>
                    </div>
                    @if ($stats['options'] > 0)
                        <div class="dp-chip">
                            <span class="dp-chip-label">Options</span>
                            <span class="dp-chip-value">{{ number_format($stats['options']) }}</span>
                        </div>
                    @endif
                </div>

                <div class="dp-price-block">
                    <div class="dp-price-row">
                        <div class="dp-price-left">
                            <span class="dp-price-empty">Price not configured for this catalogue SKU yet</span>
                        </div>
                    </div>
                </div>

                @if ($sku->description)
                    <details class="dp-desc" open>
                        <summary class="dp-desc-toggle">Description</summary>
                        <div class="dp-desc-body">
                            <p>{{ $sku->description }}</p>
                        </div>
                    </details>
                @endif

                @if ($sortedOptions->isNotEmpty())
                    <details class="dp-desc" open>
                        <summary class="dp-desc-toggle">Options</summary>
                        <ul class="dp-options">
                            @foreach ($sortedOptions as $option)
                                <li>{{ $option->variant->name }}: {{ $option->label }}@if ($option->value && $option->value !== $option->label) ({{ $option->value }})@endif</li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                <dl class="deliveroo-product-facts">
                    <div>
                        <dt>SKU code</dt>
                        <dd>{{ $sku->sku_code ?: 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt>Barcode</dt>
                        <dd>{{ $sku->barcode ?: 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt>Material</dt>
                        <dd>{{ $style->material_name ?: 'Not set' }}</dd>
                    </div>
                    @if ($sku->note)
                        <div>
                            <dt>Internal note</dt>
                            <dd>{{ $sku->note }}</dd>
                        </div>
                    @endif
                </dl>

                <div class="dp-actions">
                    <a href="#sku-edit-workspace" class="dp-action-btn dp-action-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Edit product data
                    </a>
                    <a href="{{ route('brand-catalogue.styles.show', [$catalogue, $brand, $line, $productType, $style]) }}" class="dp-action-btn dp-action-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                        Back to {{ $style->name }}
                    </a>
                    @if ($sourceIsExternal)
                        <a href="{{ $sku->url }}" class="dp-action-btn dp-action-primary" target="_blank" rel="noreferrer">
                            Product Source
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        </a>
                    @endif
                </div>
            </div>
        </div>

        @if ($relatedSkus->isNotEmpty())
            <section class="dp-related">
                <div class="dp-related-head">
                    <h2>More {{ $style->name }} products</h2>
                    <p>Other sellable variants in the same product family.</p>
                </div>

                <div class="dp-related-grid">
                    @foreach ($relatedSkus as $relatedSku)
                        @php($relatedImage = $relatedSku->primaryImage()?->displayUrl())
                        <a href="{{ route('brand-catalogue.skus.show', [$catalogue, $brand, $line, $productType, $style, $relatedSku]) }}" class="dp-related-card">
                            <div class="dp-related-img">
                                @if ($relatedImage)
                                    <img src="{{ $relatedImage }}" alt="{{ $relatedSku->name }}" loading="lazy">
                                @else
                                    <span class="dp-related-noimg">No image</span>
                                @endif
                            </div>
                            <div class="dp-related-body">
                                <h3>{{ $relatedSku->name }}</h3>
                                <span class="dp-related-fam">{{ $style->name }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($gallery->count() >= 1)
            <div class="dp-lightbox" hidden aria-hidden="true" data-deliveroo-lightbox>
                <button type="button" class="dp-lightbox-backdrop" data-deliveroo-lightbox-close aria-label="Close"></button>
                <div class="dp-lightbox-stage">
                    @if ($gallery->count() > 1)
                        <button type="button" class="dp-lightbox-nav dp-lightbox-prev" data-deliveroo-lightbox-prev aria-label="Previous image">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        </button>
                    @endif

                    <img src="" alt="{{ $sku->name }}" class="dp-lightbox-img" data-deliveroo-lightbox-img>

                    @if ($gallery->count() > 1)
                        <button type="button" class="dp-lightbox-nav dp-lightbox-next" data-deliveroo-lightbox-next aria-label="Next image">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                    @endif

                    <div class="dp-lightbox-counter" data-deliveroo-lightbox-counter></div>

                    <button type="button" class="dp-lightbox-close" data-deliveroo-lightbox-close aria-label="Close">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            </div>
        @endif
    </section>

    <section class="bc-section-shell" id="sku-edit-workspace">
        <div class="bc-section-head">
            <div>
                <p class="eyebrow">Workspace controls</p>
                <h2 class="bc-section-title">Edit sellable product data</h2>
            </div>
        </div>

        <form method="POST" action="{{ route('brand-catalogue.skus.update', $sku) }}" class="sw-sku-form sku-detail-form">
            @csrf
            @method('PATCH')

            <div class="sw-sku-grid">
                <label class="sw-field">
                    <span class="sw-field-label">Sellable name</span>
                    <input type="text" name="name" value="{{ old('name', $sku->name) }}" required>
                </label>
                <label class="sw-field">
                    <span class="sw-field-label">SKU code</span>
                    <input type="text" name="sku_code" value="{{ old('sku_code', $sku->sku_code) }}">
                </label>
                <label class="sw-field">
                    <span class="sw-field-label">Barcode</span>
                    <input type="text" name="barcode" value="{{ old('barcode', $sku->barcode) }}">
                </label>
                <label class="sw-field sw-field-short">
                    <span class="sw-field-label">Sort</span>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $sku->sort_order) }}" min="0">
                </label>
            </div>

            @if ($style->variants->isNotEmpty())
                <div class="sw-sku-options-row">
                    @foreach ($style->variants as $variant)
                        <label class="sw-field">
                            <span class="sw-field-label">{{ $variant->name }}</span>
                            <select name="variant_option_ids[{{ $variant->id }}]" @disabled($variant->options->isEmpty())>
                                <option value="">- select -</option>
                                @foreach ($variant->options as $option)
                                    <option value="{{ $option->id }}" @selected(in_array((int) $option->id, $selectedOptionIds, true))>
                                        {{ $option->label }}@if($option->value && $option->value !== $option->label) ({{ $option->value }})@endif
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    @endforeach
                </div>
            @endif

            <div class="sw-sku-extras">
                <label class="sw-field">
                    <span class="sw-field-label">Source URL</span>
                    <input type="url" name="url" value="{{ old('url', $sku->url) }}" placeholder="https://...">
                </label>
                <label class="sw-field">
                    <span class="sw-field-label">Internal note</span>
                    <textarea name="note" rows="3" placeholder="Internal note...">{{ old('note', $sku->note) }}</textarea>
                </label>
                <label class="sw-field sku-detail-span-2">
                    <span class="sw-field-label">Description</span>
                    <textarea name="description" rows="5" placeholder="Product description...">{{ old('description', $sku->description) }}</textarea>
                </label>
            </div>

            <button type="submit" class="sw-btn sw-btn-primary">Save sellable product</button>
        </form>
    </section>

    <section class="bc-section-shell">
        <div class="bc-section-head">
            <div>
                <p class="eyebrow">Product media</p>
                <h2 class="bc-section-title">SKU images</h2>
            </div>
        </div>

        @if ($sku->images->isNotEmpty())
            <div class="bc-image-grid-shell">
                @foreach ($sku->images as $image)
                    @php($displayUrl = $image->displayUrl())
                    <article class="bc-image-card-shell">
                        <div class="bc-image-preview">
                            @if ($displayUrl)
                                <img src="{{ $displayUrl }}" alt="{{ $image->notes ?: $sku->name }}">
                            @else
                                <div class="bc-image-placeholder">Image record without preview URL</div>
                            @endif
                            <div class="bc-image-preview-badges">
                                <span class="bc-vtype-badge bc-vtype-text">{{ $image->image_role }}</span>
                                @if ($image->is_primary)
                                    <span class="bc-vtype-badge bc-vtype-count">primary</span>
                                @endif
                            </div>
                        </div>
                        @if ($image->notes)
                            <p class="bc-section-note">{{ $image->notes }}</p>
                        @endif
                        <form method="POST" action="{{ route('images.destroy', $image) }}" onsubmit="return confirm('Delete this image?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="sw-btn-danger-sm">Delete image</button>
                        </form>
                    </article>
                @endforeach
            </div>
        @else
            <div class="sr-empty"><p>No direct SKU image has been recorded. The page preview can still use option or style images as fallback.</p></div>
        @endif

        @include('brand-catalogue._catalogue-image-add-form', [
            'targetType' => 'brand_catalogue_sku',
            'targetId' => $sku->id,
            'imageRoleOptions' => $skuImageRoleOptions,
            'defaultImageRole' => 'variant',
            'primaryLabel' => 'Set as primary SKU image',
            'addButtonLabel' => 'Add SKU image',
        ])
    </section>
@endsection
