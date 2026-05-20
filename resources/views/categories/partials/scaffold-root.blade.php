<div class="scaffold-card-head">
    <h4>{{ $root->name }}</h4>
    <div class="header-actions">
        <span class="pill">{{ $root->nodes->count() }} nodes</span>
        @if ($showMetaType && $root->meta_type)
            <span class="pill pill-warning">{{ $root->meta_type }}</span>
        @endif
    </div>
</div>

<p class="scaffold-note">{{ $root->note ?: 'No note yet.' }}</p>

<div class="scaffold-node-list">
    @forelse ($root->nodes as $node)
        <details class="details-block scaffold-node-item">
            <summary class="scaffold-node-summary">
                <span class="scaffold-node-name">{{ $node->name }}</span>
                <span class="pill">Edit</span>
            </summary>

            <form method="POST" action="{{ route('categories.scaffold.nodes.update', ['node' => $node]) }}" class="stack-form scaffold-details-body">
                @csrf
                @method('PATCH')

                <div class="scaffold-node-grid">
                    <label>
                        <span>Node name</span>
                        <input type="text" name="name" value="{{ $node->name }}" required>
                    </label>

                    <label>
                        <span>Sort order</span>
                        <input type="number" name="sort_order" value="{{ $node->sort_order }}" min="0">
                    </label>
                </div>

                <label>
                    <span>Note</span>
                    <input type="text" name="note" value="{{ $node->note }}" placeholder="Optional note">
                </label>

                <div class="button-row">
                    <button type="submit" class="button">Save node</button>
                </div>
            </form>
        </details>
    @empty
        <p class="scaffold-note">No child nodes yet.</p>
    @endforelse
</div>

<div class="scaffold-action-row">
    <details class="details-block scaffold-inline-details">
        <summary class="scaffold-summary-action">Edit root</summary>

        <form method="POST" action="{{ route('categories.scaffold.roots.update', ['root' => $root]) }}" class="stack-form scaffold-details-body">
            @csrf
            @method('PATCH')

            <div class="form-grid scaffold-form-grid">
                <label>
                    <span>Name</span>
                    <input type="text" name="name" value="{{ $root->name }}" required>
                </label>

                <label>
                    <span>Section</span>
                    <select name="group_key">
                        <option value="catalogue" @selected($root->group_key === 'catalogue')>Catalogue category</option>
                        <option value="department" @selected($root->group_key === 'department')>Department bucket</option>
                        <option value="collection" @selected($root->group_key === 'collection')>Non-category collection</option>
                    </select>
                </label>

                <label>
                    <span>Sort order</span>
                    <input type="number" name="sort_order" value="{{ $root->sort_order }}" min="0">
                </label>

                <label>
                    <span>Meta type</span>
                    <input type="text" name="meta_type" value="{{ $root->meta_type }}" placeholder="Only for non-category collections">
                </label>
            </div>

            <label>
                <span>Note</span>
                <textarea name="note" rows="2">{{ $root->note }}</textarea>
            </label>

            <div class="button-row">
                <button type="submit" class="button">Save root</button>
            </div>
        </form>
    </details>

    <details class="details-block scaffold-inline-details">
        <summary class="scaffold-summary-action scaffold-summary-action-primary">Add node</summary>

        <form method="POST" action="{{ route('categories.scaffold.nodes.store', ['root' => $root]) }}" class="stack-form scaffold-details-body">
            @csrf

            <div class="scaffold-node-grid">
                <label>
                    <span>Node name</span>
                    <input type="text" name="name" placeholder="For example: Leave-In Conditioner" required>
                </label>

                <label>
                    <span>Sort order</span>
                    <input type="number" name="sort_order" value="0" min="0">
                </label>
            </div>

            <label>
                <span>Note</span>
                <input type="text" name="note" placeholder="Optional note">
            </label>

            <div class="button-row">
                <button type="submit" class="button button-primary">Save new node</button>
            </div>
        </form>
    </details>
</div>
