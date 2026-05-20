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

$sourceUrl = 'https://www.sleek.co.uk/fashion-idol-express';
$families = collect(fashionIdolExpressFamilies());
$skuCount = $families->sum(fn (array $family): int => count($family['skus']));

if ($dryRun) {
    echo "Sleek Fashion Idol Express official product pages dry run.\n";
    echo "Master brand: Sleek\n";
    echo "Line: Fashion Idol Express\n";
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

    $line = findOrCreateLine($brand, 'Fashion Idol Express', $sourceUrl, 300);

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
            'material_name' => '100% Kanekalon Synthetic Fibre',
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

echo "Sleek Fashion Idol Express official structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return array<int, array<string, mixed>>
 */
function fashionIdolExpressFamilies(): array
{
    $type = 'Synthetic Braiding / Crochet Hair';

    return [
        family('Boho Water Braid', $type, 'https://www.sleek.co.uk/boho-water-braid', 'BOHOW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/595b6930-45cc-450e-9af4-75be0d68c504/BEST+SELLER+%2825%29.jpg'], [
            lengthColours('20"', ['1', '1B', '2', '4', 'GREY', 'T1B/27', 'T1B/30', 'T1B/33'], '85g'),
        ], 'Texturised braids or twist out for tight water wave style.'),
        family('Mambo Reggae Twist', $type, 'https://www.sleek.co.uk/mambo-reggae-twist', 'MAMBOREG', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583529592739-SPAJZAQTSTB3MIEQ6P65/MAMBO+REGGAE+TWIST.jpg'], [
            lengthColours('20"', ['1', '1B', '2'], '110g'),
        ], 'Small to medium twisted style. 2 in 1 style.'),
        family('Cuba Twist Marley', $type, 'https://www.sleek.co.uk/cuba-twist-marley', 'CUBATM', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583531559083-52SRA8R4GOGENK4Q28D0/JAM+FAUX+LOCS.jpg'], [
            lengthColours('14"', ['1', '1B', '2', '4', 'P1B/30', 'T1B/27', 'T1B/30', 'T1B/33', 'DARK RED', 'GREY', 'P1B/33', 'T1B/DARKBLUE'], '85g'),
        ], 'Small twisted locs with a natural twist-out texture.'),
        family('Brazilian Ripple Braid', $type, 'https://www.sleek.co.uk/brazilian-ripple-braid', 'RIPB', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583532716800-7PWF2L6SMG5SA0A0VZ5B/BRAZ+RIPPLE.jpg'], [
            lengthColours('20"', ['DARKRED', 'GREY', 'P1B/30', 'P1B/33', 'T1B/33', 'T1B/99J'], '70g'),
        ], 'Ripple wave braid style.'),
        family('Mambo Born Locs', $type, 'https://www.sleek.co.uk/mambo-born-locs', 'MAMBOBL', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/4eb52d74-db55-4299-af71-5c103a79b21e/MAMBO+BORN+LOCS+2.jpg'], [
            lengthColours('24"', ['1', '1B', '2', '27', '30', '4', '613', 'TT1B/30', 'TT1B/BURG', '3T4/27/613'], null),
        ], '2 bundle pack of small curly locs.'),
        family('Boho Coily Braid', $type, 'https://www.sleek.co.uk/boho-coily-braid', 'BOHOCB22', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/64205d68-fcd5-4e8b-b6ad-c62f483cd129/BOHOCB2201.jpg'], [
            lengthColours('22"', ['1', '1B', '2', '4', '27', '30', '350', 'T1B/27', 'T433/27', 'T18/60', 'T4/30/27', 'P12/16/613', 'T1B/DARK RED', '613'], '100g'),
        ], 'Boho coily braid style.'),
        family('Boho Satin Braid', $type, 'https://www.sleek.co.uk/boho-satin-braid', 'BOHOS', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/4735969b-d786-42a5-8ebf-80c97aea8cb7/BEST+SELLER+%2826%29.jpg'], [
            lengthColours('20"', ['1', '1B', '2', '4', 'T1B/27', 'T1B/30', 'T1B/33'], '85g'),
        ], 'Braided or twist-out spiral crimps in a satin texture. 2 in 1 style.'),
        family('Mambo Satin Twist', $type, 'https://www.sleek.co.uk/mambo-satin-twist-22', 'MAMBOS22', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583529260435-J2LM4U1DC9CO68BV4FVX/MAMBO+SATIN+22.jpg'], [
            lengthColours('22"', ['1', '1B', '2', '4', 'GREY', 'P1B/33'], '110g'),
        ], 'Mambo Satin Twist style. The range page also shows a 12" item, but the linked source currently repeats the 22" variant data; 12" variants remain pending review.'),
        family('Cuba Bounce Curl', $type, 'https://www.sleek.co.uk/cuba-bounce-marley', 'CUBABC', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583531738543-HRGRQB5WTN99ME5MW1WM/CUBA+BOUNCE.jpg'], [
            lengthColours('12"', ['1', '1B', '2', '4', 'DARK RED', 'GREY', 'P1B/33', 'T1B/27', 'T1B/30', 'T1B/33', 'P1B/30'], '85g'),
        ], 'Lightweight curl and bounce style.'),
        family('Brazilian Deep Braid', $type, 'https://www.sleek.co.uk/brazilian-deep-braid', 'DEEPB', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583533187651-DOUJC4BX2L3T800KYO0H/BRAZ+DEEP+BRAID.jpg'], [
            lengthColours('20"', ['1', '1B', '2', '4', 'T1B/27', 'T1B/30', 'T1B/99J', 'DARK RED', 'GREY', 'P1B/30', 'P1B/33', 'T1B/33'], '70g'),
        ], 'Deep S curl pattern braid.'),
        family('Boho Deep Braid', $type, 'https://www.sleek.co.uk/bohodeepbraid', 'BOHOD', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/8fbad580-9447-4ba7-a608-b4a7b2ff5cd2/BOHO+DEEP+BRAID.jpg'], [
            lengthColours('22"', ['1', '1B', '2', '4', '613', 'P12/16/613', 'T10/14', 'T18/56', 'T18/60', 'T433/27', 'TT1B/30', 'TT2/145', 'TT4/27'], null),
        ], 'Boho Deep Braid style.'),
        family('Boho Beach Curl Braid', $type, 'https://www.sleek.co.uk/bohobcb22', 'BOHOBCB22', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/6a462e77-8e43-4c5e-bf4c-26125becf24e/BOHOCB2202.jpg'], [
            lengthColours('22"', ['1', '1B', '2', '4', '27', '30', '350', 'T1B/27', 'T433/27', 'T18/60', 'P12/16/613', 'T1B/DARK RED', '613'], '100g'),
        ], 'Boho beach curl braid style.'),
        family('Jamaica Dredlock', $type, 'https://www.sleek.co.uk/jamaica-dredlock', 'JAMAICAD', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/05e6af35-f8ff-4ecb-a029-b4f401e9cc1c/BEST+SELLER+%2827%29+%281%29.jpg'], [
            lengthColours('22"', ['1', '1B', '2', '4', 'GREY', 'T1B/27', 'T1B/30', 'T1B/33'], '85g'),
        ], 'Synthetic hair with a dredlock feel.'),
        family('Mambo Box Braid', $type, 'https://www.sleek.co.uk/mambo-box-braid', 'MAMBOB', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1585901327208-6CKF4PI1SWUAJIT35A3R/mambo+box+braid.jpg'], [
            lengthColours('20"', ['P1B/30', 'T1B/33'], '85g'),
        ], 'Classic pre-braided box braid style. 2 in 1 style.'),
        family('Jamaica Faux Locs', $type, 'https://www.sleek.co.uk/jamaica-faux-locs', 'JAMAICAFAU', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583530990604-OBDBUV0EHCB3VULAOELS/JAM+FAUX+LOCS.jpg'], [
            lengthColours('18"', ['1', '1B', '2', '27', '4', 'DARK PURPLE', 'DARK RED'], '65g'),
        ], 'Faux locs for volume and texture.'),
        family('Sistrlocks Twist', $type, 'https://www.sleek.co.uk/sisterlocks', 'SISTERL', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583531321892-5EOB5SYCQQ8GMLBC2NTC/JAM+FAUX+LOCS.jpg'], [
            lengthColours('14"', ['1', '1B', '2', '4', 'T1B/33'], '85g'),
        ], 'Sistrlocks twist style.'),
        family('Kinky Twist Marley', $type, 'https://www.sleek.co.uk/kinky-twist-marley', 'KINKYTWI', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583532497167-IM7FA0GUR3AA3JHXGXC1/KINKY+TWIST+MARLEY.jpg'], [
            lengthColours('10"', ['1', '1B', '2', '4', 'DARK GREEN', 'DARK RED', 'T1B/27', 'T1B/30', 'T1B/33', 'T1B/DARK RED'], '90g'),
        ], 'Super-tight kinky Marley texture.'),
        family('Brazilian Salsa Braid', $type, 'https://www.sleek.co.uk/brazilian-salsa-braid', 'SALSB', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583533608835-WL6XCW14LF775WGSM0FG/BRAZ+DEEP+BRAID.jpg'], [
            lengthColours('20"', ['DARK RED', 'GREY', 'P1B/30', 'P1B/31', 'T1B/99J'], '70g'),
        ], 'S curl wave with braided style.'),
        family('French Curl Braid', $type, 'https://www.sleek.co.uk/french-curl', 'FRENCH', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/3476a4fd-6156-45d2-a749-e927f6e21ca4/BEST+SELLER+%2818%29.png'], [
            lengthColours('28"', ['1', '1B', '2', '4', '613', '27', '30', '350', '530', 'COPPER', 'T-BRONZE47', 'T27', 'T30', 'T27/30', 'T27/613'], null),
            lengthColours('12"', ['1', '1B', '2', '4', '613', '27', '30', '350', '530', 'COPPER', 'T-BRONZE47', 'T27', 'T30', 'T27/30', 'T27/613'], null),
        ], 'Extra-long bouncy curls for knotless braiding and crochet braids.'),
        family('Brazilian Water Braid', $type, 'https://www.sleek.co.uk/brazilian-water-braid', 'WATB', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1583533829271-GF1V2OFMOPXS0PSIYY0A/BRAZ+WATER+BRAID.jpg'], [
            lengthColours('20"', ['DARKRED', 'GREY', 'P1B/33', 'T1B/33', 'T1B/99J'], '70g'),
        ], 'Defined curls for volume.'),
        family('Boho Body Braid', $type, 'https://www.sleek.co.uk/boho-body-braid-22', 'BOHOBB22', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/ec9c743c-2e29-48b9-8256-db19d57ae77a/BOHOBB22.jpg'], [
            lengthOnly('22"', '100g'),
        ], 'Source page lists length and weight but no visible colours; colour variants pending review.'),
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
 * @param array<int, string> $colours
 * @return array<int, array<string, string|null>>
 */
function lengthColours(string $length, array $colours, ?string $weight): array
{
    return collect($colours)
        ->map(fn (string $colour): array => [
            'Length' => $length,
            'Colour' => normaliseColour($colour),
            'weight' => $weight,
        ])
        ->all();
}

/**
 * @return array<int, array<string, string|null>>
 */
function lengthOnly(string $length, ?string $weight): array
{
    return [[
        'Length' => $length,
        'weight' => $weight,
    ]];
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
        'note' => mergeNote($productType->note, 'Structured from official Sleek Fashion Idol Express pages.'),
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
    $note = "Family/style imported from the official Sleek Fashion Idol Express product page. Order code {$family['code']}. {$family['description']}";

    return $note;
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
                'source_label' => 'Sleek official Fashion Idol Express product page',
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
    if ($records->isEmpty()) {
        return [0, 0];
    }

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
    $parts = ["Official Sleek Fashion Idol Express product page lists this SKU. Order code {$family['code']}."];

    if (($record['weight'] ?? null) !== null) {
        $parts[] = 'Weight: '.$record['weight'].'.';
    }

    if ($family['name'] === 'Boho Body Braid') {
        $parts[] = 'Colour variants were not visible on the source page.';
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
    return cleanSpaces(Str::upper(str_replace(['、', 'T1B/ 30'], [',', 'T1B/30'], $colour)));
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
