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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dryRun = in_array('--dry-run', $argv, true);

$sourceUrl = 'https://www.sleek.co.uk/noble-noble-gold';
$families = collect(nobleFamilies());
$skuCount = $families->sum(fn (array $family): int => count($family['skus']));

if ($dryRun) {
    echo "Sleek Noble / Noble Gold official product pages dry run.\n";
    echo "Master brand: Sleek\n";
    echo "Line: Noble / Noble Gold\n";
    echo 'Source families: '.$families->count()."\n";
    echo "SKU variants: {$skuCount}\n\n";

    $families->each(function (array $family): void {
        echo "- {$family['product_type']} > {$family['name']}: ".count($family['skus'])." SKUs\n";
    });

    exit(0);
}

$summary = DB::transaction(function () use ($families, $sourceUrl): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();
    $brand = findOrCreateSleekBrand($catalogue);

    $brand->fill([
        'name' => 'Sleek',
        'slug' => uniqueBrandSlug($catalogue, 'sleek', $brand->id),
        'url' => 'https://www.sleek.co.uk/',
        'note' => mergeNote($brand->note, 'Master brand for official Sleek hair ranges. Reference structures are imported from official Sleek range pages and product pages and must be stock-checked before publishing retail products.'),
        'is_active' => true,
    ])->save();

    $line = findOrCreateLine($brand, 'Noble / Noble Gold', $sourceUrl, 340);

    $productTypes = [];
    foreach ($families->pluck('product_type')->unique()->values() as $index => $productTypeName) {
        $productTypes[$productTypeName] = findOrCreateProductType($brand, $line, $productTypeName, $sourceUrl, ($index + 1) * 10);
    }

    $createdStyles = 0;
    $updatedStyles = 0;
    $createdSkus = 0;
    $updatedSkus = 0;
    $styleIds = [];

    foreach ($families as $index => $family) {
        $productType = $productTypes[$family['product_type']];
        $style = findExistingLineStyle($line, $family['name']);

        if (! $style) {
            $style = new BrandCatalogueStyle([
                'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $family['name']),
            ]);
            $createdStyles++;
        } else {
            $updatedStyles++;
        }

        $style->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $productType->id,
            'brand_catalogue_material_id' => null,
            'material_name' => 'Synthetic Hair',
            'name' => $family['name'],
            'note' => mergeNote($style->note, styleNote($family)),
            'url' => $family['url'],
            'is_active' => true,
            'sort_order' => $style->exists ? $style->sort_order : $index * 10,
        ])->save();

        syncStyleImages($style, $family['image_urls'], $family['name']);
        [$created, $updated] = syncVariantsAndSkus($style, collect($family['skus']), $family);

        $createdSkus += $created;
        $updatedSkus += $updated;
        $styleIds[] = $style->id;
    }

    return [
        'brand_id' => $brand->id,
        'line_id' => $line->id,
        'product_types_touched' => count($productTypes),
        'styles_created' => $createdStyles,
        'styles_updated' => $updatedStyles,
        'styles_total_touched' => count(array_unique($styleIds)),
        'skus_created' => $createdSkus,
        'skus_updated' => $updatedSkus,
        'source_skus' => $families->sum(fn (array $family): int => count($family['skus'])),
        'retail_products_created' => 0,
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
        'line_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}/lines/{$line->id}"),
    ];
});

echo "Sleek Noble / Noble Gold official structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return array<int, array<string, mixed>>
 */
function nobleFamilies(): array
{
    return [
        family('Big Bounce Curl', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/big-bounce-curl', 'BIGBC', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583627813028-8PDQOEH2MG25DDHBRRKD/BIG+BOUNCE.jpg'], [
            lengthColourMatrix(['20"', '22"', '24"'], ['1', '1B', '2', '4', 'T1B/30'], '200g', null),
        ], 'Noble Gold 6 pcs synthetic hair weave with bouncy curls.'),
        family('Bohemian Coco', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/bohemian-coco', 'BOHEC', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/a5ed12a0-7d8e-423f-aba2-0868e3adee2f/BEST+SELLER+%2827%29.jpg'], [
            lengthColours('14"', ['1', '1B', '2', '4', 'P1B/30', 'P1B/33'], '120g', '56" x 2'),
        ], 'Noble Gold relaxed afro texture synthetic hair weave.'),
        family('Freedom Weave', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/freedom-weave', 'FREEW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/3387e524-830c-4776-9d25-636391a79f5a/BEST+SELLER+%2829%29.jpg'], [
            lengthColours('14"', ['1', '1B', '2', '4', 'P1B/30', 'P1B/33'], '120g', '112"', 'FREEW14'),
            lengthColours('18"', ['1', '1B', '2', '27', '4', '613', 'P1B/30', 'P1B/33', 'P27/613', 'P4/27'], '115g', '80"', 'FREEW18'),
        ], 'Noble synthetic hair weave in a loose wavy style.'),
        family('Big Kinky', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/big-kinky', 'BIGKW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583628332611-JJIIV18A9A6V1BNI6SH6/BIG+KINKY.jpg'], [
            lengthColourMatrix(['20"', '24"', '28"'], ['1B', '2', '4', 'T4/27'], '200g', null),
        ], 'Noble Gold 6 piece tight kinky curl synthetic hair weave.'),
        family('Bohemian Dora', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/bohemian-dora', 'BOHED', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1591179502337-8GMAKW0681YOO0N2LPAR/Bohem-Dora.jpg'], [
            lengthColours('7"', ['1', '1B', '2', '4', 'P1B/30', 'P1B/33'], '120g', '56" x 2'),
        ], 'Noble Gold 2 piece kinky curl synthetic hair weave.'),
        family('Noble Kinky Bulk', 'Synthetic Bulk Hair', 'https://www.sleek.co.uk/noble-kinky-bulk', 'NKINB', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/4aa342c0-baea-4b81-a509-f5559e578b16/BEST+SELLER+%2830%29+%281%29.jpg'], [
            colourOnly(['1', '1B', '2', '27', '30', '33', '4', 'P1B/30', 'P1B/33'], '125g'),
        ], 'Noble Gold afro texture bulk hair.'),
        family('Big Water', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/big-water', 'BIGWW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583628245962-LCEQ0VA3592KFI73ZH2Q/BIG+BOUNCE.jpg'], [
            lengthColourMatrix(['20"', '24"', '28"'], ['1', '1B', '2', '4', 'T1B/27'], '200g', null),
        ], 'Noble Gold 6 pcs synthetic hair weave with water curl S pattern.'),
        family('Starlight Weave', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/starlight-weave', 'STARLIGHT', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/76f5690d-a55e-4296-b3fa-5ae2e7d81038/Starlight.jpg'], [
            lengthColours('18"', ['1', '1B', '4'], '200g', '100" x 3'),
        ], 'Noble 3 bundle loose full curl synthetic hair weave.'),
        family('Springy Poppin Twist', 'Synthetic Braiding Hair', 'https://www.sleek.co.uk/poppin-twist', 'PTB20/PTB16', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/5b903da3-dcd2-4864-b98b-529a67abcef6/Springy+Poppin+Twist++%E5%9B%BE.jpg'], [
            lengthColourMatrix(['20"', '16"'], ['1B', '27', '30', '33', 'T1B/27', 'T1B/30', 'T4/27', 'T30/27', 'T27/613', 'T4/30/27', 'T4/27/613', '1', '4', '2'], null, null),
        ], 'Pre-stretched, pre-fluffed braiding hair suitable for distressed locs, spring twists, afro kinky, butterfly locs, passion twists and box braids.'),
    ];
}

/**
 * @param array<int, string> $imageUrls
 * @param array<int, array<string, string|null>> $skuGroups
 * @return array<string, mixed>
 */
function family(string $name, string $productType, string $url, string $code, array $imageUrls, array $skuGroups, string $description): array
{
    return [
        'name' => $name,
        'product_type' => $productType,
        'url' => $url,
        'code' => $code,
        'image_urls' => $imageUrls,
        'description' => $description,
        'skus' => collect($skuGroups)->flatten(1)->values()->all(),
    ];
}

/**
 * @param array<int, string> $lengths
 * @param array<int, string> $colours
 * @return array<int, array<string, string|null>>
 */
function lengthColourMatrix(array $lengths, array $colours, ?string $weight, ?string $weftWidth): array
{
    $records = [];
    foreach ($lengths as $length) {
        foreach ($colours as $colour) {
            $records[] = skuRecord($length, $colour, $weight, $weftWidth, null);
        }
    }

    return $records;
}

/**
 * @param array<int, string> $colours
 * @return array<int, array<string, string|null>>
 */
function lengthColours(string $length, array $colours, ?string $weight, ?string $weftWidth, ?string $skuCode = null): array
{
    return collect($colours)
        ->map(fn (string $colour): array => skuRecord($length, $colour, $weight, $weftWidth, $skuCode))
        ->all();
}

/**
 * @param array<int, string> $colours
 * @return array<int, array<string, string|null>>
 */
function colourOnly(array $colours, ?string $weight): array
{
    return collect($colours)
        ->map(fn (string $colour): array => [
            'Colour' => normaliseColour($colour),
            'weight' => $weight,
            'weft_width' => null,
            'sku_code_override' => null,
        ])
        ->all();
}

/**
 * @return array<string, string|null>
 */
function skuRecord(string $length, string $colour, ?string $weight, ?string $weftWidth, ?string $skuCode): array
{
    return [
        'Length' => $length,
        'Colour' => normaliseColour($colour),
        'weight' => $weight,
        'weft_width' => $weftWidth,
        'sku_code_override' => $skuCode,
    ];
}

function findOrCreateSleekBrand(BrandCatalogue $catalogue): BrandCatalogueBrand
{
    $brand = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where(function ($query): void {
            $query
                ->whereIn('slug', ['sleek', 'sleek-hair'])
                ->orWhereIn('name', ['Sleek', 'Sleek Hair']);
        })
        ->first();

    if ($brand) {
        return $brand;
    }

    return BrandCatalogueBrand::query()->create([
        'brand_catalogue_id' => $catalogue->id,
        'name' => 'Sleek',
        'slug' => uniqueBrandSlug($catalogue, 'sleek'),
        'is_active' => true,
        'sort_order' => 160,
    ]);
}

function findOrCreateLine(BrandCatalogueBrand $brand, string $name, string $url, int $sortOrder): BrandCatalogueLine
{
    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', $name)
        ->first();

    if (! $line) {
        $line = new BrandCatalogueLine([
            'brand_catalogue_brand_id' => $brand->id,
            'name' => $name,
            'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), $name),
        ]);
    }

    $line->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'name' => $name,
        'note' => mergeNote($line->note, "{$name} is treated as a sub-brand/line under the Sleek master brand."),
        'url' => $url,
        'is_default' => false,
        'is_active' => true,
        'sort_order' => $line->exists ? $line->sort_order : $sortOrder,
    ])->save();

    return $line;
}

function findOrCreateProductType(BrandCatalogueBrand $brand, BrandCatalogueLine $line, string $name, string $url, int $sortOrder): BrandCatalogueProductType
{
    $productType = BrandCatalogueProductType::query()->firstOrCreate(
        [
            'brand_catalogue_line_id' => $line->id,
            'name' => $name,
        ],
        [
            'brand_catalogue_brand_id' => $brand->id,
            'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $name),
            'is_active' => true,
            'sort_order' => $sortOrder,
        ],
    );

    $productType->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'note' => mergeNote($productType->note, 'Structured from official Sleek Noble / Noble Gold pages.'),
        'url' => $url,
        'is_active' => true,
        'sort_order' => $sortOrder,
    ])->save();

    return $productType;
}

function findExistingLineStyle(BrandCatalogueLine $line, string $name): ?BrandCatalogueStyle
{
    $productTypeIds = BrandCatalogueProductType::query()
        ->where('brand_catalogue_line_id', $line->id)
        ->pluck('id');

    if ($productTypeIds->isEmpty()) {
        return null;
    }

    return BrandCatalogueStyle::query()
        ->whereIn('brand_catalogue_product_type_id', $productTypeIds)
        ->where('name', $name)
        ->first();
}

/**
 * @param array<string, mixed> $family
 */
function styleNote(array $family): string
{
    return "Family/style imported from the official Sleek Noble / Noble Gold product page. Order code {$family['code']}. {$family['description']}";
}

/**
 * @param array<int, string> $imageUrls
 */
function syncStyleImages(BrandCatalogueStyle $style, array $imageUrls, string $styleName): void
{
    foreach ($imageUrls as $index => $imageUrl) {
        CatalogueImage::query()->updateOrCreate(
            [
                'imageable_type' => BrandCatalogueStyle::class,
                'imageable_id' => $style->id,
                'external_url' => $imageUrl,
            ],
            [
                'image_role' => 'source_image',
                'storage_disk' => null,
                'storage_path' => null,
                'original_filename' => basename(parse_url($imageUrl, PHP_URL_PATH) ?: ''),
                'mime_type' => null,
                'file_size' => null,
                'sort_order' => $index * 10,
                'is_primary' => $index === 0,
                'source_label' => 'Sleek official Noble / Noble Gold product page',
                'usage_context' => 'all',
                'notes' => "Official image for {$styleName}.",
            ],
        );
    }
}

/**
 * @param Collection<int, array<string, string|null>> $records
 * @param array<string, mixed> $family
 * @return array{0:int,1:int}
 */
function syncVariantsAndSkus(BrandCatalogueStyle $style, Collection $records, array $family): array
{
    $variantNames = $records
        ->flatMap(fn (array $record): array => array_keys(array_filter(
            $record,
            fn ($value, string $key): bool => in_array($key, ['Length', 'Colour'], true) && (string) $value !== '',
            ARRAY_FILTER_USE_BOTH,
        )))
        ->unique()
        ->values();

    $variants = [];
    foreach ($variantNames as $index => $variantName) {
        $variants[$variantName] = BrandCatalogueVariant::query()->updateOrCreate(
            [
                'brand_catalogue_style_id' => $style->id,
                'name' => $variantName,
            ],
            [
                'variant_type' => $variantName === 'Length' ? 'measurement' : 'colour_code',
                'sort_order' => ($index + 1) * 10,
            ],
        );
    }

    $optionMaps = [];
    foreach ($variants as $variantName => $variant) {
        $values = $records
            ->pluck($variantName)
            ->filter(fn ($value): bool => (string) $value !== '')
            ->unique()
            ->sortBy(fn (string $value): string => $variantName === 'Length' ? sprintf('%05d', lengthNumber($value)) : colourSortKey($value))
            ->values()
            ->all();

        $optionMaps[$variantName] = syncOptions($variant, $values);
    }

    $created = 0;
    $updated = 0;

    foreach ($records as $index => $record) {
        $selected = [];
        foreach (array_keys($variants) as $variantName) {
            if ((string) ($record[$variantName] ?? '') !== '') {
                $selected[$variantName] = (string) $record[$variantName];
            }
        }

        $signature = optionSignature($selected);
        $name = skuName($style->name, $selected);

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
            'sku_code' => $record['sku_code_override'] ?: $family['code'],
            'barcode' => $sku->barcode,
            'description' => $sku->description,
            'note' => mergeNote($sku->note, skuNote($family, $record)),
            'url' => $family['url'],
            'is_active' => true,
            'sort_order' => $sku->exists ? $sku->sort_order : $index * 10,
        ])->save();

        DB::table('brand_catalogue_sku_variant_options')
            ->where('brand_catalogue_sku_id', $sku->id)
            ->delete();

        $rows = [];
        foreach ($selected as $variantName => $value) {
            $rows[] = [
                'brand_catalogue_sku_id' => $sku->id,
                'brand_catalogue_variant_id' => $variants[$variantName]->id,
                'brand_catalogue_variant_option_id' => $optionMaps[$variantName][$value],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('brand_catalogue_sku_variant_options')->insert($rows);
        }
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
 * @param array<string, string> $selected
 */
function optionSignature(array $selected): string
{
    return collect($selected)
        ->map(fn (string $value, string $name): string => $name.':'.$value)
        ->implode('|');
}

/**
 * @param array<string, string> $selected
 */
function skuName(string $styleName, array $selected): string
{
    $parts = [$styleName];

    if (isset($selected['Length'])) {
        $parts[] = $selected['Length'];
    }

    if (isset($selected['Colour'])) {
        $parts[] = 'Colour '.$selected['Colour'];
    }

    return implode(' - ', $parts);
}

/**
 * @param array<string, mixed> $family
 * @param array<string, string|null> $record
 */
function skuNote(array $family, array $record): string
{
    $parts = ["Official Sleek Noble / Noble Gold product page lists this SKU. Order code ".($record['sku_code_override'] ?: $family['code']).'.'];

    if (($record['weight'] ?? null) !== null) {
        $parts[] = 'Weight: '.$record['weight'].'.';
    }

    if (($record['weft_width'] ?? null) !== null) {
        $parts[] = 'Weft width: '.$record['weft_width'].'.';
    }

    return implode(' ', $parts);
}

function lengthNumber(string $length): int
{
    if (preg_match('/\d+/', $length, $match)) {
        return (int) $match[0];
    }

    return 0;
}

function colourSortKey(string $colour): string
{
    if (preg_match('/^\d+$/', $colour)) {
        return sprintf('0%05d', (int) $colour);
    }

    if (preg_match('/^\d+[A-Z]$/', $colour)) {
        return sprintf('1%05d%s', (int) $colour, substr($colour, -1));
    }

    return '2'.$colour;
}

function normaliseColour(string $colour): string
{
    return cleanSpaces(Str::upper($colour));
}

function uniqueBrandSlug(BrandCatalogue $catalogue, string $slug, ?int $exceptId = null): string
{
    $base = Str::slug($slug) ?: 'item';
    $candidate = $base;
    $suffix = 2;

    while (
        BrandCatalogueBrand::query()
            ->where('brand_catalogue_id', $catalogue->id)
            ->where('slug', $candidate)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists()
    ) {
        $candidate = $base.'-'.$suffix;
        $suffix++;
    }

    return $candidate;
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
