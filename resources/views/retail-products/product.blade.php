@extends('layouts.app')

@php
    $online = $product->ecommerceProfile;
    $displayName = $online?->online_title ?: $product->name;
@endphp

@section('title', $displayName . ' - Sellable Product')
@section('section', 'Final Catalogue')
@section('heading', 'Sellable SKU')

@php
    $brandName = $product->brand?->name ?? $family?->brand?->name ?? $family?->brand_name ?? 'Unknown brand';
    $familyName = $family?->family_name ?? 'No family';
    $price = $product->price;
    $pos = $product->posProfile;
    $familyOnline = $family?->ecommerceProfile;
    $inventory = $product->inventoryLevels->first();
    $primaryUrl = $primaryMedia?->displayUrl();
    $onlineTitle = $displayName;
    $receiptName = $pos?->receipt_name ?: $product->receipt_name ?: $product->name;
    $shortDescription = $online?->short_description ?: ($product->description ? \Illuminate\Support\Str::limit($product->description, 180) : null);
    $longDescription = $online?->long_description ?: $product->description ?: $familyOnline?->long_description;
    $categoryAssignments = $product->categoryAssignments->isNotEmpty()
        ? $product->categoryAssignments
        : ($family?->categoryAssignments ?? collect());
    $productSourceIds = $product->sources->pluck('id')->all();
    $directSourceUrls = $product->sources
        ->pluck('source_url')
        ->filter()
        ->map(fn ($url) => strtolower(trim($url)))
        ->all();
    $familyOnlySources = ($family?->sources ?? collect())
        ->reject(fn ($source) => in_array($source->id, $productSourceIds, true))
        ->reject(fn ($source) => str_contains((string) $source->source_type, 'sku') && in_array(strtolower(trim((string) $source->source_url)), $directSourceUrls, true))
        ->unique(fn ($source) => strtolower(trim((string) $source->source_url)) ?: implode('|', [
            $source->source_type,
            $source->source_table,
            $source->source_id,
        ]))
        ->values();
    $sources = $product->sources->concat($familyOnlySources)->values();
    $money = fn ($value) => $value !== null ? 'GBP ' . number_format((float) $value, 2) : 'Not set';
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $readiness = [
        ['label' => 'Price', 'ok' => $price?->retail_price !== null],
        ['label' => 'Barcode or SKU', 'ok' => filled($product->barcode) || filled($product->sku)],
        ['label' => 'Product image', 'ok' => $allMedia->isNotEmpty()],
        ['label' => 'Stock record', 'ok' => $product->inventoryLevels->isNotEmpty()],
        ['label' => 'POS active', 'ok' => (bool) $product->is_pos_active],
        ['label' => 'Ecommerce active', 'ok' => (bool) $product->is_ecommerce_active],
    ];
@endphp

@section('content')
    <section class="sku-show">
        <div class="sku-show-inner">
            @if ($family)
                <div class="sku-show-top">
                    <a class="sku-back" href="{{ route('retail-products.families.show', $family) }}">
                        <span class="sku-back-icon" aria-hidden="true">&larr;</span>
                        Back to family
                    </a>
                    <span class="sku-show-context">Sellable SKU</span>
                </div>
            @else
                <div class="sku-show-top sku-show-top-solo">
                    <span class="sku-show-context">Sellable SKU</span>
                </div>
            @endif

            <header class="sku-hero">
                <div class="sku-hero-copy">
                    <div class="sku-hero-intro">
                        <p class="sku-eyebrow">Final product record</p>
                        <h1>{{ $displayName }}</h1>
                    </div>
                    <div class="sku-meta-row">
                        <span class="sku-chip">{{ $brandName }}</span>
                        <span class="sku-chip">{{ $familyName }}</span>
                        <span class="sku-chip {{ $product->is_pos_active ? 'is-on' : 'is-off' }}">{{ $product->is_pos_active ? 'POS active' : 'POS off' }}</span>
                        <span class="sku-chip {{ $product->is_ecommerce_active ? 'is-on' : 'is-off' }}">{{ $product->is_ecommerce_active ? 'Online active' : 'Online off' }}</span>
                        <span class="sku-chip {{ $product->is_inventory_tracked ? 'is-on' : 'is-off' }}">{{ $product->is_inventory_tracked ? 'Inventory tracked' : 'Not tracked' }}</span>
                    </div>
                </div>

                <div class="sku-hero-image">
                    @if ($primaryUrl)
                        <img src="{{ $primaryUrl }}" alt="{{ $primaryMedia->alt_text ?: $displayName }}">
                    @else
                        <div class="sku-empty-image">No product image yet</div>
                    @endif
                </div>
            </header>

        <section class="sku-card is-full sku-card-highlight">
            <div class="sku-card-head">
                <h2>Operational readiness</h2>
                <p class="sku-card-lead">What still needs attention before this SKU is fully sellable.</p>
            </div>
            <div class="sku-ready">
                @foreach ($readiness as $item)
                    <div class="sku-ready-item {{ $item['ok'] ? 'is-ok' : '' }}" role="status">
                        <span class="sku-ready-status">{{ $item['ok'] ? 'Ready' : 'Missing' }}</span>
                        <span class="sku-ready-label">{{ $item['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="sku-card is-full">
            <div class="sku-card-head">
                <h2>How this SKU looks in the system</h2>
                <p class="sku-card-lead">Ecommerce, till, and stock views your team and customers see.</p>
            </div>
            <div class="sku-preview-grid">
                <article class="sku-preview">
                    <div class="sku-preview-head">
                        <span>Ecommerce</span>
                        <span>{{ $product->is_ecommerce_active ? 'Published-ready' : 'Draft' }}</span>
                    </div>
                    <div class="sku-ecom-card">
                        <div class="sku-ecom-img">
                            @if ($primaryUrl)
                                <img src="{{ $primaryUrl }}" alt="">
                            @else
                                <span>No image</span>
                            @endif
                        </div>
                        <h3>{{ $onlineTitle }}</h3>
                        <div class="sku-price">{{ $money($price?->retail_price) }}</div>
                        <p>{{ $shortDescription ?: 'No short description added yet.' }}</p>
                        <div class="sku-fake-btn">Add to cart preview</div>
                    </div>
                </article>

                <article class="sku-preview">
                    <div class="sku-preview-head">
                        <span>POS</span>
                        <span>{{ $product->is_pos_active ? 'Till active' : 'Disabled' }}</span>
                    </div>
                    <div class="sku-pos-screen">
                        <div class="sku-pos-line">
                            <div>
                                <div class="sku-pos-name">{{ $receiptName }}</div>
                                <div class="sku-pos-code">{{ $product->barcode ?: $product->sku ?: 'No scan code yet' }}</div>
                            </div>
                            <strong>{{ $money($price?->retail_price) }}</strong>
                        </div>
                        <div class="sku-pos-line">
                            <span>Category</span>
                            <strong>{{ $pos?->pos_category ?: $family?->product_type_name ?: 'Not set' }}</strong>
                        </div>
                        <div class="sku-pos-line">
                            <span>Discount</span>
                            <strong>{{ $pos?->discount_allowed === false ? 'Blocked' : 'Allowed' }}</strong>
                        </div>
                    </div>
                </article>

                <article class="sku-preview">
                    <div class="sku-preview-head">
                        <span>Inventory</span>
                        <span>{{ $product->is_inventory_tracked ? 'Tracked' : 'Untracked' }}</span>
                    </div>
                    <div class="sku-inv-panel">
                        <span class="sku-inv-label">Total stock</span>
                        <strong class="sku-stock-number">{{ $quantity($stockQuantity) }}</strong>
                        <div class="sku-kv">
                            <div class="sku-kv-row">
                                <span>Location</span>
                                <strong>{{ $inventory?->location?->name ?: 'Not set' }}</strong>
                            </div>
                            <div class="sku-kv-row">
                                <span>Shelf</span>
                                <strong>{{ $inventory?->shelf_location ?: 'Not set' }}</strong>
                            </div>
                            <div class="sku-kv-row">
                                <span>Supplier</span>
                                <strong>{{ $inventory?->supplier ?: 'Not set' }}</strong>
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <section class="sku-grid">
            <article class="sku-card">
                <h2>Product identity</h2>
                <div class="sku-kv">
                    <div class="sku-kv-row">
                        <span>Final product name</span>
                        <strong>{{ $displayName }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Source product name</span>
                        <strong>{{ $product->name }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Brand</span>
                        <strong>{{ $brandName }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Family</span>
                        <strong>{{ $familyName }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>SKU</span>
                        <strong>{{ $product->sku ?: 'Not set' }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Barcode</span>
                        <strong>{{ $product->barcode ?: 'Not set' }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Status</span>
                        <strong>{{ ucfirst($product->status) }}</strong>
                    </div>
                </div>
            </article>

            <article class="sku-card">
                <h2>Variants</h2>
                <div class="sku-kv">
                    @forelse ($variantValues as $value)
                        <div class="sku-kv-row">
                            <span>{{ $value->group?->name ?? 'Variant' }}</span>
                            <strong>{{ $value->option?->label ?? 'Not set' }}</strong>
                        </div>
                    @empty
                        <div class="sku-kv-row">
                            <span>Variant</span>
                            <strong>No variant values recorded</strong>
                        </div>
                    @endforelse
                </div>
            </article>

            <article class="sku-card">
                <h2>Price and tax</h2>
                <div class="sku-kv">
                    <div class="sku-kv-row">
                        <span>Retail price</span>
                        <strong>{{ $money($price?->retail_price) }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Cost price</span>
                        <strong>{{ $money($price?->cost_price) }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>VAT</span>
                        <strong>{{ $price?->vat_rate !== null ? $price->vat_rate . '%' : 'Not set' }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Tax class</span>
                        <strong>{{ $price?->tax_class ?: $pos?->tax_class ?: 'standard' }}</strong>
                    </div>
                </div>
            </article>

            <article class="sku-card">
                <h2>POS fields</h2>
                <div class="sku-kv">
                    <div class="sku-kv-row">
                        <span>Receipt</span>
                        <strong>{{ $receiptName }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Search</span>
                        <strong>{{ $pos?->quick_search_keywords ?: $product->search_keywords ?: 'Not set' }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>POS category</span>
                        <strong>{{ $pos?->pos_category ?: $family?->product_type_name ?: 'Not set' }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Quick sale</span>
                        <strong>{{ $pos?->quick_sale_enabled === false ? 'No' : 'Yes' }}</strong>
                    </div>
                </div>
            </article>

            <article class="sku-card">
                <h2>Inventory fields</h2>
                <div class="sku-kv">
                    <div class="sku-kv-row">
                        <span>Stock</span>
                        <strong>{{ $quantity($stockQuantity) }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Low stock</span>
                        <strong>{{ $inventory?->low_stock_threshold !== null ? $quantity($inventory->low_stock_threshold) : 'Not set' }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Reorder</span>
                        <strong>{{ $inventory?->reorder_quantity !== null ? $quantity($inventory->reorder_quantity) : 'Not set' }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Supplier code</span>
                        <strong>{{ $inventory?->supplier_product_code ?: 'Not set' }}</strong>
                    </div>
                </div>
            </article>

            <article class="sku-card">
                <h2>Category path</h2>
                <div class="sku-kv">
                    @forelse ($categoryAssignments as $assignment)
                        <div class="sku-kv-row">
                            <span>{{ $assignment->axis?->name ?? $assignment->assignment_type ?? 'Category' }}</span>
                            <strong>
                                @if ($assignment->node?->parent)
                                    {{ $assignment->node->parent->name }} /
                                @endif
                                {{ $assignment->node?->name ?? 'Not set' }}
                            </strong>
                        </div>
                    @empty
                        <div class="sku-kv-row">
                            <span>Scaffold</span>
                            <strong>No category assignment yet</strong>
                        </div>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="sku-card is-full sku-media-section">
            <header class="sku-media-header">
                <div class="sku-card-head sku-media-header-copy">
                    <h2>Product images</h2>
                    <p class="sku-card-lead">Visual assets used on the shop, till, and catalogue surfaces.</p>
                </div>
                <div class="sku-media-stats" aria-label="Image counts">
                    <span class="sku-media-stat">
                        <strong>{{ $productMedia->count() }}</strong>
                        SKU image{{ $productMedia->count() === 1 ? '' : 's' }}
                    </span>
                    @if ($familyMedia->isNotEmpty())
                        <span class="sku-media-stat is-family">
                            <strong>{{ $familyMedia->count() }}</strong>
                            family image{{ $familyMedia->count() === 1 ? '' : 's' }}
                        </span>
                    @endif
                </div>
            </header>

            @if ($allMedia->isEmpty())
                <div class="sku-media-empty">
                    <div class="sku-media-empty-icon" aria-hidden="true">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                    </div>
                    <p>No images attached to this SKU or family yet.</p>
                </div>
            @else
                @if ($productMedia->isEmpty() && $familyMedia->isNotEmpty())
                    <p class="sku-media-inherit-note">No SKU-specific images yet — showing inherited family assets below.</p>
                @endif

                @foreach ([
                    ['title' => 'SKU images', 'hint' => 'Attached directly to this sellable product', 'items' => $productMedia, 'scope' => 'sku'],
                    ['title' => 'Inherited family images', 'hint' => 'Shared across variants in the product family', 'items' => $familyMedia, 'scope' => 'family'],
                ] as $mediaBlock)
                    @continue($mediaBlock['items']->isEmpty())
                    <div class="sku-media-block" data-media-scope="{{ $mediaBlock['scope'] }}">
                        <div class="sku-media-block-head">
                            <h3>{{ $mediaBlock['title'] }}</h3>
                            <p>{{ $mediaBlock['hint'] }}</p>
                        </div>
                        <div class="sku-media-gallery">
                            @foreach ($mediaBlock['items'] as $media)
                                @php
                                    $imageUrl = $media->displayUrl();
                                    $roleLabel = ucfirst(str_replace('_', ' ', $media->image_role ?: 'image'));
                                    $contextLabel = $media->usage_context ? ucfirst(str_replace('_', ' ', $media->usage_context)) : 'All channels';
                                    $sourceLabel = $media->sourceDisplay();
                                    $fileName = $media->storage_path ? basename($media->storage_path) : null;
                                @endphp
                                <article class="sku-media-item {{ $media->is_primary ? 'is-primary' : '' }} is-{{ $mediaBlock['scope'] }}">
                                    <div class="sku-media-frame">
                                        @if ($imageUrl)
                                            <img src="{{ $imageUrl }}" alt="{{ $media->alt_text ?: $displayName }}" loading="lazy">
                                        @else
                                            <span class="sku-media-no-preview">No preview</span>
                                        @endif
                                        <div class="sku-media-badges">
                                            @if ($media->is_primary)
                                                <span class="sku-media-badge is-primary">Primary</span>
                                            @endif
                                            <span class="sku-media-badge">{{ $mediaBlock['scope'] === 'sku' ? 'SKU' : 'Family' }}</span>
                                        </div>
                                    </div>
                                    <div class="sku-media-body">
                                        <p class="sku-media-title">{{ $roleLabel }}</p>
                                        <p class="sku-media-sub">{{ $contextLabel }}</p>
                                        <p class="sku-media-source">{{ $sourceLabel }}</p>
                                        @if ($media->external_url || $media->storage_path)
                                            <details class="sku-media-tech">
                                                <summary>File details</summary>
                                                <dl class="sku-media-tech-list">
                                                    @if ($fileName)
                                                        <div>
                                                            <dt>File</dt>
                                                            <dd>{{ $fileName }}</dd>
                                                        </div>
                                                    @endif
                                                    @if ($media->storage_disk && $media->storage_path)
                                                        <div>
                                                            <dt>Storage</dt>
                                                            <dd><code>{{ $media->storage_disk }}</code></dd>
                                                        </div>
                                                    @endif
                                                    @if ($media->external_url)
                                                        <div>
                                                            <dt>Source URL</dt>
                                                            <dd><a href="{{ $media->external_url }}" target="_blank" rel="noopener">Open original</a></dd>
                                                        </div>
                                                    @endif
                                                </dl>
                                            </details>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif
        </section>

        <section class="sku-grid">
            <article class="sku-card is-wide">
                <div class="sku-card-head">
                    <h2>Ecommerce content</h2>
                    <p class="sku-card-lead">Published copy and merchandising fields for the web shop.</p>
                </div>
                <div class="sku-kv">
                    <div class="sku-kv-row">
                        <span>Online title</span>
                        <strong>{{ $onlineTitle }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>SEO slug</span>
                        <strong>{{ $online?->seo_slug ?: $product->slug }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Tags</span>
                        <strong>{{ collect($online?->tags ?? [])->implode(', ') ?: 'Not set' }}</strong>
                    </div>
                    <div class="sku-kv-row">
                        <span>Click collect</span>
                        <strong>{{ $online?->click_and_collect_enabled === false ? 'No' : 'Yes' }}</strong>
                    </div>
                </div>
                <div class="sku-description">
                    {!! nl2br(e($longDescription ? \Illuminate\Support\Str::limit($longDescription, 900) : 'No long description added yet.')) !!}
                </div>
            </article>

            <article class="sku-card">
                <h2>Sources</h2>
                @if ($familyOnlySources->isNotEmpty())
                    <p class="sku-card-lead">{{ $product->sources->count() }} direct source{{ $product->sources->count() === 1 ? '' : 's' }} · {{ $familyOnlySources->count() }} family source{{ $familyOnlySources->count() === 1 ? '' : 's' }}</p>
                @endif
                <div class="sku-kv">
                    @forelse ($sources as $source)
                        <div class="sku-kv-row">
                            <span>{{ in_array($source->id, $productSourceIds, true) ? 'SKU source' : 'Family source' }}</span>
                            <strong>
                                {{ str_replace('_', ' ', $source->source_type) }}<br>
                                {{ $source->confidence ? 'Confidence ' . $source->confidence : 'No confidence' }}
                                @if ($source->source_url)
                                    <br><a href="{{ $source->source_url }}" target="_blank" rel="noopener">Open source</a>
                                @endif
                            </strong>
                        </div>
                    @empty
                        <div class="sku-kv-row">
                            <span>Source</span>
                            <strong>No source link recorded</strong>
                        </div>
                    @endforelse
                </div>
            </article>
        </section>
        </div>
    </section>
@endsection
