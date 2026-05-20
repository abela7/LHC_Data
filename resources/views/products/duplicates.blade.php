@extends('layouts.app')

@section('title', 'Identical Products')

@section('content')
    <section class="page-head">
        <div>
            <p class="eyebrow">Products</p>
            <h2>Identical Products</h2>
            <p class="page-note">
                Products with the exact same name, brand, and picture.
            </p>
        </div>
        <div class="header-actions">
            <a href="{{ route('products.index') }}" class="button">Back to Products</a>
        </div>
    </section>

    @if($totalGroups > 0)
        <article class="card">
            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem;">
                <div style="display:flex;flex-wrap:wrap;gap:1rem;">
                    <div>
                        <p class="stat-label">Duplicate Groups</p>
                        <p class="stat-value">{{ number_format($totalGroups) }}</p>
                    </div>
                    <div>
                        <p class="stat-label">Total Affected Rows</p>
                        <p class="stat-value">{{ number_format($totalRows) }}</p>
                    </div>
                    <div>
                        <p class="stat-label">Rows to Delete</p>
                        <p class="stat-value" style="color:var(--color-danger);">{{ number_format($toDelete) }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('products.duplicates.purge') }}"
                      onsubmit="return confirm('Delete {{ number_format($toDelete) }} duplicate row(s)? This keeps the original (lowest ID) in each group and cannot be undone.');">
                    @csrf
                    <button type="submit" class="button button-danger">
                        Delete {{ number_format($toDelete) }} Duplicates - Keep Originals
                    </button>
                </form>
            </div>
        </article>
    @endif

    @if($groups->isEmpty())
        <article class="card">
            <div class="brand-empty-state py-12">
                <h3>No identical products found</h3>
            </div>
        </article>
    @else
        <div class="stack-list">
            @foreach($groups as $i => $group)
                @php
                    $key = $group->picture_id.'|'.$group->brand.'|'.$group->product_name;
                    $rows = $groupedRows->get($key, collect());
                @endphp
                <article class="card" style="padding:0;overflow:hidden;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:0.75rem 1rem;border-bottom:1px solid #ebdfcf;background:rgba(255,255,255,0.6);">
                        <div>
                            <p class="product-card-kicker">{{ $group->canonical_brand ?: $group->brand ?: 'No brand' }} | {{ $group->picture_id }}</p>
                            <p style="font-weight:600;font-size:0.95rem;margin:0;">{{ $group->product_name }}</p>
                        </div>
                        <span class="pill pill-warning" style="white-space:nowrap;">{{ $group->cnt }}x duplicate</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:3rem">#</th>
                                    <th>ID</th>
                                    <th>Brand (raw)</th>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th>Sort</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $j => $row)
                                    <tr @if($j > 0) style="background:rgba(255,243,220,0.45);" @endif>
                                        <td style="color:#9a9590;font-variant-numeric:tabular-nums;">{{ $j + 1 }}</td>
                                        <td style="color:#9a9590;font-variant-numeric:tabular-nums;">{{ $row->id }}</td>
                                        <td>
                                            @if($row->canonical_brand)
                                                <span class="brand-chip">{{ $row->canonical_brand }}</span>
                                            @else
                                                <span style="color:#9a9590">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('products.show', $row) }}" class="brand-product-link">
                                                {{ $row->product_name }}
                                            </a>
                                        </td>
                                        <td>
                                            @if($row->category)
                                                <span class="pill">{{ $row->category->name }}</span>
                                            @else
                                                <span style="color:#9a9590">-</span>
                                            @endif
                                        </td>
                                        <td style="color:#9a9590;">{{ $row->sort_order }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="pagination-wrap">
            {{ $groups->links() }}
        </div>
    @endif
@endsection
