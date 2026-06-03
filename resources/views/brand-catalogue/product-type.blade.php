@extends('layouts.app')

@section('title', $productType->name)

@section('content')
    @include('brand-catalogue._page-label', ['label' => 'Product Type Workspace', 'context' => $productType->name])

    @include('partials.catalogue-breadcrumb', [
        'items' => [
            ['label' => 'Brand Catalogue', 'url' => route('brand-catalogue.index')],
            ['label' => $catalogue->name, 'url' => route('brand-catalogue.show', $catalogue)],
            ['label' => $brand->name, 'url' => route('brand-catalogue.brands.show', [$catalogue, $brand])],
            ['label' => $line->name, 'url' => route('brand-catalogue.lines.show', [$catalogue, $brand, $line])],
            ['label' => $productType->name, 'current' => true],
        ],
    ])

    <datalist id="style-material-options">
        @foreach ($materialOptions as $materialOption)
            <option value="{{ $materialOption }}"></option>
        @endforeach
    </datalist>

    <div class="sr-hero">
        <div class="sr-hero-top">
            <div class="sr-hero-title-block">
                <h1 class="sr-hero-title">{{ $productType->name }}</h1>
                <div class="sr-hero-badges">
                    <span class="sr-badge sr-badge-accent">{{ $brand->name }}</span>
                    <span class="sr-badge sr-badge-warn">{{ $line->name }}</span>
                    <span class="sr-badge">{{ $catalogue->name }}</span>
                </div>
            </div>
            <button type="button" class="sr-edit-trigger" onclick="document.getElementById('product-type-edit').toggleAttribute('open')">Edit</button>
        </div>
        <div class="sr-stats">
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $productType->styles->count() }}</span>
                <span class="sr-stat-label">styles</span>
            </div>
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $productType->styles->sum('variants_count') }}</span>
                <span class="sr-stat-label">variant groups</span>
            </div>
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $productType->styles->sum('skus_count') }}</span>
                <span class="sr-stat-label">sellable SKUs</span>
            </div>
        </div>
    </div>

    <details id="product-type-edit" class="sr-edit-drawer">
        <summary class="sr-edit-drawer-summary">Edit product type</summary>
        <form method="POST" action="{{ route('brand-catalogue.product-types.update', $productType) }}" class="sr-edit-form">
            @csrf
            @method('PATCH')
            <div class="sr-edit-grid">
                <label><span>Name</span><input type="text" name="name" value="{{ $productType->name }}" required></label>
                <label><span>Sort</span><input type="number" name="sort_order" value="{{ $productType->sort_order }}" min="0"></label>
            </div>
            <label><span>Note</span><textarea name="note" rows="2">{{ $productType->note }}</textarea></label>
            <label><span>Link</span><input type="url" name="url" value="{{ $productType->url }}" placeholder="https://..."></label>
            <div class="button-row"><button type="submit" class="button button-primary">Save</button></div>
        </form>
    </details>

    <form method="POST" action="{{ route('brand-catalogue.styles.store', $productType) }}" class="sr-add-bar">
        @csrf
        <div class="sr-add-bar-icon">+</div>
        <input type="text" name="name" placeholder="Style..." required class="sr-add-input">
        <input type="text" name="material_name" value="Synthetic Hair" placeholder="Material..." required list="style-material-options" class="sr-add-note">
        <input type="url" name="url" placeholder="Link (https://...)" class="sr-add-note">
        <input type="number" name="sort_order" value="0" min="0" class="sr-add-sort" title="Sort order">
        <button type="submit" class="sr-add-btn">Add style</button>
    </form>

    @if ($productType->styles->isEmpty())
        <div class="sr-empty"><p>No styles yet - add one above</p></div>
    @else
        <div class="bc-card-list">
            @foreach ($productType->styles as $style)
                @php
                    $publishedFamily = $publishedFamiliesByStyle->get($style->id);
                @endphp
                <div class="bc-card-row {{ $publishedFamily ? 'bc-card-row--published' : '' }}">
                    @php
                        $styleCardUrl = route('brand-catalogue.styles.show', [$catalogue, $brand, $line, $productType, $style]).'?catalogue=1';
                        if ($publishedFamily && (int) ($publishedFamily->retail_families_count ?? 1) <= 1) {
                            $styleCardUrl = route('retail-products.families.show', $publishedFamily);
                        }
                    @endphp
                    <a href="{{ $styleCardUrl }}" class="bc-card-link">
                        <span class="sr-node-order">{{ $style->sort_order }}</span>
                        <div class="bc-card-body">
                            <span class="bc-card-name">
                                {{ $style->name }}
                                @if ($publishedFamily)
                                    <span class="sr-badge sr-badge-accent">Published</span>
                                @endif
                            </span>
                            <span class="bc-card-meta">
                                @if ($publishedFamily)
                                    @php
                                        $retailFamilyCount = (int) ($publishedFamily->retail_families_count ?? 1);
                                        $retailSkuTotal = (int) ($publishedFamily->retail_products_total ?? $publishedFamily->products_count);
                                    @endphp
                                    @if ($retailFamilyCount > 1)
                                        {{ $retailFamilyCount }} retail families · {{ number_format($retailSkuTotal) }} SKUs
                                    @else
                                        {{ number_format($retailSkuTotal) }} sellable SKU{{ $retailSkuTotal === 1 ? '' : 's' }} in retail
                                    @endif
                                @else
                                    {{ $style->variants_count }} variant group{{ $style->variants_count === 1 ? '' : 's' }}
                                @endif
                                <span class="bc-card-url-hint">{{ $style->material_name ?: 'material not set' }}</span>
                            </span>
                        </div>
                        <svg class="bc-card-arrow" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                    </a>
                    <button type="button" class="bc-card-edit-btn" onclick="this.parentElement.querySelector('.bc-card-edit').toggleAttribute('open')">Edit</button>
                    <details class="bc-card-edit">
                        <summary class="bc-card-edit-summary"></summary>
                        <form method="POST" action="{{ route('brand-catalogue.styles.update', $style) }}" class="sr-node-form">
                            @csrf
                            @method('PATCH')
                            <div class="sr-node-form-row">
                                <label class="sr-node-field sr-node-field-name"><span>Name</span><input type="text" name="name" value="{{ $style->name }}" required></label>
                                <label class="sr-node-field sr-node-field-sort"><span>Sort</span><input type="number" name="sort_order" value="{{ $style->sort_order }}" min="0"></label>
                                <label class="sr-node-field sr-node-field-name"><span>Material</span><input type="text" name="material_name" value="{{ $style->material_name ?: 'Synthetic Hair' }}" list="style-material-options" required></label>
                                <label class="sr-node-field sr-node-field-note"><span>Note</span><input type="text" name="note" value="{{ $style->note }}" placeholder="Optional"></label>
                                <label class="sr-node-field sr-node-field-url"><span>Link</span><input type="url" name="url" value="{{ $style->url }}" placeholder="https://..."></label>
                                <button type="submit" class="sr-node-save">Save</button>
                            </div>
                        </form>
                        <form method="POST" action="{{ route('brand-catalogue.styles.destroy', $style) }}" class="bc-delete-form" onsubmit="return confirm('Delete &quot;{{ $style->name }}&quot; and all its variants?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="bc-delete-btn">Delete style</button>
                        </form>
                    </details>
                </div>
            @endforeach
        </div>
    @endif
@endsection
