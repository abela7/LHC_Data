<?php

use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueLine;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dryRun = in_array('--dry-run', $argv, true);
$sourceDate = '2026-05-08';
$stockGroups = xpressionShopStockGroups();
$skippedGroups = skippedGroups();

if ($dryRun) {
    echo "X-Pression shop-stock colour dry run.\n";
    echo 'Groups to import: '.count($stockGroups)."\n";
    echo 'Colours to process: '.collect($stockGroups)->sum(fn (array $group): int => count($group['colours']))."\n\n";

    foreach ($stockGroups as $group) {
        $variantText = collect($group['fixed_variants'] ?? [])
            ->map(fn (string $value, string $name): string => "{$name}: {$value}")
            ->implode('; ');
        echo "{$group['source_label']} -> {$group['line']} > {$group['product_type']} > {$group['style']}";
        echo $variantText ? " ({$variantText})" : '';
        echo ' | '.count(normaliseColours($group['colours']))." colours\n";
    }

    echo "\nSkipped for now:\n";
    foreach ($skippedGroups as $label => $reason) {
        echo "- {$label}: {$reason}\n";
    }

    exit(0);
}

$summary = DB::transaction(function () use ($stockGroups, $sourceDate): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();
    $brand = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where('name', 'X-Pression')
        ->firstOrFail();

    $createdSkus = 0;
    $updatedSkus = 0;
    $styleIds = [];

    foreach ($stockGroups as $group) {
        $line = findOrCreateLine($brand, $group);
        $productType = findOrCreateProductType($brand, $line, $group);
        $style = findOrCreateStyle($brand, $productType, $group);
        $styleIds[] = $style->id;

        [$variantMap, $optionMap, $variantNames] = syncVariantsForGroup($style, $group);

        foreach (normaliseColours($group['colours']) as $index => $colour) {
            $variants = orderVariants(array_merge($group['fixed_variants'] ?? [], [
                'Colour' => $colour,
            ]));
            $signature = optionSignature($variants);
            $skuName = skuName($group['sku_prefix'], $variants);
            $sku = BrandCatalogueSku::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->where('option_signature', $signature)
                ->first();

            $created = false;
            if (! $sku) {
                $sku = new BrandCatalogueSku([
                    'brand_catalogue_style_id' => $style->id,
                    'option_signature' => $signature,
                    'slug' => scopedSlug(BrandCatalogueSku::query()->where('brand_catalogue_style_id', $style->id), $skuName),
                ]);
                $created = true;
            }

            $sku->fill([
                'name' => $skuName,
                'sku_code' => $sku->sku_code,
                'barcode' => $sku->barcode,
                'description' => $sku->description,
                'note' => mergeStockNote($sku->note, $group['source_label'], $sourceDate),
                'url' => $sku->url ?: ($group['source_url'] ?? null),
                'is_active' => true,
                'sort_order' => $sku->exists ? $sku->sort_order : $index * 10,
            ])->save();

            DB::table('brand_catalogue_sku_variant_options')
                ->where('brand_catalogue_sku_id', $sku->id)
                ->delete();

            foreach ($variantNames as $variantName) {
                $value = $variants[$variantName] ?? null;
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

            $created ? $createdSkus++ : $updatedSkus++;
        }
    }

    return [
        'brand_id' => $brand->id,
        'groups_processed' => count($stockGroups),
        'styles_touched' => count(array_unique($styleIds)),
        'skus_created' => $createdSkus,
        'skus_updated' => $updatedSkus,
        'retail_products' => 0,
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
    ];
});

echo "X-Pression shop-stock colours imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

echo "Skipped groups:\n";
foreach ($skippedGroups as $label => $reason) {
    echo "- {$label}: {$reason}\n";
}

/**
 * @return array<int, array<string, mixed>>
 */
function xpressionShopStockGroups(): array
{
    return [
        [
            'source_label' => 'Ultra Pre-Stretched',
            'line' => 'X-Pression Braids',
            'product_type' => 'Pre-Stretched Braiding Hair',
            'style' => 'Pre-Stretched',
            'sku_prefix' => 'X-Pression Pre-Stretched',
            'fixed_variants' => ['Bundle' => '2x', 'Length' => '46'],
            'colours' => [
                '1', '1b', '2', '4', '6', '8', '12', '27', '30', '33', '35', '39', '44', '51', '60',
                'BG', '340', '350', '613', '99j', 'Red', 'T1B/27', 'T1B/30', 'T1B/33', 'T1B-35',
                'T1B/39', 'T1B/27/613', 'T1B/60', 'T1B/99j', 'T1B/340', 'T1B/350', 'T1B/BG',
                'T1B B-Rose', 'T1B Stormy', 'T1B/violet', 'T1B/Azure', 'T1B-Purple', 'T1B-Red',
                'Azure', 'AS Pink', 'Berry', 'B/Rose', 'Deep blue', 'Horizon', 'March', 'Stormy',
                'Purple', 'Sunset', 'Violet', 'Rosewood',
            ],
        ],
        [
            'source_label' => 'Ultra Braid',
            'line' => 'X-Pression Braids',
            'product_type' => 'Bulk Braiding Hair',
            'style' => 'Ultra Braid',
            'sku_prefix' => 'X-Pression Ultra Braid',
            'fixed_variants' => ['Length' => '82'],
            'colours' => [
                '1', '1B', '2', '4', '6', '8', '12', '27', '30', '33', '35', '39', '44', '51', '60',
                'BG', 'BR', '99j', 'Red', '340', '350', '613', 'RED', 'Oche', 'Violet', 'Purple',
                'Berry', 'Azure', 'P-Violet', 'Sunset', 'Marsh', 'Horizon', 'Deep blue', 'Rosewood',
            ],
        ],
        [
            'source_label' => 'Lagos Braid',
            'line' => 'X-Pression Braids',
            'product_type' => 'Bulk Braiding Hair',
            'style' => 'Lagos Braid',
            'sku_prefix' => 'X-Pression Lagos Braid',
            'fixed_variants' => ['Length' => '2 x 42" 2 x 46"'],
            'colours' => [
                '1', '1b', '2', '4', '1/27', '1/30', '1/33', '1/35', '1/39', '1/60', '1/Red',
                '1/BG', '1/350', '1/613', '1/Blue', '1/purple',
            ],
        ],
        [
            'source_label' => 'Cosy Pre-Stretched',
            'line' => 'X-Pression Braids',
            'product_type' => 'Pre-Stretched Braiding Hair',
            'style' => 'Cosy Pre-Stretched',
            'sku_prefix' => 'X-Pression Cosy Pre-Stretched',
            'fixed_variants' => [],
            'colours' => [
                '1', '1B', '2', '4', '6', '8', '27', '30', '33', '35', '39', 'BG', '350', '613',
                'Azure', 'March', 'Berry', 'Blond', 'Gold', 'B-Rose', 'As Pink', 'Horizon', 'Pebble',
                'Purple', 'Stormy', 'Purple', 'Rosewood',
            ],
        ],
        [
            'source_label' => '3X Pre-Stretched',
            'line' => 'X-Pression Braids',
            'product_type' => 'Pre-Stretched Braiding Hair',
            'style' => 'Pre-Stretched',
            'sku_prefix' => 'X-Pression Pre-Stretched',
            'fixed_variants' => ['Bundle' => '3x', 'Length' => '50'],
            'source_url' => 'https://hairrenterprise.com/product/x-pression-3x-pre-stretched-braid-50-in/',
            'colours' => [
                '1', '2', '1/27', '1/30', '1/33', '1/39', '1/BG', '1/350', '1/613', '1/Ruby Rogue',
                '1/Twilight', '1/Drift', '1/Chicred', '1/Baby pink', 'Pa/Violet',
            ],
        ],
        [
            'source_label' => '7X Pre-Stretched',
            'line' => 'X-Pression Braids',
            'product_type' => 'Pre-Stretched Braiding Hair',
            'style' => 'Pre-Stretched',
            'sku_prefix' => 'X-Pression Pre-Stretched',
            'fixed_variants' => ['Bundle' => '7x', 'Length' => '64'],
            'source_url' => 'https://hairrenterprise.com/product/x-pression-7x-pre-stretched-braid-64-in/',
            'colours' => [
                '1', '1B', '2', '4', '27', '30', '33', '35', '1/27', '1/30', '1/33', '1/350', '1/613',
            ],
        ],
        [
            'source_label' => 'French Curl / Curly Braid',
            'line' => 'X-Pression Braids',
            'product_type' => 'Curly Braids',
            'style' => 'French Curl / Curly Braid',
            'sku_prefix' => 'X-Pression French Curl / Curly Braid',
            'fixed_variants' => [],
            'colours' => ['1', '27', '1/900', '1/27', 'Fruity', '27/30'],
        ],
        [
            'source_label' => 'Multi',
            'line' => 'X-Pression Crochet Braids',
            'product_type' => 'Crochet Braids',
            'style' => 'Multi Senegal Twist',
            'sku_prefix' => 'X-Pression Multi Senegal Twist',
            'fixed_variants' => ['Length' => '16"-18"'],
            'colours' => ['1', '2', '4', '27', '30', '33', '35', '350'],
        ],
        [
            'source_label' => 'Daniela',
            'line' => 'X-Pression Weave On',
            'product_type' => 'Synthetic Weaves',
            'style' => 'Daniela',
            'sku_prefix' => 'X-Pression Weave On Daniela',
            'fixed_variants' => ['Length' => '15'],
            'colours' => ['1', '2', '4', '30', '33', '1/27', '1/39', '27/30', '1/350'],
        ],
        [
            'source_label' => 'X-Pression Curly Braid',
            'line' => 'X-Pression Crochet Braids',
            'product_type' => 'Crochet Braids',
            'style' => 'Curly Braid',
            'sku_prefix' => 'X-Pression Curly Braid',
            'fixed_variants' => [],
            'colours' => [
                '1', '2', '6', '8', '27', '30', '33', '60', '350', '99j', 'Pebble', 'Azure',
                'Berry', 'Purple', 'Rosewood', 'Horrizon',
            ],
        ],
        [
            'source_label' => 'Diva',
            'line' => 'X-Pression Weave On',
            'product_type' => 'Synthetic Weaves',
            'style' => 'Diva',
            'sku_prefix' => 'X-Pression Weave On Diva',
            'fixed_variants' => ['Length' => '7'],
            'colours' => ['1', '2', '4', '27', '30', '33', '1/27', '1/39', '1/350'],
        ],
        [
            'source_label' => 'Kinky Braid',
            'line' => 'X-Pression Braids',
            'product_type' => 'Bulk Braiding Hair',
            'style' => 'Kinky Braid',
            'sku_prefix' => 'X-Pression Kinky Braid',
            'fixed_variants' => [],
            'colours' => ['27', '30', '33'],
        ],
        [
            'source_label' => 'Soft Twist',
            'line' => 'X-Pression Braids',
            'product_type' => 'Twist Hair',
            'style' => 'Soft Twist',
            'sku_prefix' => 'X-Pression Soft Twist',
            'fixed_variants' => [],
            'colours' => ['1', '1B', '2', '4', '27', '30', '33', '35', '350'],
        ],
        [
            'source_label' => 'Bouncy Kinky',
            'line' => 'X-Pression Crochet Braids',
            'product_type' => 'Crochet Braids',
            'style' => 'Bouncy Kinky',
            'sku_prefix' => 'X-Pression Bouncy Kinky',
            'fixed_variants' => [],
            'colours' => ['1', '2', '27', '30', '33', '39'],
        ],
        [
            'source_label' => 'Ceres Extra',
            'line' => 'X-Pression Crochet Braids',
            'product_type' => 'Crochet Braids',
            'style' => 'Ceres Extra',
            'sku_prefix' => 'X-Pression Ceres Extra',
            'fixed_variants' => [],
            'source_url' => 'https://hairnergybraids.com/products/x-pression-ceres-crochet-braid',
            'colours' => ['1', '2', '27', '30', '33', '35', '39'],
        ],
    ];
}

/**
 * @return array<string, string>
 */
function skippedGroups(): array
{
    return [
        'Darling Loose Braid' => 'Skipped because Darling appears to be a separate brand, not an X-Pression family.',
        'Cosmetics list' => 'Skipped because these are cosmetics/personal-care brands, outside X-Pression hair-extension catalogue.',
    ];
}

function findOrCreateLine(BrandCatalogueBrand $brand, array $group): BrandCatalogueLine
{
    $line = BrandCatalogueLine::query()->firstOrCreate(
        [
            'brand_catalogue_brand_id' => $brand->id,
            'name' => $group['line'],
        ],
        [
            'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), $group['line']),
            'is_default' => false,
            'is_active' => true,
        ],
    );

    $line->fill([
        'note' => $line->note ?: 'X-Pression shop-stock structure.',
        'is_default' => false,
        'is_active' => true,
        'sort_order' => lineSort($group['line']),
    ])->save();

    return $line;
}

function findOrCreateProductType(BrandCatalogueBrand $brand, BrandCatalogueLine $line, array $group): BrandCatalogueProductType
{
    $productType = BrandCatalogueProductType::query()->firstOrCreate(
        [
            'brand_catalogue_line_id' => $line->id,
            'name' => $group['product_type'],
        ],
        [
            'brand_catalogue_brand_id' => $brand->id,
            'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $group['product_type']),
            'is_active' => true,
        ],
    );

    $productType->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'note' => $productType->note ?: 'Product type extended from shop stock colour list.',
        'is_active' => true,
        'sort_order' => productTypeSort($group['product_type']),
    ])->save();

    return $productType;
}

function findOrCreateStyle(BrandCatalogueBrand $brand, BrandCatalogueProductType $productType, array $group): BrandCatalogueStyle
{
    $style = BrandCatalogueStyle::query()
        ->where('brand_catalogue_product_type_id', $productType->id)
        ->where('name', $group['style'])
        ->first();

    if (! $style) {
        $style = new BrandCatalogueStyle([
            'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $group['style']),
        ]);
    }

    $style->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_product_type_id' => $productType->id,
        'name' => $group['style'],
        'material_name' => $style->material_name ?: 'Synthetic Hair',
        'note' => mergeStyleNote($style->note, $group['source_label']),
        'url' => $style->url ?: ($group['source_url'] ?? null),
        'is_active' => true,
        'sort_order' => $style->exists ? $style->sort_order : 50,
    ])->save();

    return $style;
}

/**
 * @return array{0:array<string, BrandCatalogueVariant>, 1:array<string, array<string, BrandCatalogueVariantOption>>, 2:array<int, string>}
 */
function syncVariantsForGroup(BrandCatalogueStyle $style, array $group): array
{
    $variantNames = array_values(array_unique(array_merge(
        array_keys($group['fixed_variants'] ?? []),
        ['Colour'],
    )));

    usort($variantNames, fn (string $a, string $b): int => variantSortOrder($a) <=> variantSortOrder($b));

    $variantMap = [];
    $optionMap = [];

    foreach ($variantNames as $index => $variantName) {
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

        $values = $variantName === 'Colour'
            ? normaliseColours($group['colours'])
            : [normaliseVariantValue($variantName, $group['fixed_variants'][$variantName])];

        foreach ($values as $optionIndex => $value) {
            $option = BrandCatalogueVariantOption::query()->updateOrCreate(
                [
                    'variant_id' => $variant->id,
                    'label' => $value,
                ],
                [
                    'value' => $value,
                    'sort_order' => $optionIndex * 10,
                ],
            );

            $optionMap[$variantName][$value] = $option;
        }
    }

    return [$variantMap, $optionMap, $variantNames];
}

/**
 * @param array<int, string> $colours
 * @return array<int, string>
 */
function normaliseColours(array $colours): array
{
    return collect($colours)
        ->map(fn (string $colour): string => normaliseColour($colour))
        ->filter()
        ->unique()
        ->values()
        ->all();
}

function normaliseColour(string $colour): string
{
    $colour = cleanSpaces(str_replace(['–', '—'], '-', $colour));
    $colour = preg_replace('/\s*\/\s*/', '/', $colour) ?? $colour;
    $colour = preg_replace('/\s*-\s*/', '-', $colour) ?? $colour;

    if (preg_match('/^T1B[\s-]+(.+)$/i', $colour, $match)) {
        $colour = 'T1B/'.$match[1];
    }

    $lower = Str::lower($colour);
    $map = [
        '1b' => '1B',
        '99j' => '99J',
        'bg' => 'BG',
        'br' => 'BR',
        'red' => 'Red',
        'as pink' => 'AS Pink',
        'as-pink' => 'AS Pink',
        'pa/violet' => 'PA/Violet',
        't1b/99j' => 'T1B/99J',
        't1b/violet' => 'T1B/Violet',
        't1b/purple' => 'T1B/Purple',
        't1b/red' => 'T1B/Red',
        't1b/b-rose' => 'T1B/B-Rose',
        't1b b-rose' => 'T1B/B-Rose',
        'b-rose' => 'B-Rose',
        'b/rose' => 'B-Rose',
        'march' => 'Marsh',
        'horrizon' => 'Horizon',
        'deep blue' => 'Deep Blue',
        '1/purple' => '1/Purple',
        '1/blue' => '1/Blue',
        '1/red' => '1/Red',
        '1/baby pink' => '1/Baby Pink',
    ];

    if (isset($map[$lower])) {
        return $map[$lower];
    }

    if (preg_match('/^(T1B|PA|AS|BG|BR|RED)(\/|-)?(.+)?$/i', $colour, $match)) {
        $prefix = Str::upper($match[1]);
        $separator = $match[2] ?? '';
        $suffix = $match[3] ?? '';

        if ($suffix !== '') {
            return $prefix.($separator ?: '/').titleColourSuffix($suffix);
        }

        return $prefix;
    }

    if (preg_match('/^\d+\/(.+)$/', $colour, $match)) {
        [$prefix] = explode('/', $colour, 2);

        return $prefix.'/'.titleColourSuffix($match[1]);
    }

    if (preg_match('/^\d+[A-Za-z]?$/', $colour)) {
        return Str::upper($colour);
    }

    return titleColourSuffix($colour);
}

function titleColourSuffix(string $value): string
{
    $value = cleanSpaces(str_replace('-', ' ', $value));
    $upperCodes = ['BG', 'BR', '99J', 'AS', 'PA'];

    return collect(explode(' ', $value))
        ->map(function (string $part) use ($upperCodes): string {
            if (in_array(Str::upper($part), $upperCodes, true)) {
                return Str::upper($part);
            }

            return Str::ucfirst(Str::lower($part));
        })
        ->implode(' ');
}

/**
 * @param array<string, string> $variants
 * @return array<string, string>
 */
function orderVariants(array $variants): array
{
    $variants = collect($variants)
        ->mapWithKeys(fn (string $value, string $name): array => [$name => normaliseVariantValue($name, $value)])
        ->all();

    uksort($variants, fn (string $a, string $b): int => variantSortOrder($a) <=> variantSortOrder($b));

    return $variants;
}

function normaliseVariantValue(string $name, string $value): string
{
    if (Str::lower($name) === 'colour') {
        return normaliseColour($value);
    }

    if (Str::lower($name) === 'bundle') {
        return Str::lower(cleanSpaces($value));
    }

    if (Str::lower($name) === 'length' && preg_match('/^(\d+(?:\.\d+)?)(?:\D*)$/u', cleanSpaces($value), $match)) {
        return $match[1];
    }

    return cleanSpaces($value);
}

/**
 * @param array<string, string> $variants
 */
function optionSignature(array $variants): string
{
    $parts = [];
    foreach (orderVariants($variants) as $name => $value) {
        $parts[] = $name.':'.$value;
    }

    return implode('|', $parts);
}

/**
 * @param array<string, string> $variants
 */
function skuName(string $prefix, array $variants): string
{
    $parts = [];
    foreach (orderVariants($variants) as $name => $value) {
        $parts[] = Str::lower($name) === 'colour' ? 'Colour '.$value : $value;
    }

    return cleanSpaces($prefix.' - '.implode(' - ', $parts));
}

function mergeStockNote(?string $existing, string $sourceLabel, string $sourceDate): string
{
    $line = "Shop stock colour list {$sourceDate}: {$sourceLabel}.";
    $existing = cleanSpaces((string) $existing);

    if ($existing === '') {
        return $line;
    }

    if (str_contains($existing, $line)) {
        return $existing;
    }

    return $existing.' '.$line;
}

function mergeStyleNote(?string $existing, string $sourceLabel): string
{
    $line = "Extended from shop stock colour list: {$sourceLabel}.";
    $existing = cleanSpaces((string) $existing);

    if ($existing === '') {
        return $line;
    }

    if (str_contains($existing, $line)) {
        return $existing;
    }

    return $existing.' '.$line;
}

function variantSortOrder(string $name): int
{
    return match (Str::lower($name)) {
        'bundle' => 10,
        'length' => 20,
        'colour', 'color' => 50,
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

function lineSort(string $line): int
{
    return match ($line) {
        'X-Pression Braids' => 10,
        'X-Pression Crochet Braids' => 20,
        'X-Pression Weave On' => 30,
        default => 90,
    };
}

function productTypeSort(string $productType): int
{
    return match ($productType) {
        'Pre-Stretched Braiding Hair' => 10,
        'Twist Hair' => 20,
        'Faux Locs' => 30,
        'Bulk Braiding Hair' => 40,
        'Curly Braids' => 50,
        'Crochet Braids' => 10,
        'Synthetic Weaves' => 10,
        default => 90,
    };
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

function cleanSpaces(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}
