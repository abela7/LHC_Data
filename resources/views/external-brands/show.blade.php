@extends('layouts.app')

@section('title', 'External Brands')

@section('content')
    <section class="page-head">
        <div>
            <p class="eyebrow">External source</p>
            <h2>External Brands</h2>
            <p class="page-note">
                Showing saved brands from {{ $summary['site_url'] ?? '' }}.
            </p>
        </div>
        <div class="header-actions">
            <span class="pill">{{ number_format($brands->count()) }} shown</span>
        </div>
    </section>

    <article class="card brand-toolbar-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Reports</p>
                <h3>{{ strtoupper($activeLabel) }}</h3>
            </div>
            <p class="page-note">Saved external brand comparisons available in the workspace.</p>
        </div>

        <div class="brand-hero-tags">
            @foreach($reports as $reportLabel => $report)
                <a
                    href="{{ route('external-brands.show', ['label' => $reportLabel]) }}"
                    class="pill{{ $reportLabel === $activeLabel ? ' pill-strong' : '' }}"
                >
                    {{ strtoupper($reportLabel) }}
                </a>
            @endforeach
        </div>
    </article>

    <section class="stats-grid">
        <article class="card stat-card">
            <p class="stat-label">Brand Candidates</p>
            <p class="stat-value">{{ number_format((int) ($summary['brand_candidates'] ?? 0)) }}</p>
        </article>
        <article class="card stat-card">
            <p class="stat-label">Matched To Our Brands</p>
            <p class="stat-value">{{ number_format((int) ($summary['matched_external_brand_count'] ?? 0)) }}</p>
        </article>
        <article class="card stat-card">
            <p class="stat-label">Not In Our Brands</p>
            <p class="stat-value">{{ number_format((int) ($summary['unmatched_external_brand_count'] ?? 0)) }}</p>
        </article>
        <article class="card stat-card">
            <p class="stat-label">Our Brands On Site</p>
            <p class="stat-value">{{ number_format((int) ($summary['matched_internal_brand_count'] ?? 0)) }}</p>
        </article>
        <article class="card stat-card">
            <p class="stat-label">Saved To Brand List</p>
            <p class="stat-value">{{ number_format($savedCount) }}</p>
        </article>
    </section>

    <article class="card">
        <form method="GET" action="{{ route('external-brands.show', ['label' => $activeLabel]) }}" class="brand-toolbar-grid">
            <div class="brand-search-field">
                <label>
                    <span class="sr-only">Search external brands</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search site brand or matched internal brand..."
                        autocomplete="off"
                    >
                </label>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="button button-primary">Search</button>
                @if($search !== '')
                    <a href="{{ route('external-brands.show', ['label' => $activeLabel]) }}" class="button">Clear</a>
                @endif
            </div>
        </form>
    </article>

    <article class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:3rem">#</th>
                        <th>Site Brand</th>
                        <th>Status</th>
                        <th>Saved</th>
                        <th>Matched Internal Brand</th>
                        <th>Source Link</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($brands as $index => $brand)
                        <tr id="brand-{{ \Illuminate\Support\Str::slug($brand['brand_name']) }}">
                            <td style="color:#9a9590;font-variant-numeric:tabular-nums;">{{ $index + 1 }}</td>
                            <td>
                                <strong>{{ $brand['brand_name'] }}</strong>
                                @if($brand['link_kind'])
                                    <br><span class="page-note">{{ str_replace('_', ' ', $brand['link_kind']) }}</span>
                                @endif
                            </td>
                            <td>
                                @if($brand['match_status'] === 'Yes')
                                    <span class="pill pill-strong">Matched</span>
                                @else
                                    <span class="pill pill-warn">New / Unmatched</span>
                                @endif
                            </td>
                            <td>
                                @if($brand['saved_in_db'])
                                    <span class="pill pill-strong">Saved</span>
                                @else
                                    <span class="page-note">Not saved</span>
                                @endif
                            </td>
                            <td>
                                @if($brand['matched_internal_brands'] !== '')
                                    {{ $brand['matched_internal_brands'] }}
                                    @if($brand['match_method'] !== 'exact')
                                        <br><span class="page-note">Matched by {{ $brand['match_method'] }}</span>
                                    @endif
                                @else
                                    <span style="color:#9a9590">-</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ $brand['brand_url'] }}" target="_blank" rel="noreferrer">Open brand page</a>
                            </td>
                            <td>
                                @if($brand['saved_in_db'] && $brand['has_real_brand_entry'])
                                    <a href="{{ route('real-brands.show', ['brand' => $brand['brand_name']]) }}" class="button">Open saved brand</a>
                                @elseif($brand['saved_in_db'])
                                    <span class="pill pill-strong">Saved</span>
                                @else
                                    <form method="POST" action="{{ route('external-brands.store-brand', ['label' => $activeLabel]) }}">
                                        @csrf
                                        <input type="hidden" name="brand_name" value="{{ $brand['brand_name'] }}">
                                        <input type="hidden" name="brand_url" value="{{ $brand['brand_url'] }}">
                                        <input type="hidden" name="search" value="{{ $search }}">
                                        <button type="submit" class="button button-primary">Save brand</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="padding:2rem; text-align:center; color:#6f6d67;">
                                No brands matched this search.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
@endsection
