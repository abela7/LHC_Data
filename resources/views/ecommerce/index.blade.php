@extends('layouts.storefront')

@section('title', 'Shop')

@section('content')
    <div class="store-grid-wrap">
        <div class="store-grid-head">
            <h1 class="store-h1">Shop</h1>
            <p class="store-sub">{{ $cards->count() }} {{ $cards->count() === 1 ? 'product' : 'products' }}</p>
        </div>

        @if ($cards->isEmpty())
            <p class="store-empty">No products with photos yet.</p>
        @else
            <div class="store-grid">
                @foreach ($cards as $card)
                    <a class="store-card" href="{{ route('shop.show', $card['family']) }}">
                        <div class="store-card-img">
                            <img src="{{ $card['image'] }}" alt="{{ $card['title'] }}" loading="lazy">
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
