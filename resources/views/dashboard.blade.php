@extends('layouts.app')

@section('title', 'LHC Home Dashboard')
@section('section', 'Home')
@section('heading', 'Dashboard')

@section('content')
    @php
        $hairStats = $hairDashboard['stats'] ?? [];
        $hairCatalogueId = $hairDashboard['catalogue_id'] ?? 1;
        $hairPrimaryLinks = [
            [
                'label' => 'Start adding products',
                'text' => 'Find family',
                'href' => route('retail-products.index', ['source' => 'all', 'department' => 'Hair Extensions', 'per_page' => 100]),
                'tone' => 'primary',
            ],
            [
                'label' => 'Catalogue',
                'text' => 'Structure',
                'href' => route('brand-catalogue.show', $hairCatalogueId),
                'tone' => 'structure',
            ],
            [
                'label' => 'Floor intakes',
                'text' => 'Observed',
                'href' => route('hair-extension-intake.submitted'),
                'tone' => 'evidence',
            ],
        ];
        $hairSecondaryLinks = [
            ['label' => 'Add missing family', 'href' => route('hair-extension-intake.v2'), 'meta' => 'New'],
            ['label' => 'Wizard drafts', 'href' => route('hair-extension-intake.wizard.sessions'), 'meta' => 'Drafts'],
            ['label' => 'Barcode wizard', 'href' => route('hair-extension-intake.wizard.index'), 'meta' => 'Scan'],
            ['label' => 'Shelves', 'href' => route('inventory-structure.index'), 'meta' => 'Store'],
            ['label' => 'Body care catalogue', 'href' => route('body-care-brand-catalogue'), 'meta' => 'Body'],
            ['label' => 'Photo batches', 'href' => route('shop-photo-batches.index'), 'meta' => 'Photos'],
            ['label' => 'All products', 'href' => route('retail-products.index', ['source' => 'all']), 'meta' => 'Retail'],
        ];
    @endphp

    <style>
        .home-hair {
            display: grid;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .home-hair-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(20, 35, 31, .1);
            border-radius: 28px;
            background:
                radial-gradient(circle at 100% 0%, rgba(8, 116, 100, .2), transparent 28%),
                linear-gradient(135deg, #fffdf8 0%, #eaf3ef 100%);
            box-shadow: 0 18px 48px rgba(20, 35, 31, .08);
            padding: clamp(1rem, 4vw, 1.5rem);
        }

        .home-hair-hero h2 {
            margin: .25rem 0 0;
            font-size: clamp(2rem, 9vw, 4.4rem);
            line-height: .94;
            letter-spacing: -.06em;
            max-width: 10ch;
        }

        .home-hair-note {
            margin: .55rem 0 0;
            color: rgba(20, 35, 31, .62);
            font-size: .95rem;
            font-weight: 800;
        }

        .home-hair-hero-actions {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(0, .75fr);
            gap: .7rem;
            margin-top: 1rem;
        }

        .home-hair-start {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            min-height: 82px;
            border-radius: 22px;
            background: #087464;
            color: #fff;
            padding: 1rem;
            text-decoration: none;
            font-size: 1.2rem;
            font-weight: 950;
            letter-spacing: -.025em;
            box-shadow: 0 16px 34px rgba(8, 116, 100, .24);
        }

        .home-hair-start span:last-child {
            display: inline-grid;
            width: 42px;
            height: 42px;
            place-items: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, .18);
            font-size: 1.25rem;
        }

        .home-hair-steps {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .45rem;
        }

        .home-hair-step {
            border: 1px solid rgba(20, 35, 31, .1);
            border-radius: 16px;
            background: rgba(255, 255, 255, .72);
            padding: .65rem;
            font-size: .8rem;
            font-weight: 900;
            color: #14231f;
        }

        .home-hair-step span {
            display: inline;
            color: #087464;
            margin-right: .3rem;
        }

        .home-hair-stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: .75rem;
        }

        .home-hair-stat {
            border: 1px solid rgba(20, 35, 31, .1);
            border-radius: 22px;
            background: #fffdf8;
            padding: .95rem;
            box-shadow: 0 10px 28px rgba(20, 35, 31, .06);
        }

        .home-hair-stat strong {
            display: block;
            font-size: 1.7rem;
            line-height: 1;
            letter-spacing: -.04em;
        }

        .home-hair-stat span {
            display: block;
            margin-top: .35rem;
            color: rgba(20, 35, 31, .62);
            font-size: .72rem;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .home-hair-mini em {
            font-style: normal;
            font-size: .78rem;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .home-hair-mini-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .65rem;
        }

        .home-hair-mini {
            display: grid;
            gap: .75rem;
            align-content: space-between;
            min-height: 94px;
            border: 1px solid rgba(20, 35, 31, .1);
            border-radius: 20px;
            background: #fffdf8;
            color: #14231f;
            text-decoration: none;
            padding: .85rem;
            font-weight: 900;
            box-shadow: 0 10px 24px rgba(20, 35, 31, .055);
        }

        .home-hair-mini span {
            font-size: 1.02rem;
            line-height: 1.08;
            letter-spacing: -.02em;
        }

        .home-hair-mini em {
            color: rgba(20, 35, 31, .55);
            font-size: .68rem;
        }

        .home-admin-divider {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin: 1.75rem 0 1rem;
            color: rgba(20, 35, 31, .58);
            font-size: .78rem;
            font-weight: 900;
            letter-spacing: .1em;
            text-transform: uppercase;
        }

        .home-admin-divider::after {
            content: "";
            height: 1px;
            flex: 1;
            background: rgba(20, 35, 31, .12);
        }

        @media (max-width: 900px) {
            .home-hair-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .home-hair-mini-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .home-hair-hero {
                border-radius: 24px;
            }

            .home-hair-hero-actions {
                grid-template-columns: 1fr;
            }

            .home-hair-stats {
                gap: .55rem;
            }

            .home-hair-stat {
                padding: .85rem;
            }

            .home-hair-mini {
                min-height: 82px;
            }
        }
    </style>

    <section class="home-hair" aria-labelledby="hair-dashboard-title">
        <article class="home-hair-hero">
            <p class="eyebrow">Hair extension dashboard</p>
            <h2 id="hair-dashboard-title">Shelf to SKU.</h2>
            <p class="home-hair-note">Pick product. Find family. Add barcode, photo and price.</p>
            <div class="home-hair-hero-actions">
                <a class="home-hair-start" href="{{ $hairPrimaryLinks[0]['href'] }}">
                    <span>Start adding products</span>
                    <span aria-hidden="true">→</span>
                </a>
                <div class="home-hair-steps" aria-label="Hair extension workflow">
                    <div class="home-hair-step"><span>1</span>Find</div>
                    <div class="home-hair-step"><span>2</span>SKU</div>
                    <div class="home-hair-step"><span>3</span>Photo</div>
                    <div class="home-hair-step"><span>4</span>Price</div>
                </div>
            </div>
        </article>

        <section class="home-hair-stats" aria-label="Hair extension status">
            <article class="home-hair-stat">
                <strong>{{ number_format($hairStats['submitted_intakes'] ?? 0) }}</strong>
                <span>floor intakes</span>
            </article>
            <article class="home-hair-stat">
                <strong>{{ number_format($hairStats['catalogue_brands'] ?? 0) }}</strong>
                <span>catalogue brands</span>
            </article>
            <article class="home-hair-stat">
                <strong>{{ number_format($hairStats['catalogue_styles'] ?? 0) }}</strong>
                <span>styles</span>
            </article>
            <article class="home-hair-stat">
                <strong>{{ number_format($hairStats['retail_families'] ?? 0) }}</strong>
                <span>retail families</span>
            </article>
            <article class="home-hair-stat">
                <strong>{{ number_format($hairStats['sellable_skus'] ?? 0) }}</strong>
                <span>sellable SKUs</span>
            </article>
        </section>

        <section class="home-hair-mini-grid" aria-label="Hair extension quick links">
            @foreach (array_slice($hairPrimaryLinks, 1) as $link)
                <a class="home-hair-mini" href="{{ $link['href'] }}">
                    <span>{{ $link['label'] }}</span>
                    <em>{{ $link['text'] }}</em>
                </a>
            @endforeach
            @foreach ($hairSecondaryLinks as $link)
                <a class="home-hair-mini" href="{{ $link['href'] }}">
                    <span>{{ $link['label'] }}</span>
                    <em>{{ $link['meta'] }}</em>
                </a>
            @endforeach
        </section>
    </section>

    <div class="home-admin-divider">Admin import tools</div>

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
@endsection
