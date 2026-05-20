@extends('layouts.app')

@section('title', $line->name . ' - View')

@section('content')
    @include('brand-catalogue._page-label', ['label' => 'Brand Line View', 'context' => $line->name])

    @include('partials.catalogue-breadcrumb', [
        'items' => [
            ['label' => 'Brand Catalogue', 'url' => route('brand-catalogue.index')],
            ['label' => $catalogue->name, 'url' => route('brand-catalogue.view', $catalogue)],
            ['label' => $brand->name, 'url' => route('brand-catalogue.view.brand', [$catalogue, $brand])],
            ['label' => $line->name, 'current' => true],
        ],
    ])

    <div class="sr-hero">
        <div class="sr-hero-top">
            <div class="sr-hero-title-block"><h1 class="sr-hero-title">{{ $line->name }}</h1></div>
            <div class="bcv-toggle">
                <a href="{{ route('brand-catalogue.view.line', [$catalogue, $brand, $line, 'view' => 'grid']) }}" class="bcv-toggle-btn {{ $viewMode === 'grid' ? 'is-active' : '' }}">Grid</a>
                <a href="{{ route('brand-catalogue.view.line', [$catalogue, $brand, $line, 'view' => 'list']) }}" class="bcv-toggle-btn {{ $viewMode === 'list' ? 'is-active' : '' }}">List</a>
            </div>
        </div>
        <div class="sr-stats">
            <div class="sr-stat"><span class="sr-stat-num">{{ $line->productTypes->count() }}</span><span class="sr-stat-label">product types</span></div>
            <div class="sr-stat"><span class="sr-stat-num">{{ $line->productTypes->sum('styles_count') }}</span><span class="sr-stat-label">styles</span></div>
        </div>
    </div>

    @if ($viewMode === 'grid')
        <div class="bcv-grid">
            @foreach ($line->productTypes as $productType)
                <a href="{{ route('brand-catalogue.view.product-type', [$catalogue, $brand, $line, $productType]) }}" class="bcv-card">
                    <div class="bcv-card-top"><h3 class="bcv-card-name">{{ $productType->name }}</h3></div>
                    <span class="bcv-card-count">{{ $productType->styles_count }} style{{ $productType->styles_count === 1 ? '' : 's' }}</span>
                    <svg class="bcv-card-arrow" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </a>
            @endforeach
        </div>
    @else
        <div class="bcv-list">
            @foreach ($line->productTypes as $productType)
                <a href="{{ route('brand-catalogue.view.product-type', [$catalogue, $brand, $line, $productType]) }}" class="bcv-list-row">
                    <span class="bcv-list-name">{{ $productType->name }}</span>
                    <span class="bcv-list-meta">{{ $productType->styles_count }} style{{ $productType->styles_count === 1 ? '' : 's' }}</span>
                    <svg class="bcv-list-arrow" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </a>
            @endforeach
        </div>
    @endif
@endsection
