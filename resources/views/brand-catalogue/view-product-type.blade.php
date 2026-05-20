@extends('layouts.app')

@section('title', $productType->name . ' - View')

@section('content')
    @include('brand-catalogue._page-label', ['label' => 'Product Type View', 'context' => $productType->name])

    @include('partials.catalogue-breadcrumb', [
        'items' => [
            ['label' => 'Brand Catalogue', 'url' => route('brand-catalogue.index')],
            ['label' => $catalogue->name, 'url' => route('brand-catalogue.view', $catalogue)],
            ['label' => $brand->name, 'url' => route('brand-catalogue.view.brand', [$catalogue, $brand])],
            ['label' => $line->name, 'url' => route('brand-catalogue.view.line', [$catalogue, $brand, $line])],
            ['label' => $productType->name, 'current' => true],
        ],
    ])

    <div class="bcv-grid">
        @foreach ($productType->styles as $style)
            <a href="{{ route('brand-catalogue.view.style', [$catalogue, $brand, $line, $productType, $style]) }}" class="bcv-card">
                <div class="bcv-card-top">
                    <h3 class="bcv-card-name">{{ $style->name }}</h3>
                    @if ($style->material_name)
                        <span class="bc-vtype-badge bc-vtype-text">{{ $style->material_name }}</span>
                    @endif
                </div>
                <span class="bcv-card-count">{{ $style->variants_count }} variant group{{ $style->variants_count === 1 ? '' : 's' }}</span>
                <svg class="bcv-card-arrow" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
            </a>
        @endforeach
    </div>
@endsection
