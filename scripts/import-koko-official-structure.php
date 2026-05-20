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
    echo "Koko official source dry run.\n";
    echo 'Official products selected: '.$products->count()."\n";
    echo 'Families/styles: '.$families->count()."\n";
    echo "SKU variants: {$skuCount}\n\n";

    $families
        ->groupBy('line_name')
        ->each(function (Collection $lineFamilies, string $lineName): void {
            echo "{$lineName}: {$lineFamilies->count()} families / ".$lineFamilies->sum(fn (array $family): int => count($family['skus']))." SKUs\n";
            $lineFamilies
                ->groupBy('product_type')
                ->each(fn (Collection $typeFamilies, string $productType): bool => print "  - {$productType}: {$typeFamilies->count()} families / ".$typeFamilies->sum(fn (array $family): int => count($family['skus']))." SKUs\n");
            echo "\n";
        });

    echo "Excluded Koko accessories, brushes, clips/pins, colour rings, eyelashes, sale-only, best-seller and new-in duplicate collections.\n";
    exit(0);
}

$summary = DB::transaction(function () use ($families): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();
    $brand = findOrCreateKokoBrand($catalogue);

    $brand->fill([
        'name' => 'Koko',
        'slug' => uniqueBrandSlug($catalogue, 'koko', $brand->id),
        'url' => 'https://koko-hair.co.uk/',
        'note' => mergeNote($brand->note, 'Official Koko product structure imported from koko-hair.co.uk Shopify collection data. Accessories, brushes, colour rings, eyelashes, sale-only and duplicate merchandising collections are excluded. Use this as a stock-check catalogue before publishing retail products.'),
        'is_active' => true,
    ])->save();

    $lineModels = [];
    foreach (lineConfigs() as $lineName => $lineConfig) {
        $lineModels[$lineName] = findOrCreateLine($brand, $lineName, $lineConfig['url'], $lineConfig['sort_order']);
    }

    $productTypes = [];
    foreach ($families->groupBy(fn (array $family): string => $family['line_name'].'|'.$family['product_type']) as $key => $typeFamilies) {
        $first = $typeFamilies->first();
        $line = $lineModels[$first['line_name']];
        $productTypes[$key] = findOrCreateProductType(
            $brand,
            $line,
            $first['product_type'],
            productTypeSortOrder($first['collection_handle']),
            $first['collection_url'],
        );
    }

    $createdStyles = 0;
    $updatedStyles = 0;
    $createdSkus = 0;
    $updatedSkus = 0;
    $styleIds = [];

    foreach ($families as $index => $family) {
        $line = $lineModels[$family['line_name']];
        $productType = $productTypes[$family['line_name'].'|'.$family['product_type']];
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

    removeEmptyLines($brand);

    $imageCount = CatalogueImage::query()
        ->whereIn('imageable_type', [BrandCatalogueStyle::class, BrandCatalogueSku::class])
        ->where('source_label', 'Koko official product page')
        ->count();

    return [
        'brand_id' => $brand->id,
        'lines_touched' => count($lineModels),
        'product_types_touched' => count($productTypes),
        'styles_created' => $createdStyles,
        'styles_updated' => $updatedStyles,
        'styles_total_touched' => count(array_unique($styleIds)),
        'skus_created' => $createdSkus,
        'skus_updated' => $updatedSkus,
        'source_skus' => $families->sum(fn (array $family): int => count($family['skus'])),
        'official_images_saved' => $imageCount,
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
    ];
});

echo "Koko official structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return array<string, array{url:string,sort_order:int}>
 */
function lineConfigs(): array
{
    return [
        'Hair Extensions' => ['url' => 'https://koko-hair.co.uk/collections/hair-extensions', 'sort_order' => 10],
        'Dip Dye Collection' => ['url' => 'https://koko-hair.co.uk/collections/dip-dye', 'sort_order' => 20],
        'Ponytails' => ['url' => 'https://koko-hair.co.uk/collections/ponytails', 'sort_order' => 30],
        'Hairpieces' => ['url' => 'https://koko-hair.co.uk/collections/hairpieces', 'sort_order' => 40],
        'Wigs' => ['url' => 'https://koko-hair.co.uk/collections/wigs', 'sort_order' => 50],
        'Human Hair' => ['url' => 'https://koko-hair.co.uk/collections/human-hair', 'sort_order' => 60],
    ];
}

/**
 * Ordered deliberately: first matching collection becomes the product's primary structure.
 *
 * @return array<int, array<string, mixed>>
 */
function collectionConfigs(): array
{
    return [
        c('Dip Dye', 'dip-dye', 'Dip Dye Collection', 'Dip Dye Hairpieces', 10),
        c('Synthetic Extensions', 'wholesale-synthetic-clip-in-extensions', 'Hair Extensions', 'Synthetic Clip-In Extensions', 20),
        c('Jumbo Braiding Hair', 'jumbo-braiding-hair', 'Hair Extensions', 'Braiding & Bulk Hair', 30),
        c('Ponytails', 'ponytails', 'Ponytails', 'Synthetic Ponytails', 40),
        c('Scrunchies', 'scrunchies', 'Hairpieces', 'Synthetic Scrunchies', 50),
        c('Fringe', 'fringe', 'Hairpieces', 'Synthetic Fringes', 60),
        c('Buns', 'buns', 'Hairpieces', 'Synthetic Buns', 70),
        c('Party Hairpieces', 'clip-in-highlights', 'Hairpieces', 'Party Hairpieces', 80),
        c('Half Head Wigs', 'half-head-wigs', 'Wigs', 'Half Head Wigs', 90),
        c('Full Head Wigs', 'full-head-wigs', 'Wigs', 'Full Head Wigs', 100),
        c('Lace Front Wigs', 'lace-front-wigs', 'Wigs', 'Lace Front Wigs', 110),
        c('Party Wigs', 'party-wigs', 'Wigs', 'Party Wigs', 120),
        c('Human Hair Extensions', 'human-hair-extensions', 'Human Hair', 'Human Hair Clip-In Extensions', 130),
        c('Bundles and Closures', 'bundles-and-closures', 'Human Hair', 'Human Hair Bundles & Closures', 140),
        c('Human Hair Wigs', 'human-hair-wigs', 'Human Hair', 'Human Hair Wigs', 150),
        c('Human Hair Toppers', 'human-hair-toppers', 'Human Hair', 'Human Hair Toppers', 160),
    ];
}

/**
 * @return array<string, mixed>
 */
function c(string $title, string $handle, string $lineName, string $productType, int $sortOrder): array
{
    return [
        'title' => $title,
        'handle' => $handle,
        'line_name' => $lineName,
        'product_type' => $productType,
        'sort_order' => $sortOrder,
        'url' => "https://koko-hair.co.uk/collections/{$handle}",
    ];
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function fetchOfficialProducts(): Collection
{
    $products = collect();

    foreach (collectionConfigs() as $config) {
        $items = fetchJson("https://koko-hair.co.uk/collections/{$config['handle']}/products.json?limit=250");

        foreach (($items['products'] ?? []) as $product) {
            $handle = (string) ($product['handle'] ?? '');
            if ($handle === '') {
                continue;
            }

            if (! $products->has($handle)) {
                $product['_primary_collection'] = $config;
                $product['_source_collections'] = [$config['title']];
                $products->put($handle, $product);
                continue;
            }

            $existing = $products->get($handle);
            $existing['_source_collections'][] = $config['title'];
            $existing['_source_collections'] = array_values(array_unique($existing['_source_collections']));
            $products->put($handle, $existing);
        }
    }

    return $products->values();
}

/**
 * @return array<string, mixed>
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
    return $products
        ->map(function (array $product): array {
            $config = $product['_primary_collection'];
            $skus = skuRecordsFromProduct($product);

            if ($skus === []) {
                throw new RuntimeException("No SKU variants parsed for Koko product {$product['handle']}.");
            }

            return [
                'line_name' => $config['line_name'],
                'product_type' => $config['product_type'],
                'collection_handle' => $config['handle'],
                'collection_title' => $config['title'],
                'collection_url' => $config['url'],
                'source_collections' => $product['_source_collections'],
                'material_name' => materialNameFor($product, $config),
                'name' => cleanProductName((string) $product['title']),
                'url' => 'https://koko-hair.co.uk/products/'.(string) $product['handle'],
                'description' => cleanDescription((string) ($product['body_html'] ?? '')),
                'source_product_id' => (string) $product['id'],
                'source_product_handle' => (string) $product['handle'],
                'style_image_urls' => styleImageUrlsFromProduct($product),
                'skus' => $skus,
            ];
        })
        ->sortBy(fn (array $family): string => sprintf(
            '%03d:%03d:%s',
            lineConfigs()[$family['line_name']]['sort_order'],
            productTypeSortOrder($family['collection_handle']),
            $family['name'],
        ))
        ->values()
        ->all();
}

/**
 * @return array<int, array<string, mixed>>
 */
function skuRecordsFromProduct(array $product): array
{
    $optionNames = collect($product['options'] ?? [])->pluck('name')->values()->all();
    $records = [];

    foreach (($product['variants'] ?? []) as $variant) {
        $options = [];

        for ($index = 0; $index < 3; $index++) {
            $rawName = (string) ($optionNames[$index] ?? "Option ".($index + 1));
            $value = $variant['option'.($index + 1)] ?? null;
            $variantName = variantName($rawName, $value);

            if ($variantName === null || $value === null || $value === '') {
                continue;
            }

            $label = cleanOptionLabel((string) $value, $variantName);
            if ($label !== '') {
                $options[$variantName] = $label;
            }
        }

        $records[] = [
            'options' => sortOptions($options),
            'source_variant_id' => (string) ($variant['id'] ?? ''),
            'sku_code' => cleanSpaces((string) ($variant['sku'] ?? '')),
            'barcode' => cleanSpaces((string) ($variant['barcode'] ?? '')),
            'image_url' => variantImageUrl($variant),
        ];
    }

    if ($records === [] && (string) ($product['id'] ?? '') !== '') {
        $records[] = [
            'options' => [],
            'source_variant_id' => (string) $product['id'],
            'sku_code' => '',
            'barcode' => '',
            'image_url' => '',
        ];
    }

    return collect($records)
        ->unique(fn (array $record): string => optionSignature($record['options']))
        ->values()
        ->all();
}

function variantName(string $sourceName, mixed $value): ?string
{
    $name = Str::lower(cleanSpaces($sourceName));
    $value = cleanSpaces((string) $value);

    if ($name === 'title' && Str::lower($value) === 'default title') {
        return null;
    }

    return match ($name) {
        'color', 'colour' => 'Colour',
        'length' => 'Length',
        'parting' => 'Parting',
        'pattern' => 'Pattern',
        'title' => 'Option',
        default => Str::headline($sourceName),
    };
}

function cleanOptionLabel(string $value, string $variantName): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
    $value = str_replace("\xc2\xa0", ' ', $value);
    $value = cleanSpaces($value);

    if ($variantName === 'Colour') {
        $value = preg_replace('/^#(?=\d|[A-Z])/i', '', $value) ?? $value;
        $value = cleanSpaces($value);
    }

    if ($variantName === 'Length' && preg_match('/^\d+(?:\.\d+)?$/', $value) === 1) {
        return $value.'"';
    }

    return $value;
}

/**
 * @return array<int, string>
 */
function styleImageUrlsFromProduct(array $product): array
{
    $images = collect($product['images'] ?? []);
    $heroImages = $images
        ->filter(fn (array $image): bool => empty($image['variant_ids'] ?? []))
        ->pluck('src')
        ->map(fn (string $url): string => normaliseImageUrl($url))
        ->filter()
        ->unique()
        ->values();

    if ($heroImages->isNotEmpty()) {
        return $heroImages->take(4)->all();
    }

    return $images
        ->pluck('src')
        ->map(fn (string $url): string => normaliseImageUrl($url))
        ->filter()
        ->unique()
        ->take(1)
        ->values()
        ->all();
}

function variantImageUrl(array $variant): string
{
    $imageUrl = (string) data_get($variant, 'featured_image.src', '');

    return normaliseImageUrl($imageUrl);
}

function normaliseImageUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (str_starts_with($url, '//')) {
        return 'https:'.$url;
    }

    return $url;
}

function materialNameFor(array $product, array $config): string
{
    $text = Str::lower((string) ($product['title'] ?? '').' '.strip_tags((string) ($product['body_html'] ?? '')).' '.$config['product_type']);

    if (str_contains($text, 'human hair') || str_contains($text, 'virgin india') || str_contains($text, 'virgin indian')) {
        return 'Human Hair';
    }

    if (str_contains($text, 'synthetic')) {
        return 'Synthetic Fibre';
    }

    return str_contains(Str::lower($config['line_name']), 'human') ? 'Human Hair' : 'Synthetic Fibre';
}

function cleanProductName(string $name): string
{
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5);
    $name = str_replace("\xc2\xa0", ' ', $name);
    $name = str_replace(['&#8243;', '&quot;'], '"', $name);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;

    return trim($name);
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

function findOrCreateKokoBrand(BrandCatalogue $catalogue): BrandCatalogueBrand
{
    $brand = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where(function ($query): void {
            $query->where('slug', 'koko')->orWhere('name', 'Koko');
        })
        ->first();

    if ($brand) {
        return $brand;
    }

    return BrandCatalogueBrand::query()->create([
        'brand_catalogue_id' => $catalogue->id,
        'name' => 'Koko',
        'slug' => uniqueBrandSlug($catalogue, 'koko'),
        'url' => 'https://koko-hair.co.uk/',
        'is_active' => true,
        'sort_order' => 120,
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
        'note' => mergeNote($line->note, "{$name} is an official Koko catalogue line from koko-hair.co.uk."),
        'url' => $url,
        'is_default' => $name === 'Hair Extensions',
        'is_active' => true,
        'sort_order' => $sortOrder,
    ])->save();

    return $line;
}

function findOrCreateProductType(BrandCatalogueBrand $brand, BrandCatalogueLine $line, string $name, int $sortOrder, string $url): BrandCatalogueProductType
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
        'note' => mergeNote($productType->note, "Structured from the official Koko {$name} collection."),
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
    $collections = implode(', ', $family['source_collections']);
    $description = $family['description'] !== '' ? ' Product page details: '.$family['description'] : '';

    return cleanSpaces("Official Koko product page. Source product {$family['source_product_id']} ({$family['source_product_handle']}). Primary collection: {$family['collection_title']}. Also appears in: {$collections}.{$description}");
}

/**
 * @param array<string, mixed> $family
 */
function syncStyleImages(BrandCatalogueStyle $style, array $family): void
{
    $imageUrls = $family['style_image_urls'];

    CatalogueImage::query()
        ->where('imageable_type', BrandCatalogueStyle::class)
        ->where('imageable_id', $style->id)
        ->where('source_label', 'Koko official product page')
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
                'source_label' => 'Koko official product page',
                'usage_context' => 'family',
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
                'variant_type' => variantType($variantName),
                'url' => $style->url,
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
            'sku_code' => $record['sku_code'] !== '' ? $record['sku_code'] : $sku->sku_code,
            'barcode' => $record['barcode'] !== '' ? $record['barcode'] : $sku->barcode,
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

        syncSkuImage($sku, $record, $family);
    }

    return [$created, $updated];
}

function variantType(string $variantName): string
{
    return match ($variantName) {
        'Length' => 'measurement',
        'Colour' => 'colour_code',
        default => 'text',
    };
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
 * @param array<string, mixed> $record
 * @param array<string, mixed> $family
 */
function syncSkuImage(BrandCatalogueSku $sku, array $record, array $family): void
{
    $imageUrl = (string) ($record['image_url'] ?? '');

    CatalogueImage::query()
        ->where('imageable_type', BrandCatalogueSku::class)
        ->where('imageable_id', $sku->id)
        ->where('source_label', 'Koko official product page')
        ->when($imageUrl !== '', fn ($query) => $query->where('external_url', '!=', $imageUrl))
        ->delete();

    if ($imageUrl === '') {
        return;
    }

    CatalogueImage::query()->updateOrCreate(
        [
            'imageable_type' => BrandCatalogueSku::class,
            'imageable_id' => $sku->id,
            'external_url' => $imageUrl,
        ],
        [
            'image_role' => 'variant_image',
            'storage_disk' => null,
            'storage_path' => null,
            'original_filename' => basename(parse_url($imageUrl, PHP_URL_PATH) ?: ''),
            'mime_type' => null,
            'file_size' => null,
            'sort_order' => 0,
            'is_primary' => true,
            'source_label' => 'Koko official product page',
            'usage_context' => 'variant',
            'notes' => "Official Koko variant image for {$family['name']}.",
        ],
    );
}

/**
 * @param array<string, mixed> $family
 * @param array<string, mixed> $record
 */
function skuNote(array $family, array $record): string
{
    $variantId = $record['source_variant_id'] !== '' ? " Variant {$record['source_variant_id']}." : '';

    return "Official Koko product page lists this SKU. Source product {$family['source_product_id']}.{$variantId}";
}

/**
 * @param array<string, string> $selected
 */
function optionSignature(array $selected): string
{
    if ($selected === []) {
        return '';
    }

    return collect(sortOptions($selected))
        ->map(fn (string $value, string $name): string => $name.':'.$value)
        ->implode('|');
}

/**
 * @param array<string, string> $options
 * @return array<string, string>
 */
function sortOptions(array $options): array
{
    $ordered = [];
    foreach (['Length', 'Colour', 'Parting', 'Pattern', 'Option'] as $variantName) {
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
 * @param array<string, string> $selected
 */
function skuName(string $styleName, array $selected): string
{
    if ($selected === []) {
        return $styleName;
    }

    $parts = [$styleName];

    foreach (sortOptions($selected) as $variantName => $value) {
        if ($variantName === 'Length' && str_contains($styleName, $value)) {
            continue;
        }

        $parts[] = "{$variantName} {$value}";
    }

    return implode(' - ', $parts);
}

function lengthSortKey(string $length): string
{
    if (preg_match('/\d+(?:\.\d+)?/', $length, $match) === 1) {
        return sprintf('%08.2f:%s', (float) $match[0], $length);
    }

    return '99999999:'.$length;
}

function colourSortKey(string $colour): string
{
    $normalised = ltrim(Str::upper($colour), '#');

    if (preg_match('/^\d+/', $normalised, $match) === 1) {
        return sprintf('0%05d:%s', (int) $match[0], $normalised);
    }

    return '1'.$normalised;
}

function productTypeSortOrder(string $collectionHandle): int
{
    foreach (collectionConfigs() as $config) {
        if ($config['handle'] === $collectionHandle) {
            return $config['sort_order'];
        }
    }

    return 999;
}

function removeEmptyLines(BrandCatalogueBrand $brand): void
{
    $lineIdsWithProductTypes = BrandCatalogueProductType::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->whereNotNull('brand_catalogue_line_id')
        ->pluck('brand_catalogue_line_id')
        ->unique();

    BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->when($lineIdsWithProductTypes->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $lineIdsWithProductTypes))
        ->delete();
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
