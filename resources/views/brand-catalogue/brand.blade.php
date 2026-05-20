@extends('layouts.app')

@section('title', $brand->name)

@section('content')
    @include('brand-catalogue._page-label', ['label' => 'Brand Workspace', 'context' => $brand->name])

    @php($displayLines = $brand->lines->reject(fn ($line) => $line->is_default && $brand->lines->contains(fn ($candidate) => ! $candidate->is_default) && (int) $line->product_types_count === 0)->values())
    @php($namedLines = $brand->lines->filter(fn ($line) => ! $line->is_default)->values())
    @php($defaultLine = $brand->lines->firstWhere('is_default', true))
    @php($showProductTypesDirectly = $namedLines->isEmpty() && $defaultLine)
    @include('partials.catalogue-breadcrumb', [
        'items' => [
            ['label' => 'Brand Catalogue', 'url' => route('brand-catalogue.index')],
            ['label' => $catalogue->name, 'url' => route('brand-catalogue.show', $catalogue)],
            ['label' => $brand->name, 'current' => true],
        ],
    ])

    <div class="sr-hero">
        <div class="sr-hero-top">
            <div class="sr-hero-title-block">
                <h1 class="sr-hero-title">{{ $brand->name }}</h1>
                <div class="sr-hero-badges">
                    <span class="sr-badge sr-badge-accent">{{ $showProductTypesDirectly ? 'Brand' : 'Brand With Product Lines' }}</span>
                    <span class="sr-badge sr-badge-warn">{{ $catalogue->name }}</span>
                    @if ($brand->url)
                        <a href="{{ $brand->url }}" target="_blank" class="bc-link-badge">link</a>
                    @endif
                </div>
            </div>
            <button type="button" class="sr-edit-trigger" onclick="document.getElementById('brand-edit').toggleAttribute('open')">Edit</button>
        </div>
        <div class="sr-stats">
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $showProductTypesDirectly ? 0 : $displayLines->count() }}</span>
                <span class="sr-stat-label">product lines</span>
            </div>
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $brand->product_types_count }}</span>
                <span class="sr-stat-label">product types</span>
            </div>
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $brand->styles_count }}</span>
                <span class="sr-stat-label">styles</span>
            </div>
        </div>
    </div>

    <details id="brand-edit" class="sr-edit-drawer">
        <summary class="sr-edit-drawer-summary">Edit brand</summary>
        <form method="POST" action="{{ route('brand-catalogue.brands.update', $brand) }}" class="sr-edit-form">
            @csrf
            @method('PATCH')
            <div class="sr-edit-grid">
                <label><span>Name</span><input type="text" name="name" value="{{ $brand->name }}" required></label>
                <label><span>Sort</span><input type="number" name="sort_order" value="{{ $brand->sort_order }}" min="0"></label>
            </div>
            <label><span>Note</span><textarea name="note" rows="2">{{ $brand->note }}</textarea></label>
            <label><span>Link</span><input type="url" name="url" value="{{ $brand->url }}" placeholder="https://..."></label>
            <div class="button-row"><button type="submit" class="button button-primary">Save</button></div>
        </form>
    </details>

    @if ($namedLines->isNotEmpty())
        <details class="sr-edit-drawer">
            <summary class="sr-edit-drawer-summary">Remove product line layer</summary>
            <form method="POST" action="{{ route('brand-catalogue.brands.remove-master-brand', $brand) }}" class="sr-edit-form" onsubmit="return confirm('Remove the product line layer for {{ $brand->name }}? Product types and styles will be moved directly under the brand. No SKUs/products will be deleted.')">
                @csrf
                <p class="page-note">
                    This keeps all product types, styles, variants and SKUs, but removes the extra product-line step. Style names are prefixed with the old line name where needed so product identity is preserved.
                </p>
                <div class="button-row"><button type="submit" class="button button-primary">Remove product line layer</button></div>
            </form>
        </details>
    @endif

    @if ($showProductTypesDirectly)
        <form method="POST" action="{{ route('brand-catalogue.product-types.store', $defaultLine) }}" class="sr-add-bar">
            @csrf
            <div class="sr-add-bar-icon">+</div>
            <input type="text" name="name" placeholder="Product type..." required class="sr-add-input">
            <input type="url" name="url" placeholder="Link (https://...)" class="sr-add-note">
            <input type="number" name="sort_order" value="0" min="0" class="sr-add-sort" title="Sort order">
            <button type="submit" class="sr-add-btn">Add product type</button>
        </form>
    @else
        <form method="POST" action="{{ route('brand-catalogue.lines.store', $brand) }}" class="sr-add-bar">
            @csrf
            <div class="sr-add-bar-icon">+</div>
            <input type="text" name="name" placeholder="Product line / sub-brand..." required class="sr-add-input">
            <input type="url" name="url" placeholder="Link (https://...)" class="sr-add-note">
            <input type="number" name="sort_order" value="0" min="0" class="sr-add-sort" title="Sort order">
            <button type="submit" class="sr-add-btn">Add product line</button>
        </form>
    @endif

    @if ($showProductTypesDirectly)
        @if ($defaultLine->productTypes->isEmpty())
            <div class="sr-empty"><p>No product types yet - add one above</p></div>
        @else
            <div class="bc-card-list">
                @foreach ($defaultLine->productTypes as $productType)
                    <div class="bc-card-row">
                        <a href="{{ route('brand-catalogue.product-types.show', [$catalogue, $brand, $defaultLine, $productType]) }}" class="bc-card-link">
                            <span class="sr-node-order">{{ $productType->sort_order }}</span>
                            <div class="bc-card-body">
                                <span class="bc-card-name">{{ $productType->name }}</span>
                                <span class="bc-card-meta">
                                    {{ $productType->styles_count }} style{{ $productType->styles_count === 1 ? '' : 's' }}
                                    <span class="bc-vtype-badge bc-vtype-text">direct under brand</span>
                                </span>
                            </div>
                            <svg class="bc-card-arrow" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                        </a>
                        <button type="button" class="bc-card-edit-btn" onclick="this.parentElement.querySelector('.bc-card-edit').toggleAttribute('open')">Edit</button>
                        <details class="bc-card-edit">
                            <summary class="bc-card-edit-summary"></summary>
                            <form method="POST" action="{{ route('brand-catalogue.product-types.update', $productType) }}" class="sr-node-form">
                                @csrf
                                @method('PATCH')
                                <div class="sr-node-form-row">
                                    <label class="sr-node-field sr-node-field-name"><span>Name</span><input type="text" name="name" value="{{ $productType->name }}" required></label>
                                    <label class="sr-node-field sr-node-field-sort"><span>Sort</span><input type="number" name="sort_order" value="{{ $productType->sort_order }}" min="0"></label>
                                    <label class="sr-node-field sr-node-field-note"><span>Note</span><input type="text" name="note" value="{{ $productType->note }}" placeholder="Optional"></label>
                                    <label class="sr-node-field sr-node-field-url"><span>Link</span><input type="url" name="url" value="{{ $productType->url }}" placeholder="https://..."></label>
                                    <button type="submit" class="sr-node-save">Save</button>
                                </div>
                            </form>
                            <form method="POST" action="{{ route('brand-catalogue.product-types.destroy', $productType) }}" class="bc-delete-form" onsubmit="return confirm('Delete &quot;{{ $productType->name }}&quot; and all its styles?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="bc-delete-btn">Delete product type</button>
                            </form>
                        </details>
                    </div>
                @endforeach
            </div>
        @endif
    @elseif ($displayLines->isEmpty())
        <div class="sr-empty"><p>No product lines yet - add one above</p></div>
    @else
        {{-- Bulk action bar (hidden until checkboxes are ticked) --}}
        <div class="bc-bulk-bar" id="bulk-bar" hidden>
            <label class="bc-bulk-select-all">
                <input type="checkbox" id="bulk-select-all"> Select all
            </label>
            <span class="bc-bulk-count"><span id="bulk-count">0</span> selected</span>
            <form method="POST" action="{{ route('brand-catalogue.lines.bulk-destroy') }}" id="bulk-delete-form" onsubmit="return confirm('Delete ' + document.getElementById('bulk-count').textContent + ' line(s) and all their nested data?')">
                @csrf
                @method('DELETE')
                <div id="bulk-ids"></div>
                <button type="submit" class="bc-bulk-delete-btn">Delete selected</button>
            </form>
        </div>

        <div class="bc-card-list" id="lines-list">
            @foreach ($displayLines as $line)
                <div class="bc-card-row">
                    @unless ($line->is_default)
                        <label class="bc-card-checkbox" title="Select">
                            <input type="checkbox" class="bc-line-check" value="{{ $line->id }}" data-name="{{ $line->name }}">
                        </label>
                    @endunless
                    <a href="{{ route('brand-catalogue.lines.show', [$catalogue, $brand, $line]) }}" class="bc-card-link">
                        <span class="sr-node-order">{{ $line->sort_order }}</span>
                        <div class="bc-card-body">
                            <span class="bc-card-name">
                                {{ $line->name }}
                                @if ($line->is_default)
                                    <span class="bc-vtype-badge bc-vtype-text">default</span>
                                @endif
                            </span>
                            <span class="bc-card-meta">
                                {{ $line->product_types_count }} product type{{ $line->product_types_count === 1 ? '' : 's' }}
                                @if ($line->url)
                                    <span class="bc-card-url-hint">has link</span>
                                @endif
                                @if ($line->note)
                                    <span class="bc-card-note">{{ Str::limit($line->note, 30) }}</span>
                                @endif
                            </span>
                        </div>
                        <svg class="bc-card-arrow" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                    </a>
                    <button type="button" class="bc-card-edit-btn" onclick="this.parentElement.querySelector('.bc-card-edit').toggleAttribute('open')">Edit</button>
                    <details class="bc-card-edit">
                        <summary class="bc-card-edit-summary"></summary>
                        <form method="POST" action="{{ route('brand-catalogue.lines.update', $line) }}" class="sr-node-form">
                            @csrf
                            @method('PATCH')
                            <div class="sr-node-form-row">
                                <label class="sr-node-field sr-node-field-name"><span>Name</span><input type="text" name="name" value="{{ $line->name }}" required></label>
                                <label class="sr-node-field sr-node-field-sort"><span>Sort</span><input type="number" name="sort_order" value="{{ $line->sort_order }}" min="0"></label>
                                <label class="sr-node-field sr-node-field-note"><span>Note</span><input type="text" name="note" value="{{ $line->note }}" placeholder="Optional"></label>
                                <label class="sr-node-field sr-node-field-url"><span>Link</span><input type="url" name="url" value="{{ $line->url }}" placeholder="https://..."></label>
                                <button type="submit" class="sr-node-save">Save</button>
                            </div>
                        </form>
                        @unless ($line->is_default)
                            <form method="POST" action="{{ route('brand-catalogue.lines.destroy', $line) }}" class="bc-delete-form" onsubmit="return confirm('Delete &quot;{{ $line->name }}&quot; and all nested product types/materials/styles?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="bc-delete-btn">Delete line</button>
                            </form>
                        @endunless
                    </details>
                </div>
            @endforeach
        </div>
    @endif

    <script>
    (() => {
        const bar = document.getElementById('bulk-bar');
        const countEl = document.getElementById('bulk-count');
        const idsEl = document.getElementById('bulk-ids');
        const selectAll = document.getElementById('bulk-select-all');
        const checks = document.querySelectorAll('.bc-line-check');
        if (!bar || !checks.length) return;

        const update = () => {
            const selected = document.querySelectorAll('.bc-line-check:checked');
            const count = selected.length;
            countEl.textContent = count;
            bar.hidden = count === 0;
            // Sync select-all state
            selectAll.checked = count === checks.length;
            selectAll.indeterminate = count > 0 && count < checks.length;
            // Build hidden inputs for form
            idsEl.innerHTML = '';
            selected.forEach(cb => {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = cb.value;
                idsEl.appendChild(inp);
            });
        };

        checks.forEach(cb => cb.addEventListener('change', update));
        selectAll.addEventListener('change', () => {
            checks.forEach(cb => cb.checked = selectAll.checked);
            update();
        });
    })();
    </script>
@endsection
