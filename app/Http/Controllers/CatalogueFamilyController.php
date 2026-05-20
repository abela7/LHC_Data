<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\CatalogueFamily;
use App\Models\DuplicateCandidate;
use App\Models\MergeEvent;
use App\Models\ReviewAction;
use App\Models\Subcategory;
use App\Models\User;
use App\Support\CatalogueOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CatalogueFamilyController extends Controller
{
    public function show(CatalogueFamily $family): View
    {
        $family->load([
            'brand',
            'category',
            'subcategory',
            'types.shopMatch',
            'variants.type',
            'variants.shopMatch',
            'sources',
            'images',
            'shopMatch',
            'importRecords.batch',
            'importRecords.images',
            'importRecords.links.linkable',
            'reviewActions.actedBy',
        ]);

        return view('families.show', [
            'family' => $family,
            'brands' => Brand::query()->orderBy('name')->get(),
            'categories' => Category::query()->orderBy('sort_order')->orderBy('name')->get(),
            'subcategories' => Subcategory::query()->orderBy('name')->get(),
            'users' => User::query()->orderBy('name')->get(),
            'mergeTargets' => CatalogueFamily::query()
                ->whereKeyNot($family->id)
                ->orderBy('product_family_name')
                ->limit(100)
                ->get(),
            'familyStatuses' => CatalogueOptions::familyStatuses(),
            'typeStatuses' => CatalogueOptions::typeStatuses(),
            'variantStatuses' => CatalogueOptions::variantStatuses(),
            'shopMatchStatuses' => CatalogueOptions::shopMatchStatuses(),
            'confirmationMethods' => CatalogueOptions::confirmationMethods(),
            'sourceTypes' => CatalogueOptions::sourceTypes(),
            'sourceRoles' => CatalogueOptions::sourceRoles(),
            'sourceTrustStatuses' => CatalogueOptions::sourceTrustStatuses(),
            'duplicateCandidates' => DuplicateCandidate::query()
                ->with(['leftFamily.brand', 'rightFamily.brand'])
                ->where('status', 'open')
                ->where(function ($query) use ($family) {
                    $query->where('left_family_id', $family->id)
                        ->orWhere('right_family_id', $family->id);
                })
                ->latest()
                ->get(),
        ]);
    }

    public function update(Request $request, CatalogueFamily $family): RedirectResponse
    {
        $validated = $request->validate([
            'brand_id' => ['nullable', 'exists:brands,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'exists:subcategories,id'],
            'product_family_name' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string'],
            'full_description' => ['nullable', 'string'],
            'source_confidence' => ['nullable', 'numeric'],
            'status' => ['required', Rule::in(CatalogueOptions::familyStatuses())],
            'needs_source_verification' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'shop_match_status' => ['nullable', Rule::in(CatalogueOptions::shopMatchStatuses())],
            'shop_match_confidence' => ['nullable', 'numeric'],
            'confirmation_method' => ['nullable', Rule::in(CatalogueOptions::confirmationMethods())],
            'confirmed_by' => ['nullable', 'exists:users,id'],
            'confirmed_at' => ['nullable', 'date'],
            'shop_match_notes' => ['nullable', 'string'],
        ]);

        $oldStatus = $family->status;

        $family->update([
            'brand_id' => $validated['brand_id'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'subcategory_id' => $validated['subcategory_id'] ?? null,
            'product_family_name' => $validated['product_family_name'],
            'slug' => $this->makeUniqueSlug($family, $validated['product_family_name'], $validated['brand_id'] ?? null),
            'short_description' => $validated['short_description'] ?? null,
            'full_description' => $validated['full_description'] ?? null,
            'source_confidence' => $validated['source_confidence'] ?? null,
            'status' => $validated['status'],
            'needs_source_verification' => (bool) ($validated['needs_source_verification'] ?? false),
            'notes' => $validated['notes'] ?? null,
            'updated_by' => $request->user()?->id,
            'reviewed_by' => $request->user()?->id,
        ]);

        if ($request->filled('shop_match_status')) {
            app(\App\Services\ShopMatchService::class)->sync($family, [
                'shop_match_status' => $validated['shop_match_status'],
                'confidence' => $validated['shop_match_confidence'] ?? null,
                'confirmation_method' => $validated['confirmation_method'] ?? null,
                'confirmed_by' => $validated['confirmed_by'] ?? null,
                'confirmed_at' => $validated['confirmed_at'] ?? null,
                'notes' => $validated['shop_match_notes'] ?? null,
            ], $request->user());
        }

        ReviewAction::query()->create([
            'reviewable_type' => $family->getMorphClass(),
            'reviewable_id' => $family->id,
            'action' => 'edit',
            'from_status' => $oldStatus,
            'to_status' => $family->status,
            'notes' => 'Family details updated from review form.',
            'acted_by' => $request->user()?->id,
        ]);

        return back()->with('status', 'Family record updated.');
    }

    public function approve(Request $request, CatalogueFamily $family): RedirectResponse
    {
        DB::transaction(function () use ($request, $family) {
            $oldStatus = $family->status;

            $family->update([
                'status' => 'approved',
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
                'reviewed_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);

            $family->types()
                ->whereNotIn('status', ['rejected', 'archived'])
                ->update([
                    'status' => 'approved',
                    'approved_by' => $request->user()?->id,
                    'approved_at' => now(),
                    'reviewed_by' => $request->user()?->id,
                    'updated_by' => $request->user()?->id,
                ]);

            $family->variants()
                ->whereNotIn('status', ['rejected', 'archived'])
                ->update([
                    'status' => 'approved',
                    'approved_by' => $request->user()?->id,
                    'approved_at' => now(),
                    'reviewed_by' => $request->user()?->id,
                    'updated_by' => $request->user()?->id,
                ]);

            ReviewAction::query()->create([
                'reviewable_type' => $family->getMorphClass(),
                'reviewable_id' => $family->id,
                'action' => 'approve',
                'from_status' => $oldStatus,
                'to_status' => 'approved',
                'notes' => $request->input('review_note'),
                'acted_by' => $request->user()?->id,
            ]);
        });

        return back()->with('status', 'Family and active child records approved.');
    }

    public function reject(Request $request, CatalogueFamily $family): RedirectResponse
    {
        $oldStatus = $family->status;

        $family->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        ReviewAction::query()->create([
            'reviewable_type' => $family->getMorphClass(),
            'reviewable_id' => $family->id,
            'action' => 'reject',
            'from_status' => $oldStatus,
            'to_status' => 'rejected',
            'notes' => $request->input('review_note'),
            'acted_by' => $request->user()?->id,
        ]);

        return back()->with('status', 'Family marked as rejected.');
    }

    public function merge(Request $request, CatalogueFamily $family): RedirectResponse
    {
        $validated = $request->validate([
            'target_family_id' => ['required', 'exists:catalogue_families,id'],
            'merge_note' => ['nullable', 'string'],
        ]);

        /** @var CatalogueFamily $target */
        $target = CatalogueFamily::query()->findOrFail($validated['target_family_id']);

        if ($target->id === $family->id) {
            return back()->withErrors(['target_family_id' => 'You must choose a different family as the merge target.']);
        }

        DB::transaction(function () use ($request, $family, $target, $validated) {
            $family->types()->update([
                'catalogue_family_id' => $target->id,
                'updated_by' => $request->user()?->id,
            ]);

            $family->variants()->update([
                'catalogue_family_id' => $target->id,
                'updated_by' => $request->user()?->id,
            ]);

            $family->sources()->update([
                'sourceable_id' => $target->id,
                'updated_at' => now(),
            ]);

            $family->images()->update([
                'imageable_id' => $target->id,
                'updated_at' => now(),
            ]);

            $family->importRecords()->update([
                'target_family_id' => $target->id,
            ]);

            foreach ($family->importRecords as $importRecord) {
                ImportRecordLink::query()->firstOrCreate([
                    'import_record_id' => $importRecord->id,
                    'linkable_type' => $target->getMorphClass(),
                    'linkable_id' => $target->id,
                ], [
                    'relation_role' => 'family',
                ]);
            }

            MergeEvent::query()->create([
                'mergeable_type' => $family->getMorphClass(),
                'source_id' => $family->id,
                'target_id' => $target->id,
                'notes' => $validated['merge_note'] ?? null,
                'merged_by' => $request->user()?->id,
                'merged_at' => now(),
            ]);

            ReviewAction::query()->create([
                'reviewable_type' => $family->getMorphClass(),
                'reviewable_id' => $family->id,
                'action' => 'merge',
                'from_status' => $family->status,
                'to_status' => 'archived',
                'notes' => $validated['merge_note'] ?? null,
                'metadata' => ['target_family_id' => $target->id],
                'acted_by' => $request->user()?->id,
            ]);

            $family->update([
                'status' => 'archived',
                'merged_into_family_id' => $target->id,
                'archived_at' => now(),
                'updated_by' => $request->user()?->id,
            ]);
        });

        return redirect()->route('families.show', $target)->with('status', 'Family merged into the selected target.');
    }

    private function makeUniqueSlug(CatalogueFamily $family, string $name, ?int $brandId): string
    {
        $base = Str::slug($name) ?: 'family';
        $slug = $base;
        $suffix = 2;

        while (CatalogueFamily::query()
            ->whereKeyNot($family->id)
            ->where('brand_id', $brandId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
