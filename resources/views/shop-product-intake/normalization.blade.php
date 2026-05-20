@extends('layouts.app')

@section('title', 'Shop Product Normalization')
@section('section', 'Shop Intake')
@section('heading', 'Normalize Sources')

@section('content')
    <section class="spn-page">
        <style>
            .spn-page {
                max-width: 1180px;
                margin: 0 auto;
                color: #14231f;
            }

            .spn-hero,
            .spn-panel,
            .spn-card {
                border: 1px solid rgba(20, 35, 31, .12);
                border-radius: 28px;
                background: #fffdf8;
                box-shadow: 0 18px 48px rgba(20, 35, 31, .08);
            }

            .spn-hero {
                padding: clamp(1.25rem, 4vw, 2rem);
                background:
                    radial-gradient(circle at 92% 10%, rgba(10, 121, 103, .16), transparent 34%),
                    linear-gradient(135deg, #fffdf8 0%, #edf4ee 100%);
            }

            .spn-eyebrow {
                margin: 0 0 .35rem;
                color: #087464;
                font-size: .78rem;
                font-weight: 900;
                letter-spacing: .14em;
                text-transform: uppercase;
            }

            .spn-hero h2 {
                margin: 0;
                font-size: clamp(2rem, 8vw, 4.75rem);
                line-height: .96;
                letter-spacing: -.06em;
            }

            .spn-note {
                color: rgba(20, 35, 31, .68);
                font-size: 1rem;
                line-height: 1.55;
            }

            .spn-actions,
            .spn-stats,
            .spn-filters,
            .spn-badges,
            .spn-card-actions,
            .spn-pagination {
                display: flex;
                flex-wrap: wrap;
                gap: .75rem;
                align-items: center;
            }

            .spn-actions {
                margin-top: 1.25rem;
            }

            .spn-stat {
                flex: 1 1 140px;
                min-width: 0;
                border-radius: 22px;
                background: rgba(255, 255, 255, .72);
                border: 1px solid rgba(20, 35, 31, .09);
                padding: 1rem;
            }

            .spn-stat strong {
                display: block;
                font-size: 1.8rem;
                line-height: 1;
            }

            .spn-stat span {
                color: rgba(20, 35, 31, .62);
                font-size: .82rem;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: .08em;
            }

            .spn-panel {
                margin-top: 1rem;
                padding: 1rem;
            }

            .spn-filters label {
                flex: 1 1 180px;
                min-width: 0;
                display: grid;
                gap: .35rem;
                color: rgba(20, 35, 31, .66);
                font-size: .78rem;
                font-weight: 900;
                text-transform: uppercase;
                letter-spacing: .08em;
            }

            .spn-filters input,
            .spn-filters select {
                width: 100%;
                min-height: 50px;
                border: 1px solid rgba(20, 35, 31, .18);
                border-radius: 18px;
                background: #fff;
                color: #14231f;
                padding: .85rem 1rem;
                font-size: 1rem;
            }

            .spn-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(min(100%, 360px), 1fr));
                gap: 1rem;
                margin-top: 1rem;
            }

            .spn-card {
                overflow: hidden;
            }

            .spn-card-main {
                display: grid;
                grid-template-columns: 86px minmax(0, 1fr);
                gap: 1rem;
                padding: 1rem;
            }

            .spn-image {
                width: 86px;
                height: 86px;
                border-radius: 20px;
                object-fit: contain;
                background: #f1f0ea;
                border: 1px solid rgba(20, 35, 31, .1);
            }

            .spn-image-empty {
                display: grid;
                place-items: center;
                color: rgba(20, 35, 31, .38);
                font-weight: 900;
            }

            .spn-card h3 {
                margin: 0;
                font-size: 1.15rem;
                line-height: 1.2;
                letter-spacing: -.02em;
            }

            .spn-meta {
                margin: .45rem 0 0;
                color: rgba(20, 35, 31, .64);
                font-size: .9rem;
                line-height: 1.4;
            }

            .spn-pill {
                display: inline-flex;
                align-items: center;
                gap: .35rem;
                border-radius: 999px;
                background: #eff5f0;
                color: #28524a;
                padding: .42rem .68rem;
                font-size: .78rem;
                font-weight: 900;
            }

            .spn-pill.good {
                background: #e0f4ec;
                color: #075f52;
            }

            .spn-pill.warn {
                background: #f4ead0;
                color: #76510d;
            }

            .spn-pill.danger {
                background: #f7e4dd;
                color: #8d2d17;
            }

            .spn-variants,
            .spn-sources {
                border-top: 1px solid rgba(20, 35, 31, .1);
                padding: 1rem;
            }

            .spn-axis {
                margin-bottom: .75rem;
            }

            .spn-axis strong {
                display: block;
                margin-bottom: .35rem;
                font-size: .78rem;
                letter-spacing: .08em;
                text-transform: uppercase;
            }

            .spn-values {
                display: flex;
                flex-wrap: wrap;
                gap: .35rem;
            }

            .spn-value {
                border-radius: 10px;
                background: rgba(20, 35, 31, .06);
                padding: .32rem .5rem;
                font-size: .82rem;
                font-weight: 800;
            }

            .spn-source-list {
                display: grid;
                gap: .55rem;
                margin: 0;
                padding: 0;
                list-style: none;
            }

            .spn-source-list li {
                color: rgba(20, 35, 31, .68);
                font-size: .86rem;
                line-height: 1.35;
            }

            .spn-card-actions {
                justify-content: space-between;
                border-top: 1px solid rgba(20, 35, 31, .1);
                padding: 1rem;
                background: rgba(241, 240, 234, .48);
            }

            .spn-empty {
                margin-top: 1rem;
                padding: 3rem 1rem;
                text-align: center;
            }

            .spn-pagination {
                justify-content: space-between;
                margin-top: 1rem;
                padding: .85rem;
            }

            .spn-page-links {
                display: flex;
                flex-wrap: wrap;
                gap: .5rem;
                align-items: center;
            }

            .spn-page-link {
                display: inline-flex;
                min-height: 42px;
                min-width: 42px;
                align-items: center;
                justify-content: center;
                border: 1px solid rgba(20, 35, 31, .14);
                border-radius: 14px;
                background: #fff;
                color: #14231f;
                font-weight: 900;
                text-decoration: none;
            }

            .spn-page-link.is-active {
                background: #087464;
                border-color: #087464;
                color: #fff;
            }

            .spn-scratch {
                border-color: rgba(8, 116, 100, .18);
                background:
                    radial-gradient(circle at 100% 0%, rgba(8, 116, 100, .13), transparent 28%),
                    #fffdf8;
                overflow: hidden;
            }

            .spn-scratch summary {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
                min-height: 64px;
                cursor: pointer;
                list-style: none;
            }

            .spn-scratch summary::-webkit-details-marker {
                display: none;
            }

            .spn-scratch-title {
                display: grid;
                gap: .18rem;
            }

            .spn-scratch-title strong {
                font-size: 1.1rem;
                letter-spacing: -.02em;
            }

            .spn-scratch-title span {
                color: rgba(20, 35, 31, .62);
                font-size: .9rem;
                line-height: 1.35;
            }

            .spn-scratch-caret {
                display: inline-grid;
                width: 42px;
                height: 42px;
                flex: 0 0 auto;
                place-items: center;
                border-radius: 999px;
                background: #087464;
                color: #fff;
                font-weight: 900;
                transition: transform .18s ease;
            }

            .spn-scratch[open] .spn-scratch-caret {
                transform: rotate(45deg);
            }

            .spn-scratch-form {
                display: grid;
                gap: 1rem;
                padding-top: 1rem;
                border-top: 1px solid rgba(20, 35, 31, .1);
            }

            .spn-scratch-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: .85rem;
            }

            .spn-scratch-field {
                display: grid;
                gap: .4rem;
                min-width: 0;
            }

            .spn-scratch-field.full {
                grid-column: 1 / -1;
            }

            .spn-scratch-field span {
                color: rgba(20, 35, 31, .72);
                font-size: .78rem;
                font-weight: 900;
                letter-spacing: .08em;
                text-transform: uppercase;
            }

            .spn-scratch-field input,
            .spn-scratch-field textarea {
                width: 100%;
                border: 1px solid rgba(20, 35, 31, .17);
                border-radius: 18px;
                background: #fff;
                color: #14231f;
                padding: .95rem 1rem;
                font-size: 1rem;
                line-height: 1.35;
            }

            .spn-scratch-field textarea {
                min-height: 104px;
                resize: vertical;
            }

            .spn-scratch-variants {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: .85rem;
            }

            .spn-scratch-axis {
                display: grid;
                gap: .65rem;
                border: 1px solid rgba(20, 35, 31, .1);
                border-radius: 22px;
                padding: .85rem;
                background: rgba(255, 255, 255, .7);
            }

            .spn-scratch-actions {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: .75rem;
                flex-wrap: wrap;
                border-radius: 22px;
                background: rgba(8, 116, 100, .08);
                padding: .85rem;
            }

            @media (max-width: 640px) {
                .spn-page {
                    margin-inline: -.5rem;
                }

                .spn-hero,
                .spn-panel,
                .spn-card {
                    border-radius: 22px;
                }

                .spn-card-main {
                    grid-template-columns: 72px minmax(0, 1fr);
                }

                .spn-image {
                    width: 72px;
                    height: 72px;
                    border-radius: 18px;
                }

                .spn-actions .button,
                .spn-card-actions .button,
                .spn-filters .button {
                    width: 100%;
                    justify-content: center;
                }

                .spn-pagination,
                .spn-page-links {
                    justify-content: center;
                }

                .spn-scratch {
                    padding: .9rem;
                }

                .spn-scratch summary {
                    align-items: flex-start;
                }

                .spn-scratch-grid,
                .spn-scratch-variants {
                    grid-template-columns: 1fr;
                }

                .spn-scratch-actions .button {
                    width: 100%;
                    justify-content: center;
                    min-height: 54px;
                }
            }
        </style>

        <article class="spn-hero">
            <p class="spn-eyebrow">Source normalization</p>
            <h2>Build reviewable product families from raw sources.</h2>
            <p class="spn-note">
                This page groups Shaba, Deliveroo, Mamado, Janson, PDFs and shop-picture rows into draft family candidates.
                Creating a family is safe: it stays draft and does not activate POS or ecommerce.
            </p>
            <div class="spn-actions">
                <a href="{{ route('shop-product-intake.index') }}" class="button">Back to intake</a>
                <a href="{{ route('source-products.index') }}" class="button">View raw sources</a>
            </div>
        </article>

        <section class="spn-stats" style="margin-top:1rem;">
            <div class="spn-stat">
                <strong>{{ number_format($candidate_count) }}</strong>
                <span>Family candidates</span>
            </div>
            <div class="spn-stat">
                <strong>{{ number_format($row_count) }}</strong>
                <span>Source rows used</span>
            </div>
            <div class="spn-stat">
                <strong>{{ number_format($confidence_counts['A'] ?? 0) }}</strong>
                <span>A confidence</span>
            </div>
            <div class="spn-stat">
                <strong>{{ number_format($confidence_counts['B'] ?? 0) }}</strong>
                <span>B confidence</span>
            </div>
            <div class="spn-stat">
                <strong>{{ number_format($confidence_counts['C'] ?? 0) }}</strong>
                <span>C review</span>
            </div>
        </section>

        <section class="spn-panel">
            <form method="GET" action="{{ route('shop-product-intake.normalization.index') }}" class="spn-filters">
                <label>
                    Search
                    <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Brand, product, code, variant...">
                </label>
                <label>
                    Brand
                    <select name="brand">
                        <option value="">All brands</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand }}" @selected($filters['brand'] === $brand)>{{ $brand }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Source
                    <select name="source">
                        <option value="">All sources</option>
                        @foreach ($source_labels as $sourceKey => $sourceLabel)
                            <option value="{{ $sourceKey }}" @selected($filters['source'] === $sourceKey)>
                                {{ $sourceLabel }} ({{ number_format($source_counts[$sourceKey] ?? 0) }})
                            </option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Department
                    <select name="department">
                        <option value="">All departments</option>
                        @foreach (['Body Care', 'Hair Care', 'Skin Care', 'Cosmetics', 'Oral Care', 'Shop Products'] as $department)
                            <option value="{{ $department }}" @selected($filters['department'] === $department)>{{ $department }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Confidence
                    <select name="confidence">
                        <option value="">All</option>
                        @foreach (['A', 'B', 'C'] as $grade)
                            <option value="{{ $grade }}" @selected($filters['confidence'] === $grade)>{{ $grade }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    Variants
                    <select name="variant_state">
                        <option value="">All</option>
                        <option value="with" @selected($filters['variant_state'] === 'with')>Has variants</option>
                        <option value="without" @selected($filters['variant_state'] === 'without')>No variants</option>
                    </select>
                </label>
                <label>
                    Review filter
                    <select name="issue">
                        <option value="">All</option>
                        <option value="duplicate_family_name" @selected($filters['issue'] === 'duplicate_family_name')>Duplicate-looking family names</option>
                        <option value="general_type" @selected($filters['issue'] === 'general_type')>General product type</option>
                        <option value="no_image" @selected($filters['issue'] === 'no_image')>No image</option>
                        <option value="single_source" @selected($filters['issue'] === 'single_source')>Single source only</option>
                        <option value="existing_family" @selected($filters['issue'] === 'existing_family')>Already created</option>
                        <option value="not_created" @selected($filters['issue'] === 'not_created')>Not created yet</option>
                    </select>
                </label>
                <label>
                    Per page
                    <select name="per_page">
                        @foreach ([50, 100, 250, 500] as $perPageOption)
                            <option value="{{ $perPageOption }}" @selected((int) $filters['per_page'] === $perPageOption)>{{ $perPageOption }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="button button-primary" type="submit">Apply</button>
                @if (collect($filters)->except(['page', 'per_page'])->filter()->isNotEmpty())
                    <a class="button" href="{{ route('shop-product-intake.normalization.index') }}">Clear</a>
                @endif
            </form>
        </section>

        @if ($brand_fallback_active)
            <article class="spn-panel" style="border-color:rgba(118,81,13,.24);background:#fff8e6;">
                <p class="spn-note" style="margin:0;color:#76510d;">
                    No matching family was found inside <strong>{{ $requested_brand }}</strong>, so the page is showing matches from all brands.
                    If none of these match the product in your hand, treat it as not found and create/review it manually.
                </p>
            </article>
        @elseif ($filters['brand'] !== '' && $filters['search'] !== '')
            <article class="spn-panel" style="border-color:rgba(8,116,100,.16);background:#eef7f3;">
                <p class="spn-note" style="margin:0;color:#075f52;">
                    Searching inside <strong>{{ $filters['brand'] }}</strong> first.
                </p>
            </article>
        @endif

        @php
            $scratchErrorFields = [
                'brand_name',
                'department_name',
                'product_type_name',
                'family_name',
                'variant_axis_1',
                'variant_values_1',
                'variant_axis_2',
                'variant_values_2',
                'description',
            ];
            $scratchHasErrors = collect($scratchErrorFields)->contains(fn (string $field): bool => $errors->has($field));
            $scratchOpen = $candidates === [] || $scratchHasErrors;
            $scratchFamilyDefault = old('family_name', $filters['search']);
            $scratchBrandDefault = old('brand_name', $filters['brand']);
        @endphp

        <details class="spn-panel spn-scratch" @if($scratchOpen) open @endif>
            <summary>
                <span class="spn-scratch-title">
                    <strong>Build from scratch</strong>
                    <span>No match? Create a safe draft family now, then add SKU, barcode, photo and price on the family page.</span>
                </span>
                <span class="spn-scratch-caret" aria-hidden="true">+</span>
            </summary>

            <form method="POST" action="{{ route('shop-product-intake.normalization.families.scratch') }}" class="spn-scratch-form">
                @csrf

                @if ($scratchHasErrors)
                    <div class="spn-pill danger">Fix: {{ $errors->first() }}</div>
                @endif

                <div class="spn-scratch-grid">
                    <label class="spn-scratch-field">
                        <span>Brand</span>
                        <input name="brand_name" value="{{ $scratchBrandDefault }}" list="spn-scratch-brands" placeholder="Example: Cantu" required autocomplete="off">
                    </label>

                    <label class="spn-scratch-field">
                        <span>Department</span>
                        <input name="department_name" value="{{ old('department_name', $filters['department'] ?: 'Body Care') }}" list="spn-scratch-departments" placeholder="Example: Body Care">
                    </label>

                    <label class="spn-scratch-field">
                        <span>Product type</span>
                        <input name="product_type_name" value="{{ old('product_type_name') }}" list="spn-scratch-types" placeholder="Example: Body Lotion">
                    </label>

                    <label class="spn-scratch-field">
                        <span>Family name</span>
                        <input name="family_name" value="{{ $scratchFamilyDefault }}" placeholder="Name visible on pack" required autocomplete="off">
                    </label>
                </div>

                <datalist id="spn-scratch-brands">
                    @foreach ($brands as $brand)
                        <option value="{{ $brand }}"></option>
                    @endforeach
                </datalist>
                <datalist id="spn-scratch-departments">
                    @foreach (['Body Care', 'Skin Care', 'Hair Care', 'Hair Products', 'Cosmetics', 'Oral Care', 'Accessories', 'General Products'] as $department)
                        <option value="{{ $department }}"></option>
                    @endforeach
                </datalist>
                <datalist id="spn-scratch-types">
                    @foreach (['Body Lotion', 'Body Cream', 'Soap', 'Shampoo', 'Conditioner', 'Hair Treatment', 'Styling Gel', 'Skin Treatment', 'Hair Colour', 'Toothpaste'] as $type)
                        <option value="{{ $type }}"></option>
                    @endforeach
                </datalist>

                <div class="spn-scratch-variants">
                    <div class="spn-scratch-axis">
                        <label class="spn-scratch-field">
                            <span>Variant axis 1</span>
                            <input name="variant_axis_1" value="{{ old('variant_axis_1', 'Size') }}" placeholder="Size, Scent, Shade...">
                        </label>
                        <label class="spn-scratch-field">
                            <span>Values</span>
                            <textarea name="variant_values_1" placeholder="Optional. One per line or comma separated. Example: 250ml, 500ml">{{ old('variant_values_1') }}</textarea>
                        </label>
                    </div>

                    <div class="spn-scratch-axis">
                        <label class="spn-scratch-field">
                            <span>Variant axis 2</span>
                            <input name="variant_axis_2" value="{{ old('variant_axis_2') }}" placeholder="Scent, Colour, Strength...">
                        </label>
                        <label class="spn-scratch-field">
                            <span>Values</span>
                            <textarea name="variant_values_2" placeholder="Optional. Example: Coconut, Shea Butter">{{ old('variant_values_2') }}</textarea>
                        </label>
                    </div>
                </div>

                <label class="spn-scratch-field full">
                    <span>Customer description</span>
                    <textarea name="description" placeholder="Optional. Keep it factual and customer-facing.">{{ old('description') }}</textarea>
                </label>

                <div class="spn-scratch-actions">
                    <p class="spn-note" style="margin:0;">Creates a draft only. POS and ecommerce stay off until you finish the real SKU data.</p>
                    <button class="button button-primary" type="submit">Create draft family</button>
                </div>
            </form>
        </details>

        <nav class="spn-panel spn-pagination" aria-label="Family candidate pages">
            <p class="spn-note" style="margin:0;">
                Showing {{ number_format($page_from) }}-{{ number_format($page_to) }} of {{ number_format($candidate_count) }} family candidates.
            </p>
            <div class="spn-page-links">
                @if ($current_page > 1)
                    <a class="spn-page-link" href="{{ request()->fullUrlWithQuery(['page' => 1]) }}">First</a>
                    <a class="spn-page-link" href="{{ request()->fullUrlWithQuery(['page' => $current_page - 1]) }}">Prev</a>
                @endif

                @php
                    $pageStart = max(1, $current_page - 2);
                    $pageEnd = min($total_pages, $current_page + 2);
                @endphp

                @for ($page = $pageStart; $page <= $pageEnd; $page++)
                    <a class="spn-page-link {{ $page === $current_page ? 'is-active' : '' }}" href="{{ request()->fullUrlWithQuery(['page' => $page]) }}">{{ $page }}</a>
                @endfor

                @if ($current_page < $total_pages)
                    <a class="spn-page-link" href="{{ request()->fullUrlWithQuery(['page' => $current_page + 1]) }}">Next</a>
                    <a class="spn-page-link" href="{{ request()->fullUrlWithQuery(['page' => $total_pages]) }}">Last</a>
                @endif
            </div>
        </nav>

        @if ($candidates === [])
            <article class="spn-card spn-empty">
                <h3>No candidates found</h3>
                <p class="spn-note">Try a wider search, clear the filters, or use the Build from scratch panel above.</p>
            </article>
        @else
            <section class="spn-grid">
                @foreach ($candidates as $candidate)
                    <article class="spn-card">
                        <div class="spn-card-main">
                            @if ($candidate['image_url'])
                                <img class="spn-image" src="{{ $candidate['image_url'] }}" alt="">
                            @else
                                <div class="spn-image spn-image-empty">No image</div>
                            @endif
                            <div>
                                <div class="spn-badges" style="margin-bottom:.55rem;">
                                    <span class="spn-pill {{ $candidate['confidence'] === 'A' ? 'good' : ($candidate['confidence'] === 'B' ? 'warn' : 'danger') }}">Confidence {{ $candidate['confidence'] }}</span>
                                    @if ($candidate['existing_family_id'])
                                        <span class="spn-pill good">Already has family</span>
                                    @endif
                                    @if (($candidate['duplicate_family_count'] ?? 1) > 1)
                                        <span class="spn-pill warn">Duplicate-looking x{{ $candidate['duplicate_family_count'] }}</span>
                                    @endif
                                </div>
                                <h3>{{ $candidate['family_name'] }}</h3>
                                <p class="spn-meta">
                                    {{ $candidate['brand'] }} · {{ $candidate['department'] }} · {{ $candidate['product_type'] }}
                                    @if ($candidate['price_summary'])
                                        · {{ $candidate['price_summary'] }}
                                    @endif
                                </p>
                                <div class="spn-badges" style="margin-top:.65rem;">
                                    @foreach ($candidate['source_types'] as $sourceType)
                                        <span class="spn-pill">{{ $source_labels[$sourceType] ?? $sourceType }}</span>
                                    @endforeach
                                    <span class="spn-pill">{{ number_format($candidate['source_count']) }} evidence row{{ $candidate['source_count'] === 1 ? '' : 's' }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="spn-variants">
                            @if (! empty($candidate['quality_notes']))
                                <div class="spn-axis">
                                    <strong>Review notes</strong>
                                    <div class="spn-values">
                                        @foreach ($candidate['quality_notes'] as $note)
                                            <span class="spn-value">{{ $note }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if ($candidate['variant_axes'] === [])
                                <p class="spn-meta" style="margin:0;">No safe variant axis extracted. The family can still have SKUs added manually later.</p>
                            @else
                                @foreach ($candidate['variant_axes'] as $axis)
                                    <div class="spn-axis">
                                        <strong>{{ $axis['name'] }}</strong>
                                        <div class="spn-values">
                                            @foreach (array_slice($axis['values'], 0, 18) as $value)
                                                <span class="spn-value">{{ $value }}</span>
                                            @endforeach
                                            @if (count($axis['values']) > 18)
                                                <span class="spn-value">+{{ count($axis['values']) - 18 }} more</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                        </div>

                        <details class="spn-sources">
                            <summary><strong>Evidence</strong></summary>
                            <ul class="spn-source-list" style="margin-top:.75rem;">
                                @foreach (array_slice($candidate['sources'], 0, 8) as $source)
                                    <li>
                                        <strong>{{ $source['source_label'] }}:</strong>
                                        {{ $source['title'] }}
                                        @if ($source['variant_name'])
                                            / {{ $source['variant_name'] }}
                                        @endif
                                        @if ($source['source_url'])
                                            · <a href="{{ $source['source_url'] }}" target="_blank" rel="noopener">source</a>
                                        @endif
                                    </li>
                                @endforeach
                                @if (count($candidate['sources']) > 8)
                                    <li>+{{ count($candidate['sources']) - 8 }} more evidence rows</li>
                                @endif
                            </ul>
                        </details>

                        <div class="spn-card-actions">
                            @if ($candidate['existing_family_id'])
                                <a class="button button-primary" href="{{ $candidate['existing_family_url'] }}">Open existing family</a>
                            @else
                                <form method="POST" action="{{ route('shop-product-intake.normalization.families.store') }}">
                                    @csrf
                                    <input type="hidden" name="candidate_key" value="{{ $candidate['key'] }}">
                                    <button class="button button-primary" type="submit">Create draft family</button>
                                </form>
                            @endif
                            <span class="spn-meta">Score {{ number_format($candidate['score'] * 100) }}%</span>
                        </div>
                    </article>
                @endforeach
            </section>

            <nav class="spn-panel spn-pagination" aria-label="Family candidate pages bottom">
                <p class="spn-note" style="margin:0;">
                    Page {{ number_format($current_page) }} of {{ number_format($total_pages) }}.
                </p>
                <div class="spn-page-links">
                    @if ($current_page > 1)
                        <a class="spn-page-link" href="{{ request()->fullUrlWithQuery(['page' => $current_page - 1]) }}">Prev</a>
                    @endif
                    @if ($current_page < $total_pages)
                        <a class="spn-page-link" href="{{ request()->fullUrlWithQuery(['page' => $current_page + 1]) }}">Next</a>
                    @endif
                </div>
            </nav>
        @endif
    </section>
@endsection
