@extends('layouts.app')

@section('title', $pictureId)

@section('content')
    @php($isMappedSource = ($dataSource ?? 'observed') === 'mapped')

    <section class="picture-review-page">
        <aside class="picture-review-sidebar">
            <article class="picture-review-panel picture-review-panel-tight">
                <div class="picture-review-titlebar">
                    <div>
                        <p class="eyebrow">Picture Review</p>
                        <h2>{{ $pictureId }}</h2>
                    </div>

                    <div class="button-row">
                        <a href="{{ route('pictures.index') }}" class="button">Back</a>
                        @unless ($isMappedSource)
                            <a href="{{ route('dashboard', ['search' => $pictureId]) }}" class="button">Rows</a>
                        @endunless
                    </div>
                </div>

                <div class="brand-chip-row">
                    <span class="pill">{{ number_format($stats['products']) }} products</span>
                    <span class="pill">{{ number_format($stats['brands']) }} brands</span>
                    <span class="pill">{{ number_format($stats['categories']) }} categories</span>
                    @if ($isMappedSource)
                        <span class="pill">Mapped source</span>
                    @endif
                </div>
            </article>

            <article class="picture-review-panel picture-review-image-panel">
                @if ($imageUrl)
                    <button
                        type="button"
                        class="picture-review-image-trigger"
                        data-picture-preview-trigger
                        data-picture-id="{{ $pictureId }}"
                        data-image-url="{{ $imageUrl }}"
                        aria-haspopup="dialog"
                        aria-controls="picture-preview-modal"
                    >
                        <img src="{{ $imageUrl }}" alt="{{ $pictureId }} local shop picture" class="picture-review-image">
                    </button>
                @else
                    <div class="product-photo-missing picture-review-missing">
                        <span>No local photo found</span>
                        <small>{{ $pictureId }}</small>
                    </div>
                @endif

                <div class="picture-review-image-actions">
                    @if ($imageUrl)
                        <button
                            type="button"
                            class="button button-primary"
                            data-picture-preview-trigger
                            data-picture-id="{{ $pictureId }}"
                            data-image-url="{{ $imageUrl }}"
                            aria-haspopup="dialog"
                            aria-controls="picture-preview-modal"
                        >
                            Open full picture
                        </button>
                    @endif
                </div>
            </article>

            <article class="picture-review-panel picture-review-help">
                <p class="helper-title">Editing guide</p>
                @if ($isMappedSource)
                    <p>This picture is currently linked from the restored JSON mapping. Review the linked products here while we rebuild the editable import layer.</p>
                @else
                    <p>Check the row against the photo. Edit the product name, brand, real brand, line, and category as needed.</p>
                @endif
            </article>
        </aside>

        <section class="picture-review-main">
            <article class="picture-review-panel picture-review-panel-tight">
                <div class="picture-review-titlebar">
                    <div>
                        <p class="eyebrow">{{ $isMappedSource ? 'Mapped Products' : 'Validation Rows' }}</p>
                        <h3>{{ $isMappedSource ? 'Linked products in '.$pictureId : 'Observed rows in '.$pictureId }}</h3>
                    </div>
                    <p class="picture-review-count">
                        {{ number_format($isMappedSource ? $stats['rows'] : $rows->count()) }}
                        {{ $isMappedSource ? 'linked rows' : 'editable rows' }}
                    </p>
                </div>
            </article>

            <div class="stack-list picture-review-rows">
                @if ($isMappedSource)
                    @foreach ($mappedPicture->product_entries as $entry)
                        <article class="observed-row-card observed-edit-card">
                            <div class="observed-edit-head">
                                <div>
                                    <p class="product-card-kicker">Mapped product</p>
                                    <h4>{{ $entry->product_name }}</h4>
                                    <p class="observed-edit-meta">{{ $entry->brand_name !== '' ? $entry->brand_name : 'No brand mapped' }}</p>
                                </div>

                                <div class="brand-chip-row">
                                    <span class="brand-chip brand-chip-soft">{{ $entry->category_name ?: 'Category unresolved' }}</span>
                                    @if (! empty($entry->subcategory))
                                        <span class="brand-chip">{{ $entry->subcategory }}</span>
                                    @endif
                                    @if (! empty($entry->confidence))
                                        <span class="brand-chip brand-chip-soft">Confidence {{ $entry->confidence }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="observed-edit-grid">
                                <label class="observed-edit-product">
                                    <span>Product name</span>
                                    <input type="text" value="{{ $entry->product_name }}" readonly>
                                </label>

                                <label class="observed-edit-field">
                                    <span>Brand</span>
                                    <input type="text" value="{{ $entry->brand_name }}" readonly>
                                </label>

                                <label class="observed-edit-field">
                                    <span>Category</span>
                                    <input type="text" value="{{ $entry->category_name ?: 'Unresolved' }}" readonly>
                                </label>

                                <label class="observed-edit-field">
                                    <span>Subcategory</span>
                                    <input type="text" value="{{ $entry->subcategory ?: '' }}" readonly>
                                </label>

                                <label class="observed-edit-field">
                                    <span>Product ID</span>
                                    <input type="text" value="{{ $entry->product_id ?: '' }}" readonly>
                                </label>

                                <label class="observed-edit-field">
                                    <span>Confidence reason</span>
                                    <input type="text" value="{{ $entry->confidence_reason ?: '' }}" readonly>
                                </label>
                            </div>
                        </article>
                    @endforeach
                @else
                    @foreach ($rows as $row)
                        @php($isEditingRow = (string) old('editing_row_id') === (string) $row->id)

                        <article class="observed-row-card observed-edit-card">
                            <div class="observed-edit-head">
                                <div>
                                    <p class="product-card-kicker">Observation row</p>
                                    <h4>Row {{ $row->id }}</h4>
                                    <p class="observed-edit-meta">Order {{ $row->sort_order }} · {{ $row->category?->name ?? 'Unassigned' }}</p>
                                </div>

                                <div class="brand-chip-row">
                                    @if ($row->canonical_brand !== '')
                                        <a href="{{ route('real-brands.show', ['brand' => $row->canonical_brand]) }}" class="brand-chip brand-chip-button">Open real brand</a>
                                    @endif
                                    <span class="brand-chip brand-chip-soft">{{ $row->category?->name ?? 'Unassigned' }}</span>
                                </div>
                            </div>

                            <form method="POST" action="{{ route('observed-products.update', ['observedProduct' => $row]) }}" class="stack-form observed-edit-form">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="return_to" value="{{ route('pictures.show', ['pictureId' => $pictureId]) }}">
                                <input type="hidden" name="editing_row_id" value="{{ $row->id }}">

                                <div class="observed-edit-grid">
                                    <label class="observed-edit-product">
                                        <span>Product name</span>
                                        <input type="text" name="product_name" value="{{ $isEditingRow ? old('product_name', $row->product_name) : $row->product_name }}">
                                    </label>

                                    <label class="observed-edit-field">
                                        <span>Observed brand</span>
                                        <input type="text" name="brand" value="{{ $isEditingRow ? old('brand', $row->brand) : $row->brand }}" list="observed-brand-options">
                                    </label>

                                    <label class="observed-edit-field">
                                        <span>Real brand</span>
                                        <input type="text" name="canonical_brand" value="{{ $isEditingRow ? old('canonical_brand', $row->canonical_brand) : $row->canonical_brand }}" list="real-brand-options">
                                    </label>

                                    <label class="observed-edit-field">
                                        <span>Line / sub-brand</span>
                                        <input type="text" name="brand_line" value="{{ $isEditingRow ? old('brand_line', $row->brand_line) : $row->brand_line }}">
                                    </label>

                                    <label class="observed-edit-field">
                                        <span>Category</span>
                                        <select name="category_id">
                                            <option value="">Unassigned</option>
                                            @foreach ($categoryOptions as $category)
                                                <option value="{{ $category->id }}" @selected((string) ($isEditingRow ? old('category_id', $row->category_id) : $row->category_id) === (string) $category->id)>
                                                    {{ $category->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>

                                <div class="observed-edit-actions">
                                    <button type="submit" class="button button-primary">Save row</button>
                                </div>
                            </form>
                        </article>
                    @endforeach
                @endif
            </div>
        </section>
    </section>

    @unless ($isMappedSource)
        <datalist id="real-brand-options">
            @foreach ($brandOptions as $brand)
                <option value="{{ $brand }}"></option>
            @endforeach
        </datalist>

        <datalist id="observed-brand-options">
            @foreach ($observedBrandOptions as $brand)
                <option value="{{ $brand }}"></option>
            @endforeach
        </datalist>
    @endunless

    <div
        class="photo-carousel-modal"
        id="picture-preview-modal"
        data-picture-preview-modal
        aria-hidden="true"
        hidden
    >
        <button type="button" class="photo-carousel-backdrop" data-picture-preview-close aria-label="Close picture preview"></button>

        <section class="picture-lightbox" role="dialog" aria-modal="true" aria-labelledby="picture-preview-title">
            <div class="picture-lightbox-toolbar">
                <div class="picture-lightbox-meta">
                    <p class="eyebrow">Picture preview</p>
                    <h3 id="picture-preview-title" data-picture-preview-title>{{ $pictureId }}</h3>
                </div>

                <button type="button" class="picture-lightbox-close" data-picture-preview-close aria-label="Close picture preview">&times;</button>
            </div>

            <div class="picture-lightbox-media">
                <img src="" alt="" data-picture-preview-image>
            </div>
        </section>
    </div>
@endsection
