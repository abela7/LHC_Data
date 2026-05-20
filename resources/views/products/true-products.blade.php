@extends('layouts.app')

@section('title', 'True Products')
@section('section', 'Products')
@section('heading', 'True Products')

@section('content')
    <section class="page-head">
        <div>
            <p class="eyebrow">Shop Truth</p>
            <h2>Top 250 Picture + PDF Matches</h2>
            <p class="page-note">Unique products that appear in both the shelf-picture import and the Sherrys PDF. This page shows the strongest 250 likely overlaps, deduped by brand and product name.</p>
        </div>
        <div class="air-stats-row">
            <span class="air-stat-chip">
                <span class="air-stat-number">{{ number_format($stats['top_250']) }}</span>
                <span class="air-stat-label">Top matches</span>
            </span>
            <span class="air-stat-chip air-stat-chip--muted">
                <span class="air-stat-number">{{ number_format($stats['exact']) }}</span>
                <span class="air-stat-label">Exact</span>
            </span>
            <span class="air-stat-chip">
                <span class="air-stat-number">{{ number_format($stats['a']) }}</span>
                <span class="air-stat-label">A</span>
            </span>
            <span class="air-stat-chip air-stat-chip--muted">
                <span class="air-stat-number">{{ number_format($stats['b']) }}</span>
                <span class="air-stat-label">B</span>
            </span>
            <span class="air-stat-chip air-stat-chip--warn">
                <span class="air-stat-number">{{ number_format($stats['c']) }}</span>
                <span class="air-stat-label">C</span>
            </span>
            <span class="air-stat-chip">
                <span class="air-stat-number">{{ number_format($stats['priced']) }}</span>
                <span class="air-stat-label">Priced</span>
            </span>
        </div>
    </section>

    <article class="card">
        <form method="GET" action="{{ route('products.true-products') }}" class="brand-toolbar-grid">
            <div class="brand-search-field">
                <label>
                    <span class="sr-only">Search true products</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ request('search') }}"
                        placeholder="Search by brand, product name, code, or picture..."
                        autocomplete="off"
                    >
                </label>
            </div>
            <div class="flex flex-wrap gap-2">
                <label class="sr-only" for="filter-confidence">Match confidence</label>
                <select name="confidence" id="filter-confidence" onchange="this.form.submit()" class="h-11 rounded-full px-4 py-2 text-sm">
                    <option value="">All confidence</option>
                    @foreach (['A', 'B', 'C'] as $grade)
                        <option value="{{ $grade }}" @selected(request('confidence') === $grade)>{{ $grade }}</option>
                    @endforeach
                </select>

                <button type="submit" class="button button-primary">Search</button>

                @if (request('search') || request('confidence'))
                    <a href="{{ route('products.true-products') }}" class="button">Clear</a>
                @endif
            </div>
        </form>
    </article>

    @if ($matches->isEmpty())
        <article class="card">
            <div class="brand-empty-state py-12">
                <h3>No matched products found</h3>
                <p class="page-note mt-2">Try a different search or confidence filter.</p>
            </div>
        </article>
    @else
        <article class="card" style="padding:0; overflow:hidden;">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:3rem">#</th>
                            <th>Shop Product</th>
                            <th>PDF Match</th>
                            <th>Score</th>
                            <th>Confidence</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($matches as $index => $match)
                            <tr>
                                <td style="color:#9a9590;font-variant-numeric:tabular-nums;">{{ $matches->firstItem() + $index }}</td>
                                <td>
                                    <div class="brand-list-cell">
                                        <span class="brand-chip">{{ $match['observed_brand'] }}</span>
                                        <strong>{{ $match['observed_name'] }}</strong>
                                        <div class="page-note" style="margin-top:4px;">
                                            <a href="{{ route('pictures.show', ['pictureId' => $match['picture_id']]) }}" class="brand-product-link">
                                                {{ $match['picture_id'] }}
                                            </a>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="brand-list-cell">
                                        <span class="brand-chip brand-chip-muted">{{ $match['pdf_brand'] }}</span>
                                        <strong>{{ $match['pdf_name'] }}</strong>
                                        <div class="page-note" style="margin-top:4px;">
                                            <code class="sw-chip-code">{{ $match['pdf_code'] }}</code>
                                            @if ($match['pdf_page_id'])
                                                <span style="margin-left:8px;">
                                                    <a href="{{ route('pdf-products.pages.show', ['page' => $match['pdf_page_id']]) }}" class="brand-product-link">
                                                        Page {{ $match['pdf_page_number'] }}
                                                    </a>
                                                </span>
                                            @else
                                                <span style="margin-left:8px;">Page {{ $match['pdf_page_number'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="pill">{{ $match['score'] }}</span>
                                </td>
                                <td>
                                    <span class="pill{{ $match['match_confidence'] === 'C' ? ' pill-warn' : '' }}">
                                        {{ $match['match_confidence'] }}
                                    </span>
                                    @if ($match['is_exact'])
                                        <div class="page-note" style="margin-top:4px;">Exact name</div>
                                    @endif
                                </td>
                                <td style="min-width: 18rem;">
                                    <form method="POST" action="{{ route('products.true-products.price') }}" class="true-price-form">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="match_key" value="{{ $match['match_key'] }}">
                                        <input type="hidden" name="observed_brand" value="{{ $match['observed_brand'] }}">
                                        <input type="hidden" name="observed_name" value="{{ $match['observed_name'] }}">
                                        <input type="hidden" name="search" value="{{ request('search') }}">
                                        <input type="hidden" name="confidence_filter" value="{{ request('confidence') }}">
                                        <label class="sr-only" for="price-{{ $loop->index }}">Price</label>
                                        <input
                                            id="price-{{ $loop->index }}"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            name="price"
                                            value="{{ $match['saved_price'] }}"
                                            placeholder="0.00"
                                            class="true-price-input"
                                        >
                                        <label class="sr-only" for="currency-{{ $loop->index }}">Currency</label>
                                        <select id="currency-{{ $loop->index }}" name="currency" class="true-price-select">
                                            @foreach (['GBP', 'USD', 'EUR'] as $currency)
                                                <option value="{{ $currency }}" @selected($match['saved_currency'] === $currency)>{{ $currency }}</option>
                                            @endforeach
                                        </select>
                                        <label class="sr-only" for="notes-{{ $loop->index }}">Notes</label>
                                        <input
                                            id="notes-{{ $loop->index }}"
                                            type="text"
                                            name="notes"
                                            value="{{ $match['saved_notes'] }}"
                                            placeholder="Optional note"
                                            class="true-price-note"
                                        >
                                        <button type="submit" class="button button-primary">Save</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </article>

        <div class="pagination-wrap">
            {{ $matches->links() }}
        </div>
    @endif
@endsection
