@extends('layouts.app')

@section('title', $family->display_family_name . ' - Final Product Records')
@section('section', 'Final Catalogue')
@section('heading', $family->display_family_name)

@php
    use App\Support\VariantNaturalSort;
    use Illuminate\Support\Str;

    $primaryFamilyMedia = $family->media->firstWhere('is_primary', true) ?? $family->media->first();
    $primaryFamilyImage = $primaryFamilyMedia?->displayUrl();
    $totalStock = $products->sum(fn ($p) => $p->inventoryLevels->sum('stock_quantity'));
    $stockValue = $products->sum(function ($p) {
        $qty = (float) $p->inventoryLevels->sum('stock_quantity');
        $price = (float) ($p->price?->retail_price ?? 0);
        return $qty * $price;
    });

    $familyOnline = $family->ecommerceProfile;
    $ecomPreviewTitle = $familyOnline?->online_title ?: $family->display_family_name;
    $ecomPreviewShort = $familyOnline?->short_description
        ?: ($family->description ? Str::limit($family->description, 200) : null);
    $ecomPreviewLong = $familyOnline?->long_description ?: $family->description;
    $ecomPreviewPrices = $products->map(fn ($p) => $p->price?->retail_price)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
    $ecomPreviewPriceMin = $ecomPreviewPrices->min();
    $ecomPreviewPriceMax = $ecomPreviewPrices->max();
    $ecomPreviewSharedPrice = $familySharedDetails['shared_retail_price'] ?? null;

    $ecomPreviewMediaItem = static function ($media, string $fallbackAlt, string $fallbackLabel = 'Image'): ?array {
        $url = $media?->displayUrl();
        if (! $url) {
            return null;
        }

        return [
            'url' => $url,
            'alt' => $media->alt_text ?: $fallbackAlt,
            'label' => ucfirst(str_replace('_', ' ', (string) ($media->image_role ?: $fallbackLabel))),
        ];
    };

    $ecomPreviewIsColourGroup = static function ($group): bool {
        $name = mb_strtolower((string) $group->name);
        $type = mb_strtolower((string) $group->variant_type);

        return str_contains($name, 'colour')
            || str_contains($name, 'color')
            || in_array($type, ['colour_name', 'colour_code'], true);
    };

    $ecomPreviewColourGroup = $family->variantGroups->first($ecomPreviewIsColourGroup);
    $ecomPreviewColourGroupId = $ecomPreviewColourGroup?->id;

    $ecomPreviewFamilyFallback = collect();
    foreach ($family->media as $media) {
        $item = $ecomPreviewMediaItem($media, $ecomPreviewTitle, 'Family');
        if ($item && ! $ecomPreviewFamilyFallback->contains('url', $item['url'])) {
            $ecomPreviewFamilyFallback->push($item);
        }
    }

    $ecomPreviewSkus = $products->map(function ($product) use ($family, $ecomPreviewMediaItem) {
        $labels = $product->variantValues
            ->sortBy(fn ($v) => sprintf('%s:%s', $v->group ? VariantNaturalSort::groupKey($v->group) : '9999', VariantNaturalSort::valueKey($v->option?->label)))
            ->map(fn ($v) => $v->option?->label)
            ->filter()
            ->values();
        $ecommerceTitle = trim((string) ($product->ecommerceProfile?->online_title ?? ''));
        $imageAltName = $ecommerceTitle !== '' ? $ecommerceTitle : $product->name;

        $mainMedia = $product->media->firstWhere('image_role', 'main')
            ?? $product->media->first(fn ($m) => $m->is_primary && $m->image_role !== 'variant');
        $variantMedia = $product->media->firstWhere('image_role', 'variant');
        $galleryMedia = $product->media
            ->where('image_role', 'gallery')
            ->sortBy('sort_order')
            ->values();

        $gallery = $galleryMedia->map(fn ($m) => $ecomPreviewMediaItem($m, $imageAltName, 'Gallery'))
            ->filter()
            ->values()
            ->all();

        $optionsByGroup = [];
        foreach ($product->variantValues as $value) {
            if ($value->product_variant_group_id) {
                $optionsByGroup[(int) $value->product_variant_group_id] = (int) $value->product_variant_option_id;
            }
        }

        return [
            'id' => $product->id,
            'ecommerceTitle' => $ecommerceTitle !== '' ? $ecommerceTitle : null,
            'internalName' => $product->name,
            'shortDescription' => $product->ecommerceProfile?->short_description
                ?: ($product->description ? Str::limit($product->description, 200) : null),
            'longDescription' => $product->ecommerceProfile?->long_description ?: $product->description,
            'price' => $product->price?->retail_price !== null ? (float) $product->price->retail_price : null,
            'optionIds' => collect($optionsByGroup)->values()->sort()->values()->all(),
            'optionsByGroup' => $optionsByGroup,
            'inStock' => $product->inventoryLevels->sum('stock_quantity') > 0,
            'media' => [
                'main' => $ecomPreviewMediaItem($mainMedia, $imageAltName, 'Main'),
                'variant' => $ecomPreviewMediaItem($variantMedia, $imageAltName, 'Variant'),
                'gallery' => $gallery,
            ],
        ];
    })->values()->all();

    $ecomPreviewSwatches = collect();
    if ($ecomPreviewColourGroup) {
        foreach ($ecomPreviewColourGroup->options as $option) {
            $representative = collect($ecomPreviewSkus)->first(
                fn (array $sku) => ($sku['optionsByGroup'][$ecomPreviewColourGroup->id] ?? null) === (int) $option->id,
            );
            $swatchUrl = $representative['media']['variant']['url'] ?? $representative['media']['main']['url'] ?? null;
            $ecomPreviewSwatches->push([
                'optionId' => (int) $option->id,
                'label' => $option->label,
                'swatchUrl' => $swatchUrl,
            ]);
        }
    }

    $ecomPreviewData = [
        'title' => $ecomPreviewTitle,
        'familyTitle' => $family->display_family_name,
        'titlePlaceholder' => 'Choose options to preview the ecommerce product title',
        'brand' => $family->brand_name,
        'line' => $family->line_name,
        'category' => $family->product_type_name ?: $family->root_catalogue_name,
        'shortDescription' => $ecomPreviewShort,
        'longDescription' => $ecomPreviewLong,
        'isPublished' => (bool) ($familyOnline?->is_published ?? false),
        'clickCollect' => $familyOnline?->click_and_collect_enabled !== false,
        'priceMin' => $ecomPreviewPriceMin,
        'priceMax' => $ecomPreviewPriceMax,
        'sharedPrice' => $ecomPreviewSharedPrice !== null ? (float) $ecomPreviewSharedPrice : null,
        'colourGroupId' => $ecomPreviewColourGroupId,
        'colourGroupName' => $ecomPreviewColourGroup?->name,
        'swatches' => $ecomPreviewSwatches->values()->all(),
        'familyFallback' => $ecomPreviewFamilyFallback->values()->all(),
        'variants' => $family->variantGroups
            ->reject(fn ($group) => $ecomPreviewColourGroupId && (int) $group->id === (int) $ecomPreviewColourGroupId)
            ->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->name,
                'options' => $group->options->map(fn ($option) => [
                    'id' => $option->id,
                    'label' => $option->label,
                ])->values()->all(),
            ])
            ->values()
            ->all(),
        'skus' => $ecomPreviewSkus,
    ];

    $variantGroupTypeLabels = collect($variantGroupTypeOptions ?? [])->pluck('label', 'value');
@endphp

@section('content')
    <section class="rfm-page"
             data-rfm-root
             data-rfm-display-name-url="{{ route('retail-products.families.display-name.update', $family) }}"
             data-rfm-variant-options-bulk-url="{{ route('retail-products.families.variant-options.bulk-store', $family) }}"
             data-rfm-variant-options-destroy-url-template="{{ route('retail-products.families.variant-options.destroy', [$family, 0]) }}"
             data-rfm-create-new-skus-url="{{ route('retail-products.families.variant-options.create-skus-for-new', $family) }}">
        <nav class="rfm-crumbs" aria-label="Breadcrumb">
            <a href="{{ route('brand-catalogue.index') }}">Catalogue</a>
            <span aria-hidden="true">›</span>
            @if ($family->catalogueStyle)
                <a href="{{ route('brand-catalogue.styles.show', [
                    $family->brand_catalogue_id,
                    $family->brand_catalogue_brand_id,
                    $family->brand_catalogue_line_id,
                    $family->brand_catalogue_product_type_id,
                    $family->catalogueStyle,
                ]) }}?catalogue=1">Catalogue style</a>
                <span aria-hidden="true">›</span>
            @endif
            <span class="rfm-crumbs-current" data-rfm-display-name-crumb>{{ $family->display_family_name }}</span>
        </nav>

        @if (($styleRetailFamilies ?? collect())->count() > 1)
            <nav class="rfm-style-families" aria-label="Retail families for this catalogue style">
                <span class="rfm-style-families-label">This style:</span>
                @foreach ($styleRetailFamilies as $styleFamily)
                    @php
                        $chipLabel = \App\Support\RetailStyleFamilyCatalogue::scopeLabel($styleFamily->catalogue_scope_key);
                    @endphp
                    <a href="{{ route('retail-products.families.show', $styleFamily) }}"
                       class="rfm-style-families-chip {{ (int) $styleFamily->id === (int) $family->id ? 'is-active' : '' }}">
                        {{ $chipLabel }}
                        <em>{{ $styleFamily->products_count }}</em>
                    </a>
                @endforeach
            </nav>
        @elseif (filled($family->catalogue_scope_key))
            <p class="rfm-style-scope-note">
                Split family bucket: <strong>{{ $styleRetailScopeLabel ?? '' }}</strong>.
                @if ($family->catalogueStyle)
                    <a href="{{ route('brand-catalogue.styles.show', [
                        $family->brand_catalogue_id,
                        $family->brand_catalogue_brand_id,
                        $family->brand_catalogue_line_id,
                        $family->brand_catalogue_product_type_id,
                        $family->catalogueStyle,
                    ]) }}?catalogue=1">View all families on style</a>
                @endif
            </p>
        @endif

        {{-- Compact sticky family hero --}}
        <header class="rfm-hero" data-rfm-hero>
            <div class="rfm-hero-main">
                <div class="rfm-hero-top">
                    <div class="rfm-hero-thumb">
                        @if ($primaryFamilyImage)
                            <button type="button"
                                    class="rfm-thumb-lightbox-trigger"
                                    data-picture-preview-trigger
                                    data-image-url="{{ $primaryFamilyImage }}"
                                    data-picture-id="{{ $family->family_name }}"
                                    @if ($primaryFamilyMedia)
                                        data-media-id="{{ $primaryFamilyMedia->id }}"
                                        data-image-delete-url="{{ route('retail-products.media.destroy', $primaryFamilyMedia) }}"
                                        data-image-replace-url="{{ route('retail-products.media.replace', $primaryFamilyMedia) }}"
                                        data-image-role="{{ $primaryFamilyMedia->image_role }}"
                                        data-image-usage="{{ $primaryFamilyMedia->usage_context }}"
                                        data-image-source-label="{{ $primaryFamilyMedia->source_label }}"
                                        data-image-notes="{{ $primaryFamilyMedia->notes }}"
                                    @endif
                                    aria-label="View family image full size">
                                <img src="{{ $primaryFamilyImage }}" alt="" loading="eager">
                            </button>
                        @else
                            <span aria-hidden="true">★</span>
                        @endif
                    </div>

                    <div class="rfm-hero-title-copy">
                        <div class="rfm-hero-title-row">
                            <p class="rfm-eyebrow">Family</p>
                            <span class="rfm-hero-status">{{ ucfirst($family->status) }}</span>
                        </div>
                        <div class="rfm-hero-title-edit">
                            <h1 data-rfm-display-name-heading>{{ $family->display_family_name }}</h1>
                            <button type="button"
                                    class="rfm-hero-title-edit-btn"
                                    data-rfm-display-name-open
                                    aria-label="{{ __('retail.family.display_name.edit') }}">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M12 20h9"/>
                                    <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                                </svg>
                            </button>
                        </div>
                        <p class="rfm-hero-meta">
                            <span class="rfm-hero-status rfm-hero-status-inline">{{ ucfirst($family->status) }}</span>
                            {{ $family->brand_name }}
                            @if ($family->root_catalogue_name)
                                · {{ $family->root_catalogue_name }}
                            @endif
                            @if ($family->product_type_name)
                                · {{ $family->product_type_name }}
                            @endif
                        </p>
                    </div>

                    <div class="rfm-hero-actions">
                        <button type="button"
                                class="rfm-hero-action-btn rfm-hero-action-btn--primary"
                                data-rfm-ecom-preview-open
                                aria-label="Preview how this family looks on the shop">
                            <span class="rfm-hero-action-label-mobile">Preview</span>
                            <span class="rfm-hero-action-label-desktop">Shop preview</span>
                        </button>
                        <a href="{{ route('retail-products.families.export', $family) }}"
                           class="rfm-hero-action-btn"
                           aria-label="Export this product family with SKUs and images">
                            Export
                        </a>
                    </div>
                </div>

                <div class="rfm-tag-row rfm-hero-tags-desktop">
                    <span class="rfm-tag rfm-tag-brand">{{ $family->brand_name }}</span>
                    @if ($family->line_name)
                        <span class="rfm-tag">{{ $family->line_name }}</span>
                    @endif
                    @if ($family->root_catalogue_name)
                        <span class="rfm-tag">{{ $family->root_catalogue_name }}</span>
                    @endif
                    @if ($family->product_type_name)
                        <span class="rfm-tag">{{ $family->product_type_name }}</span>
                    @endif
                </div>
            </div>

            <details class="rfm-hero-stats-collapse">
                <summary>
                    <span>Family stats</span>
                    <em>{{ number_format($stats['products']) }} SKUs · {{ number_format($totalStock) }} units · £{{ number_format($stockValue, 0) }}</em>
                </summary>
                <div class="rfm-hero-stats">
                <div>
                    <span>SKUs</span>
                    <strong>{{ number_format($stats['products']) }}</strong>
                </div>
                <div>
                    <span>Stock units</span>
                    <strong>{{ number_format($totalStock) }}</strong>
                </div>
                <div>
                    <span>Stock value</span>
                    <strong>£{{ number_format($stockValue, 0) }}</strong>
                </div>
                <div>
                    <span>Images</span>
                    <strong>{{ number_format($stats['images']) }}</strong>
                </div>
                </div>
            </details>

            @if ($family->categoryAssignments->isNotEmpty())
                <details class="rfm-scaffold-collapse">
                    <summary>
                        <span>Scaffold paths</span>
                        <em>{{ $family->categoryAssignments->count() }}</em>
                    </summary>
                    <div class="rfm-scaffold-list">
                        @foreach ($family->categoryAssignments as $assignment)
                            <div class="rfm-scaffold-item">
                                <span>{{ ucfirst($assignment->assignment_type) }}</span>
                                <strong>{{ $assignment->scaffold->name }}</strong>
                                <small>
                                    @if ($assignment->axis){{ $assignment->axis->name }}@else Root @endif
                                    @if ($assignment->node)
                                        › @if ($assignment->node->parent){{ $assignment->node->parent->name }} › @endif{{ $assignment->node->name }}
                                    @endif
                                </small>
                            </div>
                        @endforeach
                    </div>
                </details>
            @else
                <div class="rfm-warn">
                    <strong>No scaffold assignment.</strong>
                    <span>Map this family before exporting.</span>
                </div>
            @endif
        </header>

        {{-- Family workspace: shared card, nested panels (design unchanged inside) --}}
        <section class="rfm-family-hub" aria-label="Family workspace">
        <details class="rfm-section rfm-hub-panel" data-rfm-section="family-media">
            <summary>
                <div>
                    <p class="rfm-eyebrow">Family images</p>
                    <h2>Main, variant &amp; gallery</h2>
                </div>
                <em>{{ $family->media->count() }} image{{ $family->media->count() === 1 ? '' : 's' }}</em>
            </summary>
            <div class="rfm-section-body">
                @include('retail-products._media-manager', [
                    'managerLevel' => 'family',
                    'targetName' => $family->family_name,
                    'mediaCollection' => $family->media,
                    'storeRoute' => route('retail-products.families.media.store', $family),
                    'mobileCaptureTargetType' => 'retail_family',
                    'mobileCaptureTargetId' => $family->id,
                    'mediaRoles' => $mediaRoles,
                    'mediaUsageContexts' => $mediaUsageContexts,
                ])
            </div>
        </details>

        {{-- Variant model summary --}}
        <details class="rfm-section rfm-hub-panel" id="rfm-variant-model">
                <summary>
                    <div>
                        <p class="rfm-eyebrow">Variant model</p>
                        <h2>{{ $family->variantGroups->count() }} group{{ $family->variantGroups->count() === 1 ? '' : 's' }}</h2>
                    </div>
                    <em>{{ $stats['variant_options'] }} options</em>
                </summary>
                <div class="rfm-section-body">
                    <div class="rfm-variant-refresh">
                        @if ($family->variantGroups->isEmpty())
                            <div class="rfm-variant-refresh-empty" role="status">
                                <strong>No variant groups yet.</strong>
                                Add Length, Colour, Pack Count or another axis below, then add values and create sellable SKUs.
                            </div>
                        @elseif ($missingComboCount > 0)
                            <form method="POST"
                                  action="{{ route('retail-products.families.refresh-skus', $family) }}"
                                  data-rfm-refresh-skus-form
                                  data-rfm-missing-count="{{ $missingComboCount }}">
                                @csrf
                                <button type="submit" class="rfm-variant-refresh-btn">
                                    <span class="rfm-variant-refresh-btn-label">Refresh sellable products</span>
                                    <span class="rfm-variant-refresh-btn-count">{{ $missingComboCount }} missing</span>
                                </button>
                            </form>
                            <p class="rfm-variant-refresh-hint">
                                Creates draft sellable SKUs for every missing combination at once.
                            </p>
                        @else
                            <div class="rfm-variant-refresh-empty" role="status">
                                <strong>All combinations covered.</strong>
                                Every variant combination already has a sellable SKU.
                            </div>
                        @endif
                    </div>

                    <form method="POST"
                          action="{{ route('retail-products.families.variant-groups.store', $family) }}"
                          class="rfm-variant-group-form">
                        @csrf
                        <div class="rfm-variant-group-form-head">
                            <strong>Add variant group</strong>
                            <span>Use this for a new SKU axis like Length, Colour or Pack Count.</span>
                        </div>
                        <label class="rfm-shared-field">
                            <span class="rfm-shared-label">Group name</span>
                            <input type="text"
                                   name="name"
                                   value="{{ old('name') }}"
                                   placeholder="Length, Colour, Pack Count"
                                   maxlength="255"
                                   autocomplete="off">
                        </label>
                        <label class="rfm-shared-field">
                            <span class="rfm-shared-label">Group type</span>
                            <select name="variant_type" required data-rfm-group-type-select>
                                @foreach ($variantGroupTypeOptions as $typeOption)
                                    <option value="{{ $typeOption['value'] }}" @selected(old('variant_type', 'text') === $typeOption['value'])>
                                        {{ $typeOption['label'] }}
                                    </option>
                                @endforeach
                                <option value="__new" @selected(old('variant_type') === '__new')>+ Add new public type</option>
                            </select>
                        </label>
                        <label class="rfm-shared-field">
                            <span class="rfm-shared-label">Axis role</span>
                            <select name="axis_role" data-rfm-group-role-select>
                                <option value="" @selected(old('axis_role', '') === '')>Auto (guess)</option>
                                @foreach ($axisRoleOptions as $roleValue => $roleLabel)
                                    <option value="{{ $roleValue }}" @selected(old('axis_role') === $roleValue)>{{ $roleLabel }}</option>
                                @endforeach
                            </select>
                            <small><strong>Main</strong> = primary axis (e.g. Length). <strong>Sub-main</strong> = secondary (e.g. Colour). <strong>Common</strong> = same on every SKU (e.g. Pack 3x).</small>
                        </label>
                        <label class="rfm-shared-field rfm-variant-group-type-new" data-rfm-group-type-new @if(old('variant_type') !== '__new') hidden @endif>
                            <span class="rfm-shared-label">New public type</span>
                            <input type="text"
                                   name="new_variant_type_name"
                                   value="{{ old('new_variant_type_name') }}"
                                   placeholder="Texture, Finish, Cap size"
                                   maxlength="255"
                                   autocomplete="off">
                            <small>After saving, this type becomes available on every family product.</small>
                        </label>
                        <button type="submit" class="rfm-variant-group-add-btn">Add group</button>
                        @if ($products->isNotEmpty())
                            <p class="rfm-variant-group-note">
                                Existing SKUs are not changed. New values create new SKU combinations only when you choose them.
                            </p>
                        @endif
                    </form>

                    <div class="rfm-variant-list">
                        @foreach ($family->variantGroups as $group)
                            @php
                                $chipPlaceholder = match (true) {
                                    str_contains(mb_strtolower($group->name), 'colour')
                                        || str_contains(mb_strtolower($group->name), 'color') => 'T1B/50, DKPU, Light Pink…',
                                    str_contains(mb_strtolower($group->name), 'length') => '20", 46", 72"…',
                                    str_contains(mb_strtolower($group->name), 'pack') => '3X, 100g, 500g…',
                                    default => 'Value 1, Value 2…',
                                };
                                $groupUsedCount = $products
                                    ->filter(fn ($product) => $product->variantValues->contains('product_variant_group_id', $group->id))
                                    ->count();
                            @endphp
                            <article class="rfm-variant-item">
                                <header class="rfm-variant-item-head">
                                    <div>
                                        <h3>
                                            {{ $group->name }}
                                            <span class="rfm-variant-role-badge rfm-variant-role-badge--{{ $group->axis_role ?? 'auto' }}">{{ $group->roleLabel() ?? 'Auto' }}</span>
                                        </h3>
                                        <span>{{ $variantGroupTypeLabels[$group->variant_type] ?? str_replace(['_', '-'], ' ', $group->variant_type) }}</span>
                                        <form method="POST"
                                              action="{{ route('retail-products.families.variant-groups.role', ['family' => $family, 'variantGroup' => $group]) }}"
                                              class="rfm-variant-role-form"
                                              data-rfm-role-form>
                                            @csrf
                                            @method('PATCH')
                                            <label class="rfm-variant-role-label">
                                                <span>Role</span>
                                                <select name="axis_role" class="rfm-variant-role-select" data-rfm-role-select aria-label="Axis role for {{ $group->name }}">
                                                    <option value="" @selected(! $group->hasExplicitRole())>Auto</option>
                                                    @foreach ($axisRoleOptions as $roleValue => $roleLabel)
                                                        <option value="{{ $roleValue }}" @selected($group->axis_role === $roleValue)>{{ $roleLabel }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <noscript><button type="submit">Set</button></noscript>
                                        </form>
                                    </div>
                                    <form method="POST"
                                          action="{{ route('retail-products.families.variant-groups.destroy', ['family' => $family, 'variantGroup' => $group]) }}"
                                          onsubmit="return confirm(@js($groupUsedCount > 0
                                              ? 'Remove variant group '.$group->name.' and permanently delete '.$groupUsedCount.' sellable SKU'.($groupUsedCount === 1 ? '' : 's').' that use it? This cannot be undone.'
                                              : 'Remove variant group '.$group->name.'? Unused values under it will be removed too.'));">
                                        @csrf
                                        <button type="submit"
                                                class="rfm-variant-group-remove"
                                                title="{{ $groupUsedCount > 0
                                                    ? 'Remove '.$group->name.' and delete '.$groupUsedCount.' sellable SKU'.($groupUsedCount === 1 ? '' : 's')
                                                    : 'Remove unused group' }}">
                                            Remove
                                        </button>
                                    </form>
                                </header>
                                @if ($groupUsedCount > 0)
                                    <p class="rfm-variant-group-usage">
                                        Used by {{ $groupUsedCount }} sellable SKU{{ $groupUsedCount === 1 ? '' : 's' }}.
                                        Removing this group deletes those SKUs permanently.
                                    </p>
                                @endif
                                <div class="rfm-variant-chip-field"
                                     data-rfm-variant-chip-field
                                     data-group-id="{{ $group->id }}"
                                     data-group-name="{{ $group->name }}">
                                    <p class="rfm-variant-chip-intro">Type values — <strong>comma</strong> or <strong>Enter</strong> saves each one in the background.</p>
                                    <div class="rfm-variant-chip-box">
                                        @foreach ($group->options as $option)
                                            @php
                                                $sellable = $variantOptionSellable[$option->id] ?? ['missing' => 0, 'combo_total' => 0];
                                            @endphp
                                            <span class="rfm-vchip {{ $sellable['missing'] > 0 ? 'needs-sku' : 'is-ready' }}"
                                                  data-rfm-vchip
                                                  data-option-id="{{ $option->id }}"
                                                  data-group-id="{{ $group->id }}"
                                                  data-chip-label="{{ $option->label }}"
                                                  data-rfm-missing="{{ $sellable['missing'] }}"
                                                  @if ($sellable['missing'] === 0)
                                                      role="button"
                                                      tabindex="0"
                                                      aria-pressed="false"
                                                      title="Show sellable SKUs that use {{ $group->name }}: {{ $option->label }}"
                                                  @endif>
                                                <span class="rfm-vchip-label">{{ $option->label }}</span>
                                                @if ($sellable['missing'] > 0)
                                                    <span class="rfm-vchip-pending" title="Click to create sellable SKUs for this value">Pending</span>
                                                @else
                                                    <span class="rfm-vchip-ready" title="Show SKUs for this value">View SKUs</span>
                                                @endif
                                                <button type="button"
                                                        class="rfm-vchip-delete"
                                                        data-rfm-vchip-delete
                                                        aria-label="Remove {{ $option->label }} from {{ $group->name }}">
                                                    &times;
                                                </button>
                                            </span>
                                        @endforeach
                                        <input type="text"
                                               class="rfm-variant-chip-input"
                                               data-rfm-variant-chip-input
                                               placeholder="{{ $chipPlaceholder }}"
                                               maxlength="255"
                                               autocomplete="off"
                                               aria-label="Add {{ $group->name }} values">
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div class="rfm-variant-create-bar" data-rfm-variant-create-bar hidden>
                        <div class="rfm-variant-create-bar-copy">
                            <strong data-rfm-variant-create-title>New variant values ready</strong>
                            <p data-rfm-variant-create-summary>Creates one sellable per main variant (e.g. length), keeping common variants (bundle, pack) fixed and applying each new sub-variant (e.g. colour) you added.</p>
                        </div>
                        <button type="button"
                                class="rfm-variant-create-bar-btn"
                                data-rfm-create-new-skus
                                disabled>
                            Create sellable SKUs
                        </button>
                    </div>

                    <a href="#rfm-add-sku" class="rfm-variant-combo-link" data-rfm-open-target="#rfm-add-sku">
                        Create one SKU from selected variant combination
                    </a>
                </div>
            </details>

        <div class="rfm-hub-stack rfm-hub-tools" aria-label="Family tools">
            <details class="rfm-family-shared rfm-ai-naming-hub-panel rfm-hub-panel" data-rfm-shared-panel>
                <summary>
                    <div>
                        <span>AI product names</span>
                        <strong class="rfm-ai-naming-hub-status" data-rfm-ai-naming-page-status>Not generated</strong>
                    </div>
                    <em>Receipt, POS &amp; web titles for {{ number_format($stats['products']) }} SKU{{ $stats['products'] === 1 ? '' : 's' }}</em>
                </summary>
                <div class="rfm-ai-naming-hub-body">
                    <p class="rfm-ai-naming-hub-note">
                        Generate receipt, POS and ecommerce titles from catalogue data. Review suggestions before applying to sellables.
                    </p>
                    <div class="rfm-ai-naming-toolbar">
                        <button type="button"
                                class="rfm-ai-naming-btn"
                                data-rfm-ai-naming-generate
                                data-rfm-ai-naming-url="{{ route('retail-products.families.ai-naming.suggest', $family) }}"
                                data-rfm-ai-naming-apply-url="{{ route('retail-products.families.ai-naming.apply', $family) }}">
                            Generate AI names
                        </button>
                        <button type="button" class="rfm-secondary-btn" data-rfm-ai-naming-view-all disabled>Review suggestions</button>
                    </div>
                </div>
            </details>

            @if ($family->variantGroups->isNotEmpty())
                <details class="rfm-family-shared rfm-variant-pricing rfm-hub-panel" data-rfm-variant-pricing data-rfm-shared-panel>
                    <summary>
                        <div>
                            <span>Variant pricing</span>
                            <strong>Price only matching SKUs</strong>
                        </div>
                    </summary>

                    <form method="POST" action="{{ route('retail-products.families.variant-pricing.update', $family) }}" class="rfm-family-shared-form">
                        @csrf
                        @method('PATCH')

                        <div class="rfm-variant-pricing-picker" data-rfm-variant-pricing-picker>
                            <p class="rfm-variant-pricing-intro">Pick one or more values per axis. Use <strong>All</strong> on Colour to set cost for every colour at once. Leave an axis empty for <em>Any</em> (all values on that axis).</p>
                            @foreach ($family->variantGroups as $group)
                                <div class="rfm-variant-pricing-axis"
                                     data-rfm-variant-pricing-axis
                                     data-group-id="{{ $group->id }}"
                                     data-group-name="{{ $group->name }}">
                                    <div class="rfm-variant-pricing-axis-head">
                                        <span class="rfm-shared-label">{{ $group->name }}</span>
                                        <div class="rfm-variant-pricing-axis-actions">
                                            <button type="button" class="rfm-variant-pricing-axis-btn" data-rfm-variant-pricing-select-all>All</button>
                                            <button type="button" class="rfm-variant-pricing-axis-btn" data-rfm-variant-pricing-clear-axis>Any</button>
                                        </div>
                                    </div>
                                    <div class="rfm-variant-pricing-options" role="group" aria-label="{{ $group->name }} values">
                                        @foreach ($group->options as $option)
                                            <label class="rfm-variant-pricing-chip">
                                                <input type="checkbox"
                                                       name="variant_options[{{ $group->id }}][]"
                                                       value="{{ $option->id }}"
                                                       data-rfm-variant-option-check>
                                                <span>{{ $option->label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="rfm-variant-price-fields">
                            <label class="rfm-shared-field rfm-variant-price-field">
                                <span class="rfm-shared-apply"><input type="checkbox" name="apply_retail_price" value="1" checked> Apply</span>
                                <span class="rfm-shared-label">&pound; Retail</span>
                                <input type="number" name="retail_price" step="0.01" min="0" inputmode="decimal" placeholder="0.00">
                            </label>
                            <label class="rfm-shared-field rfm-variant-price-field">
                                <span class="rfm-shared-apply"><input type="checkbox" name="apply_cost_price" value="1"> Apply</span>
                                <span class="rfm-shared-label">&pound; Cost</span>
                                <input type="number" name="cost_price" step="0.01" min="0" inputmode="decimal" placeholder="0.00">
                            </label>
                            <label class="rfm-shared-field rfm-variant-price-field">
                                <span class="rfm-shared-apply"><input type="checkbox" name="apply_vat_rate" value="1"> Apply</span>
                                <span class="rfm-shared-label">VAT %</span>
                                <input type="number" name="vat_rate" step="0.01" min="0" max="100" inputmode="decimal" placeholder="20">
                            </label>
                        </div>

                        <div class="rfm-family-shared-actions">
                            <span data-rfm-variant-price-count>Tick one or more variant values (or All on an axis), then tick Apply on the price fields you want to update.</span>
                            <button type="submit" data-rfm-variant-price-submit disabled>Apply price to matching SKUs</button>
                        </div>
                    </form>
                </details>
            @endif

            <details class="rfm-family-shared rfm-ai-naming-review-panel rfm-hub-panel" data-rfm-ai-naming-review hidden>
                <summary>
                    <div>
                        <span>AI naming review</span>
                        <strong data-rfm-ai-naming-summary>Suggestions ready</strong>
                    </div>
                    <em>Review all suggestions, edit names, then apply all or selected rows.</em>
                </summary>

                <form class="rfm-ai-naming-review" data-rfm-ai-naming-form>
                    @csrf
                    @method('PATCH')
                    <div class="rfm-ai-naming-table" data-rfm-ai-naming-table hidden>
                        <div class="rfm-ai-naming-row rfm-ai-naming-row-head" aria-hidden="true">
                            <span>Use</span>
                            <span>Product</span>
                            <span>Receipt</span>
                            <span>POS / inventory</span>
                            <span>Ecommerce</span>
                            <span>Confidence</span>
                        </div>
                        <div data-rfm-ai-naming-rows></div>
                    </div>

                    <div class="rfm-ai-naming-empty" data-rfm-ai-naming-empty>
                        Generate AI names to review all suggestions here.
                    </div>

                    <div class="rfm-ai-naming-actions">
                        <span data-rfm-ai-naming-status>Ready.</span>
                        <button type="button" class="rfm-secondary-btn" data-rfm-ai-naming-select-all disabled>Select all</button>
                        <button type="button" class="rfm-secondary-btn" data-rfm-ai-naming-clear disabled>Clear</button>
                        <button type="button" class="rfm-secondary-btn" data-rfm-ai-naming-apply-all disabled>Apply all</button>
                        <button type="submit" class="rfm-save-btn" data-rfm-ai-naming-apply disabled>Apply selected names</button>
                    </div>
                </form>
            </details>
        </div>

        {{-- SKU manager: search + filter + list --}}
        @php
            $skuToolsHint = collect([
                $stats['missing_barcode'] > 0 ? $stats['missing_barcode'].' need barcode' : null,
                $stats['missing_prices'] > 0 ? $stats['missing_prices'].' need price' : null,
                $stats['missing_image'] > 0 ? $stats['missing_image'].' need image' : null,
            ])->filter()->take(2)->implode(' · ');
            if ($skuToolsHint === '') {
                $skuToolsHint = 'Search by name, SKU or barcode';
            }
        @endphp
        <div class="rfm-hub-stack rfm-skus" aria-labelledby="rfm-skus-heading">
            <details class="rfm-family-shared rfm-skus-panel rfm-hub-panel">
                <summary>
                    <div>
                        <span id="rfm-skus-heading">Sellable SKUs</span>
                        <strong>{{ $stats['products'] }}</strong>
                    </div>
                    <em>{{ $skuToolsHint }}</em>
                </summary>
                <div class="rfm-skus-panel-body">
                    <label class="rfm-skus-search">
                        <span class="rfm-skus-search-label">Search</span>
                        <input type="search"
                            placeholder="Name, SKU, barcode, shelf…"
                            data-rfm-search
                            autocomplete="off">
                    </label>
                </div>
            </details>

            <details class="rfm-family-shared rfm-hub-panel" data-rfm-shared-panel>
                <summary>
                    <div>
                        <span>Family shared details</span>
                        <strong>
                            @if ($familySharedDetails['retail_price'])
                                Shared price &pound;{{ number_format((float) $familySharedDetails['retail_price'], 2) }}
                            @elseif ($familySharedDetails['priced_count'] === 0)
                                No SKU prices set yet
                            @elseif ($familySharedDetails['priced_count'] < $stats['products'])
                                {{ $familySharedDetails['priced_count'] }} / {{ $stats['products'] }} SKUs priced
                            @else
                                Mixed SKU prices
                            @endif
                        </strong>
                    </div>
                </summary>

                <form method="POST" action="{{ route('retail-products.families.shared-details.update', $family) }}" class="rfm-family-shared-form">
                    @csrf
                    @method('PATCH')

                    <div class="rfm-family-shared-grid">
                        <label class="rfm-shared-field">
                            <span class="rfm-shared-apply"><input type="checkbox" name="apply_department" value="1" @checked(filled($family->root_catalogue_name))> Save</span>
                            <span class="rfm-shared-label">Department</span>
                            <input type="text" name="department" value="{{ $family->root_catalogue_name }}" list="rfm-department-options" placeholder="Skin Care">
                        </label>
                        <label class="rfm-shared-field">
                            <span class="rfm-shared-apply"><input type="checkbox" name="apply_product_type" value="1" @checked(filled($family->product_type_name))> Save</span>
                            <span class="rfm-shared-label">Product Type</span>
                            <input type="text" name="product_type" value="{{ $family->product_type_name }}" list="rfm-product-type-options" placeholder="Body Lotion">
                        </label>
                    </div>

                    <datalist id="rfm-department-options">
                        @foreach ($departmentOptions as $option)
                            <option value="{{ $option }}"></option>
                        @endforeach
                    </datalist>
                    <datalist id="rfm-product-type-options">
                        @foreach ($productTypeOptions as $option)
                            <option value="{{ $option }}"></option>
                        @endforeach
                    </datalist>

                    <div class="rfm-family-channel-bulk" aria-label="Bulk family product availability">
                        <label class="rfm-shared-field rfm-channel-card">
                            <span class="rfm-shared-apply"><input type="checkbox" name="apply_is_pos_active" value="1" checked> Apply to all SKUs</span>
                            <span class="rfm-shared-label">POS</span>
                            <strong>{{ $stats['pos_active'] }} / {{ $stats['products'] }} on</strong>
                            <select name="is_pos_active" aria-label="Set POS for all family products">
                                <option value="1" @selected($stats['pos_active'] === $stats['products'] && $stats['products'] > 0)>Turn POS on</option>
                                <option value="0" @selected($stats['pos_active'] !== $stats['products'])>Turn POS off</option>
                            </select>
                        </label>
                        <label class="rfm-shared-field rfm-channel-card">
                            <span class="rfm-shared-apply"><input type="checkbox" name="apply_is_ecommerce_active" value="1" checked> Apply to all SKUs</span>
                            <span class="rfm-shared-label">Ecommerce</span>
                            <strong>{{ $stats['ecommerce_active'] }} / {{ $stats['products'] }} on</strong>
                            <select name="is_ecommerce_active" aria-label="Set Ecommerce for all family products">
                                <option value="1" @selected($stats['ecommerce_active'] === $stats['products'] && $stats['products'] > 0)>Turn Ecommerce on</option>
                                <option value="0" @selected($stats['ecommerce_active'] !== $stats['products'])>Turn Ecommerce off</option>
                            </select>
                        </label>
                        <label class="rfm-shared-field rfm-channel-card">
                            <span class="rfm-shared-apply"><input type="checkbox" name="apply_is_inventory_tracked" value="1" checked> Apply to all SKUs</span>
                            <span class="rfm-shared-label">Inventory</span>
                            <strong>{{ $stats['tracked_inventory'] }} / {{ $stats['products'] }} tracked</strong>
                            <select name="is_inventory_tracked" aria-label="Set Inventory tracking for all family products">
                                <option value="1" @selected($stats['tracked_inventory'] === $stats['products'] && $stats['products'] > 0)>Track inventory</option>
                                <option value="0" @selected($stats['tracked_inventory'] !== $stats['products'])>Do not track</option>
                            </select>
                        </label>
                    </div>

                    <div class="rfm-shared-field rfm-shared-description"
                         data-rfm-ai-description
                         data-rfm-ai-description-url="{{ route('retail-products.families.ai-description.suggest', $family) }}">
                        <div class="rfm-ai-description-head">
                            <span class="rfm-shared-apply">
                                <input type="checkbox" name="apply_description" value="1" @checked(filled($familySharedDetails['description']))>
                                Apply shared description to all SKUs
                            </span>
                            <button type="button"
                                    class="rfm-ai-description-btn"
                                    data-rfm-ai-description-generate
                                    title="Use Gemini + web search to write a clean customer-facing description from the brand's own page.">
                                <span class="rfm-ai-description-btn-label">Generate with AI</span>
                            </button>
                        </div>
                        <span class="rfm-shared-label">Ecommerce description</span>
                        <textarea name="description"
                                  rows="5"
                                  placeholder="Describe the product for customers: texture, finish, use, and key benefits."
                                  data-rfm-ai-description-textarea>{{ $familySharedDetails['description'] }}</textarea>
                        <small>This text appears on ecommerce product pages. Keep it clear, useful, and customer-facing.</small>

                        {{-- AI status + sources panel (hidden until a generation runs) --}}
                        <div class="rfm-ai-description-feedback" data-rfm-ai-description-feedback hidden>
                            <div class="rfm-ai-description-meta">
                                <span class="rfm-ai-description-confidence" data-rfm-ai-description-confidence></span>
                                <span class="rfm-ai-description-status" data-rfm-ai-description-status></span>
                            </div>
                            <ul class="rfm-ai-description-sources" data-rfm-ai-description-sources hidden></ul>
                        </div>
                    </div>

                    <div class="rfm-family-shared-grid">
                        <label class="rfm-shared-field">
                            <span class="rfm-shared-apply"><input type="checkbox" name="apply_retail_price" value="1" @checked(filled($familySharedDetails['retail_price']))> Apply</span>
                            <span class="rfm-shared-label">&pound; Retail</span>
                            <input type="number" name="retail_price" step="0.01" min="0" inputmode="decimal" value="{{ $familySharedDetails['retail_price'] }}" placeholder="0.00">
                        </label>
                        <label class="rfm-shared-field">
                            <span class="rfm-shared-apply"><input type="checkbox" name="apply_cost_price" value="1" @checked(filled($familySharedDetails['cost_price']))> Apply</span>
                            <span class="rfm-shared-label">&pound; Cost</span>
                            <input type="number" name="cost_price" step="0.01" min="0" inputmode="decimal" value="{{ $familySharedDetails['cost_price'] }}" placeholder="0.00">
                        </label>
                        <label class="rfm-shared-field">
                            <span class="rfm-shared-apply"><input type="checkbox" name="apply_vat_rate" value="1" @checked(filled($familySharedDetails['vat_rate']))> Apply</span>
                            <span class="rfm-shared-label">VAT %</span>
                            <input type="number" name="vat_rate" step="0.01" min="0" max="100" inputmode="decimal" value="{{ $familySharedDetails['vat_rate'] }}" placeholder="20">
                        </label>
                        <label class="rfm-shared-field">
                            <span class="rfm-shared-apply"><input type="checkbox" name="apply_stock_quantity" value="1" @checked(filled($familySharedDetails['stock_quantity']))> Apply</span>
                            <span class="rfm-shared-label">Stock</span>
                            <input type="number" name="stock_quantity" step="1" inputmode="numeric" value="{{ $familySharedDetails['stock_quantity'] }}" placeholder="0">
                        </label>
                        {{-- Store / location picker --}}
                        <div class="rfm-shared-field rfm-location-field"
                             data-rfm-location-field
                             data-all-sections="{{ json_encode($inventoryLocations->mapWithKeys(fn ($loc) => [$loc->id => $loc->sections->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()])) }}">
                            <div class="rfm-location-apply-row">
                                <span class="rfm-shared-apply">
                                    <input type="checkbox" name="apply_inventory_location" value="1"
                                           id="rfm-apply-location"
                                           data-rfm-apply-location
                                           @checked(old('apply_inventory_location', $familySharedDetails['inventory_location_id'] ? '1' : null))>
                                    Apply to all SKUs
                                </span>
                                <span class="rfm-shared-label">Store &amp; Section</span>
                            </div>
                            <select name="inventory_location_id" data-rfm-location-select>
                                <option value="">
                                    @if ($familySharedDetails['inventory_location_id'])
                                        — Change store —
                                    @else
                                        Pick a store
                                    @endif
                                </option>
                                @foreach ($inventoryLocations as $loc)
                                    <option value="{{ $loc->id }}"
                                        @selected(
                                            (string) old('inventory_location_id', $familySharedDetails['inventory_location_id']) === (string) $loc->id
                                        )>
                                        {{ $loc->name }}
                                    </option>
                                @endforeach
                            </select>
                            <select name="inventory_section_id" data-rfm-section-select>
                                <option value="">No section</option>
                                @php
                                    $currentLocId = old('inventory_location_id', $familySharedDetails['inventory_location_id']);
                                    $currentSecId = old('inventory_section_id',  $familySharedDetails['inventory_section_id']);
                                    $currentSections = $currentLocId
                                        ? ($inventoryLocations->firstWhere('id', (int) $currentLocId)?->sections ?? collect())
                                        : collect();
                                @endphp
                                @foreach ($currentSections as $sec)
                                    <option value="{{ $sec->id }}" @selected((string) $currentSecId === (string) $sec->id)>
                                        {{ $sec->name }}
                                    </option>
                                @endforeach
                            </select>
                            @if ($familySharedDetails['inventory_location_id'])
                                @php
                                    $currentLoc = $inventoryLocations->firstWhere('id', $familySharedDetails['inventory_location_id']);
                                    $currentSec = $currentLoc?->sections->firstWhere('id', $familySharedDetails['inventory_section_id']);
                                @endphp
                                <small>
                                    All SKUs are on:
                                    <strong>{{ $currentLoc?->name ?? '—' }}</strong>
                                    @if ($currentSec) / <strong>{{ $currentSec->name }}</strong> @endif
                                </small>
                            @else
                                <small>No store assigned yet for any SKU in this family.</small>
                            @endif
                        </div>

                        <label class="rfm-shared-field">
                            <span class="rfm-shared-apply"><input type="checkbox" name="apply_shelf_location" value="1" @checked(filled($familySharedDetails['shelf_location']))> Apply</span>
                            <span class="rfm-shared-label">Shelf / row</span>
                            <input type="text" name="shelf_location" value="{{ $familySharedDetails['shelf_location'] }}" placeholder="e.g. A3, top shelf">
                        </label>
                        <label class="rfm-shared-field">
                            <span class="rfm-shared-apply"><input type="checkbox" name="apply_supplier" value="1" @checked(filled($familySharedDetails['supplier']))> Apply</span>
                            <span class="rfm-shared-label">Supplier</span>
                            <input type="text" name="supplier" value="{{ $familySharedDetails['supplier'] }}">
                        </label>
                        <label class="rfm-shared-field">
                            <span class="rfm-shared-apply"><input type="checkbox" name="apply_supplier_product_code" value="1" @checked(filled($familySharedDetails['supplier_product_code']))> Apply</span>
                            <span class="rfm-shared-label">Supplier code</span>
                            <input type="text" name="supplier_product_code" value="{{ $familySharedDetails['supplier_product_code'] }}">
                        </label>
                    </div>

                    <div class="rfm-family-shared-actions">
                        <span>Apply boxes stay on by default so new sellables inherit shared price, channels and stock settings when you save. Untick any field you do not want to push to all SKUs.</span>
                        <button type="submit">Save selected values</button>
                    </div>
                </form>
            </details>

            @if (false)
            <form method="POST" action="{{ route('retail-products.families.prices.update', $family) }}" class="rfm-family-price">
                @csrf
                @method('PATCH')
                <div class="rfm-family-price-copy">
                    <span>Family retail price</span>
                    <strong>
                        @if ($familyPriceSummary['shared_retail_price'])
                            All SKUs use £{{ number_format((float) $familyPriceSummary['shared_retail_price'], 2) }}
                        @elseif ($familyPriceSummary['priced_count'] === 0)
                            No SKU prices set yet
                        @elseif ($familyPriceSummary['priced_count'] < $stats['products'])
                            {{ $familyPriceSummary['priced_count'] }} / {{ $stats['products'] }} SKUs priced
                        @else
                            Mixed SKU prices
                        @endif
                    </strong>
                </div>
                <label class="rfm-family-price-field">
                    <span>£</span>
                    <input type="number"
                           name="retail_price"
                           step="0.01"
                           min="0"
                           inputmode="decimal"
                           value="{{ $familyPriceSummary['shared_retail_price'] }}"
                           placeholder="0.00"
                           required>
                </label>
                <button type="submit" class="rfm-family-price-btn">Apply to all SKUs</button>
            </form>
            @endif


        </div>
        </section>

        <section class="rfm-skus-workspace" id="rfm-skus-workspace" aria-labelledby="rfm-skus-workspace-heading">
            <header class="rfm-skus-workspace-head">
                <div>
                    <p class="rfm-eyebrow">Sellable products</p>
                    <h2 id="rfm-skus-workspace-heading">SKU list</h2>
                </div>
                <em>{{ number_format($stats['products']) }} SKU{{ $stats['products'] === 1 ? '' : 's' }}</em>
            </header>

            <div class="rfm-skus-workspace-body">

            @include('retail-products.partials.family-sku-filters', [
                'family' => $family,
                'products' => $products,
                'stats' => $stats,
            ])

            <p class="rfm-empty-state" data-rfm-empty hidden>No SKUs match this filter.</p>

            @php
                $groupingGroup = $skuGrouping['grouping_group'];
                $sharedGroupIds = $skuGrouping['shared_group_ids'];
                $familyCommonLabels = $skuGrouping['family_common_labels'];
                $skuGroups = $skuGrouping['sku_groups'];
                $useSkuAccordions = $skuGrouping['use_accordions'];
                $familyBarcodeCounts = $products
                    ->filter(fn ($product) => filled($product->barcode))
                    ->groupBy(fn ($product) => strtolower(trim((string) $product->barcode)))
                    ->map->count();
            @endphp

            @if ($useSkuAccordions && $skuGroups->count() > 1)
                <div class="rfm-sku-groups-toolbar" data-rfm-sku-groups-toolbar>
                    <button type="button" class="rfm-sku-groups-toggle" data-rfm-sku-groups-expand-all>
                        Expand all
                    </button>
                    <button type="button" class="rfm-sku-groups-toggle" data-rfm-sku-groups-collapse-all>
                        Collapse all
                    </button>
                    <form method="POST"
                          action="{{ route('retail-products.families.split-families', $family) }}"
                          class="rfm-sku-groups-split-all"
                          onsubmit="return confirm(@js('Create '.$skuGroups->count().' separate families (one per group)? Each keeps the same brand and style; SKUs move out of this family. This cannot be undone.'));">
                        @csrf
                        <button type="submit" class="rfm-sku-groups-toggle rfm-sku-groups-split-all-btn">
                            Split into families
                        </button>
                    </form>
                </div>
            @endif

            <div class="rfm-sku-groups" data-rfm-sku-groups>
                @foreach ($skuGroups as $skuGroup)
                    @if ($useSkuAccordions)
                        <details class="rfm-sku-group"
                                 data-rfm-sku-group
                                 @if (! empty($skuGroup['option_id']))
                                     data-rfm-sku-group-option-id="{{ $skuGroup['option_id'] }}"
                                 @endif>
                            <summary class="rfm-sku-group-summary">
                                <div class="rfm-sku-group-leading">
                                    <span class="rfm-sku-group-axis">{{ $groupingGroup->name }}</span>
                                    <strong class="rfm-sku-group-value">{{ $skuGroup['label'] }}</strong>
                                </div>
                                <div class="rfm-sku-group-meta">
                                    @if ($familyCommonLabels->isNotEmpty())
                                        <span class="rfm-sku-group-common">{{ $familyCommonLabels->implode(' · ') }}</span>
                                    @endif
                                    <em class="rfm-sku-group-count">{{ $skuGroup['products']->count() }} SKU{{ $skuGroup['products']->count() === 1 ? '' : 's' }}</em>
                                    @if (! empty($skuGroup['option_id']))
                                        <span class="rfm-sku-group-actions">
                                            <button type="button"
                                                    class="rfm-sku-group-new-family"
                                                    data-rfm-sku-bucket-split-family
                                                    data-rfm-action="{{ route('retail-products.families.variant-options.split-family', [$family, $skuGroup['option_id']]) }}"
                                                    data-rfm-bucket-axis="{{ $groupingGroup->name }}"
                                                    data-rfm-bucket-label="{{ $skuGroup['label'] }}"
                                                    data-rfm-sku-count="{{ $skuGroup['products']->count() }}"
                                                    aria-label="New family for {{ $groupingGroup->name }}: {{ $skuGroup['label'] }} ({{ $skuGroup['products']->count() }} SKUs)"
                                                    title="New family">
                                                <span aria-hidden="true">+</span>
                                            </button>
                                            <button type="button"
                                                    class="rfm-sku-group-delete"
                                                    data-rfm-sku-bucket-delete
                                                    data-rfm-action="{{ route('retail-products.families.variant-options.skus.destroy', [$family, $skuGroup['option_id']]) }}"
                                                    data-rfm-bucket-axis="{{ $groupingGroup->name }}"
                                                    data-rfm-bucket-label="{{ $skuGroup['label'] }}"
                                                    data-rfm-sku-count="{{ $skuGroup['products']->count() }}"
                                                    aria-label="Delete all SKUs in {{ $groupingGroup->name }}: {{ $skuGroup['label'] }}"
                                                    title="Delete group">
                                                <span aria-hidden="true">×</span>
                                            </button>
                                        </span>
                                    @endif
                                </div>
                                <span class="rfm-sku-group-chevron" aria-hidden="true">›</span>
                            </summary>
                    @endif

                    <div class="rfm-sku-list-head" aria-hidden="true">
                        <span class="rfm-sku-list-head-thumb"></span>
                        <span class="rfm-sku-list-head-name">Product</span>
                        <span class="rfm-sku-list-head-price">Price</span>
                        <span class="rfm-sku-list-head-barcode">Barcode</span>
                        <span class="rfm-sku-list-head-view"></span>
                        <span class="rfm-sku-list-head-chevron"></span>
                    </div>

                    <ul class="rfm-sku-list" data-rfm-list>
                @foreach ($skuGroup['products'] as $product)
                    @php
                        $quickMainMedia = $product->media->firstWhere('image_role', 'main');
                        $quickVariantMedia = $product->media->firstWhere('image_role', 'variant');
                        $quickGalleryMedia = $product->media->where('image_role', 'gallery')->values();
                        $quickGalleryCount = $quickGalleryMedia->count();
                        $quickImagePayload = fn ($media) => [
                            'id' => $media->id,
                            'url' => $media->displayUrl(),
                            'label' => $media->alt_text ?: ucfirst(str_replace('_', ' ', (string) $media->image_role)),
                            'deleteUrl' => route('retail-products.media.destroy', $media),
                        ];
                        $primaryMedia = $product->media->first(fn ($media) => $media->is_primary && in_array($media->image_role, ['variant', 'main'], true))
                            ?? $quickVariantMedia
                            ?? $quickMainMedia
                            ?? $product->media->firstWhere('is_primary', true)
                            ?? $product->media->first();
                        $primaryImg = $primaryMedia?->displayUrl();
                        $quickImageTargets = [
                            'main' => [
                                'storeUrl' => route('retail-products.products.media.store', $product),
                                'mobileTargetType' => $quickMainMedia ? 'retail_media' : 'retail_product',
                                'mobileTargetId' => $quickMainMedia?->id ?? $product->id,
                                'currentUrl' => $quickMainMedia?->displayUrl() ?? '',
                                'mediaId' => $quickMainMedia?->id,
                                'deleteUrl' => $quickMainMedia ? route('retail-products.media.destroy', $quickMainMedia) : '',
                                'replaceUrl' => $quickMainMedia ? route('retail-products.media.replace', $quickMainMedia) : '',
                                'count' => $quickMainMedia ? 1 : 0,
                                'images' => $quickMainMedia ? [$quickImagePayload($quickMainMedia)] : [],
                            ],
                            'variant' => [
                                'storeUrl' => route('retail-products.products.media.store', $product),
                                'mobileTargetType' => $quickVariantMedia ? 'retail_media' : 'retail_product',
                                'mobileTargetId' => $quickVariantMedia?->id ?? $product->id,
                                'currentUrl' => $quickVariantMedia?->displayUrl() ?? '',
                                'mediaId' => $quickVariantMedia?->id,
                                'deleteUrl' => $quickVariantMedia ? route('retail-products.media.destroy', $quickVariantMedia) : '',
                                'replaceUrl' => $quickVariantMedia ? route('retail-products.media.replace', $quickVariantMedia) : '',
                                'count' => $quickVariantMedia ? 1 : 0,
                                'images' => $quickVariantMedia ? [$quickImagePayload($quickVariantMedia)] : [],
                            ],
                            'gallery' => [
                                'storeUrl' => route('retail-products.products.media.store', $product),
                                'mobileTargetType' => 'retail_product',
                                'mobileTargetId' => $product->id,
                                'currentUrl' => '',
                                'mediaId' => null,
                                'deleteUrl' => '',
                                'replaceUrl' => '',
                                'count' => $quickGalleryCount,
                                'images' => $quickGalleryMedia->map($quickImagePayload)->values()->all(),
                            ],
                        ];
                        $quickImageTargetsJson = json_encode($quickImageTargets, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                        $inventory = $product->inventoryLevels->first();
                        $stockQty = (float) $product->inventoryLevels->sum('stock_quantity');
                        $retailPrice = $product->price?->retail_price;
                        $hasPrice = $retailPrice !== null;
                        $hasBarcode = ! empty($product->barcode);
                        $hasDuplicateBarcode = $hasBarcode
                            && (($familyBarcodeCounts[strtolower(trim((string) $product->barcode))] ?? 0) > 1);
                        $hasImage = $product->media->isNotEmpty();
                        $isPos = (bool) $product->is_pos_active;
                        $isOnline = (bool) $product->is_ecommerce_active;
                        $isInventoryTracked = (bool) $product->is_inventory_tracked;
                        $optionText = $product->variantValues
                            ->sortBy(fn ($v) => sprintf('%s:%s', VariantNaturalSort::groupKey($v->group), VariantNaturalSort::valueKey($v->option?->label)))
                            ->map(fn ($v) => ['group' => $v->group->name, 'label' => $v->option->label, 'group_id' => $v->group->id]);
                        $displayOptionText = $groupingGroup
                            ? $optionText->reject(fn ($v) => $v['group_id'] === $groupingGroup->id)
                            : $optionText;
                        // Split axes into "main" (shared across every SKU in the family,
                        // e.g. Length=46) and "sub/unique" (the axis that distinguishes
                        // this SKU, e.g. Colour=AZURE). The row sub-text renders them
                        // as "main - sub" so the user sees both the family axis and
                        // the distinguishing variant at a glance.
                        $mainOptionText   = $displayOptionText->filter(fn ($v) => isset($sharedGroupIds[$v['group_id']]));
                        $uniqueOptionText = $displayOptionText->reject(fn ($v) => isset($sharedGroupIds[$v['group_id']]));
                        if ($uniqueOptionText->isEmpty()) {
                            // Edge-case: every axis varies (or single-SKU family) — show
                            // the full chain on the sub side and leave the main side empty.
                            $uniqueOptionText = $displayOptionText;
                            $mainOptionText   = collect();
                        }
                        $variantOptionIds = $product->variantValues
                            ->pluck('product_variant_option_id')
                            ->filter()
                            ->implode(' ');
                        $variantOptionsByGroup = $product->variantValues
                            ->filter(fn ($v) => $v->product_variant_group_id && $v->product_variant_option_id)
                            ->mapWithKeys(fn ($v) => [(string) $v->product_variant_group_id => (string) $v->product_variant_option_id])
                            ->all();
                        $rowTitle = $optionText->isNotEmpty()
                            ? $family->family_name.' · '.$optionText->pluck('label')->implode(' · ')
                            : $product->name;
                        $barcodeVariantChips = $optionText
                            ->map(fn ($v) => ['group' => $v['group'], 'label' => $v['label']])
                            ->values();
                        $barcodeVariantTitle = $barcodeVariantChips->isNotEmpty()
                            ? $barcodeVariantChips->map(fn ($v) => trim($v['group'].' '.$v['label']))->implode(' · ')
                            : ($product->sku ?: $product->name);
                        $searchBlob = strtolower(trim(implode(' ', [
                            $family->family_name,
                            $product->name,
                            $product->inventory_name ?? '',
                            $product->receipt_name ?? '',
                            $product->ecommerceProfile?->online_title ?? '',
                            $product->sku ?? '',
                            $product->barcode ?? '',
                            $inventory?->shelf_location ?? '',
                            $inventory?->supplier ?? '',
                            $optionText->pluck('label')->implode(' '),
                        ])));
                    @endphp
                    <li class="rfm-sku" data-rfm-sku
                        data-rfm-product-id="{{ $product->id }}"
                        data-rfm-status="{{ $product->status }}"
                        data-rfm-needs-price="{{ $hasPrice ? '0' : '1' }}"
                        data-rfm-needs-barcode="{{ $hasBarcode ? '0' : '1' }}"
                        data-rfm-barcode="{{ $product->barcode }}"
                        data-rfm-duplicate-barcode="{{ $hasDuplicateBarcode ? '1' : '0' }}"
                        data-rfm-needs-image="{{ $hasImage ? '0' : '1' }}"
                        data-rfm-out-of-stock="{{ $stockQty <= 0 ? '1' : '0' }}"
                        data-rfm-not-pos="{{ $isPos ? '0' : '1' }}"
                        data-rfm-not-online="{{ $isOnline ? '0' : '1' }}"
                        data-rfm-not-inventory="{{ $isInventoryTracked ? '0' : '1' }}"
                        data-rfm-variant-options="{{ $variantOptionIds }}"
                        data-rfm-variant-options-by-group="{{ json_encode($variantOptionsByGroup) }}"
                        data-rfm-search="{{ $searchBlob }}">
                        <details class="rfm-sku-card">
                            <summary class="rfm-sku-summary">
                                {{-- tiny thumb --}}
                                <div class="rfm-row-thumb">
                                    @if ($primaryImg)
                                        <button type="button"
                                                class="rfm-thumb-lightbox-trigger"
                                                data-rfm-quick-image-open
                                                data-image-url="{{ $primaryImg }}"
                                                data-picture-id="{{ $rowTitle }}"
                                                data-image-store-url="{{ route('retail-products.products.media.store', $product) }}"
                                                data-mobile-target-id="{{ $product->id }}"
                                                data-product-title="{{ $rowTitle }}"
                                                data-current-image-url="{{ $primaryImg }}"
                                                data-rfm-quick-image-targets="{{ $quickImageTargetsJson }}"
                                                @if ($primaryMedia)
                                                    data-media-id="{{ $primaryMedia->id }}"
                                                    data-image-delete-url="{{ route('retail-products.media.destroy', $primaryMedia) }}"
                                                    data-image-replace-url="{{ route('retail-products.media.replace', $primaryMedia) }}"
                                                    data-image-role="{{ in_array($primaryMedia->image_role, ['main', 'variant'], true) ? $primaryMedia->image_role : 'main' }}"
                                                    data-image-usage="{{ $primaryMedia->usage_context }}"
                                                    data-image-source-label="{{ $primaryMedia->source_label }}"
                                                    data-image-notes="{{ $primaryMedia->notes }}"
                                                @endif
                                                aria-label="View image full size — {{ $rowTitle }}">
                                            <img src="{{ $primaryImg }}" alt="" loading="lazy">
                                        </button>
                                    @else
                                        <button type="button"
                                                class="rfm-thumb-lightbox-trigger"
                                                data-rfm-quick-image-open
                                                data-image-store-url="{{ route('retail-products.products.media.store', $product) }}"
                                                data-mobile-target-id="{{ $product->id }}"
                                                data-product-title="{{ $rowTitle }}"
                                                data-current-image-url=""
                                                data-image-role="main"
                                                data-rfm-quick-image-targets="{{ $quickImageTargetsJson }}"
                                                aria-label="Add product image for {{ $rowTitle }}">
                                            <span aria-hidden="true">?</span>
                                        </button>
                                    @endif
                                </div>

                                {{-- name + options: mobile shows family title + every variant axis; desktop keeps compact lines --}}
                                <div class="rfm-row-name">
                                    <div class="rfm-row-identity">
                                        <p class="rfm-row-family">{{ $family->display_family_name }}</p>
                                        @if ($displayOptionText->isNotEmpty())
                                            <ul class="rfm-row-variant-list">
                                                @foreach ($displayOptionText as $opt)
                                                    <li class="rfm-row-variant">
                                                        <span class="rfm-row-variant-axis">{{ $opt['group'] }}</span>
                                                        <span class="rfm-row-variant-value">{{ $opt['label'] }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @elseif ($product->name !== $family->family_name)
                                            <p class="rfm-row-variant-fallback">{{ $product->name }}</p>
                                        @endif
                                    </div>
                                    <span class="rfm-row-title">
                                        @if ($optionText->isNotEmpty())
                                            {{ $family->family_name }}@foreach ($optionText as $opt)&nbsp;·&nbsp;{{ $opt['label'] }}@endforeach
                                        @else
                                            {{ $product->name }}
                                        @endif
                                    </span>
                                    <span class="rfm-row-sub">
                                        @if ($mainOptionText->isNotEmpty() && $uniqueOptionText->isNotEmpty())
                                            <span class="rfm-row-sub-main">{{ $mainOptionText->pluck('label')->implode(' · ') }}</span>
                                            <span class="rfm-row-sub-sep" aria-hidden="true">—</span>
                                            <span class="rfm-row-sub-unique">{{ $uniqueOptionText->pluck('label')->implode(' · ') }}</span>
                                        @elseif ($uniqueOptionText->isNotEmpty())
                                            <span class="rfm-row-sub-unique">{{ $uniqueOptionText->pluck('label')->implode(' · ') }}</span>
                                        @elseif ($product->sku)
                                            {{ $product->sku }}
                                        @else
                                            —
                                        @endif
                                    </span>
                                </div>
                                {{-- price (tappable for quick entry) --}}
                                <button type="button"
                                        class="rfm-row-price {{ $hasPrice ? '' : 'is-missing' }}"
                                        data-rfm-price-open
                                        data-rfm-action="{{ route('retail-products.products.operations.update', $product) }}"
                                        data-rfm-product-title="{{ $rowTitle }}"
                                        data-rfm-current-price="{{ $retailPrice }}"
                                        aria-label="{{ $hasPrice ? 'Edit price for '.$rowTitle : 'Set price for '.$rowTitle }}">
                                    {{ $hasPrice ? '£'.number_format((float)$retailPrice, 2) : '£?' }}
                                </button>

                                <button type="button"
                                        class="rfm-row-barcode {{ $product->barcode ? 'has-barcode' : '' }}"
                                        data-rfm-barcode-open
                                        data-rfm-action="{{ route('retail-products.products.operations.update', $product) }}"
                                        data-rfm-variant-title="{{ $barcodeVariantTitle }}"
                                        data-rfm-variant-chips='@json($barcodeVariantChips)'
                                        data-rfm-current-barcode="{{ $product->barcode }}"
                                        aria-label="Add or edit barcode for {{ $barcodeVariantTitle }}">
                                    {{ $product->barcode ?: '+ Add barcode' }}
                                </button>

                                <div class="rfm-sku-summary-actions">
                                    <a class="rfm-row-view"
                                       href="{{ route('retail-products.products.show', $product) }}"
                                       onclick="event.stopPropagation()"
                                       aria-label="View sellable product page for {{ $rowTitle }}">
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                        <span class="rfm-row-view-label">View</span>
                                    </a>
                                    <button type="button"
                                            class="rfm-sku-delete"
                                            data-rfm-sku-delete
                                            data-rfm-action="{{ route('retail-products.products.destroy', $product) }}"
                                            data-rfm-product-title="{{ $rowTitle }}"
                                            aria-label="Delete {{ $rowTitle }}">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" stroke-linecap="round" stroke-linejoin="round"/>
                                            <path d="M10 11v6M14 11v6" stroke-linecap="round"/>
                                        </svg>
                                        <span class="rfm-sku-delete-label">Delete</span>
                                    </button>
                                    <span class="rfm-row-chevron" aria-hidden="true">›</span>
                                </div>
                            </summary>

                            <div class="rfm-sku-body">
                                <form method="POST"
                                      id="rfm-operations-{{ $product->id }}"
                                      action="{{ route('retail-products.products.operations.update', $product) }}"
                                      class="rfm-panel"
                                      data-rfm-edit-form
                                      data-rfm-stepper-form>
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="partial" value="1">

                                    {{-- Compact inline toggles --}}
                                    <div class="rfm-panel-toggles" data-rfm-toggles
                                         data-rfm-action="{{ route('retail-products.products.operations.update', $product) }}">
                                        <label class="rfm-tog {{ $isPos ? 'is-on' : '' }}" data-rfm-switch="pos">
                                            <span class="rfm-tog-track"><span class="rfm-tog-thumb"></span></span>
                                            <input type="checkbox" data-rfm-toggle="is_pos_active" @checked($isPos)>
                                            <span>POS</span>
                                        </label>
                                        <label class="rfm-tog {{ $isOnline ? 'is-on' : '' }}" data-rfm-switch="online">
                                            <span class="rfm-tog-track"><span class="rfm-tog-thumb"></span></span>
                                            <input type="checkbox" data-rfm-toggle="is_ecommerce_active" @checked($isOnline)>
                                            <span>Online</span>
                                        </label>
                                        <label class="rfm-tog {{ $product->is_inventory_tracked ? 'is-on' : '' }}" data-rfm-switch="track">
                                            <span class="rfm-tog-track"><span class="rfm-tog-thumb"></span></span>
                                            <input type="checkbox" data-rfm-toggle="is_inventory_tracked" @checked($product->is_inventory_tracked)>
                                            <span>Track stock</span>
                                        </label>
                                    </div>

                                    {{-- Dense field grid --}}
                                    <div class="rfm-panel-grid">
                                        <label class="rfm-f">
                                            <span>£ Retail</span>
                                            <input type="number" name="retail_price" step="0.01" min="0" inputmode="decimal" value="{{ $retailPrice }}" placeholder="0.00">
                                        </label>
                                        <label class="rfm-f">
                                            <span>£ Cost</span>
                                            <input type="number" name="cost_price" step="0.01" min="0" inputmode="decimal" value="{{ $product->price?->cost_price }}" placeholder="0.00">
                                        </label>
                                        <label class="rfm-f">
                                            <span>VAT %</span>
                                            <input type="number" name="vat_rate" step="0.01" min="0" max="100" inputmode="decimal" value="{{ $product->price?->vat_rate }}" placeholder="20">
                                        </label>
                                        <label class="rfm-f rfm-f-stock">
                                            <span>Stock</span>
                                            <div class="rfm-mini-stepper">
                                                <button type="button" data-rfm-step="-1" aria-label="−">−</button>
                                                <input type="number" name="stock_quantity" step="1" inputmode="numeric" value="{{ $stockQty }}" data-rfm-stock-input>
                                                <button type="button" data-rfm-step="1" aria-label="+">+</button>
                                            </div>
                                        </label>
                                        <label class="rfm-f">
                                            <span>SKU</span>
                                            <input type="text" name="sku" autocomplete="off" autocapitalize="characters" value="{{ $product->sku }}" placeholder="CBPT141">
                                        </label>
                                        <label class="rfm-f">
                                            <span>Barcode</span>
                                            <input type="text" name="barcode" inputmode="numeric" autocomplete="off" value="{{ $product->barcode }}" placeholder="EAN / UPC">
                                        </label>
                                        <label class="rfm-f">
                                            <span>Shelf</span>
                                            <input type="text" name="shelf_location" value="{{ $inventory?->shelf_location }}" placeholder="Aisle / row">
                                        </label>
                                        <label class="rfm-f">
                                            <span>Supplier</span>
                                            <input type="text" name="supplier" value="{{ $inventory?->supplier }}">
                                        </label>
                                        <label class="rfm-f">
                                            <span>Supplier code</span>
                                            <input type="text" name="supplier_product_code" value="{{ $inventory?->supplier_product_code }}">
                                        </label>
                                        <label class="rfm-f">
                                            <span>Receipt name</span>
                                            <input type="text" name="receipt_name" value="{{ $product->receipt_name ?: $product->name }}">
                                        </label>
                                        <label class="rfm-f">
                                            <span>POS / inventory name</span>
                                            <input type="text" name="inventory_name" value="{{ $product->inventory_name ?: $product->name }}">
                                        </label>
                                        <label class="rfm-f rfm-f-wide">
                                            <span>Ecommerce title</span>
                                            <input type="text" name="ecommerce_title" value="{{ $product->ecommerceProfile?->online_title ?: $product->name }}">
                                        </label>
                                        <label class="rfm-f rfm-f-wide rfm-f-description">
                                            <span>Ecommerce description</span>
                                            <textarea name="description" rows="4" placeholder="Describe this sellable product for customers.">{{ $product->description }}</textarea>
                                        </label>
                                    </div>
                                </form>

                                <aside class="rfm-ai-inline" data-rfm-ai-inline data-rfm-ai-product-id="{{ $product->id }}" hidden>
                                    <header>
                                        <div>
                                            <span>AI naming suggestion</span>
                                            <strong data-rfm-ai-inline-title>Ready to review</strong>
                                        </div>
                                        <span class="rfm-ai-confidence" data-rfm-ai-inline-confidence>D</span>
                                    </header>
                                    <div class="rfm-ai-inline-grid">
                                        <label>
                                            <span>Receipt</span>
                                            <input type="text" data-rfm-ai-inline-field="receipt_name" maxlength="35">
                                        </label>
                                        <label>
                                            <span>POS / inventory</span>
                                            <input type="text" data-rfm-ai-inline-field="inventory_name" maxlength="80">
                                        </label>
                                        <label>
                                            <span>Ecommerce</span>
                                            <input type="text" data-rfm-ai-inline-field="ecommerce_title" maxlength="150">
                                        </label>
                                    </div>
                                    <p data-rfm-ai-inline-reason></p>
                                    <div class="rfm-ai-inline-actions">
                                        <button type="button" class="rfm-save-btn" data-rfm-ai-inline-use>Use this</button>
                                        <button type="button" class="rfm-secondary-btn" data-rfm-ai-inline-apply>Apply now</button>
                                        <button type="button" class="rfm-secondary-btn" data-rfm-ai-inline-dismiss>Cancel</button>
                                    </div>
                                </aside>

                                <div class="rfm-panel-actions">
                                    <div class="rfm-panel-actions-bar">
                                        <div class="rfm-panel-actions-primary">
                                            <button type="submit" class="rfm-save-btn" form="rfm-operations-{{ $product->id }}">Save</button>
                                            <a class="rfm-source-chip" href="{{ route('retail-products.products.show', $product) }}">
                                                <span>View product</span>
                                                <span class="rfm-source-chip-arrow" aria-hidden="true">-&gt;</span>
                                            </a>
                                            @if ($product->catalogueSku)
                                                <a class="rfm-source-chip" href="{{ route('brand-catalogue.skus.show', [
                                                    $family->brand_catalogue_id,
                                                    $family->brand_catalogue_brand_id,
                                                    $family->brand_catalogue_line_id,
                                                    $family->brand_catalogue_product_type_id,
                                                    $family->brand_catalogue_style_id,
                                                    $product->catalogueSku,
                                                ]) }}">
                                                    <span>Source SKU</span>
                                                    <span class="rfm-source-chip-arrow" aria-hidden="true">→</span>
                                                </a>
                                            @endif
                                        </div>
                                            <details class="rfm-img-toggle">
                                                <summary class="rfm-img-summary">
                                                    <span class="rfm-img-summary-text">
                                                        <span class="rfm-img-count">{{ $product->media->count() }}</span>
                                                        <span class="rfm-img-summary-label">Images</span>
                                                    </span>
                                                    <span class="rfm-img-summary-chevron" aria-hidden="true"></span>
                                                </summary>
                                                <div class="rfm-img-panel">
                                                    @include('retail-products._media-manager', [
                                                        'managerLevel' => 'sku',
                                                        'targetName' => $product->name,
                                                        'mediaCollection' => $product->media,
                                                        'storeRoute' => route('retail-products.products.media.store', $product),
                                                        'mobileCaptureTargetType' => 'retail_product',
                                                        'mobileCaptureTargetId' => $product->id,
                                                        'mediaRoles' => $mediaRoles,
                                                        'mediaUsageContexts' => $mediaUsageContexts,
                                                    ])
                                                </div>
                                            </details>
                                        </div>
                                    </div>
                            </div>
                        </details>
                    </li>
                @endforeach
                    </ul>
                    @if ($useSkuAccordions)
                        </details>
                    @endif
                @endforeach
            </div>

            </div>

            <details id="rfm-add-sku" class="rfm-family-shared rfm-add-sku rfm-skus-workspace-panel" data-rfm-shared-panel>
                <summary>
                    <div>
                        <span>Add sellable SKU</span>
                        <strong>Create one real product from variants</strong>
                    </div>
                </summary>

                <form method="POST"
                      action="{{ route('retail-products.families.products.store', $family) }}"
                      class="rfm-family-shared-form rfm-add-sku-form"
                      data-rfm-add-sku-form
                      data-rfm-family-name="{{ $family->family_name }}"
                      data-rfm-sku-prefix="{{ $newSkuPrefix ?? '' }}"
                      data-rfm-has-skus="{{ $products->isNotEmpty() ? '1' : '0' }}">
                    @csrf

                    <input type="hidden" name="mode" value="{{ old('mode', 'fresh') }}" data-rfm-add-sku-mode>

                    @if ($family->variantGroups->isNotEmpty())
                        <div class="rfm-variant-pricing-picker"
                             data-rfm-variant-picker
                             data-rfm-variant-options-create-url="{{ route('retail-products.families.variant-options.store', $family) }}"
                             data-rfm-variant-options-update-url-template="{{ route('retail-products.families.variant-options.update', [$family, 0]) }}"
                             data-rfm-variant-options-destroy-url-template="{{ route('retail-products.families.variant-options.destroy', [$family, 0]) }}">
                            @foreach ($family->variantGroups as $group)
                                <div class="rfm-shared-field rfm-variant-axis"
                                     data-rfm-variant-axis
                                     data-group-id="{{ $group->id }}"
                                     data-group-name="{{ $group->name }}">
                                    <div class="rfm-variant-axis-head">
                                        <span class="rfm-shared-label">{{ $group->name }}</span>
                                        <button type="button"
                                                class="rfm-variant-manage-toggle"
                                                data-rfm-manage-open
                                                aria-haspopup="dialog"
                                                aria-label="Manage {{ $group->name }} values">
                                            Manage values
                                        </button>
                                    </div>
                                    <select name="variant_options[{{ $group->id }}]" required data-rfm-variant-select>
                                        <option value="">Choose {{ $group->name }}</option>
                                        @foreach ($group->options as $option)
                                            <option value="{{ $option->id }}" @selected((string) old("variant_options.{$group->id}") === (string) $option->id)>
                                                {{ $option->label }}
                                            </option>
                                        @endforeach
                                    </select>

                                    <div class="rfm-variant-manage-source" data-rfm-manage-source hidden>
                                        <ul class="rfm-variant-manage-list" data-rfm-manage-list>
                                            @foreach ($group->options as $option)
                                                <li class="rfm-variant-manage-row" data-rfm-manage-row data-option-id="{{ $option->id }}">
                                                    <span class="rfm-variant-manage-label" data-rfm-manage-label>{{ $option->label }}</span>
                                                    <input type="text"
                                                           class="rfm-variant-manage-edit-input"
                                                           value="{{ $option->label }}"
                                                           data-rfm-manage-edit-input
                                                           hidden>
                                                    <div class="rfm-variant-manage-row-actions">
                                                        <button type="button" class="rfm-variant-manage-btn" data-rfm-manage-edit aria-label="Rename {{ $option->label }}">Rename</button>
                                                        <button type="button" class="rfm-variant-manage-btn" data-rfm-manage-save hidden>Save</button>
                                                        <button type="button" class="rfm-variant-manage-btn rfm-variant-manage-btn-ghost" data-rfm-manage-cancel hidden>Cancel</button>
                                                        <button type="button" class="rfm-variant-manage-btn rfm-variant-manage-btn-danger" data-rfm-manage-delete aria-label="Delete {{ $option->label }}">Remove</button>
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                        @if ($group->options->isEmpty())
                                            <p class="rfm-variant-manage-empty" data-rfm-manage-empty>No values yet — add your first one below.</p>
                                        @endif
                                        <input type="text"
                                               class="rfm-variant-manage-add-input"
                                               data-rfm-manage-add-input
                                               value=""
                                               placeholder="Add new {{ strtolower($group->name) }} value"
                                               maxlength="255"
                                               autocomplete="off"
                                               tabindex="-1"
                                               aria-hidden="true">
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="rfm-empty-state">
                            This family has no variant axes yet. The form can create one plain SKU only if the family does not already have one.
                        </p>
                    @endif

                    {{-- Live preview of what will be saved (name + auto SKU) --}}
                    <div class="rfm-add-sku-preview" data-rfm-preview aria-live="polite">
                        <div class="rfm-add-sku-preview-row">
                            <span class="rfm-add-sku-preview-label">Will save as</span>
                            <strong class="rfm-add-sku-preview-name" data-rfm-preview-name>{{ $family->family_name }}</strong>
                        </div>
                        <div class="rfm-add-sku-preview-row">
                            <span class="rfm-add-sku-preview-label">SKU code</span>
                            <code class="rfm-add-sku-preview-sku" data-rfm-preview-sku>
                                @if ($newSkuPrefix)
                                    {{ $newSkuPrefix.'?' }} <em>(auto)</em>
                                @else
                                    Auto <em>(no prefix yet)</em>
                                @endif
                            </code>
                        </div>
                        <p class="rfm-add-sku-preview-hint" data-rfm-preview-hint>
                            Pick a value in every axis above. The name and SKU follow the family pattern automatically — you can override them below if needed.
                        </p>
                    </div>

                    {{-- One-tap shortcut: duplicate an existing SKU instead --}}
                    @if ($products->isNotEmpty())
                        <div class="rfm-add-sku-duplicate" data-rfm-duplicate-block>
                            <button type="button" class="rfm-add-sku-duplicate-toggle" data-rfm-duplicate-toggle aria-expanded="{{ old('mode') === 'duplicate' ? 'true' : 'false' }}">
                                <span data-rfm-duplicate-toggle-label>{{ old('mode') === 'duplicate' ? 'Cancel duplicate, create blank instead' : 'Duplicate an existing SKU instead' }}</span>
                            </button>
                            <div class="rfm-add-sku-duplicate-panel" data-rfm-duplicate-panel @if(old('mode') !== 'duplicate') hidden @endif>
                                <label class="rfm-shared-field">
                                    <span class="rfm-shared-label">Source SKU</span>
                                    <select name="duplicate_product_id" data-rfm-duplicate-source>
                                        <option value="">Pick a SKU to copy fields from</option>
                                        @foreach ($products as $duplicateProduct)
                                            @php
                                                $duplicateOptions = $duplicateProduct->variantValues
                                                    ->sortBy(fn ($v) => sprintf('%s:%s', $v->group ? VariantNaturalSort::groupKey($v->group) : '9999', VariantNaturalSort::valueKey($v->option?->label)))
                                                    ->map(fn ($v) => $v->option?->label)
                                                    ->filter()
                                                    ->implode(' / ');
                                                $duplicateLabel = $duplicateOptions !== ''
                                                    ? $family->family_name.' - '.$duplicateOptions
                                                    : $duplicateProduct->name;
                                            @endphp
                                            <option value="{{ $duplicateProduct->id }}" @selected((string) old('duplicate_product_id') === (string) $duplicateProduct->id)>
                                                {{ $duplicateLabel }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <small>Price, cost and VAT will be copied; barcode and photos are not.</small>
                                </label>
                                <label class="rfm-shared-field rfm-shared-field-checkbox">
                                    <span class="rfm-shared-apply">
                                        <input type="checkbox" name="copy_price" value="1" @checked(old('copy_price', '1') === '1')>
                                        Copy price / cost / VAT from the source SKU
                                    </span>
                                </label>
                            </div>
                        </div>
                    @endif

                    {{-- Advanced (collapsed): manual overrides for name, SKU, barcode, price --}}
                    <details class="rfm-add-sku-advanced" data-rfm-advanced @if(old('name') || old('sku') || old('barcode') || old('retail_price')) open @endif>
                        <summary>
                            <span class="rfm-add-sku-advanced-summary-label">Advanced overrides</span>
                            <em>Override the generated name, SKU, add a barcode, or set a custom price.</em>
                        </summary>

                        <div class="rfm-family-shared-grid">
                            <label class="rfm-shared-field">
                                <span class="rfm-shared-label">Product name override</span>
                                <input type="text"
                                       name="name"
                                       value="{{ old('name') }}"
                                       placeholder="{{ $family->family_name }} - selected variants"
                                       data-rfm-name-override>
                                <small>Leave blank to keep the auto-generated name shown above.</small>
                            </label>
                            <label class="rfm-shared-field">
                                <span class="rfm-shared-label">SKU code override</span>
                                <input type="text"
                                       name="sku"
                                       value="{{ old('sku') }}"
                                       placeholder="{{ $newSkuPrefix ? $newSkuPrefix.'?' : 'Auto' }}"
                                       data-rfm-sku-override>
                                <small>Leave blank to keep the auto-generated SKU shown above.</small>
                            </label>
                            <label class="rfm-shared-field">
                                <span class="rfm-shared-label">Barcode</span>
                                <input type="text" name="barcode" value="{{ old('barcode') }}" inputmode="numeric" placeholder="Scan or type later">
                            </label>
                            <label class="rfm-shared-field">
                                <span class="rfm-shared-label">&pound; Retail price</span>
                                <input type="number" name="retail_price" value="{{ old('retail_price') }}" step="0.01" min="0" inputmode="decimal" placeholder="Optional">
                            </label>
                        </div>
                    </details>

                    <div class="rfm-family-shared-actions">
                        <span>New SKUs start with POS, website and inventory on. Set barcode, price and photos before selling.</span>
                        <button type="submit" class="rfm-add-sku-submit">Create sellable SKU</button>
                    </div>
                </form>
            </details>

        </section>

        @include('retail-products.partials.family-ecommerce-preview', ['ecomPreviewData' => $ecomPreviewData])
        @include('retail-products.partials.family-display-name-editor', [
            'family' => $family,
            'familySharedDetails' => $familySharedDetails,
        ])

        @include('retail-products.partials.family-quick-image-modal', [
            'mediaRoles' => $mediaRoles,
            'mediaUsageContexts' => $mediaUsageContexts,
        ])

        <div class="rfm-quick-overlay rfm-quick-overlay--barcode" data-rfm-barcode-modal hidden aria-hidden="true">
            <button type="button" class="rfm-quick-backdrop" data-rfm-barcode-close aria-label="Close"></button>
            <section class="rfm-quick-panel rfm-barcode-panel" role="dialog" aria-modal="true" aria-label="Barcode receiver">
                <header class="rfm-barcode-head">
                    <div class="rfm-barcode-head-text">
                        <span class="rfm-barcode-eyebrow">Barcode scanner</span>
                        <div class="rfm-barcode-variants" data-rfm-barcode-variants aria-live="polite">
                            <p class="rfm-barcode-variants-fallback" data-rfm-barcode-title>Scan barcode</p>
                        </div>
                    </div>
                    <button type="button" class="rfm-barcode-close" data-rfm-barcode-close aria-label="Close">×</button>
                </header>
                <div class="rfm-barcode-mode" role="tablist" aria-label="Barcode input mode" data-rfm-barcode-mode-tabs hidden>
                    <button type="button"
                            class="rfm-barcode-mode-btn is-active"
                            role="tab"
                            aria-selected="true"
                            data-rfm-barcode-mode="keyboard">
                        Scanner / type
                    </button>
                    <button type="button"
                            class="rfm-barcode-mode-btn"
                            role="tab"
                            aria-selected="false"
                            data-rfm-barcode-mode="camera">
                        Camera
                    </button>
                </div>

                <div class="rfm-barcode-keyboard" data-rfm-barcode-keyboard-panel>
                    <label class="rfm-barcode-field">
                        <span>Scan or type barcode</span>
                        <div class="rfm-barcode-input-row">
                            <input type="text"
                                   inputmode="numeric"
                                   autocomplete="off"
                                   data-rfm-barcode-input
                                   placeholder="Scan now…">
                            <button type="button"
                                    class="rfm-barcode-camera-jump"
                                    data-rfm-barcode-camera-jump
                                    hidden
                                    aria-label="Switch to camera scanner">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                                    <circle cx="12" cy="13" r="4"/>
                                </svg>
                            </button>
                        </div>
                    </label>
                    <p class="rfm-barcode-hint" data-rfm-barcode-keyboard-hint>
                        Use a USB or Bluetooth scanner, or type the code. No scanner? Tap the camera button.
                    </p>
                </div>

                <div class="rfm-barcode-camera" data-rfm-barcode-camera-panel hidden>
                    <div class="rfm-barcode-camera-view">
                        <video autoplay playsinline webkit-playsinline muted data-rfm-barcode-video></video>
                    </div>
                    <p class="rfm-barcode-camera-hint">Point at the barcode. We verify the check digit and need two matching reads before saving.</p>
                    <button type="button" class="rfm-barcode-back-type" data-rfm-barcode-mode="keyboard">
                        Use scanner or type instead
                    </button>
                </div>

                <p class="rfm-barcode-status" data-rfm-barcode-status>Waiting for barcode…</p>
            </section>
        </div>

        {{-- Quick price entry bottom sheet --}}
        <div class="rfm-quick-overlay" data-rfm-price-modal hidden aria-hidden="true">
            <button type="button" class="rfm-quick-backdrop" data-rfm-price-close aria-label="Close"></button>
            <section class="rfm-quick-panel rfm-price-panel" role="dialog" aria-modal="true" aria-label="Set price">
                <header class="rfm-quick-head">
                    <div>
                        <span>Quick price</span>
                        <strong data-rfm-price-title>Set price</strong>
                    </div>
                    <button type="button" class="rfm-secondary-btn" data-rfm-price-close>Cancel</button>
                </header>
                <div class="rfm-price-body">
                    <label class="rfm-price-field">
                        <span class="rfm-price-symbol">£</span>
                        <input type="number"
                               step="0.01"
                               min="0"
                               inputmode="decimal"
                               placeholder="0.00"
                               autocomplete="off"
                               data-rfm-price-input>
                    </label>
                    <button type="button" class="rfm-save-btn rfm-price-save-btn" data-rfm-price-save>
                        Save price
                    </button>
                    <p class="rfm-barcode-status" data-rfm-price-status></p>
                </div>
            </section>
        </div>


        <div class="rfm-quick-overlay rfm-variant-manage-overlay" data-rfm-variant-manage-modal hidden aria-hidden="true">
            <button type="button" class="rfm-quick-backdrop" data-rfm-variant-manage-close aria-label="Close"></button>
            <section class="rfm-quick-panel rfm-variant-manage-panel" role="dialog" aria-modal="true" aria-labelledby="rfm-variant-manage-title">
                <header class="rfm-variant-manage-head">
                    <div>
                        <span class="rfm-variant-manage-eyebrow">Variant values</span>
                        <strong id="rfm-variant-manage-title" data-rfm-variant-manage-title>Values</strong>
                    </div>
                    <button type="button" class="rfm-variant-manage-close" data-rfm-variant-manage-close aria-label="Close">×</button>
                </header>
                <div class="rfm-variant-manage-body">
                    <ul class="rfm-variant-manage-list" data-rfm-manage-list></ul>
                    <p class="rfm-variant-manage-empty" data-rfm-manage-empty hidden>No values yet — add one below.</p>
                    <div class="rfm-variant-manage-add">
                        <input type="text"
                               class="rfm-variant-manage-add-input"
                               data-rfm-manage-add-input
                               placeholder="Add a value"
                               maxlength="255"
                               autocomplete="off">
                        <button type="button" class="rfm-variant-manage-btn rfm-variant-manage-btn-primary" data-rfm-manage-add>Add</button>
                    </div>
                </div>
                <footer class="rfm-variant-manage-foot">
                    <button type="button" class="rfm-variant-manage-done" data-rfm-variant-manage-close>Done</button>
                </footer>
            </section>
        </div>

        <div class="rfm-toast" data-rfm-toast hidden></div>

        {{-- Full-screen image preview (wheel = zoom, Esc / backdrop / tap image at 1× = close) --}}
        <div
            class="pw-lightbox-overlay"
            id="rfm-picture-preview-modal"
            data-picture-preview-modal
            aria-hidden="true"
            hidden
        >
            <button type="button" class="pw-lightbox-backdrop" aria-label="Close"></button>
            <section class="pw-lightbox" role="dialog" aria-modal="true" aria-label="Image preview">
                <img src="" alt="" data-picture-preview-image decoding="async">
                <footer class="rfm-lightbox-footer" data-picture-preview-actions hidden>
                    <div class="rfm-lightbox-footer-main">
                        <div class="rfm-lightbox-meta">
                            <span class="rfm-lightbox-eyebrow">Image actions</span>
                            <strong data-picture-preview-title>Product image</strong>
                        </div>

                        <div class="rfm-lightbox-buttons" role="toolbar" aria-label="Image actions">
                            <button type="button" class="rfm-lightbox-btn rfm-lightbox-btn-muted" data-picture-preview-replace-toggle>
                                Replace
                            </button>
                            <form method="POST" action="#" data-picture-preview-delete-form onsubmit="return confirm('Delete this image?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rfm-lightbox-btn rfm-lightbox-btn-danger">Delete</button>
                            </form>
                            <button type="button" class="rfm-lightbox-btn rfm-lightbox-btn-primary" data-picture-preview-close>
                                Close
                            </button>
                        </div>
                    </div>

                    <div class="rfm-lightbox-replace" data-picture-preview-replace-panel hidden>
                        <header class="rfm-replace-head">
                            <div>
                                <span>Replace image</span>
                                <strong data-picture-preview-replace-title>Product image</strong>
                                <p>Choose the purpose first, then add the new photo from camera, phone, upload, URL, or paste.</p>
                            </div>
                            <button type="button" class="rfm-secondary-btn" data-picture-preview-replace-cancel>Cancel</button>
                        </header>

                        <div class="rfm-replace-body">
                            <aside class="rfm-replace-current">
                                <span>Current image</span>
                                <img src="" alt="" data-picture-preview-replace-current decoding="async">
                            </aside>

                            <div class="rfm-media rfm-replace-form-card" data-rfm-media-manager>
                            <form method="POST"
                                  action="#"
                                  enctype="multipart/form-data"
                                  class="rfm-media-add"
                                  data-mobile-capture-job-url="{{ route('mobile-capture.jobs.store') }}"
                                  data-rfm-media-add-form
                                  data-picture-preview-replace-form>
                                @csrf
                                <input type="hidden" name="mobile_capture_destination" value="retail">
                                <input type="hidden" name="mobile_capture_target_type" value="retail_media">
                                <input type="hidden" name="mobile_capture_target_id" value="" data-picture-preview-replace-mobile-target-id>

                                <div class="rfm-media-purpose">
                                    <label>
                                        <span>Image purpose</span>
                                        <select name="image_role" required data-picture-preview-role>
                                            @foreach ($mediaRoles as $role)
                                                <option value="{{ $role['value'] }}">{{ $role['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>
                                        <span>Use on</span>
                                        <select name="usage_context" required data-picture-preview-usage>
                                            @foreach ($mediaUsageContexts as $ctx)
                                                <option value="{{ $ctx['value'] }}">{{ $ctx['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <small>These fields control the generated image name when the replacement is saved.</small>
                                </div>

                                <div class="rfm-media-tabs" role="tablist" aria-label="Replacement image source">
                                    <button type="button" class="rfm-media-tab is-active" data-rfm-media-tab="camera" role="tab" aria-selected="true">Camera</button>
                                    <button type="button" class="rfm-media-tab" data-rfm-media-tab="phone" role="tab" aria-selected="false">Phone</button>
                                    <button type="button" class="rfm-media-tab" data-rfm-media-tab="upload" role="tab" aria-selected="false">Upload</button>
                                    <button type="button" class="rfm-media-tab" data-rfm-media-tab="url" role="tab" aria-selected="false">URL</button>
                                    <button type="button" class="rfm-media-tab" data-rfm-media-tab="paste" role="tab" aria-selected="false">Paste</button>
                                </div>

                                <div class="rfm-media-tab-panels">
                                    <div class="rfm-media-tab-panel is-active" data-rfm-media-panel="camera">
                                        <label class="rfm-media-capture">
                                            <input type="file" name="uploaded_image" accept="image/*" capture="environment" data-rfm-camera>
                                            <span class="rfm-media-capture-title">Use camera</span>
                                            <small class="rfm-media-capture-hint">Back camera on phones; file picker on desktop.</small>
                                        </label>
                                    </div>

                                    <div class="rfm-media-tab-panel" data-rfm-media-panel="phone">
                                        <div class="rfm-phone-capture">
                                            <strong>Send this replacement request to the phone</strong>
                                            <span>Keep Mobile Capture open on the phone once. This request will replace the exact image currently open in this modal.</span>
                                            <button type="button" class="rfm-phone-create" data-rfm-phone-create>Send to phone camera</button>
                                            <label class="rfm-phone-url" hidden>
                                                <span>Fallback phone link</span>
                                                <input type="text" readonly data-rfm-phone-url>
                                            </label>
                                            <p class="rfm-phone-status" data-rfm-phone-status hidden></p>
                                        </div>
                                    </div>

                                    <div class="rfm-media-tab-panel" data-rfm-media-panel="upload">
                                        <label class="rfm-media-capture">
                                            <input type="file" name="uploaded_image_alt" accept="image/*" data-rfm-upload>
                                            <span class="rfm-media-capture-title">Choose file</span>
                                            <small class="rfm-media-capture-hint">JPEG, PNG, WebP, or GIF up to 10&nbsp;MB.</small>
                                        </label>
                                    </div>

                                    <div class="rfm-media-tab-panel" data-rfm-media-panel="url">
                                        <label class="rfm-media-tab-url-field">
                                            <span>Image URL</span>
                                            <input type="url" name="external_url" placeholder="https://..." inputmode="url" autocomplete="off">
                                        </label>
                                        <label class="rfm-media-inline-check">
                                            <input type="checkbox" name="mirror_external" value="1" checked>
                                            <span>Save a local copy for offline use</span>
                                        </label>
                                    </div>

                                    <div class="rfm-media-tab-panel" data-rfm-media-panel="paste">
                                        <div class="rfm-media-paste" tabindex="0" data-rfm-media-paste>
                                            <strong>Click here, then press Ctrl+V</strong>
                                            <span>Paste a copied image and it replaces this image.</span>
                                        </div>
                                        <p class="rfm-media-paste-status" data-rfm-media-paste-status hidden></p>
                                    </div>
                                </div>

                                <details class="rfm-media-meta-fields">
                                    <summary>Image details (optional)</summary>
                                    <div class="rfm-grid">
                                        <label>
                                            <span>Source label</span>
                                            <input type="text" name="source_label" placeholder="Shop photo, supplier, official site..." data-picture-preview-source-label>
                                        </label>
                                        <label class="rfm-grid-wide">
                                            <span>Notes</span>
                                            <input type="text" name="notes" placeholder="Front pack, colour close-up..." data-picture-preview-notes>
                                        </label>
                                    </div>
                                </details>

                                <p class="rfm-media-paste-status" data-picture-preview-replace-status hidden></p>
                                <button type="submit" class="rfm-save-btn rfm-media-add-btn">Save replacement</button>
                            </form>
                            </div>
                        </div>
                    </div>
                </footer>
            </section>
        </div>
    </section>
@endsection
