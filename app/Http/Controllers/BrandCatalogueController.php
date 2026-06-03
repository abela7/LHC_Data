<?php

namespace App\Http\Controllers;

use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueLine;
use App\Models\BrandCatalogueMaterial;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use App\Models\HairExtensionIntake;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Services\SkuCodeAllocator;
use App\Support\RetailStyleFamilyCatalogue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BrandCatalogueController extends Controller
{
    public function index(): View
    {
        $catalogues = BrandCatalogue::query()
            ->withCount('brands')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('brand-catalogue.index', [
            'catalogues' => $catalogues,
        ]);
    }

    public function showCatalogue(Request $request, BrandCatalogue $catalogue): View
    {
        $catalogue->load([
            'brands' => fn ($query) => $query->withCount(['lines', 'productTypes']),
            'brands.lines' => fn ($query) => $query
                ->withCount('productTypes')
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->orderBy('name'),
        ]);

        $catalogueBrands = $this->catalogueBrandsForDisplay($catalogue);

        return view('brand-catalogue.catalogue', [
            'catalogue' => $catalogue,
            'catalogueBrands' => $catalogueBrands,
            'catalogueLineCount' => $this->catalogueLines($catalogue)->count(),
            'productFinder' => $this->catalogueProductFinder($catalogue, $request, $catalogueBrands),
        ]);
    }

    public function productTypeStructure(BrandCatalogue $catalogue): View
    {
        $styleRows = DB::table('brand_catalogue_styles as styles')
            ->join('brand_catalogue_brands as brands', 'brands.id', '=', 'styles.brand_catalogue_brand_id')
            ->join('brand_catalogue_product_types as product_types', 'product_types.id', '=', 'styles.brand_catalogue_product_type_id')
            ->leftJoin('brand_catalogue_lines as lines', 'lines.id', '=', 'product_types.brand_catalogue_line_id')
            ->leftJoin('brand_catalogue_skus as skus', 'skus.brand_catalogue_style_id', '=', 'styles.id')
            ->leftJoin('brand_catalogue_variants as variants', 'variants.brand_catalogue_style_id', '=', 'styles.id')
            ->where('brands.brand_catalogue_id', $catalogue->id)
            ->whereNotNull('styles.brand_catalogue_product_type_id')
            ->whereNotNull('product_types.brand_catalogue_line_id')
            ->groupBy(
                'styles.id',
                'styles.name',
                'styles.material_name',
                'brands.id',
                'brands.name',
                'lines.id',
                'lines.name',
                'product_types.id',
                'product_types.name',
            )
            ->select([
                'styles.id as style_id',
                'styles.name as style_name',
                'styles.material_name',
                'brands.id as brand_id',
                'brands.name as brand_name',
                'lines.id as line_id',
                'lines.name as line_name',
                'product_types.id as product_type_id',
                'product_types.name as product_type_name',
                DB::raw('count(distinct skus.id) as sku_count'),
                DB::raw('count(distinct variants.id) as variant_count'),
            ])
            ->orderBy('styles.name')
            ->get();

        $publishedFamiliesByStyle = $this->publishedFamiliesForStyles(
            $styleRows->pluck('style_id')->map(fn (mixed $id): int => (int) $id)->all(),
        );

        $styleRows = $styleRows->map(function (object $row) use ($catalogue, $publishedFamiliesByStyle): array {
            $productTypeName = (string) $row->product_type_name;
            $styleName = (string) $row->style_name;
            $majorType = $this->majorProductTypeFor($productTypeName, $styleName);
            $publishedFamily = $publishedFamiliesByStyle->get((int) $row->style_id);

            return [
                'major_type' => $majorType,
                'major_sort' => $this->majorProductTypeSort($majorType),
                'product_type_name' => $productTypeName,
                'style_id' => (int) $row->style_id,
                'style_name' => $styleName,
                'style_family' => $this->styleFamilyLabelFor($styleName, $productTypeName),
                'material_name' => $row->material_name ?: 'Material not set',
                'brand_id' => (int) $row->brand_id,
                'brand_name' => (string) $row->brand_name,
                'line_id' => $row->line_id ? (int) $row->line_id : null,
                'line_name' => $row->line_name ?: (string) $row->brand_name,
                'product_type_id' => (int) $row->product_type_id,
                'sku_count' => (int) $row->sku_count,
                'variant_count' => (int) $row->variant_count,
                'is_published' => $publishedFamily !== null,
                'retail_products_count' => (int) ($publishedFamily?->products_count ?? 0),
                'url' => $this->styleOpenUrl(
                    $catalogue,
                    (int) $row->brand_id,
                    (int) $row->line_id,
                    (int) $row->product_type_id,
                    (int) $row->style_id,
                    $publishedFamily,
                ),
            ];
        });

        $majorGroups = $styleRows
            ->groupBy('major_type')
            ->map(function (Collection $majorRows, string $majorType): array {
                $productTypeGroups = $majorRows
                    ->groupBy('product_type_name')
                    ->map(function (Collection $typeRows, string $productTypeName): array {
                        return [
                            'name' => $productTypeName,
                            'brands' => $typeRows->pluck('brand_name')->unique()->sort()->values(),
                            'style_count' => $typeRows->count(),
                            'sku_count' => $typeRows->sum('sku_count'),
                            'variant_count' => $typeRows->sum('variant_count'),
                            'styles' => $typeRows
                                ->sortBy([
                                    ['style_family', 'asc'],
                                    ['brand_name', 'asc'],
                                    ['style_name', 'asc'],
                                ])
                                ->values(),
                        ];
                    })
                    ->sortByDesc('sku_count')
                    ->values();

                return [
                    'name' => $majorType,
                    'sort' => $this->majorProductTypeSort($majorType),
                    'brands' => $majorRows->pluck('brand_name')->unique()->sort()->values(),
                    'product_type_count' => $productTypeGroups->count(),
                    'style_count' => $majorRows->count(),
                    'sku_count' => $majorRows->sum('sku_count'),
                    'variant_count' => $majorRows->sum('variant_count'),
                    'product_types' => $productTypeGroups,
                ];
            })
            ->sortBy('sort')
            ->values();

        return view('brand-catalogue.product-type-structure', [
            'catalogue' => $catalogue,
            'majorGroups' => $majorGroups,
            'summary' => [
                'major_type_count' => $majorGroups->count(),
                'product_type_count' => $styleRows->pluck('product_type_name')->unique()->count(),
                'style_count' => $styleRows->count(),
                'sku_count' => $styleRows->sum('sku_count'),
                'brand_count' => $styleRows->pluck('brand_id')->unique()->count(),
            ],
        ]);
    }

    public function showBrand(BrandCatalogue $catalogue, BrandCatalogueBrand $brand): View
    {
        $this->assertBrandInCatalogue($catalogue, $brand);

        $brand->loadCount(['lines', 'productTypes', 'styles']);
        $brand->load([
            'lines' => fn ($query) => $query->withCount('productTypes'),
            'lines.productTypes' => fn ($query) => $query->withCount('styles'),
        ]);

        return view('brand-catalogue.brand', [
            'catalogue' => $catalogue,
            'brand' => $brand,
        ]);
    }

    public function removeMasterBrandLayer(BrandCatalogueBrand $brand): RedirectResponse
    {
        $defaultLine = $this->ensureDefaultLineForBrand($brand);
        $movedStyles = 0;
        $removedLines = 0;

        DB::transaction(function () use ($brand, $defaultLine, &$movedStyles, &$removedLines): void {
            $brand->load([
                'lines.productTypes.styles',
            ]);

            foreach ($brand->lines->where('is_default', false) as $line) {
                foreach ($line->productTypes as $productType) {
                    $targetProductType = $this->findOrCreateProductTypeOnLine($defaultLine, $productType);

                    foreach ($productType->styles as $style) {
                        $newStyleName = $this->styleNameWithFormerLine($line, $style);

                        $style->update([
                            'brand_catalogue_product_type_id' => $targetProductType->id,
                            'name' => $newStyleName,
                            'slug' => $this->uniqueSlug(
                                'brand_catalogue_styles',
                                'brand_catalogue_product_type_id',
                                $targetProductType->id,
                                $newStyleName,
                                $style->id,
                            ),
                        ]);

                        ProductFamily::query()
                            ->where('brand_catalogue_style_id', $style->id)
                            ->get()
                            ->each(function (ProductFamily $family) use ($defaultLine, $targetProductType, $newStyleName): void {
                                $family->update([
                                    'brand_catalogue_line_id' => $defaultLine->id,
                                    'brand_catalogue_product_type_id' => $targetProductType->id,
                                    'line_name' => null,
                                    'product_type_name' => $targetProductType->name,
                                    'family_name' => $newStyleName,
                                    'slug' => $this->uniqueGlobalSlug('product_families', 'slug', $newStyleName, $family->id),
                                ]);

                                DB::table('product_ecommerce_profiles')
                                    ->where('product_family_id', $family->id)
                                    ->where('profile_level', 'family')
                                    ->update([
                                        'online_title' => $newStyleName,
                                        'seo_slug' => $family->slug,
                                        'seo_title' => $newStyleName,
                                        'updated_at' => now(),
                                    ]);
                            });

                        $movedStyles++;
                    }

                    if ($productType->styles()->count() === 0) {
                        $productType->delete();
                    }
                }

                if ($line->productTypes()->count() === 0) {
                    $line->delete();
                    $removedLines++;
                }
            }
        });

        return redirect()
            ->back()
            ->with('status', "Master brand layer removed. {$movedStyles} style(s) moved directly under {$brand->name}; {$removedLines} line(s) removed.");
    }

    public function showLine(
        BrandCatalogue $catalogue,
        BrandCatalogueBrand $brand,
        BrandCatalogueLine $line,
    ): View {
        $this->assertBrandInCatalogue($catalogue, $brand);
        $this->assertLineInBrand($brand, $line);

        $line->load([
            'productTypes' => fn ($query) => $query->withCount('styles'),
        ]);

        return view('brand-catalogue.line', [
            'catalogue' => $catalogue,
            'brand' => $brand,
            'line' => $line,
        ]);
    }

    public function showProductType(
        Request $request,
        BrandCatalogue $catalogue,
        BrandCatalogueBrand $brand,
        BrandCatalogueLine $line,
        BrandCatalogueProductType $productType,
    ): View|RedirectResponse {
        $this->assertBrandInCatalogue($catalogue, $brand);
        $this->assertLineInBrand($brand, $line);
        $this->assertProductTypeInLine($line, $productType);

        $productType->load([
            'styles' => fn ($query) => $query->withCount(['variants', 'skus']),
        ]);

        $publishedFamiliesByStyle = $this->publishedFamiliesForStyles(
            $productType->styles->pluck('id')->all(),
        );

        if ($productType->styles->count() === 1 && ! $request->boolean('list')) {
            $onlyStyle = $productType->styles->first();
            $publishedFamily = $publishedFamiliesByStyle->get((int) $onlyStyle->id);
            if ($publishedFamily !== null) {
                return redirect()->route('retail-products.families.show', $publishedFamily);
            }
        }

        return view('brand-catalogue.product-type', [
            'catalogue' => $catalogue,
            'brand' => $brand,
            'line' => $line,
            'productType' => $productType,
            'materialOptions' => $this->materialOptions(),
            'publishedFamiliesByStyle' => $publishedFamiliesByStyle,
        ]);
    }

    public function showMaterial(
        BrandCatalogue $catalogue,
        BrandCatalogueBrand $brand,
        BrandCatalogueLine $line,
        BrandCatalogueProductType $productType,
        BrandCatalogueMaterial $material,
    ): RedirectResponse {
        $this->assertBrandInCatalogue($catalogue, $brand);
        $this->assertLineInBrand($brand, $line);
        $this->assertProductTypeInLine($line, $productType);
        $this->assertMaterialInProductType($productType, $material);

        return redirect()->route('brand-catalogue.product-types.show', [$catalogue, $brand, $line, $productType]);
    }

    public function showStyle(
        Request $request,
        BrandCatalogue $catalogue,
        BrandCatalogueBrand $brand,
        BrandCatalogueLine $line,
        BrandCatalogueProductType $productType,
        BrandCatalogueStyle $style,
    ): View|RedirectResponse {
        $this->assertBrandInCatalogue($catalogue, $brand);
        $this->assertLineInBrand($brand, $line);
        $this->assertProductTypeInLine($line, $productType);
        $this->assertStyleInProductType($brand, $productType, $style);

        $style->load([
            'variants.options.images',
            'images',
            'skus.optionValues.variant',
            'skus.optionValues.images',
            'skus.images',
        ]);

        $retailFamilies = RetailStyleFamilyCatalogue::familiesForStyle((int) $style->id);
        $retailVariantAxes = RetailStyleFamilyCatalogue::catalogueVariantAxes($style, $style->variants);
        $retailNavItems = RetailStyleFamilyCatalogue::styleWorkspaceRetailNav(
            $style,
            $style->variants,
            $retailFamilies,
        );
        $publishedFamily = RetailStyleFamilyCatalogue::primaryFamily($retailFamilies);
        $retailByCatalogueOptionId = RetailStyleFamilyCatalogue::catalogueOptionRetailMap(
            (int) $style->id,
            $style->variants,
            $retailFamilies,
        );

        if ($retailFamilies->count() === 1 && $publishedFamily !== null && ! $request->boolean('catalogue')) {
            return redirect()->route('retail-products.families.show', $publishedFamily);
        }

        $line->loadMissing([
            'productTypes' => fn ($query) => $query->withCount('styles'),
        ]);

        // Preview the unified SKU prefix this style would produce, so the
        // "Add SKU" form can show what the auto-generated code will look like.
        try {
            $stylePrefix = app(SkuCodeAllocator::class)->previewCatalogueStylePrefix($style);
        } catch (\Throwable $e) {
            $stylePrefix = null;
        }

        return view('brand-catalogue.style', [
            'catalogue' => $catalogue,
            'brand' => $brand,
            'line' => $line,
            'productType' => $productType,
            'style' => $style,
            'publishedFamily' => $publishedFamily,
            'retailFamilies' => $retailFamilies,
            'retailNavItems' => $retailNavItems,
            'retailMainVariant' => $retailVariantAxes['main'],
            'retailCommonVariantIds' => $retailVariantAxes['common_variant_ids'],
            'retailSubVariantIds' => $retailVariantAxes['sub_variant_ids'],
            'retailFamilyCount' => $retailNavItems->isNotEmpty()
                ? $retailNavItems->count()
                : $retailFamilies->count(),
            'retailSkuTotal' => (int) $retailFamilies->sum('products_count'),
            'retailByCatalogueOptionId' => $retailByCatalogueOptionId,
            'materialOptions' => $this->materialOptions(),
            'productTypeOptions' => $line->productTypes,
            'optionImageRoleOptions' => $this->optionImageRoleOptions(),
            'variantTypeOptions' => $this->variantTypeOptions(),
            'styleImageRoleOptions' => $this->styleImageRoleOptions(),
            'skuImageRoleOptions' => $this->skuImageRoleOptions(),
            'stylePrefix' => $stylePrefix,
        ]);
    }

    public function showStyleLegacy(
        BrandCatalogue $catalogue,
        BrandCatalogueBrand $brand,
        BrandCatalogueLine $line,
        BrandCatalogueProductType $productType,
        BrandCatalogueMaterial $material,
        BrandCatalogueStyle $style,
    ): RedirectResponse {
        $this->assertBrandInCatalogue($catalogue, $brand);
        $this->assertLineInBrand($brand, $line);
        $this->assertProductTypeInLine($line, $productType);
        $this->assertMaterialInProductType($productType, $material);
        $this->assertStyleInProductType($brand, $productType, $style);

        return redirect()->route('brand-catalogue.styles.show', [$catalogue, $brand, $line, $productType, $style]);
    }

    public function showSku(
        BrandCatalogue $catalogue,
        BrandCatalogueBrand $brand,
        BrandCatalogueLine $line,
        BrandCatalogueProductType $productType,
        BrandCatalogueStyle $style,
        BrandCatalogueSku $sku,
    ): View {
        $this->assertBrandInCatalogue($catalogue, $brand);
        $this->assertLineInBrand($brand, $line);
        $this->assertProductTypeInLine($line, $productType);
        $this->assertStyleInProductType($brand, $productType, $style);
        $this->assertSkuInStyle($style, $sku);

        $style->load([
            'variants.options.images',
            'images',
        ]);

        $sku->load([
            'optionValues.variant',
            'optionValues.images',
            'images',
        ]);

        $directGallery = $sku->images
            ->map(fn ($image) => $image->displayUrl())
            ->filter()
            ->values();

        $fallbackGallery = collect([
            $sku->selectedOptionPrimaryImage()?->displayUrl(),
        ])
            ->merge($style->images->map(fn ($image) => $image->displayUrl()))
            ->filter()
            ->values();

        $gallery = $directGallery->isNotEmpty()
            ? $directGallery->merge($fallbackGallery)->unique()->values()
            : $fallbackGallery->unique()->values();

        $relatedSkus = BrandCatalogueSku::query()
            ->where('brand_catalogue_style_id', $style->id)
            ->whereKeyNot($sku->getKey())
            ->with(['images', 'optionValues.variant', 'optionValues.images'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(4)
            ->get();

        return view('brand-catalogue.sku', [
            'catalogue' => $catalogue,
            'brand' => $brand,
            'line' => $line,
            'productType' => $productType,
            'style' => $style,
            'sku' => $sku,
            'gallery' => $gallery,
            'relatedSkus' => $relatedSkus,
            'stats' => [
                'images' => $gallery->count(),
                'options' => $sku->optionValues->count(),
            ],
            'skuImageRoleOptions' => $this->skuImageRoleOptions(),
        ]);
    }

    public function storeBrand(Request $request, BrandCatalogue $catalogue): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        DB::transaction(function () use ($catalogue, $data) {
            $brand = $catalogue->brands()->create([
                'name' => trim($data['name']),
                'slug' => $this->uniqueSlug('brand_catalogue_brands', 'brand_catalogue_id', $catalogue->id, trim($data['name'])),
                'note' => $this->nullTrim($data['note'] ?? null),
                'url' => $this->nullTrim($data['url'] ?? null),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => true,
            ]);

            $this->ensureDefaultLineForBrand($brand);
        });

        return redirect()->back()->with('status', "Brand \"{$data['name']}\" added.");
    }

    public function storeBrandLine(Request $request, BrandCatalogue $catalogue): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        DB::transaction(function () use ($catalogue, $data) {
            $brand = $catalogue->brands()->create([
                'name' => trim($data['name']),
                'slug' => $this->uniqueSlug('brand_catalogue_brands', 'brand_catalogue_id', $catalogue->id, trim($data['name'])),
                'note' => $this->nullTrim($data['note'] ?? null),
                'url' => $this->nullTrim($data['url'] ?? null),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => true,
            ]);

            $this->ensureDefaultLineForBrand($brand);
        });

        return redirect()->back()->with('status', "Brand line \"{$data['name']}\" added.");
    }

    public function storeLine(Request $request, BrandCatalogueBrand $brand): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $brand->lines()->create([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug('brand_catalogue_lines', 'brand_catalogue_brand_id', $brand->id, trim($data['name'])),
            'note' => $this->nullTrim($data['note'] ?? null),
            'url' => $this->nullTrim($data['url'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_default' => false,
            'is_active' => true,
        ]);

        return redirect()->back()->with('status', "Line \"{$data['name']}\" added.");
    }

    public function storeProductType(Request $request, BrandCatalogueLine $line): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $line->productTypes()->create([
            'brand_catalogue_brand_id' => $line->brand_catalogue_brand_id,
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug('brand_catalogue_product_types', 'brand_catalogue_line_id', $line->id, trim($data['name'])),
            'note' => $this->nullTrim($data['note'] ?? null),
            'url' => $this->nullTrim($data['url'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => true,
        ]);

        return redirect()->back()->with('status', "Product type \"{$data['name']}\" added.");
    }

    public function storeMaterial(Request $request, BrandCatalogueProductType $productType): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $productType->materials()->create([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug('brand_catalogue_materials', 'brand_catalogue_product_type_id', $productType->id, trim($data['name'])),
            'note' => $this->nullTrim($data['note'] ?? null),
            'url' => $this->nullTrim($data['url'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => true,
        ]);

        return redirect()->back()->with('status', "Material \"{$data['name']}\" added.");
    }

    public function storeStyle(Request $request, BrandCatalogueProductType $productType): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'material_name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $materialName = trim($data['material_name'] ?? '') ?: 'Synthetic Hair';
        $brandId = $productType->brand->id;

        $productType->styles()->create([
            'brand_catalogue_brand_id' => $brandId,
            'brand_catalogue_product_type_id' => $productType->id,
            'brand_catalogue_material_id' => $this->matchingMaterialIdForName($productType, $materialName),
            'material_name' => $materialName,
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $productType->id, trim($data['name'])),
            'note' => $this->nullTrim($data['note'] ?? null),
            'url' => $this->nullTrim($data['url'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => true,
        ]);

        return redirect()->back()->with('status', "Style \"{$data['name']}\" added.");
    }

    public function storeVariant(Request $request, BrandCatalogueStyle $style): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'variant_type' => ['required', 'string', 'in:'.implode(',', BrandCatalogueVariant::TYPES)],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $style->variants()->create([
            'name' => trim($data['name']),
            'variant_type' => $data['variant_type'],
            'url' => $this->nullTrim($data['url'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return redirect()->back()->with('status', "Variant group \"{$data['name']}\" added.");
    }

    public function storeVariantOption(Request $request, BrandCatalogueVariant $variant): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'value' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $variant->options()->create([
            'label' => trim($data['label']),
            'value' => $this->variantOptionValueFromRequest($data),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return redirect()->back()->with('status', "Option \"{$data['label']}\" added.");
    }

    public function storeSku(Request $request, BrandCatalogueStyle $style): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku_code' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'variant_option_ids' => ['nullable', 'array'],
            'variant_option_ids.*' => ['nullable', 'integer'],
        ]);

        $style->loadMissing('variants.options');

        [$signature, $syncPayload] = $this->prepareSkuSelections($style, $data['variant_option_ids'] ?? []);

        $sku = DB::transaction(function () use ($style, $data, $signature, $syncPayload) {
            $sku = $style->skus()->create([
                'name' => trim($data['name']),
                'slug' => $this->uniqueSlug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, trim($data['name'])),
                'sku_code' => $this->nullTrim($data['sku_code'] ?? null),
                'barcode' => $this->nullTrim($data['barcode'] ?? null),
                'option_signature' => $signature,
                'description' => $this->nullTrim($data['description'] ?? null),
                'note' => $this->nullTrim($data['note'] ?? null),
                'url' => $this->nullTrim($data['url'] ?? null),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => true,
            ]);

            $sku->optionValues()->sync($syncPayload);

            // Auto-allocate the unified SKU code if the user left the field
            // blank. Whatever they typed wins; otherwise the allocator issues
            // {DEPT}-{BRAND}-{FFFFF}{V} (e.g. HE-XPR-00007I).
            if ($sku->sku_code === null || $sku->sku_code === '') {
                $allocated = app(SkuCodeAllocator::class)->allocateForCatalogueSku($sku->fresh('style.brand.catalogue'));
                $sku->forceFill(['sku_code' => $allocated])->save();
            }

            return $sku;
        });

        return redirect()->back()->with('status', "Sellable SKU \"{$sku->name}\" added (".($sku->sku_code ?? 'no code').').');
    }

    public function syncVariantMatrix(Request $request, BrandCatalogueStyle $style): RedirectResponse
    {
        $data = $request->validate([
            'main_variant_name' => ['required', 'string', 'max:80'],
            'main_variant_type' => ['required', 'string', 'in:measurement,colour_name,colour_code,short_code,count,text'],
            'sub_variant_name' => ['required', 'string', 'max:80'],
            'sub_variant_type' => ['required', 'string', 'in:measurement,colour_name,colour_code,short_code,count,text'],
            'common_variant_name' => ['nullable', 'string', 'max:80'],
            'common_variant_type' => ['nullable', 'string', 'in:measurement,colour_name,colour_code,short_code,count,text'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.main_value' => ['nullable', 'string', 'max:255'],
            'rows.*.sub_values' => ['nullable', 'string', 'max:10000'],
            'rows.*.common_values' => ['nullable', 'string', 'max:1000'],
            'remove_missing' => ['nullable', 'boolean'],
        ]);

        $mainVariantName = trim($data['main_variant_name']);
        $subVariantName = trim($data['sub_variant_name']);
        $commonVariantName = $this->nullTrim($data['common_variant_name'] ?? null);
        $removeMissing = $request->boolean('remove_missing');

        if (strcasecmp($mainVariantName, $subVariantName) === 0) {
            throw ValidationException::withMessages([
                'sub_variant_name' => 'Main variant and sub variant must be different.',
            ]);
        }

        if ($commonVariantName && (
            strcasecmp($commonVariantName, $mainVariantName) === 0
            || strcasecmp($commonVariantName, $subVariantName) === 0
        )) {
            throw ValidationException::withMessages([
                'common_variant_name' => 'Common variant must be different from the main and sub variants.',
            ]);
        }

        $matrixRows = collect($data['rows'])
            ->map(function (array $row) use ($commonVariantName): array {
                $mainValue = trim((string) ($row['main_value'] ?? ''));
                $subValues = $this->splitVariantValues((string) ($row['sub_values'] ?? ''));
                $commonValues = $commonVariantName
                    ? $this->splitVariantValues((string) ($row['common_values'] ?? ''))
                    : [];

                return [
                    'main_value' => $mainValue,
                    'sub_values' => $subValues,
                    'common_values' => $commonValues,
                ];
            })
            ->filter(fn (array $row): bool => $row['main_value'] !== '' && count($row['sub_values']) > 0)
            ->values();

        if ($matrixRows->isEmpty()) {
            throw ValidationException::withMessages([
                'rows' => 'Add at least one main value with sub-variant values.',
            ]);
        }

        if ($commonVariantName && $matrixRows->contains(fn (array $row): bool => count($row['common_values']) === 0)) {
            throw ValidationException::withMessages([
                'rows' => 'Every row must include a common variant value, or remove the common variant name.',
            ]);
        }

        $result = DB::transaction(function () use (
            $style,
            $data,
            $matrixRows,
            $mainVariantName,
            $subVariantName,
            $commonVariantName,
            $removeMissing,
        ): array {
            $style->loadMissing('variants.options', 'skus.optionValues.variant');

            $mainVariant = $this->findOrCreateStyleVariant($style, $mainVariantName, $data['main_variant_type'], 10);
            $subVariant = $this->findOrCreateStyleVariant($style, $subVariantName, $data['sub_variant_type'], 20);
            $commonVariant = $commonVariantName
                ? $this->findOrCreateStyleVariant($style, $commonVariantName, $data['common_variant_type'] ?? 'text', 30)
                : null;

            $expected = [];
            $createdOptions = 0;
            $updatedSkus = 0;
            $createdSkus = 0;

            foreach ($matrixRows as $rowIndex => $row) {
                $mainOption = $this->findOrCreateVariantOption($mainVariant, $row['main_value'], $rowIndex + 1, $createdOptions);

                foreach ($row['sub_values'] as $subIndex => $subValue) {
                    $subOption = $this->findOrCreateVariantOption($subVariant, $subValue, $subIndex + 1, $createdOptions);
                    $commonValues = $commonVariant ? $row['common_values'] : [null];

                    foreach ($commonValues as $commonIndex => $commonValue) {
                        $commonOption = $commonVariant
                            ? $this->findOrCreateVariantOption($commonVariant, (string) $commonValue, $commonIndex + 1, $createdOptions)
                            : null;

                        $options = collect([$mainOption, $subOption, $commonOption])->filter()->values();
                        $signature = $this->optionSignatureFromOptions($options);
                        $expected[$signature] = true;

                        $name = $this->skuNameFromMatrixOptions($style, $mainVariant, $mainOption, $subVariant, $subOption, $commonVariant, $commonOption);

                        $sku = BrandCatalogueSku::query()
                            ->where('brand_catalogue_style_id', $style->id)
                            ->where('option_signature', $signature)
                            ->first();

                        if ($sku) {
                            $sku->update([
                                'name' => $name,
                                'slug' => $this->uniqueSlug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $name, $sku->id),
                                'sort_order' => count($expected),
                                'is_active' => true,
                            ]);
                            $updatedSkus++;
                        } else {
                            $sku = $style->skus()->create([
                                'name' => $name,
                                'slug' => $this->uniqueSlug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $name),
                                'option_signature' => $signature,
                                'sort_order' => count($expected),
                                'is_active' => true,
                            ]);
                            $createdSkus++;
                        }

                        $sku->optionValues()->sync($options->mapWithKeys(fn (BrandCatalogueVariantOption $option) => [
                            $option->id => ['brand_catalogue_variant_id' => $option->variant_id],
                        ])->all());
                    }
                }
            }

            $removedSkus = 0;
            if ($removeMissing) {
                $style->skus()
                    ->whereNotIn('option_signature', array_keys($expected))
                    ->get()
                    ->each(function (BrandCatalogueSku $sku) use (&$removedSkus): void {
                        $sku->optionValues()->detach();
                        $sku->delete();
                        $removedSkus++;
                    });
            }

            return [
                'created_options' => $createdOptions,
                'created_skus' => $createdSkus,
                'updated_skus' => $updatedSkus,
                'removed_skus' => $removedSkus,
                'expected_skus' => count($expected),
            ];
        });

        return redirect()
            ->back()
            ->with('status', "Variant matrix synced: {$result['expected_skus']} exact SKU combination(s), {$result['created_skus']} created, {$result['updated_skus']} updated, {$result['removed_skus']} removed.");
    }

    public function updateCatalogue(Request $request, BrandCatalogue $catalogue): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $catalogue->update([
            'name' => trim($data['name']),
            'slug' => Str::slug($data['name']),
            'note' => $this->nullTrim($data['note'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return redirect()->back()->with('status', 'Catalogue updated.');
    }

    public function updateBrand(Request $request, BrandCatalogueBrand $brand): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $oldName = $brand->name;
        $oldNote = $brand->note;
        $oldUrl = $brand->url;
        $newName = trim($data['name']);
        $newNote = $this->nullTrim($data['note'] ?? null);
        $newUrl = $this->nullTrim($data['url'] ?? null);
        $defaultLine = $brand->defaultLine;

        DB::transaction(function () use ($brand, $defaultLine, $oldNote, $oldUrl, $newName, $newNote, $newUrl, $data) {
            $brand->update([
                'name' => $newName,
                'slug' => $this->uniqueSlug('brand_catalogue_brands', 'brand_catalogue_id', $brand->brand_catalogue_id, $newName, $brand->id),
                'note' => $newNote,
                'url' => $newUrl,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);

            if ($defaultLine) {
                $defaultLine->update([
                    'name' => $newName,
                    'slug' => $this->uniqueSlug('brand_catalogue_lines', 'brand_catalogue_brand_id', $brand->id, $newName, $defaultLine->id),
                    'note' => ($defaultLine->note === null || $defaultLine->note === $oldNote) ? $newNote : $defaultLine->note,
                    'url' => ($defaultLine->url === null || $defaultLine->url === $oldUrl) ? $newUrl : $defaultLine->url,
                ]);
            } else {
                $this->ensureDefaultLineForBrand($brand->fresh());
            }
        });

        return redirect()->back()->with('status', 'Brand updated.');
    }

    public function updateLine(Request $request, BrandCatalogueLine $line): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $line->update([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug('brand_catalogue_lines', 'brand_catalogue_brand_id', $line->brand_catalogue_brand_id, trim($data['name']), $line->id),
            'note' => $this->nullTrim($data['note'] ?? null),
            'url' => $this->nullTrim($data['url'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return redirect()->back()->with('status', 'Line updated.');
    }

    public function updateProductType(Request $request, BrandCatalogueProductType $productType): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $productType->update([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug('brand_catalogue_product_types', 'brand_catalogue_line_id', (int) $productType->brand_catalogue_line_id, trim($data['name']), $productType->id),
            'note' => $this->nullTrim($data['note'] ?? null),
            'url' => $this->nullTrim($data['url'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return redirect()->back()->with('status', 'Product type updated.');
    }

    public function updateMaterial(Request $request, BrandCatalogueMaterial $material): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $material->update([
            'name' => trim($data['name']),
            'slug' => $this->uniqueSlug('brand_catalogue_materials', 'brand_catalogue_product_type_id', $material->brand_catalogue_product_type_id, trim($data['name']), $material->id),
            'note' => $this->nullTrim($data['note'] ?? null),
            'url' => $this->nullTrim($data['url'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return redirect()->back()->with('status', 'Material updated.');
    }

    public function updateStyle(Request $request, BrandCatalogueStyle $style): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand_catalogue_product_type_id' => ['nullable', 'integer'],
            'material_name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $productTypeId = (int) ($data['brand_catalogue_product_type_id'] ?? ($style->brand_catalogue_product_type_id ?: $style->material?->brand_catalogue_product_type_id));
        $productType = $productTypeId > 0 ? BrandCatalogueProductType::find($productTypeId) : null;

        if (! $productType || (int) $productType->brand_catalogue_brand_id !== (int) $style->brand_catalogue_brand_id) {
            throw ValidationException::withMessages([
                'brand_catalogue_product_type_id' => 'Choose a valid product type for this brand.',
            ]);
        }

        $materialName = trim($data['material_name'] ?? '') ?: ($style->material_name ?: 'Synthetic Hair');

        $style->update([
            'name' => trim($data['name']),
            'brand_catalogue_product_type_id' => $productTypeId ?: null,
            'brand_catalogue_material_id' => $productType ? $this->matchingMaterialIdForName($productType, $materialName) : null,
            'material_name' => $materialName,
            'slug' => $this->uniqueSlug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $productTypeId ?: 0, trim($data['name']), $style->id),
            'note' => $this->nullTrim($data['note'] ?? null),
            'url' => $this->nullTrim($data['url'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return redirect()
            ->route('brand-catalogue.styles.show', [
                $productType->brand?->catalogue,
                $productType->brand,
                $productType->line,
                $productType,
                $style,
            ])
            ->with('status', 'Style updated.');
    }

    public function updateVariant(Request $request, BrandCatalogueVariant $variant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'variant_type' => ['required', 'string', 'in:'.implode(',', BrandCatalogueVariant::TYPES)],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $variant->update([
            'name' => trim($data['name']),
            'variant_type' => $data['variant_type'],
            'url' => $this->nullTrim($data['url'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return redirect()->back()->with('status', 'Variant group updated.');
    }

    public function updateVariantOption(Request $request, BrandCatalogueVariantOption $option): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'value' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $option->update([
            'label' => trim($data['label']),
            'value' => $this->variantOptionValueFromRequest($data),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return redirect()->back()->with('status', 'Option updated.');
    }

    public function updateSku(Request $request, BrandCatalogueSku $sku): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku_code' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'variant_option_ids' => ['nullable', 'array'],
            'variant_option_ids.*' => ['nullable', 'integer'],
        ]);

        $style = $sku->style()->with('variants.options')->firstOrFail();
        [$signature, $syncPayload] = $this->prepareSkuSelections($style, $data['variant_option_ids'] ?? [], $sku);

        DB::transaction(function () use ($sku, $style, $data, $signature, $syncPayload) {
            $newCode = $this->nullTrim($data['sku_code'] ?? null);

            $sku->update([
                'name' => trim($data['name']),
                'slug' => $this->uniqueSlug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, trim($data['name']), $sku->id),
                'sku_code' => $newCode,
                'barcode' => $this->nullTrim($data['barcode'] ?? null),
                'option_signature' => $signature,
                'description' => $this->nullTrim($data['description'] ?? null),
                'note' => $this->nullTrim($data['note'] ?? null),
                'url' => $this->nullTrim($data['url'] ?? null),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);

            $sku->optionValues()->sync($syncPayload);

            // If the field was cleared (or has never had a code) and there's
            // no manual override, re-issue under the unified scheme.
            if ($sku->sku_code === null || $sku->sku_code === '') {
                $allocated = app(SkuCodeAllocator::class)->allocateForCatalogueSku($sku->fresh('style.brand.catalogue'));
                $sku->forceFill(['sku_code' => $allocated])->save();
            }

            // Keep any linked retail product's SKU in sync with the catalogue
            // (single source of truth). Skip if the catalogue code is blank.
            if ($sku->sku_code) {
                Product::query()
                    ->where('brand_catalogue_sku_id', $sku->id)
                    ->update([
                        'sku' => $sku->sku_code,
                    ]);
            }
        });

        return redirect()->back()->with('status', 'Sellable SKU updated.');
    }

    public function viewCatalogue(Request $request, BrandCatalogue $catalogue): View
    {
        $catalogue->load([
            'brands' => fn ($query) => $query->withCount(['lines', 'productTypes']),
            'brands.lines' => fn ($query) => $query
                ->withCount('productTypes')
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->orderBy('name'),
        ]);

        return view('brand-catalogue.view-catalogue', [
            'catalogue' => $catalogue,
            'catalogueBrands' => $this->catalogueBrandsForDisplay($catalogue),
            'catalogueLineCount' => $this->catalogueLines($catalogue)->count(),
            'viewMode' => $request->query('view', 'grid'),
        ]);
    }

    public function viewBrand(Request $request, BrandCatalogue $catalogue, BrandCatalogueBrand $brand): View
    {
        $this->assertBrandInCatalogue($catalogue, $brand);

        $brand->loadCount(['lines', 'productTypes', 'styles']);
        $brand->load([
            'lines' => fn ($query) => $query->withCount('productTypes'),
        ]);

        return view('brand-catalogue.view-brand', [
            'catalogue' => $catalogue,
            'brand' => $brand,
            'viewMode' => $request->query('view', 'grid'),
        ]);
    }

    public function viewLine(
        Request $request,
        BrandCatalogue $catalogue,
        BrandCatalogueBrand $brand,
        BrandCatalogueLine $line,
    ): View {
        $this->assertBrandInCatalogue($catalogue, $brand);
        $this->assertLineInBrand($brand, $line);

        $line->load([
            'productTypes' => fn ($query) => $query->withCount('styles'),
        ]);

        return view('brand-catalogue.view-line', [
            'catalogue' => $catalogue,
            'brand' => $brand,
            'line' => $line,
            'viewMode' => $request->query('view', 'grid'),
        ]);
    }

    public function viewProductType(
        Request $request,
        BrandCatalogue $catalogue,
        BrandCatalogueBrand $brand,
        BrandCatalogueLine $line,
        BrandCatalogueProductType $productType,
    ): View {
        $this->assertBrandInCatalogue($catalogue, $brand);
        $this->assertLineInBrand($brand, $line);
        $this->assertProductTypeInLine($line, $productType);

        $productType->load([
            'styles' => fn ($query) => $query->withCount('variants'),
        ]);

        return view('brand-catalogue.view-product-type', [
            'catalogue' => $catalogue,
            'brand' => $brand,
            'line' => $line,
            'productType' => $productType,
            'viewMode' => $request->query('view', 'grid'),
        ]);
    }

    public function viewMaterial(
        Request $request,
        BrandCatalogue $catalogue,
        BrandCatalogueBrand $brand,
        BrandCatalogueLine $line,
        BrandCatalogueProductType $productType,
        BrandCatalogueMaterial $material,
    ): RedirectResponse {
        $this->assertBrandInCatalogue($catalogue, $brand);
        $this->assertLineInBrand($brand, $line);
        $this->assertProductTypeInLine($line, $productType);
        $this->assertMaterialInProductType($productType, $material);

        return redirect()->route('brand-catalogue.view.product-type', [
            $catalogue,
            $brand,
            $line,
            $productType,
            'view' => $request->query('view', 'grid'),
        ]);
    }

    public function viewStyle(
        BrandCatalogue $catalogue,
        BrandCatalogueBrand $brand,
        BrandCatalogueLine $line,
        BrandCatalogueProductType $productType,
        BrandCatalogueStyle $style,
    ): View {
        $this->assertBrandInCatalogue($catalogue, $brand);
        $this->assertLineInBrand($brand, $line);
        $this->assertProductTypeInLine($line, $productType);
        $this->assertStyleInProductType($brand, $productType, $style);

        $style->load([
            'variants.options.images',
            'images',
            'skus.optionValues.variant',
            'skus.optionValues.images',
            'skus.images',
        ]);

        return view('brand-catalogue.view-style', [
            'catalogue' => $catalogue,
            'brand' => $brand,
            'line' => $line,
            'productType' => $productType,
            'style' => $style,
        ]);
    }

    public function viewStyleLegacy(
        BrandCatalogue $catalogue,
        BrandCatalogueBrand $brand,
        BrandCatalogueLine $line,
        BrandCatalogueProductType $productType,
        BrandCatalogueMaterial $material,
        BrandCatalogueStyle $style,
    ): RedirectResponse {
        $this->assertBrandInCatalogue($catalogue, $brand);
        $this->assertLineInBrand($brand, $line);
        $this->assertProductTypeInLine($line, $productType);
        $this->assertMaterialInProductType($productType, $material);
        $this->assertStyleInProductType($brand, $productType, $style);

        return redirect()->route('brand-catalogue.view.style', [$catalogue, $brand, $line, $productType, $style]);
    }

    /**
     * Chrome extension API — returns the scaffolding (brands, product types, materials)
     * for a catalogue so dropdowns can cascade properly.
     */
    public function apiScaffolding(BrandCatalogue $catalogue): JsonResponse
    {
        $catalogue->load([
            'brands' => fn ($q) => $q->orderBy('name'),
            'brands.defaultLine',
            'brands.lines' => fn ($q) => $q->orderByDesc('is_default')->orderBy('sort_order')->orderBy('name'),
            'brands.lines.productTypes' => fn ($q) => $q->orderBy('name'),
            'brands.productTypes' => fn ($q) => $q->orderBy('name'),
            'brands.productTypes.line',
        ]);

        $data = [
            'catalogue' => ['id' => $catalogue->id, 'name' => $catalogue->name],
            'material_options' => $this->materialOptions(),
            'brands' => $catalogue->brands->map(fn (BrandCatalogueBrand $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'url' => $b->url,
                'default_line_id' => $b->defaultLine?->id,
                'lines' => $b->lines->map(fn (BrandCatalogueLine $line) => [
                    'id' => $line->id,
                    'name' => $line->name,
                    'url' => $line->url,
                    'is_default' => $line->is_default,
                    'product_types' => $line->productTypes->map(fn (BrandCatalogueProductType $pt) => [
                        'id' => $pt->id,
                        'name' => $pt->name,
                    ])->values(),
                ])->values(),
                'product_types' => $b->productTypes->map(fn (BrandCatalogueProductType $pt) => [
                    'id' => $pt->id,
                    'name' => $pt->name,
                    'line_id' => $pt->brand_catalogue_line_id,
                    'line_name' => $pt->line?->name,
                ])->values(),
            ])->values(),
        ];

        return response()->json($data, 200, [
            'Access-Control-Allow-Origin' => '*',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Chrome extension API — receives captured product data and creates
     * styles (+ variants + options) in the appropriate place in the hierarchy.
     */
    public function apiCaptureStore(Request $request): JsonResponse
    {
        $products = $request->input('products', []);
        $created = [];

        foreach ($products as $product) {
            $brandId = $product['brandId'] ?? null;
            $productTypeId = $product['productTypeId'] ?? null;
            $materialId = $product['materialId'] ?? null;
            $materialName = $this->nullTrim($product['materialName'] ?? null);

            if ((! $brandId && ! $productTypeId && ! $materialId) || (! $productTypeId && ! $materialId)) {
                continue;
            }

            $material = $materialId ? BrandCatalogueMaterial::find($materialId) : null;
            $productType = $productTypeId ? BrandCatalogueProductType::find($productTypeId) : $material?->productType;
            $brand = $brandId ? BrandCatalogueBrand::find($brandId) : $productType?->brand;

            if (! $brand || ! $productType) {
                continue;
            }

            $resolvedMaterialName = $materialName ?: $material?->name ?: 'Unknown Material';

            // Create the style
            $style = BrandCatalogueStyle::create([
                'brand_catalogue_brand_id' => $brand->id,
                'brand_catalogue_product_type_id' => $productType->id,
                'brand_catalogue_material_id' => $material?->id,
                'material_name' => $resolvedMaterialName,
                'name' => $product['styleName'] ?? 'Untitled Style',
                'slug' => $this->uniqueSlug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $productType->id, $product['styleName'] ?? 'Untitled Style'),
                'note' => $this->nullTrim($product['note'] ?? null),
                'url' => $this->nullTrim($product['sourceUrl'] ?? null),
                'sort_order' => 0,
            ]);

            // Create variants and options
            if (! empty($product['variants'])) {
                foreach ($product['variants'] as $sortIdx => $variant) {
                    $vg = BrandCatalogueVariant::create([
                        'brand_catalogue_style_id' => $style->id,
                        'name' => $variant['name'] ?? 'Unknown',
                        'variant_type' => 'text',
                        'sort_order' => $sortIdx * 10,
                    ]);

                    foreach (($variant['options'] ?? []) as $optIdx => $option) {
                        BrandCatalogueVariantOption::create([
                            'variant_id' => $vg->id,
                            'label' => $option['label'] ?? $option['value'] ?? '',
                            'value' => $option['value'] ?? $option['label'] ?? '',
                            'sort_order' => $optIdx * 10,
                        ]);
                    }
                }
            }

            $created[] = [
                'id' => $style->id,
                'name' => $style->name,
                'brand' => $brand->name,
                'line' => $productType->line?->name,
                'product_type' => $productType->name,
                'material' => $resolvedMaterialName,
            ];
        }

        return response()->json([
            'success' => true,
            'created' => $created,
            'count' => count($created),
        ], 200, [
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public function exportJson(BrandCatalogue $catalogue): JsonResponse
    {
        $catalogue->load([
            'brands.lines.productTypes.styles.variants.options.images',
            'brands.lines.productTypes.styles.images',
            'brands.lines.productTypes.styles.skus.optionValues.variant',
            'brands.lines.productTypes.styles.skus.optionValues.images',
            'brands.lines.productTypes.styles.skus.images',
        ]);

        $data = [
            'catalogue' => $catalogue->name,
            'exported_at' => now()->toIso8601String(),
            'brands' => $catalogue->brands->map(fn (BrandCatalogueBrand $brand) => [
                'name' => $brand->name,
                'url' => $brand->url,
                'note' => $brand->note,
                'lines' => $brand->lines->map(fn (BrandCatalogueLine $line) => [
                    'name' => $line->name,
                    'url' => $line->url,
                    'note' => $line->note,
                    'is_default' => $line->is_default,
                    'product_types' => $line->productTypes->map(fn (BrandCatalogueProductType $productType) => [
                        'name' => $productType->name,
                        'url' => $productType->url,
                        'note' => $productType->note,
                        'styles' => $productType->styles->map(fn (BrandCatalogueStyle $style) => [
                            'name' => $style->name,
                            'material' => $style->material_name,
                            'url' => $style->url,
                            'note' => $style->note,
                            'primary_image_url' => $style->primaryImage()?->displayUrl(),
                            'images' => $style->images->map(fn ($image) => [
                                'role' => $image->image_role,
                                'display_url' => $image->displayUrl(),
                                'external_url' => $image->external_url,
                                'is_primary' => $image->is_primary,
                                'notes' => $image->notes,
                            ])->values(),
                            'variants' => $style->variants->map(fn (BrandCatalogueVariant $variant) => [
                                'name' => $variant->name,
                                'type' => $variant->variant_type,
                                'url' => $variant->url,
                                'options' => $variant->options->map(fn (BrandCatalogueVariantOption $option) => [
                                    'label' => $option->label,
                                    'value' => $option->value,
                                    'primary_image_url' => $option->primaryImage()?->displayUrl(),
                                    'images' => $option->images->map(fn ($image) => [
                                        'role' => $image->image_role,
                                        'display_url' => $image->displayUrl(),
                                        'external_url' => $image->external_url,
                                        'is_primary' => $image->is_primary,
                                        'notes' => $image->notes,
                                    ])->values(),
                                ])->values(),
                            ])->values(),
                            'skus' => $style->skus->map(fn (BrandCatalogueSku $sku) => [
                                'name' => $sku->name,
                                'sku_code' => $sku->sku_code,
                                'barcode' => $sku->barcode,
                                'url' => $sku->url,
                                'description' => $sku->description,
                                'note' => $sku->note,
                                'resolved_primary_image_url' => $sku->primaryImage()?->displayUrl(),
                                'selected_options' => $sku->optionValues
                                    ->sortBy(fn (BrandCatalogueVariantOption $option) => sprintf(
                                        '%04d:%s:%04d:%s',
                                        $option->variant->sort_order,
                                        $option->variant->name,
                                        $option->sort_order,
                                        $option->label,
                                    ))
                                    ->values()
                                    ->map(fn (BrandCatalogueVariantOption $option) => [
                                        'variant_group' => $option->variant->name,
                                        'label' => $option->label,
                                        'value' => $option->value,
                                    ])->values(),
                                'images' => $sku->images->map(fn ($image) => [
                                    'role' => $image->image_role,
                                    'display_url' => $image->displayUrl(),
                                    'external_url' => $image->external_url,
                                    'is_primary' => $image->is_primary,
                                    'notes' => $image->notes,
                                ])->values(),
                            ])->values(),
                        ])->values(),
                    ])->values(),
                ])->values(),
            ])->values(),
        ];

        return response()->json($data, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function destroyBrand(BrandCatalogueBrand $brand): RedirectResponse
    {
        $name = $brand->name;
        $catalogue = $brand->catalogue;
        $brand->delete();

        return redirect()->route('brand-catalogue.show', $catalogue)->with('status', "Brand \"{$name}\" deleted.");
    }

    public function destroyLine(BrandCatalogueLine $line): RedirectResponse
    {
        if ($line->is_default) {
            return redirect()->back()->with('status', 'Default line cannot be deleted. Rename the master brand instead.');
        }

        $name = $line->name;
        $line->delete();

        return redirect()->back()->with('status', "Line \"{$name}\" deleted.");
    }

    public function bulkDestroyLines(Request $request): RedirectResponse
    {
        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return redirect()->back()->with('warning', 'No lines selected.');
        }

        $deleted = BrandCatalogueLine::query()
            ->whereIn('id', $ids)
            ->where('is_default', false)
            ->delete();

        return redirect()->back()->with('status', "{$deleted} line(s) deleted.");
    }

    public function destroyProductType(BrandCatalogueProductType $productType): RedirectResponse
    {
        $name = $productType->name;
        $productType->delete();

        return redirect()->back()->with('status', "Product type \"{$name}\" deleted.");
    }

    public function destroyMaterial(BrandCatalogueMaterial $material): RedirectResponse
    {
        $name = $material->name;
        $material->delete();

        return redirect()->back()->with('status', "Material \"{$name}\" deleted.");
    }

    public function destroyStyle(BrandCatalogueStyle $style): RedirectResponse
    {
        $name = $style->name;
        $style->delete();

        return redirect()->back()->with('status', "Style \"{$name}\" deleted.");
    }

    public function destroySku(BrandCatalogueSku $sku): RedirectResponse
    {
        $name = $sku->name;
        $sku->delete();

        return redirect()->back()->with('status', "Sellable SKU \"{$name}\" deleted.");
    }

    public function bulkDestroySkus(Request $request, BrandCatalogueStyle $style): RedirectResponse
    {
        $ids = collect($request->input('ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return redirect()->back()->with('warning', 'No sellable SKUs selected.');
        }

        $deleted = BrandCatalogueSku::query()
            ->where('brand_catalogue_style_id', $style->id)
            ->whereIn('id', $ids->all())
            ->delete();

        return redirect()->back()->with('status', "{$deleted} sellable SKU(s) deleted.");
    }

    public function destroyVariant(BrandCatalogueVariant $variant): RedirectResponse
    {
        if ($variant->options()->exists()) {
            throw ValidationException::withMessages([
                'variant' => 'Remove all options before deleting this variant group.',
            ]);
        }

        $name = $variant->name;
        $variant->delete();

        return redirect()->back()->with('status', "Variant group \"{$name}\" deleted.");
    }

    public function destroyVariantOption(BrandCatalogueVariantOption $option): RedirectResponse
    {
        $label = $option->label;
        $option->delete();

        return redirect()->back()->with('status', "Option \"{$label}\" deleted.");
    }

    private function assertBrandInCatalogue(BrandCatalogue $catalogue, BrandCatalogueBrand $brand): void
    {
        abort_unless((int) $brand->brand_catalogue_id === (int) $catalogue->id, 404);
    }

    private function assertLineInBrand(BrandCatalogueBrand $brand, BrandCatalogueLine $line): void
    {
        abort_unless((int) $line->brand_catalogue_brand_id === (int) $brand->id, 404);
    }

    private function assertProductTypeInLine(BrandCatalogueLine $line, BrandCatalogueProductType $productType): void
    {
        abort_unless((int) $productType->brand_catalogue_line_id === (int) $line->id, 404);
        abort_unless((int) $productType->brand_catalogue_brand_id === (int) $line->brand_catalogue_brand_id, 404);
    }

    private function assertMaterialInProductType(BrandCatalogueProductType $productType, BrandCatalogueMaterial $material): void
    {
        abort_unless((int) $material->brand_catalogue_product_type_id === (int) $productType->id, 404);
    }

    private function assertStyleInProductType(BrandCatalogueBrand $brand, BrandCatalogueProductType $productType, BrandCatalogueStyle $style): void
    {
        abort_unless((int) $style->brand_catalogue_brand_id === (int) $brand->id, 404);
        abort_unless((int) $style->brand_catalogue_product_type_id === (int) $productType->id, 404);
    }

    private function assertSkuInStyle(BrandCatalogueStyle $style, BrandCatalogueSku $sku): void
    {
        abort_unless((int) $sku->brand_catalogue_style_id === (int) $style->id, 404);
    }

    private function nullTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array{label: string, value?: string|null}  $data
     */
    private function variantOptionValueFromRequest(array $data): string
    {
        $label = trim($data['label']);

        return $this->nullTrim($data['value'] ?? null) ?? $label;
    }

    private function ensureDefaultLineForBrand(BrandCatalogueBrand $brand): BrandCatalogueLine
    {
        $existing = $brand->defaultLine()->first();

        if ($existing) {
            return $existing;
        }

        return $brand->lines()->create([
            'name' => $brand->name,
            'slug' => $this->uniqueSlug('brand_catalogue_lines', 'brand_catalogue_brand_id', $brand->id, $brand->name),
            'note' => $brand->note,
            'url' => $brand->url,
            'is_default' => true,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    private function findOrCreateProductTypeOnLine(BrandCatalogueLine $line, BrandCatalogueProductType $sourceProductType): BrandCatalogueProductType
    {
        $existing = BrandCatalogueProductType::query()
            ->where('brand_catalogue_line_id', $line->id)
            ->where('name', $sourceProductType->name)
            ->first();

        if ($existing) {
            return $existing;
        }

        return $line->productTypes()->create([
            'brand_catalogue_brand_id' => $line->brand_catalogue_brand_id,
            'name' => $sourceProductType->name,
            'slug' => $this->uniqueSlug(
                'brand_catalogue_product_types',
                'brand_catalogue_line_id',
                $line->id,
                $sourceProductType->name,
            ),
            'note' => $sourceProductType->note,
            'url' => $sourceProductType->url,
            'sort_order' => $sourceProductType->sort_order,
            'is_active' => $sourceProductType->is_active,
        ]);
    }

    private function styleNameWithFormerLine(BrandCatalogueLine $line, BrandCatalogueStyle $style): string
    {
        $lineName = trim($line->name);
        $styleName = trim($style->name);

        if ($lineName === '' || Str::startsWith(Str::lower($styleName), Str::lower($lineName))) {
            return $styleName;
        }

        return trim($lineName.' '.$styleName);
    }

    private function catalogueLines(BrandCatalogue $catalogue): Collection
    {
        return $catalogue->brands
            ->flatMap(function (BrandCatalogueBrand $brand) {
                $hasNamedLines = $brand->lines->contains(fn (BrandCatalogueLine $candidate) => ! $candidate->is_default);

                return $brand->lines
                    ->reject(fn (BrandCatalogueLine $line) => $line->is_default && $hasNamedLines && (int) $line->product_types_count === 0)
                    ->map(function (BrandCatalogueLine $line) use ($brand) {
                        $line->setRelation('brand', $brand);

                        return $line;
                    });
            })
            ->sortBy(fn (BrandCatalogueLine $line) => sprintf(
                '%04d:%s:%d:%04d:%s',
                $line->brand->sort_order,
                Str::lower($line->brand->name),
                $line->is_default ? 0 : 1,
                $line->sort_order,
                Str::lower($line->name),
            ))
            ->values();
    }

    private function catalogueBrandsForDisplay(BrandCatalogue $catalogue): Collection
    {
        return $catalogue->brands
            ->map(function (BrandCatalogueBrand $brand) {
                $hasNamedLines = $brand->lines->contains(fn (BrandCatalogueLine $candidate) => ! $candidate->is_default);

                $displayLines = $brand->lines
                    ->reject(fn (BrandCatalogueLine $line) => $line->is_default && $hasNamedLines && (int) $line->product_types_count === 0)
                    ->values();

                $brand->setRelation('lines', $displayLines);

                return $brand;
            })
            ->filter(fn (BrandCatalogueBrand $brand) => $brand->lines->isNotEmpty())
            ->sortBy(fn (BrandCatalogueBrand $brand) => sprintf(
                '%04d:%s',
                $brand->sort_order,
                Str::lower($brand->name),
            ))
            ->values();
    }

    private function catalogueProductFinder(BrandCatalogue $catalogue, Request $request, Collection $catalogueBrands): array
    {
        $query = $this->nullTrim((string) $request->query('product_search', $request->query('q', ''))) ?? '';
        $brandId = (int) $request->query('product_brand', 0);
        $brandIds = $catalogueBrands->pluck('id')->map(fn (mixed $id): int => (int) $id)->values();
        $brandNames = $catalogueBrands
            ->pluck('name')
            ->map(fn (string $name): string => Str::lower($name))
            ->values();
        $selectedBrand = $catalogueBrands->firstWhere('id', $brandId);
        $selectedBrandId = $selectedBrand ? (int) $selectedBrand->id : null;
        $searched = $query !== '' || $selectedBrandId !== null;

        if (! $searched) {
            return [
                'query' => $query,
                'selected_brand_id' => null,
                'searched' => false,
                'submitted' => collect(),
                'retail' => collect(),
                'catalogue' => collect(),
                'counts' => ['submitted' => 0, 'retail' => 0, 'catalogue' => 0],
            ];
        }

        $submitted = $this->catalogueFinderSubmittedResults(
            $catalogue,
            $query,
            $brandIds,
            $brandNames,
            $selectedBrand,
        );
        $retail = $this->catalogueFinderRetailResults($catalogue, $query, $brandIds, $selectedBrand);
        $catalogueResults = $this->catalogueFinderStyleResults($catalogue, $query, $selectedBrand);

        return [
            'query' => $query,
            'selected_brand_id' => $selectedBrandId,
            'searched' => true,
            'submitted' => $submitted,
            'retail' => $retail,
            'catalogue' => $catalogueResults,
            'counts' => [
                'submitted' => $submitted->count(),
                'retail' => $retail->count(),
                'catalogue' => $catalogueResults->count(),
            ],
        ];
    }

    private function catalogueFinderSubmittedResults(
        BrandCatalogue $catalogue,
        string $query,
        Collection $brandIds,
        Collection $brandNames,
        ?BrandCatalogueBrand $selectedBrand,
    ): Collection {
        $intakes = HairExtensionIntake::query()
            ->with([
                'brand',
                'productType.line',
                'style.productType.line',
                'photos',
            ])
            ->where('status', 'submitted')
            ->where(function ($builder) use ($brandIds, $brandNames): void {
                $builder->whereIn('brand_catalogue_brand_id', $brandIds->all())
                    ->orWhereIn(DB::raw('LOWER(brand_name)'), $brandNames->all())
                    ->orWhereHas('brand', fn ($q) => $q->whereIn('id', $brandIds->all()))
                    ->orWhereHas('style', fn ($q) => $q->whereIn('brand_catalogue_brand_id', $brandIds->all()));
            })
            ->when($selectedBrand, function ($builder) use ($selectedBrand): void {
                $builder->where(function ($q) use ($selectedBrand): void {
                    $q->where('brand_catalogue_brand_id', $selectedBrand->id)
                        ->orWhereRaw('LOWER(brand_name) = ?', [Str::lower($selectedBrand->name)])
                        ->orWhereHas('style', fn ($styleQuery) => $styleQuery->where('brand_catalogue_brand_id', $selectedBrand->id));
                });
            })
            ->when($query !== '', fn ($builder) => $this->applyHairIntakeFinderSearch($builder, $query))
            ->latest('submitted_at')
            ->limit($query !== '' ? 80 : 40)
            ->get();

        $familiesByStyle = $this->publishedFamiliesForStyles($intakes->pluck('brand_catalogue_style_id')->filter()->all());

        return $intakes
            ->map(fn (HairExtensionIntake $intake): array => $this->submittedFinderResult($catalogue, $intake, $familiesByStyle, $query))
            ->sortByDesc('score')
            ->values()
            ->take(24);
    }

    private function catalogueFinderRetailResults(
        BrandCatalogue $catalogue,
        string $query,
        Collection $brandIds,
        ?BrandCatalogueBrand $selectedBrand,
    ): Collection {
        return ProductFamily::query()
            ->with([
                'catalogueStyle.productType.line',
                'catalogueStyle.brand.catalogue',
            ])
            ->withCount('products')
            ->where(function ($builder) use ($catalogue, $brandIds): void {
                $builder->where('brand_catalogue_id', $catalogue->id)
                    ->orWhereIn('brand_catalogue_brand_id', $brandIds->all());
            })
            ->when($selectedBrand, function ($builder) use ($selectedBrand): void {
                $builder->where(function ($q) use ($selectedBrand): void {
                    $q->where('brand_catalogue_brand_id', $selectedBrand->id)
                        ->orWhereRaw('LOWER(brand_name) = ?', [Str::lower($selectedBrand->name)]);
                });
            })
            ->when($query !== '', fn ($builder) => $this->applyRetailFamilyFinderSearch($builder, $query))
            ->orderBy('brand_name')
            ->orderBy('family_name')
            ->limit($query !== '' ? 80 : 40)
            ->get()
            ->map(fn (ProductFamily $family): array => $this->retailFamilyFinderResult($family, $query))
            ->sortByDesc('score')
            ->values()
            ->take(24);
    }

    private function catalogueFinderStyleResults(
        BrandCatalogue $catalogue,
        string $query,
        ?BrandCatalogueBrand $selectedBrand,
    ): Collection {
        $styles = BrandCatalogueStyle::query()
            ->with([
                'brand',
                'productType.line',
            ])
            ->withCount(['skus', 'variants'])
            ->whereHas('brand', fn ($builder) => $builder->where('brand_catalogue_id', $catalogue->id))
            ->when($selectedBrand, fn ($builder) => $builder->where('brand_catalogue_brand_id', $selectedBrand->id))
            ->when($query !== '', fn ($builder) => $this->applyCatalogueStyleFinderSearch($builder, $query))
            ->orderBy('name')
            ->limit($query !== '' ? 80 : 40)
            ->get();

        $familiesByStyle = $this->publishedFamiliesForStyles($styles->pluck('id')->all());

        return $styles
            ->map(fn (BrandCatalogueStyle $style): array => $this->catalogueStyleFinderResult($catalogue, $style, $familiesByStyle, $query))
            ->sortByDesc('score')
            ->values()
            ->take(24);
    }

    private function applyHairIntakeFinderSearch($builder, string $query): void
    {
        $phraseLike = $this->finderLike($query);
        $tokens = $this->finderTokens($query);

        $builder->where(function ($outer) use ($phraseLike, $tokens): void {
            $outer->where(fn ($q) => $this->orWhereHairIntakeFinderFields($q, $phraseLike));

            if ($tokens->count() > 1) {
                $outer->orWhere(function ($allTokens) use ($tokens): void {
                    foreach ($tokens as $token) {
                        $allTokens->where(fn ($q) => $this->orWhereHairIntakeFinderFields($q, $this->finderLike($token)));
                    }
                });
            }
        });
    }

    private function applyRetailFamilyFinderSearch($builder, string $query): void
    {
        $phraseLike = $this->finderLike($query);
        $tokens = $this->finderTokens($query);

        $builder->where(function ($outer) use ($phraseLike, $tokens): void {
            $outer->where(fn ($q) => $this->orWhereRetailFamilyFinderFields($q, $phraseLike));

            if ($tokens->count() > 1) {
                $outer->orWhere(function ($allTokens) use ($tokens): void {
                    foreach ($tokens as $token) {
                        $allTokens->where(fn ($q) => $this->orWhereRetailFamilyFinderFields($q, $this->finderLike($token)));
                    }
                });
            }
        });
    }

    private function applyCatalogueStyleFinderSearch($builder, string $query): void
    {
        $phraseLike = $this->finderLike($query);
        $tokens = $this->finderTokens($query);

        $builder->where(function ($outer) use ($phraseLike, $tokens): void {
            $outer->where(fn ($q) => $this->orWhereCatalogueStyleFinderFields($q, $phraseLike));

            if ($tokens->count() > 1) {
                $outer->orWhere(function ($allTokens) use ($tokens): void {
                    foreach ($tokens as $token) {
                        $allTokens->where(fn ($q) => $this->orWhereCatalogueStyleFinderFields($q, $this->finderLike($token)));
                    }
                });
            }
        });
    }

    private function orWhereHairIntakeFinderFields($q, string $like): void
    {
        $q->orWhere('brand_name', 'like', $like)
            ->orWhere('product_type_name', 'like', $like)
            ->orWhere('style_name', 'like', $like)
            ->orWhere('classification_path', 'like', $like)
            ->orWhere('variant_groups', 'like', $like)
            ->orWhere('variant_structure', 'like', $like)
            ->orWhere('visible_text_notes', 'like', $like)
            ->orWhere('shelf_location', 'like', $like)
            ->orWhereHas('brand', fn ($brandQuery) => $brandQuery->where('name', 'like', $like))
            ->orWhereHas('productType', fn ($typeQuery) => $typeQuery->where('name', 'like', $like))
            ->orWhereHas('style', fn ($styleQuery) => $styleQuery->where('name', 'like', $like));
    }

    private function orWhereRetailFamilyFinderFields($q, string $like): void
    {
        $q->orWhere('brand_name', 'like', $like)
            ->orWhere('line_name', 'like', $like)
            ->orWhere('product_type_name', 'like', $like)
            ->orWhere('family_name', 'like', $like)
            ->orWhere('description', 'like', $like)
            ->orWhereHas('products', function ($productQuery) use ($like): void {
                $productQuery->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhere('search_keywords', 'like', $like);
            });
    }

    private function orWhereCatalogueStyleFinderFields($q, string $like): void
    {
        $q->orWhere('name', 'like', $like)
            ->orWhere('material_name', 'like', $like)
            ->orWhere('note', 'like', $like)
            ->orWhereHas('brand', fn ($brandQuery) => $brandQuery->where('name', 'like', $like))
            ->orWhereHas('productType', fn ($typeQuery) => $typeQuery->where('name', 'like', $like))
            ->orWhereHas('productType.line', fn ($lineQuery) => $lineQuery->where('name', 'like', $like))
            ->orWhereHas('skus', function ($skuQuery) use ($like): void {
                $skuQuery->where('name', 'like', $like)
                    ->orWhere('sku_code', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhere('option_signature', 'like', $like);
            });
    }

    private function submittedFinderResult(BrandCatalogue $catalogue, HairExtensionIntake $intake, Collection $familiesByStyle, string $query): array
    {
        $style = $intake->style;
        $family = $style ? $familiesByStyle->get((int) $style->id) : null;
        $styleUrl = $this->styleWorkspaceUrl($catalogue, $style);
        $title = $this->intakeFinderTitle($intake);
        $path = $this->intakeFinderPath($intake);
        $searchText = implode(' ', array_filter([
            $intake->brand_name,
            $intake->product_type_name,
            $intake->style_name,
            $path,
            $this->variantSummary($intake->variant_structure ?: $intake->variant_groups),
            $intake->visible_text_notes,
        ]));

        return [
            'type' => 'submitted',
            'badge' => 'Shop floor submitted',
            'title' => $title,
            'subtitle' => $path ?: 'Submitted intake #'.$intake->id,
            'meta' => trim(implode(' - ', array_filter([
                'Intake #'.$intake->id,
                $intake->submitted_at?->format('d M Y'),
                $this->variantSummary($intake->variant_structure ?: $intake->variant_groups),
            ]))),
            'image_url' => $intake->photoUrl(),
            'primary_url' => $family ? route('retail-products.families.show', $family) : ($styleUrl ?: null),
            'primary_label' => $family ? 'Open family product' : ($styleUrl ? 'Open Style Workspace' : 'Review intake'),
            'secondary_url' => $family && $styleUrl
                ? ($styleUrl.'?catalogue=1')
                : route('hair-extension-intake.v2', ['edit_intake' => $intake->id]),
            'secondary_label' => $family && $styleUrl ? 'Open catalogue style' : 'Review intake',
            'score' => $this->finderScore($query, $searchText) + 300 + ($family ? 40 : 0),
            'is_floor' => true,
            'has_family' => (bool) $family,
        ];
    }

    private function retailFamilyFinderResult(ProductFamily $family, string $query): array
    {
        $styleUrl = $this->styleWorkspaceUrlFromFamily($family);
        $searchText = implode(' ', array_filter([
            $family->brand_name,
            $family->line_name,
            $family->product_type_name,
            $family->family_name,
            $family->description,
        ]));

        return [
            'type' => 'retail',
            'badge' => 'Published family',
            'title' => $family->family_name,
            'subtitle' => trim(implode(' - ', array_filter([$family->brand_name, $family->line_name, $family->product_type_name]))),
            'meta' => number_format((int) ($family->products_count ?? 0)).' SKU'.((int) ($family->products_count ?? 0) === 1 ? '' : 's'),
            'image_url' => null,
            'primary_url' => route('retail-products.families.show', $family),
            'primary_label' => 'Open family product',
            'secondary_url' => $styleUrl ? ($styleUrl.'?catalogue=1') : null,
            'secondary_label' => $styleUrl ? 'Open catalogue style' : null,
            'score' => $this->finderScore($query, $searchText) + 180,
            'is_floor' => false,
            'has_family' => true,
        ];
    }

    private function catalogueStyleFinderResult(BrandCatalogue $catalogue, BrandCatalogueStyle $style, Collection $familiesByStyle, string $query): array
    {
        $family = $familiesByStyle->get((int) $style->id);
        $styleUrl = $this->styleWorkspaceUrl($catalogue, $style);
        $searchText = implode(' ', array_filter([
            $style->brand?->name,
            $style->productType?->line?->name,
            $style->productType?->name,
            $style->name,
            $style->material_name,
            $style->note,
        ]));

        return [
            'type' => 'catalogue',
            'badge' => 'Catalogue match',
            'title' => $style->name,
            'subtitle' => trim(implode(' - ', array_filter([
                $style->brand?->name,
                $style->productType?->line?->name,
                $style->productType?->name,
            ]))),
            'meta' => number_format((int) ($style->skus_count ?? 0)).' catalogue SKU'.((int) ($style->skus_count ?? 0) === 1 ? '' : 's').' - '.number_format((int) ($style->variants_count ?? 0)).' variant axes',
            'image_url' => null,
            'primary_url' => $family ? route('retail-products.families.show', $family) : $styleUrl,
            'primary_label' => $family ? 'Open family product' : 'Open Style Workspace',
            'secondary_url' => $family ? ($styleUrl.'?catalogue=1') : null,
            'secondary_label' => $family ? 'Open catalogue style' : null,
            'score' => $this->finderScore($query, $searchText) + 80 + ($family ? 30 : 0),
            'is_floor' => false,
            'has_family' => (bool) $family,
        ];
    }

    private function intakeFinderTitle(HairExtensionIntake $intake): string
    {
        return trim(implode(' ', array_filter([
            $intake->brand_name,
            $intake->style_name ?: $intake->product_type_name,
        ]))) ?: 'Submitted intake #'.$intake->id;
    }

    private function intakeFinderPath(HairExtensionIntake $intake): string
    {
        $path = collect($intake->classification_path ?: [])
            ->map(fn (mixed $value): string => trim(is_array($value) ? (string) ($value['name'] ?? $value['label'] ?? '') : (string) $value))
            ->filter()
            ->values();

        if ($path->isNotEmpty()) {
            return $path->implode(' > ');
        }

        return trim(implode(' > ', array_filter([
            $intake->brand_name,
            $intake->product_type_name,
            $intake->style_name,
        ])));
    }

    private function variantSummary(mixed $structure): ?string
    {
        if (! is_array($structure) || $structure === []) {
            return null;
        }

        $parts = [];
        foreach (['main', 'sub', 'common'] as $axis) {
            $axisData = $structure[$axis] ?? null;
            if (! is_array($axisData)) {
                continue;
            }

            $values = collect($axisData['values'] ?? $axisData['options'] ?? [])
                ->map(fn (mixed $value): string => trim(is_array($value) ? (string) ($value['value'] ?? $value['label'] ?? '') : (string) $value))
                ->filter()
                ->take(4)
                ->values();

            if ($values->isNotEmpty()) {
                $label = $axisData['label'] ?? $axisData['axis'] ?? ucfirst($axis);
                $parts[] = $label.': '.$values->implode(', ');
            }
        }

        return $parts ? implode(' - ', $parts) : null;
    }

    private function styleWorkspaceUrl(BrandCatalogue $catalogue, ?BrandCatalogueStyle $style, bool $forceCatalogueWorkspace = false): ?string
    {
        if (! $style?->productType?->line) {
            return null;
        }

        $url = route('brand-catalogue.styles.show', [
            $catalogue,
            $style->brand_catalogue_brand_id,
            $style->productType->line,
            $style->productType,
            $style,
        ]);

        return $forceCatalogueWorkspace ? $url.'?catalogue=1' : $url;
    }

    private function styleOpenUrl(
        BrandCatalogue $catalogue,
        int $brandId,
        int $lineId,
        int $productTypeId,
        int $styleId,
        ?ProductFamily $publishedFamily = null,
    ): string {
        if ($publishedFamily !== null) {
            return route('retail-products.families.show', $publishedFamily);
        }

        return route('brand-catalogue.styles.show', [
            $catalogue,
            $brandId,
            $lineId,
            $productTypeId,
            $styleId,
        ]);
    }

    private function styleWorkspaceUrlFromFamily(ProductFamily $family): ?string
    {
        $style = $family->relationLoaded('catalogueStyle')
            ? $family->catalogueStyle
            : $family->catalogueStyle()->with('productType.line')->first();

        if (! $style instanceof BrandCatalogueStyle) {
            return null;
        }

        $catalogue = $style->brand?->catalogue;
        if (! $catalogue instanceof BrandCatalogue && $family->brand_catalogue_id) {
            $catalogue = BrandCatalogue::query()->find($family->brand_catalogue_id);
        }

        if (! $catalogue instanceof BrandCatalogue) {
            return null;
        }

        return $this->styleWorkspaceUrl($catalogue, $style, forceCatalogueWorkspace: true);
    }

    private function publishedFamilyForStyle(int $styleId): ?ProductFamily
    {
        return RetailStyleFamilyCatalogue::primaryFamily(
            RetailStyleFamilyCatalogue::familiesForStyle($styleId),
        );
    }

    /**
     * @param  array<int, int>  $styleIds
     * @return Collection<int, ProductFamily>
     */
    private function publishedFamiliesForStyles(array $styleIds): Collection
    {
        return RetailStyleFamilyCatalogue::primaryFamilyByStyleIds($styleIds);
    }

    /**
     * @return array<int, string>
     */
    private function finderTokens(string $query): Collection
    {
        return collect(preg_split('/\s+/', trim($query)) ?: [])
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => $token !== '')
            ->take(5)
            ->values();
    }

    private function finderLike(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($value)).'%';
    }

    private function finderScore(string $query, string $text): int
    {
        $query = Str::lower(trim($query));
        $text = Str::lower($text);

        if ($query === '') {
            return 0;
        }

        $score = str_contains($text, $query) ? 100 : 0;

        foreach (preg_split('/\s+/', $query) ?: [] as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (str_contains($text, $token)) {
                $score += strlen($token) >= 4 ? 20 : 8;
            }
        }

        return $score;
    }

    private function uniqueSlug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $i = 2;

        while (
            DB::table($table)
                ->where($scopeColumn, $scopeId)
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function uniqueGlobalSlug(string $table, string $column, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $i = 2;

        while (
            DB::table($table)
                ->where($column, $slug)
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /**
     * @param  array<int|string, int|string|null>  $rawSelections
     * @return array{0:string,1:array<int, array{brand_catalogue_variant_id:int}>}
     */
    private function prepareSkuSelections(BrandCatalogueStyle $style, array $rawSelections, ?BrandCatalogueSku $ignoreSku = null): array
    {
        $style->loadMissing('variants.options');

        $syncPayload = [];
        $signatureParts = [];
        $variantLookup = $style->variants->keyBy('id');

        foreach ($rawSelections as $variantId => $optionId) {
            if ($optionId === null || $optionId === '') {
                continue;
            }

            $variantId = (int) $variantId;
            $optionId = (int) $optionId;
            $variant = $variantLookup->get($variantId);

            if (! $variant) {
                throw ValidationException::withMessages([
                    'variant_option_ids' => 'One or more selected variant groups do not belong to this style.',
                ]);
            }

            $option = $variant->options->firstWhere('id', $optionId);

            if (! $option) {
                throw ValidationException::withMessages([
                    'variant_option_ids' => 'One or more selected options do not belong to this style.',
                ]);
            }

            $syncPayload[$optionId] = [
                'brand_catalogue_variant_id' => $variantId,
            ];
            $signatureParts[] = sprintf('%d:%d', $variantId, $optionId);
        }

        sort($signatureParts, SORT_NATURAL);
        $signature = implode('|', $signatureParts);

        $existingQuery = BrandCatalogueSku::query()
            ->where('brand_catalogue_style_id', $style->id)
            ->where('option_signature', $signature);

        if ($ignoreSku) {
            $existingQuery->whereKeyNot($ignoreSku->id);
        }

        if ($existingQuery->exists()) {
            throw ValidationException::withMessages([
                'variant_option_ids' => 'A sellable SKU with the same selected options already exists for this style.',
            ]);
        }

        return [$signature, $syncPayload];
    }

    private function variantMatrixForStyle(BrandCatalogueStyle $style): array
    {
        $style->loadMissing('variants.options', 'skus.optionValues.variant', 'skus.images');

        $variants = $style->variants->sortBy('sort_order')->values();

        if ($variants->isEmpty() || $style->skus->isEmpty()) {
            return [
                'enabled' => false,
                'groups' => collect(),
                'primary_axis' => null,
                'choice_axis' => null,
                'summary' => [
                    'group_count' => 0,
                    'sku_count' => $style->skus->count(),
                ],
            ];
        }

        $choiceVariant = $variants->first(function (BrandCatalogueVariant $variant): bool {
            $name = Str::lower($variant->name);
            $type = Str::lower($variant->variant_type);

            return str_contains($name, 'colour')
                || str_contains($name, 'color')
                || str_contains($type, 'colour')
                || str_contains($type, 'color');
        }) ?? $variants->last();

        $groupVariants = $variants
            ->reject(fn (BrandCatalogueVariant $variant) => (int) $variant->id === (int) $choiceVariant->id)
            ->values();

        $groups = $style->skus
            ->map(function (BrandCatalogueSku $sku) use ($groupVariants, $choiceVariant): array {
                $sku->loadMissing('optionValues.variant', 'images');

                $optionByVariant = $sku->optionValues
                    ->keyBy(fn (BrandCatalogueVariantOption $option) => (int) $option->variant_id);

                $groupParts = $groupVariants
                    ->map(function (BrandCatalogueVariant $variant) use ($optionByVariant): array {
                        $option = $optionByVariant->get((int) $variant->id);

                        return [
                            'variant_id' => $variant->id,
                            'axis' => $variant->name,
                            'value' => $option?->label ?? $option?->value ?? 'Unassigned',
                            'sort' => $option?->sort_order ?? 9999,
                        ];
                    })
                    ->values();

                $choiceOption = $optionByVariant->get((int) $choiceVariant->id);

                return [
                    'group_key' => $groupParts->map(fn (array $part) => $part['axis'].'='.$part['value'])->implode('|'),
                    'group_parts' => $groupParts,
                    'choice' => [
                        'axis' => $choiceVariant->name,
                        'value' => $choiceOption?->label ?? $choiceOption?->value ?? 'Unassigned',
                        'sort' => $choiceOption?->sort_order ?? 9999,
                    ],
                    'sku' => $sku,
                    'image_count' => $sku->images->count(),
                ];
            })
            ->groupBy('group_key')
            ->map(function (Collection $items): array {
                $first = $items->first();

                return [
                    'label' => collect($first['group_parts'])
                        ->map(fn (array $part) => $part['axis'].': '.$part['value'])
                        ->implode(' / '),
                    'parts' => collect($first['group_parts']),
                    'sort_key' => collect($first['group_parts'])
                        ->map(fn (array $part) => sprintf('%04d:%s', $part['sort'], $part['value']))
                        ->implode('|'),
                    'choices' => $items
                        ->sortBy(fn (array $item) => sprintf('%04d:%s', $item['choice']['sort'], $item['choice']['value']))
                        ->values(),
                    'sku_count' => $items->count(),
                ];
            })
            ->sortBy('sort_key')
            ->values();

        return [
            'enabled' => true,
            'groups' => $groups,
            'primary_axis' => $groupVariants->pluck('name')->implode(' / '),
            'choice_axis' => $choiceVariant->name,
            'summary' => [
                'group_count' => $groups->count(),
                'sku_count' => $style->skus->count(),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function splitVariantValues(string $raw): array
    {
        return collect(preg_split('/[,;\r\n]+/', $raw) ?: [])
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->values()
            ->all();
    }

    private function findOrCreateStyleVariant(BrandCatalogueStyle $style, string $name, string $type, int $sortOrder): BrandCatalogueVariant
    {
        $style->loadMissing('variants');

        $variant = $style->variants
            ->first(fn (BrandCatalogueVariant $variant): bool => Str::lower($variant->name) === Str::lower($name));

        if ($variant) {
            $variant->update([
                'name' => $name,
                'variant_type' => $type,
                'sort_order' => $sortOrder,
            ]);

            return $variant->fresh();
        }

        $variant = $style->variants()->create([
            'name' => $name,
            'variant_type' => $type,
            'sort_order' => $sortOrder,
        ]);

        $style->unsetRelation('variants');

        return $variant;
    }

    private function findOrCreateVariantOption(BrandCatalogueVariant $variant, string $value, int $sortOrder, int &$createdOptions): BrandCatalogueVariantOption
    {
        $variant->loadMissing('options');

        $option = $variant->options
            ->first(fn (BrandCatalogueVariantOption $option): bool => Str::lower((string) $option->value) === Str::lower($value)
                || Str::lower($option->label) === Str::lower($value));

        if ($option) {
            $option->update([
                'label' => $value,
                'value' => $value,
                'sort_order' => $option->sort_order ?: $sortOrder,
            ]);

            return $option->fresh();
        }

        $createdOptions++;

        $option = $variant->options()->create([
            'label' => $value,
            'value' => $value,
            'sort_order' => $sortOrder,
        ]);

        $variant->unsetRelation('options');

        return $option;
    }

    private function optionSignatureFromOptions(Collection $options): string
    {
        $parts = $options
            ->map(fn (BrandCatalogueVariantOption $option): string => $option->variant_id.':'.$option->id)
            ->values()
            ->all();

        sort($parts, SORT_NATURAL);

        return implode('|', $parts);
    }

    private function majorProductTypeFor(string $productTypeName, string $styleName): string
    {
        $text = Str::lower($productTypeName.' '.$styleName);

        if (str_contains($text, 'accessor')) {
            return 'Accessories & Tools';
        }

        if (str_contains($text, 'wig') || str_contains($text, 'topper')) {
            return 'Wigs & Toppers';
        }

        if (
            str_contains($text, 'ponytail')
            || str_contains($text, 'drawstring')
            || str_contains($text, 'draw string')
            || str_contains($text, 'hair piece')
            || str_contains($text, 'hairpiece')
            || str_contains($text, 'puff')
            || str_contains($text, 'scrunch')
            || str_contains($text, 'fringe')
            || preg_match('/\bbuns?\b/', $text) === 1
            || str_contains($text, 'dip dye')
            || str_contains($text, 'party hair')
        ) {
            return 'Ponytails & Hairpieces';
        }

        if (
            str_contains($text, 'clip')
            || str_contains($text, 'tape')
            || str_contains($text, 'nano')
            || str_contains($text, 'micro loop')
            || str_contains($text, 'pre-bonded')
            || str_contains($text, 'pre bonded')
            || str_contains($text, 'flat hair')
            || str_contains($text, 'diy')
            || str_contains($text, 'double drawn')
            || str_contains($text, 'triple weft')
        ) {
            return 'Fitted & Clip-In Extensions';
        }

        if (
            str_contains($text, 'weave')
            || str_contains($text, 'weft')
            || str_contains($text, 'bundle')
            || str_contains($text, 'closure')
            || str_contains($text, 'frontal')
            || str_contains($text, 'brazilian')
            || str_contains($text, 'virgin')
        ) {
            return 'Weaves, Bundles & Closures';
        }

        if (str_contains($text, 'crochet') || str_contains($text, 'loc')) {
            return 'Crochet, Locs & Twists';
        }

        if (
            str_contains($text, 'braid')
            || str_contains($text, 'bulk')
            || str_contains($text, 'twist')
            || str_contains($text, 'pre-stretched')
            || str_contains($text, 'pre stretched')
        ) {
            return 'Braids & Bulk Hair';
        }

        return 'Other Hair Structures';
    }

    private function majorProductTypeSort(string $majorType): int
    {
        return match ($majorType) {
            'Braids & Bulk Hair' => 10,
            'Crochet, Locs & Twists' => 20,
            'Weaves, Bundles & Closures' => 30,
            'Fitted & Clip-In Extensions' => 40,
            'Wigs & Toppers' => 50,
            'Ponytails & Hairpieces' => 60,
            'Accessories & Tools' => 90,
            default => 999,
        };
    }

    private function styleFamilyLabelFor(string $styleName, string $productTypeName): string
    {
        $name = Str::lower($styleName);

        $patterns = [
            'French Curl' => ['french curl'],
            'Water Wave' => ['water wave'],
            'Deep Wave' => ['deep wave'],
            'Body Wave' => ['body wave'],
            'Bohemian' => ['bohemian', 'boho'],
            'Passion Twist' => ['passion twist'],
            'Spring Twist' => ['spring twist', 'springy'],
            'Marley / Afro Kinky' => ['marley', 'afro kinky', 'kinky bulk'],
            'Faux Locs' => ['faux loc', 'locks', 'locs'],
            'Straight' => ['straight', 'yaki'],
            'Curly' => ['curly', 'curl'],
            'Box Braid' => ['box braid', 'box'],
            'Pre-Stretched' => ['pre-stretched', 'pre stretched'],
            'Ponytail' => ['ponytail'],
            'Lace Wig' => ['lace'],
            'Full Wig' => ['full head'],
            'Half Wig' => ['half head', 'half wig'],
            'Closure / Frontal' => ['closure', 'frontal'],
            'Clip-In' => ['clip'],
            'Scrunchie / Bun' => ['scrunch', 'bun'],
        ];

        foreach ($patterns as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($name, $needle)) {
                    return $label;
                }
            }
        }

        return $productTypeName;
    }

    private function skuNameFromMatrixOptions(
        BrandCatalogueStyle $style,
        BrandCatalogueVariant $mainVariant,
        BrandCatalogueVariantOption $mainOption,
        BrandCatalogueVariant $subVariant,
        BrandCatalogueVariantOption $subOption,
        ?BrandCatalogueVariant $commonVariant,
        ?BrandCatalogueVariantOption $commonOption,
    ): string {
        $parts = [$mainOption->label];

        if ($commonVariant && $commonOption) {
            array_unshift($parts, $commonOption->label);
        }

        $mainName = Str::lower($mainVariant->name);
        $mainValue = $mainOption->label;
        if (str_contains($mainName, 'length') && ! str_contains(Str::lower($mainValue), 'inch')) {
            $mainValue .= ' inch';
            $parts = array_map(
                fn (string $part): string => $part === $mainOption->label ? $mainValue : $part,
                $parts,
            );
        }

        return trim(sprintf(
            '%s %s - %s %s',
            $style->name,
            implode(' ', $parts),
            $subVariant->name,
            $subOption->label,
        ));
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function variantTypeOptions(): array
    {
        return collect(BrandCatalogueVariant::TYPES)
            ->map(fn (string $type) => [
                'value' => $type,
                'label' => $type === 'count' ? 'Pack count' : ucfirst(str_replace('_', ' ', $type)),
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function styleImageRoleOptions(): array
    {
        return [
            ['value' => 'main', 'label' => 'Main'],
            ['value' => 'style', 'label' => 'Style'],
            ['value' => 'hero', 'label' => 'Hero'],
            ['value' => 'gallery', 'label' => 'Gallery'],
            ['value' => 'detail', 'label' => 'Detail'],
            ['value' => 'texture', 'label' => 'Texture'],
            ['value' => 'packaging', 'label' => 'Packaging'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function materialOptions(): array
    {
        return [
            'Synthetic Hair',
            'Human Hair',
            'Human Hair Blend',
            'Heat Resistant Synthetic',
            'Premium Synthetic Fibre',
            'Kanekalon',
            'Toyokalon',
            'Virgin Human Hair',
            'Remy Human Hair',
        ];
    }

    private function matchingMaterialIdForName(BrandCatalogueProductType $productType, string $materialName): ?int
    {
        $normalized = Str::lower(trim($materialName));

        if ($normalized === '') {
            return null;
        }

        return $productType->materials()
            ->get()
            ->first(fn (BrandCatalogueMaterial $material) => Str::lower(trim($material->name)) === $normalized)
            ?->id;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function skuImageRoleOptions(): array
    {
        return [
            ['value' => 'main', 'label' => 'Main picture'],
            ['value' => 'gallery', 'label' => 'Gallery'],
            ['value' => 'variant', 'label' => 'Variant'],
            ['value' => 'detail', 'label' => 'Detail'],
            ['value' => 'swatch', 'label' => 'Swatch'],
            ['value' => 'packaging', 'label' => 'Packaging'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function optionImageRoleOptions(): array
    {
        return [
            ['value' => 'main', 'label' => 'Main display'],
            ['value' => 'variant', 'label' => 'Variant reference'],
            ['value' => 'swatch', 'label' => 'Swatch'],
            ['value' => 'gallery', 'label' => 'Gallery'],
            ['value' => 'detail', 'label' => 'Detail'],
        ];
    }
}
