@php
    $descendantIds = $node->descendant_ids ?? [];
    $depth = $node->tree_depth ?? 0;
    $hasChildren = $node->children->isNotEmpty();
@endphp

<details class="sr-node {{ $depth === 0 ? 'sr-node-root' : 'sr-node-child' }}">
    <summary class="sr-node-summary">
        <span class="sr-node-order">{{ $node->sort_order }}</span>
        <div class="sr-node-info">
            <span class="sr-node-name">{{ $node->name }}</span>
            <span class="sr-node-meta">
                @if ($hasChildren)
                    <span class="sr-node-count">{{ $node->children->count() }}</span>
                @endif
                @if ($node->note)
                    <span class="sr-node-note-text">{{ Str::limit($node->note, 40) }}</span>
                @endif
            </span>
        </div>
        <span class="sr-node-toggle">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
        </span>
    </summary>

    <form method="POST" action="{{ route('categories.scaffold.nodes.update', ['node' => $node]) }}" class="sr-node-form">
        @csrf
        @method('PATCH')
        <div class="sr-node-form-row">
            <label class="sr-node-field sr-node-field-name">
                <span>Name</span>
                <input type="text" name="name" value="{{ $node->name }}" required>
            </label>
            <label class="sr-node-field sr-node-field-axis">
                <span>Axis</span>
                <select name="category_scaffold_axis_id">
                    @foreach ($axisOptions as $option)
                        <option value="{{ $option['id'] }}" @selected((int) $node->category_scaffold_axis_id === $option['id'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="sr-node-field sr-node-field-parent">
                <span>Parent</span>
                <select name="parent_id">
                    <option value="">Root level</option>
                    @foreach ($parentOptions as $option)
                        @continue($option['id'] === $node->id || in_array($option['id'], $descendantIds, true))
                        <option value="{{ $option['id'] }}" @selected((int) $node->parent_id === $option['id'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="sr-node-field sr-node-field-sort">
                <span>Sort</span>
                <input type="number" name="sort_order" value="{{ $node->sort_order }}" min="0">
            </label>
            <label class="sr-node-field sr-node-field-note">
                <span>Note</span>
                <input type="text" name="note" value="{{ $node->note }}" placeholder="Optional">
            </label>
            <button type="submit" class="sr-node-save">Save</button>
        </div>
    </form>

    @if ($hasChildren)
        <div class="sr-node-children">
            @foreach ($node->children as $child)
                @include('categories.partials.scaffold-node', [
                    'node' => $child,
                    'root' => $root,
                    'axis' => $axis,
                    'axisOptions' => $axisOptions,
                    'parentOptions' => $parentOptions,
                ])
            @endforeach
        </div>
    @endif
</details>
