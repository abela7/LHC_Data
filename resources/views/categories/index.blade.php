@extends('layouts.app')

@section('title', 'Categories')

@section('content')
    <section class="brand-hero">
        <div class="brand-hero-copy">
            <p class="eyebrow">Major Categories</p>
            <h2>Top-Level Product Groups</h2>
            <p class="brand-hero-note">Hair = extensions and hair pieces. Body Care = hair care, styling, treatment, and skin/body products. Cosmetics = makeup.</p>
            <div class="brand-hero-tags">
                <span class="pill">{{ number_format($stats['categories']) }} categories</span>
                <span class="pill">{{ number_format($stats['products']) }} grouped products</span>
                <span class="pill">{{ number_format($stats['rows']) }} observed rows</span>
            </div>
        </div>

        <aside class="brand-hero-panel">
            <p class="helper-title">Current scope</p>
            <p class="brand-hero-panel-copy">Fixed categories: Hair, Body Care, Cosmetics.</p>
            <div class="button-row">
                <a href="{{ route('categories.scaffold') }}" class="button button-primary">Open category scaffold</a>
            </div>
        </aside>
    </section>

    <section class="brand-grid">
        @foreach ($categories as $category)
            @php
                $initials = collect(explode(' ', $category->name))
                    ->filter()
                    ->take(2)
                    ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
                    ->implode('');
            @endphp

            <article class="brand-card brand-card-featured">
                <div class="brand-card-top">
                    <div class="brand-avatar">{{ $initials }}</div>
                    <div class="brand-card-heading">
                        <p class="brand-card-kicker">Major category</p>
                        <h3>
                            <a href="{{ route('categories.show', ['category' => $category->slug]) }}" class="product-card-title-link">
                                {{ $category->name }}
                            </a>
                        </h3>
                        <p class="brand-card-subtitle">{{ number_format($category->brand_count) }} brand{{ $category->brand_count === 1 ? '' : 's' }}</p>
                    </div>
                </div>

                <div class="brand-card-stats">
                    <div>
                        <span>{{ number_format($category->product_count) }}</span>
                        <small>Products</small>
                    </div>
                    <div>
                        <span>{{ number_format($category->picture_count) }}</span>
                        <small>Pictures</small>
                    </div>
                    <div>
                        <span>{{ number_format($category->row_count) }}</span>
                        <small>Rows</small>
                    </div>
                </div>

                <p class="brand-card-note">
                    @if ($category->product_count === 0)
                        No observed products yet.
                    @else
                        Ready for subcategory grouping.
                    @endif
                </p>

                <div class="brand-product-link-row">
                    <a href="{{ route('categories.show', ['category' => $category->slug]) }}" class="brand-product-link">Open category</a>
                </div>
            </article>
        @endforeach
    </section>
@endsection
