@extends('layouts.app')

@section('title', $brand['label'].' - '.$familyName)
@section('section', 'Deliveroo')
@section('heading', $brand['label'].' Official')

@section('content')
    @php
        $pricedPct = $stats['products'] > 0 ? round(($stats['priced'] / $stats['products']) * 100) : 0;
    @endphp

    <div class="deliveroo-brand-page deliveroo-family-page">
        <header class="deliveroo-page-head">
            <div class="deliveroo-page-head-copy">
                <p class="eyebrow">{{ $brand['label'] }} Family</p>
                <h2>{{ $familyName }}</h2>
                <p class="page-note">
                    These are the sellable products under this family. Open any product for full details.
                </p>
            </div>

            <div class="deliveroo-page-head-actions" aria-label="{{ __('deliveroo.family_page.actions_group') }}">
                <div class="deliveroo-page-head-toolbar">
                    <a href="{{ route('deliveroo-products.create', ['brand' => $brand['slug'], 'family' => $familyName]) }}" class="button button-primary">{{ __('deliveroo.family_page.add_product') }}</a>
                    <a href="{{ route('deliveroo-products.catalogue-pdf', ['brand' => $brand['slug'], 'family' => $familyName]) }}" class="button">{{ __('deliveroo.all_products.export_family_pdf') }}</a>
                </div>
                <nav class="deliveroo-page-head-crumb" aria-label="{{ __('deliveroo.family_page.breadcrumb_nav') }}">
                    <a href="{{ route('deliveroo-products.index') }}" class="deliveroo-crumb-link">{{ __('deliveroo.family_page.hub_crumb') }}</a>
                    <span class="deliveroo-crumb-sep" aria-hidden="true">/</span>
                    <a href="{{ route('deliveroo-products.official-brand', ['brand' => $brand['slug']]) }}" class="deliveroo-crumb-link">{{ $brand['label'] }}</a>
                </nav>
            </div>
        </header>

        <section class="deliveroo-summary-bar deliveroo-summary-bar--triple" aria-label="Family stats">
            <article class="deliveroo-summary-item">
                <strong>{{ number_format($stats['products']) }}</strong>
                <span>Products</span>
            </article>
            <article class="deliveroo-summary-item">
                <strong>{{ number_format($stats['images']) }}</strong>
                <span>Image URLs</span>
            </article>
            <article class="deliveroo-summary-item deliveroo-summary-item--priced">
                <strong>{{ number_format($stats['priced']) }}</strong>
                <span>Priced</span>
            </article>
        </section>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">Family Progress</p>
                    <h3>Pricing overview</h3>
                    <p class="page-note">{{ number_format($stats['priced']) }} / {{ number_format($stats['products']) }} products priced in this family.</p>
                </div>
                <div class="deliveroo-section-head-meta">
                    <div class="deliveroo-section-progress">
                        <span class="deliveroo-section-progress-label">{{ $pricedPct }}%</span>
                        <div class="deliveroo-section-progress-track">
                            <div class="deliveroo-section-progress-fill" style="width: {{ $pricedPct }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        @if ($products->isEmpty())
            <article class="card">
                <div class="brand-empty-state py-12">
                    <h3>No sellable products found</h3>
                    <p class="page-note mt-2">This family does not currently contain any Deliveroo products.</p>
                </div>
            </article>
        @else
            <section
                class="deliveroo-section"
                data-deliveroo-brand-catalogue
                data-catalogue-storage-scope="family"
                data-selected-label-template="{{ e(__('deliveroo.brand_catalogue.selected_label_template')) }}"
                data-delete-one-confirm="{{ e(__('deliveroo.brand_catalogue.delete_one_confirm')) }}"
                data-bulk-delete-confirm="{{ e(__('deliveroo.brand_catalogue.bulk_delete_confirm')) }}"
            >
                <div class="deliveroo-catalogue-bulk-toolbar" hidden data-deliveroo-catalogue-bulk-toolbar>
                    <p class="deliveroo-catalogue-bulk-summary" data-deliveroo-catalogue-selected-summary></p>
                    <div class="deliveroo-catalogue-bulk-actions">
                        <button type="button" class="button" data-deliveroo-catalogue-clear>{{ __('deliveroo.brand_catalogue.clear_selection') }}</button>
                        <button type="button" class="button button-danger" data-deliveroo-catalogue-bulk-delete>
                            {{ __('deliveroo.brand_catalogue.delete_selected') }}
                        </button>
                    </div>
                </div>

                <form
                    id="deliveroo-catalogue-bulk-delete-form-family"
                    method="POST"
                    action="{{ route('deliveroo-products.official-brand.products.bulk-destroy', ['brand' => $brand['slug']]) }}"
                    class="deliveroo-catalogue-bulk-delete-form"
                    hidden
                    data-deliveroo-catalogue-bulk-form
                    aria-hidden="true"
                >
                    @csrf
                    @method('DELETE')
                    <div data-deliveroo-catalogue-bulk-inputs></div>
                </form>

                <details class="deliveroo-catalogue-layout-accordion">
                    <summary class="deliveroo-catalogue-layout-accordion-summary">
                        {{ __('deliveroo.brand_catalogue.layout_accordion_summary') }}
                    </summary>
                    <div class="deliveroo-catalogue-layout-toolbar deliveroo-catalogue-layout-toolbar--accordion-panel">
                        <div class="deliveroo-catalogue-layout-group">
                            <span class="deliveroo-catalogue-layout-label">{{ __('deliveroo.brand_catalogue.layout_view_label') }}</span>
                            <div class="deliveroo-catalogue-layout-segments" role="group" aria-label="{{ __('deliveroo.brand_catalogue.layout_view_label') }}">
                                <button type="button" class="deliveroo-catalogue-layout-segment" data-catalogue-layout-view="grid" aria-pressed="true">
                                    {{ __('deliveroo.brand_catalogue.layout_view_grid') }}
                                </button>
                                <button type="button" class="deliveroo-catalogue-layout-segment" data-catalogue-layout-view="list" aria-pressed="false">
                                    {{ __('deliveroo.brand_catalogue.layout_view_list') }}
                                </button>
                            </div>
                        </div>
                        <div class="deliveroo-catalogue-layout-group deliveroo-catalogue-layout-density" data-catalogue-layout-density-wrap>
                            <span class="deliveroo-catalogue-layout-label">{{ __('deliveroo.brand_catalogue.layout_columns_label') }}</span>
                            <div class="deliveroo-catalogue-layout-segments" role="group" aria-label="{{ __('deliveroo.brand_catalogue.layout_columns_label') }}">
                                <button type="button" class="deliveroo-catalogue-layout-segment" data-catalogue-layout-cols="4" aria-pressed="true">4</button>
                                <button type="button" class="deliveroo-catalogue-layout-segment" data-catalogue-layout-cols="6" aria-pressed="false">6</button>
                                <button type="button" class="deliveroo-catalogue-layout-segment" data-catalogue-layout-cols="8" aria-pressed="false">8</button>
                            </div>
                            <p class="deliveroo-catalogue-layout-hint">{{ __('deliveroo.brand_catalogue.layout_columns_hint') }}</p>
                        </div>
                    </div>
                </details>

                <div class="deliveroo-catalogue-grid-host" data-deliveroo-catalogue-grid-host data-view="grid" data-cols="4">
                <div class="deliveroo-product-grid--family-products">
                    @foreach ($products as $product)
                        @php
                            $primaryImage = collect($product->image_urls ?? [])->first();
                            $optionCount = count($product->option_values ?? []);
                            $isPriced = $product->price !== null;
                        @endphp

                        <article
                            class="deliveroo-product-card{{ $isPriced ? ' is-priced' : '' }}"
                            data-deliveroo-price-scope
                            data-deliveroo-catalogue-card
                            data-product-id="{{ $product->id }}"
                        >
                            <div class="deliveroo-product-card-chrome" data-deliveroo-catalogue-chrome>
                                <label class="deliveroo-catalogue-select-label">
                                    <input
                                        type="checkbox"
                                        class="deliveroo-catalogue-select-input"
                                        value="{{ $product->id }}"
                                        data-deliveroo-catalogue-select
                                        aria-label="{{ __('deliveroo.brand_catalogue.select_for_bulk') }}"
                                    >
                                    <span class="deliveroo-catalogue-select-face" aria-hidden="true"></span>
                                </label>
                                <form
                                    method="POST"
                                    action="{{ route('deliveroo-products.official-product.destroy', ['brand' => $brand['slug'], 'product' => $product]) }}"
                                    class="deliveroo-catalogue-delete-one"
                                    data-deliveroo-catalogue-delete-one
                                >
                                    @csrf
                                    @method('DELETE')
                                    <button
                                        type="submit"
                                        class="deliveroo-catalogue-icon-btn deliveroo-catalogue-icon-btn--delete"
                                        aria-label="{{ __('deliveroo.brand_catalogue.delete_one') }}"
                                        title="{{ __('deliveroo.brand_catalogue.delete_one') }}"
                                    >
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M3 6h18M8 6V4h8v2m-1 0v14a2 2 0 01-2 2H9a2 2 0 01-2-2V6h10z"/>
                                        </svg>
                                    </button>
                                </form>
                            </div>

                            <a href="{{ route('deliveroo-products.official-product', ['brand' => $brand['slug'], 'product' => $product]) }}" class="deliveroo-product-media">
                                @if ($primaryImage)
                                    <img src="{{ $primaryImage }}" alt="{{ $product->official_name }}">
                                @else
                                    <div class="deliveroo-product-media-empty">No image</div>
                                @endif
                            </a>

                            <div class="deliveroo-product-body">
                                @if ($product->family_name || $product->variant_name)
                                    <p class="deliveroo-product-line">
                                        @if ($product->family_name)
                                            <span>{{ $product->family_name }}</span>
                                        @endif
                                        @if ($product->family_name && $product->variant_name)
                                            <span>/</span>
                                        @endif
                                        @if ($product->variant_name)
                                            <span>{{ $product->variant_name }}</span>
                                        @endif
                                    </p>
                                @endif

                                <h3>{{ $product->official_name }}</h3>

                                <div class="deliveroo-card-price" data-deliveroo-price-filled @if (! $isPriced) hidden @endif>
                                    <span data-deliveroo-price-display>
                                        @if ($product->price !== null)
                                            {{ $product->price_display }}
                                        @else
                                            Not set
                                        @endif
                                    </span>
                                    <small data-deliveroo-price-note @if (! $product->price_notes) hidden @endif>{{ $product->price_notes }}</small>
                                </div>

                                <div class="deliveroo-card-needs-price" data-deliveroo-price-empty @if ($isPriced) hidden @endif>To price</div>

                                <div class="deliveroo-product-stats">
                                    <span>{{ count($product->image_urls ?? []) }} images</span>
                                    @if ($optionCount > 0)
                                        <span>{{ $optionCount }} options</span>
                                    @endif
                                </div>

                                <div class="button-row deliveroo-product-card-actions">
                                    <a href="{{ route('deliveroo-products.official-product', ['brand' => $brand['slug'], 'product' => $product]) }}" class="button button-primary">
                                        View Product
                                    </a>
                                    <button
                                        type="button"
                                        class="{{ $isPriced ? 'deliveroo-price-edit-button' : 'button button-primary deliveroo-price-add-button' }}"
                                        data-deliveroo-price-open
                                        data-has-price="{{ $isPriced ? '1' : '0' }}"
                                        aria-label="{{ $isPriced ? 'Edit price' : 'Add price' }}"
                                        title="{{ $isPriced ? 'Edit price' : 'Add price' }}"
                                    >
                                        @if ($isPriced)
                                            <span aria-hidden="true">&#9998;</span>
                                        @else
                                            Add Price
                                        @endif
                                    </button>
                                </div>
                            </div>

                            <div class="deliveroo-price-modal" hidden aria-hidden="true" data-deliveroo-price-modal>
                                <button type="button" class="deliveroo-price-modal-backdrop" data-deliveroo-price-close aria-label="Close price editor"></button>
                                <section class="deliveroo-price-modal-panel" role="dialog" aria-modal="true" aria-labelledby="deliveroo-price-modal-title-{{ $product->id }}">
                                    <div class="deliveroo-price-modal-head">
                                        <div>
                                            <h3 id="deliveroo-price-modal-title-{{ $product->id }}" data-deliveroo-price-modal-title>{{ $isPriced ? 'Edit Deliveroo Price' : 'Add Deliveroo Price' }}</h3>
                                            <p class="page-note">Update the selling price for this product without leaving the family page.</p>
                                        </div>
                                        <button type="button" class="deliveroo-price-modal-close" data-deliveroo-price-close aria-label="Close price editor">&times;</button>
                                    </div>

                                    <form method="POST" action="{{ route('deliveroo-products.official-product.price', ['brand' => $brand['slug'], 'product' => $product]) }}" class="deliveroo-price-form" data-deliveroo-price-form>
                                        @csrf
                                        @method('PATCH')

                                        <label class="deliveroo-field">
                                            <span>Price</span>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="999999.99"
                                                name="price"
                                                value="{{ $product->price }}"
                                                placeholder="0.00"
                                                data-deliveroo-price-input
                                            >
                                        </label>

                                        <label class="deliveroo-field deliveroo-field--full">
                                            <span>Note</span>
                                            <input
                                                type="text"
                                                name="price_notes"
                                                value="{{ $product->price_notes }}"
                                                placeholder="Optional note for Deliveroo pricing"
                                                data-deliveroo-price-note-input
                                            >
                                        </label>

                                        <p class="deliveroo-price-form-error" hidden data-deliveroo-price-error></p>

                                        <div class="button-row deliveroo-price-modal-actions">
                                            <button type="button" class="button" data-deliveroo-price-close>Cancel</button>
                                            <button type="submit" class="button button-primary" data-deliveroo-price-submit>Save Price</button>
                                        </div>
                                    </form>
                                </section>
                            </div>
                        </article>
                    @endforeach
                </div>
                </div>
            </section>
        @endif
    </div>
@endsection
