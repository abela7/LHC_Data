@extends('layouts.app')

@section('title', $style->name . ' - View')

@section('content')
    @include('brand-catalogue._page-label', ['label' => 'Style View', 'context' => $style->name])

    @include('partials.catalogue-breadcrumb', [
        'items' => [
            ['label' => 'Brand Catalogue', 'url' => route('brand-catalogue.index')],
            ['label' => $catalogue->name, 'url' => route('brand-catalogue.view', $catalogue)],
            ['label' => $brand->name, 'url' => route('brand-catalogue.view.brand', [$catalogue, $brand])],
            ['label' => $line->name, 'url' => route('brand-catalogue.view.line', [$catalogue, $brand, $line])],
            ['label' => $productType->name, 'url' => route('brand-catalogue.view.product-type', [$catalogue, $brand, $line, $productType])],
            ['label' => $style->name, 'current' => true],
        ],
    ])

    <div class="sr-hero">
        <div class="sr-hero-top">
            <div class="sr-hero-title-block">
                <h1 class="sr-hero-title">{{ $style->name }}</h1>
                <div class="sr-hero-badges">
                    <span class="sr-badge sr-badge-accent">{{ $brand->name }}</span>
                    <span class="sr-badge">{{ $line->name }}</span>
                    <span class="sr-badge sr-badge-warn">{{ $productType->name }}</span>
                    <span class="sr-badge">{{ $style->material_name ?: 'Material not set' }}</span>
                </div>
            </div>
        </div>
    </div>

    @if ($style->variants->isEmpty())
        <div class="sr-empty"><p>No variants defined for this style.</p></div>
    @else
        <div class="bcv-variant-grid">
            @foreach ($style->variants as $variant)
                <div class="bcv-variant-panel">
                    <div class="bcv-variant-head">
                        <h3>{{ $variant->name }}</h3>
                        <span class="bc-vtype-badge bc-vtype-{{ $variant->variant_type }}">{{ $variant->variant_type === 'count' ? 'pack count' : str_replace('_', ' ', $variant->variant_type) }}</span>
                    </div>
                    @if ($variant->options->isEmpty())
                        <p class="bcv-variant-empty">No options yet</p>
                    @else
                        <div class="bcv-option-chips">
                            @foreach ($variant->options as $option)
                                <span class="bcv-chip">
                                    <span class="bcv-chip-label">{{ $option->label }}</span>
                                    @if ($option->value)
                                        <code class="bcv-chip-value">{{ $option->value }}</code>
                                    @endif
                                    @if ($option->images->isNotEmpty())
                                        <span class="bc-vtype-badge bc-vtype-text">{{ $option->images->count() }} img</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>

                        @if ($variant->options->contains(fn ($option) => $option->images->isNotEmpty()))
                            <div class="bc-image-grid-shell" style="margin-top: 0.75rem;">
                                @foreach ($variant->options as $option)
                                    @foreach ($option->images as $image)
                                        @php($displayUrl = $image->displayUrl())
                                        <article class="bc-image-card-shell">
                                            <div class="bc-image-preview">
                                                @if ($displayUrl)
                                                    <img src="{{ $displayUrl }}" alt="{{ $image->notes ?: $option->label }}">
                                                @else
                                                    <div class="bc-image-placeholder">Image record without preview URL</div>
                                                @endif
                                                <div class="bc-image-preview-badges">
                                                    <span class="bc-vtype-badge bc-vtype-text">{{ $option->label }}</span>
                                                    <span class="bc-vtype-badge bc-vtype-text">{{ $image->image_role }}</span>
                                                    @if ($image->is_primary)
                                                        <span class="bc-vtype-badge bc-vtype-count">primary</span>
                                                    @endif
                                                </div>
                                            </div>
                                            @if ($image->notes)
                                                <p class="bc-section-note">{{ $image->notes }}</p>
                                            @endif
                                        </article>
                                    @endforeach
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <section class="bc-section-shell">
        <div class="bc-section-head">
            <div>
                <p class="eyebrow">Style Images</p>
                <h2 class="bc-section-title">Product-level photos</h2>
            </div>
        </div>

        @if ($style->images->isEmpty())
            <div class="sr-empty"><p>No style images recorded.</p></div>
        @else
            <div class="bc-image-grid-shell">
                @foreach ($style->images as $image)
                    @php($displayUrl = $image->displayUrl())
                    <article class="bc-image-card-shell">
                        <div class="bc-image-preview">
                            @if ($displayUrl)
                                <img src="{{ $displayUrl }}" alt="{{ $image->notes ?: $style->name }}">
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
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="bc-section-shell">
        <div class="bc-section-head">
            <div>
                <p class="eyebrow">Sellable Layer</p>
                <h2 class="bc-section-title">Sellable SKUs</h2>
            </div>
        </div>

        @if ($style->skus->isEmpty())
            <div class="sr-empty"><p>No sellable SKUs recorded.</p></div>
        @else
            <div class="bc-sku-stack">
                @foreach ($style->skus as $sku)
                    @php($sortedSkuOptions = $sku->optionValues->sortBy(fn ($option) => sprintf('%04d:%s:%04d:%s', $option->variant->sort_order, $option->variant->name, $option->sort_order, $option->label)))
                    <article class="bc-sku-card-shell">
                        <div class="bc-sku-card-head">
                            <div class="bc-sku-card-title-block">
                                <h3>{{ $sku->name }}</h3>
                                <div class="bc-sku-meta-row">
                                    @if ($sku->sku_code)
                                        <code class="bc-option-value">SKU {{ $sku->sku_code }}</code>
                                    @endif
                                    @if ($sku->barcode)
                                        <code class="bc-option-value">Barcode {{ $sku->barcode }}</code>
                                    @endif
                                </div>
                                @if ($sortedSkuOptions->isNotEmpty())
                                    <div class="bcv-option-chips">
                                        @foreach ($sortedSkuOptions as $option)
                                            <span class="bcv-chip">
                                                <span class="bcv-chip-label">{{ $option->variant->name }}: {{ $option->label }}</span>
                                                @if ($option->value)
                                                    <code class="bcv-chip-value">{{ $option->value }}</code>
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                                @if ($sku->description)
                                    <p class="bc-section-note">{{ $sku->description }}</p>
                                @endif
                                @if ($sku->note)
                                    <p class="bc-section-note"><strong>Note:</strong> {{ $sku->note }}</p>
                                @endif
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
                                    </article>
                                @endforeach
                            </div>
                        @else
                            @php($fallbackImage = $sku->selectedOptionPrimaryImage())
                            @if ($fallbackImage)
                                @php($displayUrl = $fallbackImage->displayUrl())
                                <div class="bc-image-grid-shell">
                                    <article class="bc-image-card-shell">
                                        <div class="bc-image-preview">
                                            @if ($displayUrl)
                                                <img src="{{ $displayUrl }}" alt="{{ $fallbackImage->notes ?: $sku->name }}">
                                            @else
                                                <div class="bc-image-placeholder">Image record without preview URL</div>
                                            @endif
                                            <div class="bc-image-preview-badges">
                                                <span class="bc-vtype-badge bc-vtype-text">{{ $fallbackImage->image_role }}</span>
                                                <span class="bc-vtype-badge bc-vtype-count">option fallback</span>
                                            </div>
                                        </div>
                                        <p class="bc-section-note">{{ $fallbackImage->notes ?: 'Using selected option media as the display image for this SKU.' }}</p>
                                    </article>
                                </div>
                            @else
                                <p class="bc-section-note">No SKU-specific images recorded.</p>
                            @endif
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
