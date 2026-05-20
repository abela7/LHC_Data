@extends('layouts.app')

@section('title', 'Inventory Stores, Sections & Subsections')
@section('section', 'Inventory')
@section('heading', 'Stores, Sections & Subsections')

@section('content')
    <section class="inv-structure" data-inventory-structure>
        <div class="inv-hero">
            <div>
                <p class="inv-eyebrow">Inventory structure</p>
                <h1>Stores, sections & subsections</h1>
                <p>Create the physical shop structure once, then reuse it when assigning product stock, shelves, and barcode checks.</p>
            </div>
            <div class="inv-stats">
                <div><strong>{{ number_format($stats['stores']) }}</strong><span>stores</span></div>
                <div><strong>{{ number_format($stats['sections']) }}</strong><span>sections</span></div>
                <div><strong>{{ number_format($stats['subsections']) }}</strong><span>subsections</span></div>
                <div><strong>{{ number_format($stats['activeStores'] + $stats['activeSections'] + $stats['activeSubsections']) }}</strong><span>active records</span></div>
            </div>
        </div>

        <div class="inv-live-status" data-inventory-status role="status" aria-live="polite" hidden></div>

        <form method="POST" action="{{ route('inventory-structure.stores.store') }}" class="inv-create-card">
            @csrf
            <div>
                <span class="inv-form-label">Create store</span>
                <strong>Start with Store one, Store two, or any shop name you use.</strong>
            </div>
            <input type="text" name="name" placeholder="Store name" required>
            <input type="number" name="sort_order" value="0" min="0" title="Sort order">
            <label class="inv-check"><input type="checkbox" name="is_default" value="1"> Default</label>
            <label class="inv-check"><input type="checkbox" name="is_active" value="1" checked> Active</label>
            <button type="submit">Add store</button>
        </form>

        <div class="inv-store-list">
            @forelse ($stores as $store)
                @php($storeSubsectionCount = $store->sections->sum(fn ($section) => $section->subsections->count()))
                <article class="inv-store-card">
                    <div class="inv-store-head">
                        <div>
                            <span class="inv-store-order">{{ $store->sort_order }}</span>
                            <h2>{{ $store->name }}</h2>
                            <p>
                                {{ $store->sections->count() }} section{{ $store->sections->count() === 1 ? '' : 's' }}
                                &middot; {{ $storeSubsectionCount }} subsection{{ $storeSubsectionCount === 1 ? '' : 's' }}
                                &middot; {{ $store->inventory_levels_count }} stock record{{ $store->inventory_levels_count === 1 ? '' : 's' }}
                            </p>
                        </div>
                        <div class="inv-badges">
                            <span class="{{ $store->is_active ? 'is-on' : 'is-off' }}">{{ $store->is_active ? 'Active' : 'Inactive' }}</span>
                            @if ($store->is_default)
                                <span class="is-default">Default</span>
                            @endif
                        </div>
                    </div>

                    <details class="inv-edit" data-inv-details="store-{{ $store->id }}">
                        <summary>Edit store</summary>
                        <form method="POST" action="{{ route('inventory-structure.stores.update', $store) }}" class="inv-edit-form">
                            @csrf
                            @method('PATCH')
                            <input type="text" name="name" value="{{ $store->name }}" required>
                            <input type="number" name="sort_order" value="{{ $store->sort_order }}" min="0">
                            <label class="inv-check"><input type="checkbox" name="is_default" value="1" @checked($store->is_default)> Default</label>
                            <label class="inv-check"><input type="checkbox" name="is_active" value="1" @checked($store->is_active)> Active</label>
                            <button type="submit">Save store</button>
                        </form>
                        <form method="POST" action="{{ route('inventory-structure.stores.destroy', $store) }}" data-confirm="Delete {{ $store->name }} and its sections?">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="inv-danger" @disabled((int) $store->inventory_levels_count > 0)>Delete store</button>
                            @if ((int) $store->inventory_levels_count > 0)
                                <p class="inv-delete-note">Cannot delete while {{ number_format($store->inventory_levels_count) }} product stock record{{ $store->inventory_levels_count === 1 ? '' : 's' }} use this store.</p>
                            @else
                                <p class="inv-delete-note">This will also delete unused sections and subsections under this store.</p>
                            @endif
                        </form>
                    </details>

                    <div class="inv-section-panel">
                        <form method="POST" action="{{ route('inventory-structure.sections.store', $store) }}" class="inv-section-create">
                            @csrf
                            <input type="text" name="name" placeholder="New section name" required>
                            <input type="text" name="note" placeholder="Optional note">
                            <input type="number" name="sort_order" value="0" min="0" title="Sort order">
                            <label class="inv-check"><input type="checkbox" name="is_active" value="1" checked> Active</label>
                            <button type="submit">Add section</button>
                        </form>

                        <div class="inv-section-list">
                            @forelse ($store->sections as $section)
                                <details class="inv-section-row" data-inv-details="section-{{ $section->id }}">
                                    <summary>
                                        <span>{{ $section->sort_order }}</span>
                                        <strong>{{ $section->name }}</strong>
                                        <em>{{ $section->is_active ? 'Active' : 'Inactive' }}</em>
                                        <small>{{ $section->subsections->count() }} subsection{{ $section->subsections->count() === 1 ? '' : 's' }}</small>
                                        @if ($section->inventory_levels_count > 0)
                                            <small>{{ $section->inventory_levels_count }} product{{ $section->inventory_levels_count === 1 ? '' : 's' }}</small>
                                        @endif
                                    </summary>

                                    <form method="POST" action="{{ route('inventory-structure.sections.update', $section) }}" class="inv-edit-form">
                                        @csrf
                                        @method('PATCH')
                                        <input type="text" name="name" value="{{ $section->name }}" required>
                                        <input type="text" name="note" value="{{ $section->note }}" placeholder="Optional note">
                                        <input type="number" name="sort_order" value="{{ $section->sort_order }}" min="0">
                                        <label class="inv-check"><input type="checkbox" name="is_active" value="1" @checked($section->is_active)> Active</label>
                                        <button type="submit">Save section</button>
                                    </form>

                                    <form method="POST" action="{{ route('inventory-structure.sections.destroy', $section) }}" data-confirm="Delete section {{ $section->name }}?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inv-danger" @disabled((int) $section->inventory_levels_count > 0)>Delete section</button>
                                        @if ((int) $section->inventory_levels_count > 0)
                                            <p class="inv-delete-note">Cannot delete while {{ number_format($section->inventory_levels_count) }} product{{ $section->inventory_levels_count === 1 ? '' : 's' }} use this section.</p>
                                        @else
                                            <p class="inv-delete-note">This will also delete unused subsections under this section.</p>
                                        @endif
                                    </form>

                                    <div class="inv-subsection-panel">
                                        <div class="inv-subsection-title">
                                            <strong>Subsections</strong>
                                            <span>Add shelf, wall, row, or bay names under this section.</span>
                                        </div>

                                        <form method="POST" action="{{ route('inventory-structure.subsections.store', $section) }}" class="inv-subsection-create">
                                            @csrf
                                            <input type="text" name="name" placeholder="New subsection name" required>
                                            <input type="text" name="note" placeholder="Optional note">
                                            <input type="number" name="sort_order" value="0" min="0" title="Sort order">
                                            <label class="inv-check"><input type="checkbox" name="is_active" value="1" checked> Active</label>
                                            <button type="submit">Add subsection</button>
                                        </form>

                                        <div class="inv-subsection-list">
                                            @forelse ($section->subsections as $subsection)
                                                <details class="inv-subsection-row" data-inv-details="subsection-{{ $subsection->id }}">
                                                    <summary>
                                                        <span>{{ $subsection->sort_order }}</span>
                                                        <strong>{{ $subsection->name }}</strong>
                                                        <em>{{ $subsection->is_active ? 'Active' : 'Inactive' }}</em>
                                                        @if ($subsection->inventory_levels_count > 0)
                                                            <small>{{ $subsection->inventory_levels_count }} product{{ $subsection->inventory_levels_count === 1 ? '' : 's' }}</small>
                                                        @endif
                                                    </summary>
                                                    <form method="POST" action="{{ route('inventory-structure.subsections.update', $subsection) }}" class="inv-edit-form">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="text" name="name" value="{{ $subsection->name }}" required>
                                                        <input type="text" name="note" value="{{ $subsection->note }}" placeholder="Optional note">
                                                        <input type="number" name="sort_order" value="{{ $subsection->sort_order }}" min="0">
                                                        <label class="inv-check"><input type="checkbox" name="is_active" value="1" @checked($subsection->is_active)> Active</label>
                                                        <button type="submit">Save subsection</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('inventory-structure.subsections.destroy', $subsection) }}" data-confirm="Delete subsection {{ $subsection->name }}?">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="inv-danger" @disabled((int) $subsection->inventory_levels_count > 0)>Delete subsection</button>
                                                        @if ((int) $subsection->inventory_levels_count > 0)
                                                            <p class="inv-delete-note">Cannot delete while {{ number_format($subsection->inventory_levels_count) }} product{{ $subsection->inventory_levels_count === 1 ? '' : 's' }} use this subsection.</p>
                                                        @else
                                                            <p class="inv-delete-note">This subsection is unused and can be deleted.</p>
                                                        @endif
                                                    </form>
                                                </details>
                                            @empty
                                                <div class="inv-empty">No subsections yet. Add one under this section if needed.</div>
                                            @endforelse
                                        </div>
                                    </div>
                                </details>
                            @empty
                                <div class="inv-empty">No sections yet. Add the first section for this store.</div>
                            @endforelse
                        </div>
                    </div>
                </article>
            @empty
                <div class="inv-empty inv-empty-large">No stores yet. Create Store one and Store two above.</div>
            @endforelse
        </div>
    </section>
@endsection
