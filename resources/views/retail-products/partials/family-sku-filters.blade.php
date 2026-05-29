@php
    use App\Support\VariantNaturalSort;

    $skuFilterVariantGroups = $family->variantGroups
        ->sortBy(fn ($group) => VariantNaturalSort::groupKey($group))
        ->map(function ($group) use ($products) {
            return [
                'id' => $group->id,
                'name' => $group->name,
                'options' => $group->options
                    ->sortBy(fn ($option) => VariantNaturalSort::valueKey($option->label))
                    ->map(function ($option) use ($products) {
                        $count = $products->filter(
                            fn ($product) => $product->variantValues->contains(
                                fn ($value) => (int) $value->product_variant_option_id === (int) $option->id,
                            ),
                        )->count();

                        return [
                            'id' => $option->id,
                            'label' => $option->label,
                            'count' => $count,
                        ];
                    })
                    ->values(),
            ];
        })
        ->values();

    $skuFilterStatus = [
        ['key' => 'active', 'label' => 'Active', 'count' => $products->where('status', 'active')->count()],
        ['key' => 'draft', 'label' => 'Draft', 'count' => $products->where('status', 'draft')->count()],
    ];

    $skuFilterChannels = [
        ['key' => 'pos-on', 'label' => 'POS on', 'count' => $products->where('is_pos_active', true)->count()],
        ['key' => 'pos-off', 'label' => 'POS off', 'count' => $products->where('is_pos_active', false)->count()],
        ['key' => 'web-on', 'label' => 'Website on', 'count' => $products->where('is_ecommerce_active', true)->count()],
        ['key' => 'web-off', 'label' => 'Website off', 'count' => $products->where('is_ecommerce_active', false)->count()],
        ['key' => 'stock-on', 'label' => 'Stock tracked', 'count' => $products->where('is_inventory_tracked', true)->count()],
        ['key' => 'stock-off', 'label' => 'Stock off', 'count' => $products->where('is_inventory_tracked', false)->count()],
    ];

    $hasBarcodeCount = $products->filter(fn ($product) => filled($product->barcode))->count();

    $familyBarcodeCounts = $products
        ->filter(fn ($product) => filled($product->barcode))
        ->groupBy(fn ($product) => strtolower(trim((string) $product->barcode)))
        ->map->count();

    $duplicateBarcodeCount = $products->filter(function ($product) use ($familyBarcodeCounts) {
        if (! filled($product->barcode)) {
            return false;
        }

        return ($familyBarcodeCounts[strtolower(trim((string) $product->barcode))] ?? 0) > 1;
    })->count();

    $skuFilterBarcode = [
        ['key' => 'has-barcode', 'label' => 'Has barcode', 'count' => $hasBarcodeCount],
        ['key' => 'needs-barcode', 'label' => 'Needs barcode', 'count' => $stats['missing_barcode'], 'tone' => 'warn'],
        ['key' => 'duplicate-barcode', 'label' => 'Duplicate barcode', 'count' => $duplicateBarcodeCount, 'tone' => 'danger'],
    ];

    $skuFilterQuality = [
        ['key' => 'needs-price', 'label' => 'Needs price', 'count' => $stats['missing_prices'], 'tone' => 'warn'],
        ['key' => 'needs-image', 'label' => 'No image', 'count' => $stats['missing_image'], 'tone' => 'warn'],
        ['key' => 'out-of-stock', 'label' => 'Out of stock', 'count' => $stats['out_of_stock'], 'tone' => 'danger'],
    ];
@endphp

<div class="rfm-sku-filters-wrap" data-rfm-filters-wrap>
    <details class="rfm-sku-filters" data-rfm-filters-panel>
        <summary class="rfm-sku-filters-toggle">
            <span class="rfm-sku-filters-toggle-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 6h16M7 12h10M10 18h4" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="rfm-sku-filters-toggle-text">
                <strong>Filter SKUs</strong>
                <em data-rfm-filter-summary>All {{ $stats['products'] }} SKUs</em>
            </span>
            <span class="rfm-sku-filters-badge" data-rfm-filter-badge hidden>0</span>
            <span class="rfm-sku-filters-chevron" aria-hidden="true">›</span>
        </summary>

        <div class="rfm-sku-filters-panel">
            @if ($skuFilterVariantGroups->isNotEmpty())
                <section class="rfm-sku-filter-section">
                    <header class="rfm-sku-filter-section-head">
                        <h3>Variant</h3>
                        <p>Combine axes — a SKU must match every axis you pick from.</p>
                    </header>
                    @foreach ($skuFilterVariantGroups as $variantGroup)
                        <div class="rfm-sku-filter-axis" data-rfm-filter-axis-wrap="{{ $variantGroup['id'] }}">
                            <span class="rfm-sku-filter-axis-label">{{ $variantGroup['name'] }}</span>
                            <div class="rfm-sku-filter-chips" role="group" aria-label="Filter by {{ $variantGroup['name'] }}">
                                @foreach ($variantGroup['options'] as $option)
                                    <button type="button"
                                            class="rfm-filter-chip"
                                            data-rfm-filter-variant="{{ $option['id'] }}"
                                            data-rfm-filter-axis="{{ $variantGroup['id'] }}"
                                            aria-pressed="false"
                                            @if ($option['count'] === 0) disabled @endif>
                                        <span>{{ $option['label'] }}</span>
                                        <em>{{ $option['count'] }}</em>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </section>
            @endif

            <section class="rfm-sku-filter-section">
                <header class="rfm-sku-filter-section-head">
                    <h3>Status</h3>
                    <p>Active or draft product records.</p>
                </header>
                <div class="rfm-sku-filter-chips" role="group" aria-label="Filter by status">
                    @foreach ($skuFilterStatus as $filter)
                        <button type="button"
                                class="rfm-filter-chip"
                                data-rfm-filter-status="{{ $filter['key'] }}"
                                aria-pressed="false"
                                @if ($filter['count'] === 0) disabled @endif>
                            <span>{{ $filter['label'] }}</span>
                            <em>{{ $filter['count'] }}</em>
                        </button>
                    @endforeach
                </div>
            </section>

            <section class="rfm-sku-filter-section">
                <header class="rfm-sku-filter-section-head">
                    <h3>Channels</h3>
                    <p>POS, website and inventory tracking.</p>
                </header>
                <div class="rfm-sku-filter-chips" role="group" aria-label="Filter by channel">
                    @foreach ($skuFilterChannels as $filter)
                        <button type="button"
                                class="rfm-filter-chip"
                                data-rfm-filter-channel="{{ $filter['key'] }}"
                                aria-pressed="false"
                                @if ($filter['count'] === 0) disabled @endif>
                            <span>{{ $filter['label'] }}</span>
                            <em>{{ $filter['count'] }}</em>
                        </button>
                    @endforeach
                </div>
            </section>

            <section class="rfm-sku-filter-section">
                <header class="rfm-sku-filter-section-head">
                    <h3>Barcode</h3>
                    <p>Has, missing, or the same code on more than one SKU in this family.</p>
                </header>
                <div class="rfm-sku-filter-chips" role="group" aria-label="Filter by barcode">
                    @foreach ($skuFilterBarcode as $filter)
                        <button type="button"
                                class="rfm-filter-chip @if (! empty($filter['tone'])) rfm-filter-chip-{{ $filter['tone'] }} @endif"
                                data-rfm-filter-barcode="{{ $filter['key'] }}"
                                aria-pressed="false"
                                @if ($filter['count'] === 0) disabled @endif>
                            <span>{{ $filter['label'] }}</span>
                            <em>{{ $filter['count'] }}</em>
                        </button>
                    @endforeach
                </div>
            </section>

            <section class="rfm-sku-filter-section">
                <header class="rfm-sku-filter-section-head">
                    <h3>Data gaps</h3>
                    <p>Missing price, image or stock.</p>
                </header>
                <div class="rfm-sku-filter-chips" role="group" aria-label="Filter by data gaps">
                    @foreach ($skuFilterQuality as $filter)
                        <button type="button"
                                class="rfm-filter-chip rfm-filter-chip-{{ $filter['tone'] }}"
                                data-rfm-filter-quality="{{ $filter['key'] }}"
                                aria-pressed="false"
                                @if ($filter['count'] === 0) disabled @endif>
                            <span>{{ $filter['label'] }}</span>
                            <em>{{ $filter['count'] }}</em>
                        </button>
                    @endforeach
                </div>
            </section>

            <footer class="rfm-sku-filters-foot">
                <button type="button" class="rfm-sku-filters-clear" data-rfm-filter-clear hidden>
                    Clear all filters
                </button>
            </footer>
        </div>
    </details>
</div>
