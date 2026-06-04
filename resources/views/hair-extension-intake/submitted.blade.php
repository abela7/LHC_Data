@extends('layouts.app')

@section('title', 'Submitted Hair Extension Products')
@section('section', 'Hair Extensions')
@section('heading', 'Submitted Intake')

@section('content')
    <section class="hei-submitted">
        <style>
            .hei-submitted {
                --hei-ink:#10241f;
                --hei-muted:#6c766e;
                --hei-card:#fffdf8;
                --hei-edge:#ded6c8;
                --hei-accent:#006b5a;
                --hei-danger:#a23c32;
                display:flex;
                flex-direction:column;
                gap:1rem;
                max-width:1180px;
                margin:0 auto;
            }
            .his-hero {
                display:flex;
                justify-content:space-between;
                align-items:center;
                gap:1.5rem;
                border:1px solid rgba(0,107,90,.12);
                border-radius:24px;
                padding:1.25rem 1.5rem;
                background:linear-gradient(135deg,#fffdf8,#f2f9f6);
                box-shadow:0 8px 25px rgba(31,36,33,.04);
            }
            .his-hero-content {
                display:flex;
                flex-direction:column;
                gap:.25rem;
            }
            .his-hero h1 {
                margin:0;
                color:var(--hei-ink);
                font-size:clamp(1.75rem,4vw,2.25rem);
                line-height:1.1;
                letter-spacing:-.03em;
            }
            .his-hero p { margin:0; color:var(--hei-muted); max-width:720px; line-height:1.55; }
            .his-actions { display:flex; flex-wrap:wrap; gap:.6rem; justify-content:flex-end; align-items:center; }
            .his-btn {
                display:inline-flex;
                min-height:44px;
                align-items:center;
                justify-content:center;
                border:1px solid var(--hei-edge);
                border-radius:15px;
                background:#fffdf8;
                color:var(--hei-ink);
                padding:.75rem 1rem;
                font-weight:950;
                text-decoration:none;
            }
            .his-btn.primary { border-color:var(--hei-accent); background:var(--hei-accent); color:#fffaf3; }
            .his-filters {
                display:flex;
                flex-wrap:wrap;
                align-items:flex-end;
                gap:.75rem 1rem;
                border:1px solid var(--hei-edge);
                border-radius:22px;
                background:var(--hei-card);
                padding:1rem 1.1rem;
                box-shadow:0 8px 25px rgba(31,36,33,.04);
            }
            .his-filters-field {
                display:flex;
                flex-direction:column;
                gap:.35rem;
                min-width:min(100%, 200px);
                flex:1 1 180px;
            }
            .his-filters-field span {
                color:var(--hei-muted);
                font-size:.72rem;
                font-weight:900;
                letter-spacing:.08em;
                text-transform:uppercase;
            }
            .his-filters-field select {
                min-height:44px;
                border:1px solid var(--hei-edge);
                border-radius:14px;
                background:#fffdf8;
                color:var(--hei-ink);
                padding:.55rem .75rem;
                font-size:.92rem;
                font-weight:700;
            }
            .his-filters-field select:disabled {
                opacity:.55;
                cursor:not-allowed;
                background:#f4efe5;
            }
            .his-filters-hint {
                color:var(--hei-muted);
                font-size:.72rem;
                font-weight:700;
                line-height:1.35;
            }
            .his-filters-actions {
                display:flex;
                flex-wrap:wrap;
                gap:.5rem;
                align-items:center;
            }
            .his-stats {
                display:grid;
                grid-template-columns:repeat(3,minmax(0,1fr));
                gap:.8rem;
            }
            .his-stat, .his-brand {
                border:1px solid var(--hei-edge);
                border-radius:24px;
                background:var(--hei-card);
                padding:1rem;
                box-shadow:0 12px 36px rgba(31,36,33,.06);
            }
            .his-stat span { display:block; color:var(--hei-muted); font-size:.78rem; font-weight:900; letter-spacing:.08em; text-transform:uppercase; }
            .his-stat strong { display:block; margin-top:.3rem; color:var(--hei-ink); font-size:2rem; line-height:1; }
            .his-brand { display:flex; flex-direction:column; gap:.9rem; }
            .his-brand-head {
                display:flex;
                align-items:flex-start;
                justify-content:space-between;
                gap:1rem;
                border-bottom:1px solid #eee4d6;
                padding-bottom:.85rem;
            }
            .his-brand-head h2 { margin:0; color:var(--hei-ink); font-size:1.55rem; letter-spacing:-.035em; }
            .his-brand-head span {
                display:inline-flex;
                border-radius:999px;
                background:#e8f4ef;
                color:var(--hei-accent);
                padding:.45rem .7rem;
                font-size:.78rem;
                font-weight:950;
                white-space:nowrap;
            }
            .his-product-grid {
                display:grid;
                grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
                gap:.85rem;
            }
            .his-product {
                display:flex;
                flex-direction:column;
                gap:.7rem;
                border:1px solid #eadfce;
                border-radius:22px;
                background:#fffaf3;
                padding:.85rem;
            }
            .his-product-main {
                display:grid;
                grid-template-columns:76px minmax(0,1fr);
                gap:.75rem;
                align-items:start;
            }
            .his-thumb {
                flex-shrink:0;
                display:flex;
                align-items:center;
                justify-content:center;
                width:76px;
                height:76px;
                max-width:76px;
                max-height:76px;
                overflow:hidden;
                border:1px solid #e5dbc9;
                border-radius:18px;
                background:#f4efe5;
                color:var(--hei-muted);
                font-size:.72rem;
                font-weight:900;
                text-align:center;
                cursor:pointer;
                padding:0;
            }
            .his-thumb img { width:100%; height:100%; object-fit:cover; }
            .his-photo-strip {
                display:grid;
                grid-template-columns:repeat(auto-fill,minmax(72px,1fr));
                gap:.45rem;
                border-top:1px solid #eee4d6;
                padding-top:.65rem;
            }
            .his-photo-item {
                display:flex;
                min-width:0;
                flex-direction:column;
                gap:.3rem;
                text-decoration:none;
                color:var(--hei-ink);
                cursor:pointer;
                padding:0;
                background:transparent;
                border:none;
                text-align:left;
            }
            .his-photo-item img {
                width:100%;
                aspect-ratio:1 / 1;
                object-fit:cover;
                border:1px solid #e5dbc9;
                border-radius:14px;
                background:#f4efe5;
            }
            .his-photo-item span {
                overflow:hidden;
                color:var(--hei-muted);
                font-size:.68rem;
                font-weight:900;
                text-overflow:ellipsis;
                white-space:nowrap;
            }
            .his-product h3 {
                margin:0;
                color:var(--hei-ink);
                font-size:1.05rem;
                line-height:1.12;
                letter-spacing:-.02em;
            }
            .his-meta {
                display:flex;
                flex-wrap:wrap;
                gap:.4rem;
            }
            .his-chip {
                display:inline-flex;
                border-radius:999px;
                background:#fffdf8;
                border:1px solid #e7ddcf;
                color:var(--hei-muted);
                padding:.35rem .55rem;
                font-size:.72rem;
                font-weight:900;
            }
            .his-chip.warn { color:#8a5a20; background:#fff4de; border-color:#ead3a8; }
            .his-path {
                display:flex;
                flex-wrap:wrap;
                gap:.35rem;
                border-top:1px solid #eee4d6;
                padding-top:.65rem;
            }
            .his-path-chip {
                display:inline-flex;
                align-items:center;
                gap:.3rem;
                border-radius:999px;
                border:1px solid rgba(0,107,90,.16);
                background:#edf8f3;
                color:var(--hei-accent);
                padding:.35rem .55rem;
                font-size:.72rem;
                font-weight:950;
            }
            .his-path-chip.muted {
                border-color:#e7ddcf;
                background:#fffdf8;
                color:var(--hei-muted);
            }
            .his-variants {
                display:flex;
                flex-direction:column;
                gap:.45rem;
                border-top:1px solid #eee4d6;
                padding-top:.65rem;
            }
            .his-variant-row {
                display:grid;
                grid-template-columns:minmax(5.5rem,.45fr) minmax(0,1fr);
                gap:.6rem;
                color:var(--hei-muted);
                font-size:.86rem;
            }
            .his-variant-row strong { color:var(--hei-ink); }
            .his-notes {
                max-height:7.5rem;
                overflow:auto;
                border:1px solid #eadfce;
                border-radius:16px;
                background:#fffdf8;
                padding:.7rem;
                color:var(--hei-muted);
                font-size:.86rem;
                line-height:1.45;
                white-space:pre-wrap;
            }
            .his-notes::before {
                content:'Note';
                display:block;
                margin-bottom:.35rem;
                color:var(--hei-ink);
                font-size:.68rem;
                font-weight:950;
                letter-spacing:.08em;
                text-transform:uppercase;
            }
            .his-suggestion {
                display:flex;
                flex-direction:column;
                gap:.55rem;
                border:1px solid rgba(0,107,90,.18);
                border-radius:18px;
                background:#edf8f3;
                padding:.75rem;
                color:var(--hei-ink);
            }
            .his-suggestion-head {
                display:flex;
                justify-content:space-between;
                gap:.75rem;
                align-items:flex-start;
            }
            .his-suggestion-head strong { font-size:.92rem; }
            .his-suggestion-head span {
                border-radius:999px;
                background:#d7eee6;
                color:var(--hei-accent);
                padding:.25rem .5rem;
                font-size:.7rem;
                font-weight:950;
                white-space:nowrap;
            }
            .his-suggestion-grid {
                display:grid;
                grid-template-columns:repeat(2,minmax(0,1fr));
                gap:.45rem;
            }
            .his-suggestion-field {
                border:1px solid rgba(0,107,90,.12);
                border-radius:13px;
                background:#fffdf8;
                padding:.5rem .6rem;
            }
            .his-suggestion-field span {
                display:block;
                color:var(--hei-muted);
                font-size:.68rem;
                font-weight:900;
                text-transform:uppercase;
                letter-spacing:.06em;
            }
            .his-suggestion-field strong {
                display:block;
                margin-top:.18rem;
                font-size:.86rem;
            }
            .his-suggestion details {
                border-top:1px solid rgba(0,107,90,.16);
                padding-top:.45rem;
            }
            .his-suggestion summary {
                cursor:pointer;
                color:var(--hei-accent);
                font-weight:950;
            }
            .his-suggestion ul {
                margin:.45rem 0 0;
                padding-left:1rem;
                color:var(--hei-muted);
                font-size:.82rem;
                line-height:1.4;
            }
            .his-product-footer {
                display:flex;
                justify-content:space-between;
                gap:.6rem;
                color:var(--hei-muted);
                font-size:.78rem;
            }
            .his-delete {
                width:100%;
                border:1px solid #edc9c4;
                border-radius:12px;
                background:#fff1ee;
                color:var(--hei-danger);
                padding:.45rem .6rem;
                font-weight:900;
                cursor:pointer;
            }
            .his-card-actions {
                display:grid;
                grid-template-columns:1fr 1fr 1fr;
                gap:.4rem;
            }
            .his-card-actions .his-btn,
            .his-card-actions .his-delete {
                min-height:42px;
                padding:.4rem .5rem;
                font-size:.75rem;
                border-radius:10px;
                text-align:center;
            }
            .his-empty {
                border:1px dashed #cfd8d1;
                border-radius:24px;
                background:#fffdf8;
                padding:2rem;
                color:var(--hei-muted);
                text-align:center;
                font-weight:850;
            }
            .his-recent-grid {
                display:grid;
                grid-template-columns:repeat(auto-fill,minmax(260px,1fr));
                gap:1rem;
            }
            .his-recent-card {
                display:flex;
                flex-direction:column;
                border:1px solid #eadfce;
                border-radius:20px;
                background:linear-gradient(180deg, #fffdf8, #fffaf3);
                padding:1rem;
                box-shadow:0 4px 15px rgba(31,36,33,.02);
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }
            .his-recent-card:hover {
                transform: translateY(-2px);
                box-shadow:0 8px 25px rgba(31,36,33,.05);
            }
            .his-recent-header {
                display:flex;
                justify-content:space-between;
                align-items:center;
                margin-bottom:.75rem;
            }
            .his-recent-status {
                display:inline-flex;
                align-items:center;
                gap:.35rem;
                font-size:.7rem;
                font-weight:900;
                text-transform:uppercase;
                letter-spacing:.06em;
                padding:.3rem .6rem;
                border-radius:8px;
            }
            .his-recent-status svg { width:12px; height:12px; stroke-width:2.5; }
            .his-recent-status.draft { background:#fff4de; color:#8a5a20; border:1px solid #ead3a8; }
            .his-recent-status.submitted { background:#edf8f3; color:var(--hei-accent); border:1px solid rgba(0,107,90,.16); }
            .his-recent-time {
                color:var(--hei-muted);
                font-size:.75rem;
                font-weight:700;
            }
            .his-recent-body {
                display:flex;
                gap:1rem;
                margin-bottom:.85rem;
            }
            .his-recent-photo {
                flex-shrink:0;
                display:flex;
                align-items:center;
                justify-content:center;
                width:72px;
                height:72px;
                max-width:72px;
                max-height:72px;
                border-radius:14px;
                border:1px solid #e5dbc9;
                background:#f4efe5;
                overflow:hidden;
                color:var(--hei-muted);
                font-size:.65rem;
                font-weight:900;
                text-align:center;
                cursor:pointer;
                padding:0;
            }
            .his-recent-photo img {
                width:100%;
                height:100%;
                object-fit:cover;
            }
            .his-recent-title {
                display:flex;
                flex-direction:column;
                gap:.2rem;
                margin-bottom:.85rem;
            }
            .his-recent-title strong { color:var(--hei-ink); font-size:1.15rem; letter-spacing:-.02em; line-height:1.2; }
            .his-recent-title span { color:var(--hei-muted); font-size:.9rem; font-weight:600; line-height:1.3; }
            .his-recent-meta {
                display:flex;
                flex-direction:column;
                gap:.45rem;
                margin-bottom:1rem;
                padding: .75rem;
                background: #fdfbf5;
                border-radius: 12px;
                border: 1px solid #f2eadc;
            }
            .his-recent-meta span {
                display:flex;
                align-items:flex-start;
                gap:.45rem;
                color:var(--hei-muted);
                font-size:.8rem;
                font-weight:600;
                line-height:1.4;
            }
            .his-recent-meta svg { width:15px; height:15px; flex-shrink:0; margin-top:.1rem; opacity:0.7; }
            .his-recent-actions {
                display:grid;
                grid-template-columns:1fr 1fr 1fr;
                gap:.4rem;
                margin-top:auto;
            }
            .his-recent-actions .his-btn,
            .his-recent-actions .his-delete {
                min-height:42px;
                padding:.4rem .5rem;
                font-size:.75rem;
                border-radius:10px;
                text-align:center;
            }
            .his-recent-section {
                background: linear-gradient(180deg, #fdfbf5, #fffdf8);
                border-color: #e5dbc9;
                box-shadow: 0 4px 20px rgba(31,36,33,.03);
            }
            .his-recent-accordion {
                display:flex;
                flex-direction:column;
                gap:0;
            }
            .his-recent-accordion > summary {
                list-style:none;
                cursor:pointer;
                border-bottom:1px solid transparent;
            }
            .his-recent-accordion > summary::-webkit-details-marker { display:none; }
            .his-recent-accordion > summary::marker { content:''; }
            .his-recent-accordion > summary:hover { background:rgba(237,248,243,.35); }
            .his-recent-accordion[open] > summary {
                border-bottom-color:#eee4d6;
                padding-bottom:.85rem;
            }
            .his-recent-accordion-summary {
                display:flex;
                align-items:flex-start;
                justify-content:space-between;
                gap:1rem;
            }
            .his-recent-accordion-summary::after {
                content:'';
                flex-shrink:0;
                width:20px;
                height:20px;
                margin-top:.35rem;
                background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%235f5749' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
                background-size:contain;
                background-repeat:no-repeat;
                transition:transform .2s ease;
            }
            .his-recent-accordion[open] > summary::after { transform:rotate(180deg); }
            .his-recent-accordion-body {
                padding-top:.9rem;
            }
            .his-recent-section .his-brand-head h2,
            .his-recent-accordion-summary h2 {
                display:flex;
                align-items:center;
                gap:.5rem;
            }
            .his-recent-section .his-brand-head h2::before,
            .his-recent-accordion-summary h2::before {
                content: '';
                display: inline-block;
                width: 20px;
                height: 20px;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23006b5a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='10'%3E%3C/circle%3E%3Cpolyline points='12 6 12 12 16 14'%3E%3C/polyline%3E%3C/svg%3E");
                background-size: contain;
                background-repeat: no-repeat;
            }
            @media (max-width: 820px) {
                .his-hero { flex-direction:column; align-items:flex-start; }
                .his-actions { justify-content:stretch; width:100%; }
                .his-actions .his-btn { flex:1 1 auto; }
                .his-stats { grid-template-columns:1fr; }
            }
        </style>
        @php
            $catalogueWorkspaceLink = static function ($intake): ?array {
                $style = $intake->style;
                $productType = $style?->productType ?: $intake->productType;
                $line = $productType?->line;
                $brand = $line?->brand ?: $style?->brand ?: $intake->brand;
                $catalogue = $brand?->catalogue;

                if ($style && $productType && $line && $brand && $catalogue) {
                    return [
                        'url' => route('brand-catalogue.styles.show', [$catalogue, $brand, $line, $productType, $style]),
                        'label' => 'Open family',
                    ];
                }

                if ($productType && $line && $brand && $catalogue) {
                    return [
                        'url' => route('brand-catalogue.product-types.show', [$catalogue, $brand, $line, $productType]),
                        'label' => 'Open type',
                    ];
                }

                if ($brand && $catalogue) {
                    return [
                        'url' => route('brand-catalogue.brands.show', [$catalogue, $brand]),
                        'label' => 'Open brand',
                    ];
                }

                return null;
            };
        @endphp

        <header class="his-hero">
            <div class="his-hero-content">
                <h1>{{ $includeDrafts ? 'Draft records' : 'Submitted products' }}</h1>
            </div>
            <div class="his-actions">
                <a class="his-btn primary" href="{{ route('hair-extension-intake.v2') }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:0.4rem"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Add new intake
                </a>
                @if ($includeDrafts)
                    <a class="his-btn" href="{{ $submittedUrl }}">View submitted ({{ number_format($submittedCount) }})</a>
                @else
                    <a class="his-btn" href="{{ $submittedUrl }}?include_drafts=1">View drafts ({{ number_format($draftCount) }})</a>
                @endif
                <a class="his-btn" href="{{ $exportUrl }}" target="_blank" rel="noopener">Open AI JSON</a>
            </div>
        </header>

        <section class="his-stats">
            <div class="his-stat">
                <span>{{ $includeDrafts ? 'Draft records' : 'Submitted products' }}</span>
                <strong>{{ number_format($submittedIntakes->count()) }}</strong>
            </div>
            <div class="his-stat">
                <span>Brands</span>
                <strong>{{ number_format($groupedByBrand->count()) }}</strong>
            </div>
            <div class="his-stat">
                <span>With photos</span>
                <strong>
                    {{ number_format($submittedIntakes->filter(fn ($intake) => $intake->photos->isNotEmpty() || filled($intake->photo_path))->count()) }}
                </strong>
            </div>
        </section>

        <form method="GET"
              action="{{ route('hair-extension-intake.submitted') }}"
              class="his-filters"
              data-his-filters
              data-his-filter-cascade='@json($filterCascade)'>
            @if ($includeDrafts)
                <input type="hidden" name="include_drafts" value="1">
            @endif
            <label class="his-filters-field">
                <span>Brand</span>
                <select name="brand" data-his-filter-brand>
                    <option value="">All brands</option>
                    @foreach ($filterBrands as $option)
                        <option value="{{ $option['label'] }}" @selected($filterBrand === $option['label'])>
                            {{ $option['label'] }} ({{ number_format($option['count']) }})
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="his-filters-field">
                <span>Product type</span>
                <select name="product_type"
                        data-his-filter-product-type
                        @disabled($filterBrand === '')>
                    @if ($filterBrand === '')
                        <option value="">Choose a brand first</option>
                    @else
                        <option value="">All product types for {{ $filterBrand }}</option>
                        @foreach ($filterProductTypes as $option)
                            <option value="{{ $option['label'] }}" @selected($filterProductType === $option['label'])>
                                {{ $option['label'] }} ({{ number_format($option['count']) }})
                            </option>
                        @endforeach
                    @endif
                </select>
                <span class="his-filters-hint" data-his-filter-product-type-hint @if ($filterBrand !== '') hidden @endif>
                    Choose a brand to see its product types.
                </span>
            </label>
            <label class="his-filters-field">
                <span>Style / family</span>
                <select name="style"
                        data-his-filter-style
                        @disabled($filterBrand === '' || $filterProductType === '')>
                    @if ($filterBrand === '')
                        <option value="">Choose a brand first</option>
                    @elseif ($filterProductType === '')
                        <option value="">Choose a product type first</option>
                    @else
                        <option value="">All styles for {{ $filterProductType }}</option>
                        @foreach ($filterStyles as $option)
                            <option value="{{ $option['label'] }}" @selected($filterStyle === $option['label'])>
                                {{ $option['label'] }} ({{ number_format($option['count']) }})
                            </option>
                        @endforeach
                    @endif
                </select>
                <span class="his-filters-hint" data-his-filter-style-hint @if ($filterBrand === '' || $filterProductType !== '') hidden @endif>
                    Choose a product type to see its styles and families.
                </span>
            </label>
            <div class="his-filters-actions">
                <noscript>
                    <button type="submit" class="his-btn primary">Apply filters</button>
                </noscript>
                @if ($hasActiveFilters)
                    <a class="his-btn" href="{{ $includeDrafts ? route('hair-extension-intake.submitted', ['include_drafts' => 1]) : route('hair-extension-intake.submitted') }}">
                        Clear filters
                    </a>
                @endif
            </div>
        </form>

        <details class="his-brand his-recent-section his-recent-accordion">
            <summary class="his-brand-head his-recent-accordion-summary">
                <h2>Recent drafts &amp; submissions</h2>
                <span>{{ $recentIntakes->count() }} recent</span>
            </summary>
            <div class="his-recent-accordion-body">
            <div class="his-recent-grid">
                @forelse ($recentIntakes as $intake)
                    @php
                        $observedName = $intake->observed_product_name ?: $intake->product_type_name ?: 'No observed product name';
                        $recentVariantStructure = is_array($intake->variant_structure) ? $intake->variant_structure : [];
                        $isTextV2 = ($recentVariantStructure['source'] ?? null) === 'text_note_v2';
                        $catalogueTypeName = $intake->productType?->name ?: ($isTextV2 ? $intake->product_type_name : null) ?: 'Product type pending';
                        $classificationPath = collect($intake->classification_path ?? [])->filter();
                        $locationPath = collect([$intake->store?->name, $intake->section?->name, $intake->subsection?->name])->filter();
                        
                        $recentMappedGroups = collect($recentVariantStructure['groups'] ?? []);
                        $recentMainValues = '';
                        $recentMainAxis = '';
                        if ($recentMappedGroups->isNotEmpty()) {
                            $recentMainAxis = $recentVariantStructure['main_axis'] ?? 'Variant';
                            $recentMainValues = $recentMappedGroups->pluck('main_value')->filter()->unique()->implode(', ');
                        } elseif (!empty($intake->variant_groups)) {
                            $firstGroup = $intake->variant_groups[0];
                            $recentMainAxis = $firstGroup['name'] ?? 'Variant';
                            $recentMainValues = collect($firstGroup['values'] ?? [])->implode(', ');
                        }
                        $recentWorkspaceLink = $intake->status === 'submitted' ? $catalogueWorkspaceLink($intake) : null;
                        $recentPrimaryUrl = $recentWorkspaceLink['url'] ?? route('hair-extension-intake.v2', ['edit_intake' => $intake->id]);
                        $recentPrimaryLabel = $recentWorkspaceLink['label'] ?? ($intake->status === 'submitted' ? 'View/Edit' : 'Open draft');
                    @endphp
                    <article class="his-recent-card" style="cursor:pointer" data-intake-preview="{{ route('hair-extension-intake.draft', $intake) }}" data-intake-edit-url="{{ $recentPrimaryUrl }}">
                        <div class="his-recent-header">
                            <span class="his-recent-status {{ $intake->status }}">
                                @if($intake->status === 'draft')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                                @else
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                @endif
                                {{ ucfirst($intake->status) }}
                            </span>
                            <span class="his-recent-time">{{ $intake->last_synced_at?->diffForHumans() ?: ($intake->submitted_at?->diffForHumans() ?: 'not synced') }}</span>
                        </div>
                        
                        <div class="his-recent-body">
                            <button type="button" class="his-recent-photo" data-picture-preview-trigger data-image-url="{{ $intake->photoUrl() ?: '' }}" data-picture-id="{{ $intake->brand_name }} {{ $observedName }}" aria-label="View photo">
                                @if ($intake->photoUrl())
                                    <img src="{{ $intake->photoUrl() }}" alt="{{ $intake->brand_name }} {{ $observedName }}">
                                @else
                                    No photo
                                @endif
                            </button>
                            <div class="his-recent-title">
                                <strong>{{ $intake->brand_name ?: 'Unknown brand' }}</strong>
                                <span>{{ $observedName }}</span>
                                @if($recentMainValues)
                                    <span style="font-size: 0.8rem; color: var(--hei-muted); margin-top: 0.2rem;">{{ $recentMainAxis }}: {{ $recentMainValues }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="his-recent-meta">
                            @if ($classificationPath->isNotEmpty())
                                <span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                                    {{ $classificationPath->implode(' > ') }}
                                </span>
                            @endif
                            @if ($locationPath->isNotEmpty() || $intake->shelf_location)
                                <span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                    {{ $locationPath->implode(' / ') ?: $intake->shelf_location }}
                                </span>
                            @endif
                            <span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>
                                {{ $intake->style_name ?: $catalogueTypeName }}
                            </span>
                        </div>

                        <div class="his-recent-actions">
                            <a class="his-btn primary" href="{{ $recentPrimaryUrl }}">
                                {{ $recentPrimaryLabel }}
                            </a>
                            <a class="his-btn" href="{{ route('hair-extension-intake.v2', ['duplicate_intake' => $intake->id]) }}" title="Duplicate this intake as a new record">
                                Duplicate
                            </a>
                            <form method="POST" action="{{ route('hair-extension-intake.destroy', $intake) }}" onsubmit="return confirm('Delete this intake record and its saved photos?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="his-delete">Delete</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <div class="his-empty">No recent intake records yet.</div>
                @endforelse
            </div>
            </div>
        </details>

        @forelse ($groupedByBrand as $brandName => $intakes)
            <section class="his-brand">
                <header class="his-brand-head">
                    <h2>{{ $brandName }}</h2>
                    <span>{{ $intakes->count() }} {{ $includeDrafts ? 'visible' : 'submitted' }}</span>
                </header>

                <div class="his-product-grid">
                    @foreach ($intakes as $intake)
                        @php
                            $observedName = $intake->observed_product_name ?: $intake->product_type_name ?: 'No observed product name';
                            $styleLabel = $intake->style_name ?: 'Style/family pending';
                            $variantStructure = is_array($intake->variant_structure) ? $intake->variant_structure : [];
                            $isTextV2 = ($variantStructure['source'] ?? null) === 'text_note_v2';
                            $catalogueTypeName = $intake->productType?->name ?: ($isTextV2 ? $intake->product_type_name : null);
                            $mappedGroups = collect($variantStructure['groups'] ?? []);
                            $commonVariants = collect($variantStructure['common_variants'] ?? []);
                            $skuCount = data_get($variantStructure, 'summary.sellable_combination_count')
                                ?: count($variantStructure['sku_matrix'] ?? []);
                            $mainAxis = $variantStructure['main_axis'] ?? 'Main variant';
                            $classificationPath = collect($intake->classification_path ?? [])->filter();
                            $catalogueStyleStatus = $intake->catalogue_style_status ?: 'known';
                            $productTypeStatus = $intake->product_type_status ?: ($intake->product_type_unknown ? 'not_known' : 'known');
                            $styleFamilyStatus = $intake->style_family_status ?: ($intake->style_unknown ? 'not_known' : 'known');
                            $locationPath = collect([$intake->store?->name, $intake->section?->name, $intake->subsection?->name])->filter();
                            $workspaceLink = $intake->status === 'submitted' ? $catalogueWorkspaceLink($intake) : null;
                            $primaryUrl = $workspaceLink['url'] ?? route('hair-extension-intake.v2', ['edit_intake' => $intake->id]);
                            $primaryLabel = $workspaceLink['label'] ?? ($intake->status === 'draft' ? 'Open draft' : 'View/Edit');
                        @endphp
                        <article class="his-product">
                            <div class="his-product-main">
                                <button type="button" class="his-thumb" data-picture-preview-trigger data-image-url="{{ $intake->photoUrl() ?: '' }}" data-picture-id="{{ $intake->brand_name }} {{ $observedName }}" aria-label="View photo">
                                    @if ($intake->photoUrl())
                                        <img src="{{ $intake->photoUrl() }}" alt="{{ $intake->brand_name }} {{ $observedName }}">
                                    @else
                                        No photo
                                    @endif
                                </button>
                                <div>
                                    <h3>{{ $observedName }}</h3>
                                    <div class="his-meta">
                                        <span class="his-chip {{ $intake->status === 'draft' ? 'warn' : '' }}">
                                            {{ ucfirst($intake->status) }}
                                        </span>
                                        <span class="his-chip {{ $intake->product_type_unknown ? 'warn' : '' }}">
                                            @if ($productTypeStatus === 'not_known')
                                                Product type not known
                                            @elseif ($productTypeStatus === 'not_sure')
                                                Product type not sure{{ $catalogueTypeName ? ': '.$catalogueTypeName : '' }}
                                            @else
                                                {{ $catalogueTypeName ?: 'Product type pending' }}
                                            @endif
                                        </span>
                                        <span class="his-chip {{ $intake->style_unknown ? 'warn' : '' }}">
                                            @if ($styleFamilyStatus === 'not_known')
                                                Style/family not known
                                            @elseif ($styleFamilyStatus === 'not_sure')
                                                Style/family not sure{{ $styleLabel !== 'Style/family pending' ? ': '.$styleLabel : '' }}
                                            @else
                                                {{ $styleLabel }}
                                            @endif
                                        </span>
                                        @if ($catalogueStyleStatus !== 'known')
                                            <span class="his-chip warn">Catalogue style {{ $catalogueStyleStatus === 'not_sure' ? 'not sure' : 'not known' }}</span>
                                        @endif
                                        @if ($skuCount)
                                            <span class="his-chip">{{ number_format($skuCount) }} mapped SKU{{ $skuCount === 1 ? '' : 's' }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            @if ($classificationPath->isNotEmpty() || $locationPath->isNotEmpty() || $intake->shelf_location)
                                <div class="his-path">
                                    @foreach ($classificationPath as $pathItem)
                                        <span class="his-path-chip">{{ $loop->iteration }}. {{ $pathItem }}</span>
                                    @endforeach
                                    @if ($locationPath->isNotEmpty())
                                        <span class="his-path-chip muted">Shelf: {{ $locationPath->implode(' / ') }}</span>
                                    @endif
                                    @if ($intake->shelf_location && $intake->shelf_location !== $locationPath->implode(' / '))
                                        <span class="his-path-chip muted">Note: {{ $intake->shelf_location }}</span>
                                    @endif
                                </div>
                            @endif

                            <div class="his-variants">
                                @if ($mappedGroups->isNotEmpty() || $commonVariants->isNotEmpty())
                                    <div class="his-variant-row">
                                        <span>Main axis</span>
                                        <strong>{{ $mainAxis }}</strong>
                                    </div>
                                    @foreach ($mappedGroups as $group)
                                        <div class="his-variant-row">
                                            <span>{{ $mainAxis }} {{ $group['main_value'] ?? '' }}</span>
                                            <strong>
                                                {{ $group['sub_axis'] ?? 'Variant' }}:
                                                {{ collect($group['sub_values'] ?? [])->implode(', ') ?: 'No values' }}
                                            </strong>
                                        </div>
                                    @endforeach
                                    @foreach ($commonVariants as $group)
                                        <div class="his-variant-row">
                                            <span>{{ $group['name'] ?? 'Common variant' }}</span>
                                            <strong>{{ collect($group['values'] ?? [])->implode(', ') ?: 'No values' }}</strong>
                                        </div>
                                    @endforeach
                                @else
                                    @forelse (($intake->variant_groups ?? []) as $group)
                                        <div class="his-variant-row">
                                            <span>{{ $group['name'] ?? 'Variant' }}</span>
                                            <strong>{{ collect($group['values'] ?? [])->implode(', ') ?: 'No values' }}</strong>
                                        </div>
                                    @empty
                                        <div class="his-variant-row">
                                            <span>Variants</span>
                                            <strong>No variant values captured</strong>
                                        </div>
                                    @endforelse
                                @endif
                            </div>

                            @php
                                $catalogueSuggestion = $intake->aiSuggestions
                                    ->filter(fn ($suggestion) => data_get($suggestion->suggestion, 'kind') === 'catalogue_structure_suggestion')
                                    ->sortByDesc('created_at')
                                    ->first();
                                $suggestionData = $catalogueSuggestion?->suggestion ?? [];
                                $suggestionGroups = collect(data_get($suggestionData, 'proposed_variant_groups', []));
                            @endphp
                            @if ($catalogueSuggestion)
                                <section class="his-suggestion">
                                    <div class="his-suggestion-head">
                                        <strong>Catalogue suggestion</strong>
                                        <span>{{ $catalogueSuggestion->confidence ?: data_get($suggestionData, 'confidence', 'A') }}</span>
                                    </div>
                                    <div class="his-suggestion-grid">
                                        <div class="his-suggestion-field">
                                            <span>Family</span>
                                            <strong>{{ data_get($suggestionData, 'family_name', 'Pending') }}</strong>
                                        </div>
                                        <div class="his-suggestion-field">
                                            <span>Style</span>
                                            <strong>{{ data_get($suggestionData, 'style_family', 'Pending') }}</strong>
                                        </div>
                                        <div class="his-suggestion-field">
                                            <span>Axes</span>
                                            <strong>{{ collect(data_get($suggestionData, 'variant_axes', []))->implode(' / ') ?: 'Pending' }}</strong>
                                        </div>
                                        <div class="his-suggestion-field">
                                            <span>Suggested SKUs</span>
                                            <strong>{{ number_format((int) data_get($suggestionData, 'proposed_sellable_sku_count', 0)) }}</strong>
                                        </div>
                                    </div>
                                    @if ($suggestionGroups->isNotEmpty())
                                        <details>
                                            <summary>View manufacturer-sheet variants</summary>
                                            <ul>
                                                @foreach ($suggestionGroups as $group)
                                                    <li>
                                                        {{ data_get($group, 'pack_count') }}
                                                        {{ data_get($group, 'length') }}:
                                                        {{ collect(data_get($group, 'colour_values', []))->implode(', ') }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                </section>
                            @endif

                            <div class="his-product-footer">
                                <span>
                                    @if ($intake->status === 'draft')
                                        Draft saved {{ $intake->last_synced_at?->diffForHumans() ?: 'recently' }}
                                    @else
                                        Submitted {{ $intake->submitted_at?->diffForHumans() ?: 'recently' }}
                                    @endif
                                </span>
                                @php
                                    $verificationUrls = collect($intake->verification_urls ?? [])
                                        ->push($intake->source_url)
                                        ->filter()
                                        ->unique()
                                        ->values();
                                @endphp
                                @if ($verificationUrls->isNotEmpty())
                                    <span>
                                        @foreach ($verificationUrls as $sourceIndex => $sourceUrl)
                                            <a href="{{ $sourceUrl }}" target="_blank" rel="noopener">Source {{ $sourceIndex + 1 }}</a>@if (! $loop->last) · @endif
                                        @endforeach
                                    </span>
                                @endif
                            </div>

                            <div class="his-card-actions">
                                <a class="his-btn primary" href="{{ $primaryUrl }}">
                                    {{ $primaryLabel }}
                                </a>
                                <a class="his-btn" href="{{ route('hair-extension-intake.v2', ['duplicate_intake' => $intake->id]) }}" title="Duplicate this intake as a new record">
                                    Duplicate
                                </a>
                                <form method="POST" action="{{ route('hair-extension-intake.destroy', $intake) }}" onsubmit="return confirm('Delete this {{ $intake->status === 'draft' ? 'draft' : 'submitted product intake' }} and its saved photos?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="his-delete">Delete</button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="his-empty">
                @if ($hasActiveFilters)
                    No {{ $includeDrafts ? 'drafts' : 'submitted products' }} match these filters. Try different brand, product type, or style values, or clear filters to see everything.
                @elseif ($includeDrafts)
                    No submitted products or drafts yet. Start a new intake from your phone, then it will appear here when it has at least a brand.
                @else
                    No submitted products yet. Submit product observations from the intake page, then they will appear here grouped by brand. Use "Show drafts" if you want to review unfinished records.
                @endif
            </div>
        @endforelse
    </section>
    <div class="rfm-lightbox-wrap" data-picture-preview-modal aria-hidden="true" hidden>
        <div class="pw-lightbox-overlay">
            <button type="button" class="pw-lightbox-backdrop" aria-label="Close"></button>
            <section class="pw-lightbox" role="dialog" aria-modal="true" aria-label="Image preview">
                <img src="" alt="" data-picture-preview-image>
                <footer class="rfm-lightbox-footer" data-picture-preview-actions hidden>
                    <div class="rfm-lightbox-footer-main">
                        <div class="rfm-lightbox-meta">
                            <span class="rfm-lightbox-eyebrow">Image actions</span>
                            <strong data-picture-preview-title></strong>
                        </div>
                        <div class="rfm-lightbox-buttons" role="toolbar" aria-label="Image actions">
                            <button type="button" class="rfm-lightbox-btn rfm-lightbox-btn-muted" data-picture-preview-replace-toggle>
                                Replace
                            </button>
                            <form method="POST" action="" data-picture-preview-delete-form onsubmit="return confirm('Delete this image? This cannot be undone.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rfm-lightbox-btn rfm-lightbox-btn-danger">Delete</button>
                            </form>
                            <button type="button" class="rfm-lightbox-btn rfm-lightbox-btn-primary" data-picture-preview-close>
                                Close
                            </button>
                        </div>
                    </div>
                    <div class="rfm-lightbox-replace" data-picture-preview-replace-panel hidden>
                        <form method="POST" action="" enctype="multipart/form-data" data-picture-preview-replace-form>
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="target_type" value="">
                            <input type="hidden" name="target_id" value="">
                            <div class="rfm-media-add">
                                <div class="rfm-media-capture">
                                    <label class="rfm-media-btn rfm-media-btn-primary">
                                        Take Photo
                                        <input type="file" name="camera_image" accept="image/*" capture="environment" class="sr-only" data-rfm-camera>
                                    </label>
                                    <span class="rfm-media-or">or</span>
                                    <label class="rfm-media-btn rfm-media-btn-secondary">
                                        Upload File
                                        <input type="file" name="uploaded_image" accept="image/*" class="sr-only" data-rfm-upload>
                                    </label>
                                </div>
                            </div>
                            <div class="rfm-lightbox-replace-actions">
                                <button type="button" class="rfm-lightbox-btn rfm-lightbox-btn-muted" data-picture-preview-replace-cancel>Cancel</button>
                                <button type="submit" class="rfm-lightbox-btn rfm-lightbox-btn-primary">Upload & Replace</button>
                            </div>
                            <div class="rfm-lightbox-replace-status" data-picture-preview-replace-status hidden></div>
                        </form>
                    </div>
                </footer>
            </section>
        </div>
    </div>
    {{-- Intake Preview Modal --}}
    <div id="intake-preview-modal" style="position:fixed;inset:0;z-index:200;align-items:flex-end;justify-content:center;display:none;">
        <div id="intake-preview-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);"></div>
        <div id="intake-preview-sheet" style="position:relative;z-index:1;width:100%;max-width:480px;max-height:92vh;border-radius:24px 24px 0 0;background:#fff;box-shadow:0 -16px 60px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden;transform:translateY(100%);transition:transform .3s cubic-bezier(.32,.72,0,1);">
            {{-- Handle bar --}}
            <div style="display:flex;justify-content:center;padding:.6rem 0 .3rem;">
                <span style="width:36px;height:4px;border-radius:99px;background:#d1d5db;"></span>
            </div>
            {{-- Header --}}
            <div style="display:flex;align-items:center;justify-content:space-between;padding:0 1.25rem .75rem;border-bottom:1px solid #f3f4f6;">
                <strong id="intake-preview-title" style="font-size:1.1rem;color:#111827;letter-spacing:-.02em;">Loading...</strong>
                <button type="button" id="intake-preview-close" style="background:none;border:none;padding:.25rem;cursor:pointer;color:#9ca3af;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>
            {{-- Body --}}
            <div id="intake-preview-body" style="flex:1;overflow-y:auto;padding:1.25rem;-webkit-overflow-scrolling:touch;">
                <div id="intake-preview-loading" style="text-align:center;padding:3rem 1rem;color:#9ca3af;font-weight:700;">Loading intake data...</div>
                <div id="intake-preview-content" style="display:none;"></div>
            </div>
            {{-- Footer --}}
            <div id="intake-preview-footer" style="padding:.75rem 1.25rem 1rem;border-top:1px solid #f3f4f6;display:none;gap:.5rem;">
                <a id="intake-preview-edit-link" href="#" style="flex:1;display:inline-flex;align-items:center;justify-content:center;padding:.75rem 1rem;background:#111827;color:#fff;border-radius:14px;font-weight:900;font-size:.88rem;text-decoration:none;">Open Full View</a>
                <button type="button" id="intake-preview-close-btn" style="padding:.75rem 1rem;background:#f3f4f6;color:#374151;border:none;border-radius:14px;font-weight:900;font-size:.88rem;cursor:pointer;">Close</button>
            </div>
        </div>
    </div>

    <script>
    (() => {
        const filterForm = document.querySelector('[data-his-filters]');
        if (filterForm) {
            const cascade = JSON.parse(filterForm.dataset.hisFilterCascade || '{"typesByBrand":{},"stylesByKey":{}}');
            const brandSelect = filterForm.querySelector('[data-his-filter-brand]');
            const productTypeSelect = filterForm.querySelector('[data-his-filter-product-type]');
            const styleSelect = filterForm.querySelector('[data-his-filter-style]');
            const productTypeHint = filterForm.querySelector('[data-his-filter-product-type-hint]');
            const styleHint = filterForm.querySelector('[data-his-filter-style-hint]');

            const formatCount = (count) => new Intl.NumberFormat().format(count);

            const fillSelect = (select, options, placeholder, selected) => {
                select.innerHTML = '';
                const blank = document.createElement('option');
                blank.value = '';
                blank.textContent = placeholder;
                select.appendChild(blank);
                (options || []).forEach((option) => {
                    const row = document.createElement('option');
                    row.value = option.label;
                    row.textContent = `${option.label} (${formatCount(option.count)})`;
                    if (option.label === selected) {
                        row.selected = true;
                    }
                    select.appendChild(row);
                });
            };

            const setHint = (element, message) => {
                if (!element) {
                    return;
                }
                element.textContent = message;
                element.hidden = message === '';
                element.style.display = message === '' ? 'none' : '';
            };

            const syncProductType = (brand, selected = '') => {
                const disabled = brand === '';
                productTypeSelect.disabled = disabled;
                setHint(productTypeHint, disabled ? 'Choose a brand to see its product types.' : '');

                if (disabled) {
                    fillSelect(productTypeSelect, [], 'Choose a brand first', '');
                    return;
                }

                fillSelect(
                    productTypeSelect,
                    cascade.typesByBrand?.[brand] || [],
                    `All product types for ${brand}`,
                    selected,
                );
            };

            const syncStyle = (brand, productType, selected = '') => {
                const disabled = brand === '' || productType === '';
                styleSelect.disabled = disabled;

                if (brand === '') {
                    setHint(styleHint, '');
                    fillSelect(styleSelect, [], 'Choose a brand first', '');
                    return;
                }

                if (productType === '') {
                    setHint(styleHint, 'Choose a product type to see its styles and families.');
                    fillSelect(styleSelect, [], 'Choose a product type first', '');
                    return;
                }

                setHint(styleHint, '');
                const key = `${brand}|${productType}`;
                fillSelect(
                    styleSelect,
                    cascade.stylesByKey?.[key] || [],
                    `All styles for ${productType}`,
                    selected,
                );
            };

            const applyFilters = () => {
                if (typeof filterForm.requestSubmit === 'function') {
                    filterForm.requestSubmit();
                } else {
                    filterForm.submit();
                }
            };

            brandSelect?.addEventListener('change', () => {
                const brand = brandSelect.value;
                syncProductType(brand, '');
                syncStyle(brand, '', '');
                applyFilters();
            });

            productTypeSelect?.addEventListener('change', () => {
                const brand = brandSelect.value;
                const productType = productTypeSelect.value;
                syncStyle(brand, productType, '');
                applyFilters();
            });

            styleSelect?.addEventListener('change', applyFilters);

            syncProductType(brandSelect?.value || '', productTypeSelect?.value || '');
            syncStyle(brandSelect?.value || '', productTypeSelect?.value || '', styleSelect?.value || '');
        }
    })();
    </script>

    <script>
    (() => {
        const modal = document.getElementById('intake-preview-modal');
        const sheet = document.getElementById('intake-preview-sheet');
        const backdrop = document.getElementById('intake-preview-backdrop');
        const titleEl = document.getElementById('intake-preview-title');
        const loadingEl = document.getElementById('intake-preview-loading');
        const contentEl = document.getElementById('intake-preview-content');
        const footerEl = document.getElementById('intake-preview-footer');
        const editLink = document.getElementById('intake-preview-edit-link');
        const closeBtn = document.getElementById('intake-preview-close');
        const closeBtnFooter = document.getElementById('intake-preview-close-btn');

        function openModal() {
            modal.style.display = 'flex';
            requestAnimationFrame(() => {
                sheet.style.transform = 'translateY(0)';
            });
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            sheet.style.transform = 'translateY(100%)';
            setTimeout(() => {
                modal.style.display = 'none';
                contentEl.style.display = 'none';
                contentEl.innerHTML = '';
                loadingEl.style.display = '';
                loadingEl.style.color = '#9ca3af';
                loadingEl.textContent = 'Loading intake data...';
                footerEl.style.display = 'none';
                document.body.style.overflow = '';
            }, 300);
        }

        backdrop.addEventListener('click', closeModal);
        closeBtn.addEventListener('click', closeModal);
        closeBtnFooter.addEventListener('click', closeModal);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.style.display !== 'none') closeModal();
        });

        function field(label, value, full) {
            if (!value && value !== 0) return '';
            const w = full ? 'grid-column:1/-1;' : '';
            return `<div style="${w}">
                <span style="display:block;font-size:.68rem;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin-bottom:.2rem;">${label}</span>
                <span style="display:block;font-size:.92rem;font-weight:700;color:#111827;line-height:1.35;word-break:break-word;">${escHtml(String(value))}</span>
            </div>`;
        }

        function escHtml(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        function renderIntake(data) {
            const d = data.intake || data;
            let html = '';

            // Photo
            if (d.photo_url) {
                html += `<div style="margin-bottom:1rem;border-radius:16px;overflow:hidden;background:#f9fafb;border:1px solid #e5e7eb;">
                    <img src="${escHtml(d.photo_url)}" alt="" style="width:100%;max-height:280px;object-fit:contain;display:block;">
                </div>`;
            }

            // Status chip
            const statusColor = d.status === 'submitted'
                ? 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;'
                : 'background:#fff7ed;color:#92400e;border:1px solid #fcd34d;';
            html += `<div style="margin-bottom:1rem;">
                <span style="${statusColor}display:inline-flex;padding:.3rem .65rem;border-radius:8px;font-size:.75rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;">${escHtml(d.status || 'unknown')}</span>
            </div>`;

            // Fields grid
            html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:.65rem .75rem;">';
            html += field('Brand', d.brand_name, true);
            html += field('Observed Product', d.observed_product_name || d.product_type_name, true);
            html += field('Style / Family', d.style_name);
            html += field('Product Type Status', (d.product_type_status || '').replace(/_/g, ' '));
            html += field('Style Status', (d.style_family_status || '').replace(/_/g, ' '));
            html += field('Catalogue Style', (d.catalogue_style_status || '').replace(/_/g, ' '));

            if (d.shelf_location) html += field('Shelf Location', d.shelf_location);

            const classPath = (d.classification_path || []).filter(Boolean);
            if (classPath.length) html += field('Classification', classPath.join(' > '), true);

            html += '</div>';

            // Variant structure
            const vs = d.variant_structure || {};
            const groups = vs.groups || [];
            const common = vs.common_variants || [];
            if (groups.length || common.length || (d.variant_groups && d.variant_groups.length)) {
                html += `<div style="margin-top:1rem;padding-top:.75rem;border-top:1px solid #f3f4f6;">
                    <span style="display:block;font-size:.72rem;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:.5rem;">Variants</span>`;

                if (vs.main_axis) {
                    html += `<div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:.5rem;">
                        <span style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;padding:.25rem .55rem;border-radius:8px;font-size:.75rem;font-weight:900;">${escHtml(vs.main_axis)}</span>
                    </div>`;
                }

                const allGroups = groups.length ? groups : (d.variant_groups || []);
                allGroups.forEach(g => {
                    const label = g.main_value || g.name || 'Variant';
                    const vals = g.sub_values || g.values || [];
                    html += `<div style="margin-bottom:.4rem;padding:.55rem .65rem;background:#fafaf9;border:1px solid #e7e5e4;border-radius:12px;">
                        <strong style="font-size:.82rem;color:#111827;">${escHtml(label)}</strong>
                        ${g.sub_axis ? `<span style="color:#6b7280;font-size:.78rem;"> (${escHtml(g.sub_axis)})</span>` : ''}
                        <div style="margin-top:.25rem;font-size:.82rem;color:#4b5563;font-weight:600;">${escHtml(vals.join(', ') || 'No values')}</div>
                    </div>`;
                });

                common.forEach(c => {
                    html += `<div style="margin-bottom:.4rem;padding:.55rem .65rem;background:#fafaf9;border:1px solid #e7e5e4;border-radius:12px;">
                        <strong style="font-size:.82rem;color:#111827;">${escHtml(c.name || 'Common')}</strong>
                        <div style="margin-top:.25rem;font-size:.82rem;color:#4b5563;font-weight:600;">${escHtml((c.values || []).join(', ') || 'No values')}</div>
                    </div>`;
                });

                const skuCount = (vs.summary || {}).sellable_combination_count || (vs.sku_matrix || []).length;
                if (skuCount) {
                    html += `<div style="margin-top:.35rem;font-size:.82rem;font-weight:800;color:#6b7280;">${skuCount} mapped SKU${skuCount === 1 ? '' : 's'}</div>`;
                }
                html += '</div>';
            }

            // Verification URLs
            const urls = (d.verification_urls || []).filter(Boolean);
            if (urls.length) {
                html += `<div style="margin-top:1rem;padding-top:.75rem;border-top:1px solid #f3f4f6;">
                    <span style="display:block;font-size:.72rem;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:.4rem;">Sources</span>`;
                urls.forEach((u, i) => {
                    html += `<a href="${escHtml(u)}" target="_blank" rel="noopener" style="display:block;margin-bottom:.3rem;font-size:.82rem;color:#2563eb;font-weight:700;word-break:break-all;text-decoration:none;">Source ${i + 1}</a>`;
                });
                html += '</div>';
            }

            // Notes
            if (d.visible_text_notes) {
                html += `<div style="margin-top:1rem;padding:.65rem .75rem;background:#fefce8;border:1px solid #fde68a;border-radius:12px;">
                    <span style="display:block;font-size:.68rem;font-weight:900;text-transform:uppercase;color:#92400e;letter-spacing:.06em;margin-bottom:.2rem;">Notes</span>
                    <p style="margin:0;font-size:.85rem;color:#78350f;line-height:1.45;white-space:pre-wrap;">${escHtml(d.visible_text_notes)}</p>
                </div>`;
            }

            // Photos
            const photos = d.photos || [];
            if (photos.length > 1) {
                html += `<div style="margin-top:1rem;padding-top:.75rem;border-top:1px solid #f3f4f6;">
                    <span style="display:block;font-size:.72rem;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:.5rem;">Photos (${photos.length})</span>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:.5rem;">`;
                photos.forEach(p => {
                    const src = p.url || p.photo_url || '';
                    if (src) html += `<img src="${escHtml(src)}" alt="" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:12px;border:1px solid #e5e7eb;background:#f9fafb;">`;
                });
                html += '</div></div>';
            }

            // Sync time
            if (d.last_synced_at) {
                html += `<div style="margin-top:1rem;text-align:center;font-size:.75rem;color:#9ca3af;font-weight:700;">Last synced: ${escHtml(d.last_synced_at)}</div>`;
            }

            return html;
        }

        document.addEventListener('click', async (e) => {
            const card = e.target.closest('[data-intake-preview]');
            if (!card) return;

            // Don't intercept clicks on action buttons, links, or forms inside the card
            if (e.target.closest('a, button, form, .his-recent-actions, .his-card-actions, [data-picture-preview-trigger]')) return;

            e.preventDefault();
            const url = card.dataset.intakePreview;
            const editUrl = card.dataset.intakeEditUrl;

            titleEl.textContent = 'Loading...';
            editLink.href = editUrl || '#';
            openModal();

            try {
                const resp = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const json = await resp.json();

                if (!resp.ok || !json.ok) throw new Error(json.message || 'Failed to load');

                const intake = json.intake || {};
                titleEl.textContent = (intake.brand_name || 'Unknown') + ' — ' + (intake.observed_product_name || intake.product_type_name || 'Product');

                contentEl.innerHTML = renderIntake(json);
                loadingEl.style.display = 'none';
                contentEl.style.display = 'block';
                footerEl.style.display = 'flex';
            } catch (err) {
                loadingEl.textContent = 'Error: ' + (err.message || 'Could not load intake');
                loadingEl.style.color = '#dc2626';
            }
        });
    })();
    </script>
@endsection
