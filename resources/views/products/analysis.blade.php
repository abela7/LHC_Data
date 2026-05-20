@extends('layouts.app')

@section('title', 'Product Family Analysis')

@section('content')
    @php
        $rangeQuery = array_filter([
            'picture_from' => $filters['picture_from'],
            'picture_to' => $filters['picture_to'],
        ]);
    @endphp

    <datalist id="brand-suggestions">
        @foreach($catalogueBrands as $b)
            <option value="{{ $b->name }}">
        @endforeach
        @foreach($observedBrands as $b)
            <option value="{{ $b }}">
        @endforeach
    </datalist>

    <style>
        tr.product-row {
            cursor: pointer;
            transition: background 0.1s, outline 0.1s;
            user-select: none;
        }
        tr.product-row:hover {
            background: rgba(29, 111, 95, 0.06) !important;
        }
        tr.product-row.selected {
            background: rgba(29, 111, 95, 0.14) !important;
            outline: 2px solid rgba(29, 111, 95, 0.35);
            outline-offset: -2px;
        }
        tr.product-row.selected td:first-child::before {
            content: '✓';
            color: #1d6f5f;
            font-weight: 700;
            margin-right: 0.4rem;
        }
    </style>

    <section class="page-head">
        <div>
            <p class="eyebrow">Products</p>
            <h2>Product Family Analysis</h2>
            <p class="page-note">
                Click any product row to select it. Select products across any groups, then <strong>Group as Family</strong>.
            </p>
            @if ($rangeQuery !== [])
                <div class="brand-hero-tags">
                    <span class="pill">Range {{ $filters['picture_from'] !== '' ? $filters['picture_from'] : 'start' }} to {{ $filters['picture_to'] !== '' ? $filters['picture_to'] : 'end' }}</span>
                </div>
            @endif
        </div>
        <div class="header-actions">
            <a href="{{ route('products.index') }}" class="button">← All Products</a>
        </div>
    </section>

    {{-- Filter --}}
    <article class="card">
        <form method="GET" action="{{ route('products.analysis') }}" class="inline-form">
            <label>
                <span class="text-sm font-medium">Similarity Threshold (%)</span>
                <input type="number" name="min_similarity"
                    value="{{ request('min_similarity', 60) }}"
                    min="40" max="95" step="5" style="width:6rem;">
            </label>
            <label>
                <span class="text-sm font-medium">Picture from</span>
                <input type="text" name="picture_from" value="{{ $filters['picture_from'] }}" placeholder="381 or picture381" style="width:9rem;">
            </label>
            <label>
                <span class="text-sm font-medium">Picture to</span>
                <input type="text" name="picture_to" value="{{ $filters['picture_to'] }}" placeholder="459 or picture459" style="width:9rem;">
            </label>
            <button type="submit" class="button button-primary">Analyze</button>
            @if ($rangeQuery !== [])
                <a href="{{ route('products.analysis', ['min_similarity' => request('min_similarity', 60)]) }}" class="button">Clear range</a>
            @endif
        </form>
    </article>

    {{-- Stats --}}
    @if($totalClusters > 0)
        <article class="card">
            <div style="display:flex;flex-wrap:wrap;gap:2rem;">
                <div><p class="stat-label">Potential Families</p><p class="stat-value">{{ number_format($totalClusters) }}</p></div>
                <div><p class="stat-label">Brands Analyzed</p><p class="stat-value">{{ number_format($brandCount) }}</p></div>
                <div><p class="stat-label">Threshold</p><p class="stat-value">{{ $minSimilarity }}%</p></div>
            </div>
        </article>
    @endif

    {{-- Families --}}
    @if($families->isEmpty())
        <article class="card">
            <div class="brand-empty-state py-12">
                <h3>No similar product groups found</h3>
                <p class="page-note mt-2">Try lowering the similarity threshold (currently {{ $minSimilarity }}%).</p>
            </div>
        </article>
    @else
        <div class="stack-list">
            @foreach($families as $fi => $family)
                <article class="card" style="padding:0;overflow:hidden;">
                    {{-- Group header --}}
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:0.75rem 1rem;border-bottom:1px solid #ebdfcf;background:linear-gradient(135deg,rgba(29,111,95,0.08),transparent);">
                        <div>
                            <p class="product-card-kicker">{{ $family['brand'] }}</p>
                            <p style="font-weight:600;font-size:0.95rem;margin:0;">{{ $family['count'] }} products — potential family</p>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.75rem;">
                            <span class="pill">{{ $family['count'] }}× variants</span>
                            <button type="button"
                                class="button button-primary"
                                style="padding:0.4rem 0.85rem;font-size:0.8rem;"
                                onclick="selectGroupAndOpen({{ $fi }}, '{{ addslashes($family['brand']) }}', '{{ addslashes($family['products'][0]['product_name']) }}')">
                                Select All &amp; Group
                            </button>
                        </div>
                    </div>

                    {{-- Product rows --}}
                    <details id="group-{{ $fi }}" open>
                        <summary style="cursor:pointer;font-weight:600;color:var(--color-accent);padding:0.75rem 1rem;border-top:1px solid #ebdfcf;">
                            Review products ({{ $family['count'] }})
                        </summary>
                        <div class="table-wrap" style="border-top:1px solid #f0ece4;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Product Name</th>
                                        <th>Category</th>
                                        <th>Picture</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($family['products'] as $pi => $product)
                                        <tr class="product-row"
                                            data-group="{{ $fi }}"
                                            data-id="{{ $product['id'] }}"
                                            data-name="{{ addslashes($product['product_name']) }}"
                                            onclick="toggleRow(this)">
                                            <td style="@if($pi === 0)font-weight:600;@endif">
                                                {{ $product['product_name'] }}
                                            </td>
                                            <td>
                                                @if($product['category_id'])
                                                    <span class="pill" style="font-size:0.8rem;">
                                                        {{ optional(\App\Models\Category::find($product['category_id']))->name ?? '—' }}
                                                    </span>
                                                @else
                                                    <span style="color:#9a9590;">—</span>
                                                @endif
                                            </td>
                                            <td>
                                                <a href="{{ route('pictures.show', ['pictureId' => $product['picture_id']]) }}"
                                                   class="brand-product-link"
                                                   style="font-size:0.8rem;"
                                                   onclick="event.stopPropagation()">
                                                    {{ $product['picture_id'] }}
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>
            @endforeach
        </div>
    @endif

    {{-- Sticky selection bar --}}
    <div id="selection-bar" style="
        display:none;position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);
        z-index:40;padding:0.65rem 1rem;
        background:rgba(17,37,31,0.93);backdrop-filter:blur(10px);
        border-radius:999px;border:1px solid rgba(255,255,255,0.12);
        color:#fffdf8;font-size:0.88rem;font-weight:600;
        align-items:center;gap:0.6rem;box-shadow:0 8px 30px rgba(0,0,0,0.28);
        white-space:nowrap;">
        <span id="selection-count" style="padding:0 0.4rem;">0 selected</span>
        <span style="width:1px;height:1.2rem;background:rgba(255,255,255,0.18);display:inline-block;vertical-align:middle;"></span>
        <button type="button"
            style="background:rgba(220,50,50,0.75);border:none;color:#fff;cursor:pointer;border-radius:999px;padding:0.38rem 0.85rem;font-size:0.8rem;font-weight:600;"
            onclick="openDeleteModal()">
            Delete
        </button>
        <button type="button"
            style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);color:#fffdf8;cursor:pointer;border-radius:999px;padding:0.38rem 0.85rem;font-size:0.8rem;font-weight:600;"
            onclick="openRenameModal()">
            Rename
        </button>
        <button type="button" class="button button-primary"
            style="padding:0.38rem 0.85rem;font-size:0.8rem;"
            onclick="openGroupModal()">
            Group as Family
        </button>
        <button type="button"
            style="background:none;border:none;color:rgba(255,255,248,0.45);cursor:pointer;font-size:1rem;padding:0 0.25rem;margin-left:0.15rem;"
            onclick="clearSelection()">✕</button>
    </div>

    {{-- Group as Family modal --}}
    <div id="group-modal" style="display:none;position:fixed;inset:0;z-index:50;align-items:center;justify-content:center;padding:1rem;">
        <button type="button" style="position:absolute;inset:0;background:rgba(18,27,24,0.65);border:none;cursor:pointer;" onclick="closeGroupModal()"></button>
        <div style="position:relative;z-index:10;width:100%;max-width:32rem;background:#fffdf8;border-radius:22px;border:1px solid #d8c9b2;box-shadow:0 20px 60px rgba(0,0,0,0.22);padding:1.5rem;max-height:90vh;overflow-y:auto;">

            <div style="display:flex;align-items:start;justify-content:space-between;gap:1rem;margin-bottom:1.25rem;">
                <div>
                    <p class="eyebrow">Create Catalogue Entry</p>
                    <h3 style="font-size:1.2rem;font-weight:700;margin:0;">Group as Family Product</h3>
                </div>
                <button type="button" onclick="closeGroupModal()"
                    style="background:none;border:1px solid #d8c9b2;border-radius:50%;width:2rem;height:2rem;cursor:pointer;font-size:1.1rem;flex-shrink:0;">✕</button>
            </div>

            <div id="modal-selected-list" style="margin-bottom:1.25rem;padding:0.75rem;background:#f7f3ea;border-radius:14px;font-size:0.82rem;max-height:10rem;overflow-y:auto;"></div>

            <form method="POST" action="{{ route('products.group-family') }}" id="group-form">
                @csrf
                <div id="modal-hidden-inputs"></div>
                <input type="hidden" name="min_similarity" value="{{ $minSimilarity }}">
                <input type="hidden" name="picture_from" value="{{ $filters['picture_from'] }}">
                <input type="hidden" name="picture_to" value="{{ $filters['picture_to'] }}">
                <div class="stack-form">
                    <label>
                        <span>Family Product Name</span>
                        <input type="text" name="family_name" id="modal-family-name"
                            required placeholder="e.g. Baby Nursery Jelly" style="font-weight:600;">
                    </label>
                    <label>
                        <span>Brand</span>
                        <input type="text" name="brand_name" id="modal-brand-name"
                            list="brand-suggestions" required placeholder="Type or choose a brand…">
                    </label>
                    <div style="padding:0.65rem 0.9rem;background:#f0f7f4;border-radius:12px;font-size:0.8rem;color:#3d5c51;">
                        Each selected product will be added as a <strong>type</strong> under this family.
                    </div>
                    <div class="button-row">
                        <button type="submit" class="button button-primary">Create Family</button>
                        <button type="button" class="button" onclick="closeGroupModal()">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Delete modal --}}
    <div id="delete-modal" style="display:none;position:fixed;inset:0;z-index:50;align-items:center;justify-content:center;padding:1rem;">
        <button type="button" style="position:absolute;inset:0;background:rgba(18,27,24,0.65);border:none;cursor:pointer;" onclick="closeDeleteModal()"></button>
        <div style="position:relative;z-index:10;width:100%;max-width:28rem;background:#fffdf8;border-radius:22px;border:1px solid #d8c9b2;box-shadow:0 20px 60px rgba(0,0,0,0.22);padding:1.5rem;">
            <div style="display:flex;align-items:start;justify-content:space-between;gap:1rem;margin-bottom:1rem;">
                <div>
                    <p class="eyebrow">Destructive Action</p>
                    <h3 style="font-size:1.1rem;font-weight:700;margin:0;color:#b91c1c;">Delete Selected Products</h3>
                </div>
                <button type="button" onclick="closeDeleteModal()" style="background:none;border:1px solid #d8c9b2;border-radius:50%;width:2rem;height:2rem;cursor:pointer;font-size:1.1rem;flex-shrink:0;">✕</button>
            </div>
            <div id="delete-selected-list" style="margin-bottom:1.25rem;padding:0.75rem;background:#fef2f2;border-radius:14px;font-size:0.82rem;max-height:12rem;overflow-y:auto;"></div>
            <form method="POST" action="{{ route('products.delete-selected') }}" id="delete-form">
                @csrf
                <div id="delete-hidden-inputs"></div>
                <input type="hidden" name="min_similarity" value="{{ $minSimilarity }}">
                <input type="hidden" name="picture_from" value="{{ $filters['picture_from'] }}">
                <input type="hidden" name="picture_to" value="{{ $filters['picture_to'] }}">
                <div style="padding:0.65rem 0.9rem;background:#fff7ed;border-radius:12px;font-size:0.8rem;color:#92400e;margin-bottom:1rem;">
                    This permanently removes these observed products. This cannot be undone.
                </div>
                <div class="button-row">
                    <button type="submit" class="button button-danger">Delete Products</button>
                    <button type="button" class="button" onclick="closeDeleteModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Rename modal --}}
    <div id="rename-modal" style="display:none;position:fixed;inset:0;z-index:50;align-items:center;justify-content:center;padding:1rem;">
        <button type="button" style="position:absolute;inset:0;background:rgba(18,27,24,0.65);border:none;cursor:pointer;" onclick="closeRenameModal()"></button>
        <div style="position:relative;z-index:10;width:100%;max-width:30rem;background:#fffdf8;border-radius:22px;border:1px solid #d8c9b2;box-shadow:0 20px 60px rgba(0,0,0,0.22);padding:1.5rem;">
            <div style="display:flex;align-items:start;justify-content:space-between;gap:1rem;margin-bottom:1rem;">
                <div>
                    <p class="eyebrow">Edit Product Names</p>
                    <h3 style="font-size:1.1rem;font-weight:700;margin:0;">Rename Selected Products</h3>
                </div>
                <button type="button" onclick="closeRenameModal()" style="background:none;border:1px solid #d8c9b2;border-radius:50%;width:2rem;height:2rem;cursor:pointer;font-size:1.1rem;flex-shrink:0;">✕</button>
            </div>
            <div id="rename-selected-list" style="margin-bottom:1.25rem;padding:0.75rem;background:#f7f3ea;border-radius:14px;font-size:0.82rem;max-height:8rem;overflow-y:auto;"></div>
            <form method="POST" action="{{ route('products.rename-selected') }}" id="rename-form">
                @csrf
                <div id="rename-hidden-inputs"></div>
                <input type="hidden" name="min_similarity" value="{{ $minSimilarity }}">
                <input type="hidden" name="picture_from" value="{{ $filters['picture_from'] }}">
                <input type="hidden" name="picture_to" value="{{ $filters['picture_to'] }}">
                <div class="stack-form">
                    <label>
                        <span>New Product Name</span>
                        <input type="text" name="new_name" id="modal-new-name"
                            required placeholder="Enter new name for all selected products…">
                    </label>
                    <div style="padding:0.65rem 0.9rem;background:#f0f7f4;border-radius:12px;font-size:0.8rem;color:#3d5c51;">
                        All selected products will be renamed to this name.
                    </div>
                    <div class="button-row">
                        <button type="submit" class="button button-primary">Rename Products</button>
                        <button type="button" class="button" onclick="closeRenameModal()">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
    const selectedIds  = new Set();
    const selectedData = {};

    function toggleRow(tr) {
        const id   = parseInt(tr.dataset.id);
        const name = tr.dataset.name;
        if (tr.classList.contains('selected')) {
            tr.classList.remove('selected');
            selectedIds.delete(id);
            delete selectedData[id];
        } else {
            tr.classList.add('selected');
            selectedIds.add(id);
            selectedData[id] = { id, name };
        }
        updateBar();
    }

    function selectGroupAndOpen(groupIndex, brand, firstProductName) {
        document.querySelectorAll(`tr.product-row[data-group="${groupIndex}"]`).forEach(tr => {
            const id = parseInt(tr.dataset.id);
            tr.classList.add('selected');
            selectedIds.add(id);
            selectedData[id] = { id, name: tr.dataset.name };
        });
        updateBar();
        document.getElementById('modal-brand-name').value  = brand;
        document.getElementById('modal-family-name').value = firstProductName;
        openGroupModal();
    }

    function updateBar() {
        const bar = document.getElementById('selection-bar');
        const n   = selectedIds.size;
        document.getElementById('selection-count').textContent = n + ' selected';
        bar.style.display = n > 0 ? 'flex' : 'none';
    }

    function buildHiddenInputs(containerId, formName) {
        const container = document.getElementById(containerId);
        container.innerHTML = '';
        selectedIds.forEach(id => {
            const inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = formName;
            inp.value = id;
            container.appendChild(inp);
        });
    }

    function buildPreviewList(containerId, intro) {
        const items = Object.values(selectedData);
        document.getElementById(containerId).innerHTML =
            `<p style="margin:0 0 0.4rem;font-weight:600;color:#3d5c51;">${intro} (${items.length}):</p>` +
            items.map(p => `<div style="padding:0.2rem 0;border-bottom:1px solid #ece8df;color:#3c403d;">• ${p.name}</div>`).join('');
    }

    // ── Group modal ──────────────────────────────────────────────
    function openGroupModal() {
        if (!selectedIds.size) return;
        buildHiddenInputs('modal-hidden-inputs', 'product_ids[]');
        buildPreviewList('modal-selected-list', 'Will become types');
        document.getElementById('group-modal').style.display = 'flex';
        document.getElementById('modal-family-name').focus();
    }
    function closeGroupModal() { document.getElementById('group-modal').style.display = 'none'; }

    // ── Delete modal ─────────────────────────────────────────────
    function openDeleteModal() {
        if (!selectedIds.size) return;
        buildHiddenInputs('delete-hidden-inputs', 'product_ids[]');
        buildPreviewList('delete-selected-list', 'Will be permanently deleted');
        document.getElementById('delete-modal').style.display = 'flex';
    }
    function closeDeleteModal() { document.getElementById('delete-modal').style.display = 'none'; }

    // ── Rename modal ─────────────────────────────────────────────
    function openRenameModal() {
        if (!selectedIds.size) return;
        buildHiddenInputs('rename-hidden-inputs', 'product_ids[]');
        buildPreviewList('rename-selected-list', 'Will be renamed');
        const first = Object.values(selectedData)[0];
        document.getElementById('modal-new-name').value = first ? first.name : '';
        document.getElementById('rename-modal').style.display = 'flex';
        document.getElementById('modal-new-name').focus();
    }
    function closeRenameModal() { document.getElementById('rename-modal').style.display = 'none'; }

    // ── Clear ────────────────────────────────────────────────────
    function clearSelection() {
        selectedIds.clear();
        Object.keys(selectedData).forEach(k => delete selectedData[k]);
        document.querySelectorAll('tr.product-row.selected').forEach(tr => tr.classList.remove('selected'));
        updateBar();
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeGroupModal(); closeDeleteModal(); closeRenameModal();
        }
    });
    </script>
@endsection
