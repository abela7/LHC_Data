@extends('layouts.app')

@section('title', 'PDF Brand Review')
@section('section', 'PDF Brand Review')
@section('heading', 'Brands')

@section('content')
    <section class="page-head">
        <div>
            <p class="eyebrow">Brand shortlist</p>
            <h2>PDF Brand Review</h2>
            <p class="page-note">Select brands, review the selected list, then remove or restore them in one action.</p>
        </div>
        <div class="air-stats-row">
            <span class="air-stat-chip">
                <span class="air-stat-number">{{ number_format($stats['total']) }}</span>
                <span class="air-stat-label">Total brands</span>
            </span>
            <span class="air-stat-chip">
                <span class="air-stat-number">{{ number_format($stats['active']) }}</span>
                <span class="air-stat-label">Keep</span>
            </span>
            <span class="air-stat-chip air-stat-chip--warn">
                <span class="air-stat-number">{{ number_format($stats['excluded']) }}</span>
                <span class="air-stat-label">Excluded</span>
            </span>
        </div>
    </section>

    <article class="card">
        <div class="card-head">
            <div>
                <h3>Source</h3>
                <p>{{ $source }}</p>
            </div>
            <div class="button-row">
                <a href="{{ route('pdf-products.index', ['source' => $source]) }}" class="button">Open PDF rows</a>
            </div>
        </div>
    </article>

    <article class="card">
        <form method="GET" action="{{ route('pdf-brand-decisions.index') }}" class="brand-toolbar-grid">
            <input type="hidden" name="source" value="{{ $source }}">
            <div class="brand-search-field">
                <label>
                    <span class="sr-only">Search brands</span>
                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search brand..."
                        autocomplete="off"
                    >
                </label>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="button button-primary">Search</button>
                @if ($search !== '')
                    <a href="{{ route('pdf-brand-decisions.index', ['source' => $source]) }}" class="button">Clear</a>
                @endif
            </div>
        </form>
    </article>

    <article class="card">
        <div class="card-head">
            <div>
                <h3>Add missing brand</h3>
                <p>If the seed list missed one, add it here.</p>
            </div>
        </div>
        <form method="POST" action="{{ route('pdf-brand-decisions.store') }}" class="brand-toolbar-grid">
            @csrf
            <input type="hidden" name="source" value="{{ $source }}">
            <label class="brand-search-field">
                <span class="sr-only">Brand name</span>
                <input type="text" name="brand_name" placeholder="Add a brand name">
            </label>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="button button-primary">Add brand</button>
            </div>
        </form>
    </article>

    <form method="POST" action="{{ route('pdf-brand-decisions.update') }}" data-brand-selection-form>
        @csrf
        @method('PATCH')
        <input type="hidden" name="source" value="{{ $source }}">
        <input type="hidden" name="action" value="remove">

        <article class="card">
            <div class="card-head">
                <div>
                    <h3>Active Brands</h3>
                    <p>Select the brands the shop does not do, then remove them from the main list.</p>
                </div>
                <div class="pdf-brand-actionbar">
                    <span class="pill"><span data-selected-count>0</span> selected</span>
                    <button type="submit" class="button button-primary" data-submit-selected disabled>Remove Selected</button>
                </div>
            </div>

            @if ($activeBrands->isEmpty())
                <div class="brand-empty-state py-12">
                    <h3>No active brands</h3>
                    <p class="page-note mt-2">All detected brands are currently marked unimportant.</p>
                </div>
            @else
                <div class="pdf-brand-selection-shell">
                    <div class="pdf-brand-selection-toolbar">
                        <strong>Selected brands</strong>
                        <div class="pdf-brand-selection-list" data-selected-list>
                            <span class="page-note">Nothing selected yet.</span>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:4rem;">Select</th>
                                    <th>Brand</th>
                                    <th style="width:8rem;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($activeBrands as $brand)
                                    @php $inputId = 'active-brand-'.$brand->id; @endphp
                                    <tr class="pdf-brand-row" data-row-toggle>
                                        <td>
                                            <input
                                                id="{{ $inputId }}"
                                                class="pdf-brand-select-input"
                                                type="checkbox"
                                                name="selected_ids[]"
                                                value="{{ $brand->id }}"
                                                data-brand-name="{{ $brand->brand_name }}"
                                            >
                                        </td>
                                        <td>
                                            <label for="{{ $inputId }}" class="pdf-brand-label">
                                                {{ $brand->brand_name }}
                                            </label>
                                        </td>
                                        <td><span class="pill">Active</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </article>
    </form>

    <form method="POST" action="{{ route('pdf-brand-decisions.update') }}" data-brand-selection-form>
        @csrf
        @method('PATCH')
        <input type="hidden" name="source" value="{{ $source }}">
        <input type="hidden" name="action" value="restore">

        <article class="card">
            <div class="card-head">
                <div>
                    <h3>Unimportant Brands</h3>
                    <p>Select brands here if you want to bring them back into the working list.</p>
                </div>
                <div class="pdf-brand-actionbar">
                    <span class="pill pill-warn"><span data-selected-count>0</span> selected</span>
                    <button type="submit" class="button" data-submit-selected disabled>Restore Selected</button>
                </div>
            </div>

            @if ($unimportantBrands->isEmpty())
                <div class="brand-empty-state py-12">
                    <h3>No unimportant brands</h3>
                    <p class="page-note mt-2">Removed brands will appear here.</p>
                </div>
            @else
                <div class="pdf-brand-selection-shell">
                    <div class="pdf-brand-selection-toolbar">
                        <strong>Selected brands</strong>
                        <div class="pdf-brand-selection-list" data-selected-list>
                            <span class="page-note">Nothing selected yet.</span>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:4rem;">Select</th>
                                    <th>Brand</th>
                                    <th style="width:8rem;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($unimportantBrands as $brand)
                                    @php $inputId = 'inactive-brand-'.$brand->id; @endphp
                                    <tr class="pdf-brand-row pdf-brand-row--inactive" data-row-toggle>
                                        <td>
                                            <input
                                                id="{{ $inputId }}"
                                                class="pdf-brand-select-input"
                                                type="checkbox"
                                                name="selected_ids[]"
                                                value="{{ $brand->id }}"
                                                data-brand-name="{{ $brand->brand_name }}"
                                            >
                                        </td>
                                        <td>
                                            <label for="{{ $inputId }}" class="pdf-brand-label">
                                                {{ $brand->brand_name }}
                                            </label>
                                        </td>
                                        <td><span class="pill pill-warn">Unimportant</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </article>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-brand-selection-form]').forEach(function (form) {
                var checkboxes = Array.from(form.querySelectorAll('.pdf-brand-select-input'));
                var submitButton = form.querySelector('[data-submit-selected]');
                var counters = Array.from(form.querySelectorAll('[data-selected-count]'));
                var selectedList = form.querySelector('[data-selected-list]');

                function syncSelectionState() {
                    var selected = checkboxes.filter(function (checkbox) {
                        return checkbox.checked;
                    });
                    var count = selected.length;

                    counters.forEach(function (counter) {
                        counter.textContent = count;
                    });

                    if (submitButton) {
                        submitButton.disabled = count === 0;
                    }

                    checkboxes.forEach(function (checkbox) {
                        var row = checkbox.closest('.pdf-brand-row');
                        if (!row) return;
                        row.classList.toggle('is-selected', checkbox.checked);
                    });

                    if (selectedList) {
                        if (count === 0) {
                            selectedList.innerHTML = '<span class="page-note">Nothing selected yet.</span>';
                        } else {
                            selectedList.innerHTML = selected.map(function (checkbox) {
                                return '<span class="pdf-brand-selection-chip">' + checkbox.dataset.brandName + '</span>';
                            }).join('');
                        }
                    }
                }

                checkboxes.forEach(function (checkbox) {
                    checkbox.addEventListener('change', syncSelectionState);
                });

                form.querySelectorAll('[data-row-toggle]').forEach(function (row) {
                    row.addEventListener('click', function (event) {
                        if (event.target.tagName === 'INPUT' || event.target.tagName === 'LABEL') {
                            return;
                        }
                        var checkbox = row.querySelector('.pdf-brand-select-input');
                        if (!checkbox) return;
                        checkbox.checked = !checkbox.checked;
                        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });

                syncSelectionState();
            });
        });
    </script>
@endsection
