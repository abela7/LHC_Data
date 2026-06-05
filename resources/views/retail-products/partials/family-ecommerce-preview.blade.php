@php($asPage = $asPage ?? false)
{{-- Responsive shop preview. Modal (family hero eye icon) or full page (public /shop). --}}
<div class="rfm-ecom-preview-overlay {{ $asPage ? 'as-page' : '' }}"
     data-rfm-ecom-preview
     @unless($asPage) hidden aria-hidden="true" @endunless>
    @unless($asPage)
        <div class="rfm-ecom-preview-backdrop" data-rfm-ecom-preview-close></div>
    @endunless
    <div class="rfm-ecom-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="rfm-ecom-preview-title">
        <header class="rfm-ecom-preview-toolbar">
            <div class="rfm-ecom-preview-toolbar-copy">
                <span class="rfm-ecom-preview-toolbar-eyebrow">{{ $asPage ? 'Shop' : 'Store preview' }}</span>
                <strong id="rfm-ecom-preview-toolbar-title">{{ $ecomPreviewData['title'] }}</strong>
            </div>
            @if ($asPage)
                <div class="rfm-ecom-preview-toolbar-actions">
                    @if (! empty($ecomPreviewData['familyManageUrl']))
                        <a href="{{ $ecomPreviewData['familyManageUrl'] }}"
                           class="rfm-ecom-preview-family-btn"
                           aria-label="Open family page"
                           title="Open family page">
                            <svg class="rfm-ecom-preview-family-btn-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                                <path fill="currentColor" d="M12 5C7 5 2.73 8.11 1 12.5 2.73 16.89 7 20 12 20s9.27-3.11 11-7.5C21.27 8.11 17 5 12 5zm0 11.5A4 4 0 1 1 12 8.5a4 4 0 0 1 0 8zm0-6.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z"/>
                            </svg>
                        </a>
                    @endif
                    <a href="{{ route('shop.index') }}" class="rfm-ecom-preview-close" aria-label="Back to shop">
                        <span aria-hidden="true">←</span>
                    </a>
                </div>
            @else
                <button type="button" class="rfm-ecom-preview-close" data-rfm-ecom-preview-close aria-label="Close preview">
                    <span aria-hidden="true">×</span>
                </button>
            @endif
        </header>

        <div class="rfm-ecom-preview-scroll">
            <div class="rfm-ecom-preview-store">
                <nav class="rfm-ecom-preview-breadcrumb" aria-label="Breadcrumb">
                    <a href="{{ route('shop.index') }}">Home</a>
                    <span class="rfm-ecom-preview-breadcrumb-sep" aria-hidden="true">›</span>
                    @if (! empty($ecomPreviewData['lineUrl']) && ! empty($ecomPreviewData['line']))
                        <a href="{{ $ecomPreviewData['lineUrl'] }}"
                           data-rfm-ecom-preview-breadcrumb-line>{{ $ecomPreviewData['line'] }}</a>
                    @else
                        <span data-rfm-ecom-preview-breadcrumb-line>{{ $ecomPreviewData['line'] ?: ($ecomPreviewData['category'] ?: 'Shop') }}</span>
                    @endif
                    <span class="rfm-ecom-preview-breadcrumb-sep" aria-hidden="true">›</span>
                    @if ($asPage)
                        <span aria-current="page" data-rfm-ecom-preview-breadcrumb>{{ $ecomPreviewData['familyTitle'] ?: $ecomPreviewData['title'] }}</span>
                    @elseif (! empty($ecomPreviewData['shopProductUrl']))
                        <a href="{{ $ecomPreviewData['shopProductUrl'] }}" data-rfm-ecom-preview-breadcrumb>{{ $ecomPreviewData['familyTitle'] ?: $ecomPreviewData['title'] }}</a>
                    @else
                        <span data-rfm-ecom-preview-breadcrumb>{{ $ecomPreviewData['familyTitle'] ?: $ecomPreviewData['title'] }}</span>
                    @endif
                </nav>

                <div class="rfm-ecom-preview-layout">
                    <section class="rfm-ecom-preview-gallery" aria-label="Product images">
                        <div class="rfm-ecom-preview-main-img" data-rfm-ecom-preview-main>
                            <div class="rfm-ecom-preview-img-empty">Choose options to preview this sellable SKU</div>
                        </div>
                        <p class="rfm-ecom-preview-gallery-caption" data-rfm-ecom-preview-gallery-caption hidden></p>
                        <div class="rfm-ecom-preview-thumbs" data-rfm-ecom-preview-thumbs role="list"></div>
                        <p class="rfm-ecom-preview-gallery-hint">Main photo and gallery for the selected sellable SKU. On desktop, hover the large image to zoom. Colour swatches use variant photos only.</p>
                    </section>

                    <section class="rfm-ecom-preview-buy" aria-label="Purchase information">
                        <p class="rfm-ecom-preview-brand" data-rfm-ecom-preview-brand>{{ $ecomPreviewData['brand'] }}</p>
                        <h1 class="rfm-ecom-preview-title" id="rfm-ecom-preview-title" data-rfm-ecom-preview-title>{{ $ecomPreviewData['title'] ?: ($ecomPreviewData['titlePlaceholder'] ?? 'Choose options to preview the ecommerce product title') }}</h1>

                        <div class="rfm-ecom-preview-rating" aria-hidden="true">
                            <span class="rfm-ecom-preview-stars">★★★★★</span>
                            <span class="rfm-ecom-preview-rating-note">Preview only</span>
                        </div>

                        <p class="rfm-ecom-preview-price" data-rfm-ecom-preview-price>
                            @if ($ecomPreviewData['sharedPrice'] !== null)
                                £{{ number_format($ecomPreviewData['sharedPrice'], 2) }}
                            @elseif ($ecomPreviewData['priceMin'] !== null && $ecomPreviewData['priceMax'] !== null && $ecomPreviewData['priceMin'] != $ecomPreviewData['priceMax'])
                                From £{{ number_format($ecomPreviewData['priceMin'], 2) }}
                            @elseif ($ecomPreviewData['priceMin'] !== null)
                                £{{ number_format($ecomPreviewData['priceMin'], 2) }}
                            @else
                                Price not set
                            @endif
                        </p>

                        <p class="rfm-ecom-preview-short" data-rfm-ecom-preview-short>
                            {{ $ecomPreviewData['shortDescription'] ?: 'Add a short description in family shared details or ecommerce profile.' }}
                        </p>

                        <div class="rfm-ecom-preview-variants" data-rfm-ecom-preview-variants>
                            @if (! empty($ecomPreviewData['swatches']))
                                <div class="rfm-ecom-preview-variant rfm-ecom-preview-variant--colour"
                                     data-rfm-ecom-preview-colour
                                     data-group-id="{{ $ecomPreviewData['colourGroupId'] }}">
                                    <span class="rfm-ecom-preview-variant-label">
                                        {{ $ecomPreviewData['colourGroupName'] ?? 'Colour' }}
                                        <em>Variant photo for colour choice</em>
                                    </span>
                                    <div class="rfm-ecom-preview-swatches" role="list">
                                        @foreach ($ecomPreviewData['swatches'] as $swatch)
                                            <button type="button"
                                                    class="rfm-ecom-preview-swatch"
                                                    data-rfm-ecom-preview-swatch
                                                    data-option-id="{{ $swatch['optionId'] }}"
                                                    data-swatch-label="{{ $swatch['label'] }}"
                                                    title="{{ $swatch['label'] }}"
                                                    role="listitem">
                                                @if ($swatch['swatchUrl'])
                                                    <img src="{{ $swatch['swatchUrl'] }}" alt="" loading="lazy">
                                                @else
                                                    <span class="rfm-ecom-preview-swatch-fallback">{{ mb_substr($swatch['label'], 0, 3) }}</span>
                                                @endif
                                                <span class="rfm-ecom-preview-swatch-name">{{ $swatch['label'] }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @foreach ($ecomPreviewData['variants'] as $group)
                                <div class="rfm-ecom-preview-variant"
                                     data-rfm-ecom-preview-variant
                                     data-group-id="{{ $group['id'] }}">
                                    <span class="rfm-ecom-preview-variant-label">{{ $group['name'] }}</span>
                                    <div class="rfm-ecom-preview-variant-options" role="list">
                                        @foreach ($group['options'] as $option)
                                            <button type="button"
                                                    class="rfm-ecom-preview-option"
                                                    data-rfm-ecom-preview-option
                                                    data-option-id="{{ $option['id'] }}"
                                                    role="listitem">
                                                {{ $option['label'] }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="rfm-ecom-preview-actions">
                            <button type="button" class="rfm-ecom-preview-cart" disabled>Add to bag — preview</button>
                            @if ($ecomPreviewData['clickCollect'])
                                <button type="button" class="rfm-ecom-preview-secondary" disabled>Click &amp; collect</button>
                            @endif
                        </div>

                        <ul class="rfm-ecom-preview-trust">
                            <li>Secure checkout</li>
                            <li>Free returns on eligible orders</li>
                            @if ($ecomPreviewData['clickCollect'])
                                <li>Click &amp; collect available</li>
                            @endif
                        </ul>

                        <p class="rfm-ecom-preview-sku-note" data-rfm-ecom-preview-sku-note hidden></p>
                    </section>
                </div>

                <section class="rfm-ecom-preview-details" aria-label="Product description">
                    <h2>Description</h2>
                    <div class="rfm-ecom-preview-long" data-rfm-ecom-preview-long>
                        @if ($ecomPreviewData['longDescription'])
                            {!! nl2br(e($ecomPreviewData['longDescription'])) !!}
                        @else
                            <p>No long description yet. Add copy in family shared details to see it here.</p>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="rfm-ecom-preview-data">@json($ecomPreviewData)</script>
