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

$source = [
    '8"' => ['1', '1B', '2', '4', '613'],
    '10"' => ['1', '1B', '2', '4', 'P1B/30', 'P1B/33', 'P4/27', '613'],
    '12"' => ['1', '1B', '2', '4', '6', '7', '27', '30', '33', 'P1B/30', 'P1B/33', 'P4/27', 'P6/27', '613', 'P10/16', 'P10/613', 'P18/613', 'P27/613', 'P10/16/613', 'P12/16/613'],
    '14"' => ['1', '1B', '2', '3', '4', '5', '6', '7', '8', '9', '10', '12', '14', '16', '18', '22', '24', '27', '30', '33', '130', '333', '350', '31', '32', '34', '35', '60', '613', '1001', '39J', '99J', '118', '369', '425', '530', 'RED', 'PURPLE', 'M PURPLE', 'BLUE', 'GREEN', 'DARK BLUE', 'YELLOW', 'CERISE', 'VIOLET', 'SKY', 'BUG', 'P27/613', 'P1B/27', 'P1B/30', 'P1B/33', 'P1B/99J', 'F27/613', 'M27/613', 'P99J/32', 'P99J/39J', 'M4/27', 'P4/27', 'P4/30', 'P4/33', 'P6/27', 'P6/10', 'P14/24', 'P18/27', 'P4/613', 'P6/613', 'P16/613', 'P18/613'],
];

$skus = collect($source)
    ->flatMap(fn (array $colours, string $length): array => collect($colours)
        ->map(fn (string $colour): array => [
            'length' => $length,
            'colour' => normaliseColour($colour),
        ])
        ->all())
    ->values();

if ($dryRun) {
    echo "Echo Collection European Weave sample dry run.\n";
    echo "Brand: Echo Collection\n";
    echo "Product type: Weaves\n";
    echo "Family: European Weave\n";
    echo "Material: 100% Human Hair\n";
    echo 'Length groups: '.count($source)."\n";
    echo 'SKU variants: '.$skus->count()."\n\n";

    foreach ($source as $length => $colours) {
        echo "{$length}: ".implode(', ', $colours)."\n";
    }

    exit(0);
}

$summary = DB::transaction(function () use ($skus): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();

    $brand = BrandCatalogueBrand::query()->firstOrCreate(
        [
            'brand_catalogue_id' => $catalogue->id,
            'slug' => 'echo-collection',
        ],
        [
            'name' => 'Echo Collection',
            'is_active' => true,
            'sort_order' => 70,
        ],
    );

    $brand->fill([
        'name' => 'Echo Collection',
        'note' => mergeNote($brand->note, 'Reference structure started from user-provided Echo Collection stock-list excerpt. EW expands to European Weave. Material confirmed as 100% Human Hair.'),
        'is_active' => true,
    ])->save();

    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('is_default', true)
        ->first();

    if (! $line) {
        $line = BrandCatalogueLine::query()->firstOrCreate(
            [
                'brand_catalogue_brand_id' => $brand->id,
                'name' => 'Echo Collection',
            ],
            [
                'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), 'Echo Collection'),
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 0,
            ],
        );
    }

    $line->fill([
        'name' => 'Echo Collection',
        'note' => mergeNote($line->note, 'Default Echo Collection line.'),
        'is_default' => true,
        'is_active' => true,
        'sort_order' => 0,
    ])->save();

    $productType = BrandCatalogueProductType::query()->firstOrCreate(
        [
            'brand_catalogue_line_id' => $line->id,
            'name' => 'Weaves',
        ],
        [
            'brand_catalogue_brand_id' => $brand->id,
            'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), 'Weaves'),
            'is_active' => true,
            'sort_order' => 10,
        ],
    );

    $productType->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'note' => mergeNote($productType->note, 'Echo Collection weave products.'),
        'is_active' => true,
        'sort_order' => 10,
    ])->save();

    $style = BrandCatalogueStyle::query()
        ->where('brand_catalogue_product_type_id', $productType->id)
        ->where('name', 'European Weave')
        ->first();

    if (! $style) {
        $style = new BrandCatalogueStyle([
            'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), 'European Weave'),
        ]);
    }

    $style->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_product_type_id' => $productType->id,
        'brand_catalogue_material_id' => null,
        'material_name' => '100% Human Hair',
        'name' => 'European Weave',
        'note' => mergeNote($style->note, 'EW from the source list is mapped as European Weave. Source excerpt provides Length and Colour; QTY is not product identity.'),
        'is_active' => true,
        'sort_order' => $style->exists ? $style->sort_order : 10,
    ])->save();

    [$created, $updated] = syncVariantsAndSkus($style, $skus);

    return [
        'brand_id' => $brand->id,
        'line_id' => $line->id,
        'product_type_id' => $productType->id,
        'style_id' => $style->id,
        'skus_created' => $created,
        'skus_updated' => $updated,
        'retail_products_created' => 0,
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
    ];
});

echo "Echo Collection European Weave sample imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @param \Illuminate\Support\Collection<int, array{length:string,colour:string}> $skus
 * @return array{0:int,1:int}
 */
function syncVariantsAndSkus(BrandCatalogueStyle $style, $skus): array
{
    $lengthVariant = BrandCatalogueVariant::query()->updateOrCreate(
        [
            'brand_catalogue_style_id' => $style->id,
            'name' => 'Length',
        ],
        [
            'variant_type' => 'measurement',
            'sort_order' => 10,
        ],
    );

    $colourVariant = BrandCatalogueVariant::query()->updateOrCreate(
        [
            'brand_catalogue_style_id' => $style->id,
            'name' => 'Colour',
        ],
        [
            'variant_type' => 'colour_code',
            'sort_order' => 20,
        ],
    );

    $lengthOptions = syncOptions($lengthVariant, $skus->pluck('length')->unique()->values()->all());
    $colourOptions = syncOptions($colourVariant, $skus->pluck('colour')->unique()->values()->all());

    $created = 0;
    $updated = 0;

    foreach ($skus as $index => $sourceSku) {
        $variants = [
            'Length' => $sourceSku['length'],
            'Colour' => $sourceSku['colour'],
        ];
        $signature = optionSignature($variants);
        $name = skuName($variants);

        $sku = BrandCatalogueSku::query()
            ->where('brand_catalogue_style_id', $style->id)
            ->where('option_signature', $signature)
            ->first();

        if (! $sku) {
            $sku = new BrandCatalogueSku([
                'brand_catalogue_style_id' => $style->id,
                'option_signature' => $signature,
                'slug' => scopedSlug(BrandCatalogueSku::query()->where('brand_catalogue_style_id', $style->id), $name),
            ]);
            $created++;
        } else {
            $updated++;
        }

        $sku->fill([
            'name' => $name,
            'sku_code' => $sku->sku_code,
            'barcode' => $sku->barcode,
            'description' => $sku->description,
            'note' => mergeNote($sku->note, 'Source item EW '.$sourceSku['length'].' mapped to European Weave '.$sourceSku['length'].'; colour '.$sourceSku['colour'].'.'),
            'is_active' => true,
            'sort_order' => $sku->exists ? $sku->sort_order : $index * 10,
        ])->save();

        DB::table('brand_catalogue_sku_variant_options')
            ->where('brand_catalogue_sku_id', $sku->id)
            ->delete();

        DB::table('brand_catalogue_sku_variant_options')->insert([
            [
                'brand_catalogue_sku_id' => $sku->id,
                'brand_catalogue_variant_id' => $lengthVariant->id,
                'brand_catalogue_variant_option_id' => $lengthOptions[$sourceSku['length']],
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'brand_catalogue_sku_id' => $sku->id,
                'brand_catalogue_variant_id' => $colourVariant->id,
                'brand_catalogue_variant_option_id' => $colourOptions[$sourceSku['colour']],
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    return [$created, $updated];
}

/**
 * @param array<int, string> $values
 * @return array<string, int>
 */
function syncOptions(BrandCatalogueVariant $variant, array $values): array
{
    $map = [];

    foreach ($values as $index => $value) {
        $option = BrandCatalogueVariantOption::query()->updateOrCreate(
            [
                'variant_id' => $variant->id,
                'label' => $value,
            ],
            [
                'value' => $value,
                'sort_order' => $index * 10,
            ],
        );

        $map[$value] = $option->id;
    }

    return $map;
}

/**
 * @param array<string, string> $variants
 */
function optionSignature(array $variants): string
{
    return collect($variants)
        ->map(fn (string $value, string $name): string => $name.':'.$value)
        ->implode('|');
}

/**
 * @param array<string, string> $variants
 */
function skuName(array $variants): string
{
    return 'European Weave - '.$variants['Length'].' - Colour '.$variants['Colour'];
}

function normaliseColour(string $colour): string
{
    return Str::upper(trim($colour));
}

function scopedSlug($query, string $name): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $suffix = 2;

    while ((clone $query)->where('slug', $slug)->exists()) {
        $slug = $base.'-'.$suffix;
        $suffix++;
    }

    return $slug;
}

function mergeNote(?string $existing, string $addition): string
{
    $existing = cleanSpaces((string) $existing);
    $addition = cleanSpaces($addition);

    if ($existing === '') {
        return $addition;
    }

    if (str_contains($existing, $addition)) {
        return $existing;
    }

    return $existing.' '.$addition;
}

function cleanSpaces(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}
