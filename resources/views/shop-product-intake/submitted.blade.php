@extends('layouts.app')

@section('title', 'Submitted Shop Product Intakes')
@section('section', 'Shop Intake')
@section('heading', 'Submitted')

@section('content')
    <section class="deliveroo-overview">
        <div class="deliveroo-hero-shell">
            <article class="deliveroo-hero">
                <div class="deliveroo-page-head">
                    <div class="deliveroo-page-head-copy">
                        <p class="eyebrow">Shop Product Intake</p>
                        <h2>Submitted products</h2>
                        <p class="page-note">Shelf-observed products and sellable barcode rows captured inside the shop.</p>
                    </div>
                    <div class="button-row">
                        <a href="{{ $intakeUrl }}" class="button button-primary">New Intake</a>
                    </div>
                </div>
            </article>
        </div>

        <section class="deliveroo-search-panel">
            <form method="GET" action="{{ route('shop-product-intake.submitted') }}" class="deliveroo-search-form">
                <label class="deliveroo-search-field">
                    <span class="sr-only">Search intakes</span>
                    <input type="search" name="search" value="{{ $search }}" placeholder="Search submitted intakes..." autocomplete="off">
                </label>
                <div class="deliveroo-all-products-filters">
                    <label class="deliveroo-all-products-select-label">
                        <span>Brand</span>
                        <select name="brand" class="deliveroo-all-products-select">
                            <option value="">All brands</option>
                            @foreach ($brands as $brandRow)
                                <option value="{{ $brandRow->brand_name }}" @selected($brand === $brandRow->brand_name)>
                                    {{ $brandRow->brand_name }} ({{ number_format($brandRow->intake_count) }})
                                </option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="button-row">
                    <button class="button button-primary" type="submit">Apply</button>
                    @if ($search !== '' || $brand !== '')
                        <a class="button" href="{{ route('shop-product-intake.submitted') }}">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="deliveroo-section">
            <div class="deliveroo-section-head">
                <div class="deliveroo-section-head-copy">
                    <p class="eyebrow">Submitted</p>
                    <h3>{{ number_format($intakes->total()) }} intake{{ $intakes->total() === 1 ? '' : 's' }}</h3>
                </div>
            </div>

            @if ($intakes->isEmpty())
                <article class="card">
                    <div class="brand-empty-state py-12">
                        <h3>No submitted intakes yet</h3>
                        <p class="page-note mt-2">Use the intake page to capture real shop products.</p>
                    </div>
                </article>
            @else
                <article class="card" style="padding:0;overflow:hidden;">
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Brand</th>
                                    <th>Product</th>
                                    <th>Department</th>
                                    <th>Type</th>
                                    <th>SKUs</th>
                                    <th>Barcodes</th>
                                    <th>Source</th>
                                    <th>Submitted</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($intakes as $intake)
                                    @php
                                        $skuRows = collect($intake->sku_rows ?? []);
                                        $barcodeCount = $skuRows->filter(fn ($row) => filled($row['barcode'] ?? null))->count();
                                        $missingBarcodes = $skuRows->count() - $barcodeCount;
                                    @endphp
                                    <tr>
                                        <td><strong>{{ $intake->brand_name }}</strong></td>
                                        <td>
                                            <strong>{{ $intake->family_name }}</strong>
                                            @if ($intake->shelf_ticket_price)
                                                <div class="page-note">Ticket: £{{ number_format((float) $intake->shelf_ticket_price, 2) }}</div>
                                            @endif
                                        </td>
                                        <td>{{ $intake->department_name ?: '-' }}</td>
                                        <td>{{ $intake->product_type_name ?: '-' }}</td>
                                        <td>{{ number_format($skuRows->count()) }}</td>
                                        <td>
                                            {{ number_format($barcodeCount) }}
                                            @if ($missingBarcodes > 0)
                                                <div class="page-note">{{ $missingBarcodes }} missing</div>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($intake->sourceFamily)
                                                <a href="{{ route('retail-products.families.show', $intake->sourceFamily) }}" class="button">Open</a>
                                            @else
                                                <span class="pill">New / unmatched</span>
                                            @endif
                                        </td>
                                        <td>{{ $intake->submitted_at?->format('d M Y H:i') ?: '-' }}</td>
                                        <td>
                                            <div class="button-row" style="justify-content:flex-end;gap:.4rem;">
                                                <a href="{{ route('shop-product-intake.edit', $intake) }}" class="button button-primary">Edit</a>
                                                <form method="POST"
                                                      action="{{ route('shop-product-intake.destroy', $intake) }}"
                                                      onsubmit="return confirm('Delete this submitted intake? This cannot be undone.');"
                                                      style="display:inline;margin:0;">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="button">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </article>

                <div class="mt-6">
                    {{ $intakes->links() }}
                </div>
            @endif
        </section>
    </section>
@endsection
