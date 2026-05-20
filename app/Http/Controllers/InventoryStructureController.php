<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\InventorySection;
use App\Models\InventorySubsection;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InventoryStructureController extends Controller
{
    public function index(): View
    {
        $stores = InventoryLocation::query()
            ->where('location_type', 'shop')
            ->with(['sections' => fn ($query) => $query
                ->with(['subsections' => fn ($subQuery) => $subQuery->withCount('inventoryLevels')])
                ->withCount('inventoryLevels')])
            ->withCount('inventoryLevels')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('inventory-structure.index', [
            'stores' => $stores,
            'stats' => [
                'stores' => $stores->count(),
                'sections' => $stores->sum(fn (InventoryLocation $store): int => $store->sections->count()),
                'subsections' => $stores->flatMap->sections->sum(fn (InventorySection $section): int => $section->subsections->count()),
                'activeStores' => $stores->where('is_active', true)->count(),
                'activeSections' => $stores->flatMap->sections->where('is_active', true)->count(),
                'activeSubsections' => $stores->flatMap->sections->flatMap->subsections->where('is_active', true)->count(),
            ],
        ]);
    }

    public function storeLocation(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('inventory_locations', 'name')],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ((bool) ($data['is_default'] ?? false)) {
            InventoryLocation::query()->where('location_type', 'shop')->update(['is_default' => false]);
        }

        InventoryLocation::query()->create([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug(InventoryLocation::query(), $data['name']),
            'location_type' => 'shop',
            'is_default' => (bool) ($data['is_default'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return $this->actionResponse($request, 'Store created.');
    }

    public function updateLocation(Request $request, InventoryLocation $location): RedirectResponse|JsonResponse
    {
        abort_unless($location->location_type === 'shop', 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('inventory_locations', 'name')->ignore($location->id)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ((bool) ($data['is_default'] ?? false)) {
            InventoryLocation::query()
                ->where('location_type', 'shop')
                ->whereKeyNot($location->id)
                ->update(['is_default' => false]);
        }

        $location->fill([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug(InventoryLocation::query()->whereKeyNot($location->id), $data['name']),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ])->save();

        return $this->actionResponse($request, 'Store updated.');
    }

    public function destroyLocation(Request $request, InventoryLocation $location): RedirectResponse|JsonResponse
    {
        abort_unless($location->location_type === 'shop', 404);

        if ($location->inventoryLevels()->exists()) {
            return $this->actionResponse($request, 'Store cannot be deleted because products already use it.', false, 409);
        }

        $location->delete();

        return $this->actionResponse($request, 'Store deleted.');
    }

    public function storeSection(Request $request, InventoryLocation $location): RedirectResponse|JsonResponse
    {
        abort_unless($location->location_type === 'shop', 404);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('inventory_sections', 'name')->where('inventory_location_id', $location->id),
            ],
            'note' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $location->sections()->create([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug(InventorySection::query()->where('inventory_location_id', $location->id), $data['name']),
            'note' => $this->nullTrim($data['note'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return $this->actionResponse($request, 'Section created.');
    }

    public function updateSection(Request $request, InventorySection $section): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('inventory_sections', 'name')
                    ->where('inventory_location_id', $section->inventory_location_id)
                    ->ignore($section->id),
            ],
            'note' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $section->fill([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug(
                InventorySection::query()
                    ->where('inventory_location_id', $section->inventory_location_id)
                    ->whereKeyNot($section->id),
                $data['name'],
            ),
            'note' => $this->nullTrim($data['note'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ])->save();

        return $this->actionResponse($request, 'Section updated.');
    }

    public function destroySection(Request $request, InventorySection $section): RedirectResponse|JsonResponse
    {
        if ($section->inventoryLevels()->exists()) {
            return $this->actionResponse($request, 'Section cannot be deleted because products already use it.', false, 409);
        }

        $section->delete();

        return $this->actionResponse($request, 'Section deleted.');
    }

    public function storeSubsection(Request $request, InventorySection $section): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('inventory_subsections', 'name')->where('inventory_section_id', $section->id),
            ],
            'note' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $section->subsections()->create([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug(InventorySubsection::query()->where('inventory_section_id', $section->id), $data['name']),
            'note' => $this->nullTrim($data['note'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return $this->actionResponse($request, 'Subsection created.');
    }

    public function updateSubsection(Request $request, InventorySubsection $subsection): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('inventory_subsections', 'name')
                    ->where('inventory_section_id', $subsection->inventory_section_id)
                    ->ignore($subsection->id),
            ],
            'note' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $subsection->fill([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug(
                InventorySubsection::query()
                    ->where('inventory_section_id', $subsection->inventory_section_id)
                    ->whereKeyNot($subsection->id),
                $data['name'],
            ),
            'note' => $this->nullTrim($data['note'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ])->save();

        return $this->actionResponse($request, 'Subsection updated.');
    }

    public function destroySubsection(Request $request, InventorySubsection $subsection): RedirectResponse|JsonResponse
    {
        if ($subsection->inventoryLevels()->exists()) {
            return $this->actionResponse($request, 'Subsection cannot be deleted because products already use it.', false, 409);
        }

        $subsection->delete();

        return $this->actionResponse($request, 'Subsection deleted.');
    }

    private function actionResponse(Request $request, string $message, bool $ok = true, int $status = 200): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => $ok,
                'message' => $message,
            ], $status);
        }

        return back()->with($ok ? 'status' : 'warning', $message);
    }

    private function uniqueSlug($query, string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $i = 2;

        while ((clone $query)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function nullTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
