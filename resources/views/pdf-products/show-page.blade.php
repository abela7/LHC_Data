@extends('layouts.app')

@section('title', 'PDF Page '.$page->page_number)
@section('section', 'PDF Catalogue')
@section('heading', 'Page '.$page->page_number)

@section('content')
    <section class="page-head">
        <div>
            <p class="eyebrow">{{ $page->source_name }}</p>
            <h2>Page {{ $page->page_number }}</h2>
            <p class="page-note">
                Brand context: {{ $page->brand_context ?: 'Unknown' }}
                @if ($page->brand_context_source)
                    · {{ str_replace('_', ' ', $page->brand_context_source) }}
                @endif
            </p>
        </div>
        <div class="air-stats-row">
            <span class="air-stat-chip">
                <span class="air-stat-number">{{ number_format($stats['products']) }}</span>
                <span class="air-stat-label">Products</span>
            </span>
            <span class="air-stat-chip air-stat-chip--warn">
                <span class="air-stat-number">{{ number_format($stats['needs_review']) }}</span>
                <span class="air-stat-label">Need review</span>
            </span>
            <span class="air-stat-chip air-stat-chip--muted">
                <span class="air-stat-number">{{ number_format($stats['with_images']) }}</span>
                <span class="air-stat-label">With photos</span>
            </span>
        </div>
    </section>

    <article class="card">
        <div class="brand-chip-row">
            <a href="{{ route('pdf-products.index', ['page_number' => $page->page_number]) }}" class="button button-primary">Filter this page</a>
            <a href="{{ route('pdf-products.index') }}" class="button">Back to list</a>
            @if ($page->header_text)
                <span class="brand-chip brand-chip-soft">{{ $page->header_text }}</span>
            @endif
        </div>
    </article>

    <div class="split-grid">
        <article class="card">
            <h3 style="margin-bottom:0.75rem;">Extracted products</h3>
            @if ($page->products->isEmpty())
                <div class="brand-empty-state py-8">
                    <h3>No extracted products</h3>
                    <p class="page-note mt-2">This page may be introductory or the parser may need a manual review.</p>
                </div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Code</th>
                                <th>Product</th>
                                <th>Brand</th>
                                <th>Confidence</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($page->products as $product)
                                @php
                                    $primaryImage = $product->images->first();
                                    $primaryImageUrl = $primaryImage?->displayUrl();
                                @endphp
                                <tr>
                                    <td style="width:5.5rem;">
                                        @if ($primaryImageUrl)
                                            <img
                                                src="{{ $primaryImageUrl }}"
                                                alt="{{ $product->product_name }}"
                                                loading="lazy"
                                                style="width:72px;height:72px;object-fit:contain;border-radius:12px;border:1px solid #e7ded1;background:#fffaf3;"
                                            >
                                            @if ($product->pdf_catalogue_images_count > 1)
                                                <div class="page-note" style="font-size:0.72rem;">+{{ $product->pdf_catalogue_images_count - 1 }} more</div>
                                            @endif
                                        @else
                                            <span class="pill pill-warn">No photo</span>
                                        @endif
                                    </td>
                                    <td><code class="sw-chip-code">{{ $product->product_code }}</code></td>
                                    <td>
                                        <strong>{{ $product->product_name }}</strong>
                                        @if ($product->confidence_reason)
                                            <div class="page-note" style="margin-top:4px;">{{ $product->confidence_reason }}</div>
                                        @endif
                                    </td>
                                    <td><span class="brand-chip">{{ $product->brand }}</span></td>
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
            @endif
        </article>

        <article class="card">
            <h3 style="margin-bottom:0.75rem;">Raw page text</h3>
            <pre style="white-space:pre-wrap; word-break:break-word; font-size:0.8rem; line-height:1.45; margin:0;">{{ $page->raw_text }}</pre>
        </article>
    </div>
@endsection
