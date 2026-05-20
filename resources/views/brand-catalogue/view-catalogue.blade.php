@extends('layouts.app')

@section('title', $catalogue->name . ' - View')

@section('content')
    @include('brand-catalogue._page-label', ['label' => 'Catalogue View', 'context' => $catalogue->name])

    @include('partials.catalogue-breadcrumb', [
        'items' => [
            ['label' => 'Brand Catalogue', 'url' => route('brand-catalogue.index')],
            ['label' => $catalogue->name, 'url' => route('brand-catalogue.show', $catalogue)],
            ['label' => 'View', 'current' => true],
        ],
    ])

    <div class="sr-hero">
        <div class="sr-hero-top">
            <div class="sr-hero-title-block">
                <h1 class="sr-hero-title">{{ $catalogue->name }}</h1>
            </div>
            <div class="bcv-toggle">
                <a href="{{ route('brand-catalogue.view', [$catalogue, 'view' => 'grid']) }}" class="bcv-toggle-btn {{ $viewMode === 'grid' ? 'is-active' : '' }}">Grid</a>
                <a href="{{ route('brand-catalogue.view', [$catalogue, 'view' => 'list']) }}" class="bcv-toggle-btn {{ $viewMode === 'list' ? 'is-active' : '' }}">List</a>
            </div>
        </div>
        <div class="sr-stats">
            <div class="sr-stat"><span class="sr-stat-num">{{ $catalogueLineCount }}</span><span class="sr-stat-label">brand lines</span></div>
            <div class="sr-stat"><span class="sr-stat-num">{{ $catalogueBrands->count() }}</span><span class="sr-stat-label">master brands</span></div>
            <div class="sr-stat"><span class="sr-stat-num">{{ $catalogue->brands->sum('product_types_count') }}</span><span class="sr-stat-label">product types</span></div>
        </div>
    </div>

    @if ($catalogueBrands->isEmpty())
        <div class="sr-empty"><p>No master brands in this catalogue yet.</p></div>
    @elseif ($viewMode === 'grid')
        <div class="bcv-grid">
            @foreach ($catalogueBrands as $brand)
                <a href="{{ route('brand-catalogue.view.brand', [$catalogue, $brand]) }}" class="bcv-card">
                    <div class="bcv-card-top">
                        <h3 class="bcv-card-name">{{ $brand->name }}</h3>
                        <span class="bc-vtype-badge bc-vtype-text">master brand</span>
                    </div>
                    <span class="bcv-card-count">{{ $brand->lines->count() }} line{{ $brand->lines->count() === 1 ? '' : 's' }}</span>
                    <p class="bcv-card-note">{{ $brand->product_types_count }} product type{{ $brand->product_types_count === 1 ? '' : 's' }}</p>
                    <svg class="bcv-card-arrow" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </a>
            @endforeach
        </div>
    @else
        <div class="bcv-list">
            @foreach ($catalogueBrands as $brand)
                <a href="{{ route('brand-catalogue.view.brand', [$catalogue, $brand]) }}" class="bcv-list-row">
                    <span class="bcv-list-name">{{ $brand->name }}</span>
                    <span class="bcv-list-meta">{{ $brand->lines->count() }} line{{ $brand->lines->count() === 1 ? '' : 's' }} - {{ $brand->product_types_count }} product type{{ $brand->product_types_count === 1 ? '' : 's' }}</span>
                    <svg class="bcv-list-arrow" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </a>
            @endforeach
        </div>
    @endif
@endsection
