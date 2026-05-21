<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductMedia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Phar;
use PharData;
use RuntimeException;

class ProductFamilyExportService
{
    /**
     * Build a portable product package:
     * - manifest.json for modern importers
     * - CSV files for spreadsheets / generic systems
     * - clean_import.sql for MySQL-compatible import
     * - images/ with local product media copied to stable relative paths
     */
    public function exportFamily(ProductFamily $family): string
    {
        $family->load([
            'brand',
            'catalogueStyle',
            'ecommerceProfile',
            'media',
            'variantGroups.options',
            'categoryAssignments.scaffold',
            'categoryAssignments.axis',
            'categoryAssignments.node.parent',
            'products.price',
            'products.media',
            'products.posProfile',
            'products.ecommerceProfile',
            'products.inventoryLevels.location',
            'products.inventoryLevels.section',
            'products.inventoryLevels.subsection',
            'products.variantValues.group',
            'products.variantValues.option',
            'products.categoryAssignments.scaffold',
            'products.categoryAssignments.axis',
            'products.categoryAssignments.node.parent',
            'products.sources',
            'sources',
        ]);

        $slug = Str::slug($family->brand_name.'-'.$family->display_family_name) ?: 'product-family-'.$family->id;
        $timestamp = now()->format('Ymd-His');
        $exportRoot = storage_path('app/private/exports/product-family-'.$family->id.'-'.$timestamp);
        $packageRoot = $exportRoot.'/'.$slug;

        File::deleteDirectory($exportRoot);
        File::ensureDirectoryExists($packageRoot.'/data');
        File::ensureDirectoryExists($packageRoot.'/images/family');
        File::ensureDirectoryExists($packageRoot.'/images/skus');

        $manifest = $this->manifest($family, $packageRoot);
        $this->writeJson($packageRoot.'/manifest.json', $manifest);
        $this->writeReadme($packageRoot.'/README.txt', $family);
        $this->writeCsvFiles($packageRoot.'/data', $manifest);
        $this->writeSql($packageRoot.'/clean_import.sql', $manifest);

        $archiveBase = storage_path('app/private/exports/'.$slug.'-'.$timestamp);
        $tarPath = $archiveBase.'.tar';
        $gzPath = $tarPath.'.gz';

        @unlink($tarPath);
        @unlink($gzPath);

        $tar = new PharData($tarPath);
        $tar->buildFromDirectory($exportRoot);
        $tar->compress(Phar::GZ);
        unset($tar);
        @unlink($tarPath);
        File::deleteDirectory($exportRoot);

        if (! is_file($gzPath)) {
            throw new RuntimeException('Product export archive could not be created.');
        }

        return $gzPath;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(ProductFamily $family, string $packageRoot): array
    {
        $familyKey = $this->familyKey($family);
        $familyMedia = $family->media
            ->map(fn (ProductMedia $media): array => $this->mediaPayload($media, $familyKey, 'family', $packageRoot))
            ->values()
            ->all();

        $variantGroups = $family->variantGroups
            ->map(fn ($group): array => [
                'export_group_key' => 'variant-group-'.$group->id,
                'name' => $group->name,
                'variant_type' => $group->variant_type,
                'sort_order' => (int) $group->sort_order,
                'options' => $group->options->map(fn ($option): array => [
                    'export_option_key' => 'variant-option-'.$option->id,
                    'group_key' => 'variant-group-'.$group->id,
                    'label' => $option->label,
                    'value' => $option->value,
                    'sort_order' => (int) $option->sort_order,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        $products = $family->products
            ->map(fn (Product $product): array => $this->productPayload($product, $familyKey, $packageRoot))
            ->values()
            ->all();

        return [
            'export_meta' => [
                'schema' => 'lhc_clean_product_family_export',
                'schema_version' => 1,
                'exported_at' => now()->toIso8601String(),
                'source_app' => config('app.name', 'LHC Data'),
                'source_app_url' => config('app.url'),
                'notes' => 'Clean final product export. Staging/import/intake tables are intentionally excluded.',
            ],
            'family' => [
                'export_family_key' => $familyKey,
                'source_family_id' => $family->id,
                'brand' => $family->brand_name,
                'line' => $family->line_name,
                'root_catalogue' => $family->root_catalogue_name,
                'product_type' => $family->product_type_name,
                'family_name' => $family->family_name,
                'display_name' => $family->display_family_name,
                'slug' => $family->slug,
                'sku_family_sequence' => $family->sku_family_seq,
                'description' => $family->description,
                'status' => $family->status,
                'published_at' => optional($family->published_at)->toIso8601String(),
                'source_url' => $family->source_url,
                'ecommerce' => $this->ecommercePayload($family->ecommerceProfile),
                'classifications' => $family->categoryAssignments
                    ->map(fn ($assignment): array => $this->classificationPayload($assignment))
                    ->values()
                    ->all(),
            ],
            'variant_groups' => $variantGroups,
            'products' => $products,
            'media' => [
                'family' => $familyMedia,
                'products' => collect($products)
                    ->flatMap(fn (array $product): array => $product['media'])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(Product $product, string $familyKey, string $packageRoot): array
    {
        $productKey = $this->productKey($product);

        return [
            'export_product_key' => $productKey,
            'family_key' => $familyKey,
            'source_product_id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'legacy_sku' => $product->legacy_sku,
            'barcode' => $product->barcode,
            'receipt_name' => $product->receipt_name,
            'inventory_name' => $product->inventory_name,
            'description' => $product->description,
            'search_keywords' => $product->search_keywords,
            'status' => $product->status,
            'is_pos_active' => (bool) $product->is_pos_active,
            'is_ecommerce_active' => (bool) $product->is_ecommerce_active,
            'is_inventory_tracked' => (bool) $product->is_inventory_tracked,
            'sort_order' => (int) $product->sort_order,
            'price' => $this->pricePayload($product),
            'pos' => $this->posPayload($product),
            'ecommerce' => $this->ecommercePayload($product->ecommerceProfile),
            'variants' => $product->variantValues
                ->sortBy(fn ($value): string => sprintf('%04d:%s', $value->group?->sort_order ?? 0, $value->option?->label ?? ''))
                ->map(fn ($value): array => [
                    'group_key' => $value->group ? 'variant-group-'.$value->group->id : null,
                    'option_key' => $value->option ? 'variant-option-'.$value->option->id : null,
                    'group_name' => $value->group?->name,
                    'variant_type' => $value->group?->variant_type,
                    'option_label' => $value->option?->label,
                    'option_value' => $value->option?->value,
                ])
                ->values()
                ->all(),
            'inventory' => $product->inventoryLevels
                ->map(fn ($level): array => [
                    'location_name' => $level->location?->name,
                    'section_name' => $level->section?->name,
                    'subsection_name' => $level->subsection?->name,
                    'stock_quantity' => $level->stock_quantity !== null ? (float) $level->stock_quantity : null,
                    'shelf_location' => $level->shelf_location,
                    'low_stock_threshold' => $level->low_stock_threshold !== null ? (float) $level->low_stock_threshold : null,
                    'reorder_quantity' => $level->reorder_quantity !== null ? (float) $level->reorder_quantity : null,
                    'supplier' => $level->supplier,
                    'supplier_product_code' => $level->supplier_product_code,
                ])
                ->values()
                ->all(),
            'classifications' => $product->categoryAssignments
                ->map(fn ($assignment): array => $this->classificationPayload($assignment))
                ->values()
                ->all(),
            'media' => $product->media
                ->map(fn (ProductMedia $media): array => $this->mediaPayload($media, $productKey, 'product', $packageRoot, $product))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pricePayload(Product $product): array
    {
        $price = $product->price;

        return [
            'retail_price' => $price?->retail_price !== null ? (float) $price->retail_price : null,
            'compare_at_price' => $price?->compare_at_price !== null ? (float) $price->compare_at_price : null,
            'cost_price' => $price?->cost_price !== null ? (float) $price->cost_price : null,
            'currency' => $price?->currency ?: 'GBP',
            'tax_class' => $price?->tax_class,
            'vat_rate' => $price?->vat_rate !== null ? (float) $price->vat_rate : null,
            'price_notes' => $price?->price_notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function posPayload(Product $product): array
    {
        $profile = $product->posProfile;

        return [
            'receipt_name' => $profile?->receipt_name ?: $product->receipt_name,
            'quick_search_keywords' => $profile?->quick_search_keywords,
            'pos_category' => $profile?->pos_category,
            'discount_allowed' => $profile?->discount_allowed !== null ? (bool) $profile->discount_allowed : true,
            'quick_sale_enabled' => $profile?->quick_sale_enabled !== null ? (bool) $profile->quick_sale_enabled : true,
            'tax_class' => $profile?->tax_class,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ecommercePayload($profile): ?array
    {
        if (! $profile) {
            return null;
        }

        return [
            'profile_level' => $profile->profile_level,
            'online_title' => $profile->online_title,
            'short_description' => $profile->short_description,
            'long_description' => $profile->long_description,
            'seo_slug' => $profile->seo_slug,
            'seo_title' => $profile->seo_title,
            'seo_description' => $profile->seo_description,
            'tags' => $profile->tags,
            'is_published' => (bool) $profile->is_published,
            'click_and_collect_enabled' => (bool) $profile->click_and_collect_enabled,
            'shipping_weight' => $profile->shipping_weight !== null ? (float) $profile->shipping_weight : null,
            'shipping_dimensions' => $profile->shipping_dimensions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classificationPayload($assignment): array
    {
        return [
            'assignment_type' => $assignment->assignment_type,
            'scaffold' => $assignment->scaffold?->name,
            'axis' => $assignment->axis?->name,
            'node' => $assignment->node?->name,
            'parent_node' => $assignment->node?->parent?->name,
            'source_type' => $assignment->source_type,
            'notes' => $assignment->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaPayload(
        ProductMedia $media,
        string $ownerKey,
        string $ownerType,
        string $packageRoot,
        ?Product $product = null,
    ): array {
        $relativeImagePath = $this->copyMediaFile($media, $ownerType, $packageRoot, $product);

        return [
            'export_media_key' => 'media-'.$media->id,
            'owner_type' => $ownerType,
            'owner_key' => $ownerKey,
            'source_media_id' => $media->id,
            'image_role' => $media->image_role,
            'source_type' => $media->source_type,
            'source_label' => $media->source_label,
            'usage_context' => $media->usage_context,
            'image_path' => $relativeImagePath,
            'external_url' => $media->external_url,
            'alt_text' => $media->alt_text,
            'original_filename' => $media->original_filename,
            'mime_type' => $media->mime_type,
            'file_size' => $media->file_size,
            'notes' => $media->notes,
            'is_primary' => (bool) $media->is_primary,
            'sort_order' => (int) $media->sort_order,
        ];
    }

    private function copyMediaFile(ProductMedia $media, string $ownerType, string $packageRoot, ?Product $product = null): ?string
    {
        if (! $media->storage_disk || ! $media->storage_path) {
            return null;
        }

        $disk = Storage::disk($media->storage_disk);

        if (! $disk->exists($media->storage_path)) {
            return null;
        }

        $extension = pathinfo($media->storage_path, PATHINFO_EXTENSION)
            ?: $this->extensionFromMime($media->mime_type)
            ?: 'jpg';

        $role = Str::slug($media->image_role ?: 'image') ?: 'image';
        $filename = $role.'-'.$media->id.'.'.$extension;

        if ($ownerType === 'family') {
            $relativePath = 'images/family/'.$filename;
        } else {
            $skuSegment = Str::slug($product?->sku ?: 'product-'.$media->product_id) ?: 'product-'.$media->product_id;
            $relativePath = 'images/skus/'.$skuSegment.'/'.$filename;
        }

        File::ensureDirectoryExists(dirname($packageRoot.'/'.$relativePath));

        $sourcePath = $disk->path($media->storage_path);
        if (is_file($sourcePath)) {
            File::copy($sourcePath, $packageRoot.'/'.$relativePath);

            return $relativePath;
        }

        File::put($packageRoot.'/'.$relativePath, $disk->get($media->storage_path));

        return $relativePath;
    }

    private function extensionFromMime(?string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }

    private function writeJson(string $path, array $manifest): void
    {
        File::put($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function writeReadme(string $path, ProductFamily $family): void
    {
        File::put($path, implode(PHP_EOL, [
            'LHC Clean Product Family Export',
            '================================',
            '',
            'Family: '.$family->brand_name.' - '.$family->display_family_name,
            'Exported: '.now()->toDateTimeString(),
            '',
            'Files:',
            '- manifest.json: complete nested product family data.',
            '- clean_import.sql: portable MySQL tables and inserts using lhc_export_* table names.',
            '- data/*.csv: flat files for spreadsheet or generic import tools.',
            '- images/: local copies of all stored family/SKU photos available at export time.',
            '',
            'Only final product data is included. Raw intakes, source scraping records, review queues, and staging tables are not included.',
        ]).PHP_EOL);
    }

    private function writeCsvFiles(string $dataDir, array $manifest): void
    {
        $this->writeCsv($dataDir.'/families.csv', [$this->flattenFamily($manifest['family'])]);

        $this->writeCsv($dataDir.'/products.csv', collect($manifest['products'])
            ->map(fn (array $product): array => $this->flattenProduct($product))
            ->all());

        $this->writeCsv($dataDir.'/variant_groups.csv', collect($manifest['variant_groups'])
            ->map(fn (array $group): array => [
                'export_group_key' => $group['export_group_key'],
                'family_key' => $manifest['family']['export_family_key'],
                'name' => $group['name'],
                'variant_type' => $group['variant_type'],
                'sort_order' => $group['sort_order'],
            ])
            ->all());

        $this->writeCsv($dataDir.'/variant_options.csv', collect($manifest['variant_groups'])
            ->flatMap(fn (array $group): array => collect($group['options'])
                ->map(fn (array $option): array => [
                    'export_option_key' => $option['export_option_key'],
                    'export_group_key' => $option['group_key'],
                    'label' => $option['label'],
                    'value' => $option['value'],
                    'sort_order' => $option['sort_order'],
                ])
                ->all())
            ->values()
            ->all());

        $this->writeCsv($dataDir.'/product_variant_values.csv', collect($manifest['products'])
            ->flatMap(fn (array $product): array => collect($product['variants'])
                ->map(fn (array $variant): array => [
                    'export_product_key' => $product['export_product_key'],
                    ...$variant,
                ])
                ->all())
            ->values()
            ->all());

        $this->writeCsv($dataDir.'/media.csv', collect($manifest['media']['family'])
            ->merge($manifest['media']['products'])
            ->values()
            ->all());

        $this->writeCsv($dataDir.'/inventory.csv', collect($manifest['products'])
            ->flatMap(fn (array $product): array => collect($product['inventory'])
                ->map(fn (array $inventory): array => [
                    'export_product_key' => $product['export_product_key'],
                    'sku' => $product['sku'],
                    ...$inventory,
                ])
                ->all())
            ->values()
            ->all());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to write CSV export: '.$path);
        }

        if ($rows === []) {
            fclose($handle);

            return;
        }

        $headers = array_values(array_unique(collect($rows)->flatMap(fn (array $row): array => array_keys($row))->all()));
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $header) => $this->csvValue($row[$header] ?? null), $headers));
        }

        fclose($handle);
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenFamily(array $family): array
    {
        return [
            'export_family_key' => $family['export_family_key'],
            'brand' => $family['brand'],
            'line' => $family['line'],
            'root_catalogue' => $family['root_catalogue'],
            'product_type' => $family['product_type'],
            'family_name' => $family['family_name'],
            'display_name' => $family['display_name'],
            'slug' => $family['slug'],
            'description' => $family['description'],
            'status' => $family['status'],
            'published_at' => $family['published_at'],
            'source_url' => $family['source_url'],
            'ecommerce_title' => $family['ecommerce']['online_title'] ?? null,
            'ecommerce_short_description' => $family['ecommerce']['short_description'] ?? null,
            'ecommerce_long_description' => $family['ecommerce']['long_description'] ?? null,
            'seo_slug' => $family['ecommerce']['seo_slug'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenProduct(array $product): array
    {
        return [
            'export_product_key' => $product['export_product_key'],
            'family_key' => $product['family_key'],
            'sku' => $product['sku'],
            'barcode' => $product['barcode'],
            'name' => $product['name'],
            'receipt_name' => $product['receipt_name'],
            'inventory_name' => $product['inventory_name'],
            'ecommerce_title' => $product['ecommerce']['online_title'] ?? null,
            'description' => $product['description'],
            'status' => $product['status'],
            'pos_active' => $product['is_pos_active'],
            'ecommerce_active' => $product['is_ecommerce_active'],
            'inventory_tracked' => $product['is_inventory_tracked'],
            'retail_price' => $product['price']['retail_price'],
            'compare_at_price' => $product['price']['compare_at_price'],
            'cost_price' => $product['price']['cost_price'],
            'currency' => $product['price']['currency'],
            'tax_class' => $product['price']['tax_class'],
            'vat_rate' => $product['price']['vat_rate'],
            'variant_summary' => collect($product['variants'])
                ->map(fn (array $variant): string => trim(($variant['group_name'] ?? '').': '.($variant['option_label'] ?? '')))
                ->filter()
                ->implode(' | '),
        ];
    }

    private function csvValue(mixed $value): string|int|float|null
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }

    private function writeSql(string $path, array $manifest): void
    {
        $sql = [];
        $sql[] = '-- LHC clean product family export';
        $sql[] = '-- Generated: '.now()->toDateTimeString();
        $sql[] = 'SET NAMES utf8mb4;';
        $sql[] = '';
        $sql[] = 'CREATE TABLE IF NOT EXISTS lhc_export_product_families (export_family_key VARCHAR(191) PRIMARY KEY, brand VARCHAR(191), line VARCHAR(191), root_catalogue VARCHAR(191), product_type VARCHAR(191), family_name VARCHAR(255), display_name VARCHAR(255), slug VARCHAR(255), description LONGTEXT, status VARCHAR(50), published_at VARCHAR(50), source_url TEXT, ecommerce_title VARCHAR(255), ecommerce_short_description TEXT, ecommerce_long_description LONGTEXT, seo_slug VARCHAR(255));';
        $sql[] = 'CREATE TABLE IF NOT EXISTS lhc_export_products (export_product_key VARCHAR(191) PRIMARY KEY, family_key VARCHAR(191), sku VARCHAR(191), barcode VARCHAR(191), name VARCHAR(255), receipt_name VARCHAR(255), inventory_name VARCHAR(255), ecommerce_title VARCHAR(255), description LONGTEXT, status VARCHAR(50), pos_active TINYINT(1), ecommerce_active TINYINT(1), inventory_tracked TINYINT(1), retail_price DECIMAL(10,2), compare_at_price DECIMAL(10,2), cost_price DECIMAL(10,2), currency VARCHAR(3), tax_class VARCHAR(50), vat_rate DECIMAL(5,2), variant_summary TEXT);';
        $sql[] = 'CREATE TABLE IF NOT EXISTS lhc_export_variant_groups (export_group_key VARCHAR(191) PRIMARY KEY, export_family_key VARCHAR(191), name VARCHAR(191), variant_type VARCHAR(100), sort_order INT);';
        $sql[] = 'CREATE TABLE IF NOT EXISTS lhc_export_variant_options (export_option_key VARCHAR(191) PRIMARY KEY, export_group_key VARCHAR(191), label VARCHAR(191), value VARCHAR(191), sort_order INT);';
        $sql[] = 'CREATE TABLE IF NOT EXISTS lhc_export_product_variant_values (export_product_key VARCHAR(191), export_group_key VARCHAR(191), export_option_key VARCHAR(191), group_name VARCHAR(191), variant_type VARCHAR(100), option_label VARCHAR(191), option_value VARCHAR(191));';
        $sql[] = 'CREATE TABLE IF NOT EXISTS lhc_export_product_media (export_media_key VARCHAR(191) PRIMARY KEY, owner_type VARCHAR(20), owner_key VARCHAR(191), image_role VARCHAR(100), usage_context VARCHAR(100), image_path TEXT, external_url TEXT, alt_text TEXT, original_filename VARCHAR(255), mime_type VARCHAR(100), is_primary TINYINT(1), sort_order INT);';
        $sql[] = 'CREATE TABLE IF NOT EXISTS lhc_export_inventory (export_product_key VARCHAR(191), sku VARCHAR(191), location_name VARCHAR(191), section_name VARCHAR(191), subsection_name VARCHAR(191), stock_quantity DECIMAL(10,2), shelf_location VARCHAR(191), supplier VARCHAR(191), supplier_product_code VARCHAR(191));';
        $sql[] = '';

        $this->appendInsert($sql, 'lhc_export_product_families', [$this->flattenFamily($manifest['family'])]);
        $this->appendInsert($sql, 'lhc_export_products', collect($manifest['products'])->map(fn (array $product): array => $this->flattenProduct($product))->all());
        $this->appendInsert($sql, 'lhc_export_variant_groups', collect($manifest['variant_groups'])->map(fn (array $group): array => [
            'export_group_key' => $group['export_group_key'],
            'export_family_key' => $manifest['family']['export_family_key'],
            'name' => $group['name'],
            'variant_type' => $group['variant_type'],
            'sort_order' => $group['sort_order'],
        ])->all());
        $this->appendInsert($sql, 'lhc_export_variant_options', collect($manifest['variant_groups'])->flatMap(fn (array $group): Collection => collect($group['options'])->map(fn (array $option): array => [
            'export_option_key' => $option['export_option_key'],
            'export_group_key' => $option['group_key'],
            'label' => $option['label'],
            'value' => $option['value'],
            'sort_order' => $option['sort_order'],
        ]))->values()->all());
        $this->appendInsert($sql, 'lhc_export_product_variant_values', collect($manifest['products'])->flatMap(fn (array $product): Collection => collect($product['variants'])->map(fn (array $variant): array => [
            'export_product_key' => $product['export_product_key'],
            'export_group_key' => $variant['group_key'],
            'export_option_key' => $variant['option_key'],
            'group_name' => $variant['group_name'],
            'variant_type' => $variant['variant_type'],
            'option_label' => $variant['option_label'],
            'option_value' => $variant['option_value'],
        ]))->values()->all());
        $this->appendInsert($sql, 'lhc_export_product_media', collect($manifest['media']['family'])->merge($manifest['media']['products'])->map(fn (array $media): array => [
            'export_media_key' => $media['export_media_key'],
            'owner_type' => $media['owner_type'],
            'owner_key' => $media['owner_key'],
            'image_role' => $media['image_role'],
            'usage_context' => $media['usage_context'],
            'image_path' => $media['image_path'],
            'external_url' => $media['external_url'],
            'alt_text' => $media['alt_text'],
            'original_filename' => $media['original_filename'],
            'mime_type' => $media['mime_type'],
            'is_primary' => $media['is_primary'],
            'sort_order' => $media['sort_order'],
        ])->all());
        $this->appendInsert($sql, 'lhc_export_inventory', collect($manifest['products'])->flatMap(fn (array $product): Collection => collect($product['inventory'])->map(fn (array $inventory): array => [
            'export_product_key' => $product['export_product_key'],
            'sku' => $product['sku'],
            'location_name' => $inventory['location_name'],
            'section_name' => $inventory['section_name'],
            'subsection_name' => $inventory['subsection_name'],
            'stock_quantity' => $inventory['stock_quantity'],
            'shelf_location' => $inventory['shelf_location'],
            'supplier' => $inventory['supplier'],
            'supplier_product_code' => $inventory['supplier_product_code'],
        ]))->values()->all());

        File::put($path, implode(PHP_EOL, $sql).PHP_EOL);
    }

    /**
     * @param array<int, string> $sql
     * @param array<int, array<string, mixed>> $rows
     */
    private function appendInsert(array &$sql, string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = array_values(array_keys($rows[0]));
        $sql[] = 'DELETE FROM '.$table.' WHERE '.($columns[0] ?? '1').' IN ('.collect($rows)->map(fn (array $row) => $this->sqlValue($row[$columns[0]] ?? null))->implode(', ').');';

        foreach ($rows as $row) {
            $sql[] = 'INSERT INTO '.$table.' (`'.implode('`, `', $columns).'`) VALUES ('
                .collect($columns)->map(fn (string $column): string => $this->sqlValue($row[$column] ?? null))->implode(', ')
                .');';
        }

        $sql[] = '';
    }

    private function sqlValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return DB::connection()->getPdo()->quote((string) $value);
    }

    private function familyKey(ProductFamily $family): string
    {
        return 'family-'.$family->id.'-'.(Str::slug($family->brand_name.'-'.$family->display_family_name) ?: $family->slug);
    }

    private function productKey(Product $product): string
    {
        return $product->sku ?: 'product-'.$product->id.'-'.(Str::slug($product->name) ?: 'sku');
    }
}
