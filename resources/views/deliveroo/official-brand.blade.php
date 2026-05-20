@extends('layouts.app')

@section('title', $brand['label'].' Official Products')
@section('section', 'Deliveroo')
@section('heading', $brand['label'].' Official')

@section('content')
    @php
        $pricedPct = $stats['products'] > 0 ? round(($stats['priced'] / $stats['products']) * 100) : 0;
    @endphp

    <div class="deliveroo-brand-page" data-deliveroo-bulk-price-scope>
        <header class="deliveroo-page-head">
            <div class="deliveroo-page-head-copy">
                <h2>{{ $brand['label'] }} Official Products</h2>
                <p class="page-note">
                    This brand is now grouped by product family. Open a family to work on the actual sellable products under it.
                </p>
            </div>

            <div class="deliveroo-page-head-actions button-row">
                <button type="button" class="button button-primary" data-deliveroo-bulk-open>Set Brand Price</button>
                <a href="{{ route('deliveroo-products.catalogue-pdf', ['brand' => $brand['slug']]) }}" class="button">{{ __('deliveroo.all_products.export_brand_pdf') }}</a>
                <a href="{{ route('deliveroo-products.create', ['brand' => $brand['slug']]) }}" class="button">{{ __('deliveroo.manual_product.add_for_brand') }}</a>
                <a href="{{ route('deliveroo-products.index') }}" class="button">Back to Deliveroo Products</a>
            </div>
        </header>

        <section class="deliveroo-summary-bar">
            <article class="deliveroo-summary-item">
                <strong>{{ number_format($stats['products']) }}</strong>
                <span>Products</span>
            </article>
            <article class="deliveroo-summary-item">
                <strong>{{ number_format($stats['families']) }}</strong>
                <span>Families</span>
            </article>
            <article class="deliveroo-summary-item">
                <strong>{{ number_format($stats['images']) }}</strong>
                <span>Image URLs</span>
            </article>
            <article class="deliveroo-summary-item deliveroo-summary-item--priced">
                <strong data-deliveroo-brand-priced-total>{{ number_format($stats['priced']) }}</strong>
                <span>Priced</span>
            </article>
        </section>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">Brand Progress</p>
                    <h3>Pricing overview</h3>
                    <p class="page-note">
                        <span data-deliveroo-brand-priced-summary>{{ number_format($stats['priced']) }} / {{ number_format($stats['products']) }}</span> products priced for this brand.
                    </p>
                </div>
                <div class="deliveroo-section-head-meta">
                    <div class="deliveroo-section-progress">
                        <span class="deliveroo-section-progress-label">{{ $pricedPct }}%</span>
                        <div class="deliveroo-section-progress-track">
                            <div class="deliveroo-section-progress-fill" data-deliveroo-brand-progress-fill style="width: {{ $pricedPct }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="deliveroo-price-modal" hidden aria-hidden="true" data-deliveroo-bulk-modal>
            <button type="button" class="deliveroo-price-modal-backdrop" data-deliveroo-bulk-close aria-label="Close bulk price editor"></button>
            <section class="deliveroo-price-modal-panel" role="dialog" aria-modal="true" aria-labelledby="deliveroo-bulk-price-title">
                <div class="deliveroo-price-modal-head">
                    <div>
                        <h3 id="deliveroo-bulk-price-title">Set Brand Price</h3>
                        <p class="page-note">Apply one price to all {{ number_format($stats['products']) }} {{ $brand['label'] }} products.</p>
                    </div>
                    <button type="button" class="deliveroo-price-modal-close" data-deliveroo-bulk-close aria-label="Close bulk price editor">&times;</button>
                </div>

                <form method="POST" action="{{ route('deliveroo-products.official-brand.price', ['brand' => $brand['slug']]) }}" class="deliveroo-price-form" data-deliveroo-bulk-form>
                    @csrf
                    @method('PATCH')

                    <label class="deliveroo-field">
                        <span>Price</span>
                        <input type="number" step="0.01" min="0" max="999999.99" name="price" placeholder="0.00" data-deliveroo-bulk-price-input>
                    </label>

                    <label class="deliveroo-field deliveroo-field--full">
                        <span>Note</span>
                        <input type="text" name="price_notes" placeholder="Optional note for all products in this brand" data-deliveroo-bulk-note-input>
                    </label>

                    <p class="deliveroo-price-form-error" hidden data-deliveroo-bulk-error></p>

                    <div class="button-row deliveroo-price-modal-actions">
                        <button type="button" class="button" data-deliveroo-bulk-close>Cancel</button>
                        <button type="submit" class="button button-primary" data-deliveroo-bulk-submit>Save Brand Price</button>
                    </div>
                </form>
            </section>
        </div>

        @if ($families->isEmpty())
            <article class="card">
                <div class="brand-empty-state py-12">
                    <h3>No product families loaded yet</h3>
                    <p class="page-note mt-2">This brand has no family records in the Deliveroo product table yet.</p>
                </div>
            </article>
        @else
            <section class="deliveroo-section">
                <div class="deliveroo-product-grid deliveroo-product-grid--catalogue">
                    @foreach ($families as $family)
                        @php
                            $familyPricedPct = $family['product_count'] > 0 ? round(($family['priced_count'] / $family['product_count']) * 100) : 0;
                        @endphp

                        <article class="deliveroo-product-card{{ $family['priced_count'] > 0 ? ' is-priced' : '' }}">
                            <a href="{{ route('deliveroo-products.official-family.short', ['brand' => $brand['slug'], 'family' => $family['token']]) }}" class="deliveroo-product-media">
                                @if ($family['primary_image'])
                                    <img src="{{ $family['primary_image'] }}" alt="{{ $family['name'] }}">
                                @else
                                    <div class="deliveroo-product-media-empty">No image</div>
                                @endif
                            </a>

                            <div class="deliveroo-product-body">
                                <p class="deliveroo-product-line">
                                    <span>{{ $brand['label'] }}</span>
                                    <span>/</span>
                                    <span>Family</span>
                                </p>

                                <h3>{{ $family['name'] }}</h3>

                                <div class="deliveroo-card-price">
                                    <span>{{ number_format($family['priced_count']) }} / {{ number_format($family['product_count']) }} priced</span>
                                    <small>{{ $familyPricedPct }}% complete</small>
                                </div>

                                <div class="deliveroo-product-stats">
                                    <span>{{ number_format($family['product_count']) }} products</span>
                                    <span>{{ number_format($family['image_count']) }} images</span>
                                </div>

                                @if ($family['variant_preview'] !== [])
                                    <div class="deliveroo-card-chip-row">
                                        @foreach ($family['variant_preview'] as $variant)
                                            <span class="deliveroo-chip">{{ $variant }}</span>
                                        @endforeach
                                        @if ($family['more_variants'] > 0)
                                            <span class="deliveroo-chip">+{{ $family['more_variants'] }} more</span>
                                        @endif
                                    </div>
                                @endif

                                @if ($family['description'] !== '')
                                    <p class="page-note">{{ \Illuminate\Support\Str::limit($family['description'], 140) }}</p>
                                @endif

                                <div class="button-row">
                                    <a href="{{ route('deliveroo-products.official-family.short', ['brand' => $brand['slug'], 'family' => $family['token']]) }}" class="button button-primary">
                                        Open Product
                                    </a>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
