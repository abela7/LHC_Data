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
$subBrands = sensationnelSubBrands();
$sourceProducts = collectSourceProducts($subBrands);
$groups = groupSourceProducts($sourceProducts);

if ($dryRun) {
    echo "Sensationnel Feme dry run.\n";
    echo 'Sub-brands: '.count($subBrands)."\n";
    echo 'Source products: '.$sourceProducts->count()."\n";
    echo 'Style groups: '.$groups->count()."\n";
    echo 'SKU variants: '.$groups->sum(fn (array $group): int => count(expandGroupSkus($group['products'])))."\n\n";

    foreach ($groups as $group) {
        /** @var Collection<int, array<string, mixed>> $products */
        $products = $group['products'];
        echo "{$group['line']} > {$group['product_type']} > {$group['style']}\n";
        echo '  source pages: '.$products->count().' | sku variants: '.count(expandGroupSkus($products))."\n";
        foreach ($products as $product) {
            $optionSummary = collect(normaliseProductVariantOptions($product))
                ->map(fn (array $values, string $name): string => $name.'='.count($values))
                ->implode(', ');
            echo '  - '.$product['title'].' | '.$product['base_sku'].' | '.($optionSummary ?: 'no variants parsed').' | '.$product['source_url']."\n";
        }
    }

    exit(0);
}

$summary = DB::transaction(function () use ($groups): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();

    $brand = BrandCatalogueBrand::query()->firstOrCreate(
        [
            'brand_catalogue_id' => $catalogue->id,
            'name' => 'Sensationnel',
        ],
        [
            'slug' => scopedSlug(BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id), 'Sensationnel'),
            'is_active' => true,
            'sort_order' => 0,
        ],
    );

    $brand->fill([
        'note' => 'Reference structure imported from Feme for selected Sensationnel sub-brands. Confirm shop stock before publishing retail products.',
        'url' => 'https://feme.com/Sensationnel-Wholesale-/',
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
            'note' => 'Sensationnel sub-brand selected by shop owner; source: Feme.',
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
            'note' => 'Operational product type structured from Feme Sensationnel product pages.',
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
            'material_name' => $group['material'],
            'note' => styleNote($products),
            'url' => $products->first()['source_url'],
            'is_active' => true,
            'sort_order' => $group['style_sort'],
        ])->save();

        syncStyleImages($style, $products);
        $skuCount += syncStyleVariantsAndSkus($style, $group, $products);

        $styleIds[] = $style->id;
        $lineIds[] = $line->id;
        $productTypeIds[] = $productType->id;
    }

    cleanupDefaultLineIfEmpty($brand);

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

echo "Sensationnel Feme structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return array<int, array<string, mixed>>
 */
function sensationnelSubBrands(): array
{
    return [
        [
            'line' => 'Sensationnel Empire Bulk',
            'line_url' => 'https://feme.com/sensationnel/brands/empire-bulk/',
            'line_sort' => 10,
        ],
        [
            'line' => 'Sensationnel Goddess Select',
            'line_url' => 'https://feme.com/sensationnel/goddess-select/',
            'line_sort' => 20,
        ],
        [
            'line' => 'Sensationnel Premium Too Blend Hair',
            'line_url' => 'https://feme.com/premium-too-blend-hair/',
            'line_sort' => 30,
        ],
        [
            'line' => 'Sensationnel Premium Too Human Hair',
            'line_url' => 'https://feme.com/sensationnel/premium-too-human-hair/',
            'line_sort' => 40,
        ],
    ];
}

/**
 * @param array<int, array<string, mixed>> $subBrands
 * @return Collection<int, array<string, mixed>>
 */
function collectSourceProducts(array $subBrands): Collection
{
    $products = collect();
    $seen = [];

    foreach ($subBrands as $subBrand) {
        foreach (parseCategoryProductLinks($subBrand['line_url']) as $link) {
            $product = parseProductPage($link['url']);
            if ($product === null) {
                continue;
            }

            $product = array_merge($product, [
                'line' => $subBrand['line'],
                'line_url' => $subBrand['line_url'],
                'line_sort' => $subBrand['line_sort'],
                'category_title' => $link['title'],
            ]);

            $fingerprint = Str::lower(cleanSpaces($product['title'].'|'.$product['source_url']));
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

        if ($title === '' || $href === '') {
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

    return [
        'title' => cleanSpaces($title),
        'source_url' => $url,
        'base_sku' => blankToNull($info['SKU'] ?? null),
        'info' => $info,
        'options' => productOptions($xpath),
        'images' => productImages($xpath),
        'is_active' => ! truthyInfo($info['Inactive'] ?? null),
        'is_discontinued' => truthyInfo($info['Discontinued'] ?? null),
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

    foreach ($xpath->query("//*[@data-product-attribute]") as $field) {
        $label = firstChildText($xpath, $field, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' form-label ')]");

        if ($label === '') {
            $labelledBy = $field->attributes?->getNamedItem('aria-labelledby')?->nodeValue;
            if ($labelledBy) {
                $label = firstText($xpath, "//*[@id='{$labelledBy}']");
            }
        }

        $label = preg_replace('/\bRequired\b/i', '', $label) ?? $label;
        $label = normaliseVariantName(rtrim(cleanSpaces($label), ':'));

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

            if ($value !== '') {
                $values[] = cleanVariantValue($label, html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
            }
        }

        $options[$label] = array_values(array_unique(array_filter($values)));
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
            return array_merge($product, sensationnelStructure($product));
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
                'material' => $first['material'],
                'sku_prefix' => $first['sku_prefix'],
                'products' => $products->values(),
            ];
        })
        ->sortBy(fn (array $group): string => sprintf('%03d:%03d:%03d:%s', $group['line_sort'], $group['product_type_sort'], $group['style_sort'], $group['style']))
        ->values();
}

/**
 * @return array{product_type:string,product_type_sort:int,style:string,style_sort:int,material:string,sku_prefix:string}
 */
function sensationnelStructure(array $product): array
{
    $title = cleanSpaces($product['title']);
    $style = stripKnownPrefix($title);
    $productTypeRaw = Str::lower(cleanSpaces($product['info']['Product Type'] ?? ''));
    $hairType = cleanSpaces($product['info']['Hair Type'] ?? '');

    if (Str::contains(Str::lower($title), 'bulk') || Str::contains(Str::lower($product['line']), 'empire bulk')) {
        $productType = 'Bulk Hair';
    } elseif (
        Str::contains(Str::lower($title), ['weave', 'wvg', 'body', 'desire', 'curl', 'luxe'])
        || Str::contains(Str::lower($product['line']), ['goddess select', 'premium too'])
    ) {
        $productType = 'Weaves';
    } else {
        $productType = match ($productTypeRaw) {
            'bulk', 'braids' => 'Bulk Hair',
            'weave' => 'Weaves',
            default => titleStyle($productTypeRaw ?: 'Hair Extensions'),
        };
    }

    $productTypeSort = match ($productType) {
        'Bulk Hair' => 10,
        'Weaves' => 20,
        default => 90,
    };

    return [
        'product_type' => $productType,
        'product_type_sort' => $productTypeSort,
        'style' => $style,
        'style_sort' => 10,
        'material' => materialName($hairType),
        'sku_prefix' => 'Sensationnel '.$title,
    ];
}

function stripKnownPrefix(string $title): string
{
    $style = cleanSpaces($title);
    $style = preg_replace('/^Empire\s+Bulk\s*-\s*/i', '', $style) ?? $style;
    $style = preg_replace('/^Goddess\s+Select\s+Remi\s*-\s*/i', '', $style) ?? $style;
    $style = preg_replace('/^Premium\s+Too\s*-\s*/i', '', $style) ?? $style;

    return titleStyle($style);
}

function materialName(string $hairType): string
{
    $hairType = cleanSpaces($hairType);

    return match (Str::lower($hairType)) {
        'human hair' => 'Human Hair',
        'blend' => 'Human & Premium Blend Hair',
        'synthetic' => 'Synthetic Hair',
        default => $hairType !== '' ? titleStyle($hairType) : 'Hair',
    };
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

    foreach (($product['options'] ?? []) as $name => $values) {
        $name = normaliseVariantName($name);
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
                'notes' => 'Feme Sensationnel reference image for '.$style->name.'.',
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
    }

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
            'is_active' => true,
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
        ->where('note', 'like', 'Feme Sensationnel source:%')
        ->whereNotIn('option_signature', array_unique($expectedSignatures))
        ->delete();

    return count($expandedSkus);
}

function cleanupDefaultLineIfEmpty(BrandCatalogueBrand $brand): void
{
    BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('is_default', true)
        ->with('productTypes')
        ->get()
        ->each(function (BrandCatalogueLine $line): void {
            if ($line->productTypes()->count() === 0) {
                $line->delete();
            }
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

    $statusNotes = $products
        ->filter(fn (array $product): bool => $product['is_discontinued'] || ! $product['is_active'])
        ->map(fn (array $product): string => $product['title'].' source status needs review')
        ->unique()
        ->implode('; ');

    return cleanSpaces('Reference style imported from Feme Sensationnel product pages: '.$titles.'. Confirm shop stock before publishing retail products.'.($statusNotes ? ' '.$statusNotes.'.' : ''));
}

function skuNote(array $source): string
{
    $parts = [
        'Feme Sensationnel source: '.$source['title'].'.',
    ];

    if (! blankToNull($source['base_sku'] ?? null)) {
        $parts[] = 'Source SKU: '.$source['base_sku'].'.';
    }

    if ($source['is_discontinued'] ?? false) {
        $parts[] = 'Feme marks this source page as discontinued.';
    }

    if (! ($source['is_active'] ?? true)) {
        $parts[] = 'Feme marks this source page as inactive.';
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
    return match (Str::lower(cleanSpaces($name))) {
        'color' => 'Colour',
        'colour' => 'Colour',
        'length' => 'Length',
        default => titleStyle($name),
    };
}

function cleanVariantValue(string $name, string $value): string
{
    $value = cleanSpaces(str_replace(['&quot;', '&#34;'], '"', $value));

    if (Str::lower($name) === 'length' && preg_match('/^(\d+(?:\.\d+)?)(?:\D*)$/u', $value, $match)) {
        return $match[1];
    }

    if (Str::lower($name) === 'colour') {
        return normaliseColour($value);
    }

    return $value;
}

function normaliseColour(string $colour): string
{
    $colour = cleanSpaces(str_replace(['–', '—'], '-', $colour));
    $colour = preg_replace('/\s*\/\s*/', '/', $colour) ?? $colour;
    $colour = preg_replace('/\s*-\s*/', '-', $colour) ?? $colour;
    $lower = Str::lower($colour);

    $map = [
        '1b' => '1B',
        '99j' => '99J',
        'bg' => 'BG',
        'stk' => 'STK',
    ];

    if (isset($map[$lower])) {
        return $map[$lower];
    }

    if (preg_match('/^\d+[A-Za-z]?$/', $colour)) {
        return Str::upper($colour);
    }

    return Str::upper($colour) === $colour ? $colour : $colour;
}

function variantSortOrder(string $name): int
{
    return match (Str::lower($name)) {
        'length' => 20,
        'colour', 'color' => 50,
        default => 90,
    };
}

function variantType(string $name): string
{
    return match (Str::lower($name)) {
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

    return Str::lower($value);
}

function titleStyle(string $value): string
{
    $value = cleanSpaces($value);
    $words = explode(' ', Str::lower($value));
    $keepUpper = ['2x', '4x4', 'hd'];

    return cleanSpaces(implode(' ', array_map(function (string $word) use ($keepUpper): string {
        $trimmed = trim($word);
        if (in_array($trimmed, $keepUpper, true)) {
            return Str::upper($trimmed);
        }

        return collect(explode('-', $trimmed))
            ->map(fn (string $part): string => Str::ucfirst($part))
            ->implode('-');
    }, $words)));
}

function scopedSlug($query, string $name): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $i = 2;

    while ((clone $query)->where('slug', $slug)->exists()) {
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
