<?php

namespace App\Http\Controllers;

use App\Models\CategoryScaffold;
use App\Models\CategoryScaffoldAxis;
use App\Models\CategoryScaffoldNode;
use App\Support\BeautizoneCategoryScaffold;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryScaffoldController extends Controller
{
    public function index(): View
    {
        $roots = CategoryScaffold::query()
            ->with('nodes')
            ->get();

        return view('categories.scaffold', [
            'landingRules' => BeautizoneCategoryScaffold::landingRules(),
            'sources' => BeautizoneCategoryScaffold::sources(),
            'stats' => $this->stats($roots),
            'sections' => $this->sections($roots),
        ]);
    }

    public function section(string $group): View
    {
        abort_unless(in_array($group, ['catalogue', 'department', 'collection'], true), 404);

        $roots = CategoryScaffold::query()
            ->withCount('nodes')
            ->where('group_key', $group)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('categories.scaffold-section', [
            'group' => $group,
            'groupMeta' => $this->groupMeta($group),
            'roots' => $roots,
        ]);
    }

    public function showRoot(CategoryScaffold $root): View
    {
        $root->load([
            'nodes' => fn ($query) => $query->orderBy('sort_order')->orderBy('name'),
            'axes' => fn ($query) => $query
                ->with(['nodes' => fn ($nodeQuery) => $nodeQuery->orderBy('sort_order')->orderBy('name')])
                ->orderBy('sort_order')
                ->orderBy('name'),
            'brandAssignments' => fn ($query) => $query->whereNull('category_scaffold_node_id')->orderBy('sort_order')->orderBy('canonical_brand_name'),
        ]);
        $axes = $root->axes->map(function (CategoryScaffoldAxis $axis) {
            $rootNodes = $this->buildNodeTree($axis->nodes);

            $axis->setRelation('nodes', $rootNodes);
            $axis->setAttribute('parent_options', $this->flattenNodeOptions($rootNodes));

            return $axis;
        });

        return view('categories.scaffold-root', [
            'root' => $root,
            'groupMeta' => $this->groupMeta($root->group_key),
            'axes' => $axes,
            'allNodes' => $root->nodes,
            'axisOptions' => $axes->map(fn (CategoryScaffoldAxis $axis) => [
                'id' => $axis->id,
                'label' => $axis->name,
            ])->all(),
            'brandAssignments' => $root->brandAssignments,
        ]);
    }

    public function storeAxis(Request $request, CategoryScaffold $root): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($root, $validated) {
            $isPrimary = (bool) ($validated['is_primary'] ?? false);

            if ($isPrimary) {
                $root->axes()->update(['is_primary' => false]);
            }

            $root->axes()->create([
                'key' => $this->makeUniqueAxisKey($root, trim($validated['name'])),
                'name' => trim($validated['name']),
                'note' => $this->nullableTrim($validated['note'] ?? null),
                'is_primary' => $isPrimary,
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
            ]);
        });

        return redirect()
            ->route('categories.scaffold.roots.show', ['root' => $root])
            ->with('status', "Added axis to {$root->name}.");
    }

    public function updateAxis(Request $request, CategoryScaffoldAxis $axis): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $name = trim($validated['name']);

        DB::transaction(function () use ($axis, $validated, $name) {
            $isPrimary = (bool) ($validated['is_primary'] ?? false);

            if ($isPrimary) {
                $axis->scaffold->axes()->whereKeyNot($axis->id)->update(['is_primary' => false]);
            } elseif (! $axis->scaffold->axes()->whereKeyNot($axis->id)->where('is_primary', true)->exists()) {
                $isPrimary = true;
            }

            $axis->update([
                'key' => $name !== $axis->name ? $this->makeUniqueAxisKey($axis->scaffold, $name, $axis->id) : $axis->key,
                'name' => $name,
                'note' => $this->nullableTrim($validated['note'] ?? null),
                'is_primary' => $isPrimary,
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
            ]);
        });

        return redirect()
            ->route('categories.scaffold.roots.show', ['root' => $axis->scaffold])
            ->with('status', "Updated axis {$axis->name}.");
    }

    public function storeRoot(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'group_key' => ['required', 'in:catalogue,department,collection'],
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'meta_type' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        CategoryScaffold::query()->create([
            'group_key' => $validated['group_key'],
            'name' => trim($validated['name']),
            'slug' => $this->makeUniqueRootSlug(trim($validated['name'])),
            'note' => $this->nullableTrim($validated['note'] ?? null),
            'meta_type' => $validated['group_key'] === 'collection' ? $this->nullableTrim($validated['meta_type'] ?? null) : null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => true,
        ]);

        return redirect()
            ->route('categories.scaffold.section', ['group' => $validated['group_key']])
            ->with('status', 'Scaffold category added.');
    }

    public function updateRoot(Request $request, CategoryScaffold $root): RedirectResponse
    {
        $validated = $request->validate([
            'group_key' => ['required', 'in:catalogue,department,collection'],
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'meta_type' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $name = trim($validated['name']);

        $root->update([
            'group_key' => $validated['group_key'],
            'name' => $name,
            'slug' => $name !== $root->name ? $this->makeUniqueRootSlug($name, $root->id) : $root->slug,
            'note' => $this->nullableTrim($validated['note'] ?? null),
            'meta_type' => $validated['group_key'] === 'collection' ? $this->nullableTrim($validated['meta_type'] ?? null) : null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return redirect()
            ->route('categories.scaffold.roots.show', ['root' => $root])
            ->with('status', "Updated scaffold category {$root->name}.");
    }

    public function storeNode(Request $request, CategoryScaffold $root): RedirectResponse
    {
        $validated = $request->validate([
            'category_scaffold_axis_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $name = trim($validated['name']);
        $axis = $this->resolveAxis($root, (int) $validated['category_scaffold_axis_id']);
        $parent = $this->resolveParentNode(
            $root,
            $axis,
            isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
        );

        $root->nodes()->create([
            'category_scaffold_axis_id' => $axis->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'slug' => $this->makeUniqueNodeSlug($root, $name),
            'note' => $this->nullableTrim($validated['note'] ?? null),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'is_active' => true,
        ]);

        return redirect()
            ->route('categories.scaffold.roots.show', ['root' => $root])
            ->with('status', "Added node to {$root->name}.");
    }

    public function updateNode(Request $request, CategoryScaffoldNode $node): RedirectResponse
    {
        $validated = $request->validate([
            'category_scaffold_axis_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $name = trim($validated['name']);
        $axis = $this->resolveAxis($node->scaffold, (int) $validated['category_scaffold_axis_id']);
        $parent = $this->resolveParentNode(
            $node->scaffold,
            $axis,
            isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
            $node,
        );
        $descendantIds = $axis->id !== (int) $node->category_scaffold_axis_id
            ? $this->descendantIdsForNode($node)
            : [];

        DB::transaction(function () use ($node, $axis, $parent, $name, $validated, $descendantIds) {
            $node->update([
                'category_scaffold_axis_id' => $axis->id,
                'parent_id' => $parent?->id,
                'name' => $name,
                'slug' => $name !== $node->name ? $this->makeUniqueNodeSlug($node->scaffold, $name, $node->id) : $node->slug,
                'note' => $this->nullableTrim($validated['note'] ?? null),
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
            ]);

            if ($descendantIds !== []) {
                CategoryScaffoldNode::query()
                    ->whereIn('id', $descendantIds)
                    ->update(['category_scaffold_axis_id' => $axis->id]);
            }
        });

        return redirect()
            ->route('categories.scaffold.roots.show', ['root' => $node->scaffold])
            ->with('status', "Updated node {$node->name}.");
    }

    /**
     * @param  Collection<int, CategoryScaffold>  $roots
     * @return array<int, array{group:string,label:string,note:string,count:int,node_count:int,cta:string}>
     */
    private function sections(Collection $roots): array
    {
        return collect(['catalogue', 'department', 'collection'])
            ->map(function (string $group) use ($roots): array {
                $meta = $this->groupMeta($group);
                $scoped = $roots->where('group_key', $group);

                return [
                    'group' => $group,
                    'label' => $meta['label'],
                    'note' => $meta['note'],
                    'count' => $scoped->count(),
                    'node_count' => $scoped->sum(fn (CategoryScaffold $root) => $root->nodes->count()),
                    'cta' => $meta['cta'],
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, CategoryScaffold>  $roots
     * @return array{roots: int, children: int, departments: int, department_children: int, excluded: int}
     */
    private function stats(Collection $roots): array
    {
        $catalogue = $roots->where('group_key', 'catalogue');
        $departments = $roots->where('group_key', 'department');

        return [
            'roots' => $catalogue->count(),
            'children' => $catalogue->sum(fn (CategoryScaffold $root) => $root->nodes->count()),
            'departments' => $departments->count(),
            'department_children' => $departments->sum(fn (CategoryScaffold $root) => $root->nodes->count()),
            'excluded' => $roots->where('group_key', 'collection')->count(),
        ];
    }

    /**
     * @return array{label:string,note:string,cta:string}
     */
    private function groupMeta(string $group): array
    {
        return match ($group) {
            'catalogue' => [
                'label' => 'Catalogue Categories',
                'note' => 'Actual product taxonomy such as Hair Care, Skin Care, Hair Extensions, and Makeup.',
                'cta' => 'Open catalogue categories',
            ],
            'department' => [
                'label' => 'Department Buckets',
                'note' => 'Audience-led buckets like Kids and Mens. Useful for navigation, but separate from the main product tree.',
                'cta' => 'Open department buckets',
            ],
            'collection' => [
                'label' => 'Non-category Collections',
                'note' => 'Merchandising or navigation pages like Bundles, Sale, and A-Z Brands that should stay out of taxonomy.',
                'cta' => 'Open non-category collections',
            ],
        };
    }

    private function makeUniqueRootSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'scaffold';
        $slug = $base;
        $suffix = 2;

        while (CategoryScaffold::query()
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function makeUniqueNodeSlug(CategoryScaffold $root, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'node';
        $slug = $base;
        $suffix = 2;

        while (CategoryScaffoldNode::query()
            ->where('category_scaffold_id', $root->id)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function makeUniqueAxisKey(CategoryScaffold $root, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name, '_') ?: 'axis';
        $key = $base;
        $suffix = 2;

        while (CategoryScaffoldAxis::query()
            ->where('category_scaffold_id', $root->id)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('key', $key)
            ->exists()) {
            $key = $base.'_'.$suffix;
            $suffix++;
        }

        return $key;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  Collection<int, CategoryScaffoldNode>  $nodes
     * @return Collection<int, CategoryScaffoldNode>
     */
    private function buildNodeTree(Collection $nodes): Collection
    {
        $grouped = $nodes->groupBy(fn (CategoryScaffoldNode $node) => $node->parent_id ?? 0);

        return $this->buildNodeBranch($grouped, null, 0);
    }

    /**
     * @param  Collection<int|string, Collection<int, CategoryScaffoldNode>>  $grouped
     * @return Collection<int, CategoryScaffoldNode>
     */
    private function buildNodeBranch(Collection $grouped, ?int $parentId, int $depth): Collection
    {
        return ($grouped->get($parentId ?? 0, collect()))
            ->values()
            ->map(function (CategoryScaffoldNode $node) use ($grouped, $depth) {
                $children = $this->buildNodeBranch($grouped, $node->id, $depth + 1);
                $descendantIds = [];

                foreach ($children as $child) {
                    $descendantIds[] = $child->id;
                    $descendantIds = array_merge($descendantIds, $child->descendant_ids ?? []);
                }

                $node->setRelation('children', $children);
                $node->setAttribute('tree_depth', $depth);
                $node->setAttribute('descendant_ids', array_values(array_unique($descendantIds)));

                return $node;
            });
    }

    /**
     * @param  Collection<int, CategoryScaffoldNode>  $rootNodes
     * @return array<int, array{id:int,label:string}>
     */
    private function flattenNodeOptions(Collection $rootNodes): array
    {
        $options = [];

        foreach ($rootNodes as $node) {
            $options[] = [
                'id' => $node->id,
                'label' => str_repeat('-- ', (int) ($node->tree_depth ?? 0)).$node->name,
            ];

            foreach ($this->flattenNodeOptions($node->children) as $childOption) {
                $options[] = $childOption;
            }
        }

        return $options;
    }

    private function resolveParentNode(
        CategoryScaffold $root,
        CategoryScaffoldAxis $axis,
        ?int $parentId,
        ?CategoryScaffoldNode $currentNode = null,
    ): ?CategoryScaffoldNode {
        if ($parentId === null || $parentId === 0) {
            return null;
        }

        $parent = CategoryScaffoldNode::query()
            ->where('category_scaffold_id', $root->id)
            ->where('category_scaffold_axis_id', $axis->id)
            ->find($parentId);

        if (! $parent) {
            throw ValidationException::withMessages([
                'parent_id' => 'Select a valid parent from this scaffold root.',
            ]);
        }

        if ($currentNode && $parent->id === $currentNode->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'A node cannot be its own parent.',
            ]);
        }

        if ($currentNode && $this->wouldCreateCycle($currentNode, $parent)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Choose a parent outside this node branch.',
            ]);
        }

        return $parent;
    }

    private function resolveAxis(CategoryScaffold $root, int $axisId): CategoryScaffoldAxis
    {
        $axis = CategoryScaffoldAxis::query()
            ->where('category_scaffold_id', $root->id)
            ->find($axisId);

        if (! $axis) {
            throw ValidationException::withMessages([
                'category_scaffold_axis_id' => 'Select a valid axis from this scaffold root.',
            ]);
        }

        return $axis;
    }

    private function wouldCreateCycle(CategoryScaffoldNode $node, CategoryScaffoldNode $parent): bool
    {
        $cursor = $parent;
        $visited = [];

        while ($cursor) {
            if (in_array($cursor->id, $visited, true)) {
                break;
            }

            if ($cursor->id === $node->id) {
                return true;
            }

            $visited[] = $cursor->id;
            $cursor = $cursor->parent;
        }

        return false;
    }

    /**
     * @return array<int, int>
     */
    private function descendantIdsForNode(CategoryScaffoldNode $node): array
    {
        $nodes = CategoryScaffoldNode::query()
            ->where('category_scaffold_id', $node->category_scaffold_id)
            ->get(['id', 'parent_id']);

        $childrenByParent = $nodes->groupBy(fn (CategoryScaffoldNode $item) => $item->parent_id ?? 0);
        $descendants = [];
        $stack = $childrenByParent->get($node->id, collect())->pluck('id')->all();

        while ($stack !== []) {
            $currentId = array_pop($stack);

            if ($currentId === null || in_array($currentId, $descendants, true)) {
                continue;
            }

            $descendants[] = $currentId;

            foreach ($childrenByParent->get($currentId, collect())->pluck('id')->all() as $childId) {
                if (! in_array($childId, $descendants, true)) {
                    $stack[] = $childId;
                }
            }
        }

        return $descendants;
    }
}
