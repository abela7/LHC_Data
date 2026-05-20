<?php

namespace App\Http\Controllers;

use App\Models\CatalogueFamily;
use App\Models\CatalogueType;
use App\Models\ReviewAction;
use App\Support\CatalogueOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CatalogueTypeController extends Controller
{
    public function store(Request $request, CatalogueFamily $family): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', Rule::in(CatalogueOptions::typeStatuses())],
            'sort_order' => ['nullable', 'integer'],
            'shop_match_status' => ['nullable', Rule::in(CatalogueOptions::shopMatchStatuses())],
            'shop_match_confidence' => ['nullable', 'numeric'],
            'confirmation_method' => ['nullable', Rule::in(CatalogueOptions::confirmationMethods())],
            'confirmed_by' => ['nullable', 'exists:users,id'],
            'confirmed_at' => ['nullable', 'date'],
            'shop_match_notes' => ['nullable', 'string'],
        ]);

        $type = $family->types()->create([
            'name' => $validated['name'],
            'slug' => $this->makeUniqueSlug($family, $validated['name']),
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        if ($request->filled('shop_match_status')) {
            app(\App\Services\ShopMatchService::class)->sync($type, [
                'shop_match_status' => $validated['shop_match_status'],
                'confidence' => $validated['shop_match_confidence'] ?? null,
                'confirmation_method' => $validated['confirmation_method'] ?? null,
                'confirmed_by' => $validated['confirmed_by'] ?? null,
                'confirmed_at' => $validated['confirmed_at'] ?? null,
                'notes' => $validated['shop_match_notes'] ?? null,
            ], $request->user());
        }

        ReviewAction::query()->create([
            'reviewable_type' => $type->getMorphClass(),
            'reviewable_id' => $type->id,
            'action' => 'edit',
            'to_status' => $type->status,
            'notes' => 'Type created from family review page.',
            'acted_by' => $request->user()?->id,
        ]);

        return back()->with('status', 'Type added.');
    }

    public function update(Request $request, CatalogueType $type): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', Rule::in(CatalogueOptions::typeStatuses())],
            'sort_order' => ['nullable', 'integer'],
            'shop_match_status' => ['nullable', Rule::in(CatalogueOptions::shopMatchStatuses())],
            'shop_match_confidence' => ['nullable', 'numeric'],
            'confirmation_method' => ['nullable', Rule::in(CatalogueOptions::confirmationMethods())],
            'confirmed_by' => ['nullable', 'exists:users,id'],
            'confirmed_at' => ['nullable', 'date'],
            'shop_match_notes' => ['nullable', 'string'],
        ]);

        $oldStatus = $type->status;

        $type->update([
            'name' => $validated['name'],
            'slug' => $this->makeUniqueSlug($type->family, $validated['name'], $type),
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'updated_by' => $request->user()?->id,
            'reviewed_by' => $request->user()?->id,
        ]);

        if ($request->filled('shop_match_status')) {
            app(\App\Services\ShopMatchService::class)->sync($type, [
                'shop_match_status' => $validated['shop_match_status'],
                'confidence' => $validated['shop_match_confidence'] ?? null,
                'confirmation_method' => $validated['confirmation_method'] ?? null,
                'confirmed_by' => $validated['confirmed_by'] ?? null,
                'confirmed_at' => $validated['confirmed_at'] ?? null,
                'notes' => $validated['shop_match_notes'] ?? null,
            ], $request->user());
        }

        ReviewAction::query()->create([
            'reviewable_type' => $type->getMorphClass(),
            'reviewable_id' => $type->id,
            'action' => 'edit',
            'from_status' => $oldStatus,
            'to_status' => $type->status,
            'notes' => 'Type updated from family review page.',
            'acted_by' => $request->user()?->id,
        ]);

        return back()->with('status', 'Type updated.');
    }

    public function archive(Request $request, CatalogueType $type): RedirectResponse
    {
        $oldStatus = $type->status;

        $type->variants()->update([
            'catalogue_type_id' => null,
            'updated_by' => $request->user()?->id,
        ]);

        $type->update([
            'status' => 'archived',
            'archived_at' => now(),
            'updated_by' => $request->user()?->id,
        ]);

        ReviewAction::query()->create([
            'reviewable_type' => $type->getMorphClass(),
            'reviewable_id' => $type->id,
            'action' => 'edit',
            'from_status' => $oldStatus,
            'to_status' => 'archived',
            'notes' => 'Type archived from family review page.',
            'acted_by' => $request->user()?->id,
        ]);

        return back()->with('status', 'Type archived and child variants moved back to the family level.');
    }

    private function makeUniqueSlug(CatalogueFamily $family, string $name, ?CatalogueType $ignore = null): string
    {
        $base = Str::slug($name) ?: 'type';
        $slug = $base;
        $suffix = 2;

        while (CatalogueType::query()
            ->where('catalogue_family_id', $family->id)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
