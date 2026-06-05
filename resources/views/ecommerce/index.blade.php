@extends('layouts.storefront')

@section('title', 'Shop')

@section('content')
    @php
        $activeLine = $activeLine ?? null;
        $activeLineId = $activeLineId ?? null;
        $shopListQuery = array_filter([
            'line_id' => $activeLineId,
            'line' => $activeLineId ? null : $activeLine,
        ]);
    @endphp
    <div class="store-grid-wrap">
        <div class="store-grid-head">
            <h1 class="store-h1">
                Shop
                @if (! empty($activeLine))
                    <span class="store-h1-context"> — {{ $activeLine }}</span>
                @endif
            </h1>
            <p class="store-sub">{{ $cards->count() }} {{ $cards->count() === 1 ? 'product' : 'products' }}</p>
        </div>

        <details class="store-filters-accordion">
            <summary class="store-filters-summary">
                <span class="store-filters-summary-label">Filter products</span>
                <em class="store-filters-active">{{ $filters[$activeFilter] }}</em>
            </summary>
            <div class="store-filters" role="tablist" aria-label="Filter products">
                @foreach ($filters as $key => $label)
                    <a href="{{ route('shop.index', array_merge($shopListQuery, $key === 'barcode' ? [] : ['filter' => $key])) }}"
                       class="store-filter {{ $activeFilter === $key ? 'is-active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        </details>

        @if ($cards->isEmpty())
            <p class="store-empty">No products match “{{ $filters[$activeFilter] }}”.</p>
        @else
            <div class="store-grid">
                @foreach ($cards as $card)
                    <a class="store-card" href="{{ route('shop.show', $card['family']) }}">
                        <div class="store-card-img">
                            @if ($card['image'])
                                <img src="{{ $card['image'] }}" alt="{{ $card['title'] }}" loading="lazy">
                            @else
                                <span class="store-card-noimg">No photo</span>
                            @endif
                        </div>
                        <div class="store-card-body">
                            <span class="store-card-brand">{{ $card['brand'] }}</span>
                            <strong class="store-card-title">{{ $card['title'] }}</strong>
                            <span class="store-card-price">
                                @if ($card['sharedPrice'] !== null)
                                    £{{ number_format($card['sharedPrice'], 2) }}
                                @elseif ($card['priceMin'] !== null && $card['priceMax'] !== null && $card['priceMin'] != $card['priceMax'])
                                    From £{{ number_format($card['priceMin'], 2) }}
                                @elseif ($card['priceMin'] !== null)
                                    £{{ number_format($card['priceMin'], 2) }}
                                @else
                                    —
                                @endif
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endsection
