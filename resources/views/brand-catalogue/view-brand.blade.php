@extends('layouts.app')

@section('title', $brand->name . ' - View')

@section('content')
    @include('brand-catalogue._page-label', ['label' => 'Brand View', 'context' => $brand->name])

    @php($displayLines = $brand->lines->reject(fn ($line) => $line->is_default && $brand->lines->contains(fn ($candidate) => ! $candidate->is_default) && (int) $line->product_types_count === 0)->values())
    @include('partials.catalogue-breadcrumb', [
        'items' => [
            ['label' => 'Brand Catalogue', 'url' => route('brand-catalogue.index')],
            ['label' => $catalogue->name, 'url' => route('brand-catalogue.view', $catalogue)],
            ['label' => $brand->name, 'current' => true],
        ],
    ])

    <div class="sr-hero">
        <div class="sr-hero-top">
            <div class="sr-hero-title-block"><h1 class="sr-hero-title">{{ $brand->name }}</h1></div>
            <div class="bcv-toggle">
                <a href="{{ route('brand-catalogue.view.brand', [$catalogue, $brand, 'view' => 'grid']) }}" class="bcv-toggle-btn {{ $viewMode === 'grid' ? 'is-active' : '' }}">Grid</a>
                <a href="{{ route('brand-catalogue.view.brand', [$catalogue, $brand, 'view' => 'list']) }}" class="bcv-toggle-btn {{ $viewMode === 'list' ? 'is-active' : '' }}">List</a>
            </div>
        </div>
        <div class="sr-stats">
            <div class="sr-stat"><span class="sr-stat-num">{{ $displayLines->count() }}</span><span class="sr-stat-label">lines</span></div>
            <div class="sr-stat"><span class="sr-stat-num">{{ $brand->product_types_count }}</span><span class="sr-stat-label">product types</span></div>
            <div class="sr-stat"><span class="sr-stat-num">{{ $brand->styles_count }}</span><span class="sr-stat-label">styles</span></div>
        </div>
    </div>

    @if ($viewMode === 'grid')
        <div class="bcv-grid">
            @foreach ($displayLines as $line)
                <a href="{{ route('brand-catalogue.view.line', [$catalogue, $brand, $line]) }}" class="bcv-card">
                    <div class="bcv-card-top">
                        <h3 class="bcv-card-name">{{ $line->name }}</h3>
                        @if ($line->is_default)
                            <span class="bc-vtype-badge bc-vtype-text">default</span>
                        @endif
                    </div>
                    <span class="bcv-card-count">{{ $line->product_types_count }} product type{{ $line->product_types_count === 1 ? '' : 's' }}</span>
                    <svg class="bcv-card-arrow" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </a>
            @endforeach
        </div>
    @else
        <div class="bcv-list">
            @foreach ($displayLines as $line)
                <a href="{{ route('brand-catalogue.view.line', [$catalogue, $brand, $line]) }}" class="bcv-list-row">
                    <span class="bcv-list-name">{{ $line->name }}</span>
                    <span class="bcv-list-meta">{{ $line->product_types_count }} product type{{ $line->product_types_count === 1 ? '' : 's' }}{{ $line->is_default ? ' - default line' : '' }}</span>
                    <svg class="bcv-list-arrow" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </a>
            @endforeach
        </div>
    @endif
@endsection
