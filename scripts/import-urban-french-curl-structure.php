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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dryRun = in_array('--dry-run', $argv, true);

$source = urbanFrenchCurlSource();
$skus = skuRecords($source);

if ($dryRun) {
    echo "Urban French Curl dry run.\n";
    echo "Brand: Urban\n";
    echo "Line: Urban\n";
    echo "Product type: {$source['product_type']}\n";
    echo "Style: {$source['style_name']}\n";
    echo 'SKU variants: '.count($skus)."\n";
    echo 'Colours: '.implode(', ', $source['colours'])."\n";
    echo "Source: {$source['url']}\n";

    exit(0);
}

$summary = DB::transaction(function () use ($source, $skus): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();

    $brand = BrandCatalogueBrand::query()->firstOrCreate(
        [
            'brand_catalogue_id' => $catalogue->id,
            'name' => 'Urban',
        ],
        [
            'slug' => scopedSlug(BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id), 'Urban'),
            'is_active' => true,
            'sort_order' => 0,
        ],
    );

    $brand->fill([
        'url' => 'https://feme.com/brands/urban/urban/',
        'note' => mergeNote($brand->note, 'Urban structure started from Feme Urban source. Only French Curl has been added for now because this is the confirmed shop focus.'),
        'is_active' => true,
    ])->save();

    $line = BrandCatalogueLine::query()->firstOrCreate(
        [
            'brand_catalogue_brand_id' => $brand->id,
            'name' => 'Urban',
        ],
        [
            'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), 'Urban'),
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 10,
        ],
    );

    $line->fill([
        'url' => 'https://feme.com/brands/urban/urban/',
        'note' => mergeNote($line->note, 'Urban brand line. Only French Curl is active in this catalogue at this stage.'),
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 10,
    ])->save();

    $productType = BrandCatalogueProductType::query()->firstOrCreate(
        [
            'brand_catalogue_line_id' => $line->id,
            'name' => $source['product_type'],
        ],
        [
            'brand_catalogue_brand_id' => $brand->id,
            'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $source['product_type']),
            'is_active' => true,
            'sort_order' => 10,
        ],
    );

    $productType->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'url' => $source['url'],
        'note' => mergeNote($productType->note, 'Synthetic braids/bulk/crochet product type from the Urban French Curl source page.'),
        'is_active' => true,
        'sort_order' => 10,
    ])->save();

    $style = BrandCatalogueStyle::query()
        ->where('brand_catalogue_product_type_id', $productType->id)
        ->where('name', $source['style_name'])
        ->first();

    if (! $style) {
        $style = new BrandCatalogueStyle([
            'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $source['style_name']),
        ]);
    }

    $style->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_product_type_id' => $productType->id,
        'brand_catalogue_material_id' => null,
        'material_name' => 'Synthetic Fibre',
        'name' => $source['style_name'],
        'url' => $source['url'],
        'note' => mergeNote($style->note, 'Official source lists SKU USBFC, length 30", synthetic fibre, approx. 129g packet weight, braiding/crochet application, and colours: '.implode(', ', $source['colours']).'.'),
        'is_active' => true,
        'sort_order' => 10,
    ])->save();

    syncStyleImages($style, $source);
    [$createdSkus, $updatedSkus] = syncVariantsAndSkus($style, $skus, $source);

    return [
        'brand_id' => $brand->id,
        'line_id' => $line->id,
        'product_type_id' => $productType->id,
        'style_id' => $style->id,
        'skus_created' => $createdSkus,
        'skus_updated' => $updatedSkus,
        'skus_total' => BrandCatalogueSku::query()->where('brand_catalogue_style_id', $style->id)->count(),
        'style_images' => CatalogueImage::query()
            ->where('imageable_type', BrandCatalogueStyle::class)
            ->where('imageable_id', $style->id)
            ->count(),
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
        'style_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}/lines/{$line->id}/product-types/{$productType->id}/styles/{$style->id}"),
    ];
});

echo "Urban French Curl imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return array<string, mixed>
 */
function urbanFrenchCurlSource(): array
{
    return [
        'brand' => 'Urban',
        'style_name' => 'Urban French Curl',
        'product_type' => 'Braids / Bulk Braiding / Crochet Hair',
        'material' => 'Synthetic Fibre',
        'length' => '30"',
        'base_sku' => 'USBFC',
        'url' => 'https://feme.com/urban-french-curl/',
        'customer_description' => 'Slim-strand French Curl braiding hair with voluminous curled ends. Made from synthetic fibre for braiding or crochet styles. Not suitable for hot-water setting.',
        'colours' => ['1', '1B', '2', '4', '27/613', 'T1B/27', 'T1B/30', 'T1B/BG'],
        'images' => [
            'https://cdn11.bigcommerce.com/s-p1mnzjw5yo/images/stencil/1280x1280/products/1056/2809/Urban_French_Curl_Web__55922.1708706882.jpg?c=1',
            'https://cdn11.bigcommerce.com/s-p1mnzjw5yo/images/stencil/1280x1280/products/1056/2784/Urban_French_Curl_30_Packaging__32949.1761055306.jpg?c=1',
            'https://cdn11.bigcommerce.com/s-p1mnzjw5yo/images/stencil/1280x1280/products/1056/2786/Urban_French_Curl_T1B-30_Side_1__10103.1761055306.jpg?c=1',
            'https://cdn11.bigcommerce.com/s-p1mnzjw5yo/images/stencil/1280x1280/products/1056/2785/Urban_French_Curl_T1B-30_Back__73156.1761055306.jpg?c=1',
        ],
    ];
}

/**
 * @param array<string, mixed> $source
 * @return array<int, array{Length:string,Colour:string}>
 */
function skuRecords(array $source): array
{
    return collect($source['colours'])
        ->map(fn (string $colour): array => [
            'Length' => $source['length'],
            'Colour' => $colour,
        ])
        ->all();
}

/**
 * @param array<string, mixed> $source
 */
function syncStyleImages(BrandCatalogueStyle $style, array $source): void
{
    foreach ($source['images'] as $index => $imageUrl) {
        CatalogueImage::query()->updateOrCreate(
            [
                'imageable_type' => BrandCatalogueStyle::class,
                'imageable_id' => $style->id,
                'external_url' => $imageUrl,
            ],
            [
                'image_role' => $index === 1 ? 'packaging' : 'source_image',
                'storage_disk' => null,
                'storage_path' => null,
                'original_filename' => basename(parse_url($imageUrl, PHP_URL_PATH) ?: ''),
                'mime_type' => null,
                'file_size' => null,
                'sort_order' => $index * 10,
                'is_primary' => $index === 1,
                'source_label' => 'Feme Urban French Curl product page',
                'usage_context' => 'all',
                'notes' => $index === 1
                    ? 'Official packaging image for Urban French Curl 30".'
                    : 'Official source image for Urban French Curl.',
            ],
        );
    }
}

/**
 * @param array<int, array{Length:string,Colour:string}> $records
 * @param array<string, mixed> $source
 * @return array{0:int,1:int}
 */
function syncVariantsAndSkus(BrandCatalogueStyle $style, array $records, array $source): array
{
    $lengthVariant = syncVariant($style, 'Length', 'measurement', 10);
    $colourVariant = syncVariant($style, 'Colour', 'colour_code', 20);

    $lengthOptions = syncOptions($lengthVariant, [$source['length']]);
    $colourOptions = syncOptions($colourVariant, $source['colours']);

    $created = 0;
    $updated = 0;

    foreach ($records as $index => $record) {
        $selected = [
            'Length' => $record['Length'],
            'Colour' => $record['Colour'],
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
            'sku_code' => $source['base_sku'],
            'barcode' => $sku->barcode,
            'description' => $source['customer_description'],
            'note' => mergeNote($sku->note, 'Urban French Curl source lists this sellable variant as length '.$record['Length'].' and colour '.$record['Colour'].'. Base SKU: '.$source['base_sku'].'.'),
            'url' => $source['url'],
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
                'brand_catalogue_variant_option_id' => $lengthOptions[$record['Length']],
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'brand_catalogue_sku_id' => $sku->id,
                'brand_catalogue_variant_id' => $colourVariant->id,
                'brand_catalogue_variant_option_id' => $colourOptions[$record['Colour']],
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    return [$created, $updated];
}

function syncVariant(BrandCatalogueStyle $style, string $name, string $type, int $sortOrder): BrandCatalogueVariant
{
    return BrandCatalogueVariant::query()->updateOrCreate(
        [
            'brand_catalogue_style_id' => $style->id,
            'name' => $name,
        ],
        [
            'variant_type' => $type,
            'sort_order' => $sortOrder,
        ],
    );
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

function scopedSlug($query, string $name, ?int $ignoreId = null): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $counter = 2;

    while ((clone $query)
        ->where('slug', $slug)
        ->when($ignoreId, fn ($candidate) => $candidate->where('id', '!=', $ignoreId))
        ->exists()
    ) {
        $slug = $base.'-'.$counter;
        $counter++;
    }

    return $slug;
}

function mergeNote(?string $existing, string $addition): string
{
    $existing = trim((string) $existing);

    if ($existing === '') {
        return $addition;
    }

    if (str_contains($existing, $addition)) {
        return $existing;
    }

    return $existing."\n\n".$addition;
}

