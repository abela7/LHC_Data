@extends('layouts.app')

@section('title', 'PDF Products')
@section('section', 'PDF Catalogue')
@section('heading', 'Products')

@section('content')
    <section class="page-head">
        <div>
            <p class="eyebrow">Sherrys catalogue</p>
            <h2>PDF Products</h2>
            <p class="page-note">One row per extracted product code from the PDF staging import.</p>
        </div>
        <div class="header-actions">
            <a href="{{ route('products.true-products') }}" class="button button-primary">True Products</a>
            <div class="air-stats-row">
                <span class="air-stat-chip">
                    <span class="air-stat-number">{{ number_format($stats['products']) }}</span>
                    <span class="air-stat-label">Products</span>
                </span>
                <span class="air-stat-chip air-stat-chip--muted">
                    <span class="air-stat-number">{{ number_format($stats['pages']) }}</span>
                    <span class="air-stat-label">Pages</span>
                </span>
                <span class="air-stat-chip air-stat-chip--warn">
                    <span class="air-stat-number">{{ number_format($stats['needs_review']) }}</span>
                    <span class="air-stat-label">Need review</span>
                </span>
                <span class="air-stat-chip air-stat-chip--muted">
                    <span class="air-stat-number">{{ number_format($stats['sherrys_with_images']) }}/{{ number_format($stats['sherrys_products']) }}</span>
                    <span class="air-stat-label">Sherrys photos</span>
                </span>
            </div>
        </div>
    </section>

    <article class="card">
        <form method="GET" action="{{ route('pdf-products.index') }}" class="brand-toolbar-grid">
            <div class="brand-search-field">
                <label>
                    <span class="sr-only">Search PDF products</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ request('search') }}"
                        placeholder="Search by brand, product code, or product name..."
                        autocomplete="off"
                    >
                </label>
            </div>
            <div class="flex flex-wrap gap-2">
                <label class="sr-only" for="filter-source">Source</label>
                <select name="source" id="filter-source" onchange="this.form.submit()" class="h-11 rounded-full px-4 py-2 text-sm">
                    <option value="">All PDF sources</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source }}" @selected(request('source') === $source)>{{ $source }}</option>
                    @endforeach
                </select>

                <label class="sr-only" for="filter-confidence">Confidence</label>
                <select name="confidence" id="filter-confidence" onchange="this.form.submit()" class="h-11 rounded-full px-4 py-2 text-sm">
                    <option value="">All confidence</option>
                    @foreach (['A', 'B', 'C', 'D'] as $grade)
                        <option value="{{ $grade }}" @selected(request('confidence') === $grade)>{{ $grade }}</option>
                    @endforeach
                </select>

                <label class="sr-only" for="filter-image-status">Image status</label>
                <select name="image_status" id="filter-image-status" onchange="this.form.submit()" class="h-11 rounded-full px-4 py-2 text-sm">
                    <option value="">All image status</option>
                    <option value="with_image" @selected(request('image_status') === 'with_image')>Has Sherrys photo</option>
                    <option value="missing_image" @selected(request('image_status') === 'missing_image')>Missing Sherrys photo</option>
                </select>

                <label class="sr-only" for="filter-page-number">Page number</label>
                <input
                    id="filter-page-number"
                    type="number"
                    min="1"
                    name="page_number"
                    value="{{ request('page_number') }}"
                    placeholder="Page"
                    class="h-11 rounded-full px-4 py-2 text-sm"
                >

                <button type="submit" class="button button-primary">Search</button>

                @if (request('search') || request('confidence') || request('page_number') || request('source') || request('image_status'))
                    <a href="{{ route('pdf-products.index') }}" class="button">Clear</a>
                @endif
            </div>
        </form>
    </article>

    <article class="card">
        <div class="brand-chip-row">
            <span class="brand-chip">A: {{ number_format($stats['a']) }}</span>
            <span class="brand-chip brand-chip-muted">B: {{ number_format($stats['b']) }}</span>
            <span class="brand-chip brand-chip-soft">C: {{ number_format($stats['c']) }}</span>
            <span class="brand-chip" style="background:#f8d7da;color:#842029;">D: {{ number_format($stats['d']) }}</span>
        </div>
    </article>

    @if ($products->isEmpty())
        <article class="card">
            <div class="brand-empty-state py-12">
                <h3>No PDF products found</h3>
                <p class="page-note mt-2">Run the PDF import command or change the filters.</p>
            </div>
        </article>
    @else
        <article class="card" style="padding:0; overflow:hidden;">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:3rem">#</th>
                            <th style="width:5.5rem">Photo</th>
                            <th>Brand</th>
                            <th>Product Code</th>
                            <th>Product Name</th>
                            <th>Page</th>
                            <th>Confidence</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $index => $product)
                            @php
                                $primaryImage = $product->images->first();
                                $primaryImageUrl = $primaryImage?->displayUrl();
                            @endphp
                            <tr class="clickable-row" data-href="{{ route('pdf-products.pages.show', $product->page) }}">
                                <td style="color:#9a9590;font-variant-numeric:tabular-nums;">{{ $products->firstItem() + $index }}</td>
                                <td>
                                    @if ($primaryImageUrl)
                                        <img
                                            src="{{ $primaryImageUrl }}"
                                            alt="{{ $product->product_name }}"
                                            loading="lazy"
                                            style="width:64px;height:64px;object-fit:contain;border-radius:12px;border:1px solid #e7ded1;background:#fffaf3;"
                                        >
                                        @if ($product->pdf_catalogue_images_count > 1)
                                            <div class="page-note" style="font-size:0.72rem;">+{{ $product->pdf_catalogue_images_count - 1 }} more</div>
                                        @endif
                                    @else
                                        <span class="pill pill-warn">No photo</span>
                                    @endif
                                </td>
                                <td><span class="brand-chip">{{ $product->brand }}</span></td>
                                <td><code class="sw-chip-code">{{ $product->product_code }}</code></td>
                                <td>
                                    <span class="clickable-row-name">{{ $product->product_name }}</span>
                                    @if ($product->confidence_reason)
                                        <div class="page-note" style="margin-top:4px;">{{ $product->confidence_reason }}</div>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('pdf-products.pages.show', $product->page) }}" class="brand-product-link clickable-row-stop">
                                        Page {{ $product->page_number }}
                                    </a>
                                </td>
                                <td>
                                    <span class="pill{{ $product->confidence === 'C' ? ' pill-warn' : '' }}{{ $product->confidence === 'D' ? ' pill-danger' : '' }}">
                                        {{ $product->confidence }}
                                    </span>
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
