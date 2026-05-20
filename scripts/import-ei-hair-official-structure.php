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

$products = fetchOfficialProducts();
$families = collect(buildFamilies($products));
$skuCount = $families->sum(fn (array $family): int => count($family['skus']));

if ($dryRun) {
    echo "EI Hair Extensions official source dry run.\n";
    echo 'Official API products read: '.$products->count()."\n";
    echo 'Selected clean EI/E&I families: '.$families->count()."\n";
    echo "SKU variants: {$skuCount}\n\n";

    $families
        ->groupBy('product_type')
        ->each(function (Collection $typeFamilies, string $productType): void {
            echo "{$productType}: {$typeFamilies->count()} families / ".$typeFamilies->sum(fn (array $family): int => count($family['skus']))." SKUs\n";

            $typeFamilies->each(function (array $family): void {
                $sourceIds = collect($family['source_products'])->pluck('id')->implode(', ');
                echo "- {$family['name']}: ".count($family['skus'])." SKUs [source {$sourceIds}]\n";
            });

            echo "\n";
        });

    echo "Skipped duplicate subset pages: Black/Brown/Blond EI weft and clip-in pages, because the ALL EI pages already carry the combined colour/length variant set.\n";
    echo "Skipped accessory/accessory-bundle pages: EI Colour Ring, micro beads, clips/glue/removers, and the EI DIY Weft Set with Glue page.\n";
    echo "Skipped ambiguous non-EI/no-brand Brazilian and wig products until shop/source confirmation.\n";
    exit(0);
}

$summary = DB::transaction(function () use ($families): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();
    $brand = findOrCreateEiBrand($catalogue);

    $brand->fill([
        'name' => 'EI Hair Extensions',
        'slug' => uniqueBrandSlug($catalogue, 'ei-hair-extensions', $brand->id),
        'url' => 'https://eihairextensions.co.uk/',
        'note' => mergeNote($brand->note, 'Official EI/E&I product structures imported from eihairextensions.co.uk. This catalogue is a stock-check reference before publishing retail products. Duplicate colour-subset SEO pages are intentionally consolidated into the main family pages.'),
        'is_active' => true,
    ])->save();

    $line = findOrCreateLine($brand, 'EI Hair Extensions', 'https://eihairextensions.co.uk/', 10);

    $productTypes = [];
    foreach ($families->pluck('product_type')->unique()->values() as $index => $productTypeName) {
        $productTypes[$productTypeName] = findOrCreateProductType($brand, $line, $productTypeName, ($index + 1) * 10);
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
            'material_name' => $family['material_name'],
            'name' => $family['name'],
            'note' => mergeNote($style->note, styleNote($family)),
            'url' => $family['url'],
            'is_active' => true,
            'sort_order' => $style->exists ? $style->sort_order : $index * 10,
        ])->save();

        syncStyleImages($style, $family);
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
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
        'line_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}/lines/{$line->id}"),
    ];
});

echo "EI Hair Extensions official structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function fetchOfficialProducts(): Collection
{
    $products = collect();

    for ($page = 1; $page <= 5; $page++) {
        $items = fetchJson("https://eihairextensions.co.uk/wp-json/wc/store/products?per_page=100&page={$page}");

        if ($items === []) {
            break;
        }

        $products = $products->merge($items);

        if (count($items) < 100) {
            break;
        }

        sleep(5);
    }

    return $products->keyBy('id');
}

/**
 * @return array<int, mixed>
 */
function fetchJson(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125 Safari/537.36',
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (! is_string($body) || $body === '' || $status >= 400) {
        throw new RuntimeException("Could not fetch {$url}. HTTP {$status}. {$error}");
    }

    $decoded = json_decode($body, true);

    if (! is_array($decoded)) {
        throw new RuntimeException("Invalid JSON returned from {$url}");
    }

    return $decoded;
}

/**
 * @param Collection<int, array<string, mixed>> $products
 * @return array<int, array<string, mixed>>
 */
function buildFamilies(Collection $products): array
{
    return collect(familyConfigs())
        ->map(function (array $config) use ($products): array {
            $sourceProducts = collect($config['product_ids'])
                ->map(function (int $productId) use ($products): array {
                    $product = $products->get($productId);

                    if (! is_array($product)) {
                        throw new RuntimeException("Official EI source product {$productId} was not found in the Store API response.");
                    }

                    return $product;
                })
                ->values();

            $skus = $sourceProducts
                ->flatMap(fn (array $product): array => skuRecordsFromProduct($product))
                ->unique(fn (array $record): string => optionSignature($record['options']))
                ->values()
                ->all();

            if ($skus === []) {
                throw new RuntimeException("No SKU variants parsed for {$config['name']}.");
            }

            $imageUrls = $sourceProducts
                ->flatMap(fn (array $product): array => imageUrlsFromProduct($product))
                ->unique()
                ->values()
                ->all();

            $descriptions = $sourceProducts
                ->map(fn (array $product): string => cleanDescription((string) ($product['description'] ?? '')))
                ->filter()
                ->unique()
                ->values();

            return [
                'name' => $config['name'],
                'product_type' => $config['product_type'],
                'material_name' => $config['material_name'],
                'url' => $sourceProducts->first()['permalink'] ?? 'https://eihairextensions.co.uk/',
                'description' => cleanSpaces($descriptions->implode(' ')),
                'image_urls' => $imageUrls,
                'source_products' => $sourceProducts->map(fn (array $product): array => [
                    'id' => $product['id'],
                    'name' => cleanProductName((string) $product['name']),
                    'url' => $product['permalink'] ?? null,
                ])->all(),
                'skus' => $skus,
            ];
        })
        ->all();
}

/**
 * @return array<int, array<string, mixed>>
 */
function familyConfigs(): array
{
    return [
        [
            'name' => 'EI Weft Human Hair Extensions',
            'product_type' => 'Human Hair Weft Extensions',
            'material_name' => '100% Human Hair',
            'product_ids' => [622],
        ],
        [
            'name' => 'EI Yaki Human Hair Weave',
            'product_type' => 'Human Hair Weft Extensions',
            'material_name' => '100% Human Hair',
            'product_ids' => [2434],
        ],
        [
            'name' => 'EI DIY Hair Extension Set with Clips',
            'product_type' => 'DIY Hair Extension Sets',
            'material_name' => '100% Human Hair',
            'product_ids' => [628],
        ],
        [
            'name' => 'EI Clip-In Human Hair Extensions',
            'product_type' => 'Clip-In Human Hair Extensions',
            'material_name' => '100% Human Hair',
            'product_ids' => [612],
        ],
        [
            'name' => 'EI Dulux Seamless Clip-In Extensions 8pcs 200g',
            'product_type' => 'Clip-In Human Hair Extensions',
            'material_name' => '100% Remy Human Hair',
            'product_ids' => [54411],
        ],
        [
            'name' => 'E&I Chic Extra Volume 10pcs Clip-In Remy Human Hair',
            'product_type' => 'Clip-In Human Hair Extensions',
            'material_name' => '100% Remy Human Hair',
            'product_ids' => [3323],
        ],
        [
            'name' => 'EI Unprocessed Virgin Brazilian Straight',
            'product_type' => 'Virgin Brazilian Human Hair',
            'material_name' => '100% Unprocessed Virgin Brazilian Hair',
            'product_ids' => [45221],
        ],
        [
            'name' => 'EI Unprocessed Virgin Brazilian Body Wave',
            'product_type' => 'Virgin Brazilian Human Hair',
            'material_name' => '100% Unprocessed Virgin Brazilian Hair',
            'product_ids' => [45244],
        ],
        [
            'name' => 'EI Unprocessed Virgin Brazilian Deep Wave',
            'product_type' => 'Virgin Brazilian Human Hair',
            'material_name' => '100% Unprocessed Virgin Brazilian Hair',
            'product_ids' => [45234],
        ],
        [
            'name' => 'EI Unprocessed Virgin Brazilian Jerry Curl',
            'product_type' => 'Virgin Brazilian Human Hair',
            'material_name' => '100% Unprocessed Virgin Brazilian Hair',
            'product_ids' => [45253],
        ],
        [
            'name' => 'EI Double Drawn Lumiere Remy Hair',
            'product_type' => 'Double Drawn Remy Hair',
            'material_name' => '100% Remy Human Hair',
            'product_ids' => [638],
        ],
        [
            'name' => 'EI Triple Weft Remy 150g',
            'product_type' => 'Triple Weft Remy Hair',
            'material_name' => '100% Remy Human Hair',
            'product_ids' => [5577],
        ],
        [
            'name' => 'EI Remy Nano Ring Extensions',
            'product_type' => 'Nano Ring Remy Extensions',
            'material_name' => '100% Remy Human Hair',
            'product_ids' => [35055],
        ],
        [
            'name' => 'EI Micro Loop Remy Hair',
            'product_type' => 'Micro Loop Remy Extensions',
            'material_name' => '100% Remy Human Hair',
            'product_ids' => [656],
        ],
        [
            'name' => 'EI Tape-In Remy Extensions',
            'product_type' => 'Tape-In Remy Extensions',
            'material_name' => 'Remy Human Hair',
            'product_ids' => [14236, 57600],
        ],
        [
            'name' => 'EI Stick Tip Remy Fusion Pre-Bonded',
            'product_type' => 'Pre-Bonded Remy Extensions',
            'material_name' => '100% Remy Human Hair',
            'product_ids' => [640],
        ],
        [
            'name' => 'EI Nail Tip Remy Pre-Bonded',
            'product_type' => 'Pre-Bonded Remy Extensions',
            'material_name' => '100% Remy Human Hair',
            'product_ids' => [50398],
        ],
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function skuRecordsFromProduct(array $product): array
{
    $termMaps = termMapsFromProduct($product);
    $records = [];

    foreach (($product['variations'] ?? []) as $variation) {
        $options = [];

        foreach (($variation['attributes'] ?? []) as $attribute) {
            $variantName = variantName((string) ($attribute['name'] ?? ''));

            if ($variantName === null) {
                continue;
            }

            $value = (string) ($attribute['value'] ?? '');
            $label = $termMaps[Str::lower((string) ($attribute['name'] ?? ''))][$value] ?? cleanOptionLabel($value);

            if ($label !== '') {
                $options[$variantName] = $label;
            }
        }

        if ($options === []) {
            continue;
        }

        $records[] = [
            'options' => sortOptions($options),
            'source_product_id' => (string) $product['id'],
            'source_product_name' => cleanProductName((string) $product['name']),
            'source_url' => (string) ($product['permalink'] ?? ''),
        ];
    }

    return $records;
}

/**
 * @return array<string, array<string, string>>
 */
function termMapsFromProduct(array $product): array
{
    $maps = [];

    foreach (($product['attributes'] ?? []) as $attribute) {
        $attributeName = Str::lower((string) ($attribute['name'] ?? ''));
        $maps[$attributeName] = [];

        foreach (($attribute['terms'] ?? []) as $term) {
            $label = cleanOptionLabel((string) ($term['name'] ?? ''));
            $slug = (string) ($term['slug'] ?? '');

            if ($slug !== '') {
                $maps[$attributeName][$slug] = $label;
            }

            if ($label !== '') {
                $maps[$attributeName][$label] = $label;
            }
        }
    }

    return $maps;
}

function variantName(string $sourceName): ?string
{
    $key = Str::lower(cleanSpaces($sourceName));

    return match ($key) {
        'size', 'pa_length', 'length' => 'Length',
        'ei colour', 'all colours', 'colour', 'color', 'pa_colour' => 'Colour',
        default => null,
    };
}

/**
 * @param array<string, string> $options
 * @return array<string, string>
 */
function sortOptions(array $options): array
{
    $ordered = [];
    foreach (['Length', 'Colour'] as $variantName) {
        if (isset($options[$variantName])) {
            $ordered[$variantName] = $options[$variantName];
        }
    }

    foreach ($options as $variantName => $value) {
        if (! isset($ordered[$variantName])) {
            $ordered[$variantName] = $value;
        }
    }

    return $ordered;
}

/**
 * @return array<int, string>
 */
function imageUrlsFromProduct(array $product): array
{
    return collect($product['images'] ?? [])
        ->pluck('src')
        ->filter()
        ->unique()
        ->take(1)
        ->values()
        ->all();
}

function cleanDescription(string $html): string
{
    $text = preg_replace('/(?is)<br\s*\/?>/', "\n", $html) ?? $html;
    $text = preg_replace('/(?is)<\/(p|div|li|h1|h2|h3|h4)>/', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    $text = str_replace("\xc2\xa0", ' ', $text);

    return collect(preg_split('/\R/', $text) ?: [])
        ->map(fn (string $line): string => cleanSpaces($line))
        ->filter()
        ->implode(' ');
}

function cleanProductName(string $name): string
{
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5);
    $name = str_replace(['”', '“', '″', '&#8243;'], '"', $name);

    return cleanSpaces($name);
}

function cleanOptionLabel(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
    $value = str_replace(['–', '—'], '-', $value);
    $value = str_replace(['”', '“', '″', '&#8243;'], '"', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return trim($value);
}

function findOrCreateEiBrand(BrandCatalogue $catalogue): BrandCatalogueBrand
{
    $brand = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where(function ($query): void {
            $query
                ->whereIn('slug', ['ei-hair-extensions', 'ei-hair', 'e-i'])
                ->orWhereIn('name', ['EI Hair Extensions', 'EI Hair', 'E&I']);
        })
        ->first();

    if ($brand) {
        return $brand;
    }

    return BrandCatalogueBrand::query()->create([
        'brand_catalogue_id' => $catalogue->id,
        'name' => 'EI Hair Extensions',
        'slug' => uniqueBrandSlug($catalogue, 'ei-hair-extensions'),
        'is_active' => true,
        'sort_order' => 170,
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
        'note' => mergeNote($line->note, 'EI Hair Extensions is treated as the master line for official EI/E&I products from eihairextensions.co.uk.'),
        'url' => $url,
        'is_default' => true,
        'is_active' => true,
        'sort_order' => $line->exists ? $line->sort_order : $sortOrder,
    ])->save();

    return $line;
}

function findOrCreateProductType(BrandCatalogueBrand $brand, BrandCatalogueLine $line, string $name, int $sortOrder): BrandCatalogueProductType
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
        'note' => mergeNote($productType->note, 'Structured from official EI Hair Extensions WooCommerce product data.'),
        'url' => 'https://eihairextensions.co.uk/',
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
    $sourceNames = collect($family['source_products'])
        ->map(fn (array $product): string => "{$product['name']} ({$product['id']})")
        ->implode('; ');

    return cleanSpaces("Family/style imported from the official EI Hair Extensions site. Source products: {$sourceNames}. {$family['description']}");
}

/**
 * @param array<string, mixed> $family
 */
function syncStyleImages(BrandCatalogueStyle $style, array $family): void
{
    $imageUrls = $family['image_urls'];

    CatalogueImage::query()
        ->where('imageable_type', BrandCatalogueStyle::class)
        ->where('imageable_id', $style->id)
        ->where('source_label', 'EI Hair Extensions official product page')
        ->when($imageUrls !== [], fn ($query) => $query->whereNotIn('external_url', $imageUrls))
        ->delete();

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
                'source_label' => 'EI Hair Extensions official product page',
                'usage_context' => 'all',
                'notes' => "Official source image for {$family['name']}.",
            ],
        );
    }
}

/**
 * @param Collection<int, array<string, mixed>> $records
 * @param array<string, mixed> $family
 * @return array{0:int,1:int}
 */
function syncVariantsAndSkus(BrandCatalogueStyle $style, Collection $records, array $family): array
{
    $variantNames = $records
        ->flatMap(fn (array $record): array => array_keys($record['options']))
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
            ->map(fn (array $record): ?string => $record['options'][$variantName] ?? null)
            ->filter()
            ->unique()
            ->sortBy(fn (string $value): string => $variantName === 'Length' ? lengthSortKey($value) : colourSortKey($value))
            ->values()
            ->all();

        $optionMaps[$variantName] = syncOptions($variant, $values);
    }

    $created = 0;
    $updated = 0;

    foreach ($records as $index => $record) {
        $selected = $record['options'];
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
            'sku_code' => $sku->sku_code,
            'barcode' => $sku->barcode,
            'description' => $sku->description,
            'note' => mergeNote($sku->note, skuNote($record)),
            'url' => $record['source_url'],
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
    return collect(sortOptions($selected))
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
 * @param array<string, mixed> $record
 */
function skuNote(array $record): string
{
    return "Official EI Hair Extensions product page lists this SKU. Source product: {$record['source_product_name']} ({$record['source_product_id']}).";
}

function lengthSortKey(string $length): string
{
    if (preg_match('/\d+/', $length, $match) === 1) {
        return sprintf('%05d:%s', (int) $match[0], $length);
    }

    return '99999:'.$length;
}

function colourSortKey(string $colour): string
{
    if (preg_match('/^\d+/', $colour, $match) === 1) {
        return sprintf('0%05d:%s', (int) $match[0], $colour);
    }

    return '1'.$colour;
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

    if ($addition === '') {
        return $existing;
    }

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
