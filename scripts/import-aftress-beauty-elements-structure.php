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

$sourceProducts = collectBeautyElementsAftressProducts();
$groups = groupSourceProducts($sourceProducts);

if ($dryRun) {
    echo "AFTRESS Beauty Elements dry run.\n";
    echo 'Source products: '.$sourceProducts->count()."\n";
    echo 'Style groups: '.$groups->count()."\n";
    echo 'SKU variants: '.$groups->sum(fn (array $group): int => count(expandGroupSkus($group['products'])))."\n\n";

    foreach ($groups as $group) {
        /** @var Collection<int, array<string, mixed>> $products */
        $products = $group['products'];
        echo "{$group['line']} > {$group['product_type']} > {$group['style']}\n";
        echo '  source pages: '.$products->count().' | sku variants: '.count(expandGroupSkus($products))."\n";

        foreach ($products as $product) {
            $optionSummary = collect($product['options'])
                ->map(fn (array $values, string $name): string => $name.'='.count($values))
                ->implode(', ');
            echo '  - '.$product['source_title'].' | '.($optionSummary ?: 'variant review pending').' | '.$product['source_url']."\n";
        }
    }

    exit(0);
}

$summary = DB::transaction(function () use ($groups): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();

    $brand = BrandCatalogueBrand::query()->firstOrCreate(
        [
            'brand_catalogue_id' => $catalogue->id,
            'name' => 'Aftress',
        ],
        [
            'slug' => scopedSlug(BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id), 'Aftress'),
            'is_active' => true,
            'sort_order' => 20,
        ],
    );

    $brand->fill([
        'note' => mergeNote($brand->note, 'Reference structure imported from Beauty Elements AFTRESS catalogue. Confirm shop stock before publishing retail products.'),
        'url' => $brand->url ?: aftressBrandUrl(),
        'is_active' => true,
    ])->save();

    $lineIds = [];
    $productTypeIds = [];
    $styleIds = [];
    $createdSkus = 0;
    $updatedSkus = 0;

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
                'is_default' => $group['line'] === 'Aftress',
                'is_active' => true,
            ],
        );

        $line->fill([
            'note' => mergeNote($line->note, 'Beauty Elements AFTRESS source line.'),
            'url' => $line->url ?: $group['line_url'],
            'is_default' => $group['line'] === 'Aftress',
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
            'note' => mergeNote($productType->note, 'Operational product type structured from Beauty Elements AFTRESS product data.'),
            'url' => $productType->url ?: productTypeUrl($group['product_type']),
            'is_active' => true,
            'sort_order' => $group['product_type_sort'],
        ])->save();

        $style = findOrCreateStyle($productType, $group['style']);
        $style->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $productType->id,
            'brand_catalogue_material_id' => null,
            'name' => $group['style'],
            'material_name' => $style->material_name ?: $group['material'],
            'note' => mergeNote($style->note, styleNote($products)),
            'url' => $style->url ?: $products->first()['source_url'],
            'is_active' => true,
            'sort_order' => $style->exists ? $style->sort_order : $group['style_sort'],
        ])->save();

        syncStyleImages($style, $products);
        [$created, $updated] = syncStyleVariantsAndSkus($style, $group, $products);

        $createdSkus += $created;
        $updatedSkus += $updated;
        $lineIds[] = $line->id;
        $productTypeIds[] = $productType->id;
        $styleIds[] = $style->id;
    }

    return [
        'brand_id' => $brand->id,
        'lines_touched' => count(array_unique($lineIds)),
        'product_types_touched' => count(array_unique($productTypeIds)),
        'styles_touched' => count(array_unique($styleIds)),
        'skus_created' => $createdSkus,
        'skus_updated' => $updatedSkus,
        'retail_products' => 0,
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
    ];
});

echo "AFTRESS Beauty Elements structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

function aftressBrandUrl(): string
{
    return 'http://beautyelements.co/brand/brand-aftress/';
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function collectBeautyElementsAftressProducts(): Collection
{
    $links = parseBeautyElementsBrandGrid(aftressBrandUrl());
    $products = collect();
    $seen = [];

    foreach ($links as $link) {
        $product = parseBeautyElementsProductPage($link);

        if ($product === null) {
            continue;
        }

        $fingerprint = Str::lower($product['source_url']);
        if (isset($seen[$fingerprint])) {
            continue;
        }

        $seen[$fingerprint] = true;
        $products->push($product);
    }

    return $products;
}

/**
 * @return array<int, array<string, mixed>>
 */
function parseBeautyElementsBrandGrid(string $url): array
{
    $html = fetchHtml($url);
    $xpath = htmlXPath($html);
    $links = [];

    foreach ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' grid-entry ')]") as $entry) {
        $excerpt = firstChildText($xpath, $entry, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' grid-entry-excerpt ')]");
        if (Str::upper($excerpt) !== 'AFTRESS') {
            continue;
        }

        $anchor = $xpath->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' grid-image ')]", $entry)->item(0);
        if (! $anchor instanceof DOMElement) {
            continue;
        }

        $href = absoluteUrl($anchor->getAttribute('href'));
        $title = cleanText($anchor->getAttribute('title') ?: $anchor->textContent);

        if ($href === '' || $title === '') {
            continue;
        }

        $imageNode = $xpath->query(".//img/@src", $entry)->item(0);
        $links[] = [
            'url' => $href,
            'grid_title' => $title,
            'grid_image' => $imageNode ? absoluteUrl($imageNode->nodeValue) : null,
            'classes' => classTags($entry->getAttribute('class')),
        ];
    }

    return $links;
}

/**
 * @param array<string, mixed> $link
 * @return array<string, mixed>|null
 */
function parseBeautyElementsProductPage(array $link): ?array
{
    try {
        $html = fetchHtml($link['url']);
    } catch (Throwable $exception) {
        fwrite(STDERR, "Skipped {$link['url']}: {$exception->getMessage()}\n");

        return null;
    }

    $xpath = htmlXPath($html);
    $sourceTitle = firstText($xpath, "//h1[contains(concat(' ', normalize-space(@class), ' '), ' av-special-heading-tag ')]")
        ?: $link['grid_title'];
    $sourceTitle = normaliseDashes($sourceTitle);

    if ($sourceTitle === '') {
        return null;
    }

    [$lineName, $rawStyleName] = splitSourceTitle($sourceTitle);
    $description = productTextSection($xpath, 'PRODUCT DESCRIPTION');
    $additionalInfo = productTextSection($xpath, 'ADDITIONAL INFORMATION');
    $lengths = extractLengthOptions($sourceTitle.' '.$description);
    $colours = extractColourOptions($additionalInfo);
    $styleName = styleName($rawStyleName, $lengths);
    $productType = productTypeName($link['classes'], $styleName, $rawStyleName);
    $material = materialName($link['classes']);
    $options = [];

    if ($lengths !== []) {
        $options['Length'] = $lengths;
    }

    if ($colours !== []) {
        $options['Colour'] = $colours;
    }

    $images = productImages($xpath);
    if ($images === [] && ! empty($link['grid_image'])) {
        $images[] = $link['grid_image'];
    }

    return [
        'line' => $lineName,
        'line_url' => aftressBrandUrl(),
        'line_sort' => lineSort($lineName),
        'product_type' => $productType,
        'product_type_sort' => productTypeSort($productType),
        'style' => $styleName,
        'style_sort' => 10,
        'material' => $material,
        'sku_prefix' => skuPrefix($lineName, $styleName),
        'source_title' => $sourceTitle,
        'source_url' => $link['url'],
        'description' => $description,
        'additional_info' => $additionalInfo,
        'options' => $options,
        'variants' => expandOptions($options),
        'images' => $images,
        'variant_review_pending' => $options === [],
    ];
}

/**
 * @param Collection<int, array<string, mixed>> $products
 */
function groupSourceProducts(Collection $products): Collection
{
    return $products
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
 * @param array<string, array<int, string>> $options
 * @return array<int, array{options: array<string, string>}>
 */
function expandOptions(array $options): array
{
    if ($options === []) {
        return [['options' => []]];
    }

    $rows = [[]];

    foreach ($options as $name => $values) {
        $next = [];
        foreach ($rows as $row) {
            foreach ($values as $value) {
                $next[] = array_merge($row, [$name => $value]);
            }
        }

        $rows = $next;
    }

    return array_map(fn (array $row): array => ['options' => orderVariants($row)], $rows);
}

/**
 * @param Collection<int, array<string, mixed>> $products
 */
function expandGroupSkus(Collection $products): array
{
    return $products
        ->flatMap(fn (array $product): array => collect($product['variants'])->map(fn (array $variant): array => [
            'source' => $product,
            'variant' => $variant,
            'variants' => $variant['options'],
        ])->all())
        ->unique(fn (array $row): string => optionSignature($row['variants']))
        ->values()
        ->all();
}

function findOrCreateStyle(BrandCatalogueProductType $productType, string $styleName): BrandCatalogueStyle
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
                'source_label' => 'Beauty Elements',
                'usage_context' => 'reference',
                'notes' => 'Beauty Elements AFTRESS reference image for '.$style->name.'.',
                'is_primary' => ! $hasPrimary && $index === 0,
                'sort_order' => $index * 10,
            ],
        );
    }
}

/**
 * @param Collection<int, array<string, mixed>> $products
 * @return array{0:int,1:int}
 */
function syncStyleVariantsAndSkus(BrandCatalogueStyle $style, array $group, Collection $products): array
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

    $created = 0;
    $updated = 0;

    foreach ($expandedSkus as $index => $sourceSku) {
        $variants = orderVariants($sourceSku['variants']);
        $signature = optionSignature($variants);
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
            $created++;
        } else {
            $updated++;
        }

        $note = 'Beauty Elements AFTRESS source: '.$source['source_title'].'.';
        if ($source['variant_review_pending']) {
            $note .= ' Variant review pending: source page does not expose safe colour or length options.';
        }

        $sku->fill([
            'name' => $skuName,
            'sku_code' => $sku->sku_code,
            'barcode' => $sku->barcode,
            'description' => $sku->description,
            'note' => mergeNote($sku->note, $note),
            'url' => $sku->url ?: $source['source_url'],
            'is_active' => true,
            'sort_order' => $sku->exists ? $sku->sort_order : $index * 10,
        ])->save();

        DB::table('brand_catalogue_sku_variant_options')
            ->where('brand_catalogue_sku_id', $sku->id)
            ->delete();

        foreach ($variants as $variantName => $value) {
            $variantModel = $variantMap[$variantName] ?? null;
            $option = $optionMap[$variantName][$value] ?? null;

            if (! $variantModel || ! $option) {
                continue;
            }

            DB::table('brand_catalogue_sku_variant_options')->updateOrInsert(
                [
                    'brand_catalogue_sku_id' => $sku->id,
                    'brand_catalogue_variant_id' => $variantModel->id,
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

/**
 * @param Collection<int, array<string, mixed>> $products
 */
function styleNote(Collection $products): string
{
    $titles = $products->pluck('source_title')->unique()->implode('; ');
    $pending = $products->contains(fn (array $product): bool => $product['variant_review_pending'])
        ? ' Some source pages do not expose safe variant options and need shop review.'
        : '';

    return 'Reference style imported from Beauty Elements AFTRESS products: '.$titles.'. Confirm shop stock before publishing retail products.'.$pending;
}

function splitSourceTitle(string $sourceTitle): array
{
    $title = normaliseDashes($sourceTitle);

    if (preg_match('/^(.*?)\s*-\s*(.+)$/u', $title, $match)) {
        return [lineName($match[1]), cleanSpaces($match[2])];
    }

    return ['Aftress', cleanSpaces($title)];
}

function lineName(string $prefix): string
{
    $prefix = Str::upper(cleanSpaces($prefix));

    return match ($prefix) {
        'AFTRESS LUXURY' => 'Aftress Luxury',
        'AFRICAN DREAM' => 'African Dream',
        default => 'Aftress',
    };
}

function lineSort(string $line): int
{
    return match ($line) {
        'Aftress' => 10,
        'Aftress Luxury' => 20,
        'African Dream' => 30,
        default => 90,
    };
}

/**
 * @param array<int, string> $lengths
 */
function styleName(string $rawStyle, array $lengths): string
{
    $style = normaliseDashes($rawStyle);
    $style = preg_replace('/\b(\d+(?:\.\d+)?)\s*(?:INCH(?:ES)?\b|\x{2033}|")/iu', ' ', $style) ?? $style;

    foreach ($lengths as $length) {
        $quoted = preg_quote($length, '/');
        $style = preg_replace('/(?:^|[\s,&])'.$quoted.'(?=\s*$|[\s,&])/u', ' ', $style) ?? $style;
    }

    $style = preg_replace('/\s*[,;&]\s*/u', ' ', $style) ?? $style;
    $style = preg_replace('/^B\s*\/\s*U\s+/iu', 'Bulk Ultimo ', $style) ?? $style;
    $style = preg_replace('/\bKLIPON\b/iu', 'Klip On', $style) ?? $style;
    $style = preg_replace('/\bTWSIT\b/iu', 'Twist', $style) ?? $style;
    $style = preg_replace('/\s*[,&]\s*$/u', '', $style) ?? $style;
    $style = titleStyle($style);

    $aliases = [
        '3D Aztec Braid' => '3D Aztec Braids',
        'Auntie Lizzy' => 'Auntie Lizzy Braid',
    ];

    return $aliases[$style] ?? $style;
}

/**
 * @param array<int, string> $tags
 */
function productTypeName(array $tags, string $styleName, string $rawStyleName): string
{
    $haystack = Str::lower($styleName.' '.$rawStyleName);

    if (hasTag($tags, 'drawstring') && Str::contains($haystack, 'puff')) {
        return 'Hair Puffs / Hair Pieces';
    }

    if (hasTag($tags, 'drawstring') || Str::contains($haystack, 'pony')) {
        return 'Ponytails';
    }

    if (hasTag($tags, 'lace-wigs')) {
        return 'Lace Wigs';
    }

    if (hasTag($tags, 'wigs')) {
        return 'Wigs';
    }

    if (hasTag($tags, 'weaves') || hasTag($tags, 'weave')) {
        return 'Weaves';
    }

    if (hasTag($tags, 'closure')) {
        return 'Closures';
    }

    if (hasTag($tags, 'braids')) {
        $bulkCheck = str_replace('bulk ultimo', '', $haystack);
        if (Str::contains($bulkCheck, 'bulk')) {
            return 'Bulk Hair';
        }

        return 'Braiding Hair';
    }

    return 'Hair Extensions';
}

/**
 * @param array<int, string> $tags
 */
function materialName(array $tags): string
{
    if (hasTag($tags, 'human-hair')) {
        return 'Human Hair';
    }

    if (hasTag($tags, 'human-hair-blend')) {
        return 'Human Hair Blend';
    }

    if (hasTag($tags, 'brazilian-hair')) {
        return 'Brazilian Hair';
    }

    if (hasTag($tags, 'synthetic-hair')) {
        return 'Synthetic Hair';
    }

    return 'Hair';
}

function skuPrefix(string $lineName, string $styleName): string
{
    if ($lineName === 'Aftress') {
        return 'Aftress '.$styleName;
    }

    return 'Aftress '.$lineName.' '.$styleName;
}

function productTypeUrl(string $productType): string
{
    return match ($productType) {
        'Braiding Hair', 'Bulk Hair' => 'http://beautyelements.co/product-type/braid/',
        'Ponytails', 'Hair Puffs / Hair Pieces' => 'http://beautyelements.co/product-type/drawstring/',
        'Wigs' => 'http://beautyelements.co/product-type/wigs/',
        'Lace Wigs' => 'http://beautyelements.co/product-type/lace-wig/',
        'Weaves' => 'http://beautyelements.co/product-type/weave/',
        'Closures' => 'http://beautyelements.co/product-type/closure/',
        default => aftressBrandUrl(),
    };
}

function productTypeSort(string $productType): int
{
    return match ($productType) {
        'Braiding Hair' => 10,
        'Bulk Hair' => 20,
        'Ponytails' => 30,
        'Hair Puffs / Hair Pieces' => 40,
        'Weaves' => 50,
        'Wigs' => 60,
        'Lace Wigs' => 70,
        'Closures' => 80,
        default => 90,
    };
}

function productTextSection(DOMXPath $xpath, string $heading): string
{
    $blocks = [];
    foreach ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' avia_textblock ')]") as $node) {
        $text = cleanText($node->textContent);
        if ($text !== '') {
            $blocks[] = $text;
        }
    }

    $needle = Str::upper($heading);
    foreach ($blocks as $index => $text) {
        if (Str::upper($text) === $needle) {
            return $blocks[$index + 1] ?? '';
        }
    }

    return '';
}

/**
 * @return array<int, string>
 */
function extractLengthOptions(string $text): array
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    preg_match_all('/\b(\d+(?:\.\d+)?)\s*(?:INCH(?:ES)?\b|\x{2033}|")/iu', $text, $matches);

    return collect($matches[1] ?? [])
        ->map(fn (string $value): string => rtrim(rtrim($value, '0'), '.'))
        ->filter()
        ->unique()
        ->sortBy(fn (string $value): string => naturalSortKey($value))
        ->values()
        ->all();
}

/**
 * @return array<int, string>
 */
function extractColourOptions(string $text): array
{
    $text = normaliseDashes($text);
    $text = preg_replace('/\([^)]*\)/u', ' ', $text) ?? $text;
    $text = preg_replace('/\b(?:AVAILABLE|AVAIL|COLORS?|COLOURS?|COLOUR|COLOR|CHART)\b/iu', ' ', $text) ?? $text;
    $text = str_replace([':', '-', '–', '—'], ' ', $text);
    $tokens = preg_split('/[\s,;]+/u', cleanSpaces($text)) ?: [];

    return collect($tokens)
        ->map(fn (string $token): string => normaliseColour($token))
        ->filter()
        ->reject(fn (string $token): bool => in_array(Str::upper($token), ['DE', '2TONE', 'TONE'], true))
        ->unique()
        ->sortBy(fn (string $value): string => naturalSortKey($value))
        ->values()
        ->all();
}

function normaliseColour(string $colour): string
{
    $colour = trim($colour, " \t\n\r\0\x0B-:;,.()[]");
    $colour = preg_replace('/\s*\/\s*/', '/', $colour) ?? $colour;
    $colour = cleanSpaces($colour);

    if ($colour === '') {
        return '';
    }

    $lower = Str::lower($colour);
    $map = [
        '1b' => '1B',
        '99j' => '99J',
        'burg' => 'BURG',
        'bur' => 'BURG',
        'tburg' => 'TBURG',
        'tbur' => 'TBUR',
        'btburg' => 'BTBURG',
        'btbur' => 'BTBUR',
    ];

    if (isset($map[$lower])) {
        return $map[$lower];
    }

    if (preg_match('/^(?:\d+[A-Za-z]?|[A-Z]?\d+[A-Z]?|T[A-Za-z0-9\/]+|BT[A-Za-z0-9\/]+|DE[A-Za-z0-9\/]+|F[A-Za-z0-9\/]+|P[A-Za-z0-9\/]+|TP[A-Za-z0-9\/]+)$/iu', $colour)) {
        return Str::upper($colour);
    }

    return titleStyle($colour);
}

/**
 * @return array<int, string>
 */
function productImages(DOMXPath $xpath): array
{
    $images = [];

    foreach ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' avia-slideshow-inner ')]//img/@src") as $node) {
        $url = absoluteUrl($node->nodeValue);
        if ($url !== '' && isProductImageUrl($url)) {
            $images[] = $url;
        }
    }

    if ($images === []) {
        foreach ($xpath->query("//main//img/@src") as $node) {
            $url = absoluteUrl($node->nodeValue);
            if ($url !== '' && isProductImageUrl($url)) {
                $images[] = $url;
            }
        }
    }

    return collect($images)->unique()->values()->all();
}

function isProductImageUrl(string $url): bool
{
    $lower = Str::lower($url);

    if (! str_contains($lower, '/wp-content/uploads/')) {
        return false;
    }

    return ! Str::contains($lower, [
        'logo',
        'beauty-elements-set',
        'be_logo',
        'kara_logo',
        'kali',
        'beshe',
        'queen',
    ]);
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

    return $node ? cleanText($node->textContent) : '';
}

function firstChildText(DOMXPath $xpath, DOMNode $node, string $query): string
{
    $child = $xpath->query($query, $node)->item(0);

    return $child ? cleanText($child->textContent) : '';
}

/**
 * @return array<int, string>
 */
function classTags(string $class): array
{
    preg_match_all('/\b([a-z0-9-]+)_sort\b/i', $class, $matches);

    return collect($matches[1] ?? [])
        ->map(fn (string $value): string => Str::lower($value))
        ->unique()
        ->values()
        ->all();
}

/**
 * @param array<int, string> $tags
 */
function hasTag(array $tags, string $tag): bool
{
    return in_array(Str::lower($tag), $tags, true);
}

function normaliseDashes(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    $text = str_replace(["\u{2013}", "\u{2014}", 'â€“', 'â€”'], '-', $text);

    return cleanSpaces($text);
}

function cleanText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    $text = str_replace("\xc2\xa0", ' ', $text);

    return cleanSpaces($text);
}

function absoluteUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (str_starts_with($url, '//')) {
        return 'http:'.$url;
    }

    if (str_starts_with($url, '/')) {
        return 'http://beautyelements.co'.$url;
    }

    return $url;
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
        $parts[] = Str::lower($name) === 'colour' ? 'Colour '.$value : $value;
    }

    return cleanSpaces($prefix.($parts === [] ? '' : ' - '.implode(' - ', $parts)));
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
    if ($value === '') {
        return '';
    }

    $words = explode(' ', Str::lower($value));

    return cleanSpaces(implode(' ', array_map(function (string $word): string {
        $trimmed = trim($word);

        if (preg_match('/^(?:\d+x|3d|hh|ht|lb\d+|brl\d+|kcl\d+|plf\d+|dp|de|f\d+|p\d+|tp\d+)$/i', $trimmed)) {
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
