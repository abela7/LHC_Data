@extends('layouts.app')

@section('title', $root->name)

@section('content')
    @include('partials.catalogue-breadcrumb', [
        'items' => [
            ['label' => 'Scaffold', 'url' => route('categories.scaffold')],
            ['label' => $groupMeta['label'], 'url' => route('categories.scaffold.section', ['group' => $root->group_key])],
            ['label' => $root->name, 'current' => true],
        ],
    ])

    <div class="sr-hero">
        <div class="sr-hero-top">
            <div class="sr-hero-title-block">
                <h1 class="sr-hero-title">{{ $root->name }}</h1>
                <div class="sr-hero-badges">
                    <span class="sr-badge sr-badge-accent">{{ $groupMeta['label'] }}</span>
                    @if ($root->meta_type)
                        <span class="sr-badge sr-badge-warn">{{ $root->meta_type }}</span>
                    @endif
                </div>
            </div>
            <button type="button" class="sr-edit-trigger" onclick="document.getElementById('root-edit-drawer').toggleAttribute('open')">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
            </button>
        </div>

        <div class="sr-stats">
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $axes->count() }}</span>
                <span class="sr-stat-label">axes</span>
            </div>
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $allNodes->count() }}</span>
                <span class="sr-stat-label">total nodes</span>
            </div>
            <div class="sr-stat">
                <span class="sr-stat-num">{{ $brandAssignments->count() }}</span>
                <span class="sr-stat-label">brands</span>
            </div>
        </div>
    </div>

    <details id="root-edit-drawer" class="sr-edit-drawer">
        <summary class="sr-edit-drawer-summary">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
            Edit root settings
        </summary>
        <form method="POST" action="{{ route('categories.scaffold.roots.update', ['root' => $root]) }}" class="sr-edit-form">
            @csrf
            @method('PATCH')
            <div class="sr-edit-grid">
                <label>
                    <span>Name</span>
                    <input type="text" name="name" value="{{ $root->name }}" required>
                </label>
                <label>
                    <span>Section</span>
                    <select name="group_key">
                        <option value="catalogue" @selected($root->group_key === 'catalogue')>Catalogue</option>
                        <option value="department" @selected($root->group_key === 'department')>Department</option>
                        <option value="collection" @selected($root->group_key === 'collection')>Collection</option>
                    </select>
                </label>
                <label>
                    <span>Sort</span>
                    <input type="number" name="sort_order" value="{{ $root->sort_order }}" min="0">
                </label>
                <label>
                    <span>Meta type</span>
                    <input type="text" name="meta_type" value="{{ $root->meta_type }}" placeholder="Optional">
                </label>
            </div>
            <label>
                <span>Note</span>
                <textarea name="note" rows="2">{{ $root->note }}</textarea>
            </label>
            <div class="button-row">
                <button type="submit" class="button button-primary">Save changes</button>
            </div>
        </form>
    </details>

    @if ($brandAssignments->isNotEmpty())
        <div class="sr-brands">
            <div class="sr-brands-label">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z"/></svg>
                <span>{{ $brandAssignments->count() }} brands</span>
            </div>
            <div class="sr-brands-chips">
                @foreach ($brandAssignments as $assignment)
                    <a href="{{ route('real-brands.show', ['brand' => $assignment->canonical_brand_name]) }}" class="sr-brand-chip">
                        {{ $assignment->canonical_brand_name }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="sr-axis-create">
        <div>
            <h2>Axis Framework</h2>
            <p>Keep one primary operational axis for stock ownership and add discovery axes only when they improve browsing without distorting the main taxonomy.</p>
        </div>
        <form method="POST" action="{{ route('categories.scaffold.axes.store', ['root' => $root]) }}" class="sr-axis-create-form">
            @csrf
            <input type="text" name="name" placeholder="New axis name..." required>
            <input type="number" name="sort_order" value="0" min="0" title="Sort order">
            <input type="text" name="note" placeholder="Optional note">
            <label class="sr-axis-primary-toggle">
                <input type="checkbox" name="is_primary" value="1">
                <span>Primary axis</span>
            </label>
            <button type="submit" class="sr-add-btn">Add axis</button>
        </form>
    </div>

    @if ($axes->isEmpty())
        <div class="sr-empty">
            <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
            <p>No axes yet - add one above</p>
        </div>
    @else
        <div class="sr-axis-stack">
            @foreach ($axes as $axis)
                <section class="sr-axis-card">
                    <div class="sr-axis-head">
                        <div class="sr-axis-copy">
                            <div class="sr-axis-kicker">
                                <span class="sr-badge {{ $axis->is_primary ? 'sr-badge-accent' : 'sr-badge-warn' }}">
                                    {{ $axis->is_primary ? 'Primary Axis' : 'Discovery Axis' }}
                                </span>
                                <span>{{ $axis->nodes->count() }} top-level nodes</span>
                            </div>
                            <h2>{{ $axis->name }}</h2>
                            @if ($axis->note)
                                <p>{{ $axis->note }}</p>
                            @endif
                        </div>

                        <details class="sr-axis-edit">
                            <summary>Edit axis</summary>
                            <form method="POST" action="{{ route('categories.scaffold.axes.update', ['axis' => $axis]) }}" class="sr-axis-edit-form">
                                @csrf
                                @method('PATCH')
                                <input type="text" name="name" value="{{ $axis->name }}" required>
                                <input type="number" name="sort_order" value="{{ $axis->sort_order }}" min="0">
                                <input type="text" name="note" value="{{ $axis->note }}" placeholder="Optional note">
                                <label class="sr-axis-primary-toggle">
                                    <input type="checkbox" name="is_primary" value="1" @checked($axis->is_primary)>
                                    <span>Primary axis</span>
                                </label>
                                <button type="submit" class="sr-node-save">Save axis</button>
                            </form>
                        </details>
                    </div>

                    <form method="POST" action="{{ route('categories.scaffold.nodes.store', ['root' => $root]) }}" class="sr-add-bar">
                        @csrf
                        <input type="hidden" name="category_scaffold_axis_id" value="{{ $axis->id }}">
                        <div class="sr-add-bar-icon">
                            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        </div>
                        <select name="parent_id" class="sr-add-select">
                            <option value="">Root level</option>
                            @foreach ($axis->parent_options as $option)
                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="name" placeholder="Node name..." required class="sr-add-input">
                        <input type="number" name="sort_order" value="0" min="0" class="sr-add-sort" title="Sort order">
                        <input type="text" name="note" placeholder="Note" class="sr-add-note">
                        <button type="submit" class="sr-add-btn">Add</button>
                    </form>

                    @if ($axis->nodes->isEmpty())
                        <div class="sr-empty">
                            <p>No nodes yet in {{ $axis->name }}</p>
                        </div>
                    @else
                        <div class="sr-tree">
                            @foreach ($axis->nodes as $node)
                                @include('categories.partials.scaffold-node', [
                                    'node' => $node,
                                    'root' => $root,
                                    'axis' => $axis,
                                    'axisOptions' => $axisOptions,
                                    'parentOptions' => $axis->parent_options,
                                ])
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
@endsection
