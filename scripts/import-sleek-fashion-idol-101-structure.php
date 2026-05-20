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

$sourceUrl = 'https://www.sleek.co.uk/fashion-idol-101-1';
$families = collect(fashionIdolFamilies());
$skuCount = $families->sum(fn (array $family): int => count($family['skus']));

if ($dryRun) {
    echo "Sleek Fashion Idol 101 official product pages dry run.\n";
    echo "Master brand: Sleek\n";
    echo "Line: Fashion Idol 101\n";
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

    $line = findOrCreateLine($brand, 'Fashion Idol 101', $sourceUrl, 280);

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
            'material_name' => '101 Synthetic Fibre',
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

echo "Sleek Fashion Idol 101 official structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return array<int, array<string, mixed>>
 */
function fashionIdolFamilies(): array
{
    return [
        family('Hot Yaki Weave', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/hot-yaki-weave', 'HOTYK', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/ac4eea20-8070-43ac-afb8-814f8c7aa7df/BEST+SELLER+%2831%29.jpg'], [
            lengthColours('10"', ['1', '1B', '2', '4'], '100g', '100"'),
            lengthColours('12"', ['1', '1B', '2', '4', 'P1B/30'], '100g', '75"'),
            lengthColours('14"', ['1', '1B', '2', '27', '4', '613', 'P4/27'], '100g', '70"'),
            lengthColours('16"', ['1', '1B', '2', '4', '613', '99J', 'TT1B/30', 'TT4/27'], '100g', '64"'),
            lengthColours('18"', ['1', '1B', '2', '27', '4', '6', '613', '99J', 'BLUE', 'FIRE RED', 'P10/16', 'P12/16/613', 'P16/613', 'P18/613', 'P24/613', 'P27/613', 'P4/27', 'PINK', 'RT1B/GREEN', 'RT1B/PALEBLUE', 'RT1B/PINK', 'RT1B/PURPLE', 'RT1B/ROCKGREY', 'SILVER', 'TT1B/30', 'TT1B/33', 'TT1BAQUA', 'TT1B/PURPLE', 'TT4/27/613', 'TTGREY/PINK'], '100g', '52"'),
            lengthColours('20"', ['1', '1B', '2', '27', '4', '613', 'P16/613', 'P27/613', 'P4/27', 'RT1B/GREEN', 'RT1B/PINK', 'RT1B/PURPLE', 'RT1B/ROCKGREY', 'TT1B/30', 'TT4/27/613', 'TTGREY/PINK'], '100g', '50"'),
            lengthColours('22"', ['1', '1B', '2', '27', '4', '6', '613', 'P12/16/613', 'P27/613'], null, null),
        ], 'Natural Yaki texture in a straight synthetic weave.'),
        family('Clip In Fringe', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/clip-in-fringe', 'CLIPFR', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1585585903501-SY7J042TLHDAZQ3LZ6GP/CLIP+IN+FRINGE.jpg'], [
            colourOnly(['1', '1B', '2']),
        ], 'Full body face-framing fringe piece.'),
        family('Dazzle Weave', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/dazzle-weave', 'DAZZW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1585586766506-A2BLFIA1S3O6HAKS7RA6/DAZZLEE.jpg'], [
            lengthColours('18"', ['1', '4'], '100g', null),
            lengthColours('20"', ['4'], '100g', '20" x 2'),
        ], 'Eye-catching waves with finger-combed volume.'),
        family('Nubian Weave', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/nubian-weave', 'NUBW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1585587619407-RGL71LOHYUB7RIW4107M/image-asset.jpeg'], [
            lengthColours('18"', ['1', 'B', '2', '4'], '100g', '60"'),
        ], 'Super-thin spiral curls similar to wet and wavy human hair styles.'),
        family('Hot Braid', 'Braiding Hair', 'https://www.sleek.co.uk/hot-braid', 'HOTB', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/64493d0e-144a-4754-8215-fd953403af5a/BEST+SELLER+%2815%29.jpg'], [
            lengthColours('30"', ['1', '1B', '2', '4', '6', '27', '30', '33', '613', '99J', 'AQUA', 'DARKRED', 'GREY', 'PURPLE', 'ROUGE', 'TT1B/30', 'TT1B/AQUA'], '100g', null),
        ], 'Lightweight synthetic 101 braiding hair for plaiting and hot styling.'),
        family('Hot EW 5 Pcs Clip Ins', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/hot-ew-5pc', 'HEW5', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/be0d9839-9b95-443a-86e5-27cef8db18f2/HOT-EW-1-PC-CLIP.jpg'], [
            lengthColours('18"', ['1', '10', '1B', '2', '27', '4', '5', '6', '613', '8', 'P10/16', 'P10/18', 'P10/24/613', 'P12/16/613', 'P16/613', 'P18/22/613', 'P18/613', 'P22/613', 'P24/613', 'P27/613', 'P4/27', 'P6/27', 'SILVER'], '125g', '10.5" x 2, 8.5", 8", 5.5"'),
        ], 'Five-piece silky straight clip-in extensions in European textured hair.'),
        family('Classy Weave', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/classy-weave', 'CLAW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1585586560675-OQE70EAR7T6YAFA66Z64/CLAW.jpg'], [
            lengthColours('16"', ['1', '1B', '4', 'P1B/33'], '100g', null),
        ], 'Loose curl pattern. Source notes this item is discontinued with limited stock availability.'),
        family('Kenya Natural Weave', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/kenya-natural-weave', 'KENYANW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1585586968970-RI87N5L8FGHLJGKHKV0T/KENYA+NAT.jpg'], [
            lengthColours('14"', ['1', '1B', '2', 'TT1B/27'], '100g', '21" x 4'),
            lengthColours('18"', ['1', '1B', '2', '4', 'TT1B/27'], '100g', null),
        ], 'Loose spiral curl pattern in a layered shoulder-length style.'),
        family('Peru Natural Weave', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/peru-natural-weave', 'PERUNW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1585587673028-38JRYRK3XO0KVVHBRZNT/peru.jpg'], [
            lengthColours('18"', ['1', '1B', '4', 'TT1B27'], '100g', '80"'),
        ], 'Full-body natural-looking curl pattern.'),
        family('Closure Piece', 'Closure', 'https://www.sleek.co.uk/101-closure-new', 'SYNCLOSURE', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1585585844012-70O8HB5F7GH8DG4JO8D9/101+SYN+CLOSURE.jpg'], [
            lengthColours('16"', ['1', '1B', '2', '4', '6', '613'], null, null),
        ], 'Synthetic 101 closure made from premium tongable fibre; 4" x 2" closure.'),
        family('Cutie Weave', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/cutie-weave', 'CUTE', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1585586890815-HIAWB21CZRPFDKKZDD9B/CUTIE.jpg'], [
            lengthColours('10"', ['1', '1B', '2', '4', '30', 'P1B/30', 'RED WINE'], '100g', '56" x 2'),
        ], 'Tiny corkscrew curls with volume and body.'),
        family('Kinky Natural 2pcs', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/kinky-natural-2pcs', 'KINKYNA', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1585587149337-SBRGVL6QLY2HXZC0NFK1/KINKYNA.jpg'], [
            lengthColours('14"', ['1B', '2'], '100g', '21" x 4'),
            lengthColours('18"', ['1B', '2'], '100g', null),
        ], 'Loose wavy natural textured style in two pieces.'),
        family('Rio Natural Weave', 'Synthetic Hair Weave', 'https://www.sleek.co.uk/rio-natural-weave', 'RIONW', ['https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1585587720889-4GA7FN0QKKRV9T0PCPT4/image-asset.jpeg'], [
            lengthColours('18"', ['1', '1B', '2', '4', 'TT1B/27', 'TT1B/30', 'TT1B/DARKRED'], '100g', '92"'),
            lengthColours('20"', ['TT1B/27', 'TT1B/30', 'TT1B/33'], '100g', '84"'),
            lengthColours('22"', ['99J', 'F1B/33', 'F1B/99J', 'F4/30', 'TT1B/DARK', 'F4/27', 'F12/16/613'], '100g', null),
        ], 'Loose wavy hair for volume and length.'),
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
function lengthColours(string $length, array $colours, ?string $weight, ?string $weftWidth): array
{
    return collect($colours)
        ->map(fn (string $colour): array => [
            'Length' => $length,
            'Colour' => normaliseColour($colour),
            'weight' => $weight,
            'weft_width' => $weftWidth,
        ])
        ->all();
}

/**
 * @param array<int, string> $colours
 * @return array<int, array<string, string|null>>
 */
function colourOnly(array $colours): array
{
    return collect($colours)
        ->map(fn (string $colour): array => [
            'Colour' => normaliseColour($colour),
            'weight' => null,
            'weft_width' => null,
        ])
        ->all();
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
        'note' => mergeNote($productType->note, 'Structured from official Sleek Fashion Idol 101 pages.'),
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
    $note = "Family/style imported from the official Sleek Fashion Idol 101 product page. Order code {$family['code']}. {$family['description']}";

    if ($family['name'] === 'Nubian Weave') {
        $note .= ' The source page lists colour code "B" exactly; verify in shop if this should be 1B.';
    }

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
                'source_label' => 'Sleek official Fashion Idol 101 product page',
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
    $parts = ["Official Sleek Fashion Idol 101 product page lists this SKU. Order code {$family['code']}."];

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
    return cleanSpaces(Str::upper(str_replace('TT4/ 27/613', 'TT4/27/613', $colour)));
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
