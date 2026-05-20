<?php

namespace App\Http\Controllers;

use App\Models\CatalogueFamily;
use App\Models\CatalogueType;
use App\Models\CatalogueVariant;
use App\Models\ReviewAction;
use App\Support\CatalogueOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogueVariantController extends Controller
{
    public function store(Request $request, CatalogueFamily $family): RedirectResponse
    {
        $validated = $this->validateVariant($request, $family);

        $variant = $family->variants()->create($this->extractVariantFields($validated) + [
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $this->syncVariantMatch($request, $variant);

        ReviewAction::query()->create([
            'reviewable_type' => $variant->getMorphClass(),
            'reviewable_id' => $variant->id,
            'action' => 'edit',
            'to_status' => $variant->status,
            'notes' => 'Variant created from family review page.',
            'acted_by' => $request->user()?->id,
        ]);

        return back()->with('status', 'Variant added.');
    }

    public function update(Request $request, CatalogueVariant $variant): RedirectResponse
    {
        $validated = $this->validateVariant($request, $variant->family);
        $oldStatus = $variant->status;

        $variant->update($this->extractVariantFields($validated) + [
            'updated_by' => $request->user()?->id,
            'reviewed_by' => $request->user()?->id,
        ]);

        $this->syncVariantMatch($request, $variant);

        ReviewAction::query()->create([
            'reviewable_type' => $variant->getMorphClass(),
            'reviewable_id' => $variant->id,
            'action' => 'edit',
            'from_status' => $oldStatus,
            'to_status' => $variant->status,
            'notes' => 'Variant updated from family review page.',
            'acted_by' => $request->user()?->id,
        ]);

        return back()->with('status', 'Variant updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateVariant(Request $request, CatalogueFamily $family): array
    {
        $validated = $request->validate([
            'catalogue_type_id' => ['nullable', 'exists:catalogue_types,id'],
            'variant_display_name' => ['required', 'string', 'max:255'],
            'color_code' => ['nullable', 'string', 'max:255'],
            'color_name' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', 'string', 'max:255'],
            'length' => ['nullable', 'string', 'max:255'],
            'bundle_count' => ['nullable', 'integer'],
            'pack_size' => ['nullable', 'string', 'max:255'],
            'texture' => ['nullable', 'string', 'max:255'],
            'shade' => ['nullable', 'string', 'max:255'],
            'finish' => ['nullable', 'string', 'max:255'],
            'style' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'string', 'max:255'],
            'volume' => ['nullable', 'string', 'max:255'],
            'attributes_json' => ['nullable', 'string'],
            'status' => ['required', Rule::in(CatalogueOptions::variantStatuses())],
            'notes' => ['nullable', 'string'],
            'shop_match_status' => ['nullable', Rule::in(CatalogueOptions::shopMatchStatuses())],
            'shop_match_confidence' => ['nullable', 'numeric'],
            'confirmation_method' => ['nullable', Rule::in(CatalogueOptions::confirmationMethods())],
            'confirmed_by' => ['nullable', 'exists:users,id'],
            'confirmed_at' => ['nullable', 'date'],
            'shop_match_notes' => ['nullable', 'string'],
        ]);

        if (filled($validated['catalogue_type_id'] ?? null)) {
            $type = CatalogueType::query()->findOrFail($validated['catalogue_type_id']);

            if ($type->catalogue_family_id !== $family->id) {
                throw ValidationException::withMessages([
                    'catalogue_type_id' => 'Selected type does not belong to this family.',
                ]);
            }
        }

        $attributes = [];

        if (filled($validated['attributes_json'] ?? null)) {
            $attributes = json_decode($validated['attributes_json'], true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($attributes)) {
                throw ValidationException::withMessages([
                    'attributes_json' => 'attributes_json must be a valid JSON object.',
                ]);
            }
        }

        $validated['attributes_json'] = $attributes;

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function extractVariantFields(array $validated): array
    {
        return collect($validated)->only([
            'catalogue_type_id',
            'variant_display_name',
            'color_code',
            'color_name',
            'size',
            'length',
            'bundle_count',
            'pack_size',
            'texture',
            'shade',
            'finish',
            'style',
            'weight',
            'volume',
            'attributes_json',
            'status',
            'notes',
        ])->all();
    }

    private function syncVariantMatch(Request $request, CatalogueVariant $variant): void
    {
        if ($request->filled('shop_match_status')) {
            app(\App\Services\ShopMatchService::class)->sync($variant, [
                'shop_match_status' => $request->input('shop_match_status'),
                'confidence' => $request->input('shop_match_confidence'),
                'confirmation_method' => $request->input('confirmation_method'),
                'confirmed_by' => $request->input('confirmed_by'),
                'confirmed_at' => $request->input('confirmed_at'),
                'notes' => $request->input('shop_match_notes'),
            ], $request->user());
        }
    }

    public function duplicate(Request $request, CatalogueVariant $variant): RedirectResponse
    {
        $duplicate = $variant->replicate([
            'approved_by',
            'approved_at',
            'reviewed_by',
            'merged_into_variant_id',
            'archived_at',
            'created_at',
            'updated_at',
        ]);

        $duplicate->variant_display_name = $variant->variant_display_name.' Copy';
        $duplicate->status = 'draft';
        $duplicate->notes = trim(($variant->notes ? $variant->notes."\n" : '').'Duplicated from variant #'.$variant->id);
        $duplicate->created_by = $request->user()?->id;
        $duplicate->updated_by = $request->user()?->id;
        $duplicate->save();

        ReviewAction::query()->create([
            'reviewable_type' => $duplicate->getMorphClass(),
            'reviewable_id' => $duplicate->id,
            'action' => 'edit',
            'to_status' => $duplicate->status,
            'notes' => 'Variant duplicated from variant #'.$variant->id,
            'acted_by' => $request->user()?->id,
        ]);

        return back()->with('status', 'Variant duplicated. Update the copy to create the new sellable child item.');
    }

    public function archive(Request $request, CatalogueVariant $variant): RedirectResponse
    {
        $oldStatus = $variant->status;

        $variant->update([
            'status' => 'archived',
            'archived_at' => now(),
            'updated_by' => $request->user()?->id,
        ]);

        ReviewAction::query()->create([
            'reviewable_type' => $variant->getMorphClass(),
            'reviewable_id' => $variant->id,
            'action' => 'edit',
            'from_status' => $oldStatus,
            'to_status' => 'archived',
            'notes' => 'Variant archived from family review page.',
            'acted_by' => $request->user()?->id,
        ]);

        return back()->with('status', 'Variant archived.');
    }
}
