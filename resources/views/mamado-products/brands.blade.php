@extends('layouts.app')

@section('title', 'Mamado Brands')
@section('section', 'Mamado')
@section('heading', 'Brands')

@section('content')
    <section class="deliveroo-overview">
        <div class="deliveroo-hero-shell">
            <article class="deliveroo-hero">
                <div class="deliveroo-page-head">
                    <div class="deliveroo-page-head-copy">
                        <p class="eyebrow">Supplier Source Check</p>
                        <h2>Mamado Assigned Brands</h2>
                        <p class="page-note">
                            Brand buckets assigned from Mamado order descriptions. Open a brand to review its family groups and source products using the same catalogue flow as Deliveroo.
                        </p>
                    </div>
                </div>

                <div class="deliveroo-hero-actions">
                    <a href="{{ route('mamado-products.index') }}" class="button">Back to Mamado Products</a>
                    <a href="{{ route('mamado-products.index', ['status' => 'variant_review_pending']) }}" class="button button-primary">Variant Review</a>
                </div>

                <ul class="deliveroo-hero-metrics">
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--primary">
                        <span class="deliveroo-hero-metric-label">Brands</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['brands']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Products</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['products']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--priced">
                        <span class="deliveroo-hero-metric-label">Unassigned</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['unassigned']) }}</span>
                    </li>
                </ul>
            </article>
        </div>

        <section class="deliveroo-search-panel">
            <div class="deliveroo-search-panel-copy">
                <p class="eyebrow">Database Search</p>
                <h3>Find a Mamado brand</h3>
                <p class="page-note">Search the assigned brand list, then open the brand to review its product families.</p>
            </div>

            <form method="GET" action="{{ route('mamado-products.brands') }}" class="deliveroo-search-form">
                <label class="deliveroo-search-field">
                    <span class="sr-only">Search Mamado brands</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search brand..."
                        autocomplete="off"
                    >
                </label>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Search</button>
                    @if ($search !== '')
                        <a href="{{ route('mamado-products.brands') }}" class="button">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">Assigned Brands</p>
                    <h3>{{ number_format($brands->total()) }} brand{{ $brands->total() === 1 ? '' : 's' }}</h3>
                    <p class="page-note">Page {{ $brands->currentPage() }} of {{ $brands->lastPage() }}.</p>
                </div>
            </div>

            @if ($brands->isEmpty())
                <article class="card">
                    <div class="brand-empty-state py-12">
                        <h3>No brands found</h3>
                        <p class="page-note mt-2">Try another search.</p>
                    </div>
                </article>
            @else
                <div class="deliveroo-brand-grid">
                    @foreach ($brands as $brand)
                        <article class="deliveroo-brand-tile">
                            <div class="deliveroo-brand-tile-head">
                                <div class="deliveroo-brand-mark">{{ $brand->mark }}</div>

                                <div class="deliveroo-brand-heading">
                                    <p class="deliveroo-brand-kicker">Mamado Supplier</p>
                                    <h4>{{ $brand->brand_label }}</h4>
                                </div>

                                <span class="deliveroo-count-pill">{{ number_format((int) $brand->product_count) }}</span>
                            </div>

                            <div class="deliveroo-brand-stats">
                                <div>
                                    <span>Products</span>
                                    <strong>{{ number_format((int) $brand->product_count) }}</strong>
                                </div>
                                <div>
                                    <span>Families</span>
                                    <strong>{{ number_format((int) $brand->family_count) }}</strong>
                                </div>
                                <div>
                                    <span>Images</span>
                                    <strong>{{ number_format((int) $brand->image_count) }}</strong>
                                </div>
                                <div>
                                    <span>Review</span>
                                    <strong>{{ number_format((int) $brand->variant_review_count) }}</strong>
                                </div>
                            </div>

                            <div class="deliveroo-card-price">
                                @if ($brand->min_price !== null && $brand->max_price !== null)
                                    <span>
                                        &pound;{{ number_format((float) $brand->min_price, 2) }}
                                        @if ((float) $brand->min_price !== (float) $brand->max_price)
                                            - &pound;{{ number_format((float) $brand->max_price, 2) }}
                                        @endif
                                    </span>
                                    <small>Gross unit range</small>
                                @else
                                    <span>n/a</span>
                                    <small>Gross unit range</small>
                                @endif
                            </div>

                            <div class="deliveroo-product-stats">
                                <span>{{ number_format((int) $brand->order_count) }} orders</span>
                                @if ((int) $brand->variant_review_count > 0)
                                    <span>{{ number_format((int) $brand->variant_review_count) }} variant review</span>
                                @endif
                            </div>

                            <a href="{{ route('mamado-products.index', ['brand' => $brand->brand_label]) }}" class="button button-primary deliveroo-brand-link">
                                Open Products
                            </a>
                        </article>
                    @endforeach
                </div>

                <div class="deliveroo-all-products-pagination mt-8">
                    {{ $brands->links() }}
                </div>
            @endif
        </section>
    </section>
@endsection
