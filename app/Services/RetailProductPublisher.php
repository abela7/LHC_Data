<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use App\Models\CategoryScaffold;
use App\Models\CategoryScaffoldNode;
use App\Models\CatalogueImage;
use App\Models\InventoryLevel;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductCategoryAssignment;
use App\Models\ProductEcommerceProfile;
use App\Models\ProductFamily;
use App\Models\ProductMedia;
use App\Models\ProductPosProfile;
use App\Models\ProductPrice;
use App\Models\ProductSource;
use App\Models\ProductVariantGroup;
use App\Models\ProductVariantOption;
use App\Models\ProductVariantValue;
use App\Support\CustomerProductDescription;
use App\Support\ProductImageNamer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RetailProductPublisher
{
    /**
     * @param  array<int, int>|null  $skuIds
     */
    public function publishBrandCatalogueStyle(
        BrandCatalogueStyle $style,
        ?array $skuIds = null,
        ?string $scopeKey = null,
        ?string $scopeName = null,
    ): ProductFamily
    {
        $style->load([
            'brand.catalogue',
            'productType.line',
            'images',
            'variants.options.images',
            'skus.optionValues.variant',
            'skus.images',
        ]);

        if ($skuIds !== null) {
            $allowedSkuIds = collect($skuIds)->map(fn (mixed $id): int => (int) $id)->filter()->unique();
            $style->setRelation('skus', $style->skus->whereIn('id', $allowedSkuIds->all())->values());
        }

        return DB::transaction(function () use ($style, $scopeKey, $scopeName): ProductFamily {
            $brand = $this->findOrCreateBrand($style->brand->name);
            $line = $style->productType?->line;
            $lineName = $this->effectiveLineName($style);
            $catalogue = $style->brand?->catalogue;
            $familyName = $this->familyName($lineName, $style->name, $scopeName);
            $existingFamilyId = ProductFamily::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->where('catalogue_scope_key', $scopeKey)
                ->value('id');

            $family = ProductFamily::query()->updateOrCreate(
                [
                    'brand_catalogue_style_id' => $style->id,
                    'catalogue_scope_key' => $scopeKey,
                ],
                [
                    'brand_id' => $brand->id,
                    'brand_catalogue_id' => $catalogue?->id,
                    'brand_catalogue_brand_id' => $style->brand?->id,
                    'brand_catalogue_line_id' => $line?->id,
                    'brand_catalogue_product_type_id' => $style->productType?->id,
                    'root_catalogue_name' => $catalogue?->name,
                    'brand_name' => $style->brand->name,
                    'line_name' => $lineName,
                    'product_type_name' => $style->productType?->name,
                    'family_name' => $familyName,
                    'slug' => $this->uniqueSlug('product_families', 'slug', $familyName, $existingFamilyId),
                    'description' => CustomerProductDescription::clean($style->note),
                    'source_url' => $style->url,
                    'status' => 'draft',
                    'published_at' => now(),
                    'sort_order' => (int) $style->sort_order,
                ],
            );

            $this->publishFamilySource($family, $style);
            $this->publishFamilyEcommerceProfile($family);
            $this->publishFamilyMedia($family, $style);
            $categoryAssignments = $this->publishFamilyCategoryAssignments($family, $style);

            $publishedVariants = $this->publishedVariantsForSkus($style, $style->skus, $scopeKey !== null);
            $groupMap = $this->publishVariantGroups($family, $publishedVariants, $scopeKey);
            $optionMap = $this->publishVariantOptions($groupMap, $publishedVariants, $scopeKey);
            $location = $this->defaultInventoryLocation();

            foreach ($style->skus as $sku) {
                $this->publishSku($family, $brand, $sku, $optionMap, $location, $categoryAssignments);
            }

            return $family->fresh([
                'products.price',
                'products.media',
                'variantGroups.options',
                'ecommerceProfile',
                'media',
            ]);
        });
    }

    private function publishSku(
        ProductFamily $family,
        Brand $brand,
        BrandCatalogueSku $sku,
        array $optionMap,
        InventoryLocation $location,
        array $categoryAssignments,
    ): Product {
        $existingId = Product::query()
            ->where('brand_catalogue_sku_id', $sku->id)
            ->value('id');

        $attributes = [
            'product_family_id' => $family->id,
            'brand_id' => $brand->id,
            'name' => $sku->name,
            'slug' => $this->uniqueSlug('products', 'slug', $sku->name, $existingId, ['product_family_id' => $family->id]),
            'sku' => $this->uniqueNullableValue('products', 'sku', $sku->sku_code, $existingId),
            'barcode' => $this->nullTrim($sku->barcode),
            'receipt_name' => Str::limit($sku->name, 80, ''),
            'search_keywords' => $this->searchKeywords($family, $sku),
            'description' => CustomerProductDescription::clean($sku->description),
            'sort_order' => (int) $sku->sort_order,
        ];

        if ($existingId === null) {
            $attributes['status'] = 'active';
            $attributes['is_pos_active'] = true;
            $attributes['is_ecommerce_active'] = true;
            $attributes['is_inventory_tracked'] = true;
        }

        $product = Product::query()->updateOrCreate(
            ['brand_catalogue_sku_id' => $sku->id],
            $attributes,
        );

        ProductVariantValue::query()->where('product_id', $product->id)->delete();

        foreach ($sku->optionValues as $option) {
            $publishedOption = $optionMap[$option->id] ?? null;
            if (! $publishedOption) {
                continue;
            }

            ProductVariantValue::query()->create([
                'product_id' => $product->id,
                'product_variant_group_id' => $publishedOption->product_variant_group_id,
                'product_variant_option_id' => $publishedOption->id,
            ]);
        }

        $this->publishProductMedia($product, $sku);
        $this->publishProductOperationalProfiles($family, $product, $sku, $location);
        $this->publishProductCategoryAssignments($product, $categoryAssignments);
        $this->publishProductSource($product, $sku);

        return $product;
    }

    /**
     * @return array<int, ProductCategoryAssignment>
     */
    private function publishFamilyCategoryAssignments(ProductFamily $family, BrandCatalogueStyle $style): array
    {
        $root = $this->resolveScaffoldRoot($style);

        if (! $root) {
            return [];
        }

        $assignments = [];
        $primaryNode = $this->resolveScaffoldNode($root, 'taxonomy', $style->productType?->name);
        $materialNode = $this->resolveScaffoldNode($root, 'material', $style->material_name);

        $assignments[] = $this->upsertCategoryAssignment(
            productFamilyId: $family->id,
            productId: null,
            root: $root,
            node: $primaryNode,
            assignmentType: 'primary',
            notes: 'Primary operational scaffold node for POS, ecommerce navigation, stock grouping, and reporting.',
        );

        if ($materialNode) {
            $assignments[] = $this->upsertCategoryAssignment(
                productFamilyId: $family->id,
                productId: null,
                root: $root,
                node: $materialNode,
                assignmentType: 'material',
                notes: 'Secondary material scaffold node used as a shopper filter and reporting attribute.',
            );
        }

        return array_values(array_filter($assignments));
    }

    /**
     * @param  array<int, ProductCategoryAssignment>  $familyAssignments
     */
    private function publishProductCategoryAssignments(Product $product, array $familyAssignments): void
    {
        foreach ($familyAssignments as $assignment) {
            $this->upsertCategoryAssignment(
                productFamilyId: $product->product_family_id,
                productId: $product->id,
                root: $assignment->scaffold,
                node: $assignment->node,
                assignmentType: $assignment->assignment_type,
                notes: 'Inherited from published product family scaffold assignment.',
            );
        }
    }

    private function upsertCategoryAssignment(
        int $productFamilyId,
        ?int $productId,
        CategoryScaffold $root,
        ?CategoryScaffoldNode $node,
        string $assignmentType,
        ?string $notes,
    ): ProductCategoryAssignment {
        return ProductCategoryAssignment::query()->updateOrCreate(
            [
                'product_family_id' => $productFamilyId,
                'product_id' => $productId,
                'assignment_type' => $assignmentType,
            ],
            [
                'category_scaffold_id' => $root->id,
                'category_scaffold_axis_id' => $node?->category_scaffold_axis_id,
                'category_scaffold_node_id' => $node?->id,
                'source_type' => 'brand_catalogue_publish',
                'notes' => $notes,
            ],
        );
    }

    /**
     * @return array<int, ProductVariantGroup>
     */
    private function publishedVariantsForSkus(BrandCatalogueStyle $style, Collection $skus, bool $scoped): Collection
    {
        if (! $scoped) {
            return $style->variants;
        }

        $usedOptionIdsByVariant = [];
        foreach ($skus as $sku) {
            foreach ($sku->optionValues as $option) {
                $variantId = (int) ($option->variant?->id ?? $option->pivot?->brand_catalogue_variant_id ?? 0);
                if (! $variantId) {
                    continue;
                }
                $usedOptionIdsByVariant[$variantId] ??= [];
                $usedOptionIdsByVariant[$variantId][(int) $option->id] = true;
            }
        }

        return $style->variants
            ->map(function (BrandCatalogueVariant $variant) use ($usedOptionIdsByVariant): ?BrandCatalogueVariant {
                $usedOptionIds = array_keys($usedOptionIdsByVariant[(int) $variant->id] ?? []);
                if (count($usedOptionIds) <= 1) {
                    return null;
                }

                $variant->setRelation(
                    'options',
                    $variant->options->whereIn('id', $usedOptionIds)->values(),
                );

                return $variant;
            })
            ->filter()
            ->values();
    }

    /**
     * @return array<int, ProductVariantGroup>
     */
    private function publishVariantGroups(ProductFamily $family, Collection $variants, ?string $scopeKey): array
    {
        $map = [];

        foreach ($variants as $variant) {
            $identity = $scopeKey === null
                ? ['brand_catalogue_variant_id' => $variant->id]
                : ['product_family_id' => $family->id, 'name' => $variant->name];

            $group = ProductVariantGroup::query()->updateOrCreate(
                $identity,
                [
                    'product_family_id' => $family->id,
                    'brand_catalogue_variant_id' => $scopeKey === null ? $variant->id : null,
                    'name' => $variant->name,
                    'variant_type' => $variant->variant_type,
                    'sort_order' => (int) $variant->sort_order,
                ],
            );

            $map[$variant->id] = $group;
        }

        return $map;
    }

    /**
     * @param  array<int, ProductVariantGroup>  $groupMap
     * @return array<int, ProductVariantOption>
     */
    private function publishVariantOptions(array $groupMap, Collection $variants, ?string $scopeKey): array
    {
        $map = [];

        foreach ($variants as $variant) {
            $group = $groupMap[$variant->id] ?? null;
            if (! $group instanceof ProductVariantGroup) {
                continue;
            }

            foreach ($variant->options as $option) {
                $identity = $scopeKey === null
                    ? ['brand_catalogue_variant_option_id' => $option->id]
                    : ['product_variant_group_id' => $group->id, 'label' => $option->label];

                $publishedOption = ProductVariantOption::query()->updateOrCreate(
                    $identity,
                    [
                        'product_variant_group_id' => $group->id,
                        'brand_catalogue_variant_option_id' => $scopeKey === null ? $option->id : null,
                        'label' => $option->label,
                        'value' => $option->value,
                        'sort_order' => (int) $option->sort_order,
                    ],
                );

                $map[$option->id] = $publishedOption;
            }
        }

        return $map;
    }

    private function publishFamilyMedia(ProductFamily $family, BrandCatalogueStyle $style): void
    {
        foreach ($style->images as $index => $image) {
            $this->upsertMedia($image, [
                'product_family_id' => $family->id,
                'product_id' => null,
                'is_primary' => $index === 0 || $image->is_primary,
                'name_base' => $family->family_name,
                'source_label' => 'Published catalogue family image',
            ]);
        }
    }

    private function publishProductMedia(Product $product, BrandCatalogueSku $sku): void
    {
        $publishedCatalogueImageIds = [];
        $hasDirectSkuImages = $sku->images->isNotEmpty();

        foreach ($sku->images as $index => $image) {
            $publishedCatalogueImageIds[] = $image->id;

            $this->upsertMedia($image, [
                'product_family_id' => $product->product_family_id,
                'product_id' => $product->id,
                'is_primary' => $index === 0 || $image->is_primary,
                'image_role' => $this->retailMediaRole($image->image_role, 'variant'),
                'source_label' => 'Published direct SKU image',
                'name_base' => $product->name,
            ]);
        }

        $primaryOptionImageId = $hasDirectSkuImages ? null : $sku->selectedOptionPrimaryImage()?->id;
        $fallbackOptionIndex = 0;

        foreach ($this->selectedOptionImages($sku) as $image) {
            if (in_array($image->id, $publishedCatalogueImageIds, true)) {
                continue;
            }

            $publishedCatalogueImageIds[] = $image->id;
            $isPrimary = ! $hasDirectSkuImages && (
                $image->id === $primaryOptionImageId || ($primaryOptionImageId === null && $fallbackOptionIndex === 0)
            );

            $this->upsertMedia($image, [
                'product_family_id' => $product->product_family_id,
                'product_id' => $product->id,
                'is_primary' => $isPrimary,
                'image_role' => $this->retailMediaRole($image->image_role, 'variant'),
                'source_label' => 'Published selected variant option image',
                'name_base' => $product->name,
            ]);

            $fallbackOptionIndex++;
        }

        $this->removeStalePublishedProductMedia($product, $publishedCatalogueImageIds);
    }

    /**
     * @param  array{product_family_id:int,product_id:?int,is_primary:bool}  $target
     */
    private function upsertMedia(CatalogueImage $image, array $target): void
    {
        $role = $target['image_role'] ?? $this->retailMediaRole($image->image_role, $target['product_id'] ? 'variant' : 'main');
        $imageName = ProductImageNamer::displayName((string) ($target['name_base'] ?? 'Product'), $role);
        $filename = $image->original_filename
            ?: ProductImageNamer::filename($imageName, $this->imageExtension($image));

        $media = ProductMedia::query()->firstOrNew([
            'catalogue_image_id' => $image->id,
            'product_family_id' => $target['product_family_id'],
            'product_id' => $target['product_id'],
        ]);

        $keepMirroredStorage = $media->exists && $media->storage_path && ! $image->storage_path;

        $media->fill([
            'image_role' => $role,
            'external_url' => $image->external_url,
            'notes' => $image->notes,
            'is_primary' => $target['is_primary'],
            'source_type' => $keepMirroredStorage ? $media->source_type : 'catalogue_source',
            'source_label' => $target['source_label'] ?? 'Published catalogue image',
            'usage_context' => $target['usage_context'] ?? 'all',
            'alt_text' => $imageName,
            'sort_order' => (int) $image->sort_order,
        ]);

        if (! $keepMirroredStorage) {
            $media->fill([
                'storage_disk' => $image->storage_disk,
                'storage_path' => $image->storage_path,
                'original_filename' => $filename,
                'mime_type' => $image->mime_type,
                'file_size' => $image->file_size,
                'is_offline_ready' => $image->storage_path !== null,
            ]);
        } else {
            $media->is_offline_ready = true;
        }

        $media->save();

        if ($target['is_primary']) {
            ProductMedia::query()
                ->where('product_family_id', $target['product_family_id'])
                ->where('product_id', $target['product_id'])
                ->whereKeyNot($media->id)
                ->update(['is_primary' => false]);
        }
    }

    /**
     * @return Collection<int, CatalogueImage>
     */
    private function selectedOptionImages(BrandCatalogueSku $sku): Collection
    {
        $sku->loadMissing('optionValues.variant', 'optionValues.images');

        $variantTypePriority = [
            'colour_name' => 0,
            'colour_code' => 0,
            'short_code' => 1,
            'measurement' => 2,
            'count' => 3,
            'text' => 4,
        ];

        return $sku->optionValues
            ->sortBy(fn (BrandCatalogueVariantOption $option) => sprintf(
                '%02d:%04d:%04d:%s',
                $variantTypePriority[$option->variant->variant_type] ?? 9,
                $option->variant->sort_order,
                $option->sort_order,
                $option->label,
            ))
            ->flatMap(fn (BrandCatalogueVariantOption $option) => $option->images)
            ->values();
    }

    /**
     * @param  array<int, int>  $currentCatalogueImageIds
     */
    private function removeStalePublishedProductMedia(Product $product, array $currentCatalogueImageIds): void
    {
        ProductMedia::query()
            ->where('product_family_id', $product->product_family_id)
            ->where('product_id', $product->id)
            ->where('source_type', 'catalogue_source')
            ->whereNotNull('catalogue_image_id')
            ->when(
                $currentCatalogueImageIds !== [],
                fn ($query) => $query->whereNotIn('catalogue_image_id', $currentCatalogueImageIds),
            )
            ->delete();
    }

    private function retailMediaRole(?string $role, string $fallback): string
    {
        $role = Str::of((string) $role)->lower()->replace(' ', '_')->toString();

        if ($role === '') {
            return $fallback;
        }

        if ($fallback === 'variant' && in_array($role, ['main', 'hero', 'style'], true)) {
            return 'variant';
        }

        return in_array($role, [
            'main',
            'style',
            'hero',
            'variant',
            'swatch',
            'gallery',
            'detail',
            'texture',
            'packaging',
            'source',
        ], true) ? $role : $fallback;
    }

    private function imageExtension(CatalogueImage $image): string
    {
        if ($image->mime_type) {
            return ProductImageNamer::extensionFromMime($image->mime_type);
        }

        if ($image->external_url) {
            return ProductImageNamer::extensionFromUrl($image->external_url);
        }

        return ProductImageNamer::extensionFromUrl($image->storage_path);
    }

    private function publishProductOperationalProfiles(
        ProductFamily $family,
        Product $product,
        BrandCatalogueSku $sku,
        InventoryLocation $location,
    ): void {
        ProductPrice::query()->updateOrCreate(
            ['product_id' => $product->id],
            [
                'currency' => 'GBP',
                'tax_class' => 'standard',
                'vat_rate' => null,
            ],
        );

        InventoryLevel::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'inventory_location_id' => $location->id,
            ],
            [
                'stock_quantity' => 0,
                'low_stock_threshold' => null,
                'reorder_quantity' => null,
            ],
        );

        ProductPosProfile::query()->updateOrCreate(
            ['product_id' => $product->id],
            [
                'receipt_name' => Str::limit($product->name, 80, ''),
                'quick_search_keywords' => $product->search_keywords,
                'pos_category' => $family->root_catalogue_name,
                'discount_allowed' => true,
                'quick_sale_enabled' => true,
                'tax_class' => 'standard',
            ],
        );

        $description = CustomerProductDescription::clean($sku->description);

        $ecommerceAttributes = [
            'product_family_id' => $family->id,
            'online_title' => $product->name,
            'short_description' => $description ? Str::limit($description, 180) : null,
            'long_description' => $description,
            'seo_slug' => $product->slug,
            'seo_title' => $product->name,
            'seo_description' => $description ? Str::limit($description, 155) : null,
            'tags' => array_values(array_filter([$family->brand_name, $family->line_name, $family->product_type_name, $family->family_name])),
            'click_and_collect_enabled' => true,
        ];

        if ($product->wasRecentlyCreated) {
            $ecommerceAttributes['is_published'] = true;
        }

        ProductEcommerceProfile::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'profile_level' => 'sku',
            ],
            $ecommerceAttributes,
        );
    }

    private function publishFamilyEcommerceProfile(ProductFamily $family): void
    {
        $description = CustomerProductDescription::clean($family->description);

        ProductEcommerceProfile::query()->updateOrCreate(
            [
                'product_family_id' => $family->id,
                'profile_level' => 'family',
            ],
            [
                'product_id' => null,
                'online_title' => $family->family_name,
                'short_description' => $description ? Str::limit($description, 180) : null,
                'long_description' => $description,
                'seo_slug' => $family->slug,
                'seo_title' => $family->family_name,
                'seo_description' => $description ? Str::limit($description, 155) : null,
                'tags' => array_values(array_filter([$family->brand_name, $family->line_name, $family->product_type_name])),
                'is_published' => false,
                'click_and_collect_enabled' => true,
            ],
        );
    }

    private function publishFamilySource(ProductFamily $family, BrandCatalogueStyle $style): void
    {
        ProductSource::query()->updateOrCreate(
            [
                'product_family_id' => $family->id,
                'product_id' => null,
                'source_type' => 'brand_catalogue_style',
                'source_table' => 'brand_catalogue_styles',
                'source_id' => $style->id,
            ],
            [
                'source_url' => $style->url,
                'confidence' => 'A',
                'notes' => 'Published from brand catalogue style workspace.',
            ],
        );
    }

    private function publishProductSource(Product $product, BrandCatalogueSku $sku): void
    {
        ProductSource::query()->updateOrCreate(
            [
                'product_family_id' => $product->product_family_id,
                'product_id' => $product->id,
                'source_type' => 'brand_catalogue_sku',
                'source_table' => 'brand_catalogue_skus',
                'source_id' => $sku->id,
            ],
            [
                'source_url' => $sku->url,
                'confidence' => 'A',
                'notes' => 'Published from verified brand catalogue SKU.',
            ],
        );
    }

    private function resolveScaffoldRoot(BrandCatalogueStyle $style): ?CategoryScaffold
    {
        $catalogueName = Str::lower((string) $style->brand?->catalogue?->name);

        $slug = match (true) {
            str_contains($catalogueName, 'hair extension') => 'hair-extensions-wigs',
            str_contains($catalogueName, 'hair product') => 'hair-products',
            str_contains($catalogueName, 'skin') => 'skin-care',
            default => null,
        };

        if ($slug) {
            return CategoryScaffold::query()
                ->where('group_key', 'catalogue')
                ->where('slug', $slug)
                ->with('axes.nodes')
                ->first();
        }

        return CategoryScaffold::query()
            ->where('group_key', 'catalogue')
            ->where('name', $style->brand?->catalogue?->name)
            ->with('axes.nodes')
            ->first();
    }

    private function resolveScaffoldNode(CategoryScaffold $root, string $axisKey, ?string $name): ?CategoryScaffoldNode
    {
        $name = $this->nullTrim($name);

        if ($name === null) {
            return null;
        }

        $axis = $root->axes->firstWhere('key', $axisKey);

        if (! $axis) {
            return null;
        }

        $targetSlug = Str::slug($name);

        return $axis->nodes->first(fn (CategoryScaffoldNode $node) => $node->slug === $targetSlug)
            ?? $axis->nodes->first(fn (CategoryScaffoldNode $node) => Str::lower($node->name) === Str::lower($name));
    }

    private function findOrCreateBrand(string $name): Brand
    {
        $name = trim($name);
        $existing = Brand::query()->where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        return Brand::query()->create([
            'name' => $name,
            'slug' => $this->uniqueSlug('brands', 'slug', $name),
            'is_active' => true,
            'is_generic' => false,
        ]);
    }

    private function defaultInventoryLocation(): InventoryLocation
    {
        $existing = InventoryLocation::query()
            ->where('location_type', 'shop')
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->first()
            ?? InventoryLocation::query()
                ->where('location_type', 'shop')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->first();

        if ($existing) {
            return $existing;
        }

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

    private function effectiveLineName(BrandCatalogueStyle $style): ?string
    {
        $line = $style->productType?->line;

        if (! $line) {
            return null;
        }

        if ($line->is_default && Str::lower($line->name) === Str::lower($style->brand?->name ?? '')) {
            return null;
        }

        return $line->name;
    }

    private function familyName(?string $lineName, string $styleName, ?string $scopeName = null): string
    {
        $lineName = trim((string) $lineName);
        $styleName = trim($styleName);

        $baseName = $styleName;
        if ($lineName !== '' && ! Str::startsWith(Str::lower($styleName), Str::lower($lineName))) {
            $baseName = trim($lineName.' '.$styleName);
        }

        $scopeName = $this->nullTrim($scopeName);
        if ($scopeName === null) {
            return $baseName;
        }

        return trim($baseName.' - '.$scopeName);
    }

    private function searchKeywords(ProductFamily $family, BrandCatalogueSku $sku): string
    {
        $terms = collect([
            $family->brand_name,
            $family->line_name,
            $family->product_type_name,
            $family->family_name,
            $sku->name,
            $sku->sku_code,
            $sku->barcode,
        ])->filter()->unique()->values();

        return $terms->implode(' ');
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

    private function nullTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
