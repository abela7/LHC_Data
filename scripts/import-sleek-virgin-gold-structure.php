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

$sourceUrl = 'https://www.sleek.co.uk/virgin-gold-1';
$families = collect(virginGoldFamilies());
$skuCount = $families->sum(fn (array $family): int => count($family['skus']));

if ($dryRun) {
    echo "Sleek Virgin Gold official product pages dry run.\n";
    echo "Master brand: Sleek\n";
    echo "Line: Virgin Gold\n";
    echo "Section: Human Hair Weave\n";
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

    $line = findOrCreateLine($brand, 'Virgin Gold', $sourceUrl, 200);

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
            'material_name' => '100% Virgin Human Hair',
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

echo "Sleek Virgin Gold official structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return array<int, array<string, mixed>>
 */
function virginGoldFamilies(): array
{
    $weave = 'Human Hair Weave';
    $closure = 'Human Hair Closures / Frontals';

    return [
        family('Brazilian Gold Wavy', $weave, 'https://www.sleek.co.uk/bgw', 'BGW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583366855190-ZXICKPU801206L2TYVUD/abbie2.jpg'], [
            lengthColours(['12"', '14"', '16"', '20"', '22"'], ['1B'], '108g', '100g for 12"'),
            lengthColours(['18"'], ['1B', '2'], '108g', null),
        ], 'Natural waves in soft Virgin Gold human hair.'),
        family('Brazilian Body Wave', $weave, 'https://www.sleek.co.uk/bbw', 'BBW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1587628192146-TVI59WI7MC55N4ZHI458/bbw1.jpg'], [
            lengthColours(['14"', '16"', '18"'], ['1B'], '95g', null),
        ], 'Body wave human hair weave.'),
        family('Brazilian Jerry Wave', $weave, 'https://www.sleek.co.uk/brazilian-jerry-wave', 'BJW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/874f96cd-1ca2-4c0c-9de5-bc5cf6930a1e/BJW.jpg'], [
            lengthColours(['10"', '12"', '14"', '16"', '18"', '20"', '22"', '24"'], ['1B'], '95g', null),
        ], 'Jerry style curl human hair weave.'),
        family('Brazilian Gold Curl', $weave, 'https://www.sleek.co.uk/bgc', 'BGC', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583367872543-Q0LZ8YWDK308AJXKVT2M/BGC.jpg'], [
            lengthColours(['12"', '14"', '16"', '18"', '20"', '22"'], ['1B', '2'], '108g', '100g for 12"'),
        ], 'Loose curl human hair weave.'),
        family('Brazilian Deep Wave', $weave, 'https://www.sleek.co.uk/bdw', 'BDW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583368257595-2DJDNJKB5HW9ZOYDSE1O/BDW.jpg'], [
            lengthColours(['16"', '18"', '20"'], ['1B', '2'], '95g', null),
        ], 'Deep wave human hair weave.'),
        family('Brazilian Mexican Wave', $weave, 'https://www.sleek.co.uk/brazilian-mexican-wave', 'BMW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/618dd5ba-07c9-4eb7-a93c-5f71f603b6b8/BEST+SELLER+%2813%29.jpg'], [
            lengthColours(['10"', '12"', '14"', '16"', '18"', '20"', '22"', '24"'], ['1B'], '95g', null),
        ], 'Loose wet-look wave human hair weave.'),
        family('Peruvian Gold Body Wave', $weave, 'https://www.sleek.co.uk/pgw', 'PGBW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583367348635-I5YV8D25FESFDQ0OXDPR/PGW.jpg'], [
            lengthColours(['12"', '14"', '16"', '18"', '20"', '22"', '24"'], ['1B'], '108g', '100g for 12"'),
        ], 'Peruvian loose body wave human hair weave.'),
        family('Brazilian Italian Wave', $weave, 'https://www.sleek.co.uk/pgs', 'PGS', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583368001190-6M07EZ6HTWH8FT52JXMM/PGS.jpg'], [
            lengthColours(['12"', '14"', '16"', '18"', '20"', '24"'], ['1B', '2'], '108g', '100g for 12"'),
        ], 'Italian wave human hair weave.'),
        family('Brazilian Natural Straight', $weave, 'https://www.sleek.co.uk/biw', 'BIW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583368539346-9PJNZHHKUNUAWMBTZ201/BIW.jpg'], [
            lengthColours(['10"', '12"', '14"'], ['1B', '2'], '95g', null),
        ], 'Natural straight human hair weave.'),
        family('Brazilian Yaki Straight', $weave, 'https://www.sleek.co.uk/brazilian-yaki-straight', 'BYS', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/e03d933a-ab9d-435e-8540-08f45e7df174/WhatsApp+Image+2023-11-13+at+11.45.54+%281%29.jpeg'], [
            lengthColours(['12"', '14"', '16"', '18"', '20"', '22"', '24"'], ['1B'], '95g', null),
        ], 'Crimp yaki texture human hair weave.'),
        family('Brazilian Super Wave', $weave, 'https://www.sleek.co.uk/brazilian-super-wave', 'BSW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/65bb74bd-06a5-44d7-83cc-b25b54baeb99/WhatsApp+Image+2023-11-13+at+11.45.54.jpeg'], [
            lengthColours(['12"', '14"', '16"', '18"', '20"', '22"', '24"'], ['1B'], '95g', null),
        ], 'Crimp wavy texture human hair weave.'),
        family('Body Wave Closure 4" x 2"', $closure, 'https://www.sleek.co.uk/brazilian-closures-4x2', 'BWC', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1584314837626-L13K6E1ZZ685L4JAIVPY/BNS.jpg'], [
            lengthColours(['14"'], ['1B'], null, null),
        ], 'Virgin Gold 4" x 2" body wave closure.'),
        family('Deep Wave Closure 4" x 2"', $closure, 'https://www.sleek.co.uk/brazilian-closures-4x2', 'DWC', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1584314815473-5ZJ13F4K8QGU6M816JYQ/image-asset.jpeg'], [
            lengthColours(['16"'], ['1B', '2'], null, null),
        ], 'Virgin Gold 4" x 2" deep wave closure.'),
        family('Natural Straight Closure 4" x 2"', $closure, 'https://www.sleek.co.uk/brazilian-closures-4x2', 'NSC', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1584314553711-H277CLHKU8T94V17BL7Z/NATURAL+STRAIGHT+LACE+CLOSURE.jpg'], [
            lengthColours(['14"', '18"'], ['1B'], null, null),
        ], 'Virgin Gold 4" x 2" natural straight closure.'),
        family('Italian Wave Closure 4" x 2"', $closure, 'https://www.sleek.co.uk/brazilian-closures-4x2', '1WC', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1584314553711-H277CLHKU8T94V17BL7Z/NATURAL+STRAIGHT+LACE+CLOSURE.jpg'], [
            lengthColours(['14"'], ['1B', '2'], null, null),
        ], 'Virgin Gold 4" x 2" Italian wave closure.'),
        family('Brazilian Wavy Curl Closure 4" x 4"', $closure, 'https://www.sleek.co.uk/brazilian-closures-4x4', 'BWC4', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583369837361-KBZQZ6G2AQBNPMTM7KTM/BNS.jpg'], [
            lengthColours(['14"', '16"', '18"'], ['1B', '2'], null, null),
        ], 'Virgin Gold 4" x 4" Brazilian wavy curl closure.'),
        family('Natural Straight Closure 4" x 4"', $closure, 'https://www.sleek.co.uk/brazilian-closures-4x4', 'NSC4', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1587629884495-5Y83Q0GJSQCOGJDFIGA9/NSC4.jpg'], [
            lengthColours(['14"', '16"', '18"'], ['1B'], null, null),
        ], 'Virgin Gold 4" x 4" natural straight closure.'),
        family('Lace Frontal / Lace Band', $closure, 'https://www.sleek.co.uk/brazilian-frontal', 'BLBS', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1587629462109-QZF1Q4NMX7X52WCDYTJP/lace+frontal.jpg'], [
            lengthColours(['12"'], ['1B', '2'], '58g', null),
        ], 'Virgin Gold 360 lace band/frontals.'),
        family('Silky Breathable Closure', $closure, 'https://www.sleek.co.uk/closures', 'BSC', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583402366746-L5WBD0AJCT2MQAQJIDQ3/SILKY%2BBREATHABLE%2BCLOSURE.jpg'], [
            lengthColours(['13"'], ['1', '1B', '2', '4', '613'], '20g', null),
        ], 'Silky breathable closure.'),
        family('Yaki Breathable Closure', $closure, 'https://www.sleek.co.uk/closures', 'BYC', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583402571081-9IMX1PZ60JXQWC9EX3KL/YAKI%2BBREATHABLE%2BCLOSURE.jpg'], [
            lengthColours(['13"'], ['1', '2', '4'], '16-18g', null),
        ], 'Yaki breathable closure.'),
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
function lengthColours(array $lengths, array $colours, ?string $weight, ?string $sourceNote): array
{
    $records = [];
    foreach ($lengths as $length) {
        foreach ($colours as $colour) {
            $records[] = [
                'Length' => normaliseLength($length),
                'Colour' => normaliseColour($colour),
                'weight' => $weight,
                'source_note' => $sourceNote,
            ];
        }
    }

    return $records;
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
        'note' => mergeNote($line->note, "{$name} is treated as a human hair weave sub-brand/line under the Sleek master brand."),
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
        'note' => mergeNote($productType->note, 'Structured from official Sleek Virgin Gold human hair weave pages.'),
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
    return "Family/style imported from the official Sleek Virgin Gold product page. Order code {$family['code']}. {$family['description']}";
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
                'source_label' => 'Sleek official Virgin Gold product page',
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
    $variantNames = collect(['Length', 'Colour']);

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
        $selected = [
            'Length' => (string) $record['Length'],
            'Colour' => (string) $record['Colour'],
        ];

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
            'sku_code' => $family['code'],
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

        DB::table('brand_catalogue_sku_variant_options')->insert([
            [
                'brand_catalogue_sku_id' => $sku->id,
                'brand_catalogue_variant_id' => $variants['Length']->id,
                'brand_catalogue_variant_option_id' => $optionMaps['Length'][$selected['Length']],
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'brand_catalogue_sku_id' => $sku->id,
                'brand_catalogue_variant_id' => $variants['Colour']->id,
                'brand_catalogue_variant_option_id' => $optionMaps['Colour'][$selected['Colour']],
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
    return $styleName.' - '.$selected['Length'].' - Colour '.$selected['Colour'];
}

/**
 * @param array<string, mixed> $family
 * @param array<string, string|null> $record
 */
function skuNote(array $family, array $record): string
{
    $parts = ["Official Sleek Virgin Gold product page lists this SKU. Order code {$family['code']}."];

    if (($record['weight'] ?? null) !== null) {
        $parts[] = 'Weight: '.$record['weight'].'.';
    }

    if (($record['source_note'] ?? null) !== null) {
        $parts[] = $record['source_note'].'.';
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

function normaliseLength(string $length): string
{
    $length = str_replace(['S', 's', '’', '‘', "'"], ['', '', '"', '"', '"'], trim($length));
    if (preg_match('/\d+/', $length, $match)) {
        return $match[0].'"';
    }

    return $length;
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
