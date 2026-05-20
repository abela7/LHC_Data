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

$brandName = 'Pure NaturALL';

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

    foreach ($sourceProducts as $sourceProduct) {
        $parts = parseNaturallSource($sourceProduct);
        if ($parts === []) {
            continue;
        }

        $sourceProduct->forceFill([
            'family_name' => 'Pure NaturALL Weave : '.$parts['style'],
            'variant_name' => 'Pack: '.$parts['pack'].'; Length Set: '.$parts['length_set'].'; Colour: '.$parts['colour'],
            'updated_at' => now(),
        ])->save();
    }

    $sourceProducts = MamadoProduct::query()
        ->where('brand_label', $brandName)
        ->whereNotNull('family_name')
        ->orderBy('family_name')
        ->orderBy('item_code')
        ->get();

    $line = BrandCatalogueLine::query()->firstOrCreate(
        [
            'brand_catalogue_brand_id' => $brand->id,
            'name' => 'Pure NaturALL Weave',
        ],
        [
            'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), 'Pure NaturALL Weave'),
            'note' => 'Structured from Pure NaturALL Mamado weave orders.',
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
            'name' => 'Weaves & Bundles',
        ],
        [
            'brand_catalogue_brand_id' => $brand->id,
            'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), 'Weaves & Bundles'),
            'note' => 'Weave pack products structured from Mamado order history.',
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
        $styleName = cleanSpaces(Str::after((string) $familyName, 'Pure NaturALL Weave :')) ?: cleanSpaces((string) $familyName);

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
            'material_name' => null,
            'note' => 'Structured from Mamado family: '.$familyName,
            'url' => url('/mamado-products?brand='.rawurlencode($brandName).'&family='.rawurlencode((string) $familyName)),
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

echo "Pure NaturALL structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return array{pack:string, style:string, length_set:string, colour:string}|array{}
 */
function parseNaturallSource(MamadoProduct $product): array
{
    $description = cleanSpaces($product->item_description);

    if (! preg_match('/Naturall\s+(\d+)\s*pcs\s+Wve\s*:\s*(.+?)\s+(\d+(?:\/\d+)+)\s*\(Col\.\s*([^)]+)\)/i', $description, $match)) {
        return [];
    }

    return [
        'pack' => $match[1].'pcs',
        'style' => titleStyle($match[2]),
        'length_set' => cleanSpaces($match[3]),
        'colour' => normalizeColour($match[4]),
    ];
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
        'pack' => 10,
        'length set' => 20,
        'colour', 'color' => 30,
        default => 90,
    };
}

function variantType(string $name): string
{
    return match (Str::lower($name)) {
        'pack' => 'count',
        'length set' => 'measurement',
        'colour', 'color' => 'colour_code',
        default => 'text',
    };
}

function optionLabel(string $variantName, string $value): string
{
    if (Str::lower($variantName) === 'pack' && preg_match('/^(\d+)pcs$/i', $value, $match)) {
        return $match[1].' pcs';
    }

    if (Str::lower($variantName) === 'length set') {
        return $value.' inch';
    }

    return $value;
}

/**
 * @param array<string, string> $variants
 */
function sellableSkuName(string $styleName, array $variants): string
{
    $name = 'Pure NaturALL Weave '.$styleName;

    if (isset($variants['Pack'])) {
        $name .= ' '.optionLabel('Pack', $variants['Pack']);
    }

    if (isset($variants['Length Set'])) {
        $name .= ' '.optionLabel('Length Set', $variants['Length Set']);
    }

    $colour = $variants['Colour'] ?? $variants['Color'] ?? null;
    if ($colour) {
        $name .= ' - Colour '.$colour;
    }

    return cleanSpaces($name);
}

function titleStyle(string $value): string
{
    return str_replace(' And ', ' and ', Str::title(Str::lower(cleanSpaces($value))));
}

function normalizeColour(string $value): string
{
    return strtoupper(cleanSpaces($value));
}

function naturalSortKey(string $value): string
{
    if (preg_match('/^\d+pcs$/i', $value, $match)) {
        return str_pad((string) ((int) $value), 8, '0', STR_PAD_LEFT);
    }

    if (preg_match('/^(\d+)/', $value, $match)) {
        return str_pad($match[1], 8, '0', STR_PAD_LEFT).Str::lower($value);
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
