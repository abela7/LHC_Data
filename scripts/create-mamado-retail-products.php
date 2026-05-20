<?php

use App\Models\Brand;
use App\Models\InventoryLevel;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductEcommerceProfile;
use App\Models\ProductFamily;
use App\Models\ProductPosProfile;
use App\Models\ProductPrice;
use App\Models\ProductSource;
use App\Models\ProductVariantGroup;
use App\Models\ProductVariantOption;
use App\Models\ProductVariantValue;
use App\Support\CustomerProductDescription;
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
$includeHairExtensions = array_key_exists('include-hair-extensions', $args);

if (! Schema::hasTable('mamado_products')) {
    fwrite(STDERR, "mamado_products table does not exist.\n");
    exit(1);
}

$query = DB::table('mamado_products as mp')
    ->leftJoin('product_sources as existing_source', function ($join): void {
        $join->on('existing_source.source_id', '=', 'mp.id')
            ->where('existing_source.source_type', '=', 'mamado_product');
    })
    ->whereNull('existing_source.id')
    ->where('mp.item_description', '!=', '')
    ->where('mp.family_name', '!=', '')
    ->select('mp.*')
    ->orderBy('mp.brand_label')
    ->orderBy('mp.family_name')
    ->orderBy('mp.item_code');

if (! $includeHairExtensions) {
    $query->whereNotIn('mp.brand_label', hairExtensionBrands());
}

if ($onlyBrand !== '') {
    $query->where('mp.brand_label', $onlyBrand);
}

if ($limit !== null) {
    $query->limit($limit);
}

$sourceRows = $query->get();

if ($sourceRows->isEmpty()) {
    echo "No unlinked Mamado retail rows found.\n";
    exit(0);
}

$planned = $sourceRows
    ->map(fn (object $row): array => planMamadoProduct($row))
    ->filter(fn (array $plan): bool => $plan['brand_name'] !== '' && $plan['family_name'] !== '' && $plan['product_name'] !== '')
    ->values();

$summary = [
    'source_rows' => $sourceRows->count(),
    'planned_products' => $planned->count(),
    'planned_families' => $planned->pluck('family_key')->unique()->count(),
    'planned_brands' => $planned->pluck('brand_name')->unique()->count(),
    'with_gross_unit_price' => $planned->filter(fn (array $plan): bool => $plan['cost_price'] !== null)->count(),
    'with_variants' => $planned->filter(fn (array $plan): bool => $plan['axes'] !== [])->count(),
    'review_pending_sources' => $planned->filter(fn (array $plan): bool => $plan['status'] === 'variant_review_pending')->count(),
    'created_families' => 0,
    'updated_families' => 0,
    'created_products' => 0,
    'updated_products' => 0,
    'linked_existing_products' => 0,
];

$result = DB::transaction(function () use ($planned, $sync, &$summary): array {
    $location = defaultInventoryLocation();
    $familyCache = [];

    foreach ($planned as $plan) {
        $familyKey = $plan['family_key'];
        if (! isset($familyCache[$familyKey])) {
            $familyResult = firstOrCreateMamadoFamily($plan);
            $familyCache[$familyKey] = $familyResult['family'];
            $familyResult['created'] ? $summary['created_families']++ : $summary['updated_families']++;
        }

        $family = $familyCache[$familyKey];
        $brand = findOrCreateBrand($plan['brand_name']);
        $productResult = firstOrCreateMamadoProduct($family, $brand, $plan, $location);

        if ($productResult['created']) {
            $summary['created_products']++;
        } else {
            $summary['updated_products']++;
            if ($productResult['linked_existing']) {
                $summary['linked_existing_products']++;
            }
        }
    }

    if (! $sync) {
        DB::rollBack();
    }

    return $summary;
});

echo ($sync ? "Mamado retail product candidates created.\n" : "Mamado retail product candidate dry run.\n");
foreach ($result as $key => $value) {
    echo "{$key}: {$value}\n";
}
if (! $sync) {
    echo "Run with --sync to write draft product families/products.\n";
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
 * @return array<int, string>
 */
function hairExtensionBrands(): array
{
    return [
        'Cherish',
        'Obsession',
        'Pure NaturALL',
        'Impression',
    ];
}

/**
 * @return array<string, mixed>
 */
function planMamadoProduct(object $row): array
{
    $brandName = cleanName((string) ($row->brand_label ?: 'Unknown'));
    $familyName = cleanName((string) ($row->family_name ?: $row->item_description));
    $sourceName = cleanName((string) $row->item_description);
    $axes = parseVariantAxes((string) $row->variant_name);
    $productType = inferProductType($familyName, $sourceName, $brandName);
    $rootCatalogue = rootCatalogueName($productType);
    $productName = buildProductName($brandName, $familyName, $axes, $sourceName);
    $costPrice = $row->gross_unit_price !== null ? number_format((float) $row->gross_unit_price, 2, '.', '') : null;
    $confidence = cleanName((string) $row->status) === 'variant_review_pending' ? 'C' : 'B';

    return [
        'source_id' => (int) $row->id,
        'item_code' => cleanName((string) $row->item_code),
        'source_order_number' => cleanName((string) $row->source_order_number),
        'source_order_date' => $row->source_order_date ? (string) $row->source_order_date : null,
        'brand_name' => $brandName,
        'line_name' => null,
        'root_catalogue_name' => $rootCatalogue,
        'product_type_name' => $productType,
        'family_name' => $familyName,
        'product_name' => $productName,
        'source_name' => $sourceName,
        'variant_name' => cleanName((string) $row->variant_name),
        'axes' => $axes,
        'family_key' => normalizeKey($brandName.'|'.$familyName),
        'cost_price' => $costPrice,
        'status' => cleanName((string) $row->status),
        'confidence' => $confidence,
        'notes' => cleanName((string) $row->notes),
    ];
}

/**
 * @param array<string, string> $axes
 */
function buildProductName(string $brandName, string $familyName, array $axes, string $sourceName): string
{
    $name = $familyName;
    if ($brandName !== 'Unknown' && ! Str::startsWith(Str::lower($name), Str::lower($brandName))) {
        $name = cleanName($brandName.' '.$name);
    }

    $extraValues = [];
    $familyNorm = normalizeKey($name);
    foreach ($axes as $axis => $value) {
        $value = cleanName($value);
        if ($value === '' || Str::lower($value) === 'standard') {
            continue;
        }

        if (str_contains($familyNorm, normalizeKey($value))) {
            continue;
        }

        $extraValues[] = $value;
    }

    if ($extraValues === [] && normalizeKey($sourceName) !== '' && normalizeKey($sourceName) !== normalizeKey($name)) {
        $sourceWithoutCommercialNoise = removeCommercialNoise($sourceName);
        if (Str::startsWith(Str::lower($sourceWithoutCommercialNoise), Str::lower($name))) {
            $tail = cleanName(substr($sourceWithoutCommercialNoise, strlen($name)));
            if ($tail !== '') {
                $extraValues[] = $tail;
            }
        }
    }

    if ($extraValues !== []) {
        $firstAxis = array_key_first($axes);
        $separator = in_array($firstAxis, ['Variant', 'Colour', 'Shade', 'Scent', 'Formula'], true) ? ' - ' : ' ';
        $name .= $separator.implode(' ', array_unique($extraValues));
    }

    return cleanName($name);
}

/**
 * @return array<string, string>
 */
function parseVariantAxes(string $variantName): array
{
    $variantName = cleanName($variantName);
    if ($variantName === '') {
        return [];
    }

    $axes = [];
    foreach (preg_split('/\s*;\s*/', $variantName) ?: [] as $part) {
        if (! str_contains($part, ':')) {
            continue;
        }

        [$axis, $value] = explode(':', $part, 2);
        $axis = canonicalAxis($axis);
        $value = cleanName($value);
        if ($axis === '' || $value === '') {
            continue;
        }

        $axes[$axis] = $value;
    }

    return $axes;
}

function canonicalAxis(string $axis): string
{
    $axis = Str::lower(cleanName($axis));

    return match ($axis) {
        'color', 'colour', 'col' => 'Colour',
        'shade' => 'Shade',
        'size' => 'Size',
        'strength' => 'Strength',
        'pack', 'pack count' => 'Pack',
        'scent', 'fragrance' => 'Scent',
        'formula' => 'Formula',
        'variant' => 'Variant',
        default => Str::headline($axis),
    };
}

function inferProductType(string $familyName, string $sourceName, string $brandName): string
{
    $text = Str::lower($brandName.' '.$familyName.' '.$sourceName);

    if (containsAny($text, ['clipper', 'trimmer', 'shaver', 'hair dryer', 'straightener'])) {
        return 'Clipper / Shaver';
    }
    if (containsAny($text, ['edge control', 'smooth edges', 'edge gel', 'edge wax'])) {
        return 'Edge Control';
    }
    if (containsAny($text, ['relaxer', 'texturizer', 'texlax'])) {
        return 'Relaxer';
    }
    if (containsAny($text, ['hair colour', 'hair color', 'dye', 'semi-permanent', 'colorsilk', 'adore', 'manic panic', 'bigen'])) {
        return 'Hair Colour / Dye';
    }
    if (containsAny($text, ['neutralizing shampoo', 'shampoo', 'pre-shampoo'])) {
        return 'Shampoo';
    }
    if (containsAny($text, ['leave-in conditioner', 'leave in conditioner', 'leave-in cream'])) {
        return 'Leave-In Conditioner';
    }
    if (containsAny($text, ['conditioner', 'cond.'])) {
        return 'Conditioner';
    }
    if (containsAny($text, ['masque', 'mask', 'treatment', 'cholesterol', 'reconstructor'])) {
        return 'Hair Treatment / Masque';
    }
    if (containsAny($text, ['styling gel', 'loc & twist gel', 'lock & twist gel', 'twist gel', 'hold gel'])) {
        return 'Styling Gel';
    }
    if (containsAny($text, ['mousse', 'foam', 'wrap set'])) {
        return 'Mousse / Foam';
    }
    if (containsAny($text, ['curl cream', 'curling cream', 'custard', 'pudding', 'curl activator', 'curl refresher'])) {
        return 'Curl Cream / Custard';
    }
    if (containsAny($text, ['hair spray', 'sheen spray', 'braid sheen', 'weave spray', 'wig spray', 'mist'])) {
        return 'Hair Spray';
    }
    if (containsAny($text, ['pomade', 'hair food', 'super gro', 'sulfur', 'wax'])) {
        return 'Hair Pomade / Food';
    }
    if (containsAny($text, ['hair oil', 'growth oil', 'scalp oil', 'castor oil', 'tea tree oil', 'argan oil', 'coconut oil', 'olive oil therapy'])) {
        return 'Hair Oil';
    }
    if (containsAny($text, ['body lotion', 'lotion'])) {
        return 'Body Lotion';
    }
    if (containsAny($text, ['body cream', 'skin cream', 'face cream', 'cream'])) {
        return 'Skin Cream';
    }
    if (containsAny($text, ['body oil', 'baby oil', 'glycerin', 'glycerine'])) {
        return 'Body Oil';
    }
    if (containsAny($text, ['soap', 'black soap', 'cleanser'])) {
        return 'Soap';
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
    if (containsAny($text, ['perfume', 'fragrance', 'eau de'])) {
        return 'Fragrance';
    }

    return 'General Product';
}

function rootCatalogueName(string $productType): string
{
    $text = Str::lower($productType);
    if (containsAny($text, ['clipper', 'dryer', 'shaver', 'straightener', 'trimmer'])) {
        return 'Electrical';
    }
    if (containsAny($text, ['soap', 'body', 'skin', 'deodorant', 'fragrance'])) {
        return 'Skin Care';
    }
    if (containsAny($text, ['hair', 'shampoo', 'conditioner', 'relaxer', 'edge', 'mousse', 'curl'])) {
        return 'Hair Products';
    }

    return 'General Products';
}

/**
 * @return array{family: ProductFamily, created: bool}
 */
function firstOrCreateMamadoFamily(array $plan): array
{
    $brand = findOrCreateBrand($plan['brand_name']);
    $family = ProductFamily::query()
        ->where('brand_name', $plan['brand_name'])
        ->where('family_name', $plan['family_name'])
        ->first();

    $created = false;
    if (! $family) {
        $created = true;
        $family = new ProductFamily([
            'slug' => uniqueSlug('product_families', 'slug', $plan['family_name']),
        ]);
    }

    $family->fill([
        'brand_id' => $brand->id,
        'root_catalogue_name' => $family->root_catalogue_name ?: $plan['root_catalogue_name'],
        'brand_name' => $plan['brand_name'],
        'line_name' => $family->line_name ?: $plan['line_name'],
        'product_type_name' => $family->product_type_name ?: $plan['product_type_name'],
        'family_name' => $plan['family_name'],
        'description' => CustomerProductDescription::clean($family->description),
        'source_url' => $family->source_url,
        'status' => 'draft',
        'published_at' => $family->published_at ?: now(),
        'sort_order' => brandSort($plan['brand_name']),
    ])->save();

    ProductSource::query()->updateOrCreate(
        [
            'product_family_id' => $family->id,
            'product_id' => null,
            'source_type' => 'mamado_family',
            'source_table' => 'mamado_products',
            'source_id' => null,
        ],
        [
            'confidence' => 'C',
            'notes' => 'Draft family has Mamado order-history evidence. Cross-check shop presence, image, barcode, stock and retail price before activation.',
        ],
    );

    publishFamilyEcommerceProfile($family);

    return ['family' => $family, 'created' => $created];
}

/**
 * @return array{product: Product, created: bool, linked_existing: bool}
 */
function firstOrCreateMamadoProduct(ProductFamily $family, Brand $brand, array $plan, InventoryLocation $location): array
{
    $source = ProductSource::query()
        ->where('source_type', 'mamado_product')
        ->where('source_table', 'mamado_products')
        ->where('source_id', $plan['source_id'])
        ->whereNotNull('product_id')
        ->first();

    $product = $source?->product_id ? Product::query()->find($source->product_id) : null;
    $created = false;
    $linkedExisting = false;

    if (! $product) {
        $product = findExistingProduct($plan['brand_name'], $plan['product_name']);
        $linkedExisting = (bool) $product;
    }

    if (! $product) {
        $product = Product::query()
            ->where('product_family_id', $family->id)
            ->where('name', $plan['product_name'])
            ->first();
    }

    if (! $product) {
        $product = new Product([
            'slug' => uniqueSlug('products', 'slug', $plan['product_name'], null, ['product_family_id' => $family->id]),
        ]);
        $created = true;
    }

    $targetFamily = $product->exists && $product->product_family_id ? ProductFamily::query()->find($product->product_family_id) ?: $family : $family;

    $product->fill([
        'product_family_id' => $targetFamily->id,
        'brand_id' => $brand->id,
        'name' => $product->name ?: $plan['product_name'],
        'sku' => $product->sku,
        'barcode' => $product->barcode,
        'receipt_name' => Str::limit($product->name ?: $plan['product_name'], 80, ''),
        'inventory_name' => $product->inventory_name ?: ($product->name ?: $plan['product_name']),
        'search_keywords' => implode(' ', array_filter([
            $plan['brand_name'],
            $plan['product_type_name'],
            $plan['family_name'],
            $plan['product_name'],
            $plan['item_code'],
            $plan['source_name'],
        ])),
        'description' => CustomerProductDescription::clean($product->description),
        'status' => 'draft',
        'is_pos_active' => false,
        'is_ecommerce_active' => false,
        'is_inventory_tracked' => true,
        'sort_order' => brandSort($plan['brand_name']),
    ])->save();

    syncProductVariants($targetFamily, $product, $plan['axes']);
    publishProductOperationalProfiles($targetFamily, $product, $location, $plan);
    publishProductSource($targetFamily, $product, $plan);

    return ['product' => $product, 'created' => $created, 'linked_existing' => $linkedExisting];
}

function findExistingProduct(string $brandName, string $productName): ?Product
{
    return Product::query()
        ->join('product_families as pf', 'pf.id', '=', 'products.product_family_id')
        ->where('pf.brand_name', $brandName)
        ->whereRaw('lower(products.name) = ?', [Str::lower($productName)])
        ->select('products.*')
        ->first();
}

/**
 * @param array<string, string> $axes
 */
function syncProductVariants(ProductFamily $family, Product $product, array $axes): void
{
    if ($axes === []) {
        return;
    }

    ProductVariantValue::query()->where('product_id', $product->id)->delete();

    foreach ($axes as $axis => $label) {
        $group = ProductVariantGroup::query()->updateOrCreate(
            [
                'product_family_id' => $family->id,
                'name' => $axis,
            ],
            [
                'variant_type' => variantTypeForAxis($axis),
                'sort_order' => variantGroupSort($axis),
            ],
        );

        $option = ProductVariantOption::query()->updateOrCreate(
            [
                'product_variant_group_id' => $group->id,
                'label' => $label,
            ],
            [
                'value' => $label,
                'sort_order' => optionSort($label),
            ],
        );

        ProductVariantValue::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'product_variant_group_id' => $group->id,
            ],
            [
                'product_variant_option_id' => $option->id,
            ],
        );
    }
}

function publishProductOperationalProfiles(ProductFamily $family, Product $product, InventoryLocation $location, array $plan): void
{
    ProductPrice::query()->updateOrCreate(
        ['product_id' => $product->id],
        [
            'retail_price' => null,
            'compare_at_price' => null,
            'cost_price' => $plan['cost_price'],
            'currency' => 'GBP',
            'tax_class' => 'standard',
            'vat_rate' => null,
            'price_notes' => $plan['cost_price'] !== null ? 'Mamado gross unit price; verify before retail use.' : null,
        ],
    );

    InventoryLevel::query()->updateOrCreate(
        [
            'product_id' => $product->id,
            'inventory_location_id' => $location->id,
        ],
        [
            'stock_quantity' => 0,
            'supplier' => 'Mamado',
            'supplier_product_code' => $plan['item_code'],
        ],
    );

    ProductPosProfile::query()->updateOrCreate(
        ['product_id' => $product->id],
        [
            'receipt_name' => Str::limit($product->name, 80, ''),
            'quick_search_keywords' => $product->search_keywords,
            'pos_category' => $family->root_catalogue_name,
            'discount_allowed' => true,
            'quick_sale_enabled' => true,
            'tax_class' => 'standard',
        ],
    );

    ProductEcommerceProfile::query()->updateOrCreate(
        [
            'product_id' => $product->id,
            'profile_level' => 'sku',
        ],
        [
            'product_family_id' => $family->id,
            'online_title' => $product->name,
            'short_description' => null,
            'long_description' => null,
            'seo_slug' => $product->slug,
            'seo_title' => $product->name,
            'seo_description' => null,
            'tags' => array_values(array_filter([$family->brand_name, $family->line_name, $family->product_type_name])),
            'is_published' => false,
            'click_and_collect_enabled' => true,
        ],
    );
}

function publishFamilyEcommerceProfile(ProductFamily $family): void
{
    ProductEcommerceProfile::query()->updateOrCreate(
        [
            'product_family_id' => $family->id,
            'profile_level' => 'family',
        ],
        [
            'product_id' => null,
            'online_title' => $family->family_name,
            'short_description' => null,
            'long_description' => CustomerProductDescription::clean($family->description),
            'seo_slug' => $family->slug,
            'seo_title' => $family->family_name,
            'seo_description' => null,
            'tags' => array_values(array_filter([$family->brand_name, $family->line_name, $family->product_type_name])),
            'is_published' => false,
            'click_and_collect_enabled' => true,
        ],
    );
}

function publishProductSource(ProductFamily $family, Product $product, array $plan): void
{
    ProductSource::query()->updateOrCreate(
        [
            'product_family_id' => $family->id,
            'product_id' => $product->id,
            'source_type' => 'mamado_product',
            'source_table' => 'mamado_products',
            'source_id' => $plan['source_id'],
        ],
        [
            'source_url' => url('/mamado-products/'.$plan['source_id']),
            'confidence' => $plan['confidence'],
            'notes' => implode(' ', array_filter([
                "Mamado order-history item {$plan['item_code']}: {$plan['source_name']}.",
                $plan['source_order_number'] ? "Seen on order {$plan['source_order_number']}".($plan['source_order_date'] ? " ({$plan['source_order_date']})" : '').'.' : null,
                $plan['variant_name'] ? "Parsed variant: {$plan['variant_name']}." : null,
                $plan['status'] === 'variant_review_pending' ? 'Variant parsing was not fully safe; review before activation.' : null,
                'Draft supplier-derived candidate. Cross-check shop presence, packaging, image, barcode, stock and retail price before activation.',
            ])),
        ],
    );
}

function findOrCreateBrand(string $name): Brand
{
    $name = cleanName($name) ?: 'Unknown';
    $brand = Brand::query()->where('name', $name)->first();

    if ($brand) {
        return $brand;
    }

    return Brand::query()->create([
        'name' => $name,
        'slug' => uniqueSlug('brands', 'slug', $name),
        'is_active' => true,
        'is_generic' => $name === 'Unknown',
    ]);
}

function defaultInventoryLocation(): InventoryLocation
{
    return InventoryLocation::query()->firstOrCreate(
        ['slug' => 'shop-floor'],
        [
            'name' => 'Shop Floor',
            'location_type' => 'shop',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ],
    );
}

function variantTypeForAxis(string $axis): string
{
    return match ($axis) {
        'Colour', 'Shade' => 'colour_name',
        'Size' => 'measurement',
        'Strength', 'Formula', 'Scent' => 'text',
        'Pack' => 'count',
        default => 'text',
    };
}

function variantGroupSort(string $axis): int
{
    return match ($axis) {
        'Shade', 'Colour' => 10,
        'Size' => 20,
        'Strength' => 30,
        'Formula', 'Scent' => 40,
        'Pack' => 50,
        default => 100,
    };
}

function optionSort(string $value): int
{
    if (preg_match('/^\d+/', $value, $match)) {
        return ((int) $match[0]) * 10;
    }

    return brandSort($value);
}

function brandSort(string $value): int
{
    $letter = Str::upper(trim($value))[0] ?? 'Z';

    return max(1, ord($letter) - 64) * 10;
}

/**
 * @param array<string, mixed> $scope
 */
function uniqueSlug(string $table, string $column, string $name, ?int $ignoreId = null, array $scope = []): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $i = 2;

    while (slugExists($table, $column, $slug, $ignoreId, $scope)) {
        $slug = "{$base}-{$i}";
        $i++;
    }

    return $slug;
}

/**
 * @param array<string, mixed> $scope
 */
function slugExists(string $table, string $column, string $slug, ?int $ignoreId = null, array $scope = []): bool
{
    $query = DB::table($table)->where($column, $slug);

    foreach ($scope as $scopeColumn => $scopeValue) {
        $query->where($scopeColumn, $scopeValue);
    }

    if ($ignoreId !== null) {
        $query->where('id', '!=', $ignoreId);
    }

    return $query->exists();
}

function removeCommercialNoise(string $value): string
{
    $value = preg_replace('/\b(?:doz|dozen|price per dz|price per dozen|bonus|ea|each|pk|pack|box)\b/i', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;

    return cleanName($value);
}

function cleanName(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\r", "\n", "\t", "\xc2\xa0"], ' ', $value);
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;

    return trim($value, " \t\n\r\0\x0B-");
}

function normalizeKey(string $value): string
{
    return Str::of($value)
        ->lower()
        ->replaceMatches('/[^a-z0-9]+/u', ' ')
        ->squish()
        ->value();
}

/**
 * @param array<int, string> $needles
 */
function containsAny(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if (str_contains($haystack, $needle)) {
            return true;
        }
    }

    return false;
}
