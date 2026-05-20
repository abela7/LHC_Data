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

$brandName = 'Obsession';

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

    // One Mamado source row had the colour text leaking into the family name.
    MamadoProduct::query()
        ->where('brand_label', $brandName)
        ->where('family_name', 'Obsession Bulk : Pre-fluffed Poppin Twist 16 Light Green')
        ->update([
            'family_name' => 'Obsession Bulk : Pre-fluffed Poppin Twist',
            'updated_at' => now(),
        ]);

    foreach ([
        'OB3XPFPT20TMINT' => 'Bundle: 3x; Length: 20; Colour: T MINT',
        'OB3XPFPT20TSEAWD' => 'Bundle: 3x; Length: 20; Colour: T SEAWEED',
        'OB3XPFPT20TSTRLG' => 'Bundle: 3x; Length: 20; Colour: T STERLING',
    ] as $itemCode => $variantName) {
        MamadoProduct::query()
            ->where('brand_label', $brandName)
            ->where('item_code', $itemCode)
            ->update([
                'variant_name' => $variantName,
                'updated_at' => now(),
            ]);
    }

    $sourceProducts = MamadoProduct::query()
        ->where('brand_label', $brandName)
        ->whereNotNull('family_name')
        ->orderBy('family_name')
        ->orderBy('item_code')
        ->get();

    $styles = [];
    $skus = 0;

    foreach ($sourceProducts->groupBy(fn (MamadoProduct $product): string => canonicalFamily((string) $product->family_name)) as $familyName => $products) {
        $structure = obsessionStructure($familyName);

        $line = BrandCatalogueLine::query()->firstOrCreate(
            [
                'brand_catalogue_brand_id' => $brand->id,
                'name' => $structure['line'],
            ],
            [
                'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), $structure['line']),
                'note' => 'Structured from Mamado Obsession source products.',
                'is_default' => false,
                'is_active' => true,
                'sort_order' => $structure['line_sort'],
            ],
        );

        $line->fill([
            'is_default' => false,
            'is_active' => true,
            'sort_order' => $structure['line_sort'],
        ])->save();

        $productType = BrandCatalogueProductType::query()->firstOrCreate(
            [
                'brand_catalogue_line_id' => $line->id,
                'name' => $structure['product_type'],
            ],
            [
                'brand_catalogue_brand_id' => $brand->id,
                'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $structure['product_type']),
                'note' => 'Operational product type for Obsession catalogue structure.',
                'is_active' => true,
                'sort_order' => $structure['type_sort'],
            ],
        );

        $productType->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'is_active' => true,
            'sort_order' => $structure['type_sort'],
        ])->save();

        $style = BrandCatalogueStyle::query()
            ->where('brand_catalogue_product_type_id', $productType->id)
            ->where('name', $structure['style'])
            ->first();

        if (! $style) {
            $style = new BrandCatalogueStyle([
                'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $structure['style']),
            ]);
        }

        $style->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $productType->id,
            'name' => $structure['style'],
            'material_name' => $structure['material'],
            'note' => 'Structured from Mamado family: '.$familyName,
            'url' => url('/mamado-products?brand=Obsession&family='.rawurlencode($familyName)),
            'is_active' => true,
            'sort_order' => $structure['style_sort'],
        ])->save();

        [$variantMap, $optionMap, $variantSpecs] = syncVariants($style, $products);
        $skus += syncSkus($style, $structure, $products, $variantSpecs, $variantMap, $optionMap);
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

echo "Obsession structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

function canonicalFamily(string $familyName): string
{
    $familyName = cleanSpaces($familyName);

    return str_replace('Obsession Bulk : Pre-fluffed Poppin Twist 16 Light Green', 'Obsession Bulk : Pre-fluffed Poppin Twist', $familyName);
}

/**
 * @return array{line:string, product_type:string, style:string, material:?string, line_sort:int, type_sort:int, style_sort:int}
 */
function obsessionStructure(string $familyName): array
{
    $familyName = canonicalFamily($familyName);
    $suffix = cleanSpaces(Str::after($familyName, 'Obsession'));

    if (Str::startsWith($familyName, 'Obsession Bulk')) {
        $style = cleanSpaces(Str::after($familyName, 'Obsession Bulk'));
        $style = preg_replace('/^\s*[:\-]\s*/', '', $style) ?? $style;
        $style = match (Str::lower($style)) {
            '3xpf water poppin twist' => 'Water Poppin Twist',
            'pre-fluffed poppin twist' => 'Pre-Fluffed Poppin Twist',
            default => titleStyle($style),
        };

        return [
            'line' => 'Obsession Bulk',
            'product_type' => 'Crochet, Twist & Loc Hair',
            'style' => $style,
            'material' => 'Synthetic Hair',
            'line_sort' => 10,
            'type_sort' => 10,
            'style_sort' => 10,
        ];
    }

    if (Str::startsWith($familyName, 'Obsession Ponytail')) {
        $style = cleanSpaces(Str::after($familyName, 'Obsession Ponytail'));
        $style = preg_replace('/^\s*[:\-]\s*/', '', $style) ?? $style;

        return [
            'line' => 'Obsession Ponytail',
            'product_type' => 'Ponytails & Hair Pieces',
            'style' => titleStyle($style),
            'material' => 'Synthetic Hair',
            'line_sort' => 30,
            'type_sort' => 10,
            'style_sort' => 10,
        ];
    }

    if (preg_match('/^Obsession\s+4x4\s+Lace\s+Wig\s*:\s*(.+)$/i', $familyName, $match)) {
        $style = titleStyle($match[1]).' 4x4';

        return wigStructure($style, 'Synthetic Hair');
    }

    if (preg_match('/^Obsession\s+Lace\s+Wig\s*(?:\(([^)]+)\))?\s*:\s*(.+)$/i', $familyName, $match)) {
        $code = isset($match[1]) ? strtoupper(str_replace([' ', '.'], '', cleanSpaces($match[1]))) : '';
        $code = match ($code) {
            'F/P' => 'F/P',
            'H/HFN' => 'H/H FN',
            'ILP' => 'ILP',
            default => $code,
        };
        $style = titleStyle($match[2]).($code !== '' ? ' '.$code : '');
        $material = $code === 'H/H FN' ? null : 'Synthetic Hair';

        return wigStructure($style, $material);
    }

    return [
        'line' => 'Obsession',
        'product_type' => 'Hair Extensions',
        'style' => titleStyle($suffix ?: $familyName),
        'material' => null,
        'line_sort' => 90,
        'type_sort' => 90,
        'style_sort' => 90,
    ];
}

/**
 * @return array{line:string, product_type:string, style:string, material:?string, line_sort:int, type_sort:int, style_sort:int}
 */
function wigStructure(string $style, ?string $material): array
{
    return [
        'line' => 'Obsession Lace Wig',
        'product_type' => 'Wigs',
        'style' => $style,
        'material' => $material,
        'line_sort' => 20,
        'type_sort' => 10,
        'style_sort' => 10,
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
function syncSkus(BrandCatalogueStyle $style, array $structure, $products, $variantSpecs, array $variantMap, array $optionMap): int
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
        $skuName = sellableSkuName($structure['line'], $structure['style'], $parsed);
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
        'bundle' => 10,
        'length' => 20,
        'colour', 'color' => 30,
        default => 90,
    };
}

function variantType(string $name): string
{
    return match (Str::lower($name)) {
        'bundle' => 'count',
        'length' => 'measurement',
        'colour', 'color' => 'colour_code',
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

/**
 * @param array<string, string> $variants
 */
function sellableSkuName(string $lineName, string $styleName, array $variants): string
{
    $name = cleanSpaces($lineName.' '.$styleName);

    if (isset($variants['Bundle'])) {
        $name .= ' '.$variants['Bundle'];
    }

    if (isset($variants['Length'])) {
        $name .= ' '.optionLabel('Length', $variants['Length']);
    }

    $colour = $variants['Colour'] ?? $variants['Color'] ?? null;
    if ($colour) {
        $name .= ' - Colour '.$colour;
    }

    return cleanSpaces($name);
}

function titleStyle(string $value): string
{
    $value = cleanSpaces($value);
    $value = Str::title(Str::lower($value));

    return str_replace(
        ['F/p', 'H/h', 'Ilp', 'Poppin', 'Pre-Fluffed'],
        ['F/P', 'H/H', 'ILP', 'Poppin', 'Pre-Fluffed'],
        $value,
    );
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
