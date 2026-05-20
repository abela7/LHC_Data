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

$sourceUrl = 'https://www.sleek.co.uk/style-icon';
$records = collect(styleIconRecords());
$groups = $records->groupBy('length');

if ($dryRun) {
    echo "Sleek Style Icon official page dry run.\n";
    echo "Master brand: Sleek\n";
    echo "Line: Style Icon\n";
    echo "Product type: Human Hair Weave\n";
    echo "Family: Style Icon Remy Silky Weave\n";
    echo "Material: 100% Remy Hair\n";
    echo "Weight: 113 g\n";
    echo 'SKU variants: '.$records->count()."\n\n";

    $groups
        ->sortKeysUsing(fn (string $a, string $b): int => lengthNumber($a) <=> lengthNumber($b))
        ->each(function (Collection $lengthRecords, string $length): void {
            echo "{$length}: ".$lengthRecords->count().' colours'."\n";
            echo '  '.$lengthRecords->pluck('colour')->implode(', ')."\n";
        });

    exit(0);
}

$summary = DB::transaction(function () use ($records, $sourceUrl): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();
    $brand = findOrCreateSleekBrand($catalogue);

    $brand->fill([
        'name' => 'Sleek',
        'slug' => uniqueBrandSlug($catalogue, 'sleek', $brand->id),
        'url' => 'https://www.sleek.co.uk/',
        'note' => mergeNote($brand->note, 'Master brand for official Sleek hair ranges. Reference structures are imported from official Sleek range pages and must be stock-checked before publishing retail products.'),
        'is_active' => true,
    ])->save();

    renameDefaultSleekLine($brand);

    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', 'Style Icon')
        ->first();

    if (! $line) {
        $line = new BrandCatalogueLine([
            'brand_catalogue_brand_id' => $brand->id,
            'name' => 'Style Icon',
            'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), 'Style Icon'),
        ]);
    }

    $line->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'name' => 'Style Icon',
        'note' => mergeNote($line->note, 'Style Icon is treated as a sub-brand/line under the Sleek master brand.'),
        'url' => $sourceUrl,
        'is_default' => false,
        'is_active' => true,
        'sort_order' => $line->exists ? $line->sort_order : 220,
    ])->save();

    $productType = BrandCatalogueProductType::query()->firstOrCreate(
        [
            'brand_catalogue_line_id' => $line->id,
            'name' => 'Human Hair Weave',
        ],
        [
            'brand_catalogue_brand_id' => $brand->id,
            'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), 'Human Hair Weave'),
            'is_active' => true,
            'sort_order' => 10,
        ],
    );

    $productType->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'note' => mergeNote($productType->note, 'Official Sleek Style Icon page categorises this as a human hair weave product.'),
        'url' => $sourceUrl,
        'is_active' => true,
        'sort_order' => 10,
    ])->save();

    $style = BrandCatalogueStyle::query()
        ->where('brand_catalogue_product_type_id', $productType->id)
        ->where('name', 'Style Icon Remy Silky Weave')
        ->first();

    if (! $style) {
        $style = new BrandCatalogueStyle([
            'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), 'Style Icon Remy Silky Weave'),
        ]);
    }

    $style->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_product_type_id' => $productType->id,
        'brand_catalogue_material_id' => null,
        'material_name' => '100% Remy Hair',
        'name' => 'Style Icon Remy Silky Weave',
        'note' => mergeNote($style->note, 'Official page lists weight as 113 g and describes the product as 100% Remy Hair. Length/colour variants imported from the official Style Icon page. 12" is explicitly noted as low stock in colours 1 and 2.'),
        'url' => $sourceUrl,
        'is_active' => true,
        'sort_order' => $style->exists ? $style->sort_order : 10,
    ])->save();

    syncStyleImages($style);
    [$created, $updated] = syncVariantsAndSkus($style, $records, $sourceUrl);

    return [
        'brand_id' => $brand->id,
        'line_id' => $line->id,
        'product_type_id' => $productType->id,
        'style_id' => $style->id,
        'length_groups' => $records->pluck('length')->unique()->count(),
        'source_skus' => $records->count(),
        'skus_created' => $created,
        'skus_updated' => $updated,
        'retail_products_created' => 0,
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
    ];
});

echo "Sleek Style Icon official structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return array<int, array{length:string,colour:string,note:string}>
 */
function styleIconRecords(): array
{
    $lengthColours = [
        '12"' => ['1', '2'],
        '14"' => ['2', '4', '5', '6', '613', 'P12/16/613', 'P18/613', 'P24/613', 'P27/613'],
        '16"' => ['1', '1B', '2', '4', '5', '6', '613', 'ASH BLONDE', 'P12/16/613', 'P18/613', 'P24/613', 'P27/613'],
        '18"' => ['1', '1B', '2', '27', '30', '4', '5', '6', '613', 'ASH BLONDE', 'P12/16/613', 'P24/613', 'P27/613', 'P4/27'],
        '20"' => ['1', '1B', '2', '30', '33', '4', '6', '613', 'ASH BLONDE', 'P10/16', 'P12/16/613', 'P27/613'],
        '22"' => ['1', '1B', '2', '4', '6', 'P24/613'],
        '24"' => ['1', '1B', '6', 'P27/613'],
    ];

    $records = [];

    foreach ($lengthColours as $length => $colours) {
        foreach ($colours as $colour) {
            $records[] = [
                'length' => $length,
                'colour' => $colour,
                'note' => $length === '12"' ? 'Official page notes low stock for 12" in colours 1 and 2.' : '',
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

function renameDefaultSleekLine(BrandCatalogueBrand $brand): void
{
    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('is_default', true)
        ->first();

    if (! $line) {
        BrandCatalogueLine::query()->create([
            'brand_catalogue_brand_id' => $brand->id,
            'name' => 'Sleek',
            'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), 'Sleek'),
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return;
    }

    if ($line->name === 'Sleek Hair') {
        $line->fill([
            'name' => 'Sleek',
            'slug' => scopedSlugExcluding(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), 'Sleek', $line->id),
            'is_active' => true,
        ])->save();
    }
}

function syncStyleImages(BrandCatalogueStyle $style): void
{
    $images = [
        [
            'url' => 'https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1581589715336-SKMSFOJQS2QYXRVD3MXB/STYLE+ICON+REMY+SILKY.JPG',
            'notes' => 'Official Sleek Style Icon Remy Silky Weave product image.',
        ],
        [
            'url' => 'https://images.squarespace-cdn.com/content/v1/5e318b368ea3601e861ea95d/1587589622104-H11KRLYR2R3Y74E6XWDG/style+icon+line.jpg',
            'notes' => 'Official Sleek Style Icon line image.',
        ],
    ];

    $hasPrimary = CatalogueImage::query()
        ->where('imageable_type', BrandCatalogueStyle::class)
        ->where('imageable_id', $style->id)
        ->where('is_primary', true)
        ->exists();

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
                'is_primary' => ! $hasPrimary && $index === 0,
                'source_label' => 'Sleek official Style Icon page',
                'usage_context' => 'all',
                'notes' => $image['notes'],
            ],
        );
    }

    if (! CatalogueImage::query()
        ->where('imageable_type', BrandCatalogueStyle::class)
        ->where('imageable_id', $style->id)
        ->where('is_primary', true)
        ->exists()) {
        CatalogueImage::query()
            ->where('imageable_type', BrandCatalogueStyle::class)
            ->where('imageable_id', $style->id)
            ->where('external_url', $images[0]['url'])
            ->update(['is_primary' => true]);
    }
}

/**
 * @param Collection<int, array{length:string,colour:string,note:string}> $records
 * @return array{0:int,1:int}
 */
function syncVariantsAndSkus(BrandCatalogueStyle $style, Collection $records, string $sourceUrl): array
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

    $lengthOptions = syncOptions(
        $lengthVariant,
        $records
            ->pluck('length')
            ->unique()
            ->sortBy(fn (string $length): int => lengthNumber($length))
            ->values()
            ->all(),
    );

    $colourOptions = syncOptions(
        $colourVariant,
        $records
            ->pluck('colour')
            ->unique()
            ->sortBy(fn (string $colour): string => colourSortKey($colour))
            ->values()
            ->all(),
    );

    $created = 0;
    $updated = 0;

    foreach ($records as $index => $record) {
        $variants = [
            'Length' => $record['length'],
            'Colour' => $record['colour'],
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

        $note = 'Official Sleek Style Icon page lists this length/colour combination. Weight: 113 g.';
        if ($record['note'] !== '') {
            $note .= ' '.$record['note'];
        }

        $sku->fill([
            'name' => $name,
            'sku_code' => $sku->sku_code,
            'barcode' => $sku->barcode,
            'description' => $sku->description,
            'note' => mergeNote($sku->note, $note),
            'url' => $sourceUrl,
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
                'brand_catalogue_variant_option_id' => $lengthOptions[$record['length']],
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'brand_catalogue_sku_id' => $sku->id,
                'brand_catalogue_variant_id' => $colourVariant->id,
                'brand_catalogue_variant_option_id' => $colourOptions[$record['colour']],
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
    return 'Style Icon Remy Silky Weave - '.$variants['Length'].' - Colour '.$variants['Colour'];
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
    return scopedSlugExcluding($query, $name);
}

function scopedSlugExcluding($query, string $name, ?int $exceptId = null): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $suffix = 2;

    while (
        (clone $query)
            ->where('slug', $slug)
            ->when($exceptId, fn ($innerQuery) => $innerQuery->where('id', '!=', $exceptId))
            ->exists()
    ) {
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
