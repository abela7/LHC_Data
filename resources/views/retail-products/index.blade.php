@extends('layouts.app')

@section('title', 'Sellable Products')
@section('section', 'Retail Products')
@section('heading', 'Sellable')

@section('content')
    @php
        $hasFilters = $search !== '' || $brand !== '' || $department !== '' || $productType !== '' || $confidence !== '' || $source !== 'all';
        $sourceLabel = match ($source) {
            'all' => 'All sellable products',
            'mamado' => 'Mamado drafts',
            'picture' => 'Shop photo products',
            default => 'Janson drafts',
        };
    @endphp

    <section class="deliveroo-overview">
        <div class="deliveroo-hero-shell">
            <article class="deliveroo-hero">
                <div class="deliveroo-page-head">
                    <div class="deliveroo-page-head-copy">
                        <p class="eyebrow">Sellable Product Workbench</p>
                        <h2>{{ $sourceLabel }}</h2>
                        <p class="page-note">
                            Janson, Mamado, and shop-photo candidates grouped by family. Keep them inactive until shop presence, variants, barcode, stock, image, and retail price are checked.
                        </p>
                    </div>
                    <div class="button-row">
                        <a href="{{ route('retail-products.brands') }}" class="button button-primary">Combined brands</a>
                    </div>
                </div>

                <ul class="deliveroo-hero-metrics">
                    <li class="deliveroo-hero-metric deliveroo-hero-metric--primary">
                        <span class="deliveroo-hero-metric-label">SKU candidates</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['products']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Families</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['families']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Brands</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['brands']) }}</span>
                    </li>
                    <li class="deliveroo-hero-metric">
                        <span class="deliveroo-hero-metric-label">Review</span>
                        <span class="deliveroo-hero-metric-value">{{ number_format($stats['review']) }}</span>
                    </li>
                </ul>
            </article>
        </div>

        <section class="deliveroo-search-panel">
            <div class="deliveroo-search-panel-copy">
                <p class="eyebrow">Family Search</p>
                <h3>Find a sellable family</h3>
                <p class="page-note">Search by brand, family, SKU name, supplier note, SKU, or barcode.</p>
            </div>

            <form method="GET" action="{{ route('retail-products.index') }}" class="deliveroo-search-form">
                <label class="deliveroo-search-field">
                    <span class="sr-only">Search sellable products</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search sellable products..."
                        autocomplete="off"
                    >
                </label>

                <div class="deliveroo-all-products-filters">
                    <label class="deliveroo-all-products-select-label">
                        <span>Source</span>
                        <select name="source" class="deliveroo-all-products-select">
                            <option value="all" @selected($source === 'all')>All sellable products</option>
                            <option value="janson" @selected($source === 'janson')>Janson drafts</option>
                            <option value="mamado" @selected($source === 'mamado')>Mamado drafts</option>
                            <option value="picture" @selected($source === 'picture')>Shop photo products</option>
                        </select>
                    </label>

                    <label class="deliveroo-all-products-select-label">
                        <span>Brand</span>
                        <select name="brand" class="deliveroo-all-products-select">
                            <option value="">All brands</option>
                            @foreach ($brands as $brandRow)
                                <option value="{{ $brandRow->brand_name }}" @selected($brand === $brandRow->brand_name)>
                                    {{ $brandRow->brand_name }} ({{ number_format($brandRow->product_count) }})
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="deliveroo-all-products-select-label">
                        <span>Department</span>
                        <select name="department" class="deliveroo-all-products-select">
                            <option value="">All departments</option>
                            @foreach ($departments as $departmentRow)
                                <option value="{{ $departmentRow->department }}" @selected($department === $departmentRow->department)>
                                    {{ $departmentRow->department }} ({{ number_format($departmentRow->product_count) }})
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="deliveroo-all-products-select-label">
                        <span>Product Type</span>
                        <select name="product_type" class="deliveroo-all-products-select">
                            <option value="">All product types</option>
                            @foreach ($productTypes as $typeRow)
                                <option value="{{ $typeRow->product_type }}" @selected($productType === $typeRow->product_type)>
                                    {{ $typeRow->product_type }} ({{ number_format($typeRow->product_count) }})
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="deliveroo-all-products-select-label">
                        <span>Confidence</span>
                        <select name="confidence" class="deliveroo-all-products-select">
                            <option value="">All</option>
                            @foreach (['A', 'B', 'C', 'D'] as $grade)
                                <option value="{{ $grade }}" @selected($confidence === $grade)>{{ $grade }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="deliveroo-all-products-select-label">
                        <span>Per page</span>
                        <select name="per_page" class="deliveroo-all-products-select">
                            @foreach ($allowedPerPage as $option)
                                <option value="{{ $option }}" @selected($perPage === $option)>{{ number_format($option) }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Apply</button>
                    @if ($hasFilters)
                        <a href="{{ route('retail-products.index', ['per_page' => $perPage]) }}" class="button">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">{{ $sourceLabel }}</p>
                    <h3>{{ number_format($families->total()) }} famil{{ $families->total() === 1 ? 'y' : 'ies' }}</h3>
                    <p class="page-note">Page {{ $families->currentPage() }} of {{ $families->lastPage() }}.</p>
                </div>
            </div>

            @if ($families->isEmpty())
                <article class="card">
                    <div class="brand-empty-state py-12">
                        <h3>No sellable families found</h3>
                        <p class="page-note mt-2">Try another search or filter.</p>
                    </div>
                </article>
            @else
                <article class="card rp-families-card">
                    <div class="table-wrap rp-families-table-wrap">
                        <table class="rp-families-table">
                            <thead>
                                <tr>
                                    <th class="rp-families-col-num">#</th>
                                    <th>Brand</th>
                                    <th>Family</th>
                                    <th>Department</th>
                                    <th>Type</th>
                                    <th>Sources</th>
                                    <th>Variants</th>
                                    <th>SKUs</th>
                                    <th>Readiness</th>
                                    <th>Status</th>
                                    <th class="rp-families-col-open">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($families as $index => $family)
                                    @php
                                        $detailUrl = route('retail-products.families.show', $family->id);
                                        $skuCount = (int) $family->sku_count;
                                        $missingPrice = (int) $family->missing_price_count;
                                        $missingImage = (int) $family->missing_image_count;
                                        $reviewCount = (int) $family->review_count;
                                        $sourceBadges = collect(explode(',', (string) $family->source_types))
                                            ->map(fn (string $type): string => trim($type))
                                            ->filter()
                                            ->map(fn (string $type): string => match ($type) {
                                                'janson_product' => 'Janson',
                                                'mamado_product' => 'Mamado',
                                                'picture_product_confidence_a' => 'Photo A',
                                                'picture_product_draft' => 'Photo draft',
                                                default => Str::headline(str_replace('_', ' ', $type)),
                                            })
                                            ->unique()
                                            ->values();
                                        $variantSummary = trim((string) ($family->variant_summary ?? ''));
                                        $variantOptionCount = (int) ($family->variant_option_count ?? 0);
                                    @endphp
                                    <tr class="clickable-row rp-families-row" data-href="{{ $detailUrl }}">
                                        <td class="rp-families-num" data-label="#">{{ $families->firstItem() + $index }}</td>
                                        <td class="rp-families-brand" data-label="Brand">
                                            <strong>{{ $family->brand_name ?: 'Unknown' }}</strong>
                                            @if ($family->line_name)
                                                <div class="page-note">{{ $family->line_name }}</div>
                                            @endif
                                        </td>
                                        <td class="rp-families-family" data-label="Family">
                                            <span class="clickable-row-name">{{ $family->family_name }}</span>
                                        </td>
                                        <td class="rp-families-dept" data-label="Department">{{ $family->root_catalogue_name ?: '-' }}</td>
                                        <td class="rp-families-type" data-label="Type">{{ $family->product_type_name ?: '-' }}</td>
                                        <td class="rp-families-sources" data-label="Sources">
                                            <div class="rp-families-pill-row">
                                                @foreach ($sourceBadges as $sourceBadge)
                                                    <span class="pill">{{ $sourceBadge }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="rp-families-variants" data-label="Variants">
                                            @if ($variantSummary !== '')
                                                <span class="rp-families-variant-text" title="{{ $variantSummary }}">{{ Str::limit($variantSummary, 90) }}</span>
                                                <div class="page-note">{{ number_format($variantOptionCount) }} option{{ $variantOptionCount === 1 ? '' : 's' }}</div>
                                            @else
                                                <span class="pill pill-warn">Needs variants</span>
                                            @endif
                                        </td>
                                        <td class="rp-families-skus" data-label="SKUs">
                                            <span class="rp-families-skus-value">{{ number_format($skuCount) }}</span>
                                        </td>
                                        <td class="rp-families-readiness" data-label="Readiness">
                                            <div class="rp-families-pill-row">
                                                @if ($reviewCount > 0)
                                                    <span class="pill pill-warn">{{ number_format($reviewCount) }} review</span>
                                                @endif
                                                @if ($missingPrice > 0)
                                                    <span class="pill">{{ number_format($missingPrice) }} no price</span>
                                                @endif
                                                @if ($missingImage > 0)
                                                    <span class="pill">{{ number_format($missingImage) }} no image</span>
                                                @endif
                                                @if ($reviewCount === 0 && $missingPrice === 0 && $missingImage === 0)
                                                    <span class="pill pill-success">Ready check</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="rp-families-status" data-label="Status">
                                            <span class="pill">{{ Str::headline($family->status ?: 'draft') }}</span>
                                        </td>
                                        <td class="rp-families-open" data-label="Open">
                                            <a href="{{ $detailUrl }}" class="button button-primary clickable-row-stop">Open</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </article>

                <div class="pagination-wrap">
                    {{ $families->links() }}
                </div>
            @endif
        </section>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.clickable-row[data-href]').forEach(function (row) {
                row.addEventListener('click', function (event) {
                    if (event.target.closest('.clickable-row-stop')) return;

                    if (event.ctrlKey || event.metaKey) {
                        window.open(row.dataset.href, '_blank');
                    } else {
                        window.location.href = row.dataset.href;
                    }
                });
            });
        });
    </script>
@endsection
