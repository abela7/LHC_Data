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

$sourceUrl = 'https://www.sleek.co.uk/remy-couture';
$silkyWeaveRecords = collect([
    ['length' => '14"'],
    ['length' => '16"'],
    ['length' => '18"'],
    ['length' => '20"'],
]);

$reviewOnlyFamilies = [
    [
        'product_type' => 'Pre-Bonded & Flat Hair',
        'name' => 'Remy Couture Pre-Bonded Stick Tip',
        'pack_size' => '25 pcs',
        'image_urls' => [
            'https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1581717431990-K7UZ1YHPNPQK68KDLLNM/STICK+TIPPED.jpg',
        ],
    ],
    [
        'product_type' => 'Pre-Bonded & Flat Hair',
        'name' => 'Remy Couture Pre-Bonded Nail Tip',
        'pack_size' => '25 pcs',
        'image_urls' => [],
    ],
    [
        'product_type' => 'Pre-Bonded & Flat Hair',
        'name' => 'Remy Couture Flat Hair',
        'pack_size' => '25 pcs',
        'image_urls' => [],
    ],
];

if ($dryRun) {
    echo "Sleek Remy Couture official page dry run.\n";
    echo "Master brand: Sleek\n";
    echo "Line: Remy Couture\n";
    echo "Confirmed family: Remy Couture Silky Weave\n";
    echo "Material: 100% Remy Indian Hair\n";
    echo "Weight: 113 g\n";
    echo "Code: RCSW\n";
    echo 'Length SKUs: '.$silkyWeaveRecords->count().' (14", 16", 18", 20")'."\n";
    echo 'Review-only families: '.count($reviewOnlyFamilies)." (colours/stock not listed on source page)\n";

    exit(0);
}

$summary = DB::transaction(function () use ($silkyWeaveRecords, $reviewOnlyFamilies, $sourceUrl): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();
    $brand = findOrCreateSleekBrand($catalogue);

    $brand->fill([
        'name' => 'Sleek',
        'slug' => uniqueBrandSlug($catalogue, 'sleek', $brand->id),
        'url' => 'https://www.sleek.co.uk/',
        'note' => mergeNote($brand->note, 'Master brand for official Sleek hair ranges. Reference structures are imported from official Sleek range pages and must be stock-checked before publishing retail products.'),
        'is_active' => true,
    ])->save();

    $line = findOrCreateLine($brand, 'Remy Couture', $sourceUrl, 230);
    $humanHairWeave = findOrCreateProductType($brand, $line, 'Human Hair Weave', $sourceUrl, 10);
    $preBonded = findOrCreateProductType($brand, $line, 'Pre-Bonded & Flat Hair', $sourceUrl, 20);

    $silkyWeave = findOrCreateStyle(
        $brand,
        $humanHairWeave,
        'Remy Couture Silky Weave',
        '100% Remy Indian Hair',
        'Official Sleek Remy Couture page lists this family with code RCSW, weight 113 g, and lengths 14", 16", 18", 20". Colour variants are not listed on the source page, so only visible length variants were imported.',
        $sourceUrl,
        10,
    );

    syncStyleImages($silkyWeave, [
        [
            'url' => 'https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1581717317410-GPUV0YEY36ONQMPVG0BW/RC.jpg',
            'notes' => 'Official Remy Couture Silky Weave image.',
        ],
        [
            'url' => 'https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1581717341068-4LFS2P4HNBO0K09LQRSO/RC1.jpg',
            'notes' => 'Official Remy Couture Silky Weave image.',
        ],
    ]);

    [$created, $updated] = syncLengthSkus($silkyWeave, $silkyWeaveRecords, $sourceUrl);

    $reviewStyleIds = [];
    foreach ($reviewOnlyFamilies as $index => $family) {
        $style = findOrCreateStyle(
            $brand,
            $preBonded,
            $family['name'],
            '100% Remy Indian Hair',
            'Official Sleek Remy Couture page identifies this clearance family as '.$family['pack_size'].'. Exact colours and stock are not listed, so variants are pending shop or supplier review.',
            $sourceUrl,
            100 + ($index * 10),
        );

        syncStyleImages(
            $style,
            collect($family['image_urls'])
                ->map(fn (string $url): array => [
                    'url' => $url,
                    'notes' => 'Official Remy Couture image for '.$family['name'].'.',
                ])
                ->all(),
        );

        $reviewStyleIds[] = $style->id;
    }

    return [
        'brand_id' => $brand->id,
        'line_id' => $line->id,
        'human_hair_weave_product_type_id' => $humanHairWeave->id,
        'pre_bonded_product_type_id' => $preBonded->id,
        'silky_weave_style_id' => $silkyWeave->id,
        'source_skus' => $silkyWeaveRecords->count(),
        'skus_created' => $created,
        'skus_updated' => $updated,
        'review_only_styles' => count($reviewStyleIds),
        'retail_products_created' => 0,
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
        'family_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}/lines/{$line->id}/product-types/{$humanHairWeave->id}/styles/{$silkyWeave->id}"),
    ];
});

echo "Sleek Remy Couture official structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
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
        'note' => mergeNote($productType->note, "Structured from the official Sleek Remy Couture page."),
        'url' => $url,
        'is_active' => true,
        'sort_order' => $sortOrder,
    ])->save();

    return $productType;
}

function findOrCreateStyle(
    BrandCatalogueBrand $brand,
    BrandCatalogueProductType $productType,
    string $name,
    string $materialName,
    string $note,
    string $url,
    int $sortOrder,
): BrandCatalogueStyle {
    $style = BrandCatalogueStyle::query()
        ->where('brand_catalogue_product_type_id', $productType->id)
        ->where('name', $name)
        ->first();

    if (! $style) {
        $style = new BrandCatalogueStyle([
            'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $name),
        ]);
    }

    $style->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_product_type_id' => $productType->id,
        'brand_catalogue_material_id' => null,
        'material_name' => $materialName,
        'name' => $name,
        'note' => mergeNote($style->note, $note),
        'url' => $url,
        'is_active' => true,
        'sort_order' => $style->exists ? $style->sort_order : $sortOrder,
    ])->save();

    return $style;
}

/**
 * @param array<int, array{url:string,notes:string}> $images
 */
function syncStyleImages(BrandCatalogueStyle $style, array $images): void
{
    if ($images === []) {
        return;
    }

    foreach ($images as $index => $image) {
        CatalogueImage::query()->updateOrCreate(
            [
                'imageable_type' => BrandCatalogueStyle::class,
                'imageable_id' => $style->id,
                'external_url' => $image['url'],
            ],
            [
                'image_role' => 'source_image',
                'storage_disk' => null,
                'storage_path' => null,
                'original_filename' => basename(parse_url($image['url'], PHP_URL_PATH) ?: ''),
                'mime_type' => null,
                'file_size' => null,
                'sort_order' => $index * 10,
                'is_primary' => $index === 0,
                'source_label' => 'Sleek official Remy Couture page',
                'usage_context' => 'all',
                'notes' => $image['notes'],
            ],
        );
    }
}

/**
 * @param Collection<int, array{length:string}> $records
 * @return array{0:int,1:int}
 */
function syncLengthSkus(BrandCatalogueStyle $style, Collection $records, string $sourceUrl): array
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

    $lengthOptions = syncOptions(
        $lengthVariant,
        $records
            ->pluck('length')
            ->unique()
            ->sortBy(fn (string $length): int => lengthNumber($length))
            ->values()
            ->all(),
    );

    $created = 0;
    $updated = 0;

    foreach ($records as $index => $record) {
        $variants = ['Length' => $record['length']];
        $signature = optionSignature($variants);
        $name = 'Remy Couture Silky Weave - '.$record['length'];

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
            'note' => mergeNote($sku->note, 'Official Sleek Remy Couture page lists this length for Remy Couture Silky Weave. Source code RCSW; weight 113 g. Colour variants are not listed on the page.'),
            'url' => $sourceUrl,
            'is_active' => true,
            'sort_order' => $sku->exists ? $sku->sort_order : $index * 10,
        ])->save();

        DB::table('brand_catalogue_sku_variant_options')
            ->where('brand_catalogue_sku_id', $sku->id)
            ->delete();

        DB::table('brand_catalogue_sku_variant_options')->insert([
            'brand_catalogue_sku_id' => $sku->id,
            'brand_catalogue_variant_id' => $lengthVariant->id,
            'brand_catalogue_variant_option_id' => $lengthOptions[$record['length']],
            'created_at' => now(),
            'updated_at' => now(),
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

function lengthNumber(string $length): int
{
    if (preg_match('/\d+/', $length, $match)) {
        return (int) $match[0];
    }

    return 0;
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
