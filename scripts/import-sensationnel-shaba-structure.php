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
$sourceProducts = collectShabaSensationnelProducts();
$groups = groupSourceProducts($sourceProducts);

if ($dryRun) {
    echo "Sensationnel Shaba dry run.\n";
    echo 'Collections: '.count(shabaCollections())."\n";
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
            echo '  - '.$product['title'].' | '.$product['handle'].' | '.($optionSummary ?: 'no variants parsed').' | '.$product['source_url']."\n";
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
        'note' => mergeNote($brand->note, 'Reference structure extended from Shaba Sensationnel collections. Confirm shop stock before publishing retail products.'),
        'url' => $brand->url ?: 'https://shabacosmetics.com/collections/sensationnel',
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
                'is_default' => false,
                'is_active' => true,
            ],
        );

        $line->fill([
            'note' => mergeNote($line->note, 'Sensationnel sub-brand source: Shaba Cosmetics.'),
            'url' => $line->url ?: $group['line_url'],
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
            'note' => mergeNote($productType->note, 'Operational product type structured from Shaba Sensationnel product data.'),
            'url' => $productType->url ?: $group['line_url'],
            'is_active' => true,
            'sort_order' => $group['product_type_sort'],
        ])->save();

        $style = findOrCreateStyle($brand, $productType, $group['style']);
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

    cleanupDefaultLineIfEmpty($brand);

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

echo "Sensationnel Shaba structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return array<int, array<string, mixed>>
 */
function shabaCollections(): array
{
    return [
        [
            'line' => 'Sensationnel Premium Plus',
            'slug' => 'premium-plus-1',
            'url' => 'https://shabacosmetics.com/collections/premium-plus-1',
            'sort_order' => 50,
        ],
        [
            'line' => 'Sensationnel Premium Now',
            'slug' => 'premium-now-1',
            'url' => 'https://shabacosmetics.com/collections/premium-now-1',
            'sort_order' => 60,
        ],
        [
            'line' => 'Sensationnel Premium Too',
            'slug' => 'premium-too',
            'url' => 'https://shabacosmetics.com/collections/premium-too',
            'sort_order' => 70,
        ],
        [
            'line' => 'Sensationnel Soft N Silky',
            'slug' => 'soft-n-silky-1',
            'url' => 'https://shabacosmetics.com/collections/soft-n-silky-1',
            'sort_order' => 80,
        ],
        [
            'line' => 'Sensationnel Empire Bulk',
            'slug' => 'sensationnel-empire-bulk',
            'url' => 'https://shabacosmetics.com/collections/sensationnel-empire-bulk',
            'sort_order' => 10,
        ],
    ];
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function collectShabaSensationnelProducts(): Collection
{
    $products = collect();

    foreach (shabaCollections() as $collection) {
        $data = fetchCollectionJson($collection['slug']);

        foreach ($data['products'] ?? [] as $product) {
            $products->push(normaliseProduct($product, $collection));
        }
    }

    return $products;
}

/**
 * @return array<string, mixed>
 */
function fetchCollectionJson(string $slug): array
{
    $url = "https://shabacosmetics.com/collections/{$slug}/products.json?limit=250";
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
            'timeout' => 30,
        ],
    ]);

    $json = @file_get_contents($url, false, $context);
    if ($json === false || trim($json) === '') {
        throw new RuntimeException("Could not read {$url}");
    }

    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param array<string, mixed> $product
 * @param array<string, mixed> $collection
 * @return array<string, mixed>
 */
function normaliseProduct(array $product, array $collection): array
{
    $options = [];
    foreach ($product['options'] ?? [] as $index => $option) {
        $name = normaliseVariantName((string) ($option['name'] ?? 'Option '.($index + 1)));
        $options[$name] = collect($option['values'] ?? [])
            ->map(fn ($value): string => cleanVariantValue($name, (string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    $variants = [];
    foreach ($product['variants'] ?? [] as $variant) {
        $variantValues = [];
        $optionNames = array_keys($options);
        foreach ([1, 2, 3] as $optionIndex) {
            $value = $variant['option'.$optionIndex] ?? null;
            $name = $optionNames[$optionIndex - 1] ?? null;

            if ($value === null || $name === null) {
                continue;
            }

            $variantValues[$name] = cleanVariantValue($name, (string) $value);
        }

        $variants[] = [
            'id' => $variant['id'] ?? null,
            'sku' => blankToNull((string) ($variant['sku'] ?? '')),
            'available' => (bool) ($variant['available'] ?? true),
            'options' => orderVariants($variantValues),
        ];
    }

    return [
        'title' => cleanSpaces((string) $product['title']),
        'handle' => (string) $product['handle'],
        'vendor' => cleanSpaces((string) ($product['vendor'] ?? '')),
        'product_type' => cleanSpaces((string) ($product['product_type'] ?? '')),
        'tags' => $product['tags'] ?? [],
        'source_url' => 'https://shabacosmetics.com/products/'.$product['handle'],
        'line' => $collection['line'],
        'line_url' => $collection['url'],
        'line_sort' => $collection['sort_order'],
        'collection_slug' => $collection['slug'],
        'options' => $options,
        'variants' => $variants,
        'images' => collect($product['images'] ?? [])->pluck('src')->filter()->unique()->values()->all(),
    ];
}

/**
 * @param Collection<int, array<string, mixed>> $products
 * @return Collection<int, array<string, mixed>>
 */
function groupSourceProducts(Collection $products): Collection
{
    return $products
        ->map(function (array $product): array {
            return array_merge($product, shabaStructure($product));
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
 * @return array{line:string,line_url:string,line_sort:int,product_type:string,product_type_sort:int,style:string,style_sort:int,material:string,sku_prefix:string}
 */
function shabaStructure(array $product): array
{
    $style = styleName($product['title'], $product['collection_slug']);
    $line = targetLineForProduct($product, $style);
    $productType = productTypeName($product['title'], $product['product_type'], $line['name']);

    return [
        'line' => $line['name'],
        'line_url' => $line['url'] ?? $product['line_url'],
        'line_sort' => $line['sort_order'] ?? $product['line_sort'],
        'product_type' => $productType,
        'product_type_sort' => productTypeSort($productType),
        'style' => $style,
        'style_sort' => 10,
        'material' => materialName($product['title'], $product['product_type'], $line['name']),
        'sku_prefix' => 'Sensationnel '.$product['title'],
    ];
}

/**
 * @return array{name:string,url?:string,sort_order?:int}
 */
function targetLineForProduct(array $product, string $style): array
{
    if ($product['collection_slug'] !== 'premium-too') {
        return [
            'name' => $product['line'],
            'url' => $product['line_url'],
            'sort_order' => $product['line_sort'],
        ];
    }

    $existing = BrandCatalogueStyle::query()
        ->whereHas('brand', fn ($query) => $query->where('name', 'Sensationnel'))
        ->where('name', $style)
        ->whereHas('productType.line', fn ($query) => $query->where('name', 'like', 'Sensationnel Premium Too%'))
        ->with('productType.line')
        ->first();

    if ($existing?->productType?->line) {
        return [
            'name' => $existing->productType->line->name,
            'url' => $existing->productType->line->url,
            'sort_order' => $existing->productType->line->sort_order,
        ];
    }

    return [
        'name' => 'Sensationnel Premium Too',
        'url' => $product['line_url'],
        'sort_order' => $product['line_sort'],
    ];
}

function styleName(string $title, string $collectionSlug): string
{
    $style = cleanSpaces($title);

    $patterns = match ($collectionSlug) {
        'premium-plus-1' => [
            '/^SENSATIONNEL\s+PREMIUM\s+PLUS\s+HUMAN\s+HAIR\s*/i',
            '/^SENSATIONNEL\s+PREMIUM\s+PLUS\s*-\s*/i',
            '/^PREMIUM\s+PLUS\s*-\s*/i',
        ],
        'premium-now-1' => [
            '/^SENSATIONNEL\s+PREMIUM\s+NOW\s+HUMAN\s+HAIR\s*-\s*/i',
            '/^SENSATIONNEL\s+PREMIUM\s+NOW\s+HH\s*/i',
            '/^SENSATIONNEL\s+PREMIUM\s+NOW\s*-\s*/i',
            '/^PREMIUM\s+NOW\s*-\s*/i',
        ],
        'premium-too' => [
            '/^SENSATIONNEL\s+PREMIUM\s+TOO\s+HH\s*/i',
            '/^SENSATIONNEL\s+PREMIUM\s+TOO\s*-\s*/i',
            '/^SENSATIONNEL\s+PREMIUM\s+TOO\s*/i',
            '/^PREMIUM\s+TOO\s+/i',
            '/^PREMIUM\s+TOO\s*-\s*/i',
        ],
        'soft-n-silky-1' => [
            "/^SENSATIONNEL\s+SOFT\s+N\s+'?SILKY\s*/i",
            "/^SOFT\s+N\s+'?SILKY\s*/i",
        ],
        'sensationnel-empire-bulk' => [
            '/^SENSATIONNEL\s+EMPIRE\s+BULK\s*-\s*/i',
            '/^EMPIRE\s+BULK\s*-\s*/i',
        ],
        default => [],
    };

    foreach ($patterns as $pattern) {
        $style = preg_replace($pattern, '', $style) ?? $style;
    }

    $style = preg_replace('/\bHH\b\s*/i', '', $style) ?? $style;
    $style = preg_replace('/\s+WVG\b/i', ' Wvg', $style) ?? $style;
    $style = preg_replace('/\s*-\s*/', ' ', $style) ?? $style;
    $style = cleanSpaces(ltrim($style, '- '));

    return titleStyle($style);
}

function productTypeName(string $title, string $sourceProductType, string $lineName): string
{
    $lower = Str::lower($title);

    if (Str::contains($lower, 'tara') || Str::contains($lower, 'shorty')) {
        return 'Short Weaves';
    }

    if (Str::contains($lower, ['bulk', 'boho braid', 'afro kinky'])) {
        return 'Bulk Hair';
    }

    if (Str::contains($lower, ['afro twist braid', 'twist braid'])) {
        return 'Twist Hair';
    }

    if (Str::contains($lower, ['wvg', 'weave', 'curl', 'wave', 'body', 'straight', 'yaki', 'mixx', 'multi', 'desire', 'luxe'])) {
        return 'Weaves';
    }

    if ($sourceProductType !== '') {
        return titleStyle($sourceProductType);
    }

    return 'Hair Extensions';
}

function materialName(string $title, string $sourceProductType, string $lineName): string
{
    $lower = Str::lower($title.' '.$sourceProductType.' '.$lineName);

    if (Str::contains($lower, ['human hair', ' hh ', 'premium plus', 'premium now'])) {
        return 'Human Hair';
    }

    if (Str::contains($lower, ['blend', 'premium too'])) {
        return 'Human & Premium Blend Hair';
    }

    if (Str::contains($lower, ['soft n', 'soft n silky'])) {
        return 'Synthetic Hair';
    }

    return 'Hair';
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
        ->filter(fn (array $row): bool => $row['variants'] !== [])
        ->unique(fn (array $row): string => optionSignature($row['variants']))
        ->values()
        ->all();
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
                'source_label' => 'Shaba',
                'usage_context' => 'reference',
                'notes' => 'Shaba Sensationnel reference image for '.$style->name.'.',
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
        $variant = $sourceSku['variant'];
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

        $sku->fill([
            'name' => $skuName,
            'sku_code' => $sku->sku_code ?: blankToNull($variant['sku'] ?? null),
            'barcode' => $sku->barcode,
            'description' => $sku->description,
            'note' => mergeNote($sku->note, 'Shaba Sensationnel source: '.$source['title'].'.'),
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

function cleanupDefaultLineIfEmpty(BrandCatalogueBrand $brand): void
{
    BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('is_default', true)
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
    return 'Reference style extended from Shaba Sensationnel products: '.$products->pluck('title')->unique()->implode('; ').'. Confirm shop stock before publishing retail products.';
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

function normaliseVariantName(string $name): string
{
    $name = Str::lower(cleanSpaces(str_replace(':', '', $name)));

    return match ($name) {
        'lenght', 'length', 'length ' => 'Length',
        'color', 'color ', 'colour' => 'Colour',
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

    return titleStyle($value);
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
        'stk' => 'STK',
        'bg' => 'BG',
    ];

    if (isset($map[$lower])) {
        return $map[$lower];
    }

    if (preg_match('/^\d+[A-Za-z]?$/', $colour)) {
        return Str::upper($colour);
    }

    return $colour;
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

function productTypeSort(string $productType): int
{
    return match ($productType) {
        'Bulk Hair' => 10,
        'Twist Hair' => 20,
        'Weaves' => 30,
        'Short Weaves' => 40,
        default => 90,
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
    $keepUpper = ['2x', '27', 'pcs', 'jc', 'hh'];

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

function cleanSpaces(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}
