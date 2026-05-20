<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$sync = in_array('--sync', $argv, true);
$now = now();

$reportPath = latestCandidateCsv();
$candidateRows = readCandidateRows($reportPath);
$plans = array_values(array_filter($candidateRows, fn (array $row): bool => ($row['confidence'] ?? '') === 'A'));

$summary = [
    'source_report_rows' => count($candidateRows),
    'confidence_a_families' => count($plans),
    'planned_products' => 0,
    'families_created' => 0,
    'families_updated' => 0,
    'products_created' => 0,
    'products_updated' => 0,
    'source_rows_linked' => 0,
    'source_rows_marked_productized' => 0,
];

$reportRows = [];

DB::beginTransaction();

try {
    $productizedCategoryId = ensureProductizedCategory();
    $location = defaultInventoryLocation();

    foreach ($plans as $plan) {
        $brandName = cleanName($plan['brand']);
        $familyCore = cleanName($plan['family_name']);
        $familyName = withBrandPrefix($brandName, $familyCore);
        $axis = cleanName($plan['variant_axis']);
        $variants = splitPipe($plan['variants']);
        $products = splitPipe($plan['products']);
        $pictureGroups = splitPipe($plan['picture_ids']);
        $productType = inferProductType($familyCore, $axis, implode(' ', $products));
        $rootCatalogue = rootCatalogueName($productType);
        $brand = findOrCreateBrand($brandName);
        $familyResult = firstOrCreatePictureFamily($brand, $brandName, $familyName, $productType, $rootCatalogue, $axis);
        $family = $familyResult['family'];

        $summary[$familyResult['created'] ? 'families_created' : 'families_updated']++;
        syncFamilySource($family['id'], $plan);
        syncFamilyEcommerceProfile($family);

        foreach ($products as $index => $observedProductName) {
            $variantName = $variants[$index] ?? '';
            $pictureIds = isset($pictureGroups[$index]) ? array_filter(array_map('trim', explode(',', $pictureGroups[$index]))) : [];
            $sourceRows = findObservedSourceRows($brandName, $observedProductName, $pictureIds);

            if ($variantName === '' || $sourceRows->isEmpty()) {
                $reportRows[] = [
                    'action' => 'skipped_missing_variant_or_source',
                    'brand' => $brandName,
                    'family' => $familyName,
                    'variant' => $variantName,
                    'product' => $observedProductName,
                    'product_id' => null,
                    'family_id' => $family['id'],
                    'source_ids' => '',
                ];
                continue;
            }

            $summary['planned_products']++;
            $productName = withBrandPrefix($brandName, $observedProductName);
            $productResult = firstOrCreatePictureProduct($family, $brand, $productName, $variantName, $axis, $location, $productType, $rootCatalogue);
            $product = $productResult['product'];

            $summary[$productResult['created'] ? 'products_created' : 'products_updated']++;
            syncProductVariant($family['id'], $product['id'], $axis, $variantName);
            syncProductOperationalProfiles($family, $product, $location, $productType, $rootCatalogue);

            foreach ($sourceRows as $sourceRow) {
                syncProductSource($family['id'], $product['id'], $sourceRow, $axis, $variantName);
                $summary['source_rows_linked']++;

                DB::table('observed_products')
                    ->where('id', $sourceRow->id)
                    ->update([
                        'category_id' => $productizedCategoryId,
                        'updated_at' => $GLOBALS['now'],
                    ]);
                $summary['source_rows_marked_productized']++;
            }

            $reportRows[] = [
                'action' => $productResult['created'] ? 'created_product' : 'updated_product',
                'brand' => $brandName,
                'family' => $familyName,
                'variant' => $variantName,
                'product' => $productName,
                'product_id' => $product['id'],
                'family_id' => $family['id'],
                'source_ids' => $sourceRows->pluck('id')->implode(','),
            ];
        }
    }

    $outputReport = writeImportReport($reportRows, $sync);

    if ($sync) {
        DB::commit();
    } else {
        DB::rollBack();
    }
} catch (Throwable $e) {
    DB::rollBack();
    throw $e;
}

echo $sync ? "Picture confidence A retail products synced.\n" : "Picture confidence A retail products dry run.\n";
echo "source_report: {$reportPath}\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}
echo "report: {$outputReport}\n";
if (! $sync) {
    echo "Run with --sync to apply.\n";
}

function latestCandidateCsv(): string
{
    $files = glob(storage_path('app/catalogue-reports/picture-family-candidates-*.csv')) ?: [];
    rsort($files);

    if ($files === []) {
        throw new RuntimeException('No picture family candidate CSV found. Run scripts/analyze-picture-family-candidates.php first.');
    }

    return $files[0];
}

/**
 * @return list<array<string, string>>
 */
function readCandidateRows(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Could not open {$path}");
    }

    $headers = fgetcsv($handle);
    $rows = [];

    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = array_combine($headers, $row);
    }

    fclose($handle);

    return $rows;
}

/**
 * @return list<string>
 */
function splitPipe(string $value): array
{
    return array_values(array_filter(array_map(fn (string $item): string => cleanName($item), explode(' | ', $value)), fn (string $item): bool => $item !== ''));
}

function ensureProductizedCategory(): int
{
    DB::table('categories')->updateOrInsert(
        ['slug' => 'retail-productized-confidence-a'],
        [
            'name' => 'Productized Retail Products',
            'sort_order' => 50,
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]
    );

    return (int) DB::table('categories')->where('slug', 'retail-productized-confidence-a')->value('id');
}

function findOrCreateBrand(string $name): array
{
    $brand = DB::table('brands')->where('name', $name)->first();
    if ($brand !== null) {
        return (array) $brand;
    }

    $id = DB::table('brands')->insertGetId([
        'name' => $name,
        'slug' => uniqueSlug('brands', 'slug', $name),
        'is_active' => 1,
        'is_generic' => $name === 'Unknown',
        'created_at' => $GLOBALS['now'],
        'updated_at' => $GLOBALS['now'],
    ]);

    return (array) DB::table('brands')->where('id', $id)->first();
}

/**
 * @return array{family: array<string, mixed>, created: bool}
 */
function firstOrCreatePictureFamily(array $brand, string $brandName, string $familyName, string $productType, string $rootCatalogue, string $axis): array
{
    $family = DB::table('product_families')
        ->where('brand_name', $brandName)
        ->where('family_name', $familyName)
        ->first();

    $created = false;

    if ($family === null) {
        $created = true;
        $id = DB::table('product_families')->insertGetId([
            'brand_id' => $brand['id'],
            'root_catalogue_name' => $rootCatalogue,
            'brand_name' => $brandName,
            'line_name' => null,
            'product_type_name' => $productType,
            'family_name' => $familyName,
            'slug' => uniqueSlug('product_families', 'slug', $familyName),
            'description' => null,
            'source_url' => null,
            'status' => 'draft',
            'published_at' => $GLOBALS['now'],
            'sort_order' => brandSort($brandName),
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]);
    } else {
        $id = (int) $family->id;
        DB::table('product_families')->where('id', $id)->update([
            'brand_id' => $brand['id'],
            'root_catalogue_name' => $family->root_catalogue_name ?: $rootCatalogue,
            'product_type_name' => $family->product_type_name ?: $productType,
            'status' => 'draft',
            'published_at' => $family->published_at ?: $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]);
    }

    $familyArray = (array) DB::table('product_families')->where('id', $id)->first();
    ensureVariantGroup($id, $axis);

    return ['family' => $familyArray, 'created' => $created];
}

function syncFamilySource(int $familyId, array $plan): void
{
    updateOrInsertNullable('product_sources', [
        'product_family_id' => $familyId,
        'product_id' => null,
        'source_type' => 'picture_family_confidence_a',
        'source_table' => 'observed_products',
        'source_id' => null,
    ], [
        'confidence' => 'A',
        'notes' => "Grouped from shop-picture family candidate. Variant axis: {$plan['variant_axis']}. Products: {$plan['products']}.",
        'updated_at' => $GLOBALS['now'],
    ]);
}

function syncFamilyEcommerceProfile(array $family): void
{
    updateOrInsertNullable('product_ecommerce_profiles', [
        'product_family_id' => $family['id'],
        'product_id' => null,
        'profile_level' => 'family',
    ], [
        'online_title' => $family['family_name'],
        'short_description' => null,
        'long_description' => $family['description'],
        'seo_slug' => $family['slug'],
        'seo_title' => $family['family_name'],
        'seo_description' => null,
        'tags' => json_encode(array_values(array_filter([$family['brand_name'], $family['product_type_name'], $family['family_name']]))),
        'is_published' => 0,
        'click_and_collect_enabled' => 1,
        'updated_at' => $GLOBALS['now'],
    ]);
}

function firstOrCreatePictureProduct(array $family, array $brand, string $productName, string $variantName, string $axis, array $location, string $productType, string $rootCatalogue): array
{
    $product = DB::table('products as p')
        ->join('product_families as pf', 'pf.id', '=', 'p.product_family_id')
        ->where('pf.brand_name', $family['brand_name'])
        ->whereRaw('LOWER(p.name) = ?', [Str::lower($productName)])
        ->select('p.*')
        ->first();

    $created = false;

    if ($product === null) {
        $created = true;
        $id = DB::table('products')->insertGetId([
            'product_family_id' => $family['id'],
            'brand_id' => $brand['id'],
            'name' => $productName,
            'slug' => uniqueSlug('products', 'slug', $productName, null, ['product_family_id' => $family['id']]),
            'sku' => null,
            'barcode' => null,
            'receipt_name' => Str::limit($productName, 80, ''),
            'inventory_name' => $productName,
            'search_keywords' => searchKeywords($family, $productName, $variantName),
            'description' => null,
            'status' => 'draft',
            'is_pos_active' => 0,
            'is_ecommerce_active' => 0,
            'is_inventory_tracked' => 1,
            'sort_order' => optionSort($variantName),
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]);
    } else {
        $id = (int) $product->id;
        DB::table('products')->where('id', $id)->update([
            'product_family_id' => $family['id'],
            'brand_id' => $brand['id'],
            'receipt_name' => Str::limit($productName, 80, ''),
            'inventory_name' => $product->inventory_name ?: $productName,
            'search_keywords' => searchKeywords($family, $productName, $variantName),
            'status' => 'draft',
            'is_inventory_tracked' => 1,
            'updated_at' => $GLOBALS['now'],
        ]);
    }

    return ['product' => (array) DB::table('products')->where('id', $id)->first(), 'created' => $created];
}

function syncProductVariant(int $familyId, int $productId, string $axis, string $variantName): void
{
    $group = ensureVariantGroup($familyId, $axis);
    $option = DB::table('product_variant_options')
        ->where('product_variant_group_id', $group['id'])
        ->where('label', $variantName)
        ->first();

    if ($option === null) {
        $optionId = DB::table('product_variant_options')->insertGetId([
            'product_variant_group_id' => $group['id'],
            'brand_catalogue_variant_option_id' => null,
            'label' => $variantName,
            'value' => $variantName,
            'sort_order' => optionSort($variantName),
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]);
    } else {
        $optionId = (int) $option->id;
        DB::table('product_variant_options')->where('id', $optionId)->update([
            'value' => $variantName,
            'sort_order' => optionSort($variantName),
            'updated_at' => $GLOBALS['now'],
        ]);
    }

    DB::table('product_variant_values')
        ->where('product_id', $productId)
        ->where('product_variant_group_id', $group['id'])
        ->delete();

    DB::table('product_variant_values')->insert([
        'product_id' => $productId,
        'product_variant_group_id' => $group['id'],
        'product_variant_option_id' => $optionId,
        'created_at' => $GLOBALS['now'],
        'updated_at' => $GLOBALS['now'],
    ]);
}

function ensureVariantGroup(int $familyId, string $axis): array
{
    $group = DB::table('product_variant_groups')
        ->where('product_family_id', $familyId)
        ->where('name', $axis)
        ->first();

    if ($group === null) {
        $id = DB::table('product_variant_groups')->insertGetId([
            'product_family_id' => $familyId,
            'brand_catalogue_variant_id' => null,
            'name' => $axis,
            'variant_type' => variantTypeForAxis($axis),
            'sort_order' => variantGroupSort($axis),
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]);
    } else {
        $id = (int) $group->id;
        DB::table('product_variant_groups')->where('id', $id)->update([
            'variant_type' => variantTypeForAxis($axis),
            'sort_order' => variantGroupSort($axis),
            'updated_at' => $GLOBALS['now'],
        ]);
    }

    return (array) DB::table('product_variant_groups')->where('id', $id)->first();
}

function syncProductOperationalProfiles(array $family, array $product, array $location, string $productType, string $rootCatalogue): void
{
    DB::table('product_prices')->updateOrInsert(
        ['product_id' => $product['id']],
        [
            'retail_price' => null,
            'compare_at_price' => null,
            'cost_price' => null,
            'currency' => 'GBP',
            'tax_class' => 'standard',
            'vat_rate' => null,
            'price_notes' => 'Created from shop picture evidence; verify retail price before activation.',
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]
    );

    DB::table('inventory_levels')->updateOrInsert(
        [
            'product_id' => $product['id'],
            'inventory_location_id' => $location['id'],
        ],
        [
            'stock_quantity' => 0,
            'supplier' => null,
            'supplier_product_code' => null,
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]
    );

    DB::table('product_pos_profiles')->updateOrInsert(
        ['product_id' => $product['id']],
        [
            'receipt_name' => Str::limit($product['name'], 80, ''),
            'quick_search_keywords' => $product['search_keywords'],
            'pos_category' => $rootCatalogue,
            'discount_allowed' => 1,
            'quick_sale_enabled' => 1,
            'tax_class' => 'standard',
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]
    );

    updateOrInsertNullable('product_ecommerce_profiles', [
        'product_family_id' => $family['id'],
        'product_id' => $product['id'],
        'profile_level' => 'sku',
    ], [
        'online_title' => $product['name'],
        'short_description' => null,
        'long_description' => null,
        'seo_slug' => $product['slug'],
        'seo_title' => $product['name'],
        'seo_description' => null,
        'tags' => json_encode(array_values(array_filter([$family['brand_name'], $productType, $family['family_name']]))),
        'is_published' => 0,
        'click_and_collect_enabled' => 1,
        'updated_at' => $GLOBALS['now'],
    ]);
}

function syncProductSource(int $familyId, int $productId, object $sourceRow, string $axis, string $variantName): void
{
    DB::table('product_sources')->updateOrInsert(
        [
            'product_family_id' => $familyId,
            'product_id' => $productId,
            'source_type' => 'picture_product_confidence_a',
            'source_table' => 'observed_products',
            'source_id' => $sourceRow->id,
        ],
        [
            'source_url' => url('/pictures/'.$sourceRow->picture_id),
            'confidence' => 'A',
            'notes' => "Shop photo product hit {$sourceRow->picture_id}. Grouped as {$axis}: {$variantName}.",
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]
    );
}

function findObservedSourceRows(string $brandName, string $productName, array $pictureIds)
{
    return DB::table('observed_products as op')
        ->whereRaw("COALESCE(NULLIF(op.canonical_brand, ''), op.brand) = ?", [$brandName])
        ->where('op.product_name', $productName)
        ->when($pictureIds !== [], fn ($query) => $query->whereIn('op.picture_id', $pictureIds))
        ->orderBy('op.id')
        ->get();
}

function defaultInventoryLocation(): array
{
    $location = DB::table('inventory_locations')->where('slug', 'shop-floor')->first();
    if ($location !== null) {
        return (array) $location;
    }

    $id = DB::table('inventory_locations')->insertGetId([
        'name' => 'Shop Floor',
        'slug' => 'shop-floor',
        'location_type' => 'shop',
        'is_default' => 1,
        'is_active' => 1,
        'sort_order' => 0,
        'created_at' => $GLOBALS['now'],
        'updated_at' => $GLOBALS['now'],
    ]);

    return (array) DB::table('inventory_locations')->where('id', $id)->first();
}

function inferProductType(string $familyName, string $axis, string $productText): string
{
    $text = Str::lower($familyName.' '.$axis.' '.$productText);

    return match (true) {
        containsAny($text, ['hair colour', 'hair color', 'permanent powder', 'tintation', 'high voltage', 'haircolor', 'exotic shine color']) => 'Hair Colour / Dye',
        containsAny($text, ['edge tamer', 'perfect edges']) => 'Edge Control',
        containsAny($text, ['detangler']) => 'Detangler',
        containsAny($text, ['tinted lace', 'wonder lace']) => 'Lace / Wig Products',
        containsAny($text, ['shaving powder']) => 'Shaving Powder',
        containsAny($text, ['styling wax', 'hair wax', 'beeswax', 'gel wax']) => 'Hair Wax',
        containsAny($text, ['styling gel', 'conditioning gel', 'hair gel', 'jamm conditioning gel']) => 'Styling Gel',
        containsAny($text, ['petroleum jelly', 'protecting jelly']) => 'Petroleum Jelly',
        containsAny($text, ['face & body scrub', 'savon gommant']) => 'Body Scrub',
        containsAny($text, ['shower gel']) => 'Shower Gel',
        containsAny($text, ['soap']) => 'Soap',
        containsAny($text, ['relaxer']) => 'Hair Relaxer',
        containsAny($text, ['castor oil', 'extra virgin oil', 'natural oil', 'shea butter', 'bay rum', 'super whitening oil']) => 'Oil / Treatment',
        containsAny($text, ['moisturizer', 'moisturiser', 'hand and body lotion']) => 'Body Lotion / Moisturiser',
        containsAny($text, ['co-wash', 'cowash']) => 'Co-Wash',
        default => 'General Product',
    };
}

function rootCatalogueName(string $productType): string
{
    $text = Str::lower($productType);

    return match (true) {
        containsAny($text, ['body', 'skin', 'petroleum', 'soap', 'shower', 'scrub', 'moisturiser', 'moisturizer']) => 'Skin Care',
        containsAny($text, ['hair', 'edge', 'gel', 'wax', 'detangler', 'relaxer', 'lace', 'wig', 'co-wash']) => 'Hair Products',
        default => 'General Products',
    };
}

function variantTypeForAxis(string $axis): string
{
    $axis = Str::lower($axis);

    return match (true) {
        containsAny($axis, ['colour', 'color', 'shade']) => 'colour_name',
        containsAny($axis, ['size']) => 'measurement',
        default => 'text',
    };
}

function variantGroupSort(string $axis): int
{
    $axis = Str::lower($axis);

    return match (true) {
        containsAny($axis, ['shade', 'colour', 'color']) => 10,
        containsAny($axis, ['size']) => 20,
        containsAny($axis, ['strength', 'hold']) => 30,
        containsAny($axis, ['fragrance', 'scent']) => 40,
        default => 50,
    };
}

function optionSort(string $value): int
{
    if (preg_match('/\b(\d+(?:\.\d+)?)\b/', $value, $match)) {
        return (int) ((float) $match[1] * 100);
    }

    return abs(crc32(Str::lower($value))) % 100000;
}

function searchKeywords(array $family, string $productName, string $variantName): string
{
    return implode(' ', array_values(array_filter([
        $family['brand_name'] ?? null,
        $family['family_name'] ?? null,
        $family['product_type_name'] ?? null,
        $productName,
        $variantName,
    ])));
}

function updateOrInsertNullable(string $table, array $keys, array $values): void
{
    $query = DB::table($table);
    foreach ($keys as $column => $value) {
        $value === null ? $query->whereNull($column) : $query->where($column, $value);
    }

    $existing = $query->first();

    if ($existing) {
        DB::table($table)->where('id', $existing->id)->update($values);

        return;
    }

    DB::table($table)->insert(array_merge($keys, $values, [
        'created_at' => $GLOBALS['now'],
    ]));
}

function uniqueSlug(string $table, string $column, string $name, ?int $ignoreId = null, array $scope = []): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $counter = 2;

    while (true) {
        $query = DB::table($table)->where($column, $slug);
        foreach ($scope as $scopeColumn => $scopeValue) {
            $query->where($scopeColumn, $scopeValue);
        }
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if (! $query->exists()) {
            return $slug;
        }

        $slug = $base.'-'.$counter;
        $counter++;
    }
}

function brandSort(string $brand): int
{
    return abs(crc32(Str::lower($brand))) % 100000;
}

function withBrandPrefix(string $brand, string $name): string
{
    return str_starts_with(Str::lower($name), Str::lower($brand))
        ? cleanName($name)
        : cleanName($brand.' '.$name);
}

function containsAny(string $text, array $needles): bool
{
    foreach ($needles as $needle) {
        if (str_contains($text, $needle)) {
            return true;
        }
    }

    return false;
}

function cleanName(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function writeImportReport(array $rows, bool $sync): string
{
    $dir = storage_path('app/catalogue-reports');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $path = $dir.'/picture-confidence-a-retail-import-'.($sync ? 'sync' : 'dry-run').'-'.date('Ymd-His').'.csv';
    $handle = fopen($path, 'wb');
    fputcsv($handle, ['action', 'brand', 'family', 'variant', 'product', 'product_id', 'family_id', 'source_ids']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}
