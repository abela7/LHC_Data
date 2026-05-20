@extends('layouts.app')

@section('title', $line->name)

@section('content')
    @include('brand-catalogue._page-label', ['label' => 'Brand Line Workspace', 'context' => $line->name])

    @include('partials.catalogue-breadcrumb', [
        'items' => [
            ['label' => 'Brand Catalogue', 'url' => route('brand-catalogue.index')],
            ['label' => $catalogue->name, 'url' => route('brand-catalogue.show', $catalogue)],
            ['label' => $brand->name, 'url' => route('brand-catalogue.brands.show', [$catalogue, $brand])],
            ['label' => $line->name, 'current' => true],
        ],
    ])

    <div class="sr-hero">
        <div class="sr-hero-top">
            <div class="sr-hero-title-block">
                <h1 class="sr-hero-title">{{ $line->name }}</h1>
                <div class="sr-hero-badges">
                    <span class="sr-badge sr-badge-accent">{{ $brand->name }}</span>
                    <span class="sr-badge">{{ $line->is_default ? 'Default line' : 'Line / Collection' }}</span>
                    @if ($line->url)
                        <a href="{{ $line->url }}" target="_blank" class="bc-link-badge">link</a>
                    @endif
                </div>
            </div>
            <button type="button" class="sr-edit-trigger" onclick="document.getElementById('line-edit').toggleAttribute('open')">Edit</button>
        </div>
        <div class="sr-stats">
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $line->productTypes->count() }}</span>
                <span class="sr-stat-label">product types</span>
            </div>
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $line->productTypes->sum('styles_count') }}</span>
                <span class="sr-stat-label">total styles</span>
            </div>
        </div>
    </div>

    <details id="line-edit" class="sr-edit-drawer">
        <summary class="sr-edit-drawer-summary">Edit line / collection</summary>
        <form method="POST" action="{{ route('brand-catalogue.lines.update', $line) }}" class="sr-edit-form">
            @csrf
            @method('PATCH')
            <div class="sr-edit-grid">
                <label><span>Name</span><input type="text" name="name" value="{{ $line->name }}" required></label>
                <label><span>Sort</span><input type="number" name="sort_order" value="{{ $line->sort_order }}" min="0"></label>
            </div>
            <label><span>Note</span><textarea name="note" rows="2">{{ $line->note }}</textarea></label>
            <label><span>Link</span><input type="url" name="url" value="{{ $line->url }}" placeholder="https://..."></label>
            <div class="button-row"><button type="submit" class="button button-primary">Save</button></div>
        </form>
    </details>

    <form method="POST" action="{{ route('brand-catalogue.product-types.store', $line) }}" class="sr-add-bar">
        @csrf
        <div class="sr-add-bar-icon">+</div>
        <input type="text" name="name" placeholder="Product type..." required class="sr-add-input">
        <input type="url" name="url" placeholder="Link (https://...)" class="sr-add-note">
        <input type="number" name="sort_order" value="0" min="0" class="sr-add-sort" title="Sort order">
        <button type="submit" class="sr-add-btn">Add product type</button>
    </form>

    @if ($line->productTypes->isEmpty())
        <div class="sr-empty"><p>No product types yet - add one above</p></div>
    @else
        <div class="bc-card-list">
            @foreach ($line->productTypes as $productType)
                <div class="bc-card-row">
                    <a href="{{ route('brand-catalogue.product-types.show', [$catalogue, $brand, $line, $productType]) }}" class="bc-card-link">
                        <span class="sr-node-order">{{ $productType->sort_order }}</span>
                        <div class="bc-card-body">
                            <span class="bc-card-name">{{ $productType->name }}</span>
                            <span class="bc-card-meta">
                                {{ $productType->styles_count }} style{{ $productType->styles_count === 1 ? '' : 's' }}
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
@endsection
