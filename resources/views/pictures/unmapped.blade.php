@extends('layouts.app')

@section('title', 'Unmapped Pictures')

@section('content')
    <section class="picture-page-head">
        <div>
            <p class="eyebrow">Picture Workspace</p>
            <h2>Unmapped Pictures</h2>
            <p class="page-note">Review local photo files that still have no linked products in the restored mapping.</p>
        </div>
        <div class="picture-head-stats">
            <span class="pill">{{ number_format($stats['pictures']) }} unmapped</span>
            <span class="pill">{{ number_format($stats['mapped']) }} mapped</span>
            <span class="pill">{{ number_format($stats['total_files']) }} total files</span>
        </div>
    </section>

    <article class="card picture-toolbar-card">
        <div class="card-head">
            <div>
                <h3>Review queue</h3>
                <p>These pictures exist on disk but are not linked to any product rows yet.</p>
            </div>
            <div class="button-row">
                <a href="{{ route('pictures.index') }}" class="button">Back to pictures</a>
            </div>
        </div>
    </article>

    <article class="card compact-card-shell picture-browser-shell">
        <div class="product-photo-grid product-photo-grid-compact picture-management-grid">
            @forelse ($pictures as $picture)
                <article class="product-photo-card picture-management-card">
                    <button
                        type="button"
                        class="product-photo-media picture-photo-trigger"
                        data-picture-preview-trigger
                        data-picture-id="{{ $picture->picture_id }}"
                        data-image-url="{{ $picture->image_url }}"
                        aria-haspopup="dialog"
                        aria-controls="picture-preview-modal"
                    >
                        <img src="{{ $picture->image_url }}" alt="{{ $picture->picture_id }} local shop picture">

                        <div class="picture-card-overlay">
                            <span class="picture-card-id">{{ $picture->picture_id }}</span>
                        </div>
                    </button>

                    <div class="product-photo-body picture-card-body">
                        <div class="product-photo-head">
                            <h4>{{ $picture->picture_id }}</h4>
                        </div>

                        <div class="picture-card-stats">
                            <span class="brand-chip brand-chip-soft">No linked products yet</span>
                        </div>
                    </div>
                </article>
            @empty
                <article class="card brand-empty-state">
                    <h3>No unmapped pictures found</h3>
                    <p>Every local picture file currently has at least one linked product.</p>
                </article>
            @endforelse
        </div>
    </article>

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
                    <h3 id="picture-preview-title" data-picture-preview-title>Picture</h3>
                </div>

                <button type="button" class="picture-lightbox-close" data-picture-preview-close aria-label="Close picture preview">&times;</button>
            </div>

            <div class="picture-lightbox-media">
                <img src="" alt="" data-picture-preview-image>
            </div>
        </section>
    </div>
@endsection
