@extends('layouts.app')

@section('title', $catalogue->name.' Product Type Structure')

@section('content')
    @include('brand-catalogue._page-label', ['label' => 'Product Type Structure', 'context' => $catalogue->name])

    @include('partials.catalogue-breadcrumb', [
        'items' => [
            ['label' => 'Brand Catalogue', 'url' => route('brand-catalogue.index')],
            ['label' => $catalogue->name, 'url' => route('brand-catalogue.show', $catalogue)],
            ['label' => 'Product Type Structure', 'current' => true],
        ],
    ])

    <div class="sr-hero taxonomy-hero">
        <div class="sr-hero-top">
            <div class="sr-hero-title-block">
                <h1 class="sr-hero-title">Product Type Structure</h1>
                <p class="bc-subtitle">A cross-brand view of the catalogue by major product type, product group, and style family.</p>
            </div>
            <div class="bc-hero-actions">
                <a href="{{ route('brand-catalogue.show', $catalogue) }}" class="bc-action-btn bc-action-view">Brand View</a>
            </div>
        </div>
        <div class="sr-stats">
            <div class="sr-stat">
                <span class="sr-stat-num">{{ number_format($summary['major_type_count']) }}</span>
                <span class="sr-stat-label">major types</span>
            </div>
            <div class="sr-stat">
                <span class="sr-stat-num">{{ number_format($summary['product_type_count']) }}</span>
                <span class="sr-stat-label">product groups</span>
            </div>
            <div class="sr-stat">
                <span class="sr-stat-num">{{ number_format($summary['style_count']) }}</span>
                <span class="sr-stat-label">style families</span>
            </div>
            <div class="sr-stat">
                <span class="sr-stat-num">{{ number_format($summary['sku_count']) }}</span>
                <span class="sr-stat-label">sellable SKUs</span>
            </div>
        </div>
    </div>

    <div class="taxonomy-toolbar">
        <label class="taxonomy-search">
            <span>Find style, brand, or product type</span>
            <input type="search" id="taxonomy-search" placeholder="Search: French curl, ponytail, Sleek, bulk...">
        </label>
        <div class="taxonomy-hint">This is a structure view only. Editing still happens inside each brand/style page.</div>
    </div>

    <div class="taxonomy-grid" id="taxonomy-grid">
        @foreach ($majorGroups as $major)
            <section class="taxonomy-major" data-taxonomy-block data-search="{{ Str::lower($major['name'].' '.$major['brands']->implode(' ')) }}">
                <div class="taxonomy-major-head">
                    <div>
                        <p class="taxonomy-kicker">Major Product Type</p>
                        <h2>{{ $major['name'] }}</h2>
                    </div>
                    <div class="taxonomy-major-stats">
                        <span>{{ number_format($major['product_type_count']) }} groups</span>
                        <span>{{ number_format($major['style_count']) }} styles</span>
                        <span>{{ number_format($major['sku_count']) }} SKUs</span>
                    </div>
                </div>

                <div class="taxonomy-brand-strip">
                    @foreach ($major['brands']->take(12) as $brandName)
                        <span>{{ $brandName }}</span>
                    @endforeach
                    @if ($major['brands']->count() > 12)
                        <span>+{{ $major['brands']->count() - 12 }} more</span>
                    @endif
                </div>

                <div class="taxonomy-type-list">
                    @foreach ($major['product_types'] as $type)
                        <details class="taxonomy-type" data-taxonomy-block data-search="{{ Str::lower($major['name'].' '.$type['name'].' '.$type['brands']->implode(' ')) }}" @if ($loop->first) open @endif>
                            <summary>
                                <span class="taxonomy-type-name">{{ $type['name'] }}</span>
                                <span class="taxonomy-type-meta">{{ number_format($type['style_count']) }} styles · {{ number_format($type['sku_count']) }} SKUs · {{ $type['brands']->count() }} brands</span>
                            </summary>

                            <div class="taxonomy-style-grid">
                                @foreach ($type['styles'] as $style)
                                    <a
                                        href="{{ $style['url'] }}"
                                        class="taxonomy-style-card"
                                        data-taxonomy-item
                                        data-search="{{ Str::lower($major['name'].' '.$type['name'].' '.$style['style_name'].' '.$style['style_family'].' '.$style['brand_name'].' '.$style['line_name'].' '.$style['material_name']) }}"
                                    >
                                        <span class="taxonomy-style-family">{{ $style['style_family'] }}</span>
                                        <strong>{{ $style['style_name'] }}</strong>
                                        <span>{{ $style['brand_name'] }} · {{ $style['line_name'] }}</span>
                                        <span class="taxonomy-style-meta">{{ $style['material_name'] }} · {{ number_format($style['sku_count']) }} SKU{{ $style['sku_count'] === 1 ? '' : 's' }} · {{ number_format($style['variant_count']) }} variant group{{ $style['variant_count'] === 1 ? '' : 's' }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    <style>
        .taxonomy-hero { background: linear-gradient(135deg, #f8f5ed 0%, #e9f1ef 46%, #e5eaf5 100%); }
        .taxonomy-toolbar { display: grid; gap: 12px; margin: 18px 0 20px; }
        .taxonomy-search { display: grid; gap: 8px; color: #334155; font-weight: 800; }
        .taxonomy-search input { width: 100%; border: 1px solid rgba(15, 23, 42, .16); border-radius: 20px; padding: 16px 18px; font-size: 16px; background: #fff; box-shadow: 0 14px 36px rgba(15, 23, 42, .08); }
        .taxonomy-hint { color: #64748b; font-size: 14px; }
        .taxonomy-grid { display: grid; gap: 22px; }
        .taxonomy-major { border: 1px solid rgba(15, 23, 42, .1); border-radius: 28px; padding: 22px; background: #fff; box-shadow: 0 20px 55px rgba(15, 23, 42, .08); }
        .taxonomy-major-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; border-bottom: 1px solid rgba(15, 23, 42, .08); padding-bottom: 16px; }
        .taxonomy-kicker { margin: 0 0 5px; color: #64748b; font-size: 12px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; }
        .taxonomy-major h2 { margin: 0; color: #111827; font-size: clamp(24px, 3vw, 38px); line-height: 1.04; }
        .taxonomy-major-stats { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
        .taxonomy-major-stats span, .taxonomy-brand-strip span { border-radius: 999px; background: #f1f5f9; color: #334155; padding: 8px 11px; font-size: 13px; font-weight: 800; }
        .taxonomy-brand-strip { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0; }
        .taxonomy-type-list { display: grid; gap: 12px; }
        .taxonomy-type { border: 1px solid rgba(15, 23, 42, .1); border-radius: 20px; background: #f8fafc; overflow: hidden; }
        .taxonomy-type summary { cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 15px 16px; }
        .taxonomy-type summary::-webkit-details-marker { display: none; }
        .taxonomy-type-name { color: #0f172a; font-size: 16px; font-weight: 900; }
        .taxonomy-type-meta { color: #64748b; font-size: 13px; font-weight: 800; white-space: nowrap; }
        .taxonomy-style-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px; padding: 0 14px 14px; }
        .taxonomy-style-card { display: grid; gap: 6px; min-height: 142px; border: 1px solid rgba(15, 23, 42, .08); border-radius: 18px; padding: 14px; color: inherit; text-decoration: none; background: #fff; transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease; }
        .taxonomy-style-card:hover { transform: translateY(-2px); border-color: rgba(2, 132, 199, .38); box-shadow: 0 16px 32px rgba(15, 23, 42, .1); }
        .taxonomy-style-family { width: fit-content; border-radius: 999px; background: #e0f2fe; color: #075985; padding: 5px 9px; font-size: 12px; font-weight: 900; }
        .taxonomy-style-card strong { color: #111827; font-size: 15px; line-height: 1.25; }
        .taxonomy-style-card span:not(.taxonomy-style-family) { color: #64748b; font-size: 13px; font-weight: 700; }
        .taxonomy-style-meta { margin-top: auto; }
        .taxonomy-hidden { display: none !important; }

        @media (max-width: 720px) {
            .taxonomy-major { padding: 16px; border-radius: 22px; }
            .taxonomy-major-head, .taxonomy-type summary { align-items: flex-start; flex-direction: column; }
            .taxonomy-major-stats { justify-content: flex-start; }
            .taxonomy-type-meta { white-space: normal; }
        }
    </style>

    <script>
        (() => {
            const search = document.getElementById('taxonomy-search');
            const items = Array.from(document.querySelectorAll('[data-taxonomy-item]'));
            const blocks = Array.from(document.querySelectorAll('[data-taxonomy-block]'));

            search?.addEventListener('input', () => {
                const term = search.value.trim().toLowerCase();

                items.forEach((item) => {
                    item.classList.toggle('taxonomy-hidden', term !== '' && !item.dataset.search.includes(term));
                });

                blocks.forEach((block) => {
                    const childItems = Array.from(block.querySelectorAll('[data-taxonomy-item]'));
                    if (childItems.length === 0) {
                        block.classList.toggle('taxonomy-hidden', term !== '' && !block.dataset.search.includes(term));
                        return;
                    }

                    const hasVisibleChild = childItems.some((item) => !item.classList.contains('taxonomy-hidden'));
                    const matchesSelf = block.dataset.search?.includes(term);
                    block.classList.toggle('taxonomy-hidden', term !== '' && !hasVisibleChild && !matchesSelf);

                    if (hasVisibleChild && block.tagName.toLowerCase() === 'details') {
                        block.open = true;
                    }
                });
            });
        })();
    </script>
@endsection
