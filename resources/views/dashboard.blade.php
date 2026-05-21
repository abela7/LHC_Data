@extends('layouts.app')

@section('title', 'LHC Home Dashboard')
@section('section', 'Home')
@section('heading', 'Dashboard')

@section('content')
    @php
        $hairStats = $hairDashboard['stats'] ?? [];
        $hairCatalogueId = $hairDashboard['catalogue_id'] ?? 1;

        $shortcutGroups = [
            [
                'title' => 'Retail & SKUs',
                'description' => 'Find families, add barcodes, photos and prices.',
                'items' => [
                    [
                        'label' => 'Start adding products',
                        'meta' => 'Hair extensions · sellable',
                        'href' => route('retail-products.index', ['source' => 'all', 'department' => 'Hair Extensions', 'per_page' => 100]),
                        'featured' => true,
                    ],
                    [
                        'label' => 'All sellable products',
                        'meta' => 'Every department',
                        'href' => route('retail-products.index', ['source' => 'all']),
                    ],
                    [
                        'label' => 'Barcode wizard',
                        'meta' => 'Scan & assign',
                        'href' => route('hair-extension-intake.wizard.index'),
                    ],
                ],
            ],
            [
                'title' => 'Catalogue & structure',
                'description' => 'Brands, styles, variants and publish to retail.',
                'items' => [
                    [
                        'label' => 'Hair catalogue',
                        'meta' => 'Structure & SKUs',
                        'href' => route('brand-catalogue.show', $hairCatalogueId),
                    ],
                    [
                        'label' => 'Brand catalogues',
                        'meta' => 'All departments',
                        'href' => route('brand-catalogue.index'),
                    ],
                    [
                        'label' => 'Body care catalogue',
                        'meta' => 'Body care only',
                        'href' => route('body-care-brand-catalogue'),
                    ],
                    [
                        'label' => 'Categories',
                        'meta' => 'Department tree',
                        'href' => route('categories.index'),
                    ],
                    [
                        'label' => 'Scaffold',
                        'meta' => 'Category builder',
                        'href' => route('categories.scaffold'),
                    ],
                    [
                        'label' => 'Store shelves',
                        'meta' => 'Locations',
                        'href' => route('inventory-structure.index'),
                    ],
                ],
            ],
            [
                'title' => 'Floor intake & capture',
                'description' => 'Observations from the shop floor and photo workflows.',
                'items' => [
                    [
                        'label' => 'Submitted intakes',
                        'meta' => number_format($hairStats['submitted_intakes'] ?? 0).' records',
                        'href' => route('hair-extension-intake.submitted'),
                    ],
                    [
                        'label' => 'New family intake',
                        'meta' => 'V2 form',
                        'href' => route('hair-extension-intake.v2'),
                    ],
                    [
                        'label' => 'Wizard drafts',
                        'meta' => number_format($hairStats['active_sessions'] ?? 0).' active',
                        'href' => route('hair-extension-intake.wizard.sessions'),
                    ],
                    [
                        'label' => 'Photo batches',
                        'meta' => 'Shop photos',
                        'href' => route('shop-photo-batches.index'),
                    ],
                    [
                        'label' => 'Shop product intake',
                        'meta' => 'Non-hair intake',
                        'href' => route('shop-product-intake.index'),
                    ],
                ],
            ],
            [
                'title' => 'Products & sources',
                'description' => 'Imported rows, pictures, matching and analysis.',
                'items' => [
                    [
                        'label' => 'Products',
                        'meta' => 'Observed rows',
                        'href' => route('products.index'),
                    ],
                    [
                        'label' => 'All sources',
                        'meta' => 'Source catalogue',
                        'href' => route('source-products.index'),
                    ],
                    [
                        'label' => 'Need match',
                        'meta' => 'Picture-only rows',
                        'href' => route('source-products.picture-only'),
                    ],
                    [
                        'label' => 'Pictures',
                        'meta' => 'Photo library',
                        'href' => route('pictures.index'),
                    ],
                    [
                        'label' => 'True products',
                        'meta' => 'PDF matches',
                        'href' => route('products.true-products'),
                    ],
                    [
                        'label' => 'Product analysis',
                        'meta' => 'Duplicates & stats',
                        'href' => route('products.analysis'),
                    ],
                ],
            ],
            [
                'title' => 'Other pipelines',
                'description' => 'Deliveroo, PDF catalogues and Mamado imports.',
                'items' => [
                    [
                        'label' => 'Deliveroo products',
                        'meta' => 'Official catalogue',
                        'href' => route('deliveroo-products.index'),
                    ],
                    [
                        'label' => 'PDF products',
                        'meta' => 'Catalogue staging',
                        'href' => route('pdf-products.index'),
                    ],
                    [
                        'label' => 'Mamado products',
                        'meta' => 'Mamado import',
                        'href' => route('mamado-products.index'),
                    ],
                    [
                        'label' => 'Shaba reference',
                        'meta' => 'Lookup',
                        'href' => route('reference.shaba.index'),
                    ],
                ],
            ],
            [
                'title' => 'Brands & output',
                'description' => 'Brand review, exports and settings.',
                'items' => [
                    [
                        'label' => 'Real brands',
                        'meta' => 'Canonical brands',
                        'href' => route('real-brands.index'),
                    ],
                    [
                        'label' => 'Brand review',
                        'meta' => 'Mapping queue',
                        'href' => route('brand-review.index'),
                    ],
                    [
                        'label' => 'Exports',
                        'meta' => 'Download data',
                        'href' => route('exports.index'),
                    ],
                    [
                        'label' => 'Invoice generator',
                        'meta' => 'Print invoices',
                        'href' => route('invoice-generator.create'),
                    ],
                    [
                        'label' => 'Watermark settings',
                        'meta' => 'Photo watermark',
                        'href' => route('settings.watermark.edit'),
                    ],
                    [
                        'label' => 'Photo processing',
                        'meta' => 'AI settings',
                        'href' => route('settings.photo-processing.edit'),
                    ],
                ],
            ],
        ];

        $statCards = [
            ['value' => $hairStats['submitted_intakes'] ?? 0, 'label' => 'Floor intakes'],
            ['value' => $hairStats['catalogue_brands'] ?? 0, 'label' => 'Catalogue brands'],
            ['value' => $hairStats['catalogue_styles'] ?? 0, 'label' => 'Styles'],
            ['value' => $hairStats['retail_families'] ?? 0, 'label' => 'Retail families'],
            ['value' => $hairStats['sellable_skus'] ?? 0, 'label' => 'Sellable SKUs'],
        ];
    @endphp

    <section class="lhc-dashboard" aria-labelledby="hair-dashboard-title">
        <header class="lhc-dashboard-hero">
            <div class="lhc-dashboard-hero-copy">
                <p class="lhc-dashboard-eyebrow">LHC dashboard</p>
                <h1 id="hair-dashboard-title">Shelf to SKU</h1>
                <p class="lhc-dashboard-lead">Your shortcut hub for catalogue, retail, intake and exports. Hair extensions: pick a product, find its family, then add barcode, photo and price.</p>
            </div>

            <a class="lhc-dashboard-cta" href="{{ route('retail-products.index', ['source' => 'all', 'department' => 'Hair Extensions', 'per_page' => 100]) }}">
                <span class="lhc-dashboard-cta-text">
                    <strong>Start adding products</strong>
                    <em>Hair extensions · sellable workspace</em>
                </span>
                <span class="lhc-dashboard-cta-arrow" aria-hidden="true">→</span>
            </a>

            <ol class="lhc-dashboard-flow" aria-label="Typical workflow">
                <li><span>1</span> Find</li>
                <li><span>2</span> SKU</li>
                <li><span>3</span> Photo</li>
                <li><span>4</span> Price</li>
            </ol>
        </header>

        <section class="lhc-dashboard-stats" aria-label="Hair extension status">
            @foreach ($statCards as $stat)
                <article class="lhc-dashboard-stat">
                    <strong>{{ number_format($stat['value']) }}</strong>
                    <span>{{ $stat['label'] }}</span>
                </article>
            @endforeach
        </section>

        @foreach ($shortcutGroups as $group)
            <section class="lhc-dashboard-group">
                <header class="lhc-dashboard-group-head">
                    <h2>{{ $group['title'] }}</h2>
                    <p>{{ $group['description'] }}</p>
                </header>
                <div class="lhc-dashboard-grid">
                    @foreach ($group['items'] as $item)
                        <a
                            href="{{ $item['href'] }}"
                            class="lhc-dashboard-tile {{ ! empty($item['featured']) ? 'is-featured' : '' }}"
                        >
                            <span class="lhc-dashboard-tile-label">{{ $item['label'] }}</span>
                            <span class="lhc-dashboard-tile-meta">{{ $item['meta'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

        <details class="lhc-dashboard-admin">
            <summary>Admin import tools</summary>
            <div class="lhc-dashboard-admin-body">
                <section class="page-head">
                    <div>
                        <p class="eyebrow">Step 1</p>
                        <h2>Import products from picture JSON</h2>
                        <p class="page-note">Paste one picture object, an array, or a <code>photos</code> wrapper. Limit: 10 products per picture.</p>
                    </div>
                    <div class="button-row">
                        <a href="{{ route('exports.index') }}" class="button">Open exports</a>
                    </div>
                </section>

                <section class="stats-grid">
                    <article class="card stat-card">
                        <p class="stat-label">product rows</p>
                        <p class="stat-value">{{ number_format($stats['product_rows']) }}</p>
                    </article>
                    <article class="card stat-card">
                        <p class="stat-label">pictures</p>
                        <p class="stat-value">{{ number_format($stats['pictures']) }}</p>
                    </article>
                    <article class="card stat-card">
                        <p class="stat-label">real brands</p>
                        <p class="stat-value">{{ number_format($stats['real_brands']) }}</p>
                    </article>
                    <article class="card stat-card">
                        <p class="stat-label">categories</p>
                        <p class="stat-value">{{ number_format($stats['categories']) }}</p>
                    </article>
                </section>

                <article class="card">
                    @if (session('payload_cleanup_notes'))
                        <div class="helper-block">
                            <p class="helper-title">Auto-cleaned JSON preview</p>
                            <p>The payload was cleaned before decoding.</p>
                            <ul>
                                @foreach (session('payload_cleanup_notes', []) as $note)
                                    <li>{{ $note }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('observed-products.store') }}" class="stack-form">
                        @csrf

                        <label>
                            <span>JSON payload</span>
                            <textarea name="json_payload" rows="14" placeholder='[{"picture_id":"picture001","products":[{"brand":"X-Pression","product_name":"Ultra Braid Stretched"}]}]'>{{ old('json_payload') }}</textarea>
                            <small>One picture object or many. Limit: 10 products per picture.</small>
                        </label>

                        @if (session('cleaned_json_preview'))
                            <details class="details-block" open>
                                <summary>Cleaned preview</summary>
                                <div class="details-content">
                                    <pre>{{ session('cleaned_json_preview') }}</pre>
                                </div>
                            </details>
                        @endif

                        <details class="details-block">
                            <summary>Expected JSON format</summary>
                            <div class="details-content">
<pre>{
  "picture_id": "picture001",
  "products": [
    {
      "brand": "X-Pression",
      "product_name": "Ultra Braid Stretched"
    }
  ]
}</pre>
                            </div>
                        </details>

                        <div class="button-row">
                            <button type="submit" class="button button-primary">Import product names</button>
                        </div>
                    </form>
                </article>

                <article class="card">
                    <div class="card-head">
                        <h3>Imported product rows</h3>
                        <p>{{ $observedProducts->total() }} saved rows</p>
                    </div>

                    <form method="GET" action="{{ route('dashboard') }}" class="stack-form">
                        <div class="form-grid">
                            <label class="grow">
                                <span>Search</span>
                                <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search picture id, brand, or product name">
                            </label>

                            <label>
                                <span>Real brand filter</span>
                                <select name="brand">
                                    <option value="">All real brands</option>
                                    @foreach ($brandOptions as $brand)
                                        <option value="{{ $brand === '' ? '__blank__' : $brand }}" @selected($filters['brand'] === ($brand === '' ? '__blank__' : $brand))>
                                            {{ $brand === '' ? '[blank brand]' : $brand }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <label>
                                <span>Category filter</span>
                                <select name="category">
                                    <option value="">All categories</option>
                                    @foreach ($categoryOptions as $category)
                                        <option value="{{ $category->slug }}" @selected($filters['category'] === $category->slug)>
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <div class="button-row">
                            <button type="submit" class="button button-primary">Search</button>
                            <a href="{{ route('dashboard') }}" class="button">Clear filters</a>
                        </div>
                    </form>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Row ID</th>
                                    <th>Picture</th>
                                    <th>Real brand</th>
                                    <th>Category</th>
                                    <th>Observed brand</th>
                                    <th>Line</th>
                                    <th>Product name</th>
                                    <th>Imported</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($observedProducts as $product)
                                    <tr>
                                        <td>{{ $product->id }}</td>
                                        <td>{{ $product->picture_id }}</td>
                                        <td>{{ $product->canonical_brand !== '' ? $product->canonical_brand : '[blank brand]' }}</td>
                                        <td>{{ $product->category?->name ?? 'Unassigned' }}</td>
                                        <td>{{ $product->brand !== '' ? $product->brand : '[blank brand]' }}</td>
                                        <td>{{ $product->brand_line ?: '-' }}</td>
                                        <td>{{ $product->product_name }}</td>
                                        <td>{{ $product->created_at?->format('Y-m-d H:i') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8">No product rows imported yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination-wrap">
                        {{ $observedProducts->links() }}
                    </div>
                </article>
            </div>
        </details>
    </section>
@endsection
