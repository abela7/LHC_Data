@extends('layouts.app')

@section('title', $style->name . ' — Style Workspace')
@section('section', 'Catalogue')
@section('heading', $style->name)

@section('content')
    @include('brand-catalogue._page-label', ['label' => 'Style Workspace', 'context' => $style->name])

    <datalist id="style-material-options">
        @foreach ($materialOptions as $materialOption)
            <option value="{{ $materialOption }}"></option>
        @endforeach
    </datalist>
    @include('partials.catalogue-breadcrumb', [
        'items' => [
            ['label' => 'Brand Catalogue', 'url' => route('brand-catalogue.index')],
            ['label' => $catalogue->name, 'url' => route('brand-catalogue.show', $catalogue)],
            ['label' => $brand->name, 'url' => route('brand-catalogue.brands.show', [$catalogue, $brand])],
            ['label' => $line->name, 'url' => route('brand-catalogue.lines.show', [$catalogue, $brand, $line])],
            ['label' => $productType->name, 'url' => route('brand-catalogue.product-types.show', [$catalogue, $brand, $line, $productType])],
            ['label' => $style->name, 'current' => true],
        ],
    ])

    {{-- ── Workspace Layout ── --}}
    <div class="sw-workspace">

        {{-- ═══ LEFT SIDEBAR ═══ --}}
        <aside class="sw-sidebar" data-sw-fragment="sidebar">

            {{-- Style Identity --}}
            <div class="sw-identity">
                <div class="sw-identity-top">
                    <h1 class="sw-style-name">{{ $style->name }}</h1>
                    <div class="sw-identity-actions">
                        <a href="{{ route('brand-catalogue.view.style', [$catalogue, $brand, $line, $productType, $style]) }}" class="sw-edit-btn" title="View mode">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </a>
                        <button type="button" class="sw-edit-btn" onclick="document.getElementById('style-edit-drawer').toggleAttribute('open')" title="Edit style">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                    </div>
                </div>
                <div class="sw-tags">
                    <span class="sw-tag sw-tag-brand">{{ $brand->name }}</span>
                    <span class="sw-tag sw-tag-line">{{ $line->name }}</span>
                    <span class="sw-tag sw-tag-type">{{ $productType->name }}</span>
                    <span class="sw-tag sw-tag-material">{{ $style->material_name ?: 'Material not set' }}</span>
                    @if ($style->url)
                        <a href="{{ $style->url }}" target="_blank" class="sw-tag sw-tag-link">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                            source
                        </a>
                    @endif
                </div>
            </div>

            {{-- Stats --}}
            <div class="sw-stats">
                <div class="sw-stat">
                    <span class="sw-stat-num">{{ $style->variants->count() }}</span>
                    <span class="sw-stat-label">Variants</span>
                </div>
                <div class="sw-stat">
                    <span class="sw-stat-num">{{ $style->variants->sum(fn ($v) => $v->options->count()) }}</span>
                    <span class="sw-stat-label">Options</span>
                </div>
                <div class="sw-stat">
                    <span class="sw-stat-num">{{ $style->skus->count() }}</span>
                    <span class="sw-stat-label">SKUs</span>
                </div>
                <div class="sw-stat">
                    <span class="sw-stat-num">{{ $style->images->count() }}</span>
                    <span class="sw-stat-label">Images</span>
                </div>
            </div>

            {{-- Edit Drawer --}}
            <details id="style-edit-drawer" class="sw-drawer">
                <summary class="sw-drawer-summary">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Edit Style Details
                </summary>
                <form method="POST" action="{{ route('brand-catalogue.styles.update', $style) }}" class="sw-drawer-form">
                    @csrf
                    @method('PATCH')
                    <label class="sw-field">
                        <span class="sw-field-label">Name</span>
                        <input type="text" name="name" value="{{ $style->name }}" required>
                    </label>
                    <label class="sw-field">
                        <span class="sw-field-label">Product type / scaffold</span>
                        <select name="brand_catalogue_product_type_id" required>
                            @foreach ($productTypeOptions as $typeOption)
                                <option value="{{ $typeOption->id }}" @selected((int) $style->brand_catalogue_product_type_id === (int) $typeOption->id)>
                                    {{ $typeOption->name }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <label class="sw-field">
                        <span class="sw-field-label">Material</span>
                        <input type="text" name="material_name" value="{{ $style->material_name ?: 'Synthetic Hair' }}" list="style-material-options" required>
                    </label>
                    <label class="sw-field">
                        <span class="sw-field-label">Note</span>
                        <textarea name="note" rows="2" placeholder="Internal note...">{{ $style->note }}</textarea>
                    </label>
                    <label class="sw-field">
                        <span class="sw-field-label">Link</span>
                        <input type="url" name="url" value="{{ $style->url }}" placeholder="https://...">
                    </label>
                    <label class="sw-field sw-field-short">
                        <span class="sw-field-label">Sort</span>
                        <input type="number" name="sort_order" value="{{ $style->sort_order }}" min="0">
                    </label>
                    <button type="submit" class="sw-btn sw-btn-primary">Save Changes</button>
                </form>
            </details>

            {{-- Style Images --}}
            <div class="sw-sidebar-section">
                <div class="sw-section-label">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                    Style Images
                    <span class="sw-level-badge sw-level-style">Style</span>
                </div>

                @if ($style->images->isNotEmpty())
                    <div class="sw-image-grid">
                        @foreach ($style->images as $image)
                            @php
                                $displayUrl = $image->displayUrl();
                            @endphp
                                <button type="button"
                                    class="sw-image-thumb"
                                    data-picture-preview-trigger
                                    data-image-url="{{ $displayUrl }}"
                                    data-picture-id="{{ $style->name }} - {{ $image->image_role }}{{ $image->is_primary ? ' (primary)' : '' }}"
                                    data-media-id="{{ $image->id }}"
                                    data-image-target-type="brand_catalogue_style"
                                    data-image-target-id="{{ $style->id }}"
                                    data-image-delete-url="{{ route('images.destroy', $image) }}"
                                    data-image-replace-url="{{ route('images.replace', $image) }}"
                                    data-image-role="{{ $image->image_role }}"
                                    data-image-usage="{{ $image->usage_context }}"
                                    data-image-source-label="{{ $image->source_label }}"
                                    data-image-notes="{{ $image->notes }}">
                                @if ($displayUrl)
                                    <img src="{{ $displayUrl }}" alt="{{ $image->notes ?: $style->name }}">
                                @else
                                    <span class="sw-image-placeholder-icon">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                    </span>
                                @endif
                                <span class="sw-image-role">{{ $image->image_role }}</span>
                                @if ($image->is_primary)
                                    <span class="sw-image-primary">★</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif

                <details class="sw-add-drawer">
                    <summary class="sw-add-trigger">+ Add style image</summary>
                    <div class="sw-sidebar-catalogue-add">
                        @include('brand-catalogue._catalogue-image-add-form', [
                            'targetType' => 'brand_catalogue_style',
                            'targetId' => $style->id,
                            'imageRoleOptions' => $styleImageRoleOptions,
                            'defaultImageRole' => 'main',
                            'primaryLabel' => 'Set as primary style image',
                            'addButtonLabel' => 'Add style image',
                        ])
                    </div>
                </details>

                @if ($style->images->isNotEmpty())
                    <details class="sw-add-drawer">
                        <summary class="sw-add-trigger">Manage images ({{ $style->images->count() }})</summary>
                        <div class="sw-image-manage-list">
                            @foreach ($style->images as $image)
                                @php
                                    $displayUrl = $image->displayUrl();
                                    $roleExists = collect($styleImageRoleOptions)->contains(fn ($role) => $role['value'] === $image->image_role);
                                @endphp
                                <details class="sw-image-manage-card">
                                <summary>
                                    <span class="sw-image-manage-preview">
                                        <button type="button"
                                                class="sw-image-thumb"
                                                data-picture-preview-trigger
                                                data-image-url="{{ $image->displayUrl() }}"
                                                data-picture-id="{{ $style->name }} - {{ $image->image_role }}{{ $image->is_primary ? ' (primary)' : '' }}"
                                                data-media-id="{{ $image->id }}"
                                                data-image-target-type="brand_catalogue_style"
                                                data-image-target-id="{{ $style->id }}"
                                                data-image-delete-url="{{ route('images.destroy', $image) }}"
                                                data-image-replace-url="{{ route('images.replace', $image) }}"
                                                data-image-role="{{ $image->image_role }}"
                                                data-image-usage="{{ $image->usage_context }}"
                                                data-image-source-label="{{ $image->source_label }}"
                                                data-image-notes="{{ $image->notes }}">
                                            @if ($image->displayUrl())
                                                <img src="{{ $image->displayUrl() }}" alt="{{ $image->notes ?: $style->name }}">
                                            @else
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                            @endif
                                        </button>
                                        </span>
                                        <span class="sw-image-manage-copy">
                                            <span class="bc-vtype-badge bc-vtype-text">{{ strtoupper(str_replace('_', ' ', $image->image_role)) }}</span>
                                            <strong>{{ $image->is_primary ? 'Primary image' : 'Style image' }}</strong>
                                            <small>{{ $image->notes ?: 'No notes' }}</small>
                                        </span>
                                    </summary>

                                    <form method="POST" action="{{ route('images.update', $image) }}" class="sw-image-manage-form">
                                        @csrf
                                        @method('PATCH')
                                        <label class="sw-field">
                                            <span class="sw-field-label">Role</span>
                                            <select name="image_role" required>
                                                @unless ($roleExists)
                                                    <option value="{{ $image->image_role }}" selected>{{ ucfirst(str_replace('_', ' ', $image->image_role)) }}</option>
                                                @endunless
                                                @foreach ($styleImageRoleOptions as $roleOpt)
                                                    <option value="{{ $roleOpt['value'] }}" @selected($roleOpt['value'] === $image->image_role)>{{ $roleOpt['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="sw-field">
                                            <span class="sw-field-label">Image URL</span>
                                            <input type="url" name="external_url" value="{{ $image->external_url }}" placeholder="{{ $image->storage_path ? 'Local upload' : 'https://...' }}">
                                        </label>
                                        <label class="sw-field sw-image-manage-note">
                                            <span class="sw-field-label">Notes</span>
                                            <textarea name="notes" rows="2" placeholder="Main image, packaging, angle...">{{ $image->notes }}</textarea>
                                        </label>
                                        <label class="sw-checkbox">
                                            <input type="checkbox" name="is_primary" value="1" @checked($image->is_primary)>
                                            <span>Primary image</span>
                                        </label>
                                        <div class="sw-image-manage-actions">
                                            <button type="submit" class="sw-opt-save">Save</button>
                                        </div>
                                    </form>

                                    <form method="POST" action="{{ route('images.destroy', $image) }}" onsubmit="return confirm('Delete this image?')" class="sw-image-delete-form">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="sw-btn-danger">Delete image</button>
                                    </form>
                                </details>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>
        </aside>

        {{-- ═══ MAIN AREA ═══ --}}
        <main class="sw-main">
            @php
                $directSkuImageCount = $style->skus->sum(fn ($sku) => $sku->images->count());
                $missingSkuImages = $style->skus->filter(fn ($sku) => $sku->images->isEmpty())->count();
            @endphp

            <details class="sw-publish-dock {{ $publishedFamily ? 'is-published' : 'is-draft' }}" data-sw-fragment="publish">
                <summary class="sw-publish-dock-main">
                    <div class="sw-publish-dock-title">
                        <span class="sw-publish-state-dot" aria-hidden="true"></span>
                        <div>
                            <h2>Real product</h2>
                            <p>
                                @if ($publishedFamily)
                                    Published family with {{ $publishedFamily->products_count }} sellable product{{ $publishedFamily->products_count === 1 ? '' : 's' }}.
                                @else
                                    Ready to create final POS, inventory and ecommerce product records.
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="sw-publish-dock-chips" aria-label="Publishing status">
                        <span>{{ $style->skus->count() }} SKU{{ $style->skus->count() === 1 ? '' : 's' }}</span>
                        <span>{{ $productType->name }}</span>
                        <span>{{ $style->material_name ?: 'Material missing' }}</span>
                        <span class="{{ $missingSkuImages > 0 ? 'needs-work' : 'is-ready' }}">
                            {{ $directSkuImageCount }} / {{ $style->skus->count() }} images
                        </span>
                    </div>

                    <span class="sw-publish-open-cue">
                        <span>Manage</span>
                    </span>
                </summary>

                <div class="sw-publish-dock-body">
                    <div class="sw-publish-body-top">
                        <div>
                            <h3>Publish to final product records</h3>
                            <p>Creates or updates the POS, inventory, ecommerce and export-ready product family.</p>
                        </div>

                        <div class="sw-publish-dock-actions">
                            @if ($publishedFamily)
                                <a href="{{ route('retail-products.families.show', $publishedFamily) }}" class="sw-publish-action-secondary">
                                    View real products
                                </a>
                            @endif
                            <form method="POST" action="{{ route('brand-catalogue.styles.publish-products', $style) }}">
                                @csrf
                                <button type="submit" class="sw-publish-action-primary" @disabled($style->skus->isEmpty())>
                                    {{ $publishedFamily ? 'Republish' : 'Publish' }}
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="sw-publish-setup">
                        <div class="sw-publish-setup-heading">
                            <span>Publishing setup</span>
                            <small>Name, scaffold, material and source</small>
                        </div>

                        <form method="POST" action="{{ route('brand-catalogue.styles.update', $style) }}" class="sw-publish-setup-form">
                            @csrf
                            @method('PATCH')
                            <label class="sw-field">
                                <span class="sw-field-label">Final product family name</span>
                                <input type="text" name="name" value="{{ $style->name }}" required>
                            </label>
                            <label class="sw-field">
                                <span class="sw-field-label">Product type / scaffold</span>
                                <select name="brand_catalogue_product_type_id" required>
                                    @foreach ($productTypeOptions as $typeOption)
                                        <option value="{{ $typeOption->id }}" @selected((int) $style->brand_catalogue_product_type_id === (int) $typeOption->id)>
                                            {{ $typeOption->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="sw-field">
                                <span class="sw-field-label">Material / filter</span>
                                <input type="text" name="material_name" value="{{ $style->material_name ?: 'Synthetic Hair' }}" list="style-material-options" required>
                            </label>
                            <label class="sw-field">
                                <span class="sw-field-label">Source URL</span>
                                <input type="url" name="url" value="{{ $style->url }}" placeholder="https://...">
                            </label>
                            <label class="sw-field sw-publish-field-wide">
                                <span class="sw-field-label">Internal note</span>
                                <textarea name="note" rows="2">{{ $style->note }}</textarea>
                            </label>
                            <input type="hidden" name="sort_order" value="{{ $style->sort_order }}">
                            <button type="submit" class="sw-publish-save">Save setup</button>
                        </form>
                    </div>
                </div>
            </details>

            {{-- ── Variant Groups Section ── --}}
            <details class="sw-section-accordion sw-variants-section" data-sw-fragment="variants">
                <summary class="sw-section-accordion-summary">
                    <div class="sw-section-accordion-title">
                        <h2 class="sw-section-title">Variant Option Pools</h2>
                        <span class="sw-count-pill">{{ $style->variants->count() }}</span>
                    </div>
                    <p class="sw-section-accordion-hint">
                        @if ($style->variants->isEmpty())
                            Add colour, length, pack size, etc.
                        @else
                            {{ $style->variants->sum(fn ($v) => $v->options->count()) }} options · manage in Sellable SKUs below
                        @endif
                    </p>
                    <svg class="sw-section-accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                    </svg>
                </summary>

                <section class="sw-section sw-variants-section-body">
                {{-- Add Variant Group --}}
                <form method="POST" action="{{ route('brand-catalogue.variants.store', $style) }}" class="sw-quick-add">
                    @csrf
                    <header class="sw-quick-add-head">
                        <span class="sw-quick-add-icon" aria-hidden="true">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </span>
                        <div class="sw-quick-add-intro">
                            <strong class="sw-quick-add-title">Add variant group</strong>
                            <p class="sw-quick-add-sub">Define an axis such as colour, length, or pack size.</p>
                        </div>
                    </header>
                    <div class="sw-quick-add-grid">
                        <label class="sw-field sw-quick-add-field sw-quick-add-field--name">
                            <span class="sw-field-label">Group name</span>
                            <input type="text"
                                   name="name"
                                   value="{{ old('name') }}"
                                   placeholder="e.g. Colour, Length, Pack size"
                                   required
                                   autocomplete="off">
                        </label>
                        <label class="sw-field sw-quick-add-field sw-quick-add-field--type">
                            <span class="sw-field-label">Value type</span>
                            <select name="variant_type" required>
                                @foreach ($variantTypeOptions as $opt)
                                    <option value="{{ $opt['value'] }}" @selected(old('variant_type') === $opt['value'])>{{ $opt['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <details class="sw-quick-add-link" @if(old('url')) open @endif>
                        <summary class="sw-quick-add-link-summary">Reference link <span class="sw-quick-add-optional">optional</span></summary>
                        <label class="sw-field sw-quick-add-field">
                            <span class="sw-field-label">URL</span>
                            <input type="url"
                                   name="url"
                                   value="{{ old('url') }}"
                                   placeholder="https://…"
                                   inputmode="url"
                                   autocomplete="url">
                        </label>
                    </details>
                    <div class="sw-quick-add-actions">
                        <button type="submit" class="sw-btn sw-btn-primary sw-quick-add-submit">Add group</button>
                    </div>
                </form>

                @if ($style->variants->isEmpty())
                    <div class="sw-empty">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                        <p>No variant groups yet — add one above to define colour, length, pack count, etc.</p>
                    </div>
                @else
                    <div class="sw-variant-stack">
                        @foreach ($style->variants as $variant)
                            <div class="sw-variant-card" id="vg-{{ $variant->id }}">
                                {{-- Header --}}
                                <div class="sw-variant-header">
                                    <div class="sw-variant-icon sw-vicon-{{ $variant->variant_type }}">
                                        @switch($variant->variant_type)
                                            @case('measurement')
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12h20M6 8v8M10 10v4M14 10v4M18 8v8"/></svg>
                                            @break
                                            @case('colour_name') @case('colour_code')
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="13.5" cy="6.5" r="4.5"/><circle cx="17.5" cy="15.5" r="4.5"/><circle cx="8.5" cy="15.5" r="4.5"/></svg>
                                            @break
                                            @case('count')
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
                                            @break
                                            @case('short_code')
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                                            @break
                                            @default
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                        @endswitch
                                    </div>
                                    <div class="sw-variant-title">
                                        <h3>{{ $variant->name }}</h3>
                                        <span class="sw-variant-meta">
                                            <span class="bc-vtype-badge bc-vtype-{{ $variant->variant_type }}">{{ $variant->variant_type === 'count' ? 'pack count' : str_replace('_', ' ', $variant->variant_type) }}</span>
                                            <span class="sw-opt-count">{{ $variant->options->count() }} option{{ $variant->options->count() === 1 ? '' : 's' }}</span>
                                        </span>
                                    </div>
                                    <div class="sw-variant-actions">
                                        <details class="sw-inline-edit" onclick="event.stopPropagation()">
                                            <summary class="sw-edit-btn" title="Edit group">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </summary>
                                            <div class="sw-inline-edit-panel">
                                                <form method="POST" action="{{ route('brand-catalogue.variants.update', $variant) }}" class="sw-inline-form">
                                                    @csrf
                                                    @method('PATCH')
                                                    <label class="sw-field"><span class="sw-field-label">Name</span><input type="text" name="name" value="{{ $variant->name }}" required></label>
                                                    <label class="sw-field">
                                                        <span class="sw-field-label">Type</span>
                                                        <select name="variant_type">
                                                            @foreach ($variantTypeOptions as $opt)
                                                                <option value="{{ $opt['value'] }}" @selected($variant->variant_type === $opt['value'])>{{ $opt['label'] }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label class="sw-field"><span class="sw-field-label">Link</span><input type="url" name="url" value="{{ $variant->url }}" placeholder="https://..."></label>
                                                    <label class="sw-field sw-field-short"><span class="sw-field-label">Sort</span><input type="number" name="sort_order" value="{{ $variant->sort_order }}" min="0"></label>
                                                    <button type="submit" class="sw-btn sw-btn-primary sw-btn-sm">Save</button>
                                                </form>
                                            </div>
                                        </details>
                                        @if ($variant->options->isEmpty())
                                            <form method="POST"
                                                action="{{ route('brand-catalogue.variants.destroy', $variant) }}"
                                                class="sw-delete-group-form"
                                                onclick="event.stopPropagation()"
                                                onsubmit="return confirm('Delete empty variant group &quot;{{ $variant->name }}&quot;?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="sw-delete-group-btn" title="Delete empty group" aria-label="Delete empty group">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                </button>
                                            </form>
                                        @endif
                                        <span class="sw-chevron">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                                        </span>
                                    </div>
                                </div>

                                {{-- Body (options) --}}
                                <div class="sw-variant-body">
                                    @if ($variant->options->isNotEmpty())
                                        <div class="sw-opt-list">
                                            @foreach ($variant->options as $option)
                                                @php
                                                    $optPrimary = $option->primaryImage();
                                                @endphp
                                                <details class="sw-opt-card" data-opt-id="{{ $option->id }}">
                                                    <summary class="sw-opt-summary">
                                                        {{-- Thumbnail --}}
                                                        <div class="sw-opt-thumb">
                                                            @if ($optPrimary && $optPrimary->displayUrl())
                                                            <button type="button"
                                                                        class="sw-image-thumb"
                                                                        data-picture-preview-trigger
                                                                        data-image-url="{{ $optPrimary->displayUrl() }}"
                                                                        data-picture-id="{{ $option->label }} - {{ $optPrimary->image_role }}"
                                                                        data-media-id="{{ $optPrimary->id }}"
                                                                        data-image-target-type="brand_catalogue_variant_option"
                                                                        data-image-target-id="{{ $option->id }}"
                                                                        data-image-delete-url="{{ route('images.destroy', $optPrimary) }}"
                                                                        data-image-replace-url="{{ route('images.replace', $optPrimary) }}"
                                                                        data-image-role="{{ $optPrimary->image_role }}"
                                                                        data-image-usage="{{ $optPrimary->usage_context }}"
                                                                        data-image-source-label="{{ $optPrimary->source_label }}"
                                                                        data-image-notes="{{ $optPrimary->notes }}">
                                                                    <img src="{{ $optPrimary->displayUrl() }}" alt="{{ $option->label }}">
                                                                </button>
                                                            @else
                                                                <button
                                                                    type="button"
                                                                    class="sw-image-thumb sw-image-thumb-missing"
                                                                    data-sw-option-add-image
                                                                    aria-label="Add image for {{ e($option->label) }}"
                                                                    title="Add image">
                                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.45"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                                                    <span class="sw-missing-image-plus">+</span>
                                                                </button>
                                                            @endif
                                                        </div>
                                                        <span class="sw-opt-label">{{ $option->label }}</span>
                                                        @if ($option->value && $option->value !== $option->label)
                                                            <code class="sw-chip-code">{{ $option->value }}</code>
                                                        @endif
                                                        <span class="sw-opt-badges">
                                                            @if ($option->images->isNotEmpty())
                                                                <span class="sw-opt-img-badge">{{ $option->images->count() }} img</span>
                                                            @endif
                                                            <span class="sw-opt-sort-badge">#{{ $option->sort_order }}</span>
                                                        </span>
                                                        <svg class="sw-opt-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                                                        <form method="POST"
                                                              action="{{ route('brand-catalogue.variant-options.destroy', $option) }}"
                                                              class="sw-opt-summary-del-form"
                                                              onsubmit="return confirm({{ json_encode('Delete "'.$option->label.'" and all its assets?') }})">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit"
                                                                    class="sw-opt-del-btn sw-opt-summary-del-btn"
                                                                    title="Delete option"
                                                                    aria-label="Delete {{ e($option->label) }}"
                                                                    onclick="event.stopPropagation()">&times;</button>
                                                        </form>
                                                    </summary>

                                                    <div class="sw-opt-panel">
                                                        {{-- Edit fields --}}
                                                        <form method="POST" action="{{ route('brand-catalogue.variant-options.update', $option) }}" class="sw-opt-form">
                                                            @csrf
                                                            @method('PATCH')
                                                            <div class="sw-opt-fields">
                                                                <label class="sw-field"><span class="sw-field-label">Label</span><input type="text" name="label" value="{{ $option->label }}" required></label>
                                                                <label class="sw-field"><span class="sw-field-label">Value</span><input type="text" name="value" value="{{ $option->value ?? $option->label }}" placeholder="Matches label"></label>
                                                                <label class="sw-field sw-field-short"><span class="sw-field-label">Sort</span><input type="number" name="sort_order" value="{{ $option->sort_order }}" min="0"></label>
                                                                <button type="submit" class="sw-opt-save">Save</button>
                                                            </div>
                                                        </form>

                                                        {{-- Assets section --}}
                                                        <div class="sw-opt-assets">
                                                            <div class="sw-opt-assets-head">
                                                                <span class="sw-field-label">Assets</span>
                                                                @if ($option->images->isNotEmpty())
                                                                    <span class="sw-opt-img-badge">{{ $option->images->count() }}</span>
                                                                @endif
                                                            </div>

                                                            @if ($option->images->isNotEmpty())
                                                                <div class="sw-opt-asset-grid">
                                                                    @foreach ($option->images as $image)
                                                                        @php
                                                                            $imgUrl = $image->displayUrl();
                                                                        @endphp
                                                                        <div class="sw-opt-asset-item">
                                                                            <div class="sw-opt-asset-preview">
                                                                                @if ($imgUrl)
                                                                                    <button type="button"
                                                                                        class="sw-image-thumb"
                                                                                        data-picture-preview-trigger
                                                                                        data-image-url="{{ $imgUrl }}"
                                                                                        data-picture-id="{{ $option->label }} - {{ $image->image_role }}{{ $image->is_primary ? ' (primary)' : '' }}"
                                                                                        data-media-id="{{ $image->id }}"
                                                                                        data-image-target-type="brand_catalogue_variant_option"
                                                                                        data-image-target-id="{{ $option->id }}"
                                                                                        data-image-delete-url="{{ route('images.destroy', $image) }}"
                                                                                        data-image-replace-url="{{ route('images.replace', $image) }}"
                                                                                        data-image-role="{{ $image->image_role }}"
                                                                                        data-image-usage="{{ $image->usage_context }}"
                                                                                        data-image-source-label="{{ $image->source_label }}"
                                                                                        data-image-notes="{{ $image->notes }}">
                                                                                        <img src="{{ $imgUrl }}" alt="{{ $image->notes ?: $option->label }}">
                                                                                    </button>
                                                                                @else
                                                                                    <button type="button"
                                                                                        class="sw-image-thumb"
                                                                                        data-picture-preview-trigger
                                                                                        data-image-url=""
                                                                                        data-picture-id="{{ $option->label }} - {{ $image->image_role }}"
                                                                                        data-media-id="{{ $image->id }}"
                                                                                        data-image-target-type="brand_catalogue_variant_option"
                                                                                        data-image-target-id="{{ $option->id }}"
                                                                                        data-image-delete-url="{{ route('images.destroy', $image) }}"
                                                                                        data-image-replace-url="{{ route('images.replace', $image) }}"
                                                                                        data-image-role="{{ $image->image_role }}"
                                                                                        data-image-usage="{{ $image->usage_context }}"
                                                                                        data-image-source-label="{{ $image->source_label }}"
                                                                                        data-image-notes="{{ $image->notes }}">
                                                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.3"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                                                                    </button>
                                                                                @endif
                                                                            </div>
                                                                            <div class="sw-opt-asset-info">
                                                                                <span class="sw-opt-asset-role">{{ $image->image_role }}@if($image->is_primary) ★@endif</span>
                                                                                @if ($image->notes)
                                                                                    <span class="sw-opt-asset-note">{{ $image->notes }}</span>
                                                                                @endif
                                                                            </div>
                                                                            <form method="POST" action="{{ route('images.destroy', $image) }}" onsubmit="return confirm('Delete this image?')" class="sw-opt-asset-del">
                                                                                @csrf
                                                                                @method('DELETE')
                                                                                <button type="submit" class="sw-opt-del-btn" title="Delete">&times;</button>
                                                                            </form>
                                                                        </div>
                                                                    @endforeach
                                                                </div>
                                                            @endif

                                                            {{-- Add asset form --}}
                                                            <div class="sw-opt-asset-form">
                                                                @include('brand-catalogue._catalogue-image-add-form', [
                                                                    'targetType' => 'brand_catalogue_variant_option',
                                                                    'targetId' => $option->id,
                                                                    'imageRoleOptions' => $optionImageRoleOptions,
                                                                    'defaultImageRole' => 'main',
                                                                     'defaultImageSource' => 'paste',
                                                                     'primaryLabel' => 'Set as primary option image',
                                                                     'addButtonLabel' => 'Add asset',
                                                                 ])
                                                            </div>
                                                        </div>

                                                        {{-- Delete option --}}
                                                        <div class="sw-opt-danger-zone">
                                                            <form method="POST" action="{{ route('brand-catalogue.variant-options.destroy', $option) }}" onsubmit="return confirm('Delete &quot;{{ $option->label }}&quot; and all its assets?')">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button type="submit" class="sw-btn-danger">Delete option</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </details>
                                            @endforeach
                                        </div>
                                    @endif

                                    {{-- Add option --}}
                                    <form method="POST" action="{{ route('brand-catalogue.variant-options.store', $variant) }}" class="sw-option-add">
                                        @csrf
                                        <span class="sw-option-add-icon">+</span>
                                        <input type="text" name="label" placeholder="Add option..." required class="sw-opt-input sw-opt-input-label">
                                        <input type="text" name="value" placeholder="Matches label" class="sw-opt-input sw-opt-input-value">
                                        <input type="number" name="sort_order" value="0" min="0" class="sw-opt-input sw-opt-input-sort">
                                        <button type="submit" class="sw-opt-save">Add</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
                </section>
            </details>

            {{-- ── Sellable SKUs Section ── --}}
            @php
                $skuListHint = $style->skus->isEmpty()
                    ? 'Add variant options, then create SKUs'
                    : ($publishedFamily
                        ? number_format($publishedFamily->products_count).' in retail'
                        : 'Ready to publish to retail');
            @endphp
            <details class="sw-section-accordion sw-sku-list-section" data-sw-fragment="skus" open>
                <summary class="sw-section-accordion-summary">
                    <div class="sw-section-accordion-title">
                        <h2 class="sw-section-title">Sellable SKUs</h2>
                        <span class="sw-count-pill sw-count-sku">{{ $style->skus->count() }}</span>
                    </div>
                    <p class="sw-section-accordion-hint">{{ $skuListHint }}</p>
                    <svg class="sw-section-accordion-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                    </svg>
                </summary>
                <div class="sw-sku-list-inner">
                <details class="sw-sku-tools" @if ($style->skus->isEmpty()) open @endif>
                    <summary class="sw-sku-tools-summary">
                        <span>Search &amp; publish</span>
                        <em>{{ $style->skus->isEmpty() ? 'No SKUs yet' : 'Find a SKU or sync to retail' }}</em>
                    </summary>
                    <div class="sw-sku-tools-body">
                        @if (! $style->skus->isEmpty())
                            <label class="sw-sku-search">
                                <span class="sw-sku-search-label">Search</span>
                                <input type="search"
                                       class="sw-sku-search-input"
                                       name="sw_sku_filter"
                                       data-sw-sku-filter
                                       placeholder="Name, code, variant…"
                                       autocomplete="off"
                                       enterkeyhint="search">
                                <span class="sw-sku-search-meta" data-sw-sku-filter-meta aria-live="polite"></span>
                            </label>
                        @endif
                        <div class="sw-sku-publish-row">
                            @if ($publishedFamily)
                                <a href="{{ route('retail-products.families.show', $publishedFamily) }}" class="sw-btn sw-btn-sm sw-sku-view-retail">
                                    View retail ({{ $publishedFamily->products_count }})
                                </a>
                            @endif
                            <form method="POST" action="{{ route('brand-catalogue.styles.publish-products', $style) }}" class="sw-sku-publish-form">
                                @csrf
                                <button type="submit" class="sw-btn sw-btn-primary sw-btn-sm" @disabled($style->skus->isEmpty())>
                                    {{ $publishedFamily ? 'Republish' : 'Publish to retail' }}
                                </button>
                            </form>
                        </div>
                    </div>
                </details>

                {{-- Add SKU --}}
                <details class="sw-add-drawer sw-add-drawer-lg sw-sku-add-drawer">
                    <summary class="sw-add-trigger-lg">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Add SKU
                    </summary>
                    <form method="POST" action="{{ route('brand-catalogue.skus.store', $style) }}" class="sw-sku-form">
                        @csrf
                        <div class="sw-sku-grid">
                            <label class="sw-field">
                                <span class="sw-field-label">Sellable name</span>
                                <input type="text" name="name" placeholder="e.g. Ultra Braid 82 inch / 1B" required>
                            </label>
                            <label class="sw-field">
                                <span class="sw-field-label">SKU code</span>
                                <input type="text" name="sku_code"
                                       placeholder="{{ $stylePrefix ? $stylePrefix.'?' : 'Auto' }}">
                                <small class="sw-field-hint">
                                    Leave blank to auto-generate using the unified scheme
                                    @if ($stylePrefix)
                                        — next: <code>{{ $stylePrefix }}?</code>
                                    @endif
                                </small>
                            </label>
                            <label class="sw-field">
                                <span class="sw-field-label">Barcode</span>
                                <input type="text" name="barcode" placeholder="Optional">
                            </label>
                            <label class="sw-field sw-field-short">
                                <span class="sw-field-label">Sort</span>
                                <input type="number" name="sort_order" value="0" min="0">
                            </label>
                        </div>

                        @if ($style->variants->isNotEmpty())
                            <div class="sw-sku-options-row">
                                @foreach ($style->variants as $variant)
                                    <label class="sw-field">
                                        <span class="sw-field-label">{{ $variant->name }}</span>
                                        <select name="variant_option_ids[{{ $variant->id }}]" @disabled($variant->options->isEmpty())>
                                            <option value="">— select —</option>
                                            @foreach ($variant->options as $option)
                                                <option value="{{ $option->id }}">{{ $option->label }}@if($option->value && $option->value !== $option->label) ({{ $option->value }})@endif</option>
                                            @endforeach
                                        </select>
                                    </label>
                                @endforeach
                            </div>
                        @endif

                        <div class="sw-sku-extras">
                            <label class="sw-field"><span class="sw-field-label">Source URL</span><input type="url" name="url" placeholder="https://..."></label>
                            <label class="sw-field"><span class="sw-field-label">Description</span><textarea name="description" rows="3" placeholder="Product description for this SKU..."></textarea></label>
                            <label class="sw-field"><span class="sw-field-label">Note</span><textarea name="note" rows="2" placeholder="Internal note..."></textarea></label>
                        </div>
                        <button type="submit" class="sw-btn sw-btn-primary">Add SKU</button>
                    </form>
                </details>

                @if ($style->skus->isEmpty())
                    <div class="sw-empty">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="12" y1="10" x2="12" y2="18"/><line x1="8" y1="14" x2="16" y2="14"/></svg>
                        <p>No sellable SKUs yet. Add variant options above, then create combinations.</p>
                    </div>
                @else
                    {{-- SKU Table --}}
                    <div class="sw-sku-table-wrap">
                        <table class="sw-sku-table">
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    @foreach ($style->variants as $variant)
                                        <th scope="col">{{ $variant->name }}</th>
                                    @endforeach
                                    <th scope="col">Code</th>
                                    <th scope="col">Photos</th>
                                    <th class="sw-sku-actions-col" scope="col"><span class="sw-sku-actions-heading">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($style->skus as $sku)
                                    @php
                                        $selectedOptionIds = $sku->optionValues->pluck('id')->map(fn ($id) => (int) $id)->all();
                                        $sortedOpts = $sku->optionValues->sortBy(fn ($o) => sprintf('%04d:%s', $o->variant->sort_order, $o->label));
                                        $skuShowUrl = route('brand-catalogue.skus.show', [$catalogue, $brand, $line, $productType, $style, $sku]);
                                        $skuSearchBlob = strtolower(trim(implode(' ', array_filter([
                                            $sku->name,
                                            $sku->sku_code,
                                            $sku->barcode,
                                            $sortedOpts->pluck('label')->implode(' '),
                                        ]))));
                                        $skuPhotoCount = $sku->images->count();
                                    @endphp
                                    <tr class="sw-sku-row"
                                        data-href="{{ $skuShowUrl }}"
                                        data-sw-sku-search="{{ $skuSearchBlob }}"
                                        onclick="if (!event.target.closest('a, button, input, select, textarea, summary, details, label')) window.location = this.dataset.href;">
                                        <td class="sw-sku-name-cell" data-label="Name">
                                            <a href="{{ $skuShowUrl }}" class="sw-sku-link">
                                                <strong>{{ $sku->name }}</strong>
                                                @if ($sortedOpts->isNotEmpty())
                                                    <span class="sw-sku-card-variants">
                                                        @foreach ($sortedOpts as $opt)
                                                            <span class="sw-chip sw-chip-sm">{{ $opt->label }}</span>
                                                        @endforeach
                                                    </span>
                                                @endif
                                                <span class="sw-sku-mobile-meta">
                                                    @if ($sku->sku_code)
                                                        <code class="sw-chip-code">{{ $sku->sku_code }}</code>
                                                    @endif
                                                    @if ($skuPhotoCount > 0)
                                                        <span class="sw-img-count">{{ $skuPhotoCount }} photo{{ $skuPhotoCount === 1 ? '' : 's' }}</span>
                                                    @elseif ($sku->selectedOptionPrimaryImage())
                                                        <span class="sw-img-count">opt</span>
                                                    @endif
                                                </span>
                                                @if ($sku->description)
                                                    <span class="sw-sku-desc sw-sku-desc--desktop">{{ Str::limit($sku->description, 60) }}</span>
                                                @endif
                                                @if ($sku->note)
                                                    <span class="sw-sku-note sw-sku-note--desktop">{{ Str::limit($sku->note, 40) }}</span>
                                                @endif
                                            </a>
                                        </td>
                                        @foreach ($style->variants as $variant)
                                            @php
                                                $sel = $sku->optionValues->first(fn ($option) => (int) $option->variant_id === (int) $variant->id);
                                            @endphp
                                            <td class="sw-sku-variant-cell" data-label="{{ $variant->name }}">
                                                @if ($sel)
                                                    <span class="sw-chip sw-chip-sm">{{ $sel->label }}</span>
                                                @else
                                                    <span class="sw-sku-none">—</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="sw-sku-code-cell" data-label="Code">
                                            @if ($sku->sku_code)
                                                <code class="sw-chip-code">{{ $sku->sku_code }}</code>
                                            @else
                                                <span class="sw-sku-none">—</span>
                                            @endif
                                        </td>
                                        <td class="sw-sku-imgs-cell" data-label="Photos">
                                            @if ($sku->images->isNotEmpty())
                                                <span class="sw-img-count">{{ $sku->images->count() }}</span>
                                            @elseif ($sku->selectedOptionPrimaryImage())
                                                <span class="sw-img-count">opt</span>
                                            @else
                                                <span class="sw-sku-none">0</span>
                                            @endif
                                        </td>
                                        <td class="sw-sku-actions-cell" data-label="Actions">
                                            <div class="sw-sku-actions" role="group" aria-label="SKU row actions">
                                                <form method="POST"
                                                      action="{{ route('brand-catalogue.skus.destroy', $sku) }}"
                                                      class="sw-sku-action-form"
                                                      onsubmit="return confirm('Delete the sellable SKU &quot;{{ addslashes($sku->name) }}&quot;?\n\nThis cannot be undone.');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="sw-sku-action-btn sw-sku-action-delete"
                                                            title="Delete this sellable SKU"
                                                            aria-label="Delete sellable SKU {{ $sku->name }}">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                                                    </button>
                                                </form>
                                                <details class="sw-sku-edit-detail">
                                                    <summary class="sw-sku-action-btn sw-sku-action-edit" title="Edit SKU" aria-label="Edit SKU">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                    </summary>
                                                <div class="sw-sku-edit-panel">
                                                    <form method="POST" action="{{ route('brand-catalogue.skus.update', $sku) }}" class="sw-sku-form">
                                                        @csrf
                                                        @method('PATCH')
                                                        <div class="sw-sku-grid">
                                                            <label class="sw-field"><span class="sw-field-label">Name</span><input type="text" name="name" value="{{ $sku->name }}" required></label>
                                                            <label class="sw-field"><span class="sw-field-label">SKU code</span><input type="text" name="sku_code" value="{{ $sku->sku_code }}"></label>
                                                            <label class="sw-field"><span class="sw-field-label">Barcode</span><input type="text" name="barcode" value="{{ $sku->barcode }}"></label>
                                                            <label class="sw-field sw-field-short"><span class="sw-field-label">Sort</span><input type="number" name="sort_order" value="{{ $sku->sort_order }}" min="0"></label>
                                                        </div>
                                                        @if ($style->variants->isNotEmpty())
                                                            <div class="sw-sku-options-row">
                                                                @foreach ($style->variants as $variant)
                                                                    <label class="sw-field">
                                                                        <span class="sw-field-label">{{ $variant->name }}</span>
                                                                        <select name="variant_option_ids[{{ $variant->id }}]" @disabled($variant->options->isEmpty())>
                                                                            <option value="">— select —</option>
                                                                            @foreach ($variant->options as $option)
                                                                                <option value="{{ $option->id }}" @selected(in_array((int) $option->id, $selectedOptionIds, true))>{{ $option->label }}@if($option->value && $option->value !== $option->label) ({{ $option->value }})@endif</option>
                                                                            @endforeach
                                                                        </select>
                                                                    </label>
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                        <div class="sw-sku-extras">
                                                            <label class="sw-field"><span class="sw-field-label">Source URL</span><input type="url" name="url" value="{{ $sku->url }}" placeholder="https://..."></label>
                                                            <label class="sw-field"><span class="sw-field-label">Description</span><textarea name="description" rows="3" placeholder="Product description...">{{ $sku->description }}</textarea></label>
                                                            <label class="sw-field"><span class="sw-field-label">Note</span><textarea name="note" rows="2" placeholder="Internal note...">{{ $sku->note }}</textarea></label>
                                                        </div>
                                                        <button type="submit" class="sw-btn sw-btn-primary sw-btn-sm">Save SKU</button>
                                                    </form>

                                                    {{-- SKU Images --}}
                                                    <div class="sw-sku-images-section">
                                                        <div class="sw-section-label">
                                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                                            SKU Images
                                                            <span class="sw-level-badge sw-level-sku">SKU</span>
                                                        </div>
                                                        @if ($sku->images->isNotEmpty())
                                                            <div class="sw-image-grid">
                                                                @foreach ($sku->images as $img)
                                                                    <button type="button"
                                                                        class="sw-image-thumb"
                                                                        data-picture-preview-trigger
                                                                        data-image-url="{{ $img->displayUrl() }}"
                                                                        data-picture-id="{{ $sku->name }} - {{ $img->image_role }}{{ $img->is_primary ? ' (primary)' : '' }}"
                                                                        data-media-id="{{ $img->id }}"
                                                                        data-image-target-type="brand_catalogue_sku"
                                                                        data-image-target-id="{{ $sku->id }}"
                                                                        data-image-delete-url="{{ route('images.destroy', $img) }}"
                                                                        data-image-replace-url="{{ route('images.replace', $img) }}"
                                                                        data-image-role="{{ $img->image_role }}"
                                                                        data-image-usage="{{ $img->usage_context }}"
                                                                        data-image-source-label="{{ $img->source_label }}"
                                                                        data-image-notes="{{ $img->notes }}">
                                                                        @if ($img->displayUrl())
                                                                            <img src="{{ $img->displayUrl() }}" alt="">
                                                                        @else
                                                                            <span class="sw-image-placeholder-icon">
                                                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                                                            </span>
                                                                        @endif
                                                                        <span class="sw-image-role">{{ $img->image_role }}</span>
                                                                    </button>
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                        @include('brand-catalogue._catalogue-image-add-form', [
                                                            'targetType' => 'brand_catalogue_sku',
                                                            'targetId' => $sku->id,
                                                            'imageRoleOptions' => $skuImageRoleOptions,
                                                            'defaultImageRole' => 'variant',
                                                            'primaryLabel' => 'Set as primary SKU image',
                                                            'addButtonLabel' => 'Add SKU image',
                                                        ])
                                                        @if ($sku->images->isEmpty())
                                                            @php
                                                                $optionFallbackImage = $sku->selectedOptionPrimaryImage();
                                                            @endphp
                                                            @if ($optionFallbackImage)
                                                                <p class="sw-hint">No direct SKU images - currently falls back to selected option media.</p>
                                                            @else
                                                                <p class="sw-hint">No direct SKU images - currently falls back to style media.</p>
                                                            @endif
                                                        @endif
                                                    </div>

                                                    <form method="POST" action="{{ route('brand-catalogue.skus.destroy', $sku) }}" onsubmit="return confirm('Delete &quot;{{ $sku->name }}&quot;?')" class="sw-delete-row">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="sw-btn-danger">Delete SKU</button>
                                                    </form>
                                                </div>
                                            </details>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                </div>
            </details>

        </main>
    </div>

@php
    $catalogueImageRoleOptions = collect($styleImageRoleOptions)
        ->merge($optionImageRoleOptions)
        ->merge($skuImageRoleOptions)
        ->unique('value')
        ->values();

    $catalogueImageUsageContexts = [
        ['value' => 'all', 'label' => 'All'],
        ['value' => 'pos', 'label' => 'POS'],
        ['value' => 'ecommerce', 'label' => 'Ecommerce'],
        ['value' => 'inventory', 'label' => 'Inventory'],
        ['value' => 'admin', 'label' => 'Admin'],
    ];
@endphp

    <div class="rfm-lightbox-wrap" data-picture-preview-modal aria-hidden="true" hidden>
        <div class="pw-lightbox-overlay">
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
                                <p>Choose the purpose first, then add the new photo from camera, upload, URL, or paste.</p>
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
                                    <input type="hidden" name="mobile_capture_destination" value="catalogue">
                                    <input type="hidden" name="mobile_capture_target_type" value="">
                                    <input type="hidden" name="target_type" value="">
                                    <input type="hidden" name="target_id" value="{{ $style->id }}">
                                    <input type="hidden" name="uploaded_by" value="{{ auth()->id() }}">
                                    <input type="hidden" name="mobile_capture_target_id" value="" data-picture-preview-replace-mobile-target-id>

                                    <div class="rfm-media-purpose">
                                        <label>
                                            <span>Image purpose</span>
                                            <select name="image_role" required data-picture-preview-role>
                                                @foreach ($catalogueImageRoleOptions as $role)
                                                    <option value="{{ $role['value'] }}">{{ $role['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label>
                                            <span>Use on</span>
                                            <select name="usage_context" required data-picture-preview-usage>
                                                @foreach ($catalogueImageUsageContexts as $ctx)
                                                    <option value="{{ $ctx['value'] }}">{{ $ctx['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <small>These fields control the generated image name and export context when the replacement is saved.</small>
                                    </div>

                                    <div class="rfm-media-tabs" role="tablist" aria-label="Replacement image source">
                                        <button type="button" class="rfm-media-tab is-active" data-rfm-media-tab="camera" role="tab" aria-selected="true">Camera</button>
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
                                        <label class="rfm-media-inline-check">
                                            <input type="checkbox" name="is_primary" value="1">
                                            <span>Set as primary image</span>
                                        </label>
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
    </div>
@endsection
