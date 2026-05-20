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

$sourceProducts = collectFemeXpressionProducts();
$groups = groupSourceProducts($sourceProducts);

if ($dryRun) {
    echo "X-Pression Feme dry run.\n";
    echo 'Source products: '.$sourceProducts->count()."\n";
    echo 'Style groups: '.$groups->count()."\n";

    foreach ($groups as $group) {
        /** @var Collection<int, array<string, mixed>> $products */
        $products = $group['products'];
        echo "\n{$group['line']} > {$group['product_type']} > {$group['style']}\n";
        echo '  source pages: '.$products->count()."\n";
        echo '  sku variants: '.count(expandGroupSkus($products))."\n";
        foreach ($products as $product) {
            $variantSummary = collect(normaliseProductVariantOptions($product))
                ->map(fn (array $values, string $name): string => $name.'='.count($values))
                ->implode(', ');
            echo '  - '.$product['title'].' | '.$product['base_sku'].' | '.($variantSummary ?: 'no variants parsed').' | '.$product['source_url']."\n";
        }
    }

    exit(0);
}

$summary = DB::transaction(function () use ($groups): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();

    $brand = BrandCatalogueBrand::query()->firstOrCreate(
        [
            'brand_catalogue_id' => $catalogue->id,
            'name' => 'X-Pression',
        ],
        [
            'slug' => scopedSlug(BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id), 'X-Pression'),
            'is_active' => true,
            'sort_order' => 0,
        ],
    );

    $brand->fill([
        'note' => 'Reference structure imported from Feme X-Pression Wholesale. Use for shop stock checking before publishing retail products.',
        'url' => 'https://feme.com/X-Pression-Wholesale-/',
        'is_active' => true,
    ])->save();

    $styleIds = [];
    $skuCount = 0;
    $lineIds = [];
    $productTypeIds = [];

    foreach ($groups as $group) {
        /** @var Collection<int, array<string, mixed>> $products */
        $products = $group['products'];

        $line = BrandCatalogueLine::query()->firstOrCreate(
            [
                'brand_catalogue_brand_id' => $brand->id,
                'name' => $group['line'],
            ],
            [
                'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), $group['line']),
                'is_default' => false,
                'is_active' => true,
            ],
        );

        $line->fill([
            'note' => 'Feme X-Pression source category.',
            'url' => $group['line_url'],
            'is_default' => false,
            'is_active' => true,
            'sort_order' => $group['line_sort'],
        ])->save();

        $productType = BrandCatalogueProductType::query()->firstOrCreate(
            [
                'brand_catalogue_line_id' => $line->id,
                'name' => $group['product_type'],
            ],
            [
                'brand_catalogue_brand_id' => $brand->id,
                'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $group['product_type']),
                'is_active' => true,
            ],
        );

        $productType->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'note' => 'Operational product type structured from Feme X-Pression product pages.',
            'url' => $group['line_url'],
            'is_active' => true,
            'sort_order' => $group['product_type_sort'],
        ])->save();

        $style = findOrCreateStyle($brand, $productType, $group['style']);
        $style->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $productType->id,
            'brand_catalogue_material_id' => null,
            'name' => $group['style'],
            'material_name' => 'Synthetic Hair',
            'note' => styleNote($products),
            'url' => $products->first()['source_url'],
            'is_active' => $products->contains(fn (array $product): bool => $product['is_active']),
            'sort_order' => $group['style_sort'],
        ])->save();

        syncStyleImages($style, $products);
        $skuCount += syncStyleVariantsAndSkus($style, $group, $products);

        $styleIds[] = $style->id;
        $lineIds[] = $line->id;
        $productTypeIds[] = $productType->id;
    }

    cleanupLegacyXpressionPlaceholders($brand);

    return [
        'brand_id' => $brand->id,
        'lines' => count(array_unique($lineIds)),
        'product_types' => count(array_unique($productTypeIds)),
        'styles' => count(array_unique($styleIds)),
        'skus' => $skuCount,
        'retail_products' => 0,
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
    ];
});

echo "X-Pression Feme structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function collectFemeXpressionProducts(): Collection
{
    $categories = [
        [
            'key' => 'braids',
            'line' => 'X-Pression Braids',
            'line_url' => 'https://feme.com/brands/x-pression/braids/',
            'line_sort' => 10,
        ],
        [
            'key' => 'crochet_braids',
            'line' => 'X-Pression Crochet Braids',
            'line_url' => 'https://feme.com/crochet-braids/',
            'line_sort' => 20,
        ],
        [
            'key' => 'weave_on',
            'line' => 'X-Pression Weave On',
            'line_url' => 'https://feme.com/weave-on/',
            'line_sort' => 30,
        ],
    ];

    $products = collect();
    $seen = [];

    foreach ($categories as $category) {
        $links = parseCategoryProductLinks($category['line_url']);

        foreach ($links as $link) {
            $product = parseProductPage($link['url']);
            if ($product === null) {
                continue;
            }

            $product['category_key'] = $category['key'];
            $product['line'] = $category['line'];
            $product['line_url'] = $category['line_url'];
            $product['line_sort'] = $category['line_sort'];
            $product['category_title'] = $link['title'];

            $fingerprint = Str::lower(cleanSpaces(($product['title'] ?? '').'|'.($product['base_sku'] ?? '').'|'.json_encode($product['options'])));
            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $products->push($product);
        }
    }

    return $products;
}

/**
 * @return array<int, array{title:string,url:string}>
 */
function parseCategoryProductLinks(string $url): array
{
    $html = fetchHtml($url);
    $xpath = htmlXPath($html);
    $links = [];

    foreach ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' card-title ')]//a") as $node) {
        $title = cleanSpaces($node->textContent);
        $href = cleanSpaces($node->getAttribute('href'));

        if ($title === '' || $href === '' || ! str_contains(Str::lower($title), 'x-pression')) {
            continue;
        }

        $links[] = [
            'title' => $title,
            'url' => $href,
        ];
    }

    return $links;
}

/**
 * @return array<string, mixed>|null
 */
function parseProductPage(string $url): ?array
{
    try {
        $html = fetchHtml($url);
    } catch (Throwable $exception) {
        fwrite(STDERR, "Skipped {$url}: {$exception->getMessage()}\n");

        return null;
    }

    $xpath = htmlXPath($html);
    $title = firstText($xpath, "//*[contains(concat(' ', normalize-space(@class), ' '), ' productView-title ')]")
        ?: firstMeta($xpath, 'og:title');

    if ($title === '') {
        return null;
    }

    $info = productInfoPairs($xpath);
    $options = productOptions($xpath);
    $images = productImages($xpath);

    return [
        'title' => cleanSpaces($title),
        'source_url' => $url,
        'base_sku' => blankToNull($info['SKU'] ?? null),
        'info' => $info,
        'options' => $options,
        'images' => $images,
        'is_active' => ! truthyInfo($info['Inactive'] ?? null) && ! truthyInfo($info['Discontinued'] ?? null),
    ];
}

function fetchHtml(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Mozilla/5.0\r\nAccept: text/html\r\n",
            'timeout' => 30,
        ],
    ]);

    $html = @file_get_contents($url, false, $context);
    if ($html === false || trim($html) === '') {
        throw new RuntimeException('empty response');
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

    return $node ? cleanSpaces($node->textContent) : '';
}

function firstMeta(DOMXPath $xpath, string $property): string
{
    $node = $xpath->query("//meta[@property='{$property}']/@content")->item(0);

    return $node ? cleanSpaces($node->nodeValue) : '';
}

/**
 * @return array<string, string>
 */
function productInfoPairs(DOMXPath $xpath): array
{
    $pairs = [];
    $names = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' productView-info-name ')]");

    foreach ($names as $nameNode) {
        $valueNode = $nameNode->nextSibling;
        while ($valueNode && $valueNode->nodeType !== XML_ELEMENT_NODE) {
            $valueNode = $valueNode->nextSibling;
        }

        if (! $valueNode) {
            continue;
        }

        $key = rtrim(cleanSpaces($nameNode->textContent), ':');
        $value = cleanSpaces($valueNode->textContent);

        if ($key !== '') {
            $pairs[$key] = $value;
        }
    }

    return $pairs;
}

/**
 * @return array<string, array<int, string>>
 */
function productOptions(DOMXPath $xpath): array
{
    $options = [];
    $fields = $xpath->query("//*[@data-product-attribute]");

    foreach ($fields as $field) {
        $label = firstChildText($xpath, $field, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' form-label ')]");

        if ($label === '') {
            $labelledBy = $field->attributes?->getNamedItem('aria-labelledby')?->nodeValue;
            if ($labelledBy) {
                $label = firstText($xpath, "//*[@id='{$labelledBy}']");
            }
        }

        $label = preg_replace('/\bRequired\b/i', '', $label) ?? $label;
        $label = rtrim(cleanSpaces($label), ':');

        if ($label === '') {
            continue;
        }

        $values = [];
        foreach ($xpath->query(".//label[contains(concat(' ', normalize-space(@class), ' '), ' form-option ')]", $field) as $optionNode) {
            $valueNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' form-option-variant ')]", $optionNode)->item(0);
            $value = '';

            if ($valueNode) {
                $value = cleanSpaces($valueNode->attributes?->getNamedItem('title')?->nodeValue ?? '');
                if ($value === '') {
                    $value = cleanSpaces($valueNode->textContent);
                }
            }

            if ($value === '') {
                $for = $optionNode->attributes?->getNamedItem('for')?->nodeValue;
                if ($for) {
                    $input = $xpath->query("//*[@id='{$for}']")->item(0);
                    $value = cleanSpaces($input?->attributes?->getNamedItem('aria-label')?->nodeValue ?? '');
                }
            }

            if ($value !== '') {
                $values[] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
            }
        }

        $options[normaliseVariantName($label)] = array_values(array_unique(array_map('cleanSpaces', $values)));
    }

    return array_filter($options, fn (array $values): bool => $values !== []);
}

function firstChildText(DOMXPath $xpath, DOMNode $context, string $query): string
{
    $node = $xpath->query($query, $context)->item(0);

    return $node ? cleanSpaces($node->textContent) : '';
}

/**
 * @return array<int, string>
 */
function productImages(DOMXPath $xpath): array
{
    $images = [];

    foreach ($xpath->query("//a[@data-image-gallery-item]/@href") as $href) {
        $images[] = cleanSpaces($href->nodeValue);
    }

    $ogImage = firstMeta($xpath, 'og:image');
    if ($ogImage !== '') {
        $images[] = $ogImage;
    }

    return array_values(array_unique(array_filter($images)));
}

/**
 * @param Collection<int, array<string, mixed>> $products
 * @return Collection<int, array<string, mixed>>
 */
function groupSourceProducts(Collection $products): Collection
{
    return $products
        ->map(function (array $product): array {
            return array_merge($product, xpressionStructure($product));
        })
        ->groupBy(fn (array $product): string => implode('|', [$product['line'], $product['product_type'], $product['style']]))
        ->map(function (Collection $products): array {
            $first = $products->first();

            return [
                'line' => $first['line'],
                'line_url' => $first['line_url'],
                'line_sort' => $first['line_sort'],
                'product_type' => $first['product_type'],
                'product_type_sort' => $first['product_type_sort'],
                'style' => $first['style'],
                'style_sort' => $first['style_sort'],
                'sku_prefix' => $first['sku_prefix'],
                'products' => $products->values(),
            ];
        })
        ->sortBy(fn (array $group): string => sprintf('%03d:%03d:%03d:%s', $group['line_sort'], $group['product_type_sort'], $group['style_sort'], $group['style']))
        ->values();
}

/**
 * @return array{product_type:string, product_type_sort:int, style:string, style_sort:int, sku_prefix:string, fixed_variants:array<string,string>}
 */
function xpressionStructure(array $product): array
{
    $title = cleanSpaces($product['title']);
    $simple = cleanSpaces(preg_replace('/^X-Pression\s+(?:Weave On\s*)?-\s*/i', '', $title) ?? $title);
    $simple = cleanSpaces(preg_replace('/^X-Pression\s+Weave On\s*/i', '', $simple) ?? $simple);
    $simple = ltrim($simple, '- ');
    $lower = Str::lower($simple);
    $category = $product['category_key'];
    $fixed = [];

    if ($category === 'weave_on') {
        $style = titleStyle($simple);

        return [
            'product_type' => 'Synthetic Weaves',
            'product_type_sort' => 10,
            'style' => $style,
            'style_sort' => 10,
            'sku_prefix' => 'X-Pression Weave On '.$style,
            'fixed_variants' => [],
        ];
    }

    if (preg_match('/^pre-stretched\s+(2x|3x|6x)\s+(\d+)/i', $simple, $match)) {
        return [
            'product_type' => 'Pre-Stretched Braiding Hair',
            'product_type_sort' => 10,
            'style' => 'Pre-Stretched',
            'style_sort' => 10,
            'sku_prefix' => 'X-Pression Pre-Stretched',
            'fixed_variants' => [
                'Bundle' => strtoupper($match[1]),
                'Length' => $match[2],
            ],
        ];
    }

    if (Str::contains($lower, 'pre-stretched')) {
        return [
            'product_type' => 'Pre-Stretched Braiding Hair',
            'product_type_sort' => 10,
            'style' => titleStyle($simple),
            'style_sort' => 20,
            'sku_prefix' => 'X-Pression '.titleStyle($simple),
            'fixed_variants' => [],
        ];
    }

    if ($category === 'crochet_braids') {
        if (preg_match('/^box braid\s+(small|large)/i', $simple, $match)) {
            $fixed['Braid Size'] = titleStyle($match[1]);

            return [
                'product_type' => 'Crochet Braids',
                'product_type_sort' => 10,
                'style' => 'Box Braid',
                'style_sort' => 10,
                'sku_prefix' => 'X-Pression Box Braid',
                'fixed_variants' => $fixed,
            ];
        }

        if (preg_match('/^senegalese twist\s+(.+)$/i', $simple, $match)) {
            return [
                'product_type' => 'Crochet Braids',
                'product_type_sort' => 10,
                'style' => 'Senegalese Twist',
                'style_sort' => 20,
                'sku_prefix' => 'X-Pression Senegalese Twist',
                'fixed_variants' => [
                    'Twist Size' => titleStyle($match[1]),
                ],
            ];
        }

        return [
            'product_type' => 'Crochet Braids',
            'product_type_sort' => 10,
            'style' => titleStyle($simple),
            'style_sort' => 30,
            'sku_prefix' => 'X-Pression '.titleStyle($simple),
            'fixed_variants' => [],
        ];
    }

    if (Str::contains($lower, 'faux lock') || Str::contains($lower, 'faux loc')) {
        return [
            'product_type' => 'Faux Locs',
            'product_type_sort' => 30,
            'style' => titleStyle($simple),
            'style_sort' => 30,
            'sku_prefix' => 'X-Pression '.titleStyle($simple),
            'fixed_variants' => [],
        ];
    }

    if (Str::contains($lower, 'twist')) {
        return [
            'product_type' => 'Twist Hair',
            'product_type_sort' => 20,
            'style' => titleStyle($simple),
            'style_sort' => 20,
            'sku_prefix' => 'X-Pression '.titleStyle($simple),
            'fixed_variants' => [],
        ];
    }

    return [
        'product_type' => 'Bulk Braiding Hair',
        'product_type_sort' => 40,
        'style' => titleStyle($simple),
        'style_sort' => 40,
        'sku_prefix' => 'X-Pression '.titleStyle($simple),
        'fixed_variants' => [],
    ];
}

/**
 * @param Collection<int, array<string, mixed>> $products
 * @return array<int, array{source:array<string,mixed>, variants:array<string,string>}>
 */
function expandGroupSkus(Collection $products): array
{
    $expanded = [];

    foreach ($products as $product) {
        $sets = [[]];
        foreach (normaliseProductVariantOptions($product) as $variantName => $values) {
            $next = [];
            foreach ($sets as $set) {
                foreach ($values as $value) {
                    $next[] = array_merge($set, [$variantName => $value]);
                }
            }
            $sets = $next;
        }

        foreach ($sets as $set) {
            $expanded[] = [
                'source' => $product,
                'variants' => $set,
            ];
        }
    }

    return dedupeExpandedSkus($expanded);
}

/**
 * @return array<string, array<int, string>>
 */
function normaliseProductVariantOptions(array $product): array
{
    $result = [];

    foreach (($product['fixed_variants'] ?? []) as $name => $value) {
        $result[normaliseVariantName($name)] = [cleanVariantValue($name, $value)];
    }

    foreach (($product['options'] ?? []) as $name => $values) {
        $name = normaliseVariantName($name);

        if ($name === 'Length') {
            foreach ($values as $value) {
                foreach (splitLengthBundle($value) as $splitName => $splitValue) {
                    $result[$splitName] ??= [];
                    $result[$splitName][] = $splitValue;
                }
            }

            continue;
        }

        foreach ($values as $value) {
            $result[$name] ??= [];
            $result[$name][] = cleanVariantValue($name, $value);
        }
    }

    foreach ($result as $name => $values) {
        $result[$name] = array_values(array_unique(array_filter($values)));
    }

    return array_filter($result, fn (array $values): bool => $values !== []);
}

/**
 * @return array<string, string>
 */
function splitLengthBundle(string $value): array
{
    $value = cleanSpaces(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));

    if (preg_match('/^(\d+)\s*x\s*(\d+(?:\.\d+)?)\s*(?:\"|in|inch|inches)?$/i', $value, $match)) {
        return [
            'Bundle' => $match[1].'x',
            'Length' => cleanVariantValue('Length', $match[2]),
        ];
    }

    return [
        'Length' => cleanVariantValue('Length', $value),
    ];
}

function cleanVariantValue(string $name, string $value): string
{
    $value = cleanSpaces(str_replace(['&quot;', '&#34;'], '"', $value));

    if (Str::lower($name) === 'bundle') {
        return Str::lower($value);
    }

    if (Str::lower($name) === 'length' && preg_match('/^(\d+(?:\.\d+)?)(?:\D*)$/u', $value, $match)) {
        return $match[1];
    }

    return $value;
}

/**
 * @param array<int, array{source:array<string,mixed>, variants:array<string,string>}> $expanded
 * @return array<int, array{source:array<string,mixed>, variants:array<string,string>}>
 */
function dedupeExpandedSkus(array $expanded): array
{
    $seen = [];
    $result = [];

    foreach ($expanded as $sku) {
        $signature = optionSignature($sku['variants']);
        if (isset($seen[$signature])) {
            continue;
        }

        $seen[$signature] = true;
        $result[] = $sku;
    }

    return $result;
}

function findOrCreateStyle(BrandCatalogueBrand $brand, BrandCatalogueProductType $productType, string $styleName): BrandCatalogueStyle
{
    $style = BrandCatalogueStyle::query()
        ->where('brand_catalogue_product_type_id', $productType->id)
        ->where('name', $styleName)
        ->first();

    if ($style) {
        return $style;
    }

    $legacyStyle = BrandCatalogueStyle::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', $styleName)
        ->whereDoesntHave('skus')
        ->first();

    if ($legacyStyle) {
        $legacyStyle->slug = scopedSlug(
            BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id),
            $styleName,
            $legacyStyle->id,
        );

        return $legacyStyle;
    }

    return new BrandCatalogueStyle([
        'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $styleName),
    ]);
}

/**
 * @param Collection<int, array<string, mixed>> $products
 */
function syncStyleImages(BrandCatalogueStyle $style, Collection $products): void
{
    $imageUrls = $products
        ->flatMap(fn (array $product): array => $product['images'] ?? [])
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
                'source_label' => 'Feme',
                'usage_context' => 'reference',
                'notes' => 'Feme X-Pression reference image for '.$style->name.'.',
                'is_primary' => ! $hasPrimary && $index === 0,
                'sort_order' => $index * 10,
            ],
        );
    }
}

/**
 * @param Collection<int, array<string, mixed>> $products
 */
function syncStyleVariantsAndSkus(BrandCatalogueStyle $style, array $group, Collection $products): int
{
    $expandedSkus = expandGroupSkus($products);

    $variantNames = collect($expandedSkus)
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

        $values = collect($expandedSkus)
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

        pruneUnlinkedStaleOptions($variant, $values->all());
    }

    pruneUnlinkedStaleVariants($style, $variantNames->all());

    $expectedSignatures = [];
    foreach ($expandedSkus as $index => $sourceSku) {
        $variants = orderVariants($sourceSku['variants']);
        $signature = optionSignature($variants);
        $expectedSignatures[] = $signature;
        $source = $sourceSku['source'];

        $skuName = skuName($group['sku_prefix'], $variants);
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
        }

        $sku->fill([
            'name' => $skuName,
            'sku_code' => blankToNull($source['base_sku'] ?? null),
            'barcode' => null,
            'description' => null,
            'note' => skuNote($source),
            'url' => $source['source_url'],
            'is_active' => (bool) $source['is_active'],
            'sort_order' => $index * 10,
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

    BrandCatalogueSku::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->where('note', 'like', 'Feme X-Pression source:%')
        ->whereNotIn('option_signature', array_unique($expectedSignatures))
        ->delete();

    return count($expandedSkus);
}

function cleanupLegacyXpressionPlaceholders(BrandCatalogueBrand $brand): void
{
    $legacyStyles = BrandCatalogueStyle::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->whereIn('name', ['Size'])
        ->whereDoesntHave('skus')
        ->whereDoesntHave('images')
        ->get();

    foreach ($legacyStyles as $style) {
        $productType = $style->productType;
        $style->delete();

        if ($productType && $productType->styles()->count() === 0) {
            $line = $productType->line;
            $productType->delete();

            if ($line && $line->is_default && $line->productTypes()->count() === 0) {
                $line->delete();
            }
        }
    }

    BrandCatalogueProductType::query()
        ->whereHas('line', fn ($query) => $query
            ->where('brand_catalogue_brand_id', $brand->id)
            ->where('is_default', true))
        ->with('line')
        ->get()
        ->each(function (BrandCatalogueProductType $productType): void {
            if ($productType->styles()->count() > 0) {
                return;
            }

            $line = $productType->line;
            $productType->delete();

            if ($line && $line->is_default && $line->productTypes()->count() === 0) {
                $line->delete();
            }
        });
}

/**
 * @param array<int, string> $expectedLabels
 */
function pruneUnlinkedStaleOptions(BrandCatalogueVariant $variant, array $expectedLabels): void
{
    BrandCatalogueVariantOption::query()
        ->where('variant_id', $variant->id)
        ->whereNotIn('label', $expectedLabels)
        ->get()
        ->each(function (BrandCatalogueVariantOption $option): void {
            $isLinked = DB::table('brand_catalogue_sku_variant_options')
                ->where('brand_catalogue_variant_option_id', $option->id)
                ->exists();

            if (! $isLinked) {
                $option->delete();
            }
        });
}

/**
 * @param array<int, string> $expectedNames
 */
function pruneUnlinkedStaleVariants(BrandCatalogueStyle $style, array $expectedNames): void
{
    BrandCatalogueVariant::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->whereNotIn('name', $expectedNames)
        ->with('options')
        ->get()
        ->each(function (BrandCatalogueVariant $variant): void {
            $isLinked = DB::table('brand_catalogue_sku_variant_options')
                ->where('brand_catalogue_variant_id', $variant->id)
                ->exists();

            if ($isLinked) {
                return;
            }

            $variant->options()->delete();
            $variant->delete();
        });
}

/**
 * @param Collection<int, array<string, mixed>> $products
 */
function styleNote(Collection $products): string
{
    $titles = $products
        ->pluck('title')
        ->unique()
        ->implode('; ');

    return 'Reference style imported from Feme X-Pression product pages: '.$titles.'. Confirm shop stock before publishing retail products.';
}

function skuNote(array $source): string
{
    $parts = [
        'Feme X-Pression source: '.$source['title'].'.',
    ];

    if (! blankToNull($source['base_sku'] ?? null)) {
        $parts[] = 'Source SKU: '.$source['base_sku'].'.';
    }

    if (! ($source['is_active'] ?? true)) {
        $parts[] = 'Feme marks this source page as inactive or discontinued.';
    }

    return implode(' ', $parts);
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
    $parts = [];
    foreach (orderVariants($variants) as $name => $value) {
        $parts[] = $name.':'.$value;
    }

    return implode('|', $parts);
}

/**
 * @param array<string, string> $variants
 */
function skuName(string $prefix, array $variants): string
{
    $parts = [];
    foreach (orderVariants($variants) as $name => $value) {
        if (Str::lower($name) === 'colour') {
            $parts[] = 'Colour '.$value;
            continue;
        }

        $parts[] = $value;
    }

    return cleanSpaces($prefix.($parts === [] ? '' : ' - '.implode(' - ', $parts)));
}

function normaliseVariantName(string $name): string
{
    $name = cleanSpaces($name);

    return match (Str::lower($name)) {
        'color' => 'Colour',
        'colour' => 'Colour',
        'size' => 'Size',
        'length' => 'Length',
        default => titleStyle($name),
    };
}

function variantSortOrder(string $name): int
{
    return match (Str::lower($name)) {
        'bundle', 'pack', 'package' => 10,
        'length' => 20,
        'braid size', 'twist size', 'size' => 30,
        'loop type' => 40,
        'colour', 'color' => 50,
        default => 90,
    };
}

function variantType(string $name): string
{
    return match (Str::lower($name)) {
        'bundle', 'pack', 'package' => 'count',
        'length' => 'measurement',
        'colour', 'color' => 'colour_code',
        default => 'text',
    };
}

function naturalSortKey(string $value): string
{
    if (preg_match('/^\d+(?:\.\d+)?$/', $value)) {
        return str_pad((string) ((float) $value * 100), 10, '0', STR_PAD_LEFT);
    }

    if (preg_match('/^(\d+)x$/i', $value, $match)) {
        return 'bundle:'.str_pad($match[1], 5, '0', STR_PAD_LEFT);
    }

    return Str::lower($value);
}

function titleStyle(string $value): string
{
    $value = cleanSpaces($value);
    $words = explode(' ', Str::lower($value));
    $keepUpper = ['x', 'xl', 'hd', 'vp'];

    $words = array_map(function (string $word) use ($keepUpper): string {
        $trimmed = trim($word);
        if (in_array($trimmed, $keepUpper, true)) {
            return Str::upper($trimmed);
        }

        if (preg_match('/^\d+x$/', $trimmed)) {
            return $trimmed;
        }

        return collect(explode('-', $trimmed))
            ->map(fn (string $part): string => Str::ucfirst($part))
            ->implode('-');
    }, $words);

    return cleanSpaces(implode(' ', $words));
}

function scopedSlug($query, string $name, ?int $ignoreId = null): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $i = 2;

    while ((clone $query)
        ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
        ->where('slug', $slug)
        ->exists()) {
        $slug = $base.'-'.$i;
        $i++;
    }

    return $slug;
}

function blankToNull(?string $value): ?string
{
    $value = trim((string) $value);

    return $value === '' ? null : $value;
}

function truthyInfo(?string $value): bool
{
    return in_array(Str::lower(cleanSpaces((string) $value)), ['true', 'yes', '1'], true);
}

function cleanSpaces(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}
