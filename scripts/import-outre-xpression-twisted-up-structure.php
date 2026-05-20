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

$source = collectTwistedUpProducts();

if ($dryRun) {
    echo "Outre X-Pression Twisted Up dry run.\n";
    echo "Main brand: X-Pression\n";
    echo "Line/sub-brand: Outre\n";
    echo "Product type: X-Pression Twisted Up\n";
    echo 'Source products: '.$source->count()."\n";
    echo 'SKU variants: '.count(expandSkus($source->all()))."\n";

    foreach ($source as $product) {
        $variantSummary = collect(productVariantOptions($product))
            ->map(fn (array $values, string $name): string => $name.'='.implode(', ', $values))
            ->implode('; ');

        echo '- '.$product['style_name'].' | '.$product['base_sku'].' | '.($variantSummary ?: 'no variants parsed').' | '.$product['source_url']."\n";
    }

    exit(0);
}

$summary = DB::transaction(function () use ($source): array {
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
        'url' => 'https://feme.com/X-Pression-Wholesale-/',
        'note' => mergeNote($brand->note, 'Outre X-Pression Twisted Up line added from Feme for shop stock checking.'),
        'is_active' => true,
    ])->save();

    $line = BrandCatalogueLine::query()->firstOrCreate(
        [
            'brand_catalogue_brand_id' => $brand->id,
            'name' => 'Outre',
        ],
        [
            'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), 'Outre'),
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 60,
        ],
    );

    $line->fill([
        'url' => 'https://feme.com/brands/outre/x-pression-twisted-up/',
        'note' => mergeNote($line->note, 'Sub-brand/line under X-Pression. Twisted Up products imported from the Feme Outre category.'),
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 60,
    ])->save();

    $productType = BrandCatalogueProductType::query()->firstOrCreate(
        [
            'brand_catalogue_line_id' => $line->id,
            'name' => 'X-Pression Twisted Up',
        ],
        [
            'brand_catalogue_brand_id' => $brand->id,
            'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), 'X-Pression Twisted Up'),
            'is_active' => true,
            'sort_order' => 10,
        ],
    );

    $productType->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'url' => 'https://feme.com/brands/outre/x-pression-twisted-up/',
        'note' => mergeNote($productType->note, 'Outre X-Pression Twisted Up styles from Feme. Public Feme pages expose length/pack data, not a full colour matrix. Capture available shop colours through V2 intake.'),
        'is_active' => true,
        'sort_order' => 10,
    ])->save();

    $styleIds = [];
    $skuCount = 0;

    foreach ($source->values() as $styleIndex => $product) {
        $style = BrandCatalogueStyle::query()
            ->where('brand_catalogue_product_type_id', $productType->id)
            ->where('name', $product['style_name'])
            ->first();

        if (! $style) {
            $style = new BrandCatalogueStyle([
                'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $product['style_name']),
            ]);
        }

        $style->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $productType->id,
            'brand_catalogue_material_id' => null,
            'material_name' => materialName($product),
            'name' => $product['style_name'],
            'url' => $product['source_url'],
            'note' => styleNote($product),
            'is_active' => true,
            'sort_order' => ($styleIndex + 1) * 10,
        ])->save();

        syncStyleImages($style, $product);
        $skuCount += syncVariantsAndSkus($style, $product);
        $styleIds[] = $style->id;
    }

    return [
        'brand_id' => $brand->id,
        'line_id' => $line->id,
        'product_type_id' => $productType->id,
        'styles' => count(array_unique($styleIds)),
        'skus' => $skuCount,
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
        'line_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}/lines/{$line->id}"),
    ];
});

echo "Outre X-Pression Twisted Up imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return \Illuminate\Support\Collection<int, array<string, mixed>>
 */
function collectTwistedUpProducts()
{
    $categoryUrl = 'https://feme.com/brands/outre/x-pression-twisted-up/';
    $links = parseCategoryProductLinks($categoryUrl);

    if ($links === []) {
        $links = fallbackProductLinks();
    }

    $products = collect();

    foreach ($links as $index => $link) {
        $product = parseProductPage($link['url']);
        if ($product === null) {
            continue;
        }

        $product['style_sort'] = ($index + 1) * 10;
        $products->push($product);
    }

    return $products
        ->unique(fn (array $product): string => Str::lower($product['style_name'].'|'.$product['source_url']))
        ->values();
}

/**
 * @return array<int, array{title:string,url:string}>
 */
function parseCategoryProductLinks(string $url): array
{
    try {
        $xpath = htmlXPath(fetchHtml($url));
    } catch (Throwable) {
        return [];
    }

    $links = [];
    foreach ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' card-title ')]//a") as $node) {
        $title = cleanSpaces($node->textContent);
        $href = cleanSpaces($node->getAttribute('href'));

        if ($title === '' || $href === '' || ! Str::contains(Str::lower($title), 'twisted up')) {
            continue;
        }

        $links[] = ['title' => $title, 'url' => $href];
    }

    return $links;
}

/**
 * @return array<int, array{title:string,url:string}>
 */
function fallbackProductLinks(): array
{
    return [
        ['title' => 'Outre X-Pression Twisted Up - Swicy Afro Twist', 'url' => 'https://feme.com/outre-x-pression-twisted-up-swicy-afro-twist/'],
        ['title' => 'Outre X-Pression Twisted Up - LaLa Wandcurl', 'url' => 'https://feme.com/outre-x-pression-twisted-up-lala-wandcurl/'],
        ['title' => 'Outre X-Pression Twisted Up - LuLu Wandcurl', 'url' => 'https://feme.com/outre-x-pression-twisted-up-lulu-wandcurl/'],
        ['title' => 'Outre X-Pression Twisted Up - Boho Giana Locs', 'url' => 'https://feme.com/outre-x-pression-twisted-up-boho-giana-locs/'],
        ['title' => 'Outre X-Pression Twisted Up - Borabora Locs', 'url' => 'https://feme.com/outre-x-pression-twisted-up-borabora-locs/'],
        ['title' => 'Outre X-Pression Twisted Up - Springy Afro Twist', 'url' => 'https://feme.com/outre-x-pression-twisted-up-springy-afro-twist/'],
    ];
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
    $description = sourceDescription($xpath);
    $packCount = detectPackCount($title, $description, $images);

    return [
        'title' => cleanSpaces($title),
        'style_name' => styleNameFromTitle($title),
        'source_url' => $url,
        'base_sku' => blankToNull($info['SKU'] ?? parseBcDataSku($html)),
        'description' => customerSafeDescription($description),
        'raw_description' => $description,
        'options' => $options,
        'pack_count' => $packCount,
        'images' => $images,
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

function sourceDescription(DOMXPath $xpath): string
{
    $node = $xpath->query("//meta[@name='description']/@content")->item(0);
    if ($node) {
        return cleanSpaces($node->nodeValue);
    }

    return firstMeta($xpath, 'og:description');
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
        $labelNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' form-label ')]", $field)->item(0);
        $label = $labelNode ? cleanSpaces($labelNode->textContent) : '';
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

            if ($value !== '') {
                $values[] = $value;
            }
        }

        $options[normaliseVariantName($label)] = array_values(array_unique(array_filter($values)));
    }

    return array_filter($options, fn (array $values): bool => $values !== []);
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

function parseBcDataSku(string $html): ?string
{
    if (! preg_match('/BCData\s*=\s*(\{.*?\});/s', $html, $match)) {
        return null;
    }

    $data = json_decode($match[1], true);

    return blankToNull((string) data_get($data, 'product_attributes.sku'));
}

function styleNameFromTitle(string $title): string
{
    $style = preg_replace('/^Outre\s+X-Pression\s+Twisted\s+Up\s*-\s*/i', '', cleanSpaces($title)) ?? $title;

    return cleanSpaces($style);
}

function customerSafeDescription(string $description): ?string
{
    $description = cleanSpaces($description);
    $description = preg_replace('/\s*Become a stockist today!?/i', '', $description) ?? $description;
    $description = preg_replace('/\s*Contact us today to become a stockist\.?/i', '', $description) ?? $description;

    return blankToNull($description);
}

/**
 * @param array<int, string> $images
 */
function detectPackCount(string $title, string $description, array $images): ?string
{
    $haystack = cleanSpaces($title.' '.$description.' '.implode(' ', array_map(fn (string $url): string => basename(parse_url($url, PHP_URL_PATH) ?: $url), $images)));

    if (preg_match('/(?:^|[^A-Z0-9])([2-9])X(?:[^A-Z0-9]|$)/i', $haystack, $match)) {
        return strtoupper($match[1].'X');
    }

    return null;
}

function materialName(array $product): string
{
    $description = Str::lower((string) ($product['raw_description'] ?? ''));

    if (Str::contains($description, 'kanekalon')) {
        return 'Kanekalon Synthetic Fibre';
    }

    return 'Synthetic Fibre';
}

/**
 * @return array<string, array<int, string>>
 */
function productVariantOptions(array $product): array
{
    $result = [];

    foreach (($product['options'] ?? []) as $name => $values) {
        $result[normaliseVariantName($name)] = array_values(array_unique(array_map(
            fn (string $value): string => cleanVariantValue($name, $value),
            $values,
        )));
    }

    if (! empty($product['pack_count'])) {
        $result['Pack count'] = [$product['pack_count']];
    }

    foreach ($result as $name => $values) {
        $result[$name] = array_values(array_unique(array_filter($values)));
    }

    return array_filter($result, fn (array $values): bool => $values !== []);
}

/**
 * @param array<int, array<string, mixed>> $products
 * @return array<int, array{source:array<string,mixed>, variants:array<string,string>}>
 */
function expandSkus(array $products): array
{
    $expanded = [];

    foreach ($products as $product) {
        $sets = [[]];

        foreach (productVariantOptions($product) as $variantName => $values) {
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

    return $expanded;
}

function syncStyleImages(BrandCatalogueStyle $style, array $product): void
{
    $images = collect($product['images'] ?? [])
        ->filter()
        ->unique()
        ->values();

    foreach ($images as $index => $imageUrl) {
        CatalogueImage::query()->updateOrCreate(
            [
                'imageable_type' => BrandCatalogueStyle::class,
                'imageable_id' => $style->id,
                'external_url' => $imageUrl,
            ],
            [
                'image_role' => Str::contains(Str::lower($imageUrl), 'packaging') ? 'packaging' : 'source_image',
                'source_label' => 'Feme Outre X-Pression Twisted Up',
                'usage_context' => 'reference',
                'notes' => 'Feme reference image for Outre X-Pression Twisted Up '.$style->name.'.',
                'is_primary' => $index === 0,
                'sort_order' => $index * 10,
            ],
        );
    }
}

function syncVariantsAndSkus(BrandCatalogueStyle $style, array $product): int
{
    $records = expandSkus([$product]);
    $variantNames = collect($records)
        ->flatMap(fn (array $record): array => array_keys($record['variants']))
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

        $values = collect($records)
            ->map(fn (array $record): ?string => $record['variants'][$variantName] ?? null)
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

    foreach ($records as $index => $record) {
        $variants = orderVariants($record['variants']);
        $signature = optionSignature($variants);
        $expectedSignatures[] = $signature;
        $skuName = skuName('Outre X-Pression Twisted Up '.$style->name, $variants);

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
            'sku_code' => blankToNull($product['base_sku'] ?? null),
            'barcode' => null,
            'description' => $product['description'] ?? null,
            'note' => skuNote($product),
            'url' => $product['source_url'],
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
        ->where('note', 'like', 'Feme Outre X-Pression Twisted Up source:%')
        ->whereNotIn('option_signature', array_unique($expectedSignatures))
        ->delete();

    return count($records);
}

function styleNote(array $product): string
{
    $parts = [
        'Reference style imported from Feme Outre X-Pression Twisted Up page.',
        'Source title: '.$product['title'].'.',
    ];

    if (! empty($product['raw_description'])) {
        $parts[] = 'Source description: '.$product['raw_description'];
    }

    if (! empty($product['pack_count'])) {
        $parts[] = 'Pack count '.$product['pack_count'].' was detected from Feme image naming/source assets; review against physical packaging if critical.';
    }

    $parts[] = 'Public Feme page did not expose a complete colour matrix; capture available colours from shop V2 intake.';

    return implode(' ', $parts);
}

function skuNote(array $product): string
{
    $parts = [
        'Feme Outre X-Pression Twisted Up source: '.$product['title'].'.',
    ];

    if (! empty($product['base_sku'])) {
        $parts[] = 'Source SKU: '.$product['base_sku'].'.';
    }

    if (! empty($product['pack_count'])) {
        $parts[] = 'Pack count detected as '.$product['pack_count'].' from source assets; review if needed.';
    }

    $parts[] = 'Colour variants should be confirmed through shop intake.';

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
        'length' => 'Length',
        'pack', 'pack count', 'package' => 'Pack count',
        default => titleStyle($name),
    };
}

function cleanVariantValue(string $name, string $value): string
{
    $value = cleanSpaces(str_replace(['&quot;', '&#34;'], '"', $value));

    if (Str::lower($name) === 'pack count') {
        return Str::upper($value);
    }

    return $value;
}

function variantSortOrder(string $name): int
{
    return match (Str::lower($name)) {
        'pack count', 'bundle', 'pack', 'package' => 10,
        'length' => 20,
        'colour', 'color' => 50,
        default => 90,
    };
}

function variantType(string $name): string
{
    return match (Str::lower($name)) {
        'pack count', 'bundle', 'pack', 'package' => 'count',
        'length' => 'measurement',
        'colour', 'color' => 'colour_code',
        default => 'text',
    };
}

function naturalSortKey(string $value): string
{
    if (preg_match('/^(\d+)/', $value, $match)) {
        return str_pad($match[1], 10, '0', STR_PAD_LEFT).':'.Str::lower($value);
    }

    if (preg_match('/^(\d+)X$/i', $value, $match)) {
        return 'pack:'.str_pad($match[1], 5, '0', STR_PAD_LEFT);
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
            return Str::upper($trimmed);
        }

        return collect(explode('-', $trimmed))
            ->map(fn (string $part): string => Str::ucfirst($part))
            ->implode('-');
    }, $words);

    return cleanSpaces(implode(' ', $words));
}

function mergeNote(?string $existing, string $addition): string
{
    $existing = cleanSpaces((string) $existing);
    $addition = cleanSpaces($addition);

    if ($existing === '') {
        return $addition;
    }

    if (Str::contains($existing, $addition)) {
        return $existing;
    }

    return $existing.' '.$addition;
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

function cleanSpaces(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

