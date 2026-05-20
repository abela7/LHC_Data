@extends('layouts.app')

@section('title', 'Brand Catalogue')

@section('content')
    @include('brand-catalogue._page-label', ['label' => 'Catalogue Overview', 'context' => 'All catalogues'])

    <div class="sr-hero">
        <div class="sr-hero-top">
            <div class="sr-hero-title-block">
                <h1 class="sr-hero-title">Brand Catalogue</h1>
                <p class="bc-subtitle">Product catalogues organised by master brand, line or collection, product type, material, style, and variants.</p>
            </div>
        </div>
        <div class="sr-stats">
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $catalogues->count() }}</span>
                <span class="sr-stat-label">catalogues</span>
            </div>
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $catalogues->sum('brands_count') }}</span>
                <span class="sr-stat-label">master brands</span>
            </div>
        </div>
    </div>

    @if ($catalogues->isEmpty())
        <div class="sr-empty">
            <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
            <p>No catalogues yet</p>
        </div>
    @else
        <div class="sr-tree">
            @foreach ($catalogues as $cat)
                <a href="{{ route('brand-catalogue.show', $cat) }}" class="bc-catalogue-card">
                    <div class="bc-catalogue-icon">
                        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
                    </div>
                    <div class="bc-catalogue-body">
                        <h3>{{ $cat->name }}</h3>
                        <span class="bc-catalogue-meta">{{ $cat->brands_count }} brand{{ $cat->brands_count === 1 ? '' : 's' }}</span>
                    </div>
                    <svg class="bc-catalogue-arrow" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </a>
            @endforeach
        </div>
    @endif
@endsection
