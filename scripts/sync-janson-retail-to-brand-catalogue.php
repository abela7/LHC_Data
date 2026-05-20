<?php

use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueLine;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductVariantGroup;
use App\Models\ProductVariantOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = parseArgs($argv);
$sync = array_key_exists('sync', $args);
$replace = array_key_exists('replace-existing-janson-catalogues', $args);
$limit = isset($args['limit']) ? max(1, (int) $args['limit']) : null;

if (! Schema::hasTable('janson_products')) {
    fwrite(STDERR, "janson_products table does not exist. Import the cleaned Janson JSON first.\n");
    exit(1);
}

$familyQuery = ProductFamily::query()
    ->whereHas('sources', fn ($query) => $query->where('source_type', 'janson_product'))
    ->with([
        'sources',
        'variantGroups.options',
        'products' => fn ($query) => $query->orderBy('sort_order')->orderBy('name'),
        'products.sources',
        'products.variantValues.group',
        'products.variantValues.option',
    ])
    ->orderBy('root_catalogue_name')
    ->orderBy('brand_name')
    ->orderBy('product_type_name')
    ->orderBy('family_name');

if ($limit !== null) {
    $familyQuery->limit($limit);
}

$families = $familyQuery->get();

if ($families->isEmpty()) {
    echo "No Janson retail product families found. Run scripts/create-janson-retail-products.php --sync first.\n";
    exit(0);
}

$sourceIds = $families
    ->flatMap(fn (ProductFamily $family) => $family->products->flatMap(fn (Product $product) => $product->sources))
    ->where('source_type', 'janson_product')
    ->pluck('source_id')
    ->filter()
    ->unique()
    ->values();
$jansonCodes = DB::table('janson_products')
    ->whereIn('id', $sourceIds)
    ->pluck('code', 'id')
    ->map(fn ($code) => trim((string) $code));

$summary = [
    'mode' => $sync ? 'sync' : 'dry-run',
    'replace_existing_janson_catalogues' => $replace ? 'yes' : 'no',
    'source_families' => $families->count(),
    'source_products' => $families->sum(fn (ProductFamily $family): int => $family->products->count()),
    'catalogues_created' => 0,
    'catalogues_updated' => 0,
    'brands_created' => 0,
    'brands_updated' => 0,
    'lines_created' => 0,
    'lines_updated' => 0,
    'product_types_created' => 0,
    'product_types_updated' => 0,
    'families_created' => 0,
    'families_updated' => 0,
    'variant_groups_created' => 0,
    'variant_groups_updated' => 0,
    'variant_options_created' => 0,
    'variant_options_updated' => 0,
    'skus_created' => 0,
    'skus_updated' => 0,
    'retail_families_linked' => 0,
    'retail_products_linked' => 0,
    'backup_path' => null,
];

DB::beginTransaction();
try {
    if ($replace) {
        $summary['backup_path'] = backupAndDeleteReplaceableCatalogues($families, $sync);
    }

    foreach ($families as $family) {
        $catalogue = syncCatalogueForFamily($family, $summary);
        $brand = syncCatalogueBrand($catalogue, $family, $summary);
        $line = syncCatalogueLine($brand, $summary);
        $productType = syncCatalogueProductType($brand, $line, $family, $summary);
        $style = syncCatalogueStyle($brand, $productType, $family, $summary);

        syncFamilyLinks($family, $catalogue, $brand, $line, $productType, $style, $summary);
        syncVariants($style, $family, $summary);
        syncSkus($style, $family, $jansonCodes, $summary);
    }

    if ($sync) {
        DB::commit();
    } else {
        DB::rollBack();
    }
} catch (Throwable $exception) {
    DB::rollBack();
    throw $exception;
}

echo $sync ? "Clean Janson retail catalogue synced into brand-catalogue.\n" : "Clean Janson retail catalogue dry run.\n";
foreach ($summary as $key => $value) {
    echo $key.': '.($value ?? '')."\n";
}
if (! $sync) {
    echo "Run with --sync to write changes. Add --replace-existing-janson-catalogues to remove old non-hair Janson catalogue rows first.\n";
}

/**
 * @param array<int, string> $argv
 * @return array<string, string|bool>
 */
function parseArgs(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $args[$key] = $value;
        } else {
            $args[$arg] = true;
        }
    }

    return $args;
}

function syncCatalogueForFamily(ProductFamily $family, array &$summary): BrandCatalogue
{
    $name = catalogueNameForDepartment($family->root_catalogue_name);
    $slug = Str::slug($name) ?: 'general-products';
    $catalogue = BrandCatalogue::query()->where('slug', $slug)->first();
    $created = false;

    if (! $catalogue) {
        $catalogue = new BrandCatalogue(['slug' => uniqueSlug(BrandCatalogue::query(), $name)]);
        $created = true;
    }

    $catalogue->fill([
        'name' => $name,
        'note' => catalogueNote($name),
        'is_active' => true,
        'sort_order' => catalogueSort($name),
    ])->save();

    bump($summary, $created, 'catalogues_created', 'catalogues_updated');

    return $catalogue;
}

function syncCatalogueBrand(BrandCatalogue $catalogue, ProductFamily $family, array &$summary): BrandCatalogueBrand
{
    $name = cleanName($family->brand_name) ?: 'Unknown';
    $brand = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where('name', $name)
        ->first();
    $created = false;

    if (! $brand) {
        $brand = new BrandCatalogueBrand([
            'brand_catalogue_id' => $catalogue->id,
            'slug' => uniqueSlug(BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id), $name),
        ]);
        $created = true;
    }

    $brand->fill([
        'name' => $name,
        'note' => 'Synced from clean Janson retail product candidates.',
        'url' => null,
        'is_active' => true,
        'sort_order' => brandSort($name),
    ])->save();

    bump($summary, $created, 'brands_created', 'brands_updated');

    return $brand;
}

function syncCatalogueLine(BrandCatalogueBrand $brand, array &$summary): BrandCatalogueLine
{
    $name = 'Products';
    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', $name)
        ->first();
    $created = false;

    if (! $line) {
        $line = new BrandCatalogueLine([
            'brand_catalogue_brand_id' => $brand->id,
            'slug' => uniqueSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), $name),
        ]);
        $created = true;
    }

    $line->fill([
        'name' => $name,
        'note' => 'Default line for non-hair-extension Janson products.',
        'url' => null,
        'is_default' => true,
        'is_active' => true,
        'sort_order' => 10,
    ])->save();

    bump($summary, $created, 'lines_created', 'lines_updated');

    return $line;
}

function syncCatalogueProductType(BrandCatalogueBrand $brand, BrandCatalogueLine $line, ProductFamily $family, array &$summary): BrandCatalogueProductType
{
    $name = cleanName($family->product_type_name) ?: 'General Product';
    $productType = BrandCatalogueProductType::query()
        ->where('brand_catalogue_line_id', $line->id)
        ->where('name', $name)
        ->first();
    $created = false;

    if (! $productType) {
        $productType = new BrandCatalogueProductType([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_line_id' => $line->id,
            'slug' => uniqueSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $name),
        ]);
        $created = true;
    }

    $productType->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_line_id' => $line->id,
        'name' => $name,
        'note' => null,
        'url' => null,
        'is_active' => true,
        'sort_order' => productTypeSort($name),
    ])->save();

    bump($summary, $created, 'product_types_created', 'product_types_updated');

    return $productType;
}

function syncCatalogueStyle(BrandCatalogueBrand $brand, BrandCatalogueProductType $productType, ProductFamily $family, array &$summary): BrandCatalogueStyle
{
    $name = cleanName($family->family_name) ?: cleanName($productType->name);
    $style = null;

    if ($family->brand_catalogue_style_id) {
        $style = BrandCatalogueStyle::query()->find($family->brand_catalogue_style_id);
    }

    if (! $style) {
        $style = BrandCatalogueStyle::query()
            ->where('brand_catalogue_product_type_id', $productType->id)
            ->where('name', $name)
            ->get()
            ->first(function (BrandCatalogueStyle $candidate) use ($family): bool {
                return ! ProductFamily::query()
                    ->where('brand_catalogue_style_id', $candidate->id)
                    ->whereKeyNot($family->id)
                    ->exists();
            });
    }

    $created = false;
    if (! $style) {
        $style = new BrandCatalogueStyle([
            'slug' => uniqueSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $name),
        ]);
        $created = true;
    }

    $style->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_product_type_id' => $productType->id,
        'brand_catalogue_material_id' => null,
        'material_name' => null,
        'name' => $name,
        'note' => 'Clean Janson family. Verify shop presence, image, barcode, stock and retail price before publishing.',
        'url' => null,
        'is_active' => true,
        'sort_order' => styleSort($name),
    ])->save();

    bump($summary, $created, 'families_created', 'families_updated');

    return $style;
}

function syncFamilyLinks(
    ProductFamily $family,
    BrandCatalogue $catalogue,
    BrandCatalogueBrand $brand,
    BrandCatalogueLine $line,
    BrandCatalogueProductType $productType,
    BrandCatalogueStyle $style,
    array &$summary,
): void {
    $family->fill([
        'brand_catalogue_id' => $catalogue->id,
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_line_id' => $line->id,
        'brand_catalogue_product_type_id' => $productType->id,
        'brand_catalogue_style_id' => $style->id,
        'root_catalogue_name' => $catalogue->name,
        'product_type_name' => $productType->name,
    ])->save();
    $summary['retail_families_linked']++;
}

function syncVariants(BrandCatalogueStyle $style, ProductFamily $family, array &$summary): void
{
    foreach ($family->variantGroups as $group) {
        $variant = null;
        if ($group->brand_catalogue_variant_id) {
            $variant = BrandCatalogueVariant::query()->find($group->brand_catalogue_variant_id);
        }
        if (! $variant || (int) $variant->brand_catalogue_style_id !== (int) $style->id) {
            $variant = BrandCatalogueVariant::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->where('name', $group->name)
                ->first();
        }

        $created = false;
        if (! $variant) {
            $variant = new BrandCatalogueVariant([
                'brand_catalogue_style_id' => $style->id,
            ]);
            $created = true;
        }

        $variant->fill([
            'name' => $group->name,
            'variant_type' => $group->variant_type ?: variantTypeForAxis($group->name),
            'url' => null,
            'sort_order' => $group->sort_order,
        ])->save();

        $group->forceFill(['brand_catalogue_variant_id' => $variant->id])->save();
        bump($summary, $created, 'variant_groups_created', 'variant_groups_updated');

        foreach ($group->options as $option) {
            syncVariantOption($variant, $option, $summary);
        }
    }
}

function syncVariantOption(BrandCatalogueVariant $variant, ProductVariantOption $productOption, array &$summary): BrandCatalogueVariantOption
{
    $option = null;
    if ($productOption->brand_catalogue_variant_option_id) {
        $option = BrandCatalogueVariantOption::query()->find($productOption->brand_catalogue_variant_option_id);
    }
    if (! $option || (int) $option->variant_id !== (int) $variant->id) {
        $option = BrandCatalogueVariantOption::query()
            ->where('variant_id', $variant->id)
            ->where('label', $productOption->label)
            ->first();
    }

    $created = false;
    if (! $option) {
        $option = new BrandCatalogueVariantOption(['variant_id' => $variant->id]);
        $created = true;
    }

    $option->fill([
        'label' => $productOption->label,
        'value' => $productOption->value ?: $productOption->label,
        'sort_order' => $productOption->sort_order,
    ])->save();

    $productOption->forceFill(['brand_catalogue_variant_option_id' => $option->id])->save();
    bump($summary, $created, 'variant_options_created', 'variant_options_updated');

    return $option;
}

/**
 * @param \Illuminate\Support\Collection<int, string> $jansonCodes
 */
function syncSkus(BrandCatalogueStyle $style, ProductFamily $family, \Illuminate\Support\Collection $jansonCodes, array &$summary): void
{
    $rows = $family->products
        ->values()
        ->map(function (Product $product, int $index) use ($jansonCodes): array {
            $axes = productAxes($product);
            $source = $product->sources->firstWhere('source_type', 'janson_product');
            $code = $source?->source_id ? (string) ($jansonCodes->get($source->source_id) ?? '') : '';

            return [
                'product' => $product,
                'index' => $index,
                'axes' => $axes,
                'source' => $source,
                'code' => trim($code) ?: null,
                'base_signature' => optionSignatureFromAxes($axes),
            ];
        });
    $signatureCounts = $rows->countBy('base_signature');

    foreach ($rows as $row) {
        /** @var Product $product */
        $product = $row['product'];
        $index = $row['index'];
        $axes = productAxes($product);
        $source = $row['source'];
        $code = $row['code'];
        $signature = $row['base_signature'];
        if (($signatureCounts[$signature] ?? 0) > 1) {
            $signature .= '|sku:'.Str::slug($code ?: 'product-'.$product->id);
        }

        $sku = null;
        if ($product->brand_catalogue_sku_id) {
            $sku = BrandCatalogueSku::query()->find($product->brand_catalogue_sku_id);
        }
        if (! $sku || (int) $sku->brand_catalogue_style_id !== (int) $style->id) {
            $sku = BrandCatalogueSku::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->where('option_signature', $signature)
                ->first();
        }
        if (! $sku && $code) {
            $sku = BrandCatalogueSku::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->where('sku_code', $code)
                ->first();
        }

        $created = false;
        if (! $sku) {
            $sku = new BrandCatalogueSku([
                'brand_catalogue_style_id' => $style->id,
                'slug' => uniqueSlug(BrandCatalogueSku::query()->where('brand_catalogue_style_id', $style->id), $product->name),
            ]);
            $created = true;
        }

        $sku->fill([
            'brand_catalogue_style_id' => $style->id,
            'name' => $product->name,
            'sku_code' => $code,
            'barcode' => $product->barcode,
            'option_signature' => $signature,
            'description' => $product->description,
            'note' => skuNote($product, $source?->source_id, $code),
            'url' => null,
            'is_active' => true,
            'sort_order' => $product->sort_order ?: (($index + 1) * 10),
        ])->save();

        syncSkuOptionLinks($sku, $product);
        $product->forceFill(['brand_catalogue_sku_id' => $sku->id])->save();
        $summary['retail_products_linked']++;
        bump($summary, $created, 'skus_created', 'skus_updated');
    }
}

function syncSkuOptionLinks(BrandCatalogueSku $sku, Product $product): void
{
    DB::table('brand_catalogue_sku_variant_options')
        ->where('brand_catalogue_sku_id', $sku->id)
        ->delete();

    foreach ($product->variantValues as $value) {
        $variantId = ProductVariantGroup::query()
            ->whereKey($value->product_variant_group_id)
            ->value('brand_catalogue_variant_id');
        $optionId = ProductVariantOption::query()
            ->whereKey($value->product_variant_option_id)
            ->value('brand_catalogue_variant_option_id');
        if (! $variantId || ! $optionId) {
            continue;
        }

        DB::table('brand_catalogue_sku_variant_options')->insert([
            'brand_catalogue_sku_id' => $sku->id,
            'brand_catalogue_variant_id' => $variantId,
            'brand_catalogue_variant_option_id' => $optionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/**
 * @return array<string, string>
 */
function productAxes(Product $product): array
{
    $axes = [];
    foreach ($product->variantValues->sortBy(fn ($value) => sprintf('%03d:%s', $value->group?->sort_order ?? 999, $value->group?->name ?? '')) as $value) {
        if (! $value->group || ! $value->option) {
            continue;
        }
        $axes[$value->group->name] = $value->option->label;
    }

    return $axes;
}

/**
 * @param array<string, string> $axes
 */
function optionSignatureFromAxes(array $axes): string
{
    ksort($axes);

    return collect($axes)
        ->map(fn (string $value, string $axis): string => Str::slug($axis).':'.Str::slug($value))
        ->implode('|');
}

function skuNote(Product $product, mixed $sourceId, ?string $code): string
{
    return implode(' ', array_filter([
        'Synced from clean Janson retail product candidate.',
        "Retail product ID {$product->id}.",
        $sourceId ? "Janson source row ID {$sourceId}." : null,
        $code ? "Janson item code {$code}." : null,
        'Verify shop presence, image, barcode, stock and retail price before publishing.',
    ]));
}

function catalogueNameForDepartment(?string $department): string
{
    $department = cleanName($department ?? '');
    if ($department === '' || Str::lower($department) === 'other') {
        return 'General Products';
    }
    if (in_array(Str::lower($department), ['hair extensions & wigs', 'hair extensions'], true)) {
        return 'Hair Extensions';
    }

    return $department;
}

function catalogueNote(string $name): string
{
    return match ($name) {
        'Hair Products' => 'Clean Janson structure for hair care, styling, treatment and colour products.',
        'Skin Care', 'Body Care' => 'Clean Janson structure for body care, skin care, soaps, lotions and treatments.',
        'Accessories' => 'Clean Janson structure for beauty accessories, combs, brushes and support items.',
        'Electrical' => 'Clean Janson structure for clippers, trimmers, dryers, straighteners and parts.',
        'Fragrances' => 'Clean Janson structure for fragrances and related toiletries.',
        'Makeup' => 'Clean Janson structure for cosmetics and makeup products.',
        default => 'Clean Janson structure for products needing final department review.',
    };
}

function catalogueSort(string $name): int
{
    return match ($name) {
        'Hair Extensions' => 10,
        'Hair Products' => 20,
        'Body Care' => 25,
        'Skin Care' => 30,
        'Accessories' => 40,
        'Electrical' => 50,
        'Fragrances' => 60,
        'Makeup' => 70,
        'General Products' => 90,
        default => 100,
    };
}

function variantTypeForAxis(string $axis): string
{
    return match ($axis) {
        'Size' => 'measurement',
        'Shade' => 'colour_name',
        'Pack' => 'count',
        default => 'text',
    };
}

function brandSort(string $value): int
{
    $letter = Str::upper(trim($value))[0] ?? 'Z';

    return max(1, ord($letter) - 64) * 10;
}

function productTypeSort(string $value): int
{
    $value = Str::lower($value);

    return match (true) {
        str_contains($value, 'shampoo') => 10,
        str_contains($value, 'conditioner') => 20,
        str_contains($value, 'lotion') => 30,
        str_contains($value, 'cream') => 40,
        str_contains($value, 'soap') => 50,
        str_contains($value, 'oil') => 60,
        str_contains($value, 'gel') => 70,
        str_contains($value, 'colour'), str_contains($value, 'color'), str_contains($value, 'dye') => 80,
        default => 100,
    };
}

function styleSort(string $value): int
{
    return brandSort($value);
}

function cleanName(?string $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?? (string) $value);
}

function bump(array &$summary, bool $created, string $createdKey, string $updatedKey): void
{
    if ($created) {
        $summary[$createdKey]++;
    } else {
        $summary[$updatedKey]++;
    }
}

function uniqueSlug($query, string $name): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $i = 2;

    while ((clone $query)->where('slug', $slug)->exists()) {
        $slug = $base.'-'.$i;
        $i++;
    }

    return $slug;
}

function backupAndDeleteReplaceableCatalogues(\Illuminate\Support\Collection $families, bool $write): ?string
{
    $sourceSlugs = $families
        ->map(fn (ProductFamily $family): string => Str::slug(catalogueNameForDepartment($family->root_catalogue_name)) ?: 'general-products')
        ->reject(fn (string $slug): bool => $slug === 'hair-extensions')
        ->merge(['hair-products', 'skin-care', 'body-care', 'accessories', 'electrical', 'fragrances', 'makeup', 'general-products'])
        ->unique()
        ->values();

    $catalogueIds = BrandCatalogue::query()
        ->whereIn('slug', $sourceSlugs)
        ->pluck('id');

    if ($catalogueIds->isEmpty()) {
        return null;
    }

    $backup = [
        'created_at' => now()->toIso8601String(),
        'catalogue_ids' => $catalogueIds->values()->all(),
        'catalogues' => DB::table('brand_catalogues')->whereIn('id', $catalogueIds)->get(),
        'brands' => DB::table('brand_catalogue_brands')->whereIn('brand_catalogue_id', $catalogueIds)->get(),
        'lines' => DB::table('brand_catalogue_lines as l')
            ->join('brand_catalogue_brands as b', 'b.id', '=', 'l.brand_catalogue_brand_id')
            ->whereIn('b.brand_catalogue_id', $catalogueIds)
            ->select('l.*')
            ->get(),
        'product_types' => DB::table('brand_catalogue_product_types as pt')
            ->join('brand_catalogue_brands as b', 'b.id', '=', 'pt.brand_catalogue_brand_id')
            ->whereIn('b.brand_catalogue_id', $catalogueIds)
            ->select('pt.*')
            ->get(),
        'styles' => DB::table('brand_catalogue_styles as s')
            ->join('brand_catalogue_brands as b', 'b.id', '=', 's.brand_catalogue_brand_id')
            ->whereIn('b.brand_catalogue_id', $catalogueIds)
            ->select('s.*')
            ->get(),
    ];

    $styleIds = collect($backup['styles'])->pluck('id');
    $backup['variants'] = DB::table('brand_catalogue_variants')->whereIn('brand_catalogue_style_id', $styleIds)->get();
    $backup['skus'] = DB::table('brand_catalogue_skus')->whereIn('brand_catalogue_style_id', $styleIds)->get();
    $backup['variant_options'] = DB::table('brand_catalogue_variant_options')
        ->whereIn('variant_id', collect($backup['variants'])->pluck('id'))
        ->get();
    $backup['sku_variant_options'] = DB::table('brand_catalogue_sku_variant_options')
        ->whereIn('brand_catalogue_sku_id', collect($backup['skus'])->pluck('id'))
        ->get();

    $path = storage_path('app/backups/janson-brand-catalogue-before-replace-'.now()->format('Ymd-His').'.json');
    if ($write) {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        BrandCatalogue::query()->whereIn('id', $catalogueIds)->delete();
    }

    return $path;
}
