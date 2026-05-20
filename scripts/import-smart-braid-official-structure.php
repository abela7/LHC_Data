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
    echo "Smart Braid official source dry run.\n";
    echo 'Official API products read: '.$products->count()."\n";
    echo 'Selected families: '.$families->count()."\n";
    echo "SKU variants: {$skuCount}\n\n";

    $families
        ->groupBy('line_name')
        ->each(function (Collection $lineFamilies, string $lineName): void {
            echo "{$lineName}: {$lineFamilies->count()} families / ".$lineFamilies->sum(fn (array $family): int => count($family['skus']))." SKUs\n";

            $lineFamilies->each(function (array $family): void {
                echo "- {$family['product_type']} > {$family['name']}: ".count($family['skus'])." SKUs [source {$family['source_product_id']}]\n";
            });

            echo "\n";
        });

    echo "Skipped Smart Ponytail, Smart Fashion Wig, Smart Glamlace, Bohemian Bundle, Smart Natural Bundle and Boho Style products because they were not in the requested Smart Braid/Vivitress/Remy Chaser/X-Smart scope.\n";
    exit(0);
}

$summary = DB::transaction(function () use ($families): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();
    $brand = findOrCreateSmartBraidBrand($catalogue);

    $brand->fill([
        'name' => 'Smart Braid',
        'slug' => uniqueBrandSlug($catalogue, 'smart-braid', $brand->id),
        'url' => 'https://smartbraid.co.uk/',
        'note' => mergeNote($brand->note, 'Master brand for official Smart Braid site ranges. Vivitress, Remy Chaser, Smart Braid and X-Smart are treated as lines/sub-brands under this master brand. Structures are imported from official WooCommerce product data and must be shop-checked before publishing retail products.'),
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
        $productTypeIndex = $families
            ->where('line_name', $first['line_name'])
            ->pluck('product_type')
            ->unique()
            ->values()
            ->search($first['product_type']);

        $productTypes[$key] = findOrCreateProductType(
            $brand,
            $line,
            $first['product_type'],
            (($productTypeIndex === false ? 0 : (int) $productTypeIndex) + 1) * 10,
            $first['line_name'],
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
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
    ];
});

echo "Smart Braid official structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function fetchOfficialProducts(): Collection
{
    $items = fetchJson('https://smartbraid.co.uk/wp-json/wc/store/products?per_page=100');

    return collect($items)->keyBy('id');
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
    return $products
        ->filter(fn (array $product): bool => selectedLine($product) !== null)
        ->map(function (array $product): array {
            $lineName = selectedLine($product);

            if ($lineName === null) {
                throw new RuntimeException('Unexpected unselected product.');
            }

            $skus = skuRecordsFromProduct($product);
            if ($skus === []) {
                throw new RuntimeException("No SKU variants parsed for product {$product['id']}.");
            }

            return [
                'line_name' => $lineName,
                'line_url' => lineConfigs()[$lineName]['url'],
                'name' => cleanFamilyName((string) $product['name'], $lineName),
                'product_type' => productTypeFor($product, $lineName),
                'material_name' => materialNameFor($product, $lineName),
                'url' => (string) ($product['permalink'] ?? ''),
                'description' => cleanDescription((string) ($product['short_description'] ?? '')),
                'image_urls' => imageUrlsFromProduct($product),
                'source_product_id' => (string) $product['id'],
                'source_product_name' => cleanProductName((string) $product['name']),
                'skus' => $skus,
            ];
        })
        ->sortBy(fn (array $family): string => sprintf('%03d:%s', lineConfigs()[$family['line_name']]['sort_order'], $family['name']))
        ->values()
        ->all();
}

/**
 * @return array<string, array<string, mixed>>
 */
function lineConfigs(): array
{
    return [
        'Smart Braid' => [
            'url' => 'https://smartbraid.co.uk/product-category/braids/smart-braid/',
            'sort_order' => 10,
        ],
        'X-Smart' => [
            'url' => 'https://smartbraid.co.uk/product-category/braids/x-smart/',
            'sort_order' => 20,
        ],
        'Vivitress' => [
            'url' => 'https://smartbraid.co.uk/product-category/crotchet/vivitress/',
            'sort_order' => 30,
        ],
        'Remy Chaser' => [
            'url' => 'https://smartbraid.co.uk/product-category/remy-chaser/',
            'sort_order' => 40,
        ],
    ];
}

function selectedLine(array $product): ?string
{
    $brands = productBrands($product);
    $categories = productCategories($product);

    if ($brands->contains('SMART BRAID') || $categories->contains('SMART BRAID')) {
        return 'Smart Braid';
    }

    if ($brands->contains('X-SMART') || $categories->contains('X-SMART')) {
        return 'X-Smart';
    }

    if ($brands->contains(fn (string $brand): bool => str_starts_with($brand, 'VIVITRESS')) || $categories->contains('VIVITRESS')) {
        return 'Vivitress';
    }

    if ($brands->contains(fn (string $brand): bool => str_starts_with($brand, 'REMY CHASER')) || $categories->contains('REMY CHASER')) {
        return 'Remy Chaser';
    }

    return null;
}

/**
 * @return Collection<int, string>
 */
function productBrands(array $product): Collection
{
    return collect($product['brands'] ?? [])
        ->pluck('name')
        ->map(fn (string $name): string => Str::upper(cleanSpaces(html_entity_decode($name, ENT_QUOTES | ENT_HTML5))))
        ->values();
}

/**
 * @return Collection<int, string>
 */
function productCategories(array $product): Collection
{
    return collect($product['categories'] ?? [])
        ->pluck('name')
        ->map(fn (string $name): string => Str::upper(cleanSpaces(html_entity_decode($name, ENT_QUOTES | ENT_HTML5))))
        ->values();
}

function productTypeFor(array $product, string $lineName): string
{
    return match ($lineName) {
        'Smart Braid', 'X-Smart' => 'Synthetic Braiding Hair',
        'Vivitress' => 'Crochet Hair',
        'Remy Chaser' => 'Synthetic Hair Weave',
        default => 'Hair Extensions',
    };
}

function materialNameFor(array $product, string $lineName): string
{
    $materials = attributeTerms($product, 'Material');

    if ($materials !== []) {
        return implode(' / ', $materials);
    }

    return match ($lineName) {
        'Smart Braid', 'X-Smart' => 'Synthetic Fiber',
        'Vivitress', 'Remy Chaser' => 'Real Kanekalon',
        default => 'Synthetic Hair',
    };
}

/**
 * @return array<int, string>
 */
function attributeTerms(array $product, string $attributeName): array
{
    foreach (($product['attributes'] ?? []) as $attribute) {
        if (Str::lower((string) ($attribute['name'] ?? '')) !== Str::lower($attributeName)) {
            continue;
        }

        return collect($attribute['terms'] ?? [])
            ->pluck('name')
            ->map(fn (string $term): string => cleanOptionLabel($term, $attributeName))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    return [];
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

            $sourceAttributeName = Str::lower((string) ($attribute['name'] ?? ''));
            $value = (string) ($attribute['value'] ?? '');
            $label = $termMaps[$sourceAttributeName][$value] ?? cleanOptionLabel($value, $variantName);

            if ($label !== '') {
                $options[$variantName] = $label;
            }
        }

        if ($options === []) {
            continue;
        }

        $records[] = [
            'options' => sortOptions($options),
            'source_variation_id' => (string) ($variation['id'] ?? ''),
        ];
    }

    return collect($records)
        ->unique(fn (array $record): string => optionSignature($record['options']))
        ->values()
        ->all();
}

/**
 * @return array<string, array<string, string>>
 */
function termMapsFromProduct(array $product): array
{
    $maps = [];

    foreach (($product['attributes'] ?? []) as $attribute) {
        $attributeName = Str::lower((string) ($attribute['name'] ?? ''));
        $variantName = variantName((string) ($attribute['name'] ?? ''));
        $maps[$attributeName] = [];

        foreach (($attribute['terms'] ?? []) as $term) {
            $label = cleanOptionLabel((string) ($term['name'] ?? ''), $variantName ?? (string) ($attribute['name'] ?? ''));
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
        'size' => 'Length',
        'color', 'colors', 'wig color' => 'Colour',
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

function cleanFamilyName(string $name, string $lineName): string
{
    $name = cleanProductName($name);
    $name = preg_replace('/^VIVITRESS\s+MEGA\s+PACK\s*-\s*/i', 'Vivitress Mega Pack ', $name) ?? $name;
    $name = preg_replace('/^VIVITRESS\s+MEGA\s*-\s*/i', 'Vivitress Mega Pack ', $name) ?? $name;
    $name = preg_replace('/^VIVITRESS\s*-\s*/i', 'Vivitress ', $name) ?? $name;
    $name = preg_replace('/^VIVITRESS\b/i', 'Vivitress', $name) ?? $name;
    $name = preg_replace('/^REMY CHASER\s+/i', 'Remy Chaser ', $name) ?? $name;
    $name = preg_replace('/^Smart Braid\s+/i', 'Smart Braid ', $name) ?? $name;
    $name = preg_replace('/^X-SMART\s+/i', 'X-Smart ', $name) ?? $name;
    $name = preg_replace('/\bPRE-\s*STRETCHED\b/i', 'Pre-Stretched', $name) ?? $name;
    $name = preg_replace('/\bPACK\b/i', 'Pack', $name) ?? $name;
    $name = preg_replace('/\bFIRE-\s*RESISTANCE\b/i', 'Fire Resistance', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;

    if ($lineName === 'Vivitress' && ! str_starts_with(Str::lower($name), 'vivitress')) {
        $name = 'Vivitress '.$name;
    }

    return cleanSpaces($name);
}

function cleanProductName(string $name): string
{
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5);
    $name = str_replace(['”', '“', '″', '&#8243;'], '"', $name);
    $name = str_replace(['&#215;', '×'], 'x', $name);

    return cleanSpaces($name);
}

function cleanOptionLabel(string $value, string $variantName = ''): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
    $value = str_replace(['”', '“', '″', '&#8243;'], '"', $value);
    $value = str_replace(['&#215;', '×'], 'x', $value);
    $value = cleanSpaces($value);

    if (Str::lower($variantName) === 'length' && preg_match('/^\d+$/', $value) === 1) {
        return $value.'"';
    }

    return $value;
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

function findOrCreateSmartBraidBrand(BrandCatalogue $catalogue): BrandCatalogueBrand
{
    $brand = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where(function ($query): void {
            $query
                ->whereIn('slug', ['smart-braid', 'smartbraid'])
                ->orWhere('name', 'Smart Braid');
        })
        ->first();

    if ($brand) {
        return $brand;
    }

    return BrandCatalogueBrand::query()->create([
        'brand_catalogue_id' => $catalogue->id,
        'name' => 'Smart Braid',
        'slug' => uniqueBrandSlug($catalogue, 'smart-braid'),
        'is_active' => true,
        'sort_order' => 180,
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
        'note' => mergeNote($line->note, "{$name} is treated as a line/sub-brand under the Smart Braid master brand."),
        'url' => $url,
        'is_default' => $name === 'Smart Braid',
        'is_active' => true,
        'sort_order' => $line->exists ? $line->sort_order : $sortOrder,
    ])->save();

    return $line;
}

function findOrCreateProductType(BrandCatalogueBrand $brand, BrandCatalogueLine $line, string $name, int $sortOrder, string $lineName): BrandCatalogueProductType
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
        'note' => mergeNote($productType->note, "Structured from official Smart Braid {$lineName} WooCommerce data."),
        'url' => $line->url,
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
    return cleanSpaces("Family/style imported from the official Smart Braid site. Source product: {$family['source_product_name']} ({$family['source_product_id']}). {$family['description']}");
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
        ->where('source_label', 'Smart Braid official product page')
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
                'source_label' => 'Smart Braid official product page',
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
 * @param array<string, mixed> $family
 * @param array<string, mixed> $record
 */
function skuNote(array $family, array $record): string
{
    $variation = $record['source_variation_id'] !== '' ? " Variation {$record['source_variation_id']}." : '';

    return "Official Smart Braid product page lists this SKU. Source product {$family['source_product_id']}.{$variation}";
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
