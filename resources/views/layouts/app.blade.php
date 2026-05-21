<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'LHC Catalogue Staging')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-body">
    @php
        $currentCatalogue = request()->route('catalogue');
        $currentCatalogueId = $currentCatalogue instanceof \App\Models\BrandCatalogue
            ? (int) $currentCatalogue->id
            : (is_numeric($currentCatalogue) ? (int) $currentCatalogue : null);
        $isBodyCareCatalogue = request()->routeIs('body-care-brand-catalogue')
            || (request()->routeIs('brand-catalogue.*') && $currentCatalogueId === 26);
    @endphp

    <div
        class="mobile-nav-backdrop"
        id="mobile-nav-backdrop"
        hidden
        aria-hidden="true"
    ></div>
    <aside class="sidebar is-collapsed" id="sidebar" aria-label="Primary navigation">
        <div class="sidebar-head">
            <div>
                <p class="eyebrow">Catalogue staging</p>
                <h1 class="sidebar-title">LHC</h1>
            </div>
            <button class="sidebar-collapse-btn" id="sidebar-collapse" aria-label="Collapse menu" title="Collapse"><</button>
        </div>

        <nav class="sidebar-nav">
            <p class="sidebar-group-label">Data</p>
            <a href="{{ route('dashboard') }}" class="sidebar-link {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
                <span class="sidebar-icon">LHC</span> Home
            </a>
            <a href="{{ route('products.index') }}" class="sidebar-link {{ request()->routeIs('products.index') ? 'is-active' : '' }}">
                <span class="sidebar-icon">PRD</span> Products
            </a>
            <a href="{{ route('source-products.index') }}" class="sidebar-link {{ request()->routeIs('source-products.index') ? 'is-active' : '' }}">
                <span class="sidebar-icon">SRC</span> All Sources
            </a>
            <a href="{{ route('reference.shaba.index') }}" class="sidebar-link {{ request()->routeIs('reference.shaba.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">SHB</span> Shaba Ref
            </a>
            <a href="{{ route('retail-products.index') }}" class="sidebar-link {{ request()->routeIs('retail-products.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">SEL</span> Sellable
            </a>
            <a href="{{ route('source-products.picture-only') }}" class="sidebar-link {{ request()->routeIs('source-products.picture-only') ? 'is-active' : '' }}">
                <span class="sidebar-icon">MTCH</span> Need Match
            </a>
            <a href="{{ route('source-products.picture-brands') }}" class="sidebar-link {{ request()->routeIs('source-products.picture-brands*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">PBR</span> Picture Brands
            </a>
            <a href="{{ route('products.true-products') }}" class="sidebar-link {{ request()->routeIs('products.true-products') ? 'is-active' : '' }}">
                <span class="sidebar-icon">TRU</span> True Products
            </a>
            <a href="{{ route('products.duplicates') }}" class="sidebar-link {{ request()->routeIs('products.duplicates') ? 'is-active' : '' }}">
                <span class="sidebar-icon">DUP</span> Identical
            </a>
            <a href="{{ route('products.analysis') }}" class="sidebar-link {{ request()->routeIs('products.analysis') ? 'is-active' : '' }}">
                <span class="sidebar-icon">ANL</span> Analysis
            </a>
            <a href="{{ route('pictures.index') }}" class="sidebar-link {{ request()->routeIs('pictures.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">PIC</span> Pictures
            </a>
            <a href="{{ route('pdf-products.index') }}" class="sidebar-link {{ request()->routeIs('pdf-products.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">PDF</span> PDF Products
            </a>
            <a href="{{ route('mamado-products.index') }}" class="sidebar-link {{ request()->routeIs('mamado-products.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">MAM</span> Mamado Products
            </a>
            <a href="{{ route('hair-extension-intake.index') }}" class="sidebar-link {{ request()->routeIs('hair-extension-intake.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">HEI</span> Hair Intake
            </a>
            <a href="{{ route('hair-extension-intake.wizard.index') }}" class="sidebar-link {{ request()->routeIs('hair-extension-intake.wizard.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">WIZ</span> Hair Wizard
            </a>
            <a href="{{ route('shop-product-intake.index') }}" class="sidebar-link {{ request()->routeIs('shop-product-intake.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">SPI</span> Shop Intake
            </a>
            <a href="{{ route('shop-photo-batches.index') }}" class="sidebar-link {{ request()->routeIs('shop-photo-batches.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">SPB</span> Photo Batches
            </a>
            <a href="{{ route('deliveroo-products.index') }}" class="sidebar-link {{ request()->routeIs('deliveroo-products.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">DLV</span> Deliveroo
            </a>

            <p class="sidebar-group-label">Structure</p>
            <a href="{{ route('categories.index') }}" class="sidebar-link {{ request()->routeIs('categories.index') ? 'is-active' : '' }}">
                <span class="sidebar-icon">CAT</span> Categories
            </a>
            <a href="{{ route('categories.scaffold') }}" class="sidebar-link {{ request()->routeIs('categories.scaffold*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">SCF</span> Scaffold
            </a>
            <a href="{{ route('brand-catalogue.index') }}" class="sidebar-link {{ request()->routeIs('brand-catalogue.*') && ! $isBodyCareCatalogue ? 'is-active' : '' }}">
                <span class="sidebar-icon">BCT</span> Brand Catalogue
            </a>
            <a href="{{ route('body-care-brand-catalogue') }}" class="sidebar-link {{ $isBodyCareCatalogue ? 'is-active' : '' }}">
                <span class="sidebar-icon">BDY</span> Body Care Catalogue
            </a>
            <a href="{{ route('inventory-structure.index') }}" class="sidebar-link {{ request()->routeIs('inventory-structure.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">INV</span> Stores
            </a>

            <p class="sidebar-group-label">Brands</p>
            <a href="{{ route('real-brands.index') }}" class="sidebar-link {{ request()->routeIs('real-brands.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">RBR</span> Real Brands
            </a>
            <a href="{{ route('brand-review.index') }}" class="sidebar-link {{ request()->routeIs('brand-review.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">REV</span> Brand Review
            </a>
            <a href="{{ route('external-brands.show') }}" class="sidebar-link {{ request()->routeIs('external-brands.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">EXT</span> External Brands
            </a>

            <p class="sidebar-group-label">Output</p>
            <a href="{{ route('invoice-generator.create') }}" class="sidebar-link {{ request()->routeIs('invoice-generator.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">INV</span> Invoice
            </a>
            <a href="{{ route('exports.index') }}" class="sidebar-link {{ request()->routeIs('exports.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">OUT</span> Exports
            </a>

            <p class="sidebar-group-label">Settings</p>
            <a href="{{ route('settings.watermark.edit') }}" class="sidebar-link {{ request()->routeIs('settings.watermark.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">WM</span> Watermark
            </a>
            <a href="{{ route('settings.photo-processing.edit') }}" class="sidebar-link {{ request()->routeIs('settings.photo-processing.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">AI</span> Photo Processing
            </a>
            <a href="{{ route('settings.mobile-capture.edit') }}" class="sidebar-link {{ request()->routeIs('settings.mobile-capture.*') ? 'is-active' : '' }}">
                <span class="sidebar-icon">MOB</span> Mobile Capture
            </a>
        </nav>
    </aside>

    <div class="app-main is-expanded" id="app-main">
        <header class="topnav-bar" id="topnav-bar">
            <div class="topnav-left">
                <button
                    type="button"
                    class="topnav-hamburger"
                    id="topnav-hamburger"
                    aria-label="Open menu"
                    aria-expanded="false"
                    aria-controls="sidebar"
                >Menu</button>
                <span class="topnav-breadcrumb">
                    <span class="topnav-app">LHC</span>
                    @hasSection('section')
                        <span class="topnav-sep">/</span>
                        <span class="topnav-section">@yield('section')</span>
                    @endif
                    @hasSection('heading')
                        <span class="topnav-sep">/</span>
                        <span class="topnav-page">@yield('heading')</span>
                    @endif
                </span>
            </div>
        </header>

        @if (session('status'))
            <div class="flash flash-success">{{ session('status') }}</div>
        @endif

        @if (session('warning'))
            <div class="flash flash-warning">{{ session('warning') }}</div>
        @endif

        @if ($errors->any())
            <div class="flash flash-error">
                <p class="flash-title">Please fix the following:</p>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <main class="page-content">
            @yield('content')
        </main>
    </div>
</body>
</html>
