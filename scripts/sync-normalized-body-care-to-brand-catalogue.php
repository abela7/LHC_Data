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
use App\Services\ShopProductSourceNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = parseArgs($argv);
$sync = array_key_exists('sync', $args);
$includeC = array_key_exists('include-c', $args);
$limit = isset($args['limit']) ? max(1, (int) $args['limit']) : null;

$normalizer = app(ShopProductSourceNormalizer::class);
$departments = ['Body Care', 'Skin Care'];
$confidences = $includeC ? ['A', 'B', 'C'] : ['A', 'B'];

$summary = [
    'mode' => $sync ? 'sync' : 'dry-run',
    'departments' => implode(', ', $departments),
    'confidences' => implode(', ', $confidences),
    'candidate_keys' => 0,
    'families_created_or_enriched' => 0,
    'catalogues_updated' => 0,
    'brands_created' => 0,
    'brands_updated' => 0,
    'lines_created' => 0,
    'lines_updated' => 0,
    'product_types_created' => 0,
    'product_types_updated' => 0,
    'styles_created' => 0,
    'styles_updated' => 0,
    'variant_groups_created' => 0,
    'variant_groups_updated' => 0,
    'variant_options_created' => 0,
    'variant_options_updated' => 0,
    'skus_created' => 0,
    'skus_updated' => 0,
    'retail_families_linked' => 0,
    'retail_products_linked' => 0,
    'errors' => 0,
];

$candidates = collectCandidates($normalizer, $departments, $confidences);
if ($limit !== null) {
    $candidates = array_slice($candidates, 0, $limit);
}

$summary['candidate_keys'] = count($candidates);

DB::beginTransaction();
try {
    foreach ($candidates as $candidate) {
        try {
            $family = $normalizer->createDraftFamilyFromCandidate($candidate);
            $summary['families_created_or_enriched']++;

            syncBodyFamilyToCatalogue($family, $summary);
        } catch (Throwable $exception) {
            $summary['errors']++;
            fwrite(STDERR, "Failed candidate {$candidate['key']}: {$exception->getMessage()}\n");
        }
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

echo $sync ? "Normalized Body Care sources synced into brand-catalogue.\n" : "Normalized Body Care sources dry run.\n";
foreach ($summary as $key => $value) {
    echo $key.': '.$value.PHP_EOL;
}
if (! $sync) {
    echo "Run with --sync to write changes. Add --include-c only if you intentionally want low-confidence rows.\n";
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

/**
 * @param list<string> $departments
 * @param list<string> $confidences
 * @return list<array<string, mixed>>
 */
function collectCandidates(ShopProductSourceNormalizer $normalizer, array $departments, array $confidences): array
{
    $candidates = [];

    foreach ($departments as $department) {
        foreach ($confidences as $confidence) {
            $page = 1;

            do {
                $data = $normalizer->review([
                    'department' => $department,
                    'confidence' => $confidence,
                    'page' => $page,
                    'per_page' => 500,
                ]);

                foreach ($data['candidates'] as $candidate) {
                    $candidates[$candidate['key']] = $candidate;
                }

                $page++;
            } while ($page <= (int) $data['total_pages']);
        }
    }

    return array_values($candidates);
}

function syncBodyFamilyToCatalogue(ProductFamily $family, array &$summary): void
{
    $family = $family->fresh([
        'variantGroups.options',
        'products.sources',
        'products.variantValues.group',
        'products.variantValues.option',
    ]) ?? $family;

    $catalogue = bodyCareCatalogue($summary);
    $brand = syncCatalogueBrand($catalogue, $family, $summary);
    $line = syncCatalogueLine($brand, $summary);
    $productType = syncCatalogueProductType($brand, $line, $family, $summary);
    $style = syncCatalogueStyle($brand, $productType, $family, $summary);

    $family->fill([
        'brand_catalogue_id' => $catalogue->id,
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_line_id' => $line->id,
        'brand_catalogue_product_type_id' => $productType->id,
        'brand_catalogue_style_id' => $style->id,
        'root_catalogue_name' => 'Body Care',
        'product_type_name' => $productType->name,
    ])->save();

    $summary['retail_families_linked']++;

    syncVariants($style, $family, $summary);
    syncSkus($style, $family, $summary);
}

function bodyCareCatalogue(array &$summary): BrandCatalogue
{
    $catalogue = BrandCatalogue::query()->where('slug', 'body-care')->first()
        ?? BrandCatalogue::query()->find(26)
        ?? new BrandCatalogue(['slug' => 'body-care']);

    $catalogue->fill([
        'name' => 'Body Care',
        'slug' => 'body-care',
        'note' => 'Unified catalogue from normalized Body Care and Skin Care source evidence, including Shaba and Sherrys PDF.',
        'is_active' => true,
        'sort_order' => 30,
    ])->save();

    $summary['catalogues_updated']++;

    return $catalogue;
}

function syncCatalogueBrand(BrandCatalogue $catalogue, ProductFamily $family, array &$summary): BrandCatalogueBrand
{
    $name = cleanName($family->brand_name) ?: 'Unknown';
    $brand = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->whereRaw('lower(name) = ?', [Str::lower($name)])
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
        'note' => 'Synced from normalized Body Care source evidence.',
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
        ->whereRaw('lower(name) = ?', [Str::lower($name)])
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
        'note' => 'Default line for body care catalogue products.',
        'is_default' => true,
        'is_active' => true,
        'sort_order' => 10,
    ])->save();

    bump($summary, $created, 'lines_created', 'lines_updated');

    return $line;
}

function syncCatalogueProductType(BrandCatalogueBrand $brand, BrandCatalogueLine $line, ProductFamily $family, array &$summary): BrandCatalogueProductType
{
    $name = cleanName($family->product_type_name) ?: 'General Beauty Product';
    $productType = BrandCatalogueProductType::query()
        ->where('brand_catalogue_line_id', $line->id)
        ->whereRaw('lower(name) = ?', [Str::lower($name)])
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

    if (! $style || (int) $style->brand_catalogue_brand_id !== (int) $brand->id) {
        $style = BrandCatalogueStyle::query()
            ->where('brand_catalogue_product_type_id', $productType->id)
            ->whereRaw('lower(name) = ?', [Str::lower($name)])
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
        'note' => 'Synced from normalized Body Care evidence. Verify shop presence, barcode, image, stock and retail price before activation.',
        'url' => $style->url ?: $family->source_url,
        'is_active' => true,
        'sort_order' => styleSort($name),
    ])->save();

    bump($summary, $created, 'styles_created', 'styles_updated');

    return $style;
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
                ->whereRaw('lower(name) = ?', [Str::lower($group->name)])
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
            ->whereRaw('lower(label) = ?', [Str::lower($productOption->label)])
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

function syncSkus(BrandCatalogueStyle $style, ProductFamily $family, array &$summary): void
{
    foreach ($family->products as $index => $product) {
        $axes = productAxes($product);
        $signature = optionSignatureFromAxes($axes) ?: 'product:'.$product->id;
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
            'sku_code' => $sku->sku_code ?: $product->sku,
            'barcode' => $product->barcode,
            'option_signature' => $signature,
            'description' => $product->description,
            'note' => 'Synced from normalized Body Care retail family.',
            'url' => $sku->url,
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

function variantTypeForAxis(string $axis): string
{
    return match ($axis) {
        'Size' => 'measurement',
        'Shade' => 'colour_name',
        'Colour', 'Color' => 'colour_code',
        'Pack', 'Pack Count' => 'count',
        default => 'text',
    };
}

function productTypeSort(string $value): int
{
    $value = Str::lower($value);

    return match (true) {
        str_contains($value, 'soap') => 10,
        str_contains($value, 'lotion') => 20,
        str_contains($value, 'cream') => 30,
        str_contains($value, 'butter') => 40,
        str_contains($value, 'oil') => 50,
        str_contains($value, 'wash') => 60,
        str_contains($value, 'gel') => 70,
        str_contains($value, 'serum') => 80,
        str_contains($value, 'deodorant') => 90,
        default => 120,
    };
}

function brandSort(string $value): int
{
    $letter = Str::upper(trim($value))[0] ?? 'Z';

    return max(1, ord($letter) - 64) * 10;
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
