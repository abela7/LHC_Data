@extends('layouts.app')

@section('title', $product->official_name)
@section('section', 'Deliveroo')
@section('heading', $brand['label'].' Product')

@section('content')
    @php
        $primaryImage = $gallery->first();
    @endphp

    <section class="dp-page" data-deliveroo-price-scope>
        {{-- Breadcrumbs --}}
        <nav class="dp-crumbs" aria-label="Breadcrumb">
            <a href="{{ route('deliveroo-products.index') }}">Deliveroo</a>
            <span aria-hidden="true">&rsaquo;</span>
            <a href="{{ route('deliveroo-products.official-brand', ['brand' => $brand['slug']]) }}">{{ $brand['label'] }}</a>
            <span aria-hidden="true">&rsaquo;</span>
            <span class="dp-crumbs-current" title="{{ $product->official_name }}">{{ Str::limit($product->official_name, 48) }}</span>
        </nav>

        {{-- Main product layout --}}
        <div class="dp-layout">
            {{-- Gallery column --}}
            <div class="dp-gallery">
                <div class="dp-hero" data-deliveroo-main-frame>
                    @if ($primaryImage)
                        <img
                            src="{{ $primaryImage }}"
                            alt="{{ $product->official_name }}"
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

            {{-- Info column --}}
            <div class="dp-info">
                <div class="dp-info-head">
                    <span class="dp-brand-tag">{{ $brand['label'] }}</span>
                    <h1 class="dp-title">{{ $product->official_name }}</h1>
                </div>

                {{-- Fact chips --}}
                <div class="dp-chips">
                    @if ($product->family_name)
                        <div class="dp-chip">
                            <span class="dp-chip-label">Family</span>
                            <span class="dp-chip-value">{{ $product->family_name }}</span>
                        </div>
                    @endif
                    @if ($product->variant_name)
                        <div class="dp-chip">
                            <span class="dp-chip-label">Variant</span>
                            <span class="dp-chip-value">{{ $product->variant_name }}</span>
                        </div>
                    @endif
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

                {{-- Price block --}}
                <div class="dp-price-block">
                    <div class="dp-price-row">
                        <div class="dp-price-left">
                            <div class="dp-price-display" data-deliveroo-price-filled @if ($product->price === null) hidden @endif>
                                <strong class="dp-price-amount" data-deliveroo-price-display>
                                    @if ($product->price !== null)
                                        {{ $product->price_display }}
                                    @else
                                        Not set
                                    @endif
                                </strong>
                                <small class="dp-price-note" data-deliveroo-price-note @if (! $product->price_notes) hidden @endif>{{ $product->price_notes }}</small>
                            </div>
                            <span class="dp-price-empty" data-deliveroo-price-empty @if ($product->price !== null) hidden @endif>No price set</span>
                        </div>
                        <button
                            type="button"
                            class="{{ $product->price !== null ? 'dp-price-edit-btn' : 'dp-price-add-btn' }}"
                            data-deliveroo-price-open
                            data-has-price="{{ $product->price !== null ? '1' : '0' }}"
                            aria-label="{{ $product->price !== null ? 'Edit price' : 'Add price' }}"
                        >
                            @if ($product->price !== null)
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                <span>Edit</span>
                            @else
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <span>Add Price</span>
                            @endif
                        </button>
                    </div>
                </div>

                {{-- Description --}}
                @if ($product->description)
                    <details class="dp-desc" open>
                        <summary class="dp-desc-toggle">Description</summary>
                        <div class="dp-desc-body">
                            <p>{{ $product->description }}</p>
                        </div>
                    </details>
                @endif

                {{-- Options --}}
                @if (! empty($product->option_values))
                    <details class="dp-desc" open>
                        <summary class="dp-desc-toggle">Options</summary>
                        <ul class="dp-options">
                            @foreach ($product->option_values as $optionValue)
                                <li>{{ $optionValue }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                {{-- Actions --}}
                <div class="dp-actions">
                    <a href="{{ route('deliveroo-products.official-product.edit', ['brand' => $brand['slug'], 'product' => $product]) }}" class="dp-action-btn dp-action-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        {{ __('deliveroo.manual_product.edit_product') }}
                    </a>
                    <form
                        method="POST"
                        action="{{ route('deliveroo-products.official-product.destroy', ['brand' => $brand['slug'], 'product' => $product]) }}"
                        class="dp-action-inline-form"
                        onsubmit="return confirm(@json(__('deliveroo.manual_product.delete_confirm')))"
                    >
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="dp-action-btn dp-action-danger">{{ __('deliveroo.manual_product.delete_product') }}</button>
                    </form>
                    <a href="{{ route('deliveroo-products.official-brand', ['brand' => $brand['slug']]) }}" class="dp-action-btn dp-action-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                        Back to {{ $brand['label'] }}
                    </a>
                    @if (! str_starts_with((string) $product->official_url, 'manual:lhc:'))
                        <a href="{{ $product->official_url }}" class="dp-action-btn dp-action-primary" target="_blank" rel="noreferrer">
                            Official Source
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        </a>
                    @endif
                </div>
            </div>
        </div>

        {{-- Related products --}}
        @if ($relatedProducts->isNotEmpty())
            <section class="dp-related">
                <div class="dp-related-head">
                    <h2>More from {{ $brand['label'] }}</h2>
                    <p>Other validated products from the same official source.</p>
                </div>

                <div class="dp-related-grid">
                    @foreach ($relatedProducts as $relatedProduct)
                        @php
                            $relatedImage = collect($relatedProduct->image_urls ?? [])->first();
                        @endphp

                        <a href="{{ route('deliveroo-products.official-product', ['brand' => $brand['slug'], 'product' => $relatedProduct]) }}" class="dp-related-card">
                            <div class="dp-related-img">
                                @if ($relatedImage)
                                    <img src="{{ $relatedImage }}" alt="{{ $relatedProduct->official_name }}" loading="lazy">
                                @else
                                    <span class="dp-related-noimg">No image</span>
                                @endif
                            </div>
                            <div class="dp-related-body">
                                <h3>{{ $relatedProduct->official_name }}</h3>
                                @if ($relatedProduct->family_name)
                                    <span class="dp-related-fam">{{ $relatedProduct->family_name }}</span>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Lightbox --}}
        @if ($gallery->count() >= 1)
            <div class="dp-lightbox" hidden aria-hidden="true" data-deliveroo-lightbox>
                <button type="button" class="dp-lightbox-backdrop" data-deliveroo-lightbox-close aria-label="Close"></button>
                <div class="dp-lightbox-stage">
                    @if ($gallery->count() > 1)
                        <button type="button" class="dp-lightbox-nav dp-lightbox-prev" data-deliveroo-lightbox-prev aria-label="Previous image">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        </button>
                    @endif

                    <img src="" alt="{{ $product->official_name }}" class="dp-lightbox-img" data-deliveroo-lightbox-img>

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

        {{-- Price modal --}}
        <div class="deliveroo-price-modal" hidden aria-hidden="true" data-deliveroo-price-modal>
            <button type="button" class="deliveroo-price-modal-backdrop" data-deliveroo-price-close aria-label="Close price editor"></button>
            <section class="deliveroo-price-modal-panel" role="dialog" aria-modal="true" aria-labelledby="deliveroo-price-modal-title">
                <div class="deliveroo-price-modal-head">
                    <div>
                        <h3 id="deliveroo-price-modal-title" data-deliveroo-price-modal-title>{{ $product->price !== null ? 'Edit Deliveroo Price' : 'Add Deliveroo Price' }}</h3>
                        <p class="page-note">Update the selling price for this Deliveroo product without leaving the page.</p>
                    </div>
                    <button type="button" class="deliveroo-price-modal-close" data-deliveroo-price-close aria-label="Close price editor">&times;</button>
                </div>

                <form method="POST" action="{{ route('deliveroo-products.official-product.price', ['brand' => $brand['slug'], 'product' => $product]) }}" class="deliveroo-price-form" data-deliveroo-price-form>
                    @csrf
                    @method('PATCH')

                    <label class="deliveroo-field">
                        <span>Price</span>
                        <input type="number" step="0.01" min="0" max="999999.99" name="price" value="{{ $product->price }}" placeholder="0.00" data-deliveroo-price-input>
                    </label>

                    <label class="deliveroo-field deliveroo-field--full">
                        <span>Note</span>
                        <input type="text" name="price_notes" value="{{ $product->price_notes }}" placeholder="Optional note for Deliveroo pricing" data-deliveroo-price-note-input>
                    </label>

                    <p class="deliveroo-price-form-error" hidden data-deliveroo-price-error></p>

                    <div class="button-row deliveroo-price-modal-actions">
                        <button type="button" class="button" data-deliveroo-price-close>Cancel</button>
                        <button type="submit" class="button button-primary" data-deliveroo-price-submit>Save Price</button>
                    </div>
                </form>
            </section>
        </div>
    </section>
@endsection
