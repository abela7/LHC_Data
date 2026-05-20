<?php

use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueLine;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use App\Models\CatalogueImage;
use App\Models\InventoryLocation;
use App\Models\MamadoProduct;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductSource;
use App\Services\RetailProductPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = parseArgs($argv);
$brandName = trim((string) ($args['brand'] ?? 'Cherish'));
$familyName = trim((string) ($args['family'] ?? ''));
$publish = array_key_exists('publish', $args);

if ($familyName === '') {
    fwrite(STDERR, "Usage: php scripts/import-mamado-family-to-brand-catalogue.php --brand=\"Cherish\" --family=\"Cherish Bulk - Family\" [--publish]\n");
    exit(1);
}

$sourceProducts = MamadoProduct::query()
    ->where('brand_label', $brandName)
    ->where('family_name', $familyName)
    ->orderBy('item_code')
    ->get();

if ($sourceProducts->isEmpty()) {
    fwrite(STDERR, "No Mamado products found for {$brandName} / {$familyName}.\n");
    exit(1);
}

[$lineName, $styleName] = splitFamilyName($brandName, $familyName);
$productTypeName = inferProductType($lineName, $styleName);
$materialName = 'Synthetic Hair';

$summary = DB::transaction(function () use ($brandName, $familyName, $lineName, $styleName, $productTypeName, $materialName, $sourceProducts, $publish): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();
    $brand = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where('name', $brandName)
        ->first();

    if (! $brand) {
        $brand = BrandCatalogueBrand::query()->create([
            'brand_catalogue_id' => $catalogue->id,
            'slug' => scopedSlug(BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id), $brandName),
            'name' => $brandName,
            'note' => 'Imported from Mamado source products.',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    $brandHasMasterLines = $brand->lines()
        ->where('is_default', false)
        ->exists();

    if (! $brandHasMasterLines && ! Str::startsWith(Str::lower($styleName), Str::lower($lineName))) {
        $styleName = trim($lineName.' '.$styleName);
        $lineName = $brand->name;
        $productTypeName = inferProductType($lineName, $styleName);
    }

    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', $lineName)
        ->first();

    if (! $line) {
        $line = BrandCatalogueLine::query()->create([
            'brand_catalogue_brand_id' => $brand->id,
            'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), $lineName),
            'name' => $lineName,
            'note' => 'Imported from Mamado family grouping.',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);
    }

    $productType = BrandCatalogueProductType::query()
        ->where('brand_catalogue_line_id', $line->id)
        ->where('name', $productTypeName)
        ->first();

    if (! $productType) {
        $productType = BrandCatalogueProductType::query()->create([
            'brand_catalogue_line_id' => $line->id,
            'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $productTypeName),
            'brand_catalogue_brand_id' => $brand->id,
            'name' => $productTypeName,
            'note' => 'Operational product type inferred from Mamado family name.',
            'is_active' => true,
            'sort_order' => 10,
        ]);
    }

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
            'note' => "Imported from Mamado family: {$familyName}. Review source product images before export.",
            'material_name' => $materialName,
            'is_active' => true,
            'sort_order' => 10,
    ])->save();

    $variantSpecs = collect($sourceProducts)
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

        $values = collect($sourceProducts)
            ->map(fn (MamadoProduct $product): ?string => parseVariantName((string) $product->variant_name)[$variantName] ?? null)
            ->filter()
            ->unique()
            ->sortBy(fn (string $value): string => naturalSortKey($value))
            ->values();

        foreach ($values as $optionIndex => $value) {
            $label = optionLabel($variantName, $value);
            $option = BrandCatalogueVariantOption::query()->updateOrCreate(
                [
                    'variant_id' => $variant->id,
                    'label' => $label,
                ],
                [
                    'value' => $value,
                    'sort_order' => $optionIndex * 10,
                ],
            );

            $optionMap[$variantName][$value] = $option;
        }
    }

    $skuCount = 0;
    $imageCount = 0;

    foreach ($sourceProducts as $index => $sourceProduct) {
        $parsed = parseVariantName((string) $sourceProduct->variant_name);
        $signatureParts = [];

        foreach ($variantSpecs as $variantName) {
            if (! isset($parsed[$variantName])) {
                continue;
            }

            $signatureParts[] = $variantName.':'.$parsed[$variantName];
        }

        $optionSignature = implode('|', $signatureParts);
        $skuName = sellableSkuName($lineName, $styleName, $parsed);

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
            'note' => "Mamado item: {$sourceProduct->item_description}",
            'url' => firstUrl((string) $sourceProduct->notes),
            'description' => blankToNull($sourceProduct->description),
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

        foreach (($sourceProduct->image_urls ?? []) as $imageIndex => $imageUrl) {
            $imageUrl = trim((string) $imageUrl);
            if ($imageUrl === '') {
                continue;
            }

            CatalogueImage::query()->updateOrCreate(
                [
                    'imageable_type' => BrandCatalogueSku::class,
                    'imageable_id' => $sku->id,
                    'external_url' => $imageUrl,
                ],
                [
                    'image_role' => $imageIndex === 0 ? 'variant' : 'gallery',
                    'notes' => 'Mamado/Shaba image imported from source product '.$sourceProduct->id,
                    'is_primary' => $imageIndex === 0,
                    'sort_order' => $imageIndex,
                ],
            );

            $imageCount++;
        }

        $skuCount++;
    }

    $firstImage = $sourceProducts
        ->flatMap(fn (MamadoProduct $product): array => $product->image_urls ?? [])
        ->filter()
        ->first();

    if ($firstImage) {
        CatalogueImage::query()->updateOrCreate(
            [
                'imageable_type' => BrandCatalogueStyle::class,
                'imageable_id' => $style->id,
                'external_url' => $firstImage,
            ],
            [
                'image_role' => 'main',
                'notes' => 'Family preview image imported from Mamado source products.',
                'is_primary' => true,
                'sort_order' => 0,
            ],
        );
    }

    $retailFamily = null;
    if ($publish) {
        $retailFamily = app(RetailProductPublisher::class)->publishBrandCatalogueStyle($style->fresh());
        backfillOperationalTrace($retailFamily, $sourceProducts);
    }

    return [
        'style_id' => $style->id,
        'style_url' => url("/brand-catalogue/{$brand->brand_catalogue_id}/brands/{$brand->id}/lines/{$line->id}/product-types/{$productType->id}/styles/{$style->id}"),
        'retail_family_id' => $retailFamily?->id,
        'retail_url' => $retailFamily ? url("/retail-products/families/{$retailFamily->id}") : null,
        'line' => $line->name,
        'product_type' => $productType->name,
        'style' => $style->name,
        'skus' => $skuCount,
        'images' => $imageCount,
        'published' => $publish,
    ];
});

echo "Imported Mamado family into brand catalogue.\n";
foreach ($summary as $key => $value) {
    if ($value !== null && $value !== false) {
        echo "{$key}: {$value}\n";
    }
}

/**
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
        if (! str_contains($arg, '=')) {
            $args[$arg] = true;
            continue;
        }

        [$key, $value] = explode('=', $arg, 2);
        $args[$key] = trim($value, "\"'");
    }

    return $args;
}

/**
 * @return array{0:string,1:string}
 */
function splitFamilyName(string $brandName, string $familyName): array
{
    if (str_contains($familyName, ' - ')) {
        [$line, $style] = explode(' - ', $familyName, 2);

        return [trim($line), trim($style)];
    }

    return [trim($brandName), trim(Str::after($familyName, $brandName)) ?: trim($familyName)];
}

function inferProductType(string $lineName, string $styleName): string
{
    $haystack = Str::lower($lineName.' '.$styleName);

    if (str_contains($haystack, 'wig')) {
        return 'Wigs';
    }

    if (str_contains($haystack, 'ponytail') || str_contains($haystack, 'puff')) {
        return 'Ponytails & Hair Pieces';
    }

    if (str_contains($haystack, 'pre-stretched') || str_contains($haystack, 'braid')) {
        return 'Braiding Hair';
    }

    if (
        str_contains($haystack, 'loc')
        || str_contains($haystack, 'twist')
        || str_contains($haystack, 'box braid')
        || str_contains($haystack, 'senegal')
        || str_contains($haystack, 'bubbly')
    ) {
        return 'Crochet, Twist & Loc Hair';
    }

    if (str_contains($haystack, 'bulk')) {
        return 'Bulk Hair';
    }

    return 'Crochet, Twist & Loc Hair';
}

/**
 * @return array<string, string>
 */
function parseVariantName(string $variantName): array
{
    $parts = array_filter(array_map('trim', explode(';', $variantName)));
    $result = [];

    foreach ($parts as $part) {
        if (! str_contains($part, ':')) {
            continue;
        }

        [$key, $value] = explode(':', $part, 2);
        $result[trim($key)] = trim($value);
    }

    return $result;
}

function variantSortOrder(string $name): int
{
    return match (Str::lower($name)) {
        'length' => 10,
        'colour', 'color' => 20,
        'bundle' => 30,
        default => 90,
    };
}

function variantType(string $name): string
{
    return match (Str::lower($name)) {
        'length' => 'measurement',
        'colour', 'color' => 'colour_code',
        'bundle' => 'count',
        default => 'text',
    };
}

function optionLabel(string $variantName, string $value): string
{
    if (Str::lower($variantName) === 'length' && preg_match('/^\d+$/', $value)) {
        return $value.' inch';
    }

    return $value;
}

function sellableSkuName(string $lineName, string $styleName, array $variants): string
{
    $name = trim($lineName.' '.$styleName);
    $bundle = $variants['Bundle'] ?? null;
    $length = $variants['Length'] ?? null;
    $colour = $variants['Colour'] ?? $variants['Color'] ?? null;

    if ($bundle) {
        $name .= ' '.$bundle;
    }

    if ($length) {
        $name .= ' '.optionLabel('Length', $length);
    }

    if ($colour) {
        $name .= ' - Colour '.$colour;
    }

    return $name;
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

function firstUrl(string $text): ?string
{
    if (preg_match('/https?:\/\/\S+/', $text, $matches)) {
        return rtrim($matches[0], '.,);]');
    }

    return null;
}

function backfillOperationalTrace($retailFamily, $sourceProducts): void
{
    $location = InventoryLocation::query()->firstOrCreate(
        ['slug' => 'shop-floor'],
        [
            'name' => 'Shop Floor',
            'location_type' => 'shop',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ],
    );

    $sourceByCode = $sourceProducts->keyBy('item_code');

    Product::query()
        ->where('product_family_id', $retailFamily->id)
        ->with('catalogueSku')
        ->get()
        ->each(function (Product $product) use ($retailFamily, $sourceByCode, $location): void {
            $source = $product->catalogueSku?->sku_code ? $sourceByCode->get($product->catalogueSku->sku_code) : null;

            if (! $source) {
                return;
            }

            ProductPrice::query()->updateOrCreate(
                ['product_id' => $product->id],
                [
                    'cost_price' => $source->gross_unit_price,
                    'currency' => 'GBP',
                    'tax_class' => 'standard',
                ],
            );

            $product->inventoryLevels()->updateOrCreate(
                ['inventory_location_id' => $location->id],
                [
                    'supplier' => 'Mamado',
                    'supplier_product_code' => $source->item_code,
                ],
            );

            ProductSource::query()->updateOrCreate(
                [
                    'product_family_id' => $retailFamily->id,
                    'product_id' => $product->id,
                    'source_type' => 'mamado_product',
                    'source_table' => 'mamado_products',
                    'source_id' => $source->id,
                ],
                [
                    'source_url' => firstUrl((string) $source->notes),
                    'confidence' => 'A',
                    'notes' => trim((string) $source->notes) ?: 'Imported from Mamado source product.',
                ],
            );
        });
}
