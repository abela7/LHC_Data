@extends('layouts.app')

@section('title', 'Products')

@section('content')
    <section class="page-head">
        <div>
            <p class="eyebrow">Catalogue</p>
            <h2>Products</h2>
            <p class="page-note">{{ number_format($total) }} observed products from shelf photos.</p>
        </div>
        <div class="header-actions">
            @if(request('brand') || request('category_id') || request('search'))
                <span class="pill pill-warning">{{ number_format($products->total()) }} results</span>
            @else
                <span class="pill">{{ number_format($total) }} total</span>
            @endif
        </div>
    </section>

    <article class="card">
        <form method="GET" action="{{ route('products.index') }}" class="brand-toolbar-grid">
            <div class="brand-search-field">
                <label>
                    <span class="sr-only">Search products</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ request('search') }}"
                        placeholder="Search by product name or brand..."
                        autocomplete="off"
                    >
                </label>
            </div>
            <div class="flex flex-wrap gap-2">
                <label class="sr-only" for="filter-brand">Brand</label>
                <select name="brand" id="filter-brand" onchange="this.form.submit()" class="h-11 rounded-full px-4 py-2 text-sm">
                    <option value="">All brands</option>
                    @foreach($brandOptions as $b)
                        <option value="{{ $b }}" @selected(request('brand') === $b)>{{ $b }}</option>
                    @endforeach
                </select>

                <label class="sr-only" for="filter-category">Category</label>
                <select name="category_id" id="filter-category" onchange="this.form.submit()" class="h-11 rounded-full px-4 py-2 text-sm">
                    <option value="">All categories</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>

                <button type="submit" class="button button-primary">Search</button>

                @if(request('brand') || request('category_id') || request('search'))
                    <a href="{{ route('products.index') }}" class="button">Clear</a>
                @endif
            </div>
        </form>
    </article>

    @if($products->isEmpty())
        <article class="card">
            <div class="brand-empty-state py-12">
                <h3>No products found</h3>
                <p class="page-note mt-2">Try adjusting your search or filters.</p>
            </div>
        </article>
    @else
        <article class="card" style="padding:0; overflow:hidden;">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:3rem">#</th>
                            <th>Brand</th>
                            <th>Product Name</th>
                            <th>Confidence</th>
                            <th>Category</th>
                            <th>Picture</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products as $i => $product)
                            <tr class="clickable-row" data-href="{{ route('products.show', $product) }}">
                                <td style="color:#9a9590;font-variant-numeric:tabular-nums;">{{ $products->firstItem() + $i }}</td>
                                <td>
                                    @if($product->canonical_brand)
                                        <span class="brand-chip">{{ $product->canonical_brand }}</span>
                                        @if($product->brand_line)
                                            <br><span class="brand-chip brand-chip-muted" style="margin-top:4px;display:inline-flex;">{{ $product->brand_line }}</span>
                                        @endif
                                    @else
                                        <span style="color:#9a9590">-</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="clickable-row-name">{{ $product->product_name }}</span>
                                </td>
                                <td>
                                    @if($product->ai_confidence)
                                        <span class="pill{{ $product->ai_confidence === 'C' ? ' pill-warn' : '' }}{{ $product->ai_confidence === 'D' ? ' pill-danger' : '' }}">
                                            {{ $product->ai_confidence }}
                                        </span>
                                    @else
                                        <span style="color:#9a9590">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if($product->category)
                                        <span class="pill">{{ $product->category->name }}</span>
                                    @else
                                        <span style="color:#9a9590">-</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('pictures.show', ['pictureId' => $product->picture_id]) }}" class="brand-product-link clickable-row-stop">
                                        {{ $product->picture_id }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </article>

        <div class="pagination-wrap">
            {{ $products->links() }}
        </div>
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.clickable-row[data-href]').forEach(function (row) {
                row.addEventListener('click', function (e) {
                    // Don't navigate if clicking an actual link or button inside the row
                    if (e.target.closest('.clickable-row-stop')) return;
                    var href = row.dataset.href;
                    if (e.ctrlKey || e.metaKey) {
                        window.open(href, '_blank');
                    } else {
                        window.location.href = href;
                    }
                });
            });
        });
    </script>
@endsection
