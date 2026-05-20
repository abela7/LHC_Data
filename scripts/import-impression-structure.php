<?php

use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueLine;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use App\Models\MamadoProduct;
use App\Support\CustomerProductDescription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$brandName = 'Impression';

$summary = DB::transaction(function () use ($brandName): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();
    $brand = BrandCatalogueBrand::query()->firstOrCreate(
        [
            'brand_catalogue_id' => $catalogue->id,
            'name' => $brandName,
        ],
        [
            'slug' => scopedSlug(BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id), $brandName),
            'note' => 'Imported from Mamado source products.',
            'is_active' => true,
            'sort_order' => 0,
        ],
    );

    $sourceProducts = MamadoProduct::query()
        ->where('brand_label', $brandName)
        ->whereNotNull('family_name')
        ->orderBy('family_name')
        ->orderBy('item_code')
        ->get();

    $line = BrandCatalogueLine::query()->firstOrCreate(
        [
            'brand_catalogue_brand_id' => $brand->id,
            'name' => 'Impression Braid',
        ],
        [
            'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), 'Impression Braid'),
            'note' => 'Structured from Mamado Impression braid orders.',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 10,
        ],
    );

    $line->fill([
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 10,
    ])->save();

    $productType = BrandCatalogueProductType::query()->firstOrCreate(
        [
            'brand_catalogue_line_id' => $line->id,
            'name' => 'Braiding Hair',
        ],
        [
            'brand_catalogue_brand_id' => $brand->id,
            'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), 'Braiding Hair'),
            'note' => 'Braiding hair products structured from Mamado order history.',
            'is_active' => true,
            'sort_order' => 10,
        ],
    );

    $productType->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'is_active' => true,
        'sort_order' => 10,
    ])->save();

    $styles = [];
    $skus = 0;

    foreach ($sourceProducts->groupBy('family_name') as $familyName => $products) {
        $styleName = styleName((string) $familyName);

        $style = BrandCatalogueStyle::query()
            ->where('brand_catalogue_product_type_id', $productType->id)
            ->where('name', $styleName)
            ->first();

        if (! $style) {
            $style = new BrandCatalogueStyle([
                'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $styleName),
            ]);
        }

        $style->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $productType->id,
            'name' => $styleName,
            'material_name' => 'Synthetic Hair',
            'note' => 'Structured from Mamado family: '.$familyName,
            'url' => url('/mamado-products?brand=Impression&family='.rawurlencode((string) $familyName)),
            'is_active' => true,
            'sort_order' => 10,
        ])->save();

        [$variantMap, $optionMap, $variantSpecs] = syncVariants($style, $products);
        $skus += syncSkus($style, $products, $variantSpecs, $variantMap, $optionMap);
        $styles[] = $style->id;
    }

    return [
        'brand_id' => $brand->id,
        'lines' => BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id)->where('is_default', false)->count(),
        'product_types' => BrandCatalogueProductType::query()->where('brand_catalogue_brand_id', $brand->id)->count(),
        'styles' => count(array_unique($styles)),
        'skus' => $skus,
        'retail_products' => 0,
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
    ];
});

echo "Impression structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

function styleName(string $familyName): string
{
    return match (cleanSpaces($familyName)) {
        'Impression Pre-Stretched Super Braid 4X' => 'Pre-Stretched Super Braid 4X',
        'Impression Super Braid' => 'Super Braid',
        default => cleanSpaces(Str::after($familyName, 'Impression')) ?: cleanSpaces($familyName),
    };
}

/**
 * @param \Illuminate\Support\Collection<int, MamadoProduct> $products
 * @return array{0:array<string, BrandCatalogueVariant>, 1:array<string, array<string, BrandCatalogueVariantOption>>, 2:\Illuminate\Support\Collection<int, string>}
 */
function syncVariants(BrandCatalogueStyle $style, $products): array
{
    $variantSpecs = $products
        ->flatMap(fn (MamadoProduct $product): array => array_keys(parseVariantName((string) $product->variant_name)))
        ->unique()
        ->sortBy(fn (string $name): int => variantSortOrder($name))
        ->values();

    $variantMap = [];
    $optionMap = [];

    foreach ($variantSpecs as $index => $variantName) {
        $variant = BrandCatalogueVariant::query()->updateOrCreate(
            [
                'brand_catalogue_style_id' => $style->id,
                'name' => $variantName,
            ],
            [
                'variant_type' => variantType($variantName),
                'sort_order' => $index * 10,
            ],
        );

        $variantMap[$variantName] = $variant;

        $values = $products
            ->map(fn (MamadoProduct $product): ?string => parseVariantName((string) $product->variant_name)[$variantName] ?? null)
            ->filter()
            ->unique()
            ->sortBy(fn (string $value): string => naturalSortKey($value))
            ->values();

        foreach ($values as $optionIndex => $value) {
            $option = BrandCatalogueVariantOption::query()->updateOrCreate(
                [
                    'variant_id' => $variant->id,
                    'label' => optionLabel($variantName, $value),
                ],
                [
                    'value' => $value,
                    'sort_order' => $optionIndex * 10,
                ],
            );

            $optionMap[$variantName][$value] = $option;
        }
    }

    return [$variantMap, $optionMap, $variantSpecs];
}

/**
 * @param \Illuminate\Support\Collection<int, MamadoProduct> $products
 * @param \Illuminate\Support\Collection<int, string> $variantSpecs
 * @param array<string, BrandCatalogueVariant> $variantMap
 * @param array<string, array<string, BrandCatalogueVariantOption>> $optionMap
 */
function syncSkus(BrandCatalogueStyle $style, $products, $variantSpecs, array $variantMap, array $optionMap): int
{
    $count = 0;
    $expectedSignatures = [];

    foreach ($products->values() as $index => $sourceProduct) {
        $parsed = parseVariantName((string) $sourceProduct->variant_name);
        $signatureParts = [];

        foreach ($variantSpecs as $variantName) {
            if (isset($parsed[$variantName])) {
                $signatureParts[] = $variantName.':'.$parsed[$variantName];
            }
        }

        $optionSignature = implode('|', $signatureParts);
        $expectedSignatures[] = $optionSignature;
        $skuName = sellableSkuName($style->name, $parsed);
        $sku = BrandCatalogueSku::query()
            ->where('brand_catalogue_style_id', $style->id)
            ->where('option_signature', $optionSignature)
            ->first();

        if (! $sku) {
            $sku = new BrandCatalogueSku([
                'brand_catalogue_style_id' => $style->id,
                'option_signature' => $optionSignature,
                'slug' => scopedSlug(BrandCatalogueSku::query()->where('brand_catalogue_style_id', $style->id), $skuName),
            ]);
        }

        $sku->fill([
            'name' => $skuName,
            'sku_code' => blankToNull($sourceProduct->item_code),
            'barcode' => null,
            'description' => CustomerProductDescription::clean($sourceProduct->description),
            'note' => 'Mamado item '.$sourceProduct->item_code.': '.$sourceProduct->item_description.'. Gross unit price: '.number_format((float) $sourceProduct->gross_unit_price, 2).'.',
            'url' => url('/mamado-products/'.$sourceProduct->id),
            'is_active' => true,
            'sort_order' => $index * 10,
        ])->save();

        DB::table('brand_catalogue_sku_variant_options')
            ->where('brand_catalogue_sku_id', $sku->id)
            ->delete();

        foreach ($variantSpecs as $variantName) {
            $value = $parsed[$variantName] ?? null;
            $variant = $variantMap[$variantName] ?? null;
            $option = $value !== null ? ($optionMap[$variantName][$value] ?? null) : null;

            if (! $variant || ! $option) {
                continue;
            }

            DB::table('brand_catalogue_sku_variant_options')->updateOrInsert(
                [
                    'brand_catalogue_sku_id' => $sku->id,
                    'brand_catalogue_variant_id' => $variant->id,
                ],
                [
                    'brand_catalogue_variant_option_id' => $option->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $count++;
    }

    BrandCatalogueSku::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->where('note', 'like', 'Mamado item %')
        ->whereNotIn('option_signature', array_unique($expectedSignatures))
        ->delete();

    return $count;
}

/**
 * @return array<string, string>
 */
function parseVariantName(string $variantName): array
{
    $result = [];
    foreach (array_filter(array_map('trim', explode(';', $variantName))) as $part) {
        if (! str_contains($part, ':')) {
            continue;
        }

        [$key, $value] = explode(':', $part, 2);
        $result[cleanSpaces($key)] = cleanSpaces($value);
    }

    return $result;
}

function variantSortOrder(string $name): int
{
    return match (Str::lower($name)) {
        'colour', 'color' => 10,
        default => 90,
    };
}

function variantType(string $name): string
{
    return match (Str::lower($name)) {
        'colour', 'color' => 'colour_code',
        default => 'text',
    };
}

function optionLabel(string $variantName, string $value): string
{
    return $value;
}

/**
 * @param array<string, string> $variants
 */
function sellableSkuName(string $styleName, array $variants): string
{
    $name = 'Impression '.$styleName;
    $colour = $variants['Colour'] ?? $variants['Color'] ?? null;

    if ($colour) {
        $name .= ' - Colour '.$colour;
    }

    return cleanSpaces($name);
}

function naturalSortKey(string $value): string
{
    if (preg_match('/^\d+$/', $value)) {
        return str_pad($value, 8, '0', STR_PAD_LEFT);
    }

    return Str::lower($value);
}

function scopedSlug($query, string $name): string
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

function blankToNull(?string $value): ?string
{
    $value = trim((string) $value);

    return $value === '' ? null : $value;
}

function cleanSpaces(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}
