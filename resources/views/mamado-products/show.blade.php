@extends('layouts.app')

@section('title', $product->item_code.' - Mamado')
@section('section', 'Mamado')
@section('heading', $product->item_code)

@section('content')
    @php
        $primaryImage = $gallery->first();
    @endphp

    <section class="dp-page">
        <nav class="dp-crumbs" aria-label="Breadcrumb">
            <a href="{{ route('mamado-products.index') }}">Mamado</a>
            @if ($product->brand_label)
                <span aria-hidden="true">&rsaquo;</span>
                <a href="{{ route('mamado-products.index', ['brand' => $product->brand_label]) }}">{{ $product->brand_label }}</a>
            @endif
            @if ($product->brand_label && $product->family_name)
                <span aria-hidden="true">&rsaquo;</span>
                <a href="{{ route('mamado-products.index', ['brand' => $product->brand_label, 'family' => $product->family_name]) }}">{{ Str::limit($product->family_name, 42) }}</a>
            @endif
            <span aria-hidden="true">&rsaquo;</span>
            <span class="dp-crumbs-current">{{ $product->item_code }}</span>
        </nav>

        <div class="dp-layout">
            <div class="dp-gallery">
                <div class="dp-hero">
                    @if ($primaryImage)
                        <img src="{{ $primaryImage }}" alt="{{ $product->sellable_name ?: $product->item_description }}" class="dp-hero-img">
                    @else
                        <div class="dp-hero-empty">No image available</div>
                    @endif
                </div>

                @if ($gallery->count() > 1)
                    <div class="dp-thumbs">
                        @foreach ($gallery as $idx => $imageUrl)
                            <button type="button" class="dp-thumb{{ $idx === 0 ? ' is-active' : '' }}" aria-label="View image {{ $idx + 1 }}">
                                <img src="{{ $imageUrl }}" alt="" loading="lazy">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="dp-info">
                <div class="dp-info-head">
                    <span class="dp-brand-tag">{{ $product->brand_label ?: 'Mamado source' }}</span>
                    <h1 class="dp-title">{{ $product->sellable_name ?: $product->item_description }}</h1>
                </div>

                <div class="dp-chips">
                    <div class="dp-chip">
                        <span class="dp-chip-label">Item code</span>
                        <span class="dp-chip-value">{{ $product->item_code }}</span>
                    </div>
                    @if ($product->family_name)
                        <div class="dp-chip">
                            <span class="dp-chip-label">Family</span>
                            <span class="dp-chip-value">{{ $product->family_name }}</span>
                        </div>
                    @endif
                    <div class="dp-chip">
                        <span class="dp-chip-label">Variant</span>
                        <span class="dp-chip-value">{{ $product->variant_name ?: 'Review pending' }}</span>
                    </div>
                    <div class="dp-chip">
                        <span class="dp-chip-label">Images</span>
                        <span class="dp-chip-value">{{ number_format($gallery->count()) }}</span>
                    </div>
                    @if ($product->source_order_number)
                        <div class="dp-chip">
                            <span class="dp-chip-label">Source order</span>
                            <span class="dp-chip-value">{{ $product->source_order_number }}</span>
                        </div>
                    @endif
                </div>

                <div class="dp-price-block">
                    <div class="dp-price-row">
                        <div class="dp-price-left">
                            <div class="dp-price-display">
                                <strong class="dp-price-amount">{{ $product->gross_unit_price_display }}</strong>
                                <small class="dp-price-note">Mamado gross unit price</small>
                            </div>
                        </div>
                    </div>
                </div>

                @if ($product->status === 'variant_review_pending')
                    <div class="deliveroo-card-needs-price">Variant review pending</div>
                @endif

                <details class="dp-desc" open>
                    <summary class="dp-desc-toggle">Mamado source row</summary>
                    <div class="dp-desc-body">
                        <p><strong>Description:</strong> {{ $product->item_description }}</p>
                        <p><strong>Units:</strong> {{ $product->units ?: 'n/a' }}</p>
                        <p><strong>Status:</strong> {{ Str::headline($product->status) }}</p>
                        <p><strong>Order date:</strong> {{ optional($product->source_order_date)->format('d M Y') ?: 'n/a' }}</p>
                        <p><strong>Delivery date:</strong> {{ optional($product->source_delivery_date)->format('d M Y') ?: 'n/a' }}</p>
                    </div>
                </details>

                <details class="dp-desc" open>
                    <summary class="dp-desc-toggle">Sellable product fields</summary>
                    <div class="dp-desc-body">
                        <p><strong>Brand:</strong> {{ $product->brand_label ?: 'Not set' }}</p>
                        <p><strong>Family:</strong> {{ $product->family_name ?: 'Not set' }}</p>
                        <p><strong>Variant:</strong> {{ $product->variant_name ?: 'Review pending' }}</p>
                        <p><strong>Sellable price:</strong> {{ $product->sellable_price_display }}</p>
                        @if ($product->description)
                            <p>{{ $product->description }}</p>
                        @endif
                    </div>
                </details>

                <div class="dp-actions">
                    @if ($product->brand_label)
                        <a href="{{ route('mamado-products.index', ['brand' => $product->brand_label]) }}" class="dp-action-btn dp-action-secondary">
                            Back to {{ $product->brand_label }}
                        </a>
                    @endif
                    <a href="{{ route('mamado-products.index') }}" class="dp-action-btn dp-action-secondary">Back to Mamado Products</a>
                </div>
            </div>
        </div>
    </section>
@endsection
