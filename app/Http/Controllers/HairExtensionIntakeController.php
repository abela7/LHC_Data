<?php

namespace App\Http\Controllers;

use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueStyle;
use App\Models\HairExtensionIntake;
use App\Models\HairExtensionIntakeAiSuggestion;
use App\Models\HairExtensionIntakePhoto;
use App\Models\InventoryLocation;
use App\Models\InventorySection;
use App\Models\InventorySubsection;
use App\Services\GeminiProductLookupService;
use App\Services\ImageWatermarker;
use App\Services\OpenRouterProductLookupService;
use App\Services\OpenRouterPackagingTextService;
use App\Support\ProductImageNamer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class HairExtensionIntakeController extends Controller
{
    private const MAX_INTAKE_PHOTO_KB = 35840;

    public function index(): View
    {
        $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->first();

        $brands = BrandCatalogueBrand::query()
            ->with(['productTypes.styles.variants.options'])
            ->when($catalogue, fn ($query) => $query->where('brand_catalogue_id', $catalogue->id))
            ->orderBy('name')
            ->get();

        $brandData = $brands->map(fn (BrandCatalogueBrand $brand) => [
            'id' => $brand->id,
            'name' => $brand->name,
            'product_types' => $brand->productTypes->map(fn (BrandCatalogueProductType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'styles' => $type->styles->map(fn (BrandCatalogueStyle $style) => [
                    'id' => $style->id,
                    'name' => $style->name,
                    'variants' => $style->variants->map(fn ($variant) => [
                        'id' => $variant->id,
                        'name' => $variant->name,
                        'variant_type' => $variant->variant_type,
                        'options' => $variant->options->map(fn ($option) => [
                            'id' => $option->id,
                            'label' => $option->label,
                            'value' => $option->value,
                        ])->values(),
                    ])->values(),
                ])->values(),
            ])->values(),
        ])->values();

        $recentIntakes = HairExtensionIntake::query()
            ->with(['brand', 'productType', 'style', 'photos', 'aiSuggestions', 'store', 'section', 'subsection'])
            ->where(function ($query): void {
                $query
                    ->whereNotNull('brand_catalogue_brand_id')
                    ->orWhere(fn ($inner) => $inner
                        ->whereNotNull('brand_name')
                        ->where('brand_name', '!=', '')
                    );
            })
            ->latest()
            ->limit(20)
            ->get();

        $recentIntakeData = $recentIntakes->map(fn (HairExtensionIntake $intake) => $this->draftPayload($intake))->values();

        return view('hair-extension-intake.index', [
            'brands' => $brands,
            'brandData' => $brandData,
            'recentIntakes' => $recentIntakes,
            'recentIntakeData' => $recentIntakeData,
            'autosaveUrl' => route('hair-extension-intake.autosave'),
            'phoneCaptureUrl' => route('mobile-capture.jobs.store'),
            'aiLookupUrl' => route('hair-extension-intake.ai-lookup'),
            'packagingTextUrl' => route('hair-extension-intake.packaging-text'),
            'aiModels' => app(OpenRouterProductLookupService::class)->models(),
            'aiDefaultModel' => config('services.openrouter.model', 'google/gemini-3-flash-preview'),
            'visionModels' => app(OpenRouterPackagingTextService::class)->models(),
            'visionDefaultModel' => config('services.openrouter.vision_model', 'openrouter/free'),
            'exportUrl' => route('hair-extension-intake.export-json'),
        ]);
    }

    public function v2(Request $request): View
    {
        $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->first();

        $brands = BrandCatalogueBrand::query()
            ->with(['productTypes.styles.variants.options'])
            ->when($catalogue, fn ($query) => $query->where('brand_catalogue_id', $catalogue->id))
            ->orderBy('name')
            ->get();

        $brandData = $brands->map(fn (BrandCatalogueBrand $brand) => [
            'id' => $brand->id,
            'name' => $brand->name,
            'product_types' => $brand->productTypes->map(fn (BrandCatalogueProductType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'styles' => $type->styles->map(fn (BrandCatalogueStyle $style) => [
                    'id' => $style->id,
                    'name' => $style->name,
                    'variants' => $style->variants->map(fn ($variant) => [
                        'id' => $variant->id,
                        'name' => $variant->name,
                        'variant_type' => $variant->variant_type,
                        'options' => $variant->options->map(fn ($option) => [
                            'id' => $option->id,
                            'label' => $option->label,
                            'value' => $option->value,
                        ])->values(),
                    ])->values(),
                ])->values(),
            ])->values(),
        ])->values();
        $storeData = $this->inventoryStorePayload();

        $editIntake = null;
        $duplicateIntake = null;
        if ($request->filled('edit_intake')) {
            $editIntake = HairExtensionIntake::query()
                ->with(['photos', 'brand', 'productType', 'style', 'store', 'section', 'subsection'])
                ->find($request->integer('edit_intake'));
        } elseif ($request->filled('duplicate_intake')) {
            $duplicateIntake = HairExtensionIntake::query()
                ->with(['photos', 'brand', 'productType', 'style', 'store', 'section', 'subsection'])
                ->find($request->integer('duplicate_intake'));
        }

        return view('hair-extension-intake.v2', [
            'brands' => $brands,
            'brandData' => $brandData,
            'storeData' => $storeData,
            'editIntake' => $editIntake,
            'editPayload' => $editIntake ? $this->v2EditPayload($editIntake) : ($duplicateIntake ? $this->v2EditPayload($duplicateIntake, true) : null),
            'v1Url' => route('hair-extension-intake.index'),
            'submittedUrl' => route('hair-extension-intake.submitted'),
            'submitUrl' => $editIntake
                ? route('hair-extension-intake.v2.update', $editIntake)
                : route('hair-extension-intake.v2.submit'),
        ]);
    }

    public function submitFamily(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_catalogue_brand_id' => ['nullable', 'integer', 'exists:brand_catalogue_brands,id'],
            'brand_catalogue_product_type_id' => ['nullable', 'integer', 'exists:brand_catalogue_product_types,id'],
            'brand_catalogue_style_id' => ['nullable', 'integer', 'exists:brand_catalogue_styles,id'],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'product_type_name' => ['nullable', 'string', 'max:255'],
            'catalogue_style_status' => ['nullable', 'in:known,not_known,not_sure'],
            'product_type_status' => ['nullable', 'in:known,not_known,not_sure'],
            'style_family_status' => ['nullable', 'in:known,not_known,not_sure'],
            'classification_path' => ['nullable', 'string', 'max:5000'],
            'shelf_location' => ['nullable', 'string', 'max:255'],
            'store_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'section_id' => ['nullable', 'integer', 'exists:inventory_sections,id'],
            'subsection_id' => ['nullable', 'integer', 'exists:inventory_subsections,id'],
            'style_name' => ['nullable', 'string', 'max:255'],
            'variant_main_axis' => ['nullable', 'string', 'max:80'],
            'variant_sub_axis' => ['nullable', 'string', 'max:80'],
            'variant_rows' => ['required', 'string'],
            'common_variant_rows' => ['nullable', 'string'],
            'visible_text_notes' => ['nullable', 'string', 'max:10000'],
            'cover_photo' => ['nullable', 'image', 'max:'.self::MAX_INTAKE_PHOTO_KB],
        ]);

        $brand = ! empty($data['brand_catalogue_brand_id'])
            ? BrandCatalogueBrand::query()->find((int) $data['brand_catalogue_brand_id'])
            : null;
        $productType = ! empty($data['brand_catalogue_product_type_id'])
            ? BrandCatalogueProductType::query()->find((int) $data['brand_catalogue_product_type_id'])
            : null;
        $style = ! empty($data['brand_catalogue_style_id'])
            ? BrandCatalogueStyle::query()->find((int) $data['brand_catalogue_style_id'])
            : null;
        $brandName = $this->nullTrim($data['brand_name'] ?? null) ?: $brand?->name;

        if (! $brandName) {
            throw ValidationException::withMessages([
                'brand_name' => 'Choose a brand or enter the brand name.',
            ]);
        }

        $catalogueStyleStatus = $this->fieldKnowledgeStatus($data['catalogue_style_status'] ?? null);
        $productTypeStatus = $this->fieldKnowledgeStatus($data['product_type_status'] ?? null);
        $styleFamilyStatus = $this->fieldKnowledgeStatus($data['style_family_status'] ?? null);
        if ($catalogueStyleStatus === 'not_known') {
            $style = null;
        }
        if ($productTypeStatus === 'not_known') {
            $productType = null;
        }
        $productTypeName = $this->nullTrim($data['product_type_name'] ?? null);
        if ($productTypeName === null && $productTypeStatus === 'known') {
            $productTypeName = $productType?->name;
        }
        $styleName = $this->cleanVariantText($data['style_name'] ?? '');
        if ($styleName === '' && $styleFamilyStatus === 'known') {
            $styleName = $style?->name ?: '';
        }
        $mainAxis = $this->cleanVariantText($data['variant_main_axis'] ?? '') ?: 'Length';
        $subAxis = $this->cleanVariantText($data['variant_sub_axis'] ?? '') ?: 'Colour';
        $variantRows = $this->decodeFamilyVariantRows((string) ($data['variant_rows'] ?? ''), $subAxis);
        $commonVariants = $this->decodeFamilyCommonVariants((string) ($data['common_variant_rows'] ?? ''));
        $classificationPath = $this->decodeClassificationPath($data['classification_path'] ?? null);
        $location = $this->resolveIntakeLocation($data);

        $variantStructure = $this->familyVariantStructure($mainAxis, $variantRows, $commonVariants);
        $variantGroups = $this->familyVariantGroups($mainAxis, $subAxis, $variantRows, $commonVariants);

        $intake = HairExtensionIntake::query()->create([
            'brand_catalogue_brand_id' => $brand?->id,
            'brand_catalogue_product_type_id' => $productType?->id,
            'brand_catalogue_style_id' => $style?->id,
            'brand_name' => $brandName,
            'observed_product_name' => $styleName ?: null,
            'product_type_name' => $productTypeName,
            'catalogue_style_status' => $catalogueStyleStatus,
            'product_type_status' => $productTypeStatus,
            'style_family_status' => $styleFamilyStatus,
            'classification_path' => $classificationPath,
            'shelf_location' => $this->nullTrim($data['shelf_location'] ?? null) ?: $location['label'],
            'store_id' => $location['store_id'],
            'section_id' => $location['section_id'],
            'subsection_id' => $location['subsection_id'],
            'product_type_unknown' => $productTypeStatus === 'not_known' || $productTypeName === null,
            'style_name' => $styleName ?: null,
            'style_unknown' => $styleFamilyStatus === 'not_known' || $styleName === '',
            'variant_groups' => $variantGroups,
            'variant_structure' => $variantStructure,
            'visible_text_notes' => $this->nullTrim($data['visible_text_notes'] ?? null),
            'status' => 'submitted',
            'ai_status' => 'ready_for_ai',
            'submitted_at' => now(),
            'last_synced_at' => now(),
        ]);

        if ($request->hasFile('cover_photo') && !$request->boolean('remove_photo')) {
            $this->storeIntakePhoto(
                $intake,
                $request->file('cover_photo'),
                'main',
                'Hair intake V2',
                'Optional cover photo from text intake',
                true,
            );
        }

        return redirect()
            ->route('hair-extension-intake.v2')
            ->with('status', 'Text intake saved with '.number_format((int) data_get($variantStructure, 'summary.sellable_combination_count', 0)).' mapped sellable variant'.(((int) data_get($variantStructure, 'summary.sellable_combination_count', 0)) === 1 ? '' : 's').'.')
            ->with('saved_intake_id', $intake->id)
            ->with('clear_draft', true);
    }

    public function updateFamily(Request $request, HairExtensionIntake $intake): RedirectResponse
    {
        $data = $request->validate($this->v2FamilyRules(requirePhoto: false));
        [$updates, $variantStructure] = $this->v2FamilyUpdates($data);

        $updates['status'] = 'submitted';
        $updates['ai_status'] = 'ready_for_ai';
        $updates['submitted_at'] = $intake->submitted_at ?: now();
        $updates['last_synced_at'] = now();

        if ($request->boolean('remove_photo')) {
            $this->deleteLegacyPhoto($intake);
            $updates['photo_disk'] = null;
            $updates['photo_path'] = null;
            $updates['photo_original_filename'] = null;
        }

        $intake->update($updates);

        if ($request->hasFile('cover_photo') && !$request->boolean('remove_photo')) {
            $this->storeIntakePhoto(
                $intake,
                $request->file('cover_photo'),
                'main',
                'Hair intake V2',
                'Optional cover photo from text intake edit',
                true,
            );
        }

        return redirect()
            ->route('hair-extension-intake.v2')
            ->with('status', 'Text intake updated with '.number_format((int) data_get($variantStructure, 'summary.sellable_combination_count', 0)).' mapped sellable variant'.(((int) data_get($variantStructure, 'summary.sellable_combination_count', 0)) === 1 ? '' : 's').'.')
            ->with('saved_intake_id', $intake->id)
            ->with('clear_draft', true);
    }

    /**
     * Copy a local image into intake evidence storage (e.g. shop batch folder).
     */
    public function attachIntakePhotoFromAbsolutePath(
        HairExtensionIntake $intake,
        string $absolutePath,
        string $originalFilename,
        string $sourceLabel = 'Shop photo batch',
    ): void {
        if (! is_file($absolutePath)) {
            return;
        }

        $extension = pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'jpg';
        $targetName = Str::slug(collect([$intake->brand_name, $intake->style_name, 'photo'])->filter()->implode(' '));
        $directory = 'hair-extension-intake/evidence/'.$intake->id.'-'.$targetName;
        $filename = $targetName.'.'.$extension;
        $path = $directory.'/'.$filename;
        $counter = 2;

        while (Storage::disk('public')->exists($path)) {
            $path = $directory.'/'.$targetName.'-'.$counter.'.'.$extension;
            $counter++;
        }

        $bytes = file_get_contents($absolutePath);
        Storage::disk('public')->put($path, $bytes !== false ? $bytes : '');
        app(ImageWatermarker::class)->applyToPublicStoragePath($path);

        $intake->photos()->update(['is_primary' => false]);

        $intake->photos()->create([
            'image_role' => 'main',
            'source_label' => $sourceLabel,
            'notes' => 'Imported from '.$originalFilename,
            'storage_disk' => 'public',
            'storage_path' => $path,
            'original_filename' => $originalFilename,
            'mime_type' => mime_content_type($absolutePath) ?: null,
            'file_size' => filesize($absolutePath) ?: null,
            'source_type' => 'shop_photo_batch',
            'is_primary' => true,
            'sort_order' => ((int) $intake->photos()->max('sort_order')) + 1,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function v2FamilyRules(bool $requirePhoto = false): array
    {
        return [
            'brand_catalogue_brand_id' => ['nullable', 'integer', 'exists:brand_catalogue_brands,id'],
            'brand_catalogue_product_type_id' => ['nullable', 'integer', 'exists:brand_catalogue_product_types,id'],
            'brand_catalogue_style_id' => ['nullable', 'integer', 'exists:brand_catalogue_styles,id'],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'product_type_name' => ['nullable', 'string', 'max:255'],
            'catalogue_style_status' => ['nullable', 'in:known,not_known,not_sure'],
            'product_type_status' => ['nullable', 'in:known,not_known,not_sure'],
            'style_family_status' => ['nullable', 'in:known,not_known,not_sure'],
            'classification_path' => ['nullable', 'string', 'max:5000'],
            'shelf_location' => ['nullable', 'string', 'max:255'],
            'store_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'section_id' => ['nullable', 'integer', 'exists:inventory_sections,id'],
            'subsection_id' => ['nullable', 'integer', 'exists:inventory_subsections,id'],
            'style_name' => ['nullable', 'string', 'max:255'],
            'variant_main_axis' => ['nullable', 'string', 'max:80'],
            'variant_sub_axis' => ['nullable', 'string', 'max:80'],
            'variant_rows' => ['required', 'string'],
            'common_variant_rows' => ['nullable', 'string'],
            'visible_text_notes' => ['nullable', 'string', 'max:10000'],
            'remove_photo' => ['nullable', 'boolean'],
            'cover_photo' => [$requirePhoto ? 'required' : 'nullable', 'image', 'max:'.self::MAX_INTAKE_PHOTO_KB],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0:array<string, mixed>,1:array<string, mixed>}
     */
    public function v2FamilyUpdates(array $data): array
    {
        $brand = ! empty($data['brand_catalogue_brand_id'])
            ? BrandCatalogueBrand::query()->find((int) $data['brand_catalogue_brand_id'])
            : null;
        $productType = ! empty($data['brand_catalogue_product_type_id'])
            ? BrandCatalogueProductType::query()->find((int) $data['brand_catalogue_product_type_id'])
            : null;
        $style = ! empty($data['brand_catalogue_style_id'])
            ? BrandCatalogueStyle::query()->find((int) $data['brand_catalogue_style_id'])
            : null;
        $brandName = $this->nullTrim($data['brand_name'] ?? null) ?: $brand?->name;

        if (! $brandName) {
            throw ValidationException::withMessages([
                'brand_name' => 'Choose a brand or enter the brand name.',
            ]);
        }

        $catalogueStyleStatus = $this->fieldKnowledgeStatus($data['catalogue_style_status'] ?? null);
        $productTypeStatus = $this->fieldKnowledgeStatus($data['product_type_status'] ?? null);
        $styleFamilyStatus = $this->fieldKnowledgeStatus($data['style_family_status'] ?? null);
        if ($catalogueStyleStatus === 'not_known') {
            $style = null;
        }
        if ($productTypeStatus === 'not_known') {
            $productType = null;
        }
        $productTypeName = $this->nullTrim($data['product_type_name'] ?? null);
        if ($productTypeName === null && $productTypeStatus === 'known') {
            $productTypeName = $productType?->name;
        }
        $styleName = $this->cleanVariantText($data['style_name'] ?? '');
        if ($styleName === '' && $styleFamilyStatus === 'known') {
            $styleName = $style?->name ?: '';
        }
        $mainAxis = $this->cleanVariantText($data['variant_main_axis'] ?? '') ?: 'Length';
        $subAxis = $this->cleanVariantText($data['variant_sub_axis'] ?? '') ?: 'Colour';
        $variantRows = $this->decodeFamilyVariantRows((string) ($data['variant_rows'] ?? ''), $subAxis);
        $commonVariants = $this->decodeFamilyCommonVariants((string) ($data['common_variant_rows'] ?? ''));
        $classificationPath = $this->decodeClassificationPath($data['classification_path'] ?? null);
        $location = $this->resolveIntakeLocation($data);

        $variantStructure = $this->familyVariantStructure($mainAxis, $variantRows, $commonVariants);
        $variantGroups = $this->familyVariantGroups($mainAxis, $subAxis, $variantRows, $commonVariants);

        return [[
            'brand_catalogue_brand_id' => $brand?->id,
            'brand_catalogue_product_type_id' => $productType?->id,
            'brand_catalogue_style_id' => $style?->id,
            'brand_name' => $brandName,
            'observed_product_name' => $styleName ?: null,
            'product_type_name' => $productTypeName,
            'catalogue_style_status' => $catalogueStyleStatus,
            'product_type_status' => $productTypeStatus,
            'style_family_status' => $styleFamilyStatus,
            'classification_path' => $classificationPath,
            'shelf_location' => $this->nullTrim($data['shelf_location'] ?? null) ?: $location['label'],
            'store_id' => $location['store_id'],
            'section_id' => $location['section_id'],
            'subsection_id' => $location['subsection_id'],
            'product_type_unknown' => $productTypeStatus === 'not_known' || $productTypeName === null,
            'style_name' => $styleName ?: null,
            'style_unknown' => $styleFamilyStatus === 'not_known' || $styleName === '',
            'variant_groups' => $variantGroups,
            'variant_structure' => $variantStructure,
            'visible_text_notes' => $this->nullTrim($data['visible_text_notes'] ?? null),
        ], $variantStructure];
    }

    public function packagingText(Request $request, OpenRouterPackagingTextService $vision): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'image', 'max:20480'],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'observed_product_name' => ['nullable', 'string', 'max:255'],
            'current_notes' => ['nullable', 'string', 'max:10000'],
            'ai_model' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $vision->extract($request->file('image'), [
                'brand_name' => $data['brand_name'] ?? null,
                'observed_product_name' => $data['observed_product_name'] ?? null,
                'current_notes' => $data['current_notes'] ?? null,
                'ai_model' => $data['ai_model'] ?? null,
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'Packaging text recognised.',
                'model' => $result['model'],
                'result' => $result['result'],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function aiLookup(
        Request $request,
        OpenRouterProductLookupService $openRouterLookup,
        GeminiProductLookupService $geminiLookup,
    ): JsonResponse
    {
        $data = $request->validate([
            'hair_extension_intake_id' => ['nullable', 'integer', 'exists:hair_extension_intakes,id'],
            'brand_name' => ['required', 'string', 'max:255'],
            'observed_product_name' => ['required', 'string', 'max:255'],
            'source_url' => ['nullable', 'string', 'max:2000'],
            'ai_model' => ['nullable', 'string', 'max:255'],
        ]);
        $provider = (string) config('services.ai_lookup.provider', 'openrouter');

        $record = HairExtensionIntakeAiSuggestion::query()->create([
            'hair_extension_intake_id' => $data['hair_extension_intake_id'] ?? null,
            'brand_name' => trim((string) $data['brand_name']),
            'observed_product_name' => trim((string) $data['observed_product_name']),
            'source_url' => $this->nullTrim($data['source_url'] ?? null),
            'provider' => $provider,
            'model' => $data['ai_model'] ?? null,
            'status' => 'running',
        ]);

        try {
            $lookupInput = [
                'brand_name' => $record->brand_name,
                'observed_product_name' => $record->observed_product_name,
                'source_url' => $record->source_url,
                'ai_model' => $data['ai_model'] ?? null,
            ];

            $result = $provider === 'gemini'
                ? $geminiLookup->suggest($lookupInput)
                : $openRouterLookup->suggest($lookupInput);

            $record->update([
                'model' => $result['model'],
                'status' => 'completed',
                'confidence' => $result['confidence'],
                'suggestion' => $result['suggestion'],
                'source_urls' => $result['source_urls'],
                'raw_response' => $result['raw_response'],
                'prompt_hash' => $result['prompt_hash'],
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'AI suggestion ready.',
                'suggestion_id' => $record->id,
                'confidence' => $record->confidence,
                'provider' => $record->provider,
                'model' => $record->model,
                'suggestion' => $record->suggestion,
                'source_urls' => $record->source_urls ?? [],
            ]);
        } catch (\Throwable $exception) {
            $record->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            report($exception);

            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
                'suggestion_id' => $record->id,
            ], 422);
        }
    }

    public function submitted(Request $request): View
    {
        $showDraftsOnly = $request->boolean('drafts_only') || $request->boolean('include_drafts');

        $query = HairExtensionIntake::query()
            ->with(['brand', 'productType', 'style', 'photos', 'aiSuggestions', 'store', 'section', 'subsection'])
            ->where(function ($query): void {
                $query
                    ->whereNotNull('brand_catalogue_brand_id')
                    ->orWhere(fn ($inner) => $inner
                        ->whereNotNull('brand_name')
                        ->where('brand_name', '!=', '')
                    );
            })
            ->when(
                $showDraftsOnly,
                fn ($query) => $query->where('status', 'draft'),
                fn ($query) => $query->where('status', 'submitted'),
            )
            ->latest('submitted_at')
            ->latest('last_synced_at');

        $intakes = $query->get();

        $submittedCount = HairExtensionIntake::query()
            ->where('status', 'submitted')
            ->where(function ($query): void {
                $query
                    ->whereNotNull('brand_catalogue_brand_id')
                    ->orWhere(fn ($inner) => $inner
                        ->whereNotNull('brand_name')
                        ->where('brand_name', '!=', '')
                    );
            })
            ->count();

        $draftCount = HairExtensionIntake::query()
            ->where('status', 'draft')
            ->where(function ($query): void {
                $query
                    ->whereNotNull('brand_catalogue_brand_id')
                    ->orWhere(fn ($inner) => $inner
                        ->whereNotNull('brand_name')
                        ->where('brand_name', '!=', '')
                    );
            })
            ->count();

        $recentIntakes = HairExtensionIntake::query()
            ->with(['brand', 'productType', 'style', 'photos', 'aiSuggestions'])
            ->where(function ($query): void {
                $query
                    ->whereNotNull('brand_catalogue_brand_id')
                    ->orWhere(fn ($inner) => $inner
                        ->whereNotNull('brand_name')
                        ->where('brand_name', '!=', '')
                    );
            })
            ->latest()
            ->limit(30)
            ->get();

        $groupedByBrand = $intakes
            ->groupBy(fn (HairExtensionIntake $intake) => $intake->brand_name ?: 'Unknown brand')
            ->sortKeys();

        return view('hair-extension-intake.submitted', [
            'groupedByBrand' => $groupedByBrand,
            'submittedIntakes' => $intakes,
            'submittedCount' => $submittedCount,
            'draftCount' => $draftCount,
            'includeDrafts' => $showDraftsOnly,
            'recentIntakes' => $recentIntakes,
            'exportUrl' => route('hair-extension-intake.export-json'),
            'intakeUrl' => route('hair-extension-intake.index'),
            'submittedUrl' => route('hair-extension-intake.submitted'),
        ]);
    }

    public function photos(HairExtensionIntake $intake): JsonResponse
    {
        $intake->load('photos');

        return response()->json([
            'ok' => true,
            'photos' => $this->photoPayloads($intake),
            'photo_url' => $intake->photoUrl(),
        ]);
    }

    public function storePhotos(Request $request, HairExtensionIntake $intake): JsonResponse
    {
        if (! $this->intakeHasBrand($intake)) {
            throw ValidationException::withMessages([
                'brand_name' => 'Enter the brand before saving product photos.',
            ]);
        }

        $data = $request->validate([
            'photo' => ['nullable', 'image', 'max:'.self::MAX_INTAKE_PHOTO_KB],
            'photos' => ['nullable', 'array', 'max:12'],
            'photos.*' => ['image', 'max:'.self::MAX_INTAKE_PHOTO_KB],
            'image_role' => ['required', 'string', 'max:255'],
            'source_label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $files = $this->uploadedPhotos($request);
        if ($files === []) {
            throw ValidationException::withMessages([
                'photo' => 'Take or upload at least one product photo.',
            ]);
        }

        $results = [];
        $fileCount = count($files);
        foreach ($files as $index => $file) {
            $photoNumber = $fileCount > 1 ? $index + 1 : null;
            $results[] = $this->storeIntakePhoto(
                $intake,
                $file,
                (string) $data['image_role'],
                $this->nullTrim($data['source_label'] ?? null) ?: 'Phone intake page',
                $this->nullTrim($data['notes'] ?? null),
                (bool) ($data['is_primary'] ?? false),
                $photoNumber,
            );
        }

        $intake->update([
            'status' => 'draft',
            'ai_status' => 'not_started',
            'submitted_at' => null,
            'last_synced_at' => now(),
        ]);
        $intake->load('photos');

        return response()->json([
            'ok' => true,
            'message' => count($results) === 1
                ? 'Photo saved to this product draft.'
                : count($results).' photos saved to this product draft.',
            'photos' => $this->photoPayloads($intake),
            'photo_url' => $intake->photoUrl(),
            'results' => $results,
        ]);
    }

    public function draft(HairExtensionIntake $intake): JsonResponse
    {
        $intake->load('photos');

        return response()->json([
            'ok' => true,
            'intake' => $this->draftPayload($intake),
            'autosave_url' => route('hair-extension-intake.autosave-existing', $intake),
            'submit_url' => route('hair-extension-intake.submit', $intake),
        ]);
    }

    public function autosave(Request $request, ?HairExtensionIntake $intake = null): JsonResponse
    {
        $data = $this->validatedData($request, false);

        if (! $this->hasMinimumDraftIdentity($data) && (! $intake || ! $this->intakeHasBrand($intake))) {
            return response()->json([
                'ok' => true,
                'message' => 'Enter the brand before creating a draft.',
                'intake' => null,
            ]);
        }

        $intake ??= HairExtensionIntake::query()->create([
            'status' => 'draft',
            'ai_status' => 'not_started',
        ]);

        $this->applyData($request, $intake, $data);
        $intake->update([
            'status' => 'draft',
            'ai_status' => 'not_started',
            'submitted_at' => null,
            'last_synced_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Draft synced.',
            'intake' => $this->payload($intake->fresh()),
            'autosave_url' => route('hair-extension-intake.autosave-existing', $intake),
            'submit_url' => route('hair-extension-intake.submit', $intake),
        ]);
    }

    public function submit(Request $request, HairExtensionIntake $intake): JsonResponse
    {
        $data = $this->validatedData($request, true);
        $this->applyData($request, $intake, $data);

        $intake->update([
            'status' => 'submitted',
            'ai_status' => 'ready_for_ai',
            'submitted_at' => now(),
            'last_synced_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Submitted for AI catalogue processing.',
            'intake' => $this->payload($intake->fresh()),
        ]);
    }

    public function destroy(Request $request, HairExtensionIntake $intake): RedirectResponse|JsonResponse
    {
        $this->deleteAllPhotos($intake);
        $intake->delete();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Intake deleted.']);
        }

        return back()->with('status', 'Hair extension intake deleted.');
    }

    public function exportJson(): JsonResponse
    {
        $intakes = HairExtensionIntake::query()
            ->with(['brand', 'productType', 'style', 'photos', 'store', 'section', 'subsection'])
            ->where('status', 'submitted')
            ->latest('submitted_at')
            ->get()
            ->map(fn (HairExtensionIntake $intake) => $this->payload($intake))
            ->values();

        return response()->json([
            'generated_at' => now()->toDateTimeString(),
            'purpose' => 'Hair extension shop observations ready for AI catalogue creation.',
            'count' => $intakes->count(),
            'intakes' => $intakes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, bool $forSubmit): array
    {
        $rules = [
            'brand_catalogue_brand_id' => ['nullable', 'integer', 'exists:brand_catalogue_brands,id'],
            'brand_name' => [$forSubmit ? 'required' : 'nullable', 'string', 'max:255'],
            'brand_catalogue_product_type_id' => ['nullable', 'integer', 'exists:brand_catalogue_product_types,id'],
            'observed_product_name' => ['nullable', 'string', 'max:255'],
            'product_type_name' => ['nullable', 'string', 'max:255'],
            'product_type_unknown' => ['nullable', 'boolean'],
            'brand_catalogue_style_id' => ['nullable', 'integer', 'exists:brand_catalogue_styles,id'],
            'style_name' => ['nullable', 'string', 'max:255'],
            'style_unknown' => ['nullable', 'boolean'],
            'variant_groups' => ['nullable', 'string'],
            'variant_structure' => ['nullable', 'string'],
            'source_url' => ['nullable', 'string', 'max:2000'],
            'verification_urls' => ['nullable', 'string'],
            'visible_text_notes' => ['nullable', 'string'],
            'remove_photo' => ['nullable', 'boolean'],
            'product_photo' => ['nullable', 'image', 'max:35840'],
        ];

        return $request->validate($rules);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyData(Request $request, HairExtensionIntake $intake, array $data): void
    {
        $brand = ! empty($data['brand_catalogue_brand_id'])
            ? BrandCatalogueBrand::query()->find((int) $data['brand_catalogue_brand_id'])
            : null;
        $productType = ! empty($data['brand_catalogue_product_type_id'])
            ? BrandCatalogueProductType::query()->find((int) $data['brand_catalogue_product_type_id'])
            : null;
        $style = ! empty($data['brand_catalogue_style_id'])
            ? BrandCatalogueStyle::query()->find((int) $data['brand_catalogue_style_id'])
            : null;

        $variantGroups = $this->decodeVariantGroups($data['variant_groups'] ?? null);
        $variantStructure = $this->decodeVariantStructure($data['variant_structure'] ?? null);
        $observedProductName = $this->nullTrim($data['observed_product_name'] ?? null)
            ?: $this->nullTrim($data['product_type_name'] ?? null);
        $verificationUrls = $this->cleanVerificationUrls(
            $data['verification_urls'] ?? null,
            $data['source_url'] ?? null,
        );

        $updates = [
            'brand_catalogue_brand_id' => $brand?->id,
            'brand_catalogue_product_type_id' => $productType?->id,
            'brand_catalogue_style_id' => $style?->id,
            'brand_name' => trim((string) ($data['brand_name'] ?? '')) ?: $brand?->name ?: $intake->brand_name,
            'observed_product_name' => $observedProductName,
            'product_type_name' => $observedProductName,
            'product_type_unknown' => (bool) ($data['product_type_unknown'] ?? false),
            'style_name' => trim((string) ($data['style_name'] ?? '')) ?: $style?->name,
            'style_unknown' => (bool) ($data['style_unknown'] ?? false),
            'variant_groups' => $variantGroups,
            'variant_structure' => $variantStructure,
            'source_url' => $verificationUrls[0] ?? null,
            'verification_urls' => $verificationUrls,
            'visible_text_notes' => trim((string) ($data['visible_text_notes'] ?? '')) ?: null,
            'last_synced_at' => now(),
        ];

        if ($request->boolean('remove_photo')) {
            $this->deleteLegacyPhoto($intake);
            $updates['photo_disk'] = null;
            $updates['photo_path'] = null;
            $updates['photo_original_filename'] = null;
        }

        if ($request->hasFile('product_photo')) {
            $this->deleteLegacyPhoto($intake);
            $file = $request->file('product_photo');
            $extension = $file->guessExtension() ?: $file->extension() ?: 'jpg';
            $brandSlug = Str::slug($updates['brand_name'] ?: 'unknown-brand');
            $directory = 'hair-extension-intake/'.$brandSlug.'/'.now()->format('Y-m-d');
            $filename = now()->format('His').'-'.Str::random(8).'.'.$extension;

            $updates['photo_disk'] = 'public';
            $updates['photo_path'] = $file->storeAs($directory, $filename, 'public');
            $updates['photo_original_filename'] = $file->getClientOriginalName();
        }

        $intake->update($updates);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeVariantGroups(?string $raw): array
    {
        if (! $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(function ($group): ?array {
                if (! is_array($group)) {
                    return null;
                }

                $name = trim((string) ($group['name'] ?? ''));
                $values = collect($group['values'] ?? [])
                    ->map(fn ($value) => trim((string) $value))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($name === '' && $values === []) {
                    return null;
                }

                return [
                    'name' => $name ?: 'Unknown group',
                    'values' => $values,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeVariantStructure(?string $raw): ?array
    {
        if (! $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $mainAxis = $this->cleanVariantText($decoded['main_axis'] ?? null) ?: null;
        $groups = collect($decoded['groups'] ?? [])
            ->map(function ($group): ?array {
                if (! is_array($group)) {
                    return null;
                }

                $mainValue = $this->cleanVariantText($group['main_value'] ?? null);
                $subAxis = $this->cleanVariantText($group['sub_axis'] ?? null) ?: 'Variant';
                $subValues = $this->cleanVariantValues($group['sub_values'] ?? []);

                if ($mainValue === '' && $subValues === []) {
                    return null;
                }

                return [
                    'main_value' => $mainValue ?: 'Unknown main value',
                    'sub_axis' => $subAxis,
                    'sub_values' => $subValues,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $commonVariants = collect($decoded['common_variants'] ?? [])
            ->map(function ($variant): ?array {
                if (! is_array($variant)) {
                    return null;
                }

                $name = $this->cleanVariantText($variant['name'] ?? null);
                $values = $this->cleanVariantValues($variant['values'] ?? []);

                if ($name === '' && $values === []) {
                    return null;
                }

                return [
                    'name' => $name ?: 'Common variant',
                    'values' => $values,
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($mainAxis === null && $groups === [] && $commonVariants === []) {
            return null;
        }

        $mainAxis ??= 'Main variant';

        return [
            'mode' => 'mapped',
            'main_axis' => $mainAxis,
            'groups' => $groups,
            'common_variants' => $commonVariants,
            'sku_matrix' => $this->buildVariantSkuMatrix($mainAxis, $groups, $commonVariants),
            'summary' => [
                'main_group_count' => count($groups),
                'common_variant_count' => count($commonVariants),
                'sellable_combination_count' => collect($groups)->sum(
                    fn (array $group): int => max(1, count($group['sub_values'] ?? []))
                ),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @param array<int, array<string, mixed>> $commonVariants
     * @return array<int, array<string, mixed>>
     */
    private function buildVariantSkuMatrix(string $mainAxis, array $groups, array $commonVariants): array
    {
        $commonAttributes = collect($commonVariants)
            ->mapWithKeys(fn (array $variant): array => [
                $variant['name'] => $variant['values'] ?? [],
            ])
            ->all();

        return collect($groups)
            ->flatMap(function (array $group) use ($mainAxis, $commonAttributes): array {
                $subValues = $group['sub_values'] ?? [];

                if ($subValues === []) {
                    return [[
                        'main_axis' => $mainAxis,
                        'main_value' => $group['main_value'],
                        'sub_axis' => $group['sub_axis'] ?? null,
                        'sub_value' => null,
                        'common_attributes' => $commonAttributes,
                    ]];
                }

                return collect($subValues)
                    ->map(fn (string $subValue): array => [
                        'main_axis' => $mainAxis,
                        'main_value' => $group['main_value'],
                        'sub_axis' => $group['sub_axis'] ?? 'Variant',
                        'sub_value' => $subValue,
                        'common_attributes' => $commonAttributes,
                    ])
                    ->all();
            })
            ->values()
            ->all();
    }

    private function cleanVariantText(mixed $value): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', ' ', $value) ?: $value;

        return $value;
    }

    private function fieldKnowledgeStatus(mixed $value): string
    {
        $value = (string) $value;

        return in_array($value, ['known', 'not_known', 'not_sure'], true) ? $value : 'known';
    }

    /**
     * @param array<string, mixed> $data
     * @return array{store_id:?int,section_id:?int,subsection_id:?int,label:?string}
     */
    private function resolveIntakeLocation(array $data): array
    {
        $storeId = ! empty($data['store_id']) ? (int) $data['store_id'] : null;
        $sectionId = ! empty($data['section_id']) ? (int) $data['section_id'] : null;
        $subsectionId = ! empty($data['subsection_id']) ? (int) $data['subsection_id'] : null;

        $subsection = $subsectionId
            ? InventorySubsection::query()->with('section.location')->find($subsectionId)
            : null;
        if ($subsection) {
            $sectionId = (int) $subsection->inventory_section_id;
            $storeId = (int) $subsection->section->inventory_location_id;
        }

        $section = $sectionId
            ? InventorySection::query()->with('location')->find($sectionId)
            : null;
        if ($section) {
            if ($storeId !== null && (int) $section->inventory_location_id !== $storeId) {
                throw ValidationException::withMessages([
                    'section_id' => 'Selected section does not belong to the selected store.',
                ]);
            }
            $storeId = (int) $section->inventory_location_id;
        }

        $store = $storeId ? InventoryLocation::query()->find($storeId) : null;
        if ($store && $store->location_type !== 'shop') {
            throw ValidationException::withMessages([
                'store_id' => 'Selected store is not a shop location.',
            ]);
        }

        if ($subsection && $section && (int) $subsection->inventory_section_id !== (int) $section->id) {
            throw ValidationException::withMessages([
                'subsection_id' => 'Selected subsection does not belong to the selected section.',
            ]);
        }

        $label = collect([$store?->name, $section?->name, $subsection?->name])
            ->filter()
            ->implode(' / ');

        return [
            'store_id' => $store?->id,
            'section_id' => $section?->id,
            'subsection_id' => $subsection?->id,
            'label' => $label !== '' ? $label : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function cleanVariantValues(mixed $values): array
    {
        if (! is_array($values)) {
            $values = preg_split('/[\n,]+/', (string) $values) ?: [];
        }

        return collect($values)
            ->map(fn (mixed $value): string => $this->cleanVariantText($value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function decodeClassificationPath(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded)
                ? $decoded
                : (preg_split('/[\n,>]+/', $raw) ?: []);
        }

        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->map(fn (mixed $value): string => $this->cleanVariantText($value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeFamilyVariantRows(string $raw, string $defaultSubAxis): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(function ($row) use ($defaultSubAxis): ?array {
                if (! is_array($row)) {
                    return null;
                }

                $mainValue = $this->cleanVariantText($row['main_value'] ?? null);
                $subAxis = $this->cleanVariantText($row['sub_axis'] ?? null) ?: $defaultSubAxis;
                $subValues = $this->cleanVariantValues($row['sub_values'] ?? []);
                $notes = $this->cleanVariantText($row['notes'] ?? null);

                if ($mainValue === '' && $subValues === [] && $notes === '') {
                    return null;
                }

                return [
                    'main_value' => $mainValue ?: 'Unspecified',
                    'sub_axis' => $subAxis ?: 'Variant',
                    'sub_values' => $subValues,
                    'notes' => $notes ?: null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @return array<string, mixed>
     */
    private function familyVariantStructure(string $mainAxis, array $groups, array $commonVariants = []): array
    {
        return [
            'mode' => 'mapped',
            'source' => 'text_note_v2',
            'main_axis' => $mainAxis,
            'groups' => $groups,
            'common_variants' => $commonVariants,
            'sku_matrix' => $this->buildVariantSkuMatrix($mainAxis, $groups, $commonVariants),
            'summary' => [
                'main_group_count' => count($groups),
                'common_variant_count' => count($commonVariants),
                'sellable_combination_count' => collect($groups)->sum(
                    fn (array $group): int => max(1, count($group['sub_values'] ?? []))
                ),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @return array<int, array<string, mixed>>
     */
    private function familyVariantGroups(string $mainAxis, string $subAxis, array $groups, array $commonVariants = []): array
    {
        $mainValues = collect($groups)
            ->pluck('main_value')
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->values()
            ->all();
        $subValues = collect($groups)
            ->flatMap(fn (array $group): array => $group['sub_values'] ?? [])
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->values()
            ->all();
        $variantGroups = [];

        if ($mainValues !== []) {
            $variantGroups[] = [
                'name' => $mainAxis,
                'values' => $mainValues,
            ];
        }

        if ($subValues !== []) {
            $variantGroups[] = [
                'name' => $subAxis,
                'values' => $subValues,
            ];
        }

        foreach ($commonVariants as $commonVariant) {
            if (($commonVariant['values'] ?? []) === []) {
                continue;
            }

            $variantGroups[] = [
                'name' => $commonVariant['name'] ?? 'Common variant',
                'values' => $commonVariant['values'],
            ];
        }

        return $variantGroups;
    }

    /**
     * @return array<int, array{name:string,values:array<int,string>}>
     */
    private function decodeFamilyCommonVariants(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(function ($row): ?array {
                if (! is_array($row)) {
                    return null;
                }

                $name = $this->cleanVariantText($row['name'] ?? null);
                $values = $this->cleanVariantValues($row['values'] ?? []);

                if ($name === '' && $values === []) {
                    return null;
                }

                return [
                    'name' => $name ?: 'Common variant',
                    'values' => $values,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function cleanVerificationUrls(mixed $rawUrls, mixed $fallbackUrl = null): array
    {
        if (is_string($rawUrls) && trim($rawUrls) !== '') {
            $decoded = json_decode($rawUrls, true);
            $rawUrls = is_array($decoded) ? $decoded : preg_split('/[\n,]+/', $rawUrls);
        }

        if (! is_array($rawUrls)) {
            $rawUrls = [];
        }

        if ($fallbackUrl !== null) {
            $rawUrls[] = $fallbackUrl;
        }

        return collect($rawUrls)
            ->map(fn (mixed $url): string => trim((string) $url))
            ->filter(fn (string $url): bool => $url !== '')
            ->map(fn (string $url): string => Str::startsWith($url, ['http://', 'https://']) ? $url : 'https://'.$url)
            ->filter(fn (string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->unique(fn (string $url): string => Str::lower(rtrim($url, '/')))
            ->take(20)
            ->values()
            ->all();
    }

    private function deleteLegacyPhoto(HairExtensionIntake $intake): void
    {
        if ($intake->photo_disk && $intake->photo_path) {
            Storage::disk($intake->photo_disk)->delete($intake->photo_path);
        }
    }

    private function deleteAllPhotos(HairExtensionIntake $intake): void
    {
        $this->deleteLegacyPhoto($intake);

        $intake->photos()
            ->get()
            ->each(function (HairExtensionIntakePhoto $photo): void {
                Storage::disk($photo->storage_disk ?: 'public')->delete($photo->storage_path);
                $photo->delete();
            });
    }

    /**
     * @return array{type:string,id:int}
     */
    private function storeIntakePhoto(
        HairExtensionIntake $intake,
        mixed $file,
        string $imageRole,
        string $sourceLabel,
        ?string $notes,
        bool $requestedPrimary,
        ?int $photoNumber = null,
    ): array {
        $targetName = $this->intakeImageBaseName($intake);
        $imageName = $this->imageDisplayName($targetName, $imageRole, $photoNumber);
        $directory = 'hair-extension-intake/evidence/'.$intake->id.'-'.Str::slug(Str::limit($targetName, 80, ''));
        $stored = $this->storeUploadedPhotoFile($file, $directory, $imageName);

        $hasExistingPhotos = $intake->photos()->exists();
        $isPrimary = $requestedPrimary || ! $hasExistingPhotos || ($imageRole === 'main' && $photoNumber === null);

        if ($isPrimary) {
            $intake->photos()->update(['is_primary' => false]);
        }

        /** @var HairExtensionIntakePhoto $photo */
        $photo = $intake->photos()->create([
            'image_role' => $imageRole,
            'source_label' => $sourceLabel,
            'notes' => $notes,
            'source_type' => 'phone_intake_page',
            'sort_order' => ((int) $intake->photos()->max('sort_order')) + 1,
            'is_primary' => $isPrimary,
        ] + $stored);

        return ['type' => 'hair_extension_intake_photo', 'id' => $photo->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function storeUploadedPhotoFile(mixed $file, string $directory, string $imageName): array
    {
        $extension = $file->guessExtension() ?: $file->extension() ?: 'jpg';
        $filename = ProductImageNamer::uniqueFilename($directory, $imageName, $extension);
        $path = $file->storeAs($directory, $filename, 'public');
        app(ImageWatermarker::class)->applyToPublicStoragePath($path);

        return [
            'storage_disk' => 'public',
            'storage_path' => $path,
            'original_filename' => $filename,
            'mime_type' => Storage::disk('public')->mimeType($path) ?: $file->getClientMimeType(),
            'file_size' => Storage::disk('public')->size($path),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function uploadedPhotos(Request $request): array
    {
        $files = [];

        if ($request->hasFile('photos')) {
            $photoFiles = $request->file('photos');
            $files = array_values(is_array($photoFiles) ? $photoFiles : [$photoFiles]);
        }

        if ($request->hasFile('photo')) {
            $files[] = $request->file('photo');
        }

        return array_values(array_filter($files));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hasMinimumDraftIdentity(array $data): bool
    {
        return $this->nullTrim($data['brand_name'] ?? null) !== null
            || ! empty($data['brand_catalogue_brand_id']);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function inventoryStorePayload()
    {
        return InventoryLocation::query()
            ->where('location_type', 'shop')
            ->where('is_active', true)
            ->with(['sections' => fn ($query) => $query
                ->where('is_active', true)
                ->with(['subsections' => fn ($subQuery) => $subQuery->where('is_active', true)])])
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (InventoryLocation $store): array => [
                'id' => $store->id,
                'name' => $store->name,
                'is_default' => (bool) $store->is_default,
                'sections' => $store->sections->map(fn (InventorySection $section): array => [
                    'id' => $section->id,
                    'name' => $section->name,
                    'subsections' => $section->subsections->map(fn (InventorySubsection $subsection): array => [
                        'id' => $subsection->id,
                        'name' => $subsection->name,
                    ])->values(),
                ])->values(),
            ])
            ->values();
    }

    private function intakeHasBrand(HairExtensionIntake $intake): bool
    {
        return filled($intake->brand_name) || filled($intake->brand_catalogue_brand_id);
    }

    private function imageDisplayName(string $targetName, string $imageRole, ?int $photoNumber = null): string
    {
        $imageName = ProductImageNamer::displayName($targetName, $imageRole);

        return $photoNumber ? $imageName.' '.$photoNumber : $imageName;
    }

    private function nullTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function observedProductName(HairExtensionIntake $intake): ?string
    {
        return $this->nullTrim($intake->observed_product_name ?? null)
            ?: $this->nullTrim($intake->product_type_name ?? null);
    }

    private function intakeImageBaseName(HairExtensionIntake $intake): string
    {
        $brand = $this->nullTrim($intake->brand_name);
        $product = $this->nullTrim($intake->style_name)
            ?: $this->observedProductName($intake)
            ?: 'Product observation';

        if ($brand && Str::startsWith(Str::lower($product), Str::lower($brand))) {
            return ProductImageNamer::cleanHumanText($product);
        }

        return ProductImageNamer::cleanHumanText(trim(collect([$brand, $product])->filter()->implode(' ')) ?: 'Hair extension intake');
    }

    /**
     * @return array<int, string>
     */
    private function verificationUrls(HairExtensionIntake $intake): array
    {
        return $this->cleanVerificationUrls($intake->verification_urls ?? [], $intake->source_url);
    }

    /**
     * @return array<string, mixed>
     */
    private function draftPayload(HairExtensionIntake $intake): array
    {
        return [
            'id' => $intake->id,
            'status' => $intake->status,
            'brand_catalogue_brand_id' => $intake->brand_catalogue_brand_id,
            'brand_name' => $intake->brand_name,
            'brand_catalogue_product_type_id' => $intake->brand_catalogue_product_type_id,
            'catalogue_product_type_name' => $intake->productType?->name,
            'product_type_name' => $this->observedProductName($intake),
            'observed_product_name' => $this->observedProductName($intake),
            'catalogue_style_status' => $intake->catalogue_style_status ?: 'known',
            'product_type_status' => $intake->product_type_status ?: ($intake->product_type_unknown ? 'not_known' : 'known'),
            'style_family_status' => $intake->style_family_status ?: ($intake->style_unknown ? 'not_known' : 'known'),
            'classification_path' => $intake->classification_path ?? [],
            'shelf_location' => $intake->shelf_location,
            'store_id' => $intake->store_id,
            'section_id' => $intake->section_id,
            'subsection_id' => $intake->subsection_id,
            'product_type_unknown' => $intake->product_type_unknown,
            'brand_catalogue_style_id' => $intake->brand_catalogue_style_id,
            'style_name' => $intake->style_name,
            'style_unknown' => $intake->style_unknown,
            'variant_groups' => $intake->variant_groups ?? [],
            'variant_structure' => $intake->variant_structure,
            'source_url' => $intake->source_url,
            'verification_urls' => $this->verificationUrls($intake),
            'visible_text_notes' => $intake->visible_text_notes,
            'photo_url' => $intake->photoUrl(),
            'photos' => $this->photoPayloads($intake),
            'last_synced_at' => $intake->last_synced_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v2EditPayload(HairExtensionIntake $intake, bool $isDuplicate = false): array
    {
        $variantStructure = is_array($intake->variant_structure) ? $intake->variant_structure : [];

        return [
            'id' => $isDuplicate ? null : $intake->id,
            'status' => $isDuplicate ? null : $intake->status,
            'brand_catalogue_brand_id' => $intake->brand_catalogue_brand_id,
            'brand_catalogue_product_type_id' => $intake->brand_catalogue_product_type_id,
            'brand_catalogue_style_id' => $intake->brand_catalogue_style_id,
            'brand_name' => $intake->brand_name,
            'product_type_name' => $intake->product_type_name,
            'catalogue_style_status' => $intake->catalogue_style_status ?: 'known',
            'product_type_status' => $intake->product_type_status ?: ($intake->product_type_unknown ? 'not_known' : 'known'),
            'style_family_status' => $intake->style_family_status ?: ($intake->style_unknown ? 'not_known' : 'known'),
            'classification_path' => $intake->classification_path ?? [],
            'shelf_location' => $intake->shelf_location,
            'store_id' => $intake->store_id,
            'section_id' => $intake->section_id,
            'subsection_id' => $intake->subsection_id,
            'style_name' => $intake->style_name ?: $this->observedProductName($intake),
            'visible_text_notes' => $intake->visible_text_notes,
            'variant_main_axis' => $variantStructure['main_axis'] ?? 'Length',
            'rows' => $variantStructure['groups'] ?? [],
            'common_rows' => $variantStructure['common_variants'] ?? [],
            'photo_url' => $isDuplicate ? null : $intake->photoUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(HairExtensionIntake $intake): array
    {
        return [
            'id' => $intake->id,
            'status' => $intake->status,
            'ai_status' => $intake->ai_status,
            'brand' => [
                'catalogue_brand_id' => $intake->brand_catalogue_brand_id,
                'name' => $intake->brand_name,
            ],
            'observed_product_name' => $this->observedProductName($intake),
            'knowledge_status' => [
                'catalogue_style' => $intake->catalogue_style_status ?: 'known',
                'product_type' => $intake->product_type_status ?: ($intake->product_type_unknown ? 'not_known' : 'known'),
                'style_family' => $intake->style_family_status ?: ($intake->style_unknown ? 'not_known' : 'known'),
            ],
            'classification' => [
                'path' => $intake->classification_path ?? [],
                'shelf_location' => $intake->shelf_location,
                'store' => $intake->store ? ['id' => $intake->store->id, 'name' => $intake->store->name] : null,
                'section' => $intake->section ? ['id' => $intake->section->id, 'name' => $intake->section->name] : null,
                'subsection' => $intake->subsection ? ['id' => $intake->subsection->id, 'name' => $intake->subsection->name] : null,
            ],
            'product_type' => [
                'catalogue_product_type_id' => $intake->brand_catalogue_product_type_id,
                'name' => $intake->productType?->name,
                'unknown' => $intake->product_type_unknown,
            ],
            'style_family' => [
                'catalogue_style_id' => $intake->brand_catalogue_style_id,
                'name' => $intake->style_name,
                'unknown' => $intake->style_unknown,
            ],
            'variant_groups' => $intake->variant_groups ?? [],
            'variant_structure' => $intake->variant_structure,
            'source_url' => $intake->source_url,
            'verification_urls' => $this->verificationUrls($intake),
            'visible_text_notes' => $intake->visible_text_notes,
            'photo_url' => $intake->photoUrl(),
            'photos' => $this->photoPayloads($intake),
            'photo_original_filename' => $intake->photo_original_filename,
            'last_synced_at' => $intake->last_synced_at?->toDateTimeString(),
            'submitted_at' => $intake->submitted_at?->toDateTimeString(),
            'created_at' => $intake->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function photoPayloads(HairExtensionIntake $intake): array
    {
        $photos = $intake->relationLoaded('photos')
            ? $intake->photos
            : $intake->photos()->get();

        return $photos->map(fn (HairExtensionIntakePhoto $photo) => [
            'id' => $photo->id,
            'url' => $photo->displayUrl(),
            'image_role' => $photo->image_role,
            'role_label' => $photo->roleLabel(),
            'source_label' => $photo->source_label,
            'notes' => $photo->notes,
            'original_filename' => $photo->original_filename,
            'file_size' => $photo->file_size,
            'is_primary' => $photo->is_primary,
            'sort_order' => $photo->sort_order,
            'created_at' => $photo->created_at?->toDateTimeString(),
        ])->values()->all();
    }
}
