<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = parseArgs($argv);
$sync = array_key_exists('sync', $args);
$limit = isset($args['limit']) ? max(1, (int) $args['limit']) : null;
$onlyBrand = isset($args['brand']) ? cleanName((string) $args['brand']) : '';
$now = now();

if (! Schema::hasTable('observed_products')) {
    fwrite(STDERR, "observed_products table does not exist.\n");
    exit(1);
}

$hiddenCategorySlugs = [
    'hair-extension-moved',
    'retail-productized-confidence-a',
    'retail-productized-picture-draft',
];

$sourceRows = visiblePictureRows($hiddenCategorySlugs, $onlyBrand);

if ($sourceRows->isEmpty()) {
    echo "No remaining picture product hits found.\n";
    exit(0);
}

$plans = planPictureDraftProducts($sourceRows);

if ($limit !== null) {
    $plans = $plans->take($limit)->values();
}

$summary = [
    'source_rows' => $sourceRows->count(),
    'planned_products' => $plans->count(),
    'planned_brands' => $plans->pluck('brand_name')->unique()->count(),
    'created_families' => 0,
    'updated_families' => 0,
    'created_products' => 0,
    'updated_products' => 0,
    'linked_existing_products' => 0,
    'source_rows_linked' => 0,
    'source_rows_marked_productized' => 0,
];

$reportRows = [];

DB::beginTransaction();

try {
    $productizedCategoryId = ensureProductizedCategory();
    $location = defaultInventoryLocation();

    foreach ($plans as $plan) {
        $brand = findOrCreateBrand($plan['brand_name']);
        $existingProduct = findExistingProduct($plan['brand_name'], $plan['product_name']);
        $linkedExisting = $existingProduct !== null;

        if ($existingProduct !== null) {
            $product = (array) $existingProduct;
            $family = (array) DB::table('product_families')->where('id', $product['product_family_id'])->first();
            $summary['linked_existing_products']++;
            $summary['updated_products']++;
            ensureFamilyHasCoreFields($family, $brand, $plan);
        } else {
            $familyResult = firstOrCreatePictureDraftFamily($brand, $plan);
            $family = $familyResult['family'];
            $summary[$familyResult['created'] ? 'created_families' : 'updated_families']++;

            $productResult = firstOrCreatePictureDraftProduct($family, $brand, $plan);
            $product = $productResult['product'];
            $summary[$productResult['created'] ? 'created_products' : 'updated_products']++;
        }

        syncFamilySource((int) $family['id'], $plan);
        ensureFamilyEcommerceProfile($family);
        ensureProductOperationalProfiles($family, $product, $location, $plan);

        foreach ($plan['source_rows'] as $sourceRow) {
            syncProductSource((int) $family['id'], (int) $product['id'], $sourceRow, $plan);
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
            'action' => $linkedExisting ? 'linked_existing_product' : ($product['created_marker'] ?? false ? 'created_product' : 'updated_product'),
            'brand' => $plan['brand_name'],
            'family' => $family['family_name'],
            'product' => $product['name'],
            'product_type' => $plan['product_type_name'],
            'department' => $plan['root_catalogue_name'],
            'product_id' => $product['id'],
            'family_id' => $family['id'],
            'source_ids' => collect($plan['source_rows'])->pluck('id')->implode(','),
            'picture_ids' => implode(',', $plan['picture_ids']),
            'source_rows' => count($plan['source_rows']),
        ];
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

echo $sync ? "Picture draft retail products synced.\n" : "Picture draft retail product dry run.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}
echo "report: {$outputReport}\n";
if (! $sync) {
    echo "Run with --sync to apply.\n";
}

/**
 * @param array<int, string> $argv
 * @return array<string, string|bool>
 */
function parseArgs(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $args[$key] = $value;
        } else {
            $args[$arg] = true;
        }
    }

    return $args;
}

/**
 * @param list<string> $hiddenCategorySlugs
 */
function visiblePictureRows(array $hiddenCategorySlugs, string $onlyBrand)
{
    return DB::table('observed_products as op')
        ->leftJoin('categories as c', 'c.id', '=', 'op.category_id')
        ->where('op.product_name', '!=', '')
        ->whereRaw("COALESCE(NULLIF(op.canonical_brand, ''), op.brand) != ''")
        ->where(function ($query) use ($hiddenCategorySlugs): void {
            $query
                ->whereNull('c.slug')
                ->orWhereNotIn('c.slug', $hiddenCategorySlugs);
        })
        ->when($onlyBrand !== '', fn ($query) => $query->whereRaw("COALESCE(NULLIF(op.canonical_brand, ''), op.brand) = ?", [$onlyBrand]))
        ->select([
            'op.*',
            'c.slug as category_slug',
            'c.name as category_name',
            DB::raw("COALESCE(NULLIF(op.canonical_brand, ''), op.brand) as brand_name"),
        ])
        ->orderBy('brand_name')
        ->orderBy('op.product_name')
        ->orderBy('op.picture_id')
        ->get();
}

function planPictureDraftProducts($sourceRows)
{
    return $sourceRows
        ->groupBy(fn (object $row): string => normalizeKey($row->brand_name.'|'.$row->product_name))
        ->map(function ($rows): array {
            $first = $rows->first();
            $brandName = cleanName((string) $first->brand_name);
            $observedProductName = cleanName((string) $first->product_name);
            $productName = withBrandPrefix($brandName, $observedProductName);
            $categoryNames = $rows->pluck('category_name')->filter()->unique()->values()->all();
            $categorySlugs = $rows->pluck('category_slug')->filter()->unique()->values()->all();
            $productType = inferProductType($productName, $categoryNames);

            return [
                'brand_name' => $brandName,
                'observed_product_name' => $observedProductName,
                'family_name' => $productName,
                'product_name' => $productName,
                'line_name' => null,
                'root_catalogue_name' => rootCatalogueName($productType, $categorySlugs, $categoryNames),
                'product_type_name' => $productType,
                'category_names' => $categoryNames,
                'category_slugs' => $categorySlugs,
                'picture_ids' => $rows->pluck('picture_id')->unique()->sort()->values()->all(),
                'source_rows' => $rows->values()->all(),
            ];
        })
        ->sortBy(fn (array $plan): string => Str::lower($plan['brand_name'].' '.$plan['product_name']))
        ->values();
}

function ensureProductizedCategory(): int
{
    DB::table('categories')->updateOrInsert(
        ['slug' => 'retail-productized-picture-draft'],
        [
            'name' => 'Productized Picture Drafts',
            'sort_order' => 55,
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]
    );

    return (int) DB::table('categories')->where('slug', 'retail-productized-picture-draft')->value('id');
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

function findExistingProduct(string $brandName, string $productName): ?object
{
    return DB::table('products as p')
        ->join('product_families as pf', 'pf.id', '=', 'p.product_family_id')
        ->where('pf.brand_name', $brandName)
        ->whereRaw('LOWER(p.name) = ?', [Str::lower($productName)])
        ->select('p.*')
        ->first();
}

/**
 * @return array{family: array<string, mixed>, created: bool}
 */
function firstOrCreatePictureDraftFamily(array $brand, array $plan): array
{
    $family = DB::table('product_families')
        ->where('brand_name', $plan['brand_name'])
        ->where('family_name', $plan['family_name'])
        ->first();

    $created = false;
    if ($family === null) {
        $created = true;
        $id = DB::table('product_families')->insertGetId([
            'brand_id' => $brand['id'],
            'root_catalogue_name' => $plan['root_catalogue_name'],
            'brand_name' => $plan['brand_name'],
            'line_name' => $plan['line_name'],
            'product_type_name' => $plan['product_type_name'],
            'family_name' => $plan['family_name'],
            'slug' => uniqueSlug('product_families', 'slug', $plan['family_name']),
            'description' => null,
            'source_url' => null,
            'status' => 'draft',
            'published_at' => null,
            'sort_order' => brandSort($plan['brand_name']),
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]);
    } else {
        $id = (int) $family->id;
        DB::table('product_families')->where('id', $id)->update([
            'brand_id' => $brand['id'],
            'root_catalogue_name' => betterExistingValue((string) $family->root_catalogue_name, $plan['root_catalogue_name'], 'General Products'),
            'line_name' => $family->line_name ?: $plan['line_name'],
            'product_type_name' => betterExistingValue((string) $family->product_type_name, $plan['product_type_name'], 'General Product'),
            'status' => 'draft',
            'updated_at' => $GLOBALS['now'],
        ]);
    }

    return [
        'family' => (array) DB::table('product_families')->where('id', $id)->first(),
        'created' => $created,
    ];
}

function ensureFamilyHasCoreFields(array $family, array $brand, array $plan): void
{
    DB::table('product_families')->where('id', $family['id'])->update([
        'brand_id' => $brand['id'],
        'root_catalogue_name' => betterExistingValue((string) $family['root_catalogue_name'], $plan['root_catalogue_name'], 'General Products'),
        'product_type_name' => betterExistingValue((string) $family['product_type_name'], $plan['product_type_name'], 'General Product'),
        'updated_at' => $GLOBALS['now'],
    ]);
}

function betterExistingValue(string $existing, string $candidate, string $weakValue): string
{
    $existing = cleanName($existing);
    $candidate = cleanName($candidate);

    if ($existing === '' || Str::lower($existing) === Str::lower($weakValue)) {
        return $candidate;
    }

    return $existing;
}

/**
 * @return array{product: array<string, mixed>, created: bool}
 */
function firstOrCreatePictureDraftProduct(array $family, array $brand, array $plan): array
{
    $product = DB::table('products')
        ->where('product_family_id', $family['id'])
        ->whereRaw('LOWER(name) = ?', [Str::lower($plan['product_name'])])
        ->first();

    $created = false;
    if ($product === null) {
        $created = true;
        $id = DB::table('products')->insertGetId([
            'product_family_id' => $family['id'],
            'brand_id' => $brand['id'],
            'name' => $plan['product_name'],
            'slug' => uniqueSlug('products', 'slug', $plan['product_name'], null, ['product_family_id' => $family['id']]),
            'sku' => null,
            'barcode' => null,
            'receipt_name' => Str::limit($plan['product_name'], 80, ''),
            'inventory_name' => $plan['product_name'],
            'search_keywords' => searchKeywords($family, $plan),
            'description' => null,
            'status' => 'draft',
            'is_pos_active' => 0,
            'is_ecommerce_active' => 0,
            'is_inventory_tracked' => 1,
            'sort_order' => brandSort($plan['brand_name']),
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]);
    } else {
        $id = (int) $product->id;
        DB::table('products')->where('id', $id)->update([
            'brand_id' => $brand['id'],
            'receipt_name' => $product->receipt_name ?: Str::limit($plan['product_name'], 80, ''),
            'inventory_name' => $product->inventory_name ?: $plan['product_name'],
            'search_keywords' => $product->search_keywords ?: searchKeywords($family, $plan),
            'status' => 'draft',
            'is_inventory_tracked' => 1,
            'updated_at' => $GLOBALS['now'],
        ]);
    }

    $productArray = (array) DB::table('products')->where('id', $id)->first();
    $productArray['created_marker'] = $created;

    return ['product' => $productArray, 'created' => $created];
}

function syncFamilySource(int $familyId, array $plan): void
{
    updateOrInsertNullable('product_sources', [
        'product_family_id' => $familyId,
        'product_id' => null,
        'source_type' => 'picture_family_draft',
        'source_table' => 'observed_products',
        'source_id' => null,
    ], [
        'confidence' => 'C',
        'notes' => 'Draft family created from shop picture product hits. Variant structure, barcode, price and images still need shop review.',
        'updated_at' => $GLOBALS['now'],
    ]);
}

function syncProductSource(int $familyId, int $productId, object $sourceRow, array $plan): void
{
    DB::table('product_sources')->updateOrInsert(
        [
            'product_family_id' => $familyId,
            'product_id' => $productId,
            'source_type' => 'picture_product_draft',
            'source_table' => 'observed_products',
            'source_id' => $sourceRow->id,
        ],
        [
            'source_url' => url('/pictures/'.$sourceRow->picture_id),
            'confidence' => 'C',
            'notes' => 'Shop photo product hit. Created as draft sellable product; verify variants/barcode/price before activation.',
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]
    );
}

function ensureFamilyEcommerceProfile(array $family): void
{
    $exists = DB::table('product_ecommerce_profiles')
        ->where('product_family_id', $family['id'])
        ->whereNull('product_id')
        ->where('profile_level', 'family')
        ->exists();

    if ($exists) {
        return;
    }

    DB::table('product_ecommerce_profiles')->insert([
        'product_family_id' => $family['id'],
        'product_id' => null,
        'profile_level' => 'family',
        'online_title' => $family['family_name'],
        'short_description' => null,
        'long_description' => $family['description'] ?? null,
        'seo_slug' => $family['slug'],
        'seo_title' => $family['family_name'],
        'seo_description' => null,
        'tags' => json_encode(array_values(array_filter([$family['brand_name'], $family['product_type_name'], $family['family_name']]))),
        'is_published' => 0,
        'click_and_collect_enabled' => 1,
        'created_at' => $GLOBALS['now'],
        'updated_at' => $GLOBALS['now'],
    ]);
}

function ensureProductOperationalProfiles(array $family, array $product, array $location, array $plan): void
{
    if (! DB::table('product_prices')->where('product_id', $product['id'])->exists()) {
        DB::table('product_prices')->insert([
            'product_id' => $product['id'],
            'retail_price' => null,
            'compare_at_price' => null,
            'cost_price' => null,
            'currency' => 'GBP',
            'tax_class' => 'standard',
            'vat_rate' => null,
            'price_notes' => 'Created from shop picture evidence; verify retail price before activation.',
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]);
    }

    if (! DB::table('inventory_levels')->where('product_id', $product['id'])->where('inventory_location_id', $location['id'])->exists()) {
        DB::table('inventory_levels')->insert([
            'product_id' => $product['id'],
            'inventory_location_id' => $location['id'],
            'stock_quantity' => 0,
            'supplier' => null,
            'supplier_product_code' => null,
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]);
    }

    if (! DB::table('product_pos_profiles')->where('product_id', $product['id'])->exists()) {
        DB::table('product_pos_profiles')->insert([
            'product_id' => $product['id'],
            'receipt_name' => Str::limit($product['name'], 80, ''),
            'quick_search_keywords' => $product['search_keywords'] ?: searchKeywords($family, $plan),
            'pos_category' => $plan['root_catalogue_name'],
            'discount_allowed' => 1,
            'quick_sale_enabled' => 1,
            'tax_class' => 'standard',
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]);
    }

    $ecommerceExists = DB::table('product_ecommerce_profiles')
        ->where('product_family_id', $family['id'])
        ->where('product_id', $product['id'])
        ->where('profile_level', 'sku')
        ->exists();

    if (! $ecommerceExists) {
        DB::table('product_ecommerce_profiles')->insert([
            'product_family_id' => $family['id'],
            'product_id' => $product['id'],
            'profile_level' => 'sku',
            'online_title' => $product['name'],
            'short_description' => null,
            'long_description' => null,
            'seo_slug' => $product['slug'],
            'seo_title' => $product['name'],
            'seo_description' => null,
            'tags' => json_encode(array_values(array_filter([$family['brand_name'], $plan['product_type_name'], $family['family_name']]))),
            'is_published' => 0,
            'click_and_collect_enabled' => 1,
            'created_at' => $GLOBALS['now'],
            'updated_at' => $GLOBALS['now'],
        ]);
    }
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

/**
 * @param list<string> $categoryNames
 */
function inferProductType(string $name, array $categoryNames): string
{
    $text = Str::lower($name.' '.implode(' ', $categoryNames));

    if (containsAny($text, ['clipper', 'trimmer', 'shaver', 'hair dryer', 'straightener'])) {
        return 'Clipper / Shaver';
    }
    if (containsAny($text, ['edge control', 'smooth edges', 'edge gel', 'edge wax', 'perfect edges'])) {
        return 'Edge Control';
    }
    if (containsAny($text, ['relaxer', 'texturizer', 'texlax'])) {
        return 'Relaxer';
    }
    if (containsAny($text, ['hair colour', 'hair color', 'dye', 'semi-permanent', 'permanent powder', 'colorsilk', 'tintation', 'adore', 'manic panic', 'bigen'])) {
        return 'Hair Colour / Dye';
    }
    if (containsAny($text, ['neutralizing shampoo', 'shampoo', 'pre-shampoo'])) {
        return 'Shampoo';
    }
    if (containsAny($text, ['detangler', 'detangling'])) {
        return 'Detangler';
    }
    if (containsAny($text, ['leave-in conditioner', 'leave in conditioner', 'leave-in cream'])) {
        return 'Leave-In Conditioner';
    }
    if (containsAny($text, ['hair spray', 'sheen spray', 'braid sheen', 'weave spray', 'wig spray', 'mist', 'oil sheen'])) {
        return 'Hair Spray';
    }
    if (containsAny($text, ['conditioner', 'cond.'])) {
        return 'Conditioner';
    }
    if (containsAny($text, ['masque', 'mask', 'treatment', 'therapy', 'cholesterol', 'reconstructor', 'protein pack'])) {
        return 'Hair Treatment / Masque';
    }
    if (containsAny($text, ['styling gel', 'hair gel', 'loc & twist gel', 'lock & twist gel', 'twist gel', 'hold gel', 'jamm conditioning gel'])) {
        return 'Styling Gel';
    }
    if (containsAny($text, ['mousse', 'foam', 'wrap set'])) {
        return 'Mousse / Foam';
    }
    if (containsAny($text, ['curl cream', 'curling cream', 'custard', 'pudding', 'curl activator', 'curl refresher', 'curling souffle'])) {
        return 'Curl Cream / Custard';
    }
    if (containsAny($text, ['pomade', 'hair food', 'super gro', 'sulfur', 'beeswax', 'hair wax', 'gel-wax'])) {
        return 'Hair Pomade / Food';
    }
    if (containsAny($text, ['hair oil', 'growth oil', 'scalp oil', 'castor oil', 'tea tree oil', 'argan oil', 'coconut oil', 'olive oil therapy'])) {
        return 'Hair Oil';
    }
    if (containsAny($text, ['body lotion', 'hand and body lotion', 'moisturizer', 'moisturiser', 'lotion'])) {
        return 'Body Lotion';
    }
    if (containsAny($text, ['body cream', 'skin cream', 'face cream', 'cream'])) {
        return 'Skin Cream';
    }
    if (containsAny($text, ['body oil', 'baby oil', 'glycerin', 'glycerine'])) {
        return 'Body Oil';
    }
    if (containsAny($text, ['petroleum jelly', 'protecting jelly'])) {
        return 'Petroleum Jelly';
    }
    if (containsAny($text, ['shower gel'])) {
        return 'Shower Gel';
    }
    if (containsAny($text, ['scrub', 'exfoliant', 'exfoliating'])) {
        return 'Body Scrub';
    }
    if (containsAny($text, ['soap', 'black soap', 'cleanser'])) {
        return 'Soap / Cleanser';
    }
    if (containsAny($text, ['toner'])) {
        return 'Skin Toner';
    }
    if (containsAny($text, ['serum'])) {
        return 'Skin Serum';
    }
    if (containsAny($text, ['deodorant', 'roll on', 'roll-on'])) {
        return 'Deodorant';
    }
    if (containsAny($text, ['perfume', 'fragrance', 'eau de', 'cologne'])) {
        return 'Fragrance';
    }
    if (containsAny($text, ['make up', 'makeup', 'foundation', 'concealer', 'powder', 'lipstick', 'mascara', 'eyeliner'])) {
        return 'Cosmetics';
    }

    return 'General Product';
}

/**
 * @param list<string> $categorySlugs
 * @param list<string> $categoryNames
 */
function rootCatalogueName(string $productType, array $categorySlugs, array $categoryNames): string
{
    $text = Str::lower($productType.' '.implode(' ', $categoryNames));
    if (containsAny($text, ['clipper', 'dryer', 'shaver', 'straightener', 'trimmer'])) {
        return 'Electrical';
    }
    if (containsAny($text, ['makeup', 'cosmetic', 'foundation', 'concealer', 'lipstick', 'mascara'])) {
        return 'Cosmetics';
    }
    if (containsAny($text, ['hair', 'shampoo', 'conditioner', 'detangler', 'relaxer', 'edge', 'mousse', 'curl', 'pomade', 'styling gel', 'hair spray'])) {
        return 'Hair Products';
    }
    if (containsAny($text, ['soap', 'body', 'skin', 'deodorant', 'fragrance', 'petroleum', 'shower', 'scrub', 'lotion', 'toner', 'serum'])) {
        return 'Skin Care';
    }
    if (in_array('cosmetics', $categorySlugs, true)) {
        return 'Cosmetics';
    }
    if (in_array('hair', $categorySlugs, true)) {
        return 'Hair Products';
    }
    if (in_array('body-care', $categorySlugs, true)) {
        return 'Skin Care';
    }

    return 'General Products';
}

function searchKeywords(array $family, array $plan): string
{
    return implode(' ', array_values(array_filter([
        $plan['brand_name'],
        $plan['root_catalogue_name'],
        $plan['product_type_name'],
        $family['family_name'] ?? $plan['family_name'],
        $plan['product_name'],
        $plan['observed_product_name'],
        implode(' ', $plan['picture_ids']),
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

function normalizeKey(string $value): string
{
    $value = Str::ascii(Str::lower($value));
    $value = str_replace(['&', '+'], ' and ', $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
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

    $path = $dir.'/picture-draft-retail-import-'.($sync ? 'sync' : 'dry-run').'-'.date('Ymd-His').'.csv';
    $handle = fopen($path, 'wb');
    fputcsv($handle, ['action', 'brand', 'family', 'product', 'product_type', 'department', 'product_id', 'family_id', 'source_ids', 'picture_ids', 'source_rows']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}
