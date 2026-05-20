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

$sourceProducts = collectKuknusProducts();
$sourceSkus = buildSourceSkus($sourceProducts);
$groups = $sourceSkus->groupBy(fn (array $sku): string => implode('|', [
    $sku['product_type'],
    $sku['family'],
]));

if ($dryRun) {
    echo "Kuknus official site dry run.\n";
    echo 'Source products: '.$sourceProducts->count()."\n";
    echo 'Style groups: '.$groups->count()."\n";
    echo 'SKU variants: '.$sourceSkus->count()."\n\n";

    $groups
        ->sortKeys()
        ->each(function (Collection $skus): void {
            $first = $skus->first();
            echo "Kuknus > {$first['product_type']} > {$first['family']}\n";
            echo '  skus: '.$skus->count().' | products: '.$skus->pluck('source.product_id')->unique()->count()."\n";
        });

    exit(0);
}

$summary = DB::transaction(function () use ($groups): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();
    $brand = findOrCreateKuknusBrand($catalogue);

    $brand->fill([
        'name' => 'Kuknus',
        'slug' => uniqueBrandSlug($catalogue, 'kuknus', $brand->id),
        'url' => 'https://www.kuknus.co.uk/',
        'note' => mergeNote($brand->note, 'Reference structure imported from the official Kuknus website. Confirm exact shop stock before publishing retail products.'),
        'is_active' => true,
    ])->save();

    hideEmptyLegacyKuknusBrands($catalogue, $brand);

    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('is_default', true)
        ->first();

    if (! $line) {
        $line = BrandCatalogueLine::query()->firstOrCreate(
            [
                'brand_catalogue_brand_id' => $brand->id,
                'name' => 'Kuknus',
            ],
            [
                'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), 'Kuknus'),
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 0,
            ],
        );
    }

    $line->fill([
        'name' => 'Kuknus',
        'note' => mergeNote($line->note, 'Default Kuknus line. Product data is sourced from the official Kuknus website.'),
        'url' => 'https://www.kuknus.co.uk/',
        'is_default' => true,
        'is_active' => true,
        'sort_order' => 0,
    ])->save();

    $createdSkus = 0;
    $updatedSkus = 0;
    $productTypeIds = [];
    $styleIds = [];

    foreach ($groups as $groupSkus) {
        $first = $groupSkus->first();

        $productType = BrandCatalogueProductType::query()->firstOrCreate(
            [
                'brand_catalogue_line_id' => $line->id,
                'name' => $first['product_type'],
            ],
            [
                'brand_catalogue_brand_id' => $brand->id,
                'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $first['product_type']),
                'is_active' => true,
            ],
        );

        $productType->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'note' => mergeNote($productType->note, 'Structured from official Kuknus website categories.'),
            'url' => $productType->url ?: productTypeUrl($first['product_type']),
            'is_active' => true,
            'sort_order' => productTypeSort($first['product_type']),
        ])->save();

        $style = BrandCatalogueStyle::query()
            ->where('brand_catalogue_product_type_id', $productType->id)
            ->where('name', $first['family'])
            ->first();

        if (! $style) {
            $style = new BrandCatalogueStyle([
                'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $first['family']),
            ]);
        }

        $style->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $productType->id,
            'brand_catalogue_material_id' => null,
            'material_name' => $style->material_name ?: $first['material'],
            'name' => $first['family'],
            'note' => mergeNote($style->note, styleNote($groupSkus)),
            'url' => $style->url ?: $first['source']['source_url'],
            'is_active' => true,
            'sort_order' => $style->exists ? $style->sort_order : styleSort($first['family']),
        ])->save();

        syncStyleImages($style, $groupSkus);
        [$created, $updated] = syncStyleVariantsAndSkus($style, $groupSkus);

        $createdSkus += $created;
        $updatedSkus += $updated;
        $productTypeIds[] = $productType->id;
        $styleIds[] = $style->id;
    }

    return [
        'brand_id' => $brand->id,
        'line_id' => $line->id,
        'product_types_touched' => count(array_unique($productTypeIds)),
        'styles_touched' => count(array_unique($styleIds)),
        'skus_created' => $createdSkus,
        'skus_updated' => $updatedSkus,
        'retail_products_created' => 0,
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
    ];
});

echo "Kuknus official site structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function collectKuknusProducts(): Collection
{
    $categories = officialKuknusCategories();
    $products = [];

    foreach ($categories as $category) {
        $categoryProducts = collectCategoryProducts($category);

        foreach ($categoryProducts as $categoryProduct) {
            $productId = productIdFromUrl($categoryProduct['source_url']);
            if ($productId === '') {
                continue;
            }

            if (! isset($products[$productId])) {
                $products[$productId] = parseKuknusProductPage($categoryProduct['source_url']);
            }

            $products[$productId]['categories'][$category['name']] = $category['url'];
        }
    }

    return collect($products)
        ->map(function (array $product): array {
            $product['categories'] = collect($product['categories'] ?? [])
                ->map(fn (string $url, string $name): array => ['name' => $name, 'url' => $url])
                ->values()
                ->all();

            return $product;
        })
        ->sortBy(fn (array $product): string => Str::lower($product['title']))
        ->values();
}

/**
 * @return array<int, array{name:string,path:string,url:string}>
 */
function officialKuknusCategories(): array
{
    $base = 'https://kuknus.co.uk/index.php?route=product/category&path=';

    return [
        ['name' => 'Brazilian Wigs/Lace Wigs', 'path' => '20', 'url' => $base.'20'],
        ['name' => 'Human Hair > Hair Wigs', 'path' => '18_46', 'url' => $base.'18_46'],
        ['name' => 'Human Hair > Lace Wigs', 'path' => '18_45', 'url' => $base.'18_45'],
        ['name' => 'Swiss Lace Wigs', 'path' => '57', 'url' => $base.'57'],
        ['name' => 'Synthetic Wigs > Full Wigs', 'path' => '25_28', 'url' => $base.'25_28'],
        ['name' => 'Synthetic Wigs > Half Wigs', 'path' => '25_29', 'url' => $base.'25_29'],
        ['name' => 'Synthetic Wigs > Lace Wigs', 'path' => '25_30', 'url' => $base.'25_30'],
        ['name' => 'Synthetic Crochet Braids', 'path' => '17', 'url' => $base.'17'],
        ['name' => 'Drawstring/Ponytails', 'path' => '34', 'url' => $base.'34'],
        ['name' => 'Accessories', 'path' => '59', 'url' => $base.'59'],
        ['name' => 'Venetian & Swiss Lace Front', 'path' => '63', 'url' => $base.'63'],
    ];
}

/**
 * @param array{name:string,path:string,url:string} $category
 * @return array<int, array<string, mixed>>
 */
function collectCategoryProducts(array $category): array
{
    $pending = [$category['url']];
    $seenPages = [];
    $products = [];

    while ($pending !== []) {
        $url = array_shift($pending);
        if (isset($seenPages[$url])) {
            continue;
        }

        $seenPages[$url] = true;
        $xpath = htmlXPath(fetchHtml($url));

        foreach ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' product-thumb ')]") as $thumb) {
            $anchor = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' caption ')]//h4/a | .//h4/a", $thumb)->item(0);
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $productUrl = absoluteKuknusUrl($anchor->getAttribute('href'));
            $title = cleanText($anchor->textContent);
            if ($productUrl === '' || $title === '') {
                continue;
            }

            $price = firstChildText($xpath, $thumb, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' price ')]");
            $imageNode = $xpath->query(".//img/@src", $thumb)->item(0);

            $products[] = [
                'title' => $title,
                'source_url' => $productUrl,
                'category' => $category['name'],
                'category_url' => $category['url'],
                'grid_image' => $imageNode ? absoluteKuknusUrl($imageNode->nodeValue) : null,
                'price' => priceNumber($price),
            ];
        }

        foreach ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' pagination ')]//a/@href") as $node) {
            $href = absoluteKuknusUrl($node->nodeValue);
            if ($href !== '' && str_contains($href, 'route=product/category') && ! isset($seenPages[$href])) {
                $pending[] = $href;
            }
        }

        $pending = array_values(array_unique($pending));
    }

    return $products;
}

/**
 * @return array<string, mixed>
 */
function parseKuknusProductPage(string $url): array
{
    $xpath = htmlXPath(fetchHtml($url));
    $title = normaliseTitle(firstText($xpath, '//h1'));
    $description = cleanText(firstText($xpath, "//*[@id='tab-description']"));
    $priceText = firstText($xpath, "//ul[contains(concat(' ', normalize-space(@class), ' '), ' list-unstyled ')]//h2");
    $images = [];

    foreach ($xpath->query("//ul[contains(concat(' ', normalize-space(@class), ' '), ' thumbnails ')]//a[contains(concat(' ', normalize-space(@class), ' '), ' thumbnail ')]/@href") as $node) {
        $imageUrl = absoluteKuknusUrl($node->nodeValue);
        if ($imageUrl !== '') {
            $images[] = $imageUrl;
        }
    }

    $options = [];
    foreach ($xpath->query("//*[@id='product']//*[contains(concat(' ', normalize-space(@class), ' '), ' form-group ')]") as $group) {
        $label = firstChildText($xpath, $group, './/label');
        $label = preg_replace('/\s*\*+\s*/', '', $label) ?? $label;
        $label = cleanText($label);
        if ($label === '' || Str::lower($label) === 'qty') {
            continue;
        }

        $values = [];
        foreach ($xpath->query('.//select/option', $group) as $option) {
            $value = cleanText($option->textContent);
            if ($value === '' || str_starts_with(Str::lower($value), '---')) {
                continue;
            }

            $values[] = normaliseOptionValue($value);
        }

        if ($values !== []) {
            $options[$label] = collect($values)->unique()->values()->all();
        }
    }

    return [
        'product_id' => productIdFromUrl($url),
        'title' => $title,
        'source_url' => $url,
        'description' => descriptionForSource($description, $title),
        'price' => priceNumber($priceText),
        'images' => collect($images)->unique()->values()->all(),
        'options' => $options,
        'categories' => [],
    ];
}

/**
 * @param Collection<int, array<string, mixed>> $products
 * @return Collection<int, array<string, mixed>>
 */
function buildSourceSkus(Collection $products): Collection
{
    return $products->flatMap(function (array $product): array {
        $classified = classifyProduct($product);
        $colourOptions = collect($product['options']['Hair Color'] ?? [])
            ->map(fn (string $colour): string => normaliseColour($colour))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $colourRows = $colourOptions === [] ? [null] : $colourOptions;

        return collect($colourRows)
            ->map(function (?string $colour) use ($product, $classified): array {
                $variants = $classified['variants'];

                if ($colour !== null) {
                    $variants['Colour'] = $colour;
                } elseif ($variants === []) {
                    $variants['Review Status'] = 'Variant review pending';
                }

                $variants = orderVariants($variants);

                return [
                    'product_type' => $classified['product_type'],
                    'family' => $classified['family'],
                    'material' => $classified['material'],
                    'variants' => $variants,
                    'sku_name' => skuName($classified['family'], $variants),
                    'source' => $product,
                ];
            })
            ->all();
    })->values();
}

/**
 * @return array{product_type:string,family:string,material:string,variants:array<string,string>}
 */
function classifyProduct(array $product): array
{
    $categories = collect($product['categories'])->pluck('name')->all();
    $title = $product['title'];

    return [
        'product_type' => productTypeName($categories),
        'family' => familyName($title, $categories),
        'material' => materialName($title, $categories),
        'variants' => titleVariants($title, $categories),
    ];
}

/**
 * @param array<int, string> $categories
 */
function productTypeName(array $categories): string
{
    $joined = Str::lower(implode(' | ', $categories));

    if (str_contains($joined, 'accessories')) {
        return 'Accessories';
    }

    if (str_contains($joined, 'crochet braids')) {
        return 'Braiding Hair';
    }

    if (str_contains($joined, 'drawstring') || str_contains($joined, 'ponytails')) {
        return 'Ponytails / Drawstrings';
    }

    if (str_contains($joined, 'half wigs')) {
        return 'Half Wigs';
    }

    if (str_contains($joined, 'lace wigs') || str_contains($joined, 'lace front')) {
        return 'Lace Wigs';
    }

    return 'Wigs';
}

/**
 * @param array<int, string> $categories
 */
function materialName(string $title, array $categories): string
{
    $joined = Str::lower(implode(' | ', $categories).' '.$title);

    if (str_contains($joined, 'accessories')) {
        return 'Accessory';
    }

    if (str_contains($joined, 'brazilian')) {
        return 'Brazilian Hair';
    }

    if (str_contains($joined, 'human hair blend')) {
        return 'Human Hair Blend';
    }

    if (str_contains($joined, 'human hair') || preg_match('/\bHH\b/i', $title)) {
        return 'Human Hair';
    }

    if (str_contains($joined, 'synthetic') || str_contains($joined, 'crochet')) {
        return 'Synthetic Hair';
    }

    return 'Hair';
}

/**
 * @param array<int, string> $categories
 */
function familyName(string $title, array $categories): string
{
    $family = normaliseTitle($title);
    $family = preg_replace('/\s*,?\s*human\s+hair\s+blend\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\b(\d+(?:\.\d+)?)\s*"/', ' ', $family) ?? $family;
    $family = preg_replace('/\b[2-9]X\b/i', ' ', $family) ?? $family;

    if (isLengthSuffixCategory($categories)) {
        $family = preg_replace('/\s+\b(?:10|12|14|16|18|20|22|24|26|28|30)\b$/', '', $family) ?? $family;
    }

    $family = cleanSpaces($family);
    $family = titleStyle($family);

    return cleanSpaces($family);
}

/**
 * @param array<int, string> $categories
 * @return array<string,string>
 */
function titleVariants(string $title, array $categories): array
{
    $variants = [];

    if (preg_match('/\b(\d+(?:\.\d+)?)\s*"/', $title, $match)) {
        $variants['Length'] = rtrim(rtrim($match[1], '0'), '.').'"';
    } elseif (isLengthSuffixCategory($categories) && preg_match('/\b(10|12|14|16|18|20|22|24|26|28|30)\b$/', $title, $match)) {
        $variants['Length'] = $match[1].'"';
    }

    if (preg_match('/\b([2-9]X)\b/i', $title, $match)) {
        $variants['Pack'] = Str::upper($match[1]);
    }

    return orderVariants($variants);
}

/**
 * @param array<int, string> $categories
 */
function isLengthSuffixCategory(array $categories): bool
{
    $joined = Str::lower(implode(' | ', $categories));

    return str_contains($joined, 'crochet braids') || str_contains($joined, 'drawstring') || str_contains($joined, 'ponytails');
}

/**
 * @param Collection<int, array<string, mixed>> $groupSkus
 */
function syncStyleImages(BrandCatalogueStyle $style, Collection $groupSkus): void
{
    $imageUrls = $groupSkus
        ->pluck('source.images')
        ->flatten()
        ->filter()
        ->unique()
        ->values();

    $hasPrimary = CatalogueImage::query()
        ->where('imageable_type', BrandCatalogueStyle::class)
        ->where('imageable_id', $style->id)
        ->where('is_primary', true)
        ->exists();

    foreach ($imageUrls as $index => $imageUrl) {
        CatalogueImage::query()->updateOrCreate(
            [
                'imageable_type' => BrandCatalogueStyle::class,
                'imageable_id' => $style->id,
                'external_url' => $imageUrl,
            ],
            [
                'image_role' => 'source_image',
                'source_label' => 'Kuknus official site',
                'usage_context' => 'reference',
                'notes' => 'Official Kuknus product image. Shared at family/product level, not confirmed colour-specific.',
                'is_primary' => ! $hasPrimary && $index === 0,
                'sort_order' => $index * 10,
            ],
        );
    }
}

/**
 * @param Collection<int, array<string, mixed>> $groupSkus
 * @return array{0:int,1:int}
 */
function syncStyleVariantsAndSkus(BrandCatalogueStyle $style, Collection $groupSkus): array
{
    $variantNames = $groupSkus
        ->flatMap(fn (array $sku): array => array_keys($sku['variants']))
        ->unique()
        ->sortBy(fn (string $name): int => variantSortOrder($name))
        ->values();

    $variantMap = [];
    $optionMap = [];

    foreach ($variantNames as $index => $variantName) {
        $variant = BrandCatalogueVariant::query()->updateOrCreate(
            [
                'brand_catalogue_style_id' => $style->id,
                'name' => $variantName,
            ],
            [
                'variant_type' => variantType($variantName),
                'sort_order' => $index * 10,
            ],
        );

        $variantMap[$variantName] = $variant;

        $values = $groupSkus
            ->map(fn (array $sku): ?string => $sku['variants'][$variantName] ?? null)
            ->filter()
            ->unique()
            ->sortBy(fn (string $value): string => naturalSortKey($value))
            ->values();

        foreach ($values as $optionIndex => $value) {
            $option = BrandCatalogueVariantOption::query()->updateOrCreate(
                [
                    'variant_id' => $variant->id,
                    'label' => $value,
                ],
                [
                    'value' => $value,
                    'sort_order' => $optionIndex * 10,
                ],
            );

            $optionMap[$variantName][$value] = $option;
        }
    }

    $created = 0;
    $updated = 0;

    foreach ($groupSkus->values() as $index => $sourceSku) {
        $variants = orderVariants($sourceSku['variants']);
        $signature = optionSignature($variants);
        $source = $sourceSku['source'];
        $skuName = $sourceSku['sku_name'];

        $sku = BrandCatalogueSku::query()
            ->where('brand_catalogue_style_id', $style->id)
            ->where('option_signature', $signature)
            ->first();

        if (! $sku) {
            $sku = new BrandCatalogueSku([
                'brand_catalogue_style_id' => $style->id,
                'option_signature' => $signature,
                'slug' => scopedSlug(BrandCatalogueSku::query()->where('brand_catalogue_style_id', $style->id), $skuName),
            ]);
            $created++;
        } else {
            $updated++;
        }

        $sku->fill([
            'name' => $skuName,
            'sku_code' => $sku->sku_code,
            'barcode' => $sku->barcode,
            'description' => $sku->description,
            'note' => mergeNote($sku->note, sourceSkuNote($source)),
            'url' => $sku->url ?: $source['source_url'],
            'is_active' => true,
            'sort_order' => $sku->exists ? $sku->sort_order : $index * 10,
        ])->save();

        DB::table('brand_catalogue_sku_variant_options')
            ->where('brand_catalogue_sku_id', $sku->id)
            ->delete();

        foreach ($variants as $variantName => $value) {
            $variant = $variantMap[$variantName] ?? null;
            $option = $optionMap[$variantName][$value] ?? null;
            if (! $variant || ! $option) {
                continue;
            }

            DB::table('brand_catalogue_sku_variant_options')->updateOrInsert(
                [
                    'brand_catalogue_sku_id' => $sku->id,
                    'brand_catalogue_variant_id' => $variant->id,
                ],
                [
                    'brand_catalogue_variant_option_id' => $option->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    return [$created, $updated];
}

function findOrCreateKuknusBrand(BrandCatalogue $catalogue): BrandCatalogueBrand
{
    $existing = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where(function ($query): void {
            $query->where('slug', 'kuknus')
                ->orWhere('name', 'Kuknus');
        })
        ->first();

    if ($existing) {
        return $existing;
    }

    $collection = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where('name', 'Kuknus Collection')
        ->first();

    if ($collection) {
        return $collection;
    }

    return BrandCatalogueBrand::query()->create([
        'brand_catalogue_id' => $catalogue->id,
        'name' => 'Kuknus',
        'slug' => scopedSlug(BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id), 'Kuknus'),
        'is_active' => true,
        'sort_order' => 90,
    ]);
}

function hideEmptyLegacyKuknusBrands(BrandCatalogue $catalogue, BrandCatalogueBrand $targetBrand): void
{
    $legacyBrands = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where('id', '!=', $targetBrand->id)
        ->whereIn('name', ['Kuknus Braid', 'Kuknus Collection'])
        ->get();

    foreach ($legacyBrands as $legacyBrand) {
        $hasNestedData = BrandCatalogueProductType::query()
            ->where('brand_catalogue_brand_id', $legacyBrand->id)
            ->exists()
            || BrandCatalogueStyle::query()
                ->where('brand_catalogue_brand_id', $legacyBrand->id)
                ->exists();

        if ($hasNestedData) {
            continue;
        }

        BrandCatalogueLine::query()
            ->where('brand_catalogue_brand_id', $legacyBrand->id)
            ->delete();

        $legacyBrand->fill([
            'is_active' => false,
            'note' => mergeNote($legacyBrand->note, 'Empty legacy Kuknus placeholder hidden after importing the clean Kuknus official-site catalogue.'),
        ])->save();
    }
}

/**
 * @param Collection<int, array<string, mixed>> $groupSkus
 */
function styleNote(Collection $groupSkus): string
{
    $sources = $groupSkus
        ->pluck('source')
        ->unique('product_id')
        ->values();

    $titles = $sources->pluck('title')->unique()->implode('; ');
    $categories = $sources
        ->flatMap(fn (array $source): array => collect($source['categories'])->pluck('name')->all())
        ->unique()
        ->implode('; ');

    return 'Reference family imported from official Kuknus website. Source products: '.$titles.'. Categories: '.$categories.'. Images are product-level source images and may not be colour-specific. Confirm shop stock before publishing retail products.';
}

/**
 * @param array<string, mixed> $source
 */
function sourceSkuNote(array $source): string
{
    $note = "Official Kuknus website source: {$source['title']}; product id {$source['product_id']}; source price GBP {$source['price']}.";

    if (empty($source['options']['Hair Color'])) {
        $note .= ' Variant review pending: no safe Hair Color dropdown was visible on the source page.';
    } else {
        $note .= ' Hair Color options are taken directly from the source dropdown.';
    }

    return $note;
}

function productTypeUrl(string $productType): string
{
    return match ($productType) {
        'Braiding Hair' => 'https://kuknus.co.uk/index.php?route=product/category&path=17',
        'Ponytails / Drawstrings' => 'https://kuknus.co.uk/index.php?route=product/category&path=34',
        'Half Wigs' => 'https://kuknus.co.uk/index.php?route=product/category&path=25_29',
        'Lace Wigs' => 'https://kuknus.co.uk/index.php?route=product/category&path=25_30',
        'Accessories' => 'https://kuknus.co.uk/index.php?route=product/category&path=59',
        default => 'https://kuknus.co.uk/',
    };
}

function productTypeSort(string $productType): int
{
    return match ($productType) {
        'Braiding Hair' => 10,
        'Ponytails / Drawstrings' => 20,
        'Wigs' => 30,
        'Half Wigs' => 40,
        'Lace Wigs' => 50,
        'Accessories' => 60,
        default => 900,
    };
}

function styleSort(string $family): int
{
    return (int) (crc32(Str::lower($family)) % 10000);
}

function fetchHtml(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-GB,en;q=0.9',
            ]),
            'timeout' => 30,
        ],
    ]);

    $html = @file_get_contents($url, false, $context);
    if ($html === false || trim($html) === '') {
        throw new RuntimeException("Could not fetch {$url}");
    }

    return $html;
}

function htmlXPath(string $html): DOMXPath
{
    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);

    return new DOMXPath($dom);
}

function firstText(DOMXPath $xpath, string $query): string
{
    $node = $xpath->query($query)->item(0);

    return $node ? cleanText($node->textContent) : '';
}

function firstChildText(DOMXPath $xpath, DOMNode $node, string $query): string
{
    $child = $xpath->query($query, $node)->item(0);

    return $child ? cleanText($child->textContent) : '';
}

function absoluteKuknusUrl(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);
    if ($url === '') {
        return '';
    }

    if (str_starts_with($url, '//')) {
        return 'https:'.$url;
    }

    if (str_starts_with($url, 'http://kuknus.co.uk')) {
        return 'https://'.substr($url, strlen('http://'));
    }

    if (str_starts_with($url, 'https://kuknus.co.uk') || str_starts_with($url, 'https://www.kuknus.co.uk')) {
        return $url;
    }

    if (str_starts_with($url, '/')) {
        return 'https://kuknus.co.uk'.$url;
    }

    return 'https://kuknus.co.uk/'.$url;
}

function productIdFromUrl(string $url): string
{
    $query = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5), PHP_URL_QUERY) ?: '';
    parse_str($query, $params);

    return isset($params['product_id']) ? (string) $params['product_id'] : '';
}

function priceNumber(string $price): string
{
    if (preg_match('/(\d+(?:\.\d{2})?)/', $price, $match)) {
        return $match[1];
    }

    return '';
}

function normaliseTitle(string $title): string
{
    $title = cleanText($title);
    $title = str_replace(['&amp;', '  '], ['&', ' '], $title);
    $title = preg_replace('/\s*\/\s*/', '/', $title) ?? $title;

    return cleanSpaces($title);
}

function descriptionForSource(string $description, string $title): string
{
    $description = cleanText($description);

    if ($description === '' || Str::lower($description) === Str::lower($title)) {
        return '';
    }

    return $description;
}

function normaliseOptionValue(string $value): string
{
    return cleanSpaces($value);
}

function normaliseColour(string $colour): string
{
    $colour = trim(cleanSpaces($colour));
    if ($colour === '') {
        return '';
    }

    $compact = preg_replace('/\s+/', '', $colour) ?? $colour;

    return Str::upper($compact);
}

/**
 * @param array<string, string> $variants
 * @return array<string, string>
 */
function orderVariants(array $variants): array
{
    uksort($variants, fn (string $a, string $b): int => variantSortOrder($a) <=> variantSortOrder($b));

    return $variants;
}

/**
 * @param array<string, string> $variants
 */
function optionSignature(array $variants): string
{
    return collect(orderVariants($variants))
        ->map(fn (string $value, string $name): string => $name.':'.$value)
        ->implode('|');
}

/**
 * @param array<string, string> $variants
 */
function skuName(string $family, array $variants): string
{
    $parts = collect(orderVariants($variants))
        ->map(fn (string $value, string $name): string => $name === 'Colour' ? 'Colour '.$value : $value)
        ->values()
        ->all();

    return cleanSpaces($family.($parts === [] ? '' : ' - '.implode(' - ', $parts)));
}

function variantSortOrder(string $name): int
{
    return match ($name) {
        'Length' => 10,
        'Pack' => 20,
        'Colour' => 50,
        'Review Status' => 900,
        default => 800,
    };
}

function variantType(string $name): string
{
    return match ($name) {
        'Length' => 'measurement',
        'Pack' => 'count',
        'Colour' => 'colour_code',
        default => 'text',
    };
}

function naturalSortKey(string $value): string
{
    if (preg_match('/^\d+(?:\.\d+)?/i', $value, $match)) {
        return str_pad((string) ((float) $match[0] * 100), 10, '0', STR_PAD_LEFT).Str::lower($value);
    }

    return Str::lower($value);
}

function uniqueBrandSlug(BrandCatalogue $catalogue, string $slug, ?int $ignoreId = null): string
{
    $base = Str::slug($slug) ?: 'kuknus';
    $candidate = $base;
    $suffix = 2;

    while (BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where('slug', $candidate)
        ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
        ->exists()) {
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

function cleanText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    $text = str_replace("\xc2\xa0", ' ', $text);

    return cleanSpaces($text);
}

function cleanSpaces(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function titleStyle(string $value): string
{
    $value = cleanSpaces($value);
    if ($value === '') {
        return '';
    }

    $words = preg_split('/\s+/', Str::lower($value)) ?: [];

    return cleanSpaces(implode(' ', array_map(function (string $word): string {
        if (preg_match('/^(?:hh|hb|ez|[2-9]x|gd\d+|tt[a-z0-9]+)$/i', $word)) {
            return Str::upper($word);
        }

        return collect(explode('-', $word))
            ->map(fn (string $part): string => collect(explode('/', $part))
                ->map(fn (string $slashPart): string => Str::ucfirst($slashPart))
                ->implode('/'))
            ->implode('-');
    }, $words)));
}
