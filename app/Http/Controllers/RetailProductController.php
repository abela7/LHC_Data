<?php

namespace App\Http\Controllers;

use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueVariantOption;
use App\Models\InventoryLocation;
use App\Models\InventorySection;
use App\Models\Product;
use App\Models\ProductCategoryAssignment;
use App\Models\ProductEcommerceProfile;
use App\Models\ProductFamily;
use App\Models\ProductPosProfile;
use App\Models\ProductPrice;
use App\Models\ProductSource;
use App\Models\ProductVariantGroup;
use App\Models\ProductVariantGroupType;
use App\Models\ProductVariantOption;
use App\Models\ProductVariantValue;
use App\Services\HairIntakeBarcodeService;
use App\Services\OpenAiRetailNamingService;
use App\Services\RetailDescriptionWriterService;
use App\Services\SkuCodeAllocator;
use App\Support\CustomerProductDescription;
use App\Support\HairExtensionLengthLabel;
use App\Support\RetailFamilySkuGrouper;
use App\Support\VariantNaturalSort;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RetailProductController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $brand = trim((string) $request->query('brand', ''));
        $department = trim((string) $request->query('department', ''));
        $productType = trim((string) $request->query('product_type', ''));
        $confidence = trim((string) $request->query('confidence', ''));
        $source = trim((string) $request->query('source', 'all'));
        $allowedPerPage = [25, 50, 100, 200];
        $perPage = (int) $request->query('per_page', 50);
        if (! in_array($perPage, $allowedPerPage, true)) {
            $perPage = 50;
        }

        $sourceTypes = [
            'janson' => 'janson_product',
            'mamado' => 'mamado_product',
            'picture' => ['picture_product_confidence_a', 'picture_product_draft'],
            'all' => null,
        ];
        $sourceType = array_key_exists($source, $sourceTypes) ? $sourceTypes[$source] : 'janson_product';
        $source = array_key_exists($source, $sourceTypes) ? $source : 'janson';

        $families = $this->retailFamilyQuery($sourceType)
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('pf.family_name', 'like', $like)
                        ->orWhere('pf.brand_name', 'like', $like)
                        ->orWhere('pf.line_name', 'like', $like)
                        ->orWhere('pf.product_type_name', 'like', $like)
                        ->orWhere('p.name', 'like', $like)
                        ->orWhere('p.sku', 'like', $like)
                        ->orWhere('p.barcode', 'like', $like)
                        ->orWhere('ps.notes', 'like', $like);
                });
            })
            ->when($brand !== '', fn ($query) => $query->where('pf.brand_name', $brand))
            ->when($department !== '', fn ($query) => $query->where('pf.root_catalogue_name', $department))
            ->when($productType !== '', fn ($query) => $query->where('pf.product_type_name', $productType))
            ->when(in_array($confidence, ['A', 'B', 'C', 'D'], true), fn ($query) => $query->where('ps.confidence', $confidence))
            ->groupBy('pf.id', 'pf.brand_name', 'pf.family_name', 'pf.line_name', 'pf.product_type_name', 'pf.root_catalogue_name', 'pf.status')
            ->orderBy('pf.brand_name')
            ->orderBy('pf.family_name')
            ->paginate($perPage)
            ->withQueryString();

        return view('retail-products.index', [
            'families' => $families,
            'stats' => $this->retailProductStats($sourceType),
            'brands' => $this->retailProductBrands($sourceType),
            'departments' => $this->retailProductDepartments($sourceType),
            'productTypes' => $this->retailProductTypes($sourceType),
            'search' => $search,
            'brand' => $brand,
            'department' => $department,
            'productType' => $productType,
            'confidence' => $confidence,
            'source' => $source,
            'allowedPerPage' => $allowedPerPage,
            'perPage' => $perPage,
        ]);
    }

    public function brands(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $brands = $this->combinedRetailBrandGroups();

        if ($search !== '') {
            $needle = Str::lower($search);
            $brands = $brands
                ->filter(function (array $brand) use ($needle): bool {
                    return Str::contains(Str::lower($brand['display_name']), $needle)
                        || collect($brand['source_names'])->contains(fn (string $name): bool => Str::contains(Str::lower($name), $needle));
                })
                ->values();
        }

        $stats = [
            'brands' => $brands->count(),
            'matched_brands' => $brands->filter(fn (array $brand): bool => count($brand['sources']) > 1)->count(),
            'janson_brands' => $brands->filter(fn (array $brand): bool => $brand['janson_products'] > 0)->count(),
            'mamado_brands' => $brands->filter(fn (array $brand): bool => $brand['mamado_products'] > 0)->count(),
            'picture_brands' => $brands->filter(fn (array $brand): bool => $brand['picture_products'] > 0)->count(),
            'products' => $brands->sum('products'),
            'families' => $brands->sum('families'),
            'picture_products' => $brands->sum('picture_products'),
            'pictures' => $brands->sum('pictures'),
        ];

        return view('retail-products.brands', [
            'brands' => $brands,
            'stats' => $stats,
            'search' => $search,
        ]);
    }

    public function showBrand(Request $request, string $brandKey): View
    {
        $brand = $this->combinedRetailBrandGroups()
            ->firstWhere('key', $brandKey);

        abort_if(! $brand, 404);

        $families = $this->combinedRetailFamilyGroups($brandKey);

        return view('retail-products.brand', [
            'brand' => $brand,
            'families' => $families,
            'stats' => [
                'products' => $families->sum('products'),
                'families' => $families->count(),
                'janson_products' => $families->sum('janson_products'),
                'mamado_products' => $families->sum('mamado_products'),
                'picture_products' => $families->sum('picture_products'),
                'picture_hits' => $families->sum('picture_hits'),
                'pictures' => $families->sum('pictures'),
                'both_source_families' => $families->filter(fn (array $family): bool => $family['janson_products'] > 0 && $family['mamado_products'] > 0)->count(),
                'review' => $families->sum('review_count'),
            ],
        ]);
    }

    /**
     * Permanently remove a sellable SKU and its operational records.
     * The linked brand catalogue SKU (if any) is left intact.
     */
    public function destroyProduct(Request $request, Product $product): JsonResponse|RedirectResponse
    {
        $familyId = $product->product_family_id;
        $label = $product->inventory_name ?: $product->name;

        DB::transaction(function () use ($product): void {
            $product->load('media');

            foreach ($product->media as $media) {
                if ($media->catalogue_image_id === null && $media->storage_disk && $media->storage_path) {
                    Storage::disk($media->storage_disk)->delete($media->storage_path);
                }
            }

            $product->delete();
        });

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'SKU removed.',
                'product_id' => $product->id,
                'family_id' => $familyId,
            ]);
        }

        return redirect()
            ->route('retail-products.families.show', $familyId)
            ->with('status', "Removed {$label}.");
    }

    public function showProduct(Product $product): View
    {
        $product->load([
            'brand',
            'family.brand',
            'family.media',
            'family.ecommerceProfile',
            'family.sources',
            'family.categoryAssignments.scaffold',
            'family.categoryAssignments.axis',
            'family.categoryAssignments.node.parent',
            'price',
            'media',
            'sources',
            'categoryAssignments.scaffold',
            'categoryAssignments.axis',
            'categoryAssignments.node.parent',
            'inventoryLevels.location',
            'posProfile',
            'ecommerceProfile',
            'variantValues.group',
            'variantValues.option',
            'catalogueSku.style.productType.line',
        ]);

        $productMedia = $product->media;
        $familyMedia = $product->family?->media ?? collect();
        $allMedia = $productMedia->concat($familyMedia)->values();
        $primaryMedia = $productMedia->firstWhere('is_primary', true)
            ?? $productMedia->first()
            ?? $familyMedia->firstWhere('is_primary', true)
            ?? $familyMedia->first();
        $stockQuantity = (float) $product->inventoryLevels->sum('stock_quantity');
        $variantValues = $product->variantValues
            ->sortBy(fn ($value) => sprintf('%s:%s', $value->group ? VariantNaturalSort::groupKey($value->group) : '9999', VariantNaturalSort::valueKey($value->option?->label)))
            ->values();

        return view('retail-products.product', [
            'product' => $product,
            'family' => $product->family,
            'productMedia' => $productMedia,
            'familyMedia' => $familyMedia,
            'allMedia' => $allMedia,
            'primaryMedia' => $primaryMedia,
            'stockQuantity' => $stockQuantity,
            'variantValues' => $variantValues,
        ]);
    }

    public function showFamily(ProductFamily $family): View
    {
        $family->load([
            'brand',
            'catalogueStyle',
            'categoryAssignments.scaffold',
            'categoryAssignments.axis',
            'categoryAssignments.node.parent',
            'variantGroups.options',
            'media',
            'ecommerceProfile',
            'products.price',
            'products.media',
            'products.categoryAssignments.scaffold',
            'products.categoryAssignments.axis',
            'products.categoryAssignments.node.parent',
            'products.inventoryLevels.location',
            'products.posProfile',
            'products.ecommerceProfile',
            'products.variantValues.group',
            'products.variantValues.option',
            'products.catalogueSku.style.productType.line',
        ]);
        $this->normalizeFamilyVariantOrder($family);

        $products = $family->products;
        $pricedCount = $products->filter(fn (Product $product): bool => $product->price?->retail_price !== null)->count();

        // Shared location/section: use the first product's level values if all products share the same.
        $sharedLocationId = $this->sharedIntValue($products, fn (Product $product) => $product->inventoryLevels->first()?->inventory_location_id);
        $sharedSectionId = $this->sharedIntValue($products, fn (Product $product) => $product->inventoryLevels->first()?->inventory_section_id);

        $familySharedDetails = [
            'retail_price' => $this->sharedDecimalValue($products, fn (Product $product) => $product->price?->retail_price),
            'cost_price' => $this->sharedDecimalValue($products, fn (Product $product) => $product->price?->cost_price),
            'vat_rate' => $this->sharedDecimalValue($products, fn (Product $product) => $product->price?->vat_rate),
            'stock_quantity' => $this->sharedDecimalValue($products, fn (Product $product) => $product->inventoryLevels->sum('stock_quantity')),
            'shelf_location' => $this->sharedTextValue($products, fn (Product $product) => $product->inventoryLevels->first()?->shelf_location),
            'supplier' => $this->sharedTextValue($products, fn (Product $product) => $product->inventoryLevels->first()?->supplier),
            'supplier_product_code' => $this->sharedTextValue($products, fn (Product $product) => $product->inventoryLevels->first()?->supplier_product_code),
            'description' => $this->sharedTextValue($products, fn (Product $product) => $product->description) ?? $family->description,
            'priced_count' => $pricedCount,
            'inventory_location_id' => $sharedLocationId,
            'inventory_section_id' => $sharedSectionId,
        ];

        return view('retail-products.family', [
            'family' => $family,
            'products' => $products,
            'skuGrouping' => RetailFamilySkuGrouper::forFamily($family, $products),
            'familySharedDetails' => $familySharedDetails,
            'mediaRoles' => [
                ['value' => 'main', 'label' => 'Main display'],
                ['value' => 'variant', 'label' => 'Variant photo'],
                ['value' => 'gallery', 'label' => 'Gallery image'],
                ['value' => 'detail', 'label' => 'Detail'],
                ['value' => 'packaging', 'label' => 'Packaging'],
                ['value' => 'barcode', 'label' => 'Barcode close-up'],
                ['value' => 'back', 'label' => 'Back of pack'],
                ['value' => 'label_ingredients', 'label' => 'Label / ingredients'],
                ['value' => 'shelf_context', 'label' => 'Shelf context'],
                ['value' => 'swatch', 'label' => 'Swatch'],
                ['value' => 'style', 'label' => 'Style image'],
                ['value' => 'hero', 'label' => 'Hero/main banner'],
                ['value' => 'texture', 'label' => 'Texture'],
                ['value' => 'source', 'label' => 'Source/reference'],
            ],
            'mediaUsageContexts' => [
                ['value' => 'all', 'label' => 'Use everywhere'],
                ['value' => 'pos', 'label' => 'POS only'],
                ['value' => 'ecommerce', 'label' => 'Ecommerce only'],
                ['value' => 'inventory', 'label' => 'Inventory/admin'],
                ['value' => 'admin', 'label' => 'Internal reference'],
            ],
            'stats' => [
                'products' => $products->count(),
                'variant_groups' => $family->variantGroups->count(),
                'variant_options' => $family->variantGroups->sum(fn ($group) => $group->options->count()),
                'images' => $family->media->count() + $products->sum(fn ($product) => $product->media->count()),
                'missing_prices' => $products->filter(fn ($product) => $product->price?->retail_price === null)->count(),
                'missing_barcode' => $products->filter(fn ($product) => empty($product->barcode))->count(),
                'missing_image' => $products->filter(fn ($product) => $product->media->isEmpty())->count(),
                'pos_active' => $products->where('is_pos_active', true)->count(),
                'ecommerce_active' => $products->where('is_ecommerce_active', true)->count(),
                'tracked_inventory' => $products->where('is_inventory_tracked', true)->count(),
                'out_of_stock' => $products->filter(fn ($product) => $product->inventoryLevels->sum('stock_quantity') <= 0)->count(),
            ],
            'departmentOptions' => $this->departmentOptions(),
            'productTypeOptions' => $this->productTypeOptions(),
            'newSkuPrefix' => $this->familySkuPrefix($family),
            'missingComboCount' => $this->countMissingFamilyCombos($family),
            'variantOptionSellable' => $this->variantOptionSellableMap($family),
            'variantGroupTypeOptions' => $this->variantGroupTypeOptions(),
            'inventoryLocations' => InventoryLocation::with(['sections' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('name')])->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function storeFamilyProduct(Request $request, ProductFamily $family): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['fresh', 'duplicate'])],
            'duplicate_product_id' => ['nullable', 'integer'],
            'variant_options' => ['nullable', 'array'],
            'variant_options.*' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('products', 'sku')],
            'barcode' => ['nullable', 'string', 'max:255'],
            'retail_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'copy_price' => ['nullable', 'boolean'],
        ]);

        $family->load([
            'brand',
            'catalogueStyle',
            'categoryAssignments',
            'variantGroups.options',
            'products.price',
            'products.variantValues.option',
            'products.variantValues.group',
        ]);

        $baseProduct = null;
        if ($data['mode'] === 'duplicate') {
            $baseProduct = $family->products->firstWhere('id', (int) ($data['duplicate_product_id'] ?? 0));

            if (! $baseProduct) {
                throw ValidationException::withMessages([
                    'duplicate_product_id' => 'Choose the SKU you want to duplicate.',
                ]);
            }
        }

        $selectedOptions = $this->validatedFamilyVariantOptions($family, $data['variant_options'] ?? []);
        $existingProduct = $this->existingProductWithVariantOptions($family, $selectedOptions->pluck('id')->all());

        if ($existingProduct) {
            throw ValidationException::withMessages([
                'variant_options' => "This variant combination already exists as {$existingProduct->name}.",
            ]);
        }

        $product = $this->createSellableProductForFamily($family, $selectedOptions, [
            'name' => $data['name'] ?? null,
            'sku' => $data['sku'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'retail_price' => array_key_exists('retail_price', $data) ? $data['retail_price'] : null,
            'base_product' => $baseProduct,
            'copy_price' => (bool) ($data['copy_price'] ?? true),
            'strict_catalogue_check' => true,
        ]);

        return redirect()
            ->route('retail-products.families.show', $family)
            ->with('status', "Created sellable SKU {$product->name}.");
    }

    /**
     * Detect every combination of variant options that does NOT yet have a
     * sellable product and create one for each, using the same logic as the
     * manual Add sellable SKU form (auto name, auto SKU; POS, website and
     * inventory on by default).
     */
    public function refreshFamilyMissingSkus(ProductFamily $family): RedirectResponse
    {
        $family->load([
            'brand',
            'catalogueStyle',
            'categoryAssignments',
            'variantGroups.options',
            'products.variantValues.option',
            'products.variantValues.group',
        ]);

        if ($family->variantGroups->isEmpty()) {
            return back()->with('status', 'This family has no variant axes — nothing to refresh.');
        }

        foreach ($family->variantGroups as $group) {
            if ($group->options->isEmpty()) {
                return back()->with('status', "Cannot refresh: the \"{$group->name}\" axis has no values yet.");
            }
        }

        $combinations = $this->cartesianFamilyVariantCombinations($family);
        $existingSignatures = $this->existingFamilyVariantSignatures($family);

        $createdCount = 0;
        $skippedExisting = 0;
        $skippedConflict = 0;
        $firstName = null;

        foreach ($combinations as $combo) {
            $signature = collect($combo)->map(fn (ProductVariantOption $o): int => (int) $o->id)->sort()->values()->implode(',');
            if (isset($existingSignatures[$signature])) {
                $skippedExisting++;

                continue;
            }

            $product = $this->createSellableProductForFamily(
                $family,
                collect($combo),
                $this->defaultSellableProductOpts($family),
            );

            if ($product === null) {
                $skippedConflict++;

                continue;
            }

            $existingSignatures[$signature] = true;
            $createdCount++;
            $firstName ??= $product->name;
        }

        $bits = [];
        if ($createdCount > 0) {
            $bits[] = $createdCount === 1
                ? "Created 1 sellable SKU ({$firstName})."
                : "Created {$createdCount} sellable SKUs.";
        }
        if ($skippedExisting > 0) {
            $bits[] = "{$skippedExisting} combination".($skippedExisting === 1 ? '' : 's').' already had a SKU.';
        }
        if ($skippedConflict > 0) {
            $bits[] = "{$skippedConflict} combination".($skippedConflict === 1 ? '' : 's').' skipped: catalogue SKU already linked elsewhere.';
        }
        if ($bits === []) {
            $bits[] = 'No missing combinations found — every variant combination already has a SKU.';
        }

        return back()->with('status', implode(' ', $bits));
    }

    /**
     * Create sellable SKUs for combinations that use newly added variant values.
     * Uses the same family naming, description, and shared pricing as existing SKUs.
     */
    public function createSkusForNewVariantOptions(Request $request, ProductFamily $family): JsonResponse
    {
        $data = $request->validate([
            'option_ids' => ['required', 'array', 'min:1'],
            'option_ids.*' => ['integer'],
        ]);

        $family->load([
            'brand',
            'catalogueStyle',
            'categoryAssignments',
            'variantGroups.options',
            'products.price',
            'products.variantValues.option',
            'products.variantValues.group',
            'ecommerceProfile',
        ]);

        foreach ($family->variantGroups as $variantGroup) {
            if ($variantGroup->options->isEmpty()) {
                return response()->json([
                    'message' => "Cannot create SKUs: the \"{$variantGroup->name}\" axis has no values yet.",
                ], 422);
            }
        }

        $optionIds = collect($data['option_ids'])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $validOptionIds = ProductVariantOption::query()
            ->whereIn('id', $optionIds)
            ->whereIn('product_variant_group_id', $family->variantGroups->pluck('id'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        if ($validOptionIds->isEmpty()) {
            return response()->json(['message' => 'No valid variant values were selected.'], 422);
        }

        $validOptionIdSet = $validOptionIds->flip();
        $existingSignatures = $this->existingFamilyVariantSignatures($family);
        $defaultOpts = $this->defaultSellableProductOpts($family);
        $createdProducts = [];
        $skippedExisting = 0;
        $skippedConflict = 0;

        foreach ($this->cartesianFamilyVariantCombinations($family) as $combo) {
            $includesNewOption = collect($combo)->contains(
                fn (ProductVariantOption $option): bool => $validOptionIdSet->has((int) $option->id),
            );

            if (! $includesNewOption) {
                continue;
            }

            $signature = collect($combo)
                ->map(fn (ProductVariantOption $o): int => (int) $o->id)
                ->sort()
                ->values()
                ->implode(',');

            if (isset($existingSignatures[$signature])) {
                $skippedExisting++;

                continue;
            }

            $product = $this->createSellableProductForFamily($family, collect($combo), $defaultOpts);

            if ($product === null) {
                $skippedConflict++;

                continue;
            }

            $existingSignatures[$signature] = true;
            $createdProducts[] = [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'url' => route('retail-products.products.show', $product),
            ];
        }

        $createdCount = count($createdProducts);
        $message = match (true) {
            $createdCount === 0 && $skippedExisting > 0 => 'Those sellable products already exist.',
            $createdCount === 1 => "Created 1 sellable SKU ({$createdProducts[0]['name']}).",
            $createdCount > 1 => "Created {$createdCount} sellable SKUs.",
            default => 'No sellable products were created.',
        };

        if ($skippedExisting > 0 && $createdCount > 0) {
            $message .= " {$skippedExisting} combination".($skippedExisting === 1 ? '' : 's').' already existed.';
        }
        if ($skippedConflict > 0) {
            $message .= " {$skippedConflict} skipped (catalogue conflict).";
        }

        return response()->json([
            'message' => $message,
            'created_count' => $createdCount,
            'skipped_existing' => $skippedExisting,
            'skipped_conflict' => $skippedConflict,
            'products' => $createdProducts,
            'variant_option_sellable' => $this->variantOptionSellableMap($family),
        ]);
    }

    /**
     * Create draft sellable SKUs for every variant combination that includes
     * this option value and does not already have a product.
     */
    public function createSkusForVariantOption(ProductFamily $family, ProductVariantOption $option): RedirectResponse
    {
        $group = $this->familyVariantGroup($family, (int) $option->product_variant_group_id);

        $family->load([
            'brand',
            'catalogueStyle',
            'categoryAssignments',
            'variantGroups.options',
            'products.variantValues.option',
            'products.variantValues.group',
        ]);

        foreach ($family->variantGroups as $variantGroup) {
            if ($variantGroup->options->isEmpty()) {
                return back()->with('status', "Cannot create SKUs: the \"{$variantGroup->name}\" axis has no values yet.");
            }
        }

        $combinations = $this->combinationsIncludingVariantOption($family, $option);
        if ($combinations === []) {
            return back()->with('status', 'No variant combinations use this value.');
        }

        $existingSignatures = $this->existingFamilyVariantSignatures($family);
        $createdCount = 0;
        $skippedExisting = 0;
        $skippedConflict = 0;
        $firstName = null;

        foreach ($combinations as $combo) {
            $signature = collect($combo)->map(fn (ProductVariantOption $o): int => (int) $o->id)->sort()->values()->implode(',');
            if (isset($existingSignatures[$signature])) {
                $skippedExisting++;

                continue;
            }

            $product = $this->createSellableProductForFamily(
                $family,
                collect($combo),
                $this->defaultSellableProductOpts($family),
            );

            if ($product === null) {
                $skippedConflict++;

                continue;
            }

            $existingSignatures[$signature] = true;
            $createdCount++;
            $firstName ??= $product->name;
        }

        $bits = [];
        if ($createdCount > 0) {
            $bits[] = $createdCount === 1
                ? "Created 1 sellable SKU for \"{$option->label}\" ({$firstName}). Add barcode, price and photo in the SKU list below."
                : "Created {$createdCount} sellable SKUs for \"{$option->label}\". Add barcode, price and photo in the SKU list below.";
        }
        if ($skippedExisting > 0) {
            $bits[] = "{$skippedExisting} combination".($skippedExisting === 1 ? '' : 's').' already had a SKU.';
        }
        if ($skippedConflict > 0) {
            $bits[] = "{$skippedConflict} combination".($skippedConflict === 1 ? '' : 's').' skipped: catalogue SKU already linked elsewhere.';
        }
        if ($bits === []) {
            $bits[] = "Every combination using \"{$option->label}\" already has a sellable SKU.";
        }

        return redirect()
            ->route('retail-products.families.show', ['family' => $family, 'focus' => 'skus'])
            ->with('status', implode(' ', $bits));
    }

    /**
     * Shared product creator used by both Add sellable SKU and Refresh sellable products.
     *
     * @param  Collection<int, ProductVariantOption>  $selectedOptions
     * @param  array{
     *     name?: ?string,
     *     sku?: ?string,
     *     barcode?: ?string,
     *     retail_price?: numeric|string|null,
     *     base_product?: ?Product,
     *     copy_price?: bool,
     *     strict_catalogue_check?: bool,
     * }  $opts
     */
    private function createSellableProductForFamily(ProductFamily $family, Collection $selectedOptions, array $opts = []): ?Product
    {
        return DB::transaction(function () use ($family, $selectedOptions, $opts): ?Product {
            $name = $this->nullTrim($opts['name'] ?? null)
                ?: $this->generatedRetailProductName($family, $selectedOptions);

            $skuOverride = $this->nullTrim($opts['sku'] ?? null);
            $barcode = $this->nullTrim($opts['barcode'] ?? null);

            $catalogueSku = $this->findOrCreateCatalogueSkuForSelection(
                $family,
                $selectedOptions,
                $name,
                $skuOverride,
                $barcode,
            );

            if ($catalogueSku) {
                $existingCatalogueProduct = Product::query()->where('brand_catalogue_sku_id', $catalogueSku->id)->first();
                if ($existingCatalogueProduct) {
                    if (! empty($opts['strict_catalogue_check'])) {
                        throw ValidationException::withMessages([
                            'variant_options' => "This catalogue SKU is already linked to {$existingCatalogueProduct->name}.",
                        ]);
                    }

                    return null;
                }
            }

            $resolvedSku = $skuOverride !== null
                ? $this->uniqueNullableValue('products', 'sku', $skuOverride)
                : null;

            $baseProduct = $opts['base_product'] ?? null;
            $retailPrice = array_key_exists('retail_price', $opts) ? $opts['retail_price'] : null;
            $copyPrice = $baseProduct && (bool) ($opts['copy_price'] ?? false);
            $basePrice = $copyPrice ? $baseProduct?->price : null;

            $product = Product::query()->create([
                'product_family_id' => $family->id,
                'brand_id' => $family->brand_id,
                'brand_catalogue_sku_id' => $catalogueSku?->id,
                'name' => $name,
                'slug' => $this->uniqueSlug('products', 'slug', $name, null, ['product_family_id' => $family->id]),
                'sku' => $resolvedSku,
                'barcode' => $barcode,
                'receipt_name' => Str::limit($name, 80, ''),
                'inventory_name' => $name,
                'search_keywords' => $this->productSearchKeywords($family, $name, $selectedOptions),
                'description' => CustomerProductDescription::clean($family->description),
                'status' => 'active',
                'is_pos_active' => true,
                'is_ecommerce_active' => true,
                'is_inventory_tracked' => true,
                'sort_order' => ((int) $family->products->max('sort_order')) + 1,
            ]);

            if ($skuOverride === null) {
                $product->forceFill([
                    'sku' => $this->allocatedRetailProductSku($product),
                ])->save();
            }

            foreach ($selectedOptions as $option) {
                ProductVariantValue::query()->create([
                    'product_id' => $product->id,
                    'product_variant_group_id' => $option->product_variant_group_id,
                    'product_variant_option_id' => $option->id,
                ]);
            }

            ProductPrice::query()->updateOrCreate(
                ['product_id' => $product->id],
                [
                    'retail_price' => $retailPrice !== null ? $retailPrice : $basePrice?->retail_price,
                    'cost_price' => array_key_exists('cost_price', $opts)
                        ? $opts['cost_price']
                        : $basePrice?->cost_price,
                    'vat_rate' => array_key_exists('vat_rate', $opts)
                        ? $opts['vat_rate']
                        : $basePrice?->vat_rate,
                    'currency' => 'GBP',
                    'tax_class' => 'standard',
                ],
            );

            $locationId = ! empty($opts['inventory_location_id'])
                ? (int) $opts['inventory_location_id']
                : $this->defaultInventoryLocation()->id;
            $inventoryPayload = [
                'stock_quantity' => array_key_exists('stock_quantity', $opts) ? (float) $opts['stock_quantity'] : 0,
                'low_stock_threshold' => null,
                'reorder_quantity' => null,
            ];
            if (! empty($opts['inventory_section_id'])) {
                $inventoryPayload['inventory_section_id'] = (int) $opts['inventory_section_id'];
            }
            if (array_key_exists('shelf_location', $opts)) {
                $inventoryPayload['shelf_location'] = $this->nullTrim($opts['shelf_location']);
            }
            if (array_key_exists('supplier', $opts)) {
                $inventoryPayload['supplier'] = $this->nullTrim($opts['supplier']);
            }
            if (array_key_exists('supplier_product_code', $opts)) {
                $inventoryPayload['supplier_product_code'] = $this->nullTrim($opts['supplier_product_code']);
            }

            $product->inventoryLevels()->updateOrCreate(
                ['inventory_location_id' => $locationId],
                $inventoryPayload,
            );

            ProductPosProfile::query()->updateOrCreate(
                ['product_id' => $product->id],
                [
                    'receipt_name' => Str::limit($name, 80, ''),
                    'quick_search_keywords' => $product->search_keywords,
                    'pos_category' => $family->root_catalogue_name,
                    'discount_allowed' => true,
                    'quick_sale_enabled' => true,
                    'tax_class' => 'standard',
                ],
            );

            ProductEcommerceProfile::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'profile_level' => 'sku',
                ],
                [
                    'product_family_id' => $family->id,
                    'online_title' => $name,
                    'short_description' => $product->description ? Str::limit($product->description, 180) : null,
                    'long_description' => $product->description,
                    'seo_slug' => $product->slug,
                    'seo_title' => $name,
                    'seo_description' => $product->description ? Str::limit($product->description, 155) : null,
                    'tags' => array_values(array_filter([$family->brand_name, $family->line_name, $family->product_type_name, $family->family_name])),
                    'is_published' => true,
                    'click_and_collect_enabled' => true,
                ],
            );

            $this->copyFamilyCategoryAssignmentsToProduct($family, $product);
            $this->recordManualProductSource($family, $product, $catalogueSku);

            $family->setRelation('products', $family->products->push($product));

            return $product;
        });
    }

    /**
     * Build the full cartesian product of all variant axes' options for a family.
     *
     * @return list<list<ProductVariantOption>>
     */
    private function cartesianFamilyVariantCombinations(ProductFamily $family): array
    {
        $combinations = [[]];

        foreach ($family->variantGroups as $group) {
            $next = [];
            foreach ($combinations as $combo) {
                foreach ($group->options as $option) {
                    $option->setRelation('group', $group);
                    $next[] = array_merge($combo, [$option]);
                }
            }
            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * Map of existing variant-option signatures (comma-separated sorted ids)
     * for every product in this family, used to skip already-present combos.
     *
     * @return array<string, true>
     */
    private function existingFamilyVariantSignatures(ProductFamily $family): array
    {
        $signatures = [];
        foreach ($family->products as $product) {
            $signature = $product->variantValues
                ->pluck('product_variant_option_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->implode(',');
            $signatures[$signature] = true;
        }

        return $signatures;
    }

    /**
     * Count the variant combinations that don't yet have a sellable SKU.
     */
    private function countMissingFamilyCombos(ProductFamily $family): int
    {
        if ($family->variantGroups->isEmpty()) {
            return 0;
        }

        foreach ($family->variantGroups as $group) {
            if ($group->options->isEmpty()) {
                return 0;
            }
        }

        $existingSignatures = $this->existingFamilyVariantSignatures($family);
        $missing = 0;

        foreach ($this->cartesianFamilyVariantCombinations($family) as $combo) {
            $signature = collect($combo)
                ->map(fn (ProductVariantOption $option): int => (int) $option->id)
                ->sort()
                ->values()
                ->implode(',');

            if (! isset($existingSignatures[$signature])) {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * Per variant-option counts of combinations still missing a sellable SKU.
     *
     * @return array<int, array{missing: int, combo_total: int}>
     */
    private function variantOptionSellableMap(ProductFamily $family): array
    {
        if ($family->variantGroups->isEmpty()) {
            return [];
        }

        $existingSignatures = $this->existingFamilyVariantSignatures($family);
        $map = [];

        foreach ($family->variantGroups as $group) {
            foreach ($group->options as $option) {
                $missing = 0;
                $comboTotal = 0;

                foreach ($this->combinationsIncludingVariantOption($family, $option) as $combo) {
                    $comboTotal++;
                    $signature = collect($combo)
                        ->map(fn (ProductVariantOption $o): int => (int) $o->id)
                        ->sort()
                        ->values()
                        ->implode(',');

                    if (! isset($existingSignatures[$signature])) {
                        $missing++;
                    }
                }

                $map[(int) $option->id] = [
                    'missing' => $missing,
                    'combo_total' => $comboTotal,
                ];
            }
        }

        return $map;
    }

    /**
     * @return list<list<ProductVariantOption>>
     */
    private function combinationsIncludingVariantOption(ProductFamily $family, ProductVariantOption $option): array
    {
        $optionId = (int) $option->id;

        return array_values(array_filter(
            $this->cartesianFamilyVariantCombinations($family),
            fn (array $combo): bool => collect($combo)->contains(
                fn (ProductVariantOption $o): bool => (int) $o->id === $optionId,
            ),
        ));
    }

    private function countMissingCombosForVariantOption(ProductFamily $family, ProductVariantOption $option): int
    {
        return $this->variantOptionSellableMap($family)[(int) $option->id]['missing'] ?? 0;
    }

    /**
     * Defaults for auto-created sellable SKUs: family description, shared price, categories.
     *
     * @return array{
     *     strict_catalogue_check: bool,
     *     base_product?: Product,
     *     copy_price?: bool,
     *     retail_price?: float|string|null,
     * }
     */
    private function defaultSellableProductOpts(ProductFamily $family): array
    {
        $family->loadMissing(['products.price', 'products.inventoryLevels']);

        $opts = ['strict_catalogue_check' => false];

        $baseProduct = $family->products->first(
            fn (Product $product): bool => $product->price?->retail_price !== null,
        ) ?? $family->products->first();

        if ($baseProduct) {
            $opts['base_product'] = $baseProduct;
            $opts['copy_price'] = true;
        }

        $sharedRetail = $this->sharedDecimalValue(
            $family->products,
            fn (Product $product) => $product->price?->retail_price,
        );
        $sharedCost = $this->sharedDecimalValue(
            $family->products,
            fn (Product $product) => $product->price?->cost_price,
        );
        $sharedVat = $this->sharedDecimalValue(
            $family->products,
            fn (Product $product) => $product->price?->vat_rate,
        );
        $sharedStock = $this->sharedDecimalValue(
            $family->products,
            fn (Product $product) => $product->inventoryLevels->sum('stock_quantity'),
        );

        if ($sharedRetail !== null) {
            $opts['retail_price'] = $sharedRetail;
        }
        if ($sharedCost !== null) {
            $opts['cost_price'] = $sharedCost;
        }
        if ($sharedVat !== null) {
            $opts['vat_rate'] = $sharedVat;
        }
        if ($sharedStock !== null) {
            $opts['stock_quantity'] = $sharedStock;
        }

        $baseLevel = $baseProduct?->inventoryLevels->first();
        if ($baseLevel) {
            if ($baseLevel->inventory_location_id) {
                $opts['inventory_location_id'] = (int) $baseLevel->inventory_location_id;
            }
            if ($baseLevel->inventory_section_id) {
                $opts['inventory_section_id'] = (int) $baseLevel->inventory_section_id;
            }
            if ($this->nullTrim($baseLevel->shelf_location) !== null) {
                $opts['shelf_location'] = $baseLevel->shelf_location;
            }
            if ($this->nullTrim($baseLevel->supplier) !== null) {
                $opts['supplier'] = $baseLevel->supplier;
            }
            if ($this->nullTrim($baseLevel->supplier_product_code) !== null) {
                $opts['supplier_product_code'] = $baseLevel->supplier_product_code;
            }
        }

        return $opts;
    }

    public function updateFamilySharedDetails(Request $request, ProductFamily $family): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'apply_department' => ['nullable', 'boolean'],
            'department' => ['nullable', 'string', 'max:255'],
            'apply_product_type' => ['nullable', 'boolean'],
            'product_type' => ['nullable', 'string', 'max:255'],
            'apply_retail_price' => ['nullable', 'boolean'],
            'retail_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'apply_cost_price' => ['nullable', 'boolean'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'apply_vat_rate' => ['nullable', 'boolean'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'apply_stock_quantity' => ['nullable', 'boolean'],
            'stock_quantity' => ['nullable', 'numeric', 'min:-999999.99', 'max:999999.99'],
            'apply_shelf_location' => ['nullable', 'boolean'],
            'shelf_location' => ['nullable', 'string', 'max:255'],
            'apply_inventory_location' => ['nullable', 'boolean'],
            'inventory_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'inventory_section_id' => ['nullable', 'integer', 'exists:inventory_sections,id'],
            'apply_supplier' => ['nullable', 'boolean'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'apply_supplier_product_code' => ['nullable', 'boolean'],
            'supplier_product_code' => ['nullable', 'string', 'max:255'],
            'apply_description' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:20000'],
            'apply_is_pos_active' => ['nullable', 'boolean'],
            'is_pos_active' => ['nullable', 'boolean'],
            'apply_is_ecommerce_active' => ['nullable', 'boolean'],
            'is_ecommerce_active' => ['nullable', 'boolean'],
            'apply_is_inventory_tracked' => ['nullable', 'boolean'],
            'is_inventory_tracked' => ['nullable', 'boolean'],
        ]);

        $priceFields = [
            'retail_price' => 'retail_price',
            'cost_price' => 'cost_price',
            'vat_rate' => 'vat_rate',
        ];
        $inventoryFields = [
            'stock_quantity' => 'stock_quantity',
            'shelf_location' => 'shelf_location',
            'supplier' => 'supplier',
            'supplier_product_code' => 'supplier_product_code',
        ];

        // Resolve the target inventory location for the apply-location action.
        // If the user explicitly chose a location, we use that as the upsert key
        // (creating or updating that specific location row rather than the default).
        $applyLocation = $request->boolean('apply_inventory_location')
            && ! empty($data['inventory_location_id']);

        $targetLocationId = null;
        $locationUpdates = [];
        if ($applyLocation) {
            $chosenLocation = InventoryLocation::find((int) $data['inventory_location_id']);
            if ($chosenLocation) {
                $targetLocationId = $chosenLocation->id;
                $locationUpdates['inventory_location_id'] = $chosenLocation->id;

                // Section must belong to the chosen location; clear it if not.
                $sectionId = ! empty($data['inventory_section_id']) ? (int) $data['inventory_section_id'] : null;
                if ($sectionId) {
                    $sectionOk = InventorySection::where('id', $sectionId)
                        ->where('inventory_location_id', $chosenLocation->id)
                        ->exists();
                    $locationUpdates['inventory_section_id'] = $sectionOk ? $sectionId : null;
                } else {
                    $locationUpdates['inventory_section_id'] = null;
                }
            }
        }
        $productFields = [
            'is_pos_active' => 'is_pos_active',
            'is_ecommerce_active' => 'is_ecommerce_active',
            'is_inventory_tracked' => 'is_inventory_tracked',
        ];

        $priceUpdates = [];
        foreach ($priceFields as $field) {
            if ($request->boolean('apply_'.$field)) {
                $priceUpdates[$field] = $data[$field] ?? null;
            }
        }

        $inventoryUpdates = [];
        foreach ($inventoryFields as $field) {
            if ($request->boolean('apply_'.$field)) {
                $inventoryUpdates[$field] = $field === 'stock_quantity'
                    ? ($data[$field] ?? 0)
                    : $this->nullTrim($data[$field] ?? null);
            }
        }

        $productUpdates = [];
        foreach ($productFields as $field) {
            if ($request->boolean('apply_'.$field)) {
                $productUpdates[$field] = (bool) ($data[$field] ?? false);
            }
        }

        $familyStructureUpdates = [];
        if ($request->boolean('apply_department')) {
            $familyStructureUpdates['root_catalogue_name'] = $this->nullTrim($data['department'] ?? null);
        }
        if ($request->boolean('apply_product_type')) {
            $familyStructureUpdates['product_type_name'] = $this->nullTrim($data['product_type'] ?? null);
        }

        $descriptionApplied = $request->boolean('apply_description');
        $sharedDescription = $descriptionApplied ? CustomerProductDescription::clean($data['description'] ?? null) : null;
        if ($descriptionApplied) {
            $productUpdates['description'] = $sharedDescription;
            $family->forceFill(['description' => $sharedDescription])->save();
            $existingFamilyProfile = $family->ecommerceProfile;
            $family->ecommerceProfile()->updateOrCreate(
                [
                    'product_family_id' => $family->id,
                    'profile_level' => 'family',
                ],
                [
                    'online_title' => $family->family_name,
                    'short_description' => $sharedDescription ? str($sharedDescription)->limit(180)->toString() : null,
                    'long_description' => $sharedDescription,
                    'seo_slug' => $family->slug,
                    'seo_title' => $family->family_name,
                    'seo_description' => $sharedDescription ? str($sharedDescription)->limit(155)->toString() : null,
                    'is_published' => (bool) ($existingFamilyProfile?->is_published ?? false),
                    'click_and_collect_enabled' => true,
                ],
            );
        }

        if ($familyStructureUpdates !== []) {
            $family->fill($familyStructureUpdates)->save();
            $family->ecommerceProfile()->updateOrCreate(
                [
                    'product_family_id' => $family->id,
                    'profile_level' => 'family',
                ],
                [
                    'online_title' => $family->family_name,
                    'short_description' => $family->description ? str($family->description)->limit(180)->toString() : null,
                    'long_description' => $family->description,
                    'seo_slug' => $family->slug,
                    'seo_title' => $family->family_name,
                    'seo_description' => $family->description ? str($family->description)->limit(155)->toString() : null,
                    'tags' => array_values(array_filter([$family->brand_name, $family->line_name, $family->product_type_name, $family->root_catalogue_name])),
                    'is_published' => (bool) ($family->ecommerceProfile?->is_published ?? false),
                    'click_and_collect_enabled' => true,
                ],
            );
        }

        if ($priceUpdates === [] && $inventoryUpdates === [] && $productUpdates === [] && $familyStructureUpdates === [] && ! $applyLocation) {
            throw ValidationException::withMessages([
                'shared_details' => 'Choose at least one family value to apply.',
            ]);
        }

        $products = $family->products()->with('family')->get();
        $defaultLocation = ($inventoryUpdates !== [] || $applyLocation) ? $this->defaultInventoryLocation() : null;

        foreach ($products as $product) {
            if ($productUpdates !== []) {
                $product->fill($productUpdates);
                $product->status = ($product->is_pos_active || $product->is_ecommerce_active) ? 'active' : 'draft';
                $product->save();
            }

            if ($priceUpdates !== []) {
                $product->price()->updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'currency' => 'GBP',
                        'tax_class' => 'standard',
                    ] + $priceUpdates,
                );
            }

            if ($applyLocation && $targetLocationId) {
                // When moving to a new location: update the existing default level's FK
                // (or create a fresh level at the chosen location).
                $existingLevel = $product->inventoryLevels()->first();
                if ($existingLevel) {
                    $existingLevel->update($locationUpdates + $inventoryUpdates);
                } else {
                    $product->inventoryLevels()->create(
                        $locationUpdates + $inventoryUpdates + ['stock_quantity' => 0],
                    );
                }
            } elseif ($inventoryUpdates !== [] && $defaultLocation) {
                $product->inventoryLevels()->updateOrCreate(
                    ['inventory_location_id' => $defaultLocation->id],
                    $inventoryUpdates,
                );
            }

            if (array_key_exists('is_pos_active', $productUpdates)) {
                $product->posProfile()->updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'receipt_name' => $product->receipt_name ?: $product->name,
                        'quick_search_keywords' => $product->search_keywords,
                        'pos_category' => $family->root_catalogue_name,
                        'discount_allowed' => true,
                        'quick_sale_enabled' => true,
                        'tax_class' => 'standard',
                    ],
                );
            }

            if ($familyStructureUpdates !== [] && ! array_key_exists('is_pos_active', $productUpdates) && $product->posProfile) {
                $product->posProfile()->update([
                    'pos_category' => $family->root_catalogue_name,
                ]);
            }

            if (array_key_exists('is_ecommerce_active', $productUpdates) || array_key_exists('description', $productUpdates)) {
                $isEcommerceActive = $product->fresh()->is_ecommerce_active;
                $product->ecommerceProfile()->updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'profile_level' => 'sku',
                    ],
                    [
                        'product_family_id' => $product->product_family_id,
                        'online_title' => $product->name,
                        'short_description' => $product->description ? str($product->description)->limit(180)->toString() : null,
                        'long_description' => $product->description,
                        'seo_slug' => $product->slug,
                        'seo_title' => $product->name,
                        'seo_description' => $product->description ? str($product->description)->limit(155)->toString() : null,
                        'is_published' => $isEcommerceActive,
                        'click_and_collect_enabled' => true,
                    ],
                );
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Family details saved.',
                'updated_count' => $products->count(),
            ]);
        }

        return back()->with('status', 'Applied shared family details to '.$products->count().' sellable product'.($products->count() === 1 ? '' : 's').'.');
    }

    public function updateFamilyVariantPricing(Request $request, ProductFamily $family): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'variant_options' => ['nullable', 'array'],
            'variant_options.*' => ['nullable'],
            'apply_retail_price' => ['nullable', 'boolean'],
            'retail_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'apply_cost_price' => ['nullable', 'boolean'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'apply_vat_rate' => ['nullable', 'boolean'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $filtersByGroup = $this->variantPricingFiltersByGroup($family, $data['variant_options'] ?? []);

        $priceUpdates = [
            'currency' => 'GBP',
            'tax_class' => 'standard',
        ];

        foreach (['retail_price', 'cost_price', 'vat_rate'] as $field) {
            if ($request->boolean('apply_'.$field)) {
                $priceUpdates[$field] = $data[$field] ?? null;
            }
        }

        if (count($priceUpdates) === 2) {
            throw ValidationException::withMessages([
                'variant_pricing' => 'Choose at least one price field to apply.',
            ]);
        }

        $products = $this->productsMatchingVariantPricingFilters(
            $family->products()->with('variantValues')->get(),
            $filtersByGroup,
        );

        if ($products->isEmpty()) {
            throw ValidationException::withMessages([
                'variant_options' => 'No sellable products match that variant selection.',
            ]);
        }

        foreach ($products as $product) {
            $product->price()->updateOrCreate(
                ['product_id' => $product->id],
                $priceUpdates,
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Variant pricing applied.',
                'updated_count' => $products->count(),
            ]);
        }

        return back()->with('status', 'Applied variant pricing to '.$products->count().' sellable product'.($products->count() === 1 ? '' : 's').'.');
    }

    /**
     * @param  array<int|string, mixed>  $rawVariantOptions
     * @return array<int, Collection<int, int>>
     */
    private function variantPricingFiltersByGroup(ProductFamily $family, array $rawVariantOptions): array
    {
        $family->loadMissing('variantGroups.options');

        $validOptionIds = $family->variantGroups
            ->flatMap(fn (ProductVariantGroup $group) => $group->options->pluck('id'))
            ->map(fn ($id): int => (int) $id);

        $filtersByGroup = [];

        foreach ($family->variantGroups as $group) {
            $raw = $rawVariantOptions[$group->id] ?? $rawVariantOptions[(string) $group->id] ?? null;
            $optionIds = collect(is_array($raw) ? $raw : [$raw])
                ->filter(fn ($value): bool => filled($value))
                ->map(fn ($value): int => (int) $value)
                ->unique()
                ->values();

            if ($optionIds->diff($validOptionIds)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'variant_options' => 'One of the selected variant values does not belong to this family.',
                ]);
            }

            if ($optionIds->isNotEmpty()) {
                $filtersByGroup[(int) $group->id] = $optionIds;
            }
        }

        if ($filtersByGroup === []) {
            throw ValidationException::withMessages([
                'variant_options' => 'Choose at least one variant value, or use All on an axis (e.g. every Colour).',
            ]);
        }

        return $filtersByGroup;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array<int, Collection<int, int>>  $filtersByGroup
     * @return Collection<int, Product>
     */
    private function productsMatchingVariantPricingFilters(Collection $products, array $filtersByGroup): Collection
    {
        return $products->filter(function (Product $product) use ($filtersByGroup): bool {
            foreach ($filtersByGroup as $groupId => $optionIds) {
                $productOptionId = (int) ($product->variantValues
                    ->firstWhere('product_variant_group_id', (int) $groupId)
                    ?->product_variant_option_id ?? 0);

                if (! $optionIds->contains($productOptionId)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Add a new variant axis to this family, e.g. Length, Colour, Pack Count.
     * Existing SKUs are left untouched; new SKUs can then be created from the
     * new grouped combination.
     */
    public function storeFamilyVariantGroup(Request $request, ProductFamily $family): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'variant_type' => ['required', 'string', 'max:100'],
            'new_variant_type_name' => ['nullable', 'string', 'max:255'],
        ]);

        $name = trim(preg_replace('/\s+/', ' ', (string) $data['name']));
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Enter a variant group name.',
            ]);
        }

        $duplicate = ProductVariantGroup::query()
            ->where('product_family_id', $family->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => "\"{$duplicate->name}\" already exists on this family.",
            ]);
        }

        $variantType = $this->resolveVariantGroupTypeSlug(
            (string) $data['variant_type'],
            $data['new_variant_type_name'] ?? null,
        );

        ProductVariantGroup::query()->create([
            'product_family_id' => $family->id,
            'name' => $name,
            'variant_type' => $variantType,
            'sort_order' => ((int) ProductVariantGroup::query()
                ->where('product_family_id', $family->id)
                ->max('sort_order')) + 10,
        ]);

        return redirect()
            ->to(route('retail-products.families.show', $family).'#rfm-variant-model')
            ->with('status', "Added variant group \"{$name}\". Add values, then create SKUs from the combination.");
    }

    /**
     * Remove an unused variant axis from this family.
     * Refuses deletion when any SKU still stores a value for the group.
     */
    public function destroyFamilyVariantGroup(ProductFamily $family, ProductVariantGroup $group): RedirectResponse
    {
        $group = $this->familyVariantGroup($family, (int) $group->id);

        $inUseCount = ProductVariantValue::query()
            ->where('product_variant_group_id', $group->id)
            ->count();

        if ($inUseCount > 0) {
            throw ValidationException::withMessages([
                'variant_group' => "Cannot delete \"{$group->name}\" because {$inUseCount} sellable SKU"
                    .($inUseCount === 1 ? ' uses' : 's use').' it. Remove or edit those SKUs first.',
            ]);
        }

        $name = $group->name;
        $optionCount = $group->options()->count();
        $group->delete();

        return redirect()
            ->to(route('retail-products.families.show', $family).'#rfm-variant-model')
            ->with('status', "Removed variant group \"{$name}\" and {$optionCount} unused value".($optionCount === 1 ? '' : 's').'.');
    }

    /**
     * Add a new option to one of this family's variant groups.
     * Used by the inline "manage values" UI inside the Add sellable SKU panel.
     */
    public function storeFamilyVariantOption(Request $request, ProductFamily $family): JsonResponse
    {
        $data = $request->validate([
            'product_variant_group_id' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:255'],
            'value' => ['nullable', 'string', 'max:255'],
        ]);

        $group = $this->familyVariantGroup($family, (int) $data['product_variant_group_id']);
        $result = $this->createFamilyVariantOption(
            $family,
            $group,
            trim($data['label']),
            $this->nullTrim($data['value'] ?? null),
            rejectDuplicates: true,
        );

        return response()->json([
            'message' => "Added \"{$result['option']->label}\" to {$group->name}.",
            'option' => $result['option_payload'],
            'sellable' => $result['sellable'],
        ]);
    }

    /**
     * Add several variant option labels at once (comma-separated intake-style entry).
     */
    public function storeFamilyVariantOptionsBulk(Request $request, ProductFamily $family): JsonResponse
    {
        $data = $request->validate([
            'product_variant_group_id' => ['required', 'integer'],
            'labels' => ['required', 'array', 'min:1', 'max:40'],
            'labels.*' => ['required', 'string', 'max:255'],
        ]);

        $group = $this->familyVariantGroup($family, (int) $data['product_variant_group_id']);
        $created = [];
        $skipped = [];

        foreach ($this->uniqueVariantLabels($data['labels']) as $label) {
            $result = $this->createFamilyVariantOption($family, $group, $label, null, rejectDuplicates: false);
            if ($result['created']) {
                $created[] = $result;
            } else {
                $skipped[] = $result['option']->label;
            }
        }

        $message = match (true) {
            count($created) === 0 && count($skipped) > 0 => 'Those values already exist in '.$group->name.'.',
            count($created) === 1 => "Added \"{$created[0]['option']->label}\" to {$group->name}.",
            count($created) > 1 => 'Added '.count($created).' values to '.$group->name.'.',
            default => 'No new values to add.',
        };

        if (count($skipped) > 0 && count($created) > 0) {
            $message .= ' '.count($skipped).' already existed.';
        }

        return response()->json([
            'message' => $message,
            'created' => array_map(fn (array $row): array => [
                'option' => $row['option_payload'],
                'sellable' => $row['sellable'],
            ], $created),
            'skipped' => array_values($skipped),
        ]);
    }

    /**
     * @return array{
     *     option: ProductVariantOption,
     *     created: bool,
     *     option_payload: array<string, mixed>,
     *     sellable: array{missing: int, create_url: string},
     * }
     */
    private function createFamilyVariantOption(
        ProductFamily $family,
        ProductVariantGroup $group,
        string $rawLabel,
        ?string $rawValue = null,
        bool $rejectDuplicates = false,
    ): array {
        $label = HairExtensionLengthLabel::normalizeForHairExtensionLength(
            (string) $family->root_catalogue_name,
            $group->name,
            $rawLabel,
        );
        $value = $rawValue;
        if ($value !== null) {
            $value = HairExtensionLengthLabel::normalizeForHairExtensionLength(
                (string) $family->root_catalogue_name,
                $group->name,
                $value,
            );
        }

        if ($label === '') {
            throw ValidationException::withMessages(['label' => 'Enter a label for the new value.']);
        }

        $duplicate = ProductVariantOption::query()
            ->where('product_variant_group_id', $group->id)
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
            ->first();

        if ($duplicate) {
            if ($rejectDuplicates) {
                throw ValidationException::withMessages([
                    'label' => "\"{$duplicate->label}\" already exists in {$group->name}.",
                ]);
            }

            $family->load([
                'variantGroups.options',
                'products.variantValues.option',
                'products.variantValues.group',
            ]);

            return [
                'option' => $duplicate,
                'created' => false,
                'option_payload' => $this->variantOptionPayload($duplicate),
                'sellable' => $this->variantOptionSellablePayload($family, $duplicate),
            ];
        }

        $sortOrder = (int) ProductVariantOption::query()
            ->where('product_variant_group_id', $group->id)
            ->max('sort_order');

        $option = ProductVariantOption::create([
            'product_variant_group_id' => $group->id,
            'label' => $label,
            'value' => $value ?? $label,
            'sort_order' => $sortOrder + 1,
        ]);

        $family->load([
            'variantGroups.options',
            'products.variantValues.option',
            'products.variantValues.group',
        ]);

        return [
            'option' => $option,
            'created' => true,
            'option_payload' => $this->variantOptionPayload($option),
            'sellable' => $this->variantOptionSellablePayload($family, $option),
        ];
    }

    /**
     * @return array{id: int, product_variant_group_id: int, label: string, value: string, sort_order: int}
     */
    private function variantOptionPayload(ProductVariantOption $option): array
    {
        return [
            'id' => $option->id,
            'product_variant_group_id' => $option->product_variant_group_id,
            'label' => $option->label,
            'value' => $option->value,
            'sort_order' => $option->sort_order,
        ];
    }

    /**
     * @return array{missing: int, create_url: string}
     */
    private function variantOptionSellablePayload(ProductFamily $family, ProductVariantOption $option): array
    {
        return [
            'missing' => $this->countMissingCombosForVariantOption($family, $option),
            'create_url' => route('retail-products.families.variant-options.create-skus', [$family, $option]),
        ];
    }

    /**
     * @param  array<int, string>  $labels
     * @return list<string>
     */
    private function uniqueVariantLabels(array $labels): array
    {
        $seen = [];
        $unique = [];

        foreach ($labels as $label) {
            $normalized = trim((string) $label);
            if ($normalized === '') {
                continue;
            }
            $key = mb_strtolower($normalized);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $normalized;
        }

        return $unique;
    }

    /**
     * Rename an existing variant option that belongs to this family.
     */
    public function updateFamilyVariantOption(Request $request, ProductFamily $family, ProductVariantOption $option): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'value' => ['nullable', 'string', 'max:255'],
        ]);

        $group = $this->familyVariantGroup($family, (int) $option->product_variant_group_id);
        $label = HairExtensionLengthLabel::normalizeForHairExtensionLength(
            (string) $family->root_catalogue_name,
            $group->name,
            trim($data['label']),
        );
        $value = $this->nullTrim($data['value'] ?? null);
        if ($value !== null) {
            $value = HairExtensionLengthLabel::normalizeForHairExtensionLength(
                (string) $family->root_catalogue_name,
                $group->name,
                $value,
            );
        }

        if ($label === '') {
            throw ValidationException::withMessages(['label' => 'Enter a label.']);
        }

        $duplicate = ProductVariantOption::query()
            ->where('product_variant_group_id', $group->id)
            ->whereKeyNot($option->id)
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
            ->first();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'label' => "\"{$duplicate->label}\" already exists in {$group->name}.",
            ]);
        }

        $option->update([
            'label' => $label,
            'value' => $value ?? $label,
        ]);

        return response()->json([
            'message' => "Renamed to \"{$option->label}\".",
            'option' => [
                'id' => $option->id,
                'product_variant_group_id' => $option->product_variant_group_id,
                'label' => $option->label,
                'value' => $option->value,
                'sort_order' => $option->sort_order,
            ],
        ]);
    }

    /**
     * Delete a variant option that belongs to this family.
     * Refuses if any sellable product still references the option.
     */
    public function destroyFamilyVariantOption(ProductFamily $family, ProductVariantOption $option): JsonResponse
    {
        $group = $this->familyVariantGroup($family, (int) $option->product_variant_group_id);

        $inUseCount = ProductVariantValue::query()
            ->where('product_variant_option_id', $option->id)
            ->count();

        if ($inUseCount > 0) {
            throw ValidationException::withMessages([
                'option' => "Cannot delete: \"{$option->label}\" is still used by {$inUseCount} sellable product"
                    .($inUseCount === 1 ? '' : 's').'.',
            ]);
        }

        $label = $option->label;
        $option->delete();

        return response()->json([
            'message' => "Removed \"{$label}\" from {$group->name}.",
            'deleted_option_id' => $option->id,
        ]);
    }

    /**
     * Resolve a variant group by id and ensure it belongs to this family.
     */
    private function familyVariantGroup(ProductFamily $family, int $groupId): ProductVariantGroup
    {
        $group = ProductVariantGroup::query()
            ->where('id', $groupId)
            ->where('product_family_id', $family->id)
            ->first();

        if (! $group) {
            throw ValidationException::withMessages([
                'product_variant_group_id' => 'That variant axis does not belong to this family.',
            ]);
        }

        return $group;
    }

    public function suggestFamilyNaming(Request $request, ProductFamily $family, OpenAiRetailNamingService $naming): JsonResponse
    {
        $data = $request->validate([
            'ai_model' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $naming->suggest($family, $data['ai_model'] ?? null);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'AI naming suggestions ready.',
            'result' => $result,
        ]);
    }

    /**
     * Ask Gemini (via OpenRouter + web search) to write a customer-facing
     * ecommerce description for this family, stripping any references to other
     * retailers and returning clean copy ready to be applied to all SKUs.
     */
    public function suggestFamilyDescription(Request $request, ProductFamily $family, RetailDescriptionWriterService $writer): JsonResponse
    {
        $data = $request->validate([
            'source_url' => ['nullable', 'string', 'max:2048', 'url'],
        ]);

        $family->loadMissing(['variantGroups.options']);

        $variantSamples = [];
        foreach ($family->variantGroups as $group) {
            $variantSamples[$group->name] = $group->options->pluck('label')->take(6)->all();
        }

        $result = $writer->suggest([
            'brand_name' => $family->brand_name,
            'family_name' => $family->family_name,
            'line_name' => $family->line_name,
            'product_type' => $family->product_type_name,
            'department' => $family->root_catalogue_name,
            'variant_axes' => $family->variantGroups->pluck('name')->all(),
            'variant_samples' => $variantSamples,
            'source_url' => $this->nullTrim($data['source_url'] ?? null) ?? $family->source_url,
            'existing_description' => $family->description,
        ]);

        return response()->json([
            'message' => 'AI description ready.',
            'description' => $result['description'],
            'confidence' => $result['confidence'],
            'used_search' => $result['used_search'],
            'source_urls' => $result['source_urls'],
            'notes' => $result['notes'],
            'model' => $result['model'],
        ]);
    }

    public function applyFamilyNaming(Request $request, ProductFamily $family): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'suggestions' => ['required', 'array', 'min:1'],
            'suggestions.*.product_id' => ['required', 'integer'],
            'suggestions.*.receipt_name' => ['nullable', 'string', 'max:35'],
            'suggestions.*.inventory_name' => ['nullable', 'string', 'max:80'],
            'suggestions.*.ecommerce_title' => ['nullable', 'string', 'max:150'],
        ]);

        $products = $family->products()->with('ecommerceProfile', 'posProfile')->get()->keyBy('id');
        $updated = 0;

        foreach ($data['suggestions'] as $row) {
            $product = $products->get((int) $row['product_id']);
            if (! $product) {
                throw ValidationException::withMessages([
                    'suggestions' => 'One of the selected suggestions does not belong to this family.',
                ]);
            }

            $receiptName = $this->nullTrim($row['receipt_name'] ?? null);
            $inventoryName = $this->nullTrim($row['inventory_name'] ?? null);
            $ecommerceTitle = $this->nullTrim($row['ecommerce_title'] ?? null);

            $product->fill([
                'receipt_name' => $receiptName,
                'inventory_name' => $inventoryName,
            ]);
            $product->save();

            $product->posProfile()->updateOrCreate(
                ['product_id' => $product->id],
                [
                    'receipt_name' => $receiptName ?: $inventoryName ?: $product->name,
                    'quick_search_keywords' => $product->search_keywords,
                    'pos_category' => $product->family?->root_catalogue_name,
                    'discount_allowed' => true,
                    'quick_sale_enabled' => true,
                    'tax_class' => 'standard',
                ],
            );

            $product->ecommerceProfile()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'profile_level' => 'sku',
                ],
                [
                    'product_family_id' => $product->product_family_id,
                    'online_title' => $ecommerceTitle ?: $product->name,
                    'short_description' => $product->description ? str($product->description)->limit(180)->toString() : null,
                    'long_description' => $product->description,
                    'seo_slug' => $product->slug,
                    'seo_title' => $ecommerceTitle ?: $product->name,
                    'seo_description' => $product->description ? str($product->description)->limit(155)->toString() : null,
                    'is_published' => (bool) $product->is_ecommerce_active,
                    'click_and_collect_enabled' => true,
                ],
            );

            $updated += 1;
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'AI naming applied.',
                'updated_count' => $updated,
            ]);
        }

        return back()->with('status', 'Applied AI naming to '.$updated.' sellable product'.($updated === 1 ? '' : 's').'.');
    }

    public function updateFamilyDisplayName(Request $request, ProductFamily $family): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'apply_to_matching_sellables' => ['nullable', 'boolean'],
        ]);

        $displayName = $this->nullTrim($data['display_name']);
        if ($displayName === null || $displayName === '') {
            throw ValidationException::withMessages([
                'display_name' => 'Enter a product name.',
            ]);
        }

        $applyToMatchingSellables = $request->boolean('apply_to_matching_sellables');
        $family->load(['catalogueStyle', 'variantGroups', 'ecommerceProfile']);

        $referencePrice = $this->sharedDecimalValue(
            $family->products()->with('price')->get(),
            fn (Product $product) => $product->price?->retail_price,
        );

        $sellablesUpdated = 0;

        DB::transaction(function () use ($family, $displayName, $applyToMatchingSellables, $referencePrice, &$sellablesUpdated): void {
            $this->applyFamilyDisplayName($family, $displayName);
            $family->refresh();

            if (! $applyToMatchingSellables) {
                return;
            }

            $structureKey = $this->familyVariantStructureKey($family);
            $baseName = $this->familyDisplayBaseName($family);

            $candidateFamilies = $referencePrice === null
                ? collect([$family->load([
                    'products.price',
                    'products.variantValues.option',
                    'products.variantValues.group',
                    'products.ecommerceProfile',
                ])])
                : ProductFamily::query()
                    ->where('brand_id', $family->brand_id)
                    ->with([
                        'variantGroups',
                        'products.price',
                        'products.variantValues.option',
                        'products.variantValues.group',
                        'products.ecommerceProfile',
                    ])
                    ->get()
                    ->filter(fn (ProductFamily $candidate): bool => $this->familyVariantStructureKey($candidate) === $structureKey)
                    ->filter(function (ProductFamily $candidate) use ($referencePrice): bool {
                        $candidateSharedPrice = $this->sharedDecimalValue(
                            $candidate->products,
                            fn (Product $product) => $product->price?->retail_price,
                        );

                        return $candidateSharedPrice === $referencePrice;
                    })
                    ->values();

            foreach ($candidateFamilies as $candidateFamily) {
                foreach ($candidateFamily->products as $product) {
                    if ($referencePrice !== null) {
                        $productPrice = $product->price?->retail_price !== null
                            ? number_format((float) $product->price->retail_price, 2, '.', '')
                            : null;
                        if ($productPrice !== $referencePrice) {
                            continue;
                        }
                    }

                    $this->renameSellableWithDisplayBase($product, $baseName, $candidateFamily);
                    $sellablesUpdated += 1;
                }
            }
        });

        $message = $applyToMatchingSellables
            ? __('retail.family.display_name.saved_with_sellables', ['count' => $sellablesUpdated])
            : __('retail.family.display_name.saved');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'display_name' => $displayName,
                'sellables_updated' => $sellablesUpdated,
            ]);
        }

        return back()->with('status', $message);
    }

    private function sharedDecimalValue(mixed $products, callable $resolver): ?string
    {
        if ($products->isEmpty()) {
            return null;
        }

        $values = $products
            ->map($resolver)
            ->map(fn ($value): ?string => $value === null ? null : number_format((float) $value, 2, '.', ''))
            ->unique()
            ->values();

        return $values->count() === 1 ? $values->first() : null;
    }

    private function combinedRetailBrandGroups(): Collection
    {
        return $this->combinedRetailRowsWithPictures()
            ->groupBy(fn (object $row): string => $this->brandKey((string) $row->brand_name))
            ->map(function (Collection $rows, string $key): array {
                $sourceNames = $rows->pluck('brand_name')
                    ->filter()
                    ->unique(fn (string $name): string => Str::lower($name))
                    ->sort()
                    ->values();
                $supplierNames = $rows
                    ->where('source_type', '!=', 'picture_product')
                    ->pluck('brand_name')
                    ->filter()
                    ->unique(fn (string $name): string => Str::lower($name));
                $displayName = ($supplierNames->isNotEmpty() ? $supplierNames : $sourceNames)
                    ->sortByDesc(fn (string $name): int => $rows->where('brand_name', $name)->count())
                    ->first() ?: 'Unknown';
                $sourceTypes = $rows->pluck('source_type')->unique()->values();
                $jansonProducts = $rows->where('source_type', 'janson_product')->pluck('product_id')->unique()->count();
                $mamadoProducts = $rows->where('source_type', 'mamado_product')->pluck('product_id')->unique()->count();
                $pictureSourceRows = $rows->whereIn('source_type', ['picture_product_confidence_a', 'picture_product_draft']);
                $unproductizedPictureRows = $rows->where('source_type', 'picture_product');
                $pictureProducts = $pictureSourceRows->pluck('product_id')->filter()->unique()->count()
                    + $unproductizedPictureRows->pluck('picture_product_key')->filter()->unique()->count();

                return [
                    'key' => $key,
                    'display_name' => $displayName,
                    'source_names' => $sourceNames->all(),
                    'sources' => $sourceTypes->map(fn (string $source): string => $this->sourceLabel($source))->all(),
                    'products' => $rows->where('source_type', '!=', 'picture_product')->pluck('product_id')->filter()->unique()->count(),
                    'families' => $rows->where('source_type', '!=', 'picture_product')->pluck('product_family_id')->filter()->unique()->count(),
                    'janson_products' => $jansonProducts,
                    'mamado_products' => $mamadoProducts,
                    'janson_families' => $rows->where('source_type', 'janson_product')->pluck('product_family_id')->unique()->count(),
                    'mamado_families' => $rows->where('source_type', 'mamado_product')->pluck('product_family_id')->unique()->count(),
                    'picture_products' => $pictureProducts,
                    'picture_hits' => $unproductizedPictureRows->count(),
                    'pictures' => $unproductizedPictureRows->pluck('picture_id')->filter()->unique()->count(),
                    'review_count' => $rows->where('confidence', 'C')->count(),
                    'is_matched' => $sourceTypes->count() > 1,
                ];
            })
            ->sortBy('display_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function combinedRetailFamilyGroups(string $brandKey): Collection
    {
        return $this->combinedRetailRowsWithPictures()
            ->filter(fn (object $row): bool => $this->brandKey((string) $row->brand_name) === $brandKey)
            ->groupBy(fn (object $row): string => $this->familyKey((string) $row->family_name))
            ->map(function (Collection $rows, string $key): array {
                $familyName = $rows->pluck('family_name')
                    ->filter()
                    ->sortByDesc(fn (string $name): int => $rows->where('family_name', $name)->count())
                    ->first() ?: 'Unknown family';
                $sourceTypes = $rows->pluck('source_type')->unique()->values();
                $familyIds = $rows->pluck('product_family_id')->filter()->unique()->values();
                $jansonProducts = $rows->where('source_type', 'janson_product')->pluck('product_id')->unique()->count();
                $mamadoProducts = $rows->where('source_type', 'mamado_product')->pluck('product_id')->unique()->count();
                $pictureSourceRows = $rows->whereIn('source_type', ['picture_product_confidence_a', 'picture_product_draft']);
                $pictureRows = $rows->where('source_type', 'picture_product');

                return [
                    'key' => $key,
                    'family_name' => $familyName,
                    'brand_name' => $rows->pluck('brand_name')->filter()->first() ?: 'Unknown',
                    'department' => $rows->pluck('root_catalogue_name')->filter()->first() ?: '',
                    'product_type' => $rows->pluck('product_type_name')->filter()->first() ?: '',
                    'sources' => $sourceTypes->map(fn (string $source): string => $this->sourceLabel($source))->all(),
                    'products' => $rows->pluck('product_id')->filter()->unique()->count(),
                    'families' => $familyIds->count(),
                    'janson_products' => $jansonProducts,
                    'mamado_products' => $mamadoProducts,
                    'picture_products' => $pictureSourceRows->pluck('product_id')->filter()->unique()->count()
                        + $pictureRows->pluck('picture_product_key')->filter()->unique()->count(),
                    'picture_hits' => $pictureRows->count(),
                    'pictures' => $pictureRows->pluck('picture_id')->filter()->unique()->count(),
                    'picture_ids' => $pictureRows->pluck('picture_id')->filter()->unique()->sortBy(fn (string $value): string => $value, SORT_NATURAL)->values()->all(),
                    'review_count' => $rows->where('confidence', 'C')->count(),
                    'family_links' => $familyIds
                        ->map(function ($familyId) use ($rows): array {
                            $familyRows = $rows->where('product_family_id', $familyId);

                            return [
                                'id' => $familyId,
                                'sources' => $familyRows->pluck('source_type')->unique()->map(fn (string $source): string => $this->sourceLabel($source))->values()->all(),
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy('family_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function combinedRetailRowsWithPictures(): Collection
    {
        return $this->combinedRetailSourceRows()
            ->concat($this->pictureRetailSourceRows())
            ->values();
    }

    private function combinedRetailSourceRows(): Collection
    {
        return DB::table('product_sources as ps')
            ->join('product_families as pf', 'pf.id', '=', 'ps.product_family_id')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->whereIn('ps.source_type', ['janson_product', 'mamado_product', 'picture_product_confidence_a', 'picture_product_draft'])
            ->whereNotNull('ps.product_id')
            ->where(function ($query): void {
                $query->whereNull('pf.root_catalogue_name')
                    ->orWhere('pf.root_catalogue_name', '!=', 'Hair Extensions');
            })
            ->select([
                'ps.source_type',
                'ps.confidence',
                'ps.product_id',
                'ps.product_family_id',
                'pf.brand_name',
                'pf.family_name',
                'pf.root_catalogue_name',
                'pf.product_type_name',
            ])
            ->get();
    }

    private function pictureRetailSourceRows(): Collection
    {
        return DB::table('observed_products as op')
            ->leftJoin('categories as c', 'c.id', '=', 'op.category_id')
            ->where(function ($query): void {
                $query->where('op.canonical_brand', '!=', '')
                    ->orWhere('op.brand', '!=', '');
            })
            ->where(function ($query): void {
                $query
                    ->whereNull('c.slug')
                    ->orWhereNotIn('c.slug', [
                        'hair-extension-moved',
                        'retail-productized-confidence-a',
                        'retail-productized-picture-draft',
                    ]);
            })
            ->select([
                DB::raw("'picture_product' as source_type"),
                DB::raw("'A' as confidence"),
                DB::raw('NULL as product_id'),
                DB::raw('NULL as product_family_id'),
                DB::raw("COALESCE(NULLIF(op.canonical_brand, ''), op.brand) as brand_name"),
                'op.product_name as family_name',
                'c.name as root_catalogue_name',
                DB::raw("'' as product_type_name"),
                'op.picture_id',
                DB::raw("CONCAT('picture:', COALESCE(NULLIF(op.canonical_brand, ''), op.brand), '|', op.product_name) as picture_product_key"),
            ])
            ->get();
    }

    private function brandKey(string $brand): string
    {
        return Str::slug($this->normalizeRetailName($brand)) ?: 'unknown';
    }

    private function familyKey(string $family): string
    {
        return Str::slug($this->normalizeRetailName($family)) ?: 'unknown';
    }

    private function normalizeRetailName(string $value): string
    {
        $value = Str::ascii(Str::lower(trim($value)));
        $value = str_replace(['&', '+'], ' and ', $value);
        $value = preg_replace('/\b(?:ltd|limited|inc|llc|co)\b/', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function sourceLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'mamado_product' => 'Mamado',
            'janson_product' => 'Janson',
            'picture_product_confidence_a' => 'Picture Verified',
            'picture_product_draft' => 'Picture Draft',
            'picture_product' => 'Picture',
            default => Str::headline(str_replace('_', ' ', $sourceType)),
        };
    }

    private function retailFamilyQuery($sourceType): Builder
    {
        $productImageCounts = DB::table('product_media')
            ->select('product_id', DB::raw('count(*) as image_count'))
            ->whereNotNull('product_id')
            ->groupBy('product_id');

        $variantSummaries = DB::table('product_variant_groups as pvg')
            ->leftJoin('product_variant_options as pvo', 'pvo.product_variant_group_id', '=', 'pvg.id')
            ->select([
                'pvg.product_family_id',
                DB::raw("GROUP_CONCAT(DISTINCT CONCAT(pvg.name, ': ', pvo.label) ORDER BY pvg.sort_order, pvo.sort_order, pvo.label SEPARATOR ' | ') as variant_summary"),
                DB::raw('count(distinct pvg.id) as variant_group_count'),
                DB::raw('count(distinct pvo.id) as variant_option_count'),
            ])
            ->groupBy('pvg.product_family_id');

        $query = DB::table('product_sources as ps')
            ->join('product_families as pf', 'pf.id', '=', 'ps.product_family_id')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->leftJoin('product_prices as pp', 'pp.product_id', '=', 'p.id')
            ->leftJoinSub($productImageCounts, 'pmc', fn ($join) => $join->on('pmc.product_id', '=', 'p.id'))
            ->leftJoinSub($variantSummaries, 'vs', fn ($join) => $join->on('vs.product_family_id', '=', 'pf.id'))
            ->whereNotNull('ps.product_id')
            ->where(function ($query): void {
                $query->whereNull('pf.root_catalogue_name')
                    ->orWhere('pf.root_catalogue_name', '!=', 'Hair Extensions');
            })
            ->select([
                'pf.id',
                'pf.brand_name',
                'pf.family_name',
                'pf.line_name',
                'pf.product_type_name',
                'pf.root_catalogue_name',
                'pf.status',
                DB::raw('count(distinct ps.product_id) as sku_count'),
                DB::raw('count(distinct case when ps.confidence = "C" then ps.id end) as review_count'),
                DB::raw('count(distinct case when p.is_pos_active = 1 then p.id end) as pos_active_count'),
                DB::raw('count(distinct case when p.is_ecommerce_active = 1 then p.id end) as ecommerce_active_count'),
                DB::raw('count(distinct case when pp.retail_price is null then p.id end) as missing_price_count'),
                DB::raw('count(distinct case when coalesce(pmc.image_count, 0) = 0 then p.id end) as missing_image_count'),
                DB::raw('count(distinct case when pp.cost_price is not null then p.id end) as with_cost_count'),
                DB::raw('min(ps.confidence) as best_confidence'),
                DB::raw('max(ps.confidence) as worst_confidence'),
                DB::raw('max(ps.updated_at) as source_updated_at'),
                DB::raw("GROUP_CONCAT(DISTINCT ps.source_type ORDER BY ps.source_type SEPARATOR ',') as source_types"),
                DB::raw("COALESCE(MAX(vs.variant_summary), '') as variant_summary"),
                DB::raw('COALESCE(MAX(vs.variant_group_count), 0) as variant_group_count'),
                DB::raw('COALESCE(MAX(vs.variant_option_count), 0) as variant_option_count'),
            ]);

        $this->applySourceTypeFilter($query, $sourceType);

        return $query;
    }

    private function retailProductStats($sourceType): array
    {
        $query = DB::table('product_sources as ps')
            ->join('product_families as pf', 'pf.id', '=', 'ps.product_family_id')
            ->whereNotNull('ps.product_id')
            ->where(function ($query): void {
                $query->whereNull('pf.root_catalogue_name')
                    ->orWhere('pf.root_catalogue_name', '!=', 'Hair Extensions');
            });

        $this->applySourceTypeFilter($query, $sourceType);

        return [
            'products' => (clone $query)->distinct('ps.product_id')->count('ps.product_id'),
            'families' => (clone $query)->distinct('ps.product_family_id')->count('ps.product_family_id'),
            'brands' => (clone $query)->distinct('pf.brand_name')->count('pf.brand_name'),
            'review' => (clone $query)->where('ps.confidence', 'C')->count(),
        ];
    }

    private function retailProductBrands($sourceType): Collection
    {
        $query = DB::table('product_sources as ps')
            ->join('product_families as pf', 'pf.id', '=', 'ps.product_family_id')
            ->whereNotNull('ps.product_id')
            ->where(function ($query): void {
                $query->whereNull('pf.root_catalogue_name')
                    ->orWhere('pf.root_catalogue_name', '!=', 'Hair Extensions');
            });

        $this->applySourceTypeFilter($query, $sourceType);

        return $query
            ->select('pf.brand_name', DB::raw('count(distinct ps.product_family_id) as family_count'), DB::raw('count(distinct ps.product_id) as product_count'))
            ->groupBy('pf.brand_name')
            ->orderBy('pf.brand_name')
            ->get();
    }

    private function retailProductDepartments($sourceType): Collection
    {
        $query = DB::table('product_sources as ps')
            ->join('product_families as pf', 'pf.id', '=', 'ps.product_family_id')
            ->whereNotNull('ps.product_id')
            ->whereNotNull('pf.root_catalogue_name')
            ->where('pf.root_catalogue_name', '!=', '')
            ->where('pf.root_catalogue_name', '!=', 'Hair Extensions');

        $this->applySourceTypeFilter($query, $sourceType);

        return $query
            ->select('pf.root_catalogue_name as department', DB::raw('count(distinct ps.product_family_id) as family_count'), DB::raw('count(distinct ps.product_id) as product_count'))
            ->groupBy('pf.root_catalogue_name')
            ->orderBy('pf.root_catalogue_name')
            ->get();
    }

    private function retailProductTypes($sourceType): Collection
    {
        $query = DB::table('product_sources as ps')
            ->join('product_families as pf', 'pf.id', '=', 'ps.product_family_id')
            ->whereNotNull('ps.product_id')
            ->where(function ($query): void {
                $query->whereNull('pf.root_catalogue_name')
                    ->orWhere('pf.root_catalogue_name', '!=', 'Hair Extensions');
            })
            ->whereNotNull('pf.product_type_name')
            ->where('pf.product_type_name', '!=', '');

        $this->applySourceTypeFilter($query, $sourceType);

        return $query
            ->select('pf.product_type_name as product_type', DB::raw('count(distinct ps.product_family_id) as family_count'), DB::raw('count(distinct ps.product_id) as product_count'))
            ->groupBy('pf.product_type_name')
            ->orderBy('pf.product_type_name')
            ->get();
    }

    private function applySourceTypeFilter(Builder $query, $sourceType): void
    {
        if (is_array($sourceType)) {
            $query->whereIn('ps.source_type', $sourceType);

            return;
        }

        if ($sourceType !== null) {
            $query->where('ps.source_type', $sourceType);
        }
    }

    private function departmentOptions(): Collection
    {
        $base = collect([
            'Hair Products',
            'Body Care',
            'Skin Care',
            'Hair Extensions & Wigs',
            'Accessories',
            'Electrical',
            'Fragrances',
            'Makeup',
            'Kids',
            'Mens',
            'Other',
        ]);

        $existing = ProductFamily::query()
            ->whereNotNull('root_catalogue_name')
            ->where('root_catalogue_name', '!=', '')
            ->distinct()
            ->orderBy('root_catalogue_name')
            ->pluck('root_catalogue_name');

        return $base
            ->merge($existing)
            ->filter()
            ->unique()
            ->values();
    }

    private function productTypeOptions(): Collection
    {
        $base = collect([
            'Body Lotion',
            'Body Cream',
            'Body Butter',
            'Body Wash',
            'Face Cream',
            'Soap',
            'Body Oil',
            'Skin Treatment',
            'Lip Care',
            'Nail Care',
            'Hair Shampoo',
            'Hair Conditioner',
            'Hair Treatment',
            'Styling Gel',
            'Hair Colour',
            'Relaxer / Texturizer',
            'Perfume',
            'Makeup',
            'Accessory',
        ]);

        $existing = ProductFamily::query()
            ->whereNotNull('product_type_name')
            ->where('product_type_name', '!=', '')
            ->distinct()
            ->orderBy('product_type_name')
            ->pluck('product_type_name');

        return $base
            ->merge($existing)
            ->filter()
            ->unique()
            ->values();
    }

    private function sharedTextValue(mixed $products, callable $resolver): ?string
    {
        if ($products->isEmpty()) {
            return null;
        }

        $values = $products
            ->map($resolver)
            ->map(fn ($value): ?string => $this->nullTrim($value === null ? null : (string) $value))
            ->unique()
            ->values();

        return $values->count() === 1 ? $values->first() : null;
    }

    /**
     * Return the shared integer value across all products via a resolver callback,
     * or null when products disagree or all return null.
     */
    private function sharedIntValue(mixed $products, callable $resolver): ?int
    {
        if ($products->isEmpty()) {
            return null;
        }

        $values = $products
            ->map($resolver)
            ->map(fn (mixed $v): ?int => $v !== null ? (int) $v : null)
            ->unique()
            ->values();

        return $values->count() === 1 ? $values->first() : null;
    }

    public function updateProductOperations(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($product->id)],
            'barcode' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'barcode')->ignore($product->id),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (! app(HairIntakeBarcodeService::class)->isPlausible((string) $value)) {
                        $fail('This barcode does not look valid. Check the scan and try again.');
                    }
                },
            ],
            'receipt_name' => ['nullable', 'string', 'max:255'],
            'inventory_name' => ['nullable', 'string', 'max:255'],
            'retail_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock_quantity' => ['nullable', 'numeric', 'min:-999999.99', 'max:999999.99'],
            'shelf_location' => ['nullable', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'supplier_product_code' => ['nullable', 'string', 'max:255'],
            'is_inventory_tracked' => ['nullable', 'boolean'],
            'is_pos_active' => ['nullable', 'boolean'],
            'is_ecommerce_active' => ['nullable', 'boolean'],
            'ecommerce_title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
        ], [
            'barcode.unique' => 'This barcode is already used on another product.',
        ]);

        $isPartial = (bool) $request->boolean('partial', false);

        $productUpdates = [];
        if (! $isPartial || $request->has('sku')) {
            $productUpdates['sku'] = $this->nullTrim($data['sku'] ?? null);
        }
        if (! $isPartial || $request->has('barcode')) {
            $productUpdates['barcode'] = $this->nullTrim($data['barcode'] ?? null);
        }
        if (! $isPartial || $request->has('receipt_name')) {
            $productUpdates['receipt_name'] = $this->nullTrim($data['receipt_name'] ?? null);
        }
        if (! $isPartial || $request->has('inventory_name')) {
            $productUpdates['inventory_name'] = $this->nullTrim($data['inventory_name'] ?? null);
        }
        if (! $isPartial || $request->has('description')) {
            $productUpdates['description'] = CustomerProductDescription::clean($data['description'] ?? null);
        }
        if (! $isPartial || $request->has('is_inventory_tracked')) {
            $productUpdates['is_inventory_tracked'] = (bool) ($data['is_inventory_tracked'] ?? false);
        }
        if (! $isPartial || $request->has('is_pos_active')) {
            $productUpdates['is_pos_active'] = (bool) ($data['is_pos_active'] ?? false);
        }
        if (! $isPartial || $request->has('is_ecommerce_active')) {
            $productUpdates['is_ecommerce_active'] = (bool) ($data['is_ecommerce_active'] ?? false);
        }

        if (! empty($productUpdates)) {
            $product->fill($productUpdates);
            $isPosActive = $product->is_pos_active;
            $isEcommerceActive = $product->is_ecommerce_active;
            $product->status = ($isPosActive || $isEcommerceActive) ? 'active' : 'draft';
            $product->save();
        }

        $priceFields = ['retail_price', 'cost_price', 'vat_rate'];
        $priceTouched = false;
        $priceUpdates = [
            'currency' => 'GBP',
            'tax_class' => 'standard',
        ];
        foreach ($priceFields as $field) {
            if (! $isPartial || $request->has($field)) {
                $priceTouched = true;
                $priceUpdates[$field] = $data[$field] ?? null;
            }
        }
        if ($priceTouched) {
            $product->price()->updateOrCreate(
                ['product_id' => $product->id],
                $priceUpdates,
            );
        }

        $inventoryFields = ['stock_quantity', 'shelf_location', 'supplier', 'supplier_product_code'];
        $inventoryTouched = false;
        $inventoryUpdates = [];
        foreach ($inventoryFields as $field) {
            if (! $isPartial || $request->has($field)) {
                $inventoryTouched = true;
                $inventoryUpdates[$field] = in_array($field, ['stock_quantity'], true)
                    ? ($data[$field] ?? 0)
                    : $this->nullTrim($data[$field] ?? null);
            }
        }
        if ($inventoryTouched) {
            $location = $this->defaultInventoryLocation();
            $product->inventoryLevels()->updateOrCreate(
                ['inventory_location_id' => $location->id],
                $inventoryUpdates,
            );
        }

        if (! $isPartial || $request->has('receipt_name') || $request->has('inventory_name') || $request->has('is_pos_active')) {
            $product->posProfile()->updateOrCreate(
                ['product_id' => $product->id],
                [
                    'receipt_name' => $this->nullTrim($data['receipt_name'] ?? null) ?: $this->nullTrim($data['inventory_name'] ?? null) ?: $product->inventory_name ?: $product->name,
                    'quick_search_keywords' => $product->search_keywords,
                    'pos_category' => $product->family?->root_catalogue_name,
                    'discount_allowed' => true,
                    'quick_sale_enabled' => true,
                    'tax_class' => 'standard',
                ],
            );
        }

        if (! $isPartial || $request->has('ecommerce_title') || $request->has('is_ecommerce_active') || $request->has('description')) {
            $isEcommerceActive = $product->fresh()->is_ecommerce_active;
            $product->ecommerceProfile()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'profile_level' => 'sku',
                ],
                [
                    'product_family_id' => $product->product_family_id,
                    'online_title' => $this->nullTrim($data['ecommerce_title'] ?? null) ?: $product->name,
                    'short_description' => $product->description ? str($product->description)->limit(180)->toString() : null,
                    'long_description' => $product->description,
                    'seo_slug' => $product->slug,
                    'seo_title' => $this->nullTrim($data['ecommerce_title'] ?? null) ?: $product->name,
                    'seo_description' => $product->description ? str($product->description)->limit(155)->toString() : null,
                    'is_published' => $isEcommerceActive,
                    'click_and_collect_enabled' => true,
                ],
            );
        }

        if ($request->expectsJson()) {
            $fresh = $product->fresh(['price', 'inventoryLevels']);
            $stock = $fresh?->inventoryLevels->sum('stock_quantity') ?? 0;

            return response()->json([
                'message' => 'Saved.',
                'product' => [
                    'id' => $product->id,
                    'sku' => $fresh?->sku,
                    'barcode' => $fresh?->barcode,
                    'is_pos_active' => (bool) $fresh?->is_pos_active,
                    'is_ecommerce_active' => (bool) $fresh?->is_ecommerce_active,
                    'is_inventory_tracked' => (bool) $fresh?->is_inventory_tracked,
                    'inventory_name' => $fresh?->inventory_name,
                    'description' => $fresh?->description,
                    'retail_price' => $fresh?->price?->retail_price,
                    'cost_price' => $fresh?->price?->cost_price,
                    'stock_quantity' => $stock,
                    'shelf_location' => $fresh?->inventoryLevels->first()?->shelf_location,
                    'status' => $fresh?->status,
                ],
            ]);
        }

        return redirect()
            ->route('retail-products.families.show', $product->product_family_id)
            ->with('status', "Saved {$product->name}.");
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @return Collection<int, ProductVariantOption>
     */
    private function validatedFamilyVariantOptions(ProductFamily $family, array $input): Collection
    {
        if ($family->variantGroups->isEmpty()) {
            return collect();
        }

        $errors = [];
        $selected = collect();

        foreach ($family->variantGroups as $group) {
            $rawOptionId = $input[(string) $group->id] ?? $input[$group->id] ?? null;

            if ($rawOptionId === null || $rawOptionId === '') {
                $errors["variant_options.{$group->id}"] = "Choose {$group->name}.";

                continue;
            }

            $option = $group->options->firstWhere('id', (int) $rawOptionId);

            if (! $option instanceof ProductVariantOption) {
                $errors["variant_options.{$group->id}"] = "The selected {$group->name} option does not belong to this family.";

                continue;
            }

            $option->setRelation('group', $group);
            $selected->push($option);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $selected->values();
    }

    /**
     * @param  array<int, int>  $selectedOptionIds
     */
    private function existingProductWithVariantOptions(ProductFamily $family, array $selectedOptionIds): ?Product
    {
        $needle = collect($selectedOptionIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        foreach ($family->products as $product) {
            $candidate = $product->variantValues
                ->pluck('product_variant_option_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            if ($candidate === $needle) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, ProductVariantOption>  $selectedOptions
     */
    private function generatedRetailProductName(ProductFamily $family, Collection $selectedOptions): string
    {
        return $this->generatedRetailProductNameWithBase(
            $this->familyDisplayBaseName($family),
            $selectedOptions,
        );
    }

    /**
     * @param  Collection<int, ProductVariantOption>  $selectedOptions
     */
    private function generatedRetailProductNameWithBase(string $baseName, Collection $selectedOptions): string
    {
        $labels = $selectedOptions
            ->sortBy(fn (ProductVariantOption $option): string => sprintf(
                '%04d:%04d:%s',
                (int) ($option->group?->sort_order ?? 0),
                (int) $option->sort_order,
                $option->label,
            ))
            ->pluck('label')
            ->filter()
            ->implode(' - ');

        $base = trim($baseName);

        return $labels !== '' ? "{$base} - {$labels}" : $base;
    }

    private function familyDisplayBaseName(ProductFamily $family): string
    {
        $family->loadMissing('catalogueStyle');
        $styleName = trim((string) ($family->catalogueStyle?->name ?? ''));
        if ($styleName !== '') {
            return $styleName;
        }

        $name = trim((string) $family->family_name);
        if ($name === '') {
            return '';
        }

        if (str_contains($name, ' > ')) {
            $segments = array_values(array_filter(array_map('trim', explode('>', $name))));

            return (string) (end($segments) ?: $name);
        }

        return $name;
    }

    private function applyFamilyDisplayName(ProductFamily $family, string $displayName): void
    {
        $displayName = trim($displayName);

        if ($family->catalogueStyle) {
            $family->catalogueStyle->update(['name' => $displayName]);
        }

        $current = trim((string) $family->family_name);
        if (str_contains($current, ' > ')) {
            $segments = array_values(array_filter(array_map('trim', explode('>', $current))));
            if ($segments !== []) {
                $segments[count($segments) - 1] = $displayName;
                $family->update(['family_name' => implode(' > ', $segments)]);
            } else {
                $family->update(['family_name' => $displayName]);
            }
        } else {
            $family->update(['family_name' => $displayName]);
        }

        $family->refresh();

        $existingFamilyProfile = $family->ecommerceProfile;
        $family->ecommerceProfile()->updateOrCreate(
            [
                'product_family_id' => $family->id,
                'profile_level' => 'family',
            ],
            [
                'online_title' => $displayName,
                'short_description' => $existingFamilyProfile?->short_description
                    ?: ($family->description ? str($family->description)->limit(180)->toString() : null),
                'long_description' => $existingFamilyProfile?->long_description ?: $family->description,
                'seo_slug' => $family->slug ?? $this->uniqueSlug('product_families', 'slug', $displayName, $family->id),
                'seo_title' => $displayName,
                'seo_description' => $existingFamilyProfile?->seo_description
                    ?: ($family->description ? str($family->description)->limit(155)->toString() : null),
                'is_published' => (bool) ($existingFamilyProfile?->is_published ?? false),
                'click_and_collect_enabled' => $existingFamilyProfile?->click_and_collect_enabled ?? true,
            ],
        );
    }

    private function familyVariantStructureKey(ProductFamily $family): string
    {
        $groups = $family->relationLoaded('variantGroups')
            ? $family->variantGroups
            : $family->variantGroups()->orderBy('sort_order')->orderBy('name')->get();

        return $groups
            ->sortBy(fn (ProductVariantGroup $group): string => sprintf('%04d:%s', (int) $group->sort_order, $group->name))
            ->map(fn (ProductVariantGroup $group): string => mb_strtolower($group->name).':'.mb_strtolower((string) $group->variant_type))
            ->implode('|');
    }

    private function renameSellableWithDisplayBase(Product $product, string $baseName, ProductFamily $family): void
    {
        $product->loadMissing(['variantValues.option', 'variantValues.group', 'price', 'ecommerceProfile']);

        $selectedOptions = $product->variantValues
            ->map(fn (ProductVariantValue $value) => $value->option)
            ->filter();

        $name = $this->generatedRetailProductNameWithBase($baseName, $selectedOptions);

        $product->fill([
            'name' => $name,
            'slug' => $this->uniqueSlug('products', 'slug', $name, $product->id, ['product_family_id' => $family->id]),
            'receipt_name' => Str::limit($name, 80, ''),
            'inventory_name' => $name,
            'search_keywords' => $this->productSearchKeywords($family, $name, $selectedOptions),
        ]);
        $product->save();

        $product->ecommerceProfile()->updateOrCreate(
            [
                'product_id' => $product->id,
                'profile_level' => 'sku',
            ],
            [
                'product_family_id' => $family->id,
                'online_title' => $name,
                'short_description' => $product->description ? str($product->description)->limit(180)->toString() : null,
                'long_description' => $product->description,
                'seo_slug' => $product->slug,
                'seo_title' => $name,
                'seo_description' => $product->description ? str($product->description)->limit(155)->toString() : null,
                'is_published' => (bool) $product->is_ecommerce_active,
                'click_and_collect_enabled' => true,
            ],
        );
    }

    /**
     * Generate a unique SKU code for a new sellable product in this family,
     * following the unified scheme: {DEPT}-{BRAND}-{FFFFF}{V} (e.g. HE-XPR-00012A).
     *
     * Returns null when no allocator slot is available — callers treat null as
     * "leave SKU empty and let the user fill it in manually".
     */
    private function allocatedRetailProductSku(Product $product): ?string
    {
        try {
            return app(SkuCodeAllocator::class)->allocateForProduct($product);
        } catch (\Throwable $e) {
            report($e);
        }

        return null;
    }

    /**
     * Family-level SKU prefix (without the variant letter), used by the
     * "next SKU" preview banner on the family page.
     */
    private function familySkuPrefix(ProductFamily $family): ?string
    {
        try {
            return app(SkuCodeAllocator::class)->previewFamilyPrefix($family);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function defaultVariantGroupTypeOptions(): array
    {
        return [
            ['value' => 'measurement', 'label' => 'Length / size'],
            ['value' => 'colour_name', 'label' => 'Colour name'],
            ['value' => 'colour_code', 'label' => 'Colour code'],
            ['value' => 'short_code', 'label' => 'Short code'],
            ['value' => 'count', 'label' => 'Pack / count'],
            ['value' => 'text', 'label' => 'Text'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function variantGroupTypeOptions(): array
    {
        try {
            $options = ProductVariantGroupType::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (ProductVariantGroupType $type): array => [
                    'value' => $type->slug,
                    'label' => $type->name,
                ])
                ->values()
                ->all();

            return $options !== [] ? $options : $this->defaultVariantGroupTypeOptions();
        } catch (\Throwable) {
            return $this->defaultVariantGroupTypeOptions();
        }
    }

    private function resolveVariantGroupTypeSlug(string $selectedType, ?string $newTypeName): string
    {
        if ($selectedType === '__new') {
            $name = trim(preg_replace('/\s+/', ' ', (string) $newTypeName));
            if ($name === '') {
                throw ValidationException::withMessages([
                    'new_variant_type_name' => 'Enter the new public group type name.',
                ]);
            }

            $baseSlug = mb_substr(Str::slug($name), 0, 80) ?: 'custom-type';

            $existing = ProductVariantGroupType::query()
                ->where('slug', $baseSlug)
                ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            if ($existing) {
                return $existing->slug;
            }

            $slug = $baseSlug;
            $counter = 2;
            while (ProductVariantGroupType::query()->where('slug', $slug)->exists()) {
                $suffix = '-'.$counter++;
                $slug = mb_substr($baseSlug, 0, 80 - mb_strlen($suffix)).$suffix;
            }

            ProductVariantGroupType::query()->create([
                'name' => $name,
                'slug' => $slug,
                'is_system' => false,
                'sort_order' => ((int) ProductVariantGroupType::query()->max('sort_order')) + 10,
            ]);

            return $slug;
        }

        $allowed = collect($this->variantGroupTypeOptions())->pluck('value');
        if (! $allowed->contains($selectedType)) {
            throw ValidationException::withMessages([
                'variant_type' => 'Choose a valid group type, or add a new public one.',
            ]);
        }

        return $selectedType;
    }

    /**
     * @param  Collection<int, ProductVariantOption>  $selectedOptions
     */
    private function findOrCreateCatalogueSkuForSelection(
        ProductFamily $family,
        Collection $selectedOptions,
        string $name,
        ?string $skuCode,
        ?string $barcode,
    ): ?BrandCatalogueSku {
        if (! $family->brand_catalogue_style_id || $selectedOptions->isEmpty()) {
            return null;
        }

        $catalogueOptionIds = $selectedOptions
            ->pluck('brand_catalogue_variant_option_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values();

        if ($catalogueOptionIds->count() !== $selectedOptions->count()) {
            return null;
        }

        $existing = BrandCatalogueSku::query()
            ->where('brand_catalogue_style_id', $family->brand_catalogue_style_id)
            ->with('optionValues.variant')
            ->get()
            ->first(function (BrandCatalogueSku $sku) use ($catalogueOptionIds): bool {
                $candidate = $sku->optionValues
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->sort()
                    ->values();

                return $candidate->all() === $catalogueOptionIds->all();
            });

        if ($existing instanceof BrandCatalogueSku) {
            return $existing;
        }

        $catalogueOptions = BrandCatalogueVariantOption::query()
            ->whereIn('id', $catalogueOptionIds->all())
            ->with('variant')
            ->get()
            ->sortBy(fn (BrandCatalogueVariantOption $option): string => sprintf(
                '%04d:%04d:%s',
                (int) ($option->variant?->sort_order ?? 0),
                (int) $option->sort_order,
                $option->label,
            ))
            ->values();

        if ($catalogueOptions->count() !== $catalogueOptionIds->count()) {
            return null;
        }

        $signature = $catalogueOptions
            ->map(fn (BrandCatalogueVariantOption $option): string => Str::slug((string) $option->variant?->name).':'.$option->label)
            ->implode('|');

        $existingBySignature = BrandCatalogueSku::query()
            ->where('brand_catalogue_style_id', $family->brand_catalogue_style_id)
            ->where('option_signature', $signature)
            ->first();

        if ($existingBySignature instanceof BrandCatalogueSku) {
            return $existingBySignature;
        }

        $catalogueSku = BrandCatalogueSku::query()->create([
            'brand_catalogue_style_id' => $family->brand_catalogue_style_id,
            'name' => $name,
            'slug' => $this->uniqueSlug('brand_catalogue_skus', 'slug', $name, null, ['brand_catalogue_style_id' => $family->brand_catalogue_style_id]),
            'sku_code' => $skuCode,
            'barcode' => $barcode,
            'option_signature' => $signature,
            'note' => 'Created from retail family SKU manager.',
            'url' => $family->source_url,
            'is_active' => true,
            'sort_order' => ((int) BrandCatalogueSku::query()->where('brand_catalogue_style_id', $family->brand_catalogue_style_id)->max('sort_order')) + 1,
        ]);

        foreach ($catalogueOptions as $option) {
            $catalogueSku->optionValues()->attach($option->id, [
                'brand_catalogue_variant_id' => $option->variant_id,
            ]);
        }

        return $catalogueSku;
    }

    /**
     * @param  Collection<int, ProductVariantOption>  $selectedOptions
     */
    private function productSearchKeywords(ProductFamily $family, string $productName, Collection $selectedOptions): string
    {
        return collect([
            $family->brand_name,
            $family->line_name,
            $family->product_type_name,
            $family->family_name,
            $productName,
        ])
            ->merge($selectedOptions->pluck('label'))
            ->filter()
            ->unique()
            ->implode(' ');
    }

    private function copyFamilyCategoryAssignmentsToProduct(ProductFamily $family, Product $product): void
    {
        foreach ($family->categoryAssignments as $assignment) {
            ProductCategoryAssignment::query()->updateOrCreate(
                [
                    'product_family_id' => $family->id,
                    'product_id' => $product->id,
                    'assignment_type' => $assignment->assignment_type,
                ],
                [
                    'category_scaffold_id' => $assignment->category_scaffold_id,
                    'category_scaffold_axis_id' => $assignment->category_scaffold_axis_id,
                    'category_scaffold_node_id' => $assignment->category_scaffold_node_id,
                    'source_type' => 'retail_family_manual_add',
                    'notes' => 'Inherited from product family when manually adding a sellable SKU.',
                ],
            );
        }
    }

    private function recordManualProductSource(ProductFamily $family, Product $product, ?BrandCatalogueSku $catalogueSku): void
    {
        if ($catalogueSku) {
            ProductSource::query()->updateOrCreate(
                [
                    'product_family_id' => $family->id,
                    'product_id' => $product->id,
                    'source_type' => 'brand_catalogue_sku',
                    'source_table' => 'brand_catalogue_skus',
                    'source_id' => $catalogueSku->id,
                ],
                [
                    'source_url' => $catalogueSku->url,
                    'confidence' => 'A',
                    'notes' => 'Linked while manually adding a sellable SKU from the family page.',
                ],
            );
        }

        ProductSource::query()->updateOrCreate(
            [
                'product_family_id' => $family->id,
                'product_id' => $product->id,
                'source_type' => 'retail_family_manual_add',
                'source_table' => 'product_families',
                'source_id' => $family->id,
            ],
            [
                'source_url' => $family->source_url,
                'confidence' => 'A',
                'notes' => 'Manually created from the retail family SKU manager.',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function uniqueSlug(string $table, string $column, string $name, ?int $ignoreId = null, array $scope = []): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $i = 2;

        while ($this->valueExists($table, $column, $slug, $ignoreId, $scope)) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function uniqueNullableValue(string $table, string $column, ?string $value, ?int $ignoreId = null): ?string
    {
        $value = $this->nullTrim($value);

        if ($value === null) {
            return null;
        }

        if (! $this->valueExists($table, $column, $value, $ignoreId)) {
            return $value;
        }

        return $value.'-'.Str::lower(Str::random(6));
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function valueExists(string $table, string $column, string $value, ?int $ignoreId = null, array $scope = []): bool
    {
        $query = DB::table($table)->where($column, $value);

        foreach ($scope as $scopeColumn => $scopeValue) {
            $query->where($scopeColumn, $scopeValue);
        }

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }

    private function defaultInventoryLocation(): InventoryLocation
    {
        return InventoryLocation::query()->firstOrCreate(
            ['slug' => 'shop-floor'],
            [
                'name' => 'Shop Floor',
                'location_type' => 'shop',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 0,
            ],
        );
    }

    private function normalizeFamilyVariantOrder(ProductFamily $family): void
    {
        $groups = VariantNaturalSort::sortGroups($family->variantGroups);
        $groups->each(function (ProductVariantGroup $group): void {
            if ($group->relationLoaded('options')) {
                $group->setRelation('options', VariantNaturalSort::sortOptions($group->options));
            }
        });

        $family->setRelation('variantGroups', $groups);
        $groupOrder = $groups
            ->values()
            ->mapWithKeys(fn (ProductVariantGroup $group, int $index): array => [(int) $group->id => $index]);

        $products = $family->products
            ->map(function (Product $product) use ($groupOrder): Product {
                if ($product->relationLoaded('variantValues')) {
                    $product->setRelation('variantValues', $product->variantValues
                        ->sortBy(fn (ProductVariantValue $value): string => sprintf(
                            '%04d:%s',
                            (int) $groupOrder->get((int) $value->product_variant_group_id, 9999),
                            VariantNaturalSort::valueKey($value->option?->label),
                        ))
                        ->values());
                }

                return $product;
            })
            ->sortBy(fn (Product $product): string => VariantNaturalSort::productKey($product, $groups))
            ->values();

        $family->setRelation('products', $products);
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
