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

if (! Schema::hasTable('janson_products')) {
    fwrite(STDERR, "janson_products table does not exist. Import the cleaned Janson JSON first.\n");
    exit(1);
}

$query = DB::table('janson_products')
    ->where('name', '!=', '')
    ->orderBy('row_index');

if ($limit !== null) {
    $query->limit($limit);
}

$sourceRows = $query->get();

if ($sourceRows->isEmpty()) {
    echo "No Janson rows available.\n";
    exit(0);
}

$planned = $sourceRows->map(fn (object $row): array => planJansonProduct($row));

$summary = [
    'source_rows' => $sourceRows->count(),
    'planned_products' => $planned->count(),
    'planned_families' => $planned->pluck('family_key')->unique()->count(),
    'planned_brands' => $planned->pluck('brand_name')->unique()->count(),
    'with_cost_price' => $planned->filter(fn (array $plan): bool => $plan['cost_price'] !== null)->count(),
    'with_variants' => $planned->filter(fn (array $plan): bool => $plan['axes'] !== [])->count(),
    'review_flagged_sources' => $planned->filter(fn (array $plan): bool => $plan['review_flags'] !== [])->count(),
    'created_families' => 0,
    'created_products' => 0,
    'updated_products' => 0,
];

$result = DB::transaction(function () use ($planned, $sync, &$summary): array {
    $location = defaultInventoryLocation();
    $familyCache = [];

    foreach ($planned as $plan) {
        $familyKey = $plan['family_key'];
        if (! isset($familyCache[$familyKey])) {
            $familyResult = firstOrCreateJansonFamily($plan);
            $familyCache[$familyKey] = $familyResult['family'];
            if ($familyResult['created']) {
                $summary['created_families']++;
            }
        }

        $family = $familyCache[$familyKey];
        $brand = findOrCreateBrand($plan['brand_name']);
        $productResult = firstOrCreateJansonProduct($family, $brand, $plan, $location);

        if ($productResult['created']) {
            $summary['created_products']++;
        } else {
            $summary['updated_products']++;
        }
    }

    if (! $sync) {
        DB::rollBack();
    }

    return $summary;
});

echo ($sync ? "Janson retail product candidates created.\n" : "Janson retail product candidate dry run.\n");
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
 * @return array<string, mixed>
 */
function planJansonProduct(object $row): array
{
    $name = cleanName((string) $row->name);
    $category = cleanName((string) $row->category);
    $code = cleanName((string) $row->code);
    $reviewFlags = json_decode((string) ($row->review_flags ?? '[]'), true);
    $reviewFlags = is_array($reviewFlags) ? array_values($reviewFlags) : [];
    $identity = resolveBrandLine($category, $code, $name);
    $productType = resolveProductType($name, $category);
    $axes = extractAxes($name, $category, $productType);
    $familyName = buildFamilyName($name, $identity['brand_name'], $identity['line_name'], $productType, $axes, $category);
    $productName = buildProductName($identity['brand_name'], $name);
    $familyKey = normalizeKey($identity['brand_name'].'|'.$identity['line_name'].'|'.$productType.'|'.$familyName);
    $costPrice = $row->price_gbp !== null ? number_format((float) $row->price_gbp, 2, '.', '') : null;

    return [
        'source_id' => (int) $row->id,
        'source_row_id' => (string) $row->source_row_id,
        'page' => (int) $row->page,
        'page_row' => (int) $row->page_row,
        'code' => $code !== '' ? $code : null,
        'category' => $category,
        'source_category' => cleanName((string) $row->source_category),
        'source_name' => cleanName((string) ($row->source_name ?: $row->name)),
        'brand_name' => $identity['brand_name'],
        'line_name' => $identity['line_name'],
        'product_type_name' => $productType,
        'family_name' => $familyName,
        'product_name' => $productName,
        'family_key' => $familyKey,
        'axes' => $axes,
        'cost_price' => $costPrice,
        'is_new' => (bool) $row->is_new,
        'special_note' => $row->special_note ? cleanName((string) $row->special_note) : null,
        'review_flags' => $reviewFlags,
    ];
}

/**
 * @return array{brand_name:string,line_name:?string}
 */
function resolveBrandLine(string $category, string $code, string $name): array
{
    $categoryUpper = Str::upper($category);
    $special = specialCategoryMap();

    if (isset($special[$categoryUpper])) {
        return $special[$categoryUpper];
    }

    foreach (knownBrandPrefixes() as $prefix => $brand) {
        if (str_starts_with($categoryUpper, $prefix)) {
            $line = cleanName(substr($category, strlen($prefix)));
            $line = $line !== '' ? $line : null;

            return [
                'brand_name' => $brand,
                'line_name' => $line,
            ];
        }
    }

    $prefixMap = prefixBrandMap();
    $prefix = codePrefix($code);
    if (isset($prefixMap[$prefix])) {
        return $prefixMap[$prefix];
    }

    return [
        'brand_name' => canonicalName($category !== '' ? $category : 'Unknown'),
        'line_name' => null,
    ];
}

/**
 * @return array<string, array{brand_name:string,line_name:?string}>
 */
function specialCategoryMap(): array
{
    return [
        'A/B ULTIMATE ORIGINALS' => ['brand_name' => "Africa's Best", 'line_name' => 'Ultimate Originals'],
        'A PRIDE MOISTURE MIRACLE' => ['brand_name' => 'African Pride', 'line_name' => 'Moisture Miracle'],
        'AFRICA BLACK SOAPS' => ['brand_name' => 'African Black Soaps', 'line_name' => null],
        'AFRICA\'S BEST KIDS' => ['brand_name' => "Africa's Best", 'line_name' => 'Kids'],
        'ALIZA ACCESSORIES' => ['brand_name' => 'Aliza', 'line_name' => 'Accessories'],
        'AMPRO SHINE N JAM' => ['brand_name' => 'Ampro', 'line_name' => 'Shine N Jam'],
        'AS I AM JAMAICAN BLACK CASTOR OIL' => ['brand_name' => 'As I Am', 'line_name' => 'Jamaican Black Castor Oil'],
        'AS I AM LONG & LUXE' => ['brand_name' => 'As I Am', 'line_name' => 'Long & Luxe'],
        'AS I AM DRY & ITCHY' => ['brand_name' => 'As I Am', 'line_name' => 'Dry & Itchy'],
        'AS I AM BORN CURLY' => ['brand_name' => 'As I Am', 'line_name' => 'Born Curly'],
        'AS I AM ROSEMARY' => ['brand_name' => 'As I Am', 'line_name' => 'Rosemary'],
        'AS I AM RICE WATER' => ['brand_name' => 'As I Am', 'line_name' => 'Rice Water'],
        'BATANA OILS' => ['brand_name' => 'Batana', 'line_name' => 'Oils'],
        'CANTU SHEA BUTTER NATURAL' => ['brand_name' => 'Cantu', 'line_name' => 'Shea Butter Natural'],
        'CANTU SHEA BUTTER' => ['brand_name' => 'Cantu', 'line_name' => 'Shea Butter'],
        'CANTU AVOCADO' => ['brand_name' => 'Cantu', 'line_name' => 'Avocado'],
        'CANTU KIDS' => ['brand_name' => 'Cantu', 'line_name' => 'Kids'],
        'CAROTONE / CAROLISS' => ['brand_name' => 'Carotone', 'line_name' => 'Caroliss'],
        'CLERE GLYCERINE' => ['brand_name' => 'Clere', 'line_name' => 'Glycerine'],
        'CON BUTTER BLEND & FLAXSEED' => ['brand_name' => 'Creme of Nature', 'line_name' => 'Butter Blend & Flaxseed'],
        'CREME OF NATURE ARGAN COLOR' => ['brand_name' => 'Creme of Nature', 'line_name' => 'Argan Oil Color'],
        'CREME OF NATURE ARGAN CURLY' => ['brand_name' => 'Creme of Nature', 'line_name' => 'Argan Curl'],
        'CREME OF NATURE ARGAN MEN' => ['brand_name' => 'Creme of Nature', 'line_name' => 'Argan Men'],
        'CREME OF NATURE ARGAN' => ['brand_name' => 'Creme of Nature', 'line_name' => 'Argan Oil'],
        'CREME OF NATURE PURE HONEY' => ['brand_name' => 'Creme of Nature', 'line_name' => 'Pure Honey'],
        'PURE HONEY SCALP REFRESH' => ['brand_name' => 'Creme of Nature', 'line_name' => 'Pure Honey Scalp Refresh'],
        'D & L - BEAUTIFUL BEGINNINGS' => ['brand_name' => 'Dark & Lovely', 'line_name' => 'Beautiful Beginnings'],
        'DABUR VATIKA' => ['brand_name' => 'Dabur', 'line_name' => 'Vatika'],
        'DABUR HENNA' => ['brand_name' => 'Dabur', 'line_name' => 'Vatika Henna'],
        'DAGGET & RAMSDELL' => ['brand_name' => 'Daggett & Ramsdell', 'line_name' => null],
        'DIFEEL HAIR OIL' => ['brand_name' => 'Difeel', 'line_name' => 'Hair Oil'],
        'EBIN 24HR EDGE TAMER' => ['brand_name' => 'Ebin', 'line_name' => '24 Hour Edge Tamer'],
        'EBIN 24HR EDGE SLEEK' => ['brand_name' => 'Ebin', 'line_name' => '24 Hour Edge Sleek'],
        'EBIN WONDER LACE SPRAY' => ['brand_name' => 'Ebin', 'line_name' => 'Wonder Lace Spray'],
        'ECO KURVY - KOLLY' => ['brand_name' => 'Eco Style', 'line_name' => 'Kurly Koily'],
        'ECO STYLING GEL' => ['brand_name' => 'Eco Style', 'line_name' => 'Styling Gel'],
        'FAIR & WHITE GOLD' => ['brand_name' => 'Fair & White', 'line_name' => 'Gold'],
        'FAIR N WHITE GLUTATHION' => ['brand_name' => 'Fair & White', 'line_name' => 'Glutathion'],
        'FAIR N WHITE SO LEMON' => ['brand_name' => 'Fair & White', 'line_name' => 'So Lemon'],
        'FAIR AND WHITE EXCLUSIVE' => ['brand_name' => 'Fair & White', 'line_name' => 'Exclusive'],
        'FAIR AND WHITE MIX BRIGHTENING' => ['brand_name' => 'Fair & White', 'line_name' => 'Mix Brightening'],
        'FAIR AND WHITE CARROT' => ['brand_name' => 'Fair & White', 'line_name' => 'Carrot'],
        'FAIR & WHITE SO WHITE' => ['brand_name' => 'Fair & White', 'line_name' => 'So White'],
        'JAMAICAN MANGO & LIME' => ['brand_name' => 'Jamaican Mango & Lime', 'line_name' => null],
        'JUST FOR ME KIDS' => ['brand_name' => 'Just For Me', 'line_name' => 'Kids'],
        'LUSTER\'S PINK KIDS' => ['brand_name' => "Luster's Pink", 'line_name' => 'Kids'],
        'LUSTER\'S S CURL' => ['brand_name' => "Luster's S-Curl", 'line_name' => null],
        'MAKARI EXCLUSIVE (BROWN)' => ['brand_name' => 'Makari', 'line_name' => 'Exclusive'],
        'MAKARI EXTREME ARGAN & CARROT' => ['brand_name' => 'Makari', 'line_name' => 'Extreme Argan & Carrot'],
        'MAKARI NATURALLE' => ['brand_name' => 'Makari', 'line_name' => 'Naturalle'],
        'MAKARI (WHITE)' => ['brand_name' => 'Makari', 'line_name' => 'White'],
        'MAMADO PURE OILS' => ['brand_name' => 'Mamado', 'line_name' => 'Pure Oils'],
        'MIELLE POMEGRANATE & HONEY' => ['brand_name' => 'Mielle Organics', 'line_name' => 'Pomegranate & Honey'],
        'MIELLE ROSEMARY MINT' => ['brand_name' => 'Mielle Organics', 'line_name' => 'Rosemary Mint'],
        'PALMER\'S SKIN SUCCESS' => ['brand_name' => "Palmer's", 'line_name' => 'Skin Success'],
        'PALMERS COCOA BUTTER' => ['brand_name' => "Palmer's", 'line_name' => 'Cocoa Butter Formula'],
        'PALMERS COCONUT' => ['brand_name' => "Palmer's", 'line_name' => 'Coconut Oil Formula'],
        'PALMERS SHEA BUTTER' => ['brand_name' => "Palmer's", 'line_name' => 'Shea Butter Formula'],
        'PALMERS OLIVE OIL' => ['brand_name' => "Palmer's", 'line_name' => 'Olive Oil Formula'],
        'S M JAMAICAN BLACK CASTOR OIL' => ['brand_name' => 'Shea Moisture', 'line_name' => 'Jamaican Black Castor Oil'],
        'S M MANUKA HONEY & MAFURA OIL' => ['brand_name' => 'Shea Moisture', 'line_name' => 'Manuka Honey & Mafura Oil'],
        'S MOISTURE VIRGIN COCONUT OIL' => ['brand_name' => 'Shea Moisture', 'line_name' => 'Virgin Coconut Oil'],
        'SHEA MOISTURE COCONUT & HIBISCUS' => ['brand_name' => 'Shea Moisture', 'line_name' => 'Coconut & Hibiscus'],
        'SHEA MOISTURE RAW SHEA BUTTER' => ['brand_name' => 'Shea Moisture', 'line_name' => 'Raw Shea Butter'],
        'SHEA MOISTURE KIDS' => ['brand_name' => 'Shea Moisture', 'line_name' => 'Kids'],
        'SOFT N FREE BLACK CASTOR OIL' => ['brand_name' => "Soft 'N Free", 'line_name' => 'Black Castor Oil'],
        'SOFT N FREE NATURAL' => ['brand_name' => "Soft 'N Free", 'line_name' => 'Natural'],
        'SOFT N FREE' => ['brand_name' => "Soft 'N Free", 'line_name' => null],
        'SOFT N\'FREE PRETTY' => ['brand_name' => "Soft 'N Free", 'line_name' => 'Pretty'],
        'SOFT \'N WHITE SWISS PAPAYA' => ['brand_name' => "Soft 'N White", 'line_name' => 'Swiss Papaya'],
        'SUNNY ISLES JBCO' => ['brand_name' => 'Sunny Isle', 'line_name' => 'Jamaican Black Castor Oil'],
        'SUNNY ISLES KIDS' => ['brand_name' => 'Sunny Isle', 'line_name' => 'Kids'],
        'TALIAH WAAJID APPLE & ALOE' => ['brand_name' => 'Taliah Waajid', 'line_name' => 'Apple & Aloe'],
        'TALIAH WAAJID KINKY WAVY' => ['brand_name' => 'Taliah Waajid', 'line_name' => 'Kinky Wavy'],
        'TALIAH WAAJID LOVE MY LOCS' => ['brand_name' => 'Taliah Waajid', 'line_name' => 'Love My Locs'],
        'TALIAH WAAJID LOVE MY NATURAL HAIR' => ['brand_name' => 'Taliah Waajid', 'line_name' => 'Love My Natural Hair'],
    ];
}

/**
 * @return array<string, string>
 */
function knownBrandPrefixes(): array
{
    return [
        'AFRICAN PRIDE' => 'African Pride',
        'AFRICA\'S BEST' => "Africa's Best",
        'AUNT JACKIE\'S' => "Aunt Jackie's",
        'AS I AM' => 'As I Am',
        'BLUE MAGIC' => 'Blue Magic',
        'CAMILLE ROSE' => 'Camille Rose',
        'CANTU' => 'Cantu',
        'CARO LIGHT' => 'Caro Light',
        'CARO WHITE' => 'Caro White',
        'CLEAR ESSENCE' => 'Clear Essence',
        'CREME OF NATURE' => 'Creme of Nature',
        'DARK & LOVELY' => 'Dark & Lovely',
        'DAX' => 'Dax',
        'DIFEEL' => 'Difeel',
        'DOO GRO' => 'Doo Gro',
        'DR MIRACLE' => "Dr. Miracle's",
        'EBIN' => 'Ebin',
        'ECO' => 'Eco Style',
        'EDEN' => 'Eden',
        'FAIR & WHITE' => 'Fair & White',
        'FAIR AND WHITE' => 'Fair & White',
        'FANTASIA' => 'Fantasia IC',
        'GABRI' => 'Gabri',
        'GROGANICS' => 'Groganics',
        'JOHNSON\'S' => "Johnson's",
        'KERACARE' => 'KeraCare',
        'KOJIE-SAN' => 'Kojie-San',
        'LUSTER\'S PINK' => "Luster's Pink",
        'MAGIC' => 'Magic Collection',
        'MAKARI' => 'Makari',
        'MIELLE' => 'Mielle Organics',
        'MOTIONS' => 'Motions',
        'MURRAY\'S' => "Murray's",
        'ORS' => 'ORS',
        'PALMERS' => "Palmer's",
        'PALMER\'S' => "Palmer's",
        'QUEEN ELIZABETH' => 'Queen Elizabeth',
        'QUEEN HELENE' => 'Queen Helene',
        'RED ONE' => 'Red One',
        'REVLON' => 'Revlon',
        'SHEA MOISTURE' => 'Shea Moisture',
        'SOFT N FREE' => "Soft 'N Free",
        'STA SOF FRO' => 'Sta-Sof-Fro',
        'SUNNY ISLES' => 'Sunny Isle',
        'TALIAH WAAJID' => 'Taliah Waajid',
        'VASELINE' => 'Vaseline',
        'WAHL' => 'Wahl',
        'X-PRESSIONS' => 'X-Pression',
    ];
}

/**
 * @return array<string, array{brand_name:string,line_name:?string}>
 */
function prefixBrandMap(): array
{
    return [
        'ADO' => ['brand_name' => 'Adore', 'line_name' => 'Colours'],
        'ABK' => ['brand_name' => "Africa's Best", 'line_name' => 'Kids'],
        'ALZ' => ['brand_name' => 'Aliza', 'line_name' => null],
        'APM' => ['brand_name' => 'African Pride', 'line_name' => 'Moisture Miracle'],
        'ATO' => ['brand_name' => 'Atone', 'line_name' => null],
        'BM' => ['brand_name' => 'Blue Magic', 'line_name' => null],
        'CAL' => ['brand_name' => 'Caro Light', 'line_name' => null],
        'CAN' => ['brand_name' => 'Cantu', 'line_name' => null],
        'CND' => ['brand_name' => 'Creme of Nature', 'line_name' => 'Argan Oil Color'],
        'COC' => ['brand_name' => 'KeraCare', 'line_name' => null],
        'CPH' => ['brand_name' => 'Creme of Nature', 'line_name' => 'Pure Honey'],
        'CUK' => ['brand_name' => 'Curly Kids', 'line_name' => null],
        'DAX' => ['brand_name' => 'Dax', 'line_name' => null],
        'DNL' => ['brand_name' => 'Dark & Lovely', 'line_name' => null],
        'DNR' => ['brand_name' => 'Daggett & Ramsdell', 'line_name' => null],
        'DOG' => ['brand_name' => 'Doo Gro', 'line_name' => null],
        'FAN' => ['brand_name' => 'Fantasia IC', 'line_name' => null],
        'FNC' => ['brand_name' => 'Fair & White', 'line_name' => 'Carrot'],
        'JER' => ['brand_name' => 'Jergens', 'line_name' => null],
        'JML' => ['brand_name' => 'Jamaican Mango & Lime', 'line_name' => null],
        'MIE' => ['brand_name' => 'Mielle Organics', 'line_name' => null],
        'MKN' => ['brand_name' => 'Makari', 'line_name' => 'Naturalle'],
        'MOG' => ['brand_name' => 'Morgans', 'line_name' => null],
        'ORS' => ['brand_name' => 'ORS', 'line_name' => null],
        'PAL' => ['brand_name' => "Palmer's", 'line_name' => null],
        'POO' => ['brand_name' => "Palmer's", 'line_name' => 'Olive Oil Formula'],
        'SUN' => ['brand_name' => 'Sunny Isle', 'line_name' => 'Jamaican Black Castor Oil'],
        'VAS' => ['brand_name' => 'Vaseline', 'line_name' => null],
        'WCA' => ['brand_name' => 'Wahl', 'line_name' => 'Clipper Attachments'],
    ];
}

function codePrefix(string $code): string
{
    $keys = array_keys(prefixBrandMap());
    usort($keys, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($keys as $key) {
        if (str_starts_with($code, $key)) {
            return $key;
        }
    }

    preg_match('/^[A-Z]+/', $code, $match);

    return $match[0] ?? $code;
}

function resolveProductType(string $name, string $category): string
{
    $text = Str::lower($name.' '.$category);

    if (containsAny($text, ['dryer'])) return 'Hair Dryer';
    if (containsAny($text, ['straightener'])) return 'Hair Straightener';
    if (containsAny($text, ['curling tong'])) return 'Curling Tong';
    if (containsAny($text, ['trimmer'])) return 'Trimmer';
    if (containsAny($text, ['clipper blade', 'replacement blade', 'blade set'])) return 'Clipper Blade / Part';
    if (containsAny($text, ['clipper', 'shaver'])) return 'Clipper / Shaver';
    if (containsAny($text, ['comb', 'brush'])) return 'Comb / Brush';
    if (containsAny($text, ['hair colour spray'])) return 'Hair Colour Spray';
    if (containsAny($text, ['hair color', 'hair colour', 'dye', 'henna', 'adore colours', 'crazy color', 'bigen', 'colour culture'])) return 'Hair Colour / Dye';
    if (containsAny($text, ['peroxide', 'bleach powder'])) return 'Developer / Bleach';
    if (containsAny($text, ['relaxer'])) return 'Relaxer';
    if (containsAny($text, ['texturizer'])) return 'Texturizer';
    if (containsAny($text, ['shampoo'])) return 'Shampoo';
    if (containsAny($text, ['conditioner', ' cond ', ' cond'])) return containsAny($text, ['leave in', 'leave-in']) ? 'Leave-In Conditioner' : 'Conditioner';
    if (containsAny($text, ['detangler'])) return 'Detangler';
    if (containsAny($text, ['hair mask', 'masque', 'treatment', 'reconstructor'])) return 'Hair Treatment / Masque';
    if (containsAny($text, ['edge control', 'edge gel', 'edge wax', 'edge tamer'])) return 'Edge Control';
    if (containsAny($text, ['wax'])) return 'Hair Wax';
    if (containsAny($text, ['mousse', 'foam wrap', 'foaming mousse', 'styling foam'])) return 'Mousse / Foam';
    if (containsAny($text, ['spray', 'spritz', 'oil sheen'])) return 'Hair Spray';
    if (containsAny($text, ['styling gel', 'hair gel', ' gel'])) return 'Styling Gel';
    if (containsAny($text, ['pomade', 'hairdress', 'hair food'])) return 'Pomade / Hairdress';
    if (containsAny($text, ['curl cream', 'curling cream', 'custard', 'pudding', 'curl activator'])) return 'Curl Cream / Custard';
    if (containsAny($text, ['hair oil', 'scalp oil', 'black castor oil', 'jbco', 'oil moisturizer'])) return 'Hair Oil';
    if (containsAny($text, ['soap'])) return 'Soap';
    if (containsAny($text, ['shower gel'])) return 'Shower Gel';
    if (containsAny($text, ['body lotion', 'skin lotion', 'hand body lotion', 'body milk', 'clearing milk', 'fade milk'])) return 'Body Lotion';
    if (containsAny($text, ['body cream', 'beauty cream', 'face cream', 'facial cream', 'fade cream', 'lightening cream', 'whitening cream', 'complexion cream'])) return 'Skin Cream';
    if (containsAny($text, ['serum', 'elixir'])) return 'Skin Serum';
    if (containsAny($text, ['glycerine', 'glycerin'])) return 'Glycerine';
    if (containsAny($text, ['petroleum jelly', 'soft skin jelly'])) return 'Petroleum Jelly';
    if (containsAny($text, ['scrub', 'exfoliator'])) return 'Scrub / Exfoliator';
    if (containsAny($text, ['cleanser', 'tonic', 'toner', 'astringent', 'micellar'])) return 'Cleanser / Toner';
    if (containsAny($text, ['shave', 'shaving', 'bump'])) return 'Shaving / Bump Care';
    if (containsAny($text, ['body oil', 'pure oil', 'vitamin e oil', 'rose water'])) return 'Body Oil';
    if (containsAny($text, ['cream', 'creme'])) return containsAny($text, ['hair', 'curl', 'scalp']) ? 'Hair Cream' : 'Skin Cream';
    if (containsAny($text, ['gel'])) return containsAny($text, ['hair', 'edge', 'styling']) ? 'Styling Gel' : 'Skin Gel';
    if (containsAny($text, ['oil'])) return containsAny($text, ['hair', 'scalp']) ? 'Hair Oil' : 'Body Oil';

    return 'General Product';
}

/**
 * @return array<string, string>
 */
function extractAxes(string $name, string $category, string $productType): array
{
    $axes = [];

    $size = extractSize($name);
    if ($size !== '') {
        $axes['Size'] = $size;
    }

    $strength = extractStrength($name);
    if ($strength !== '') {
        $axes['Strength'] = $strength;
    }

    $pack = extractPack($name);
    if ($pack !== '') {
        $axes['Pack'] = $pack;
    }

    if ($productType === 'Hair Colour / Dye' || containsAny(Str::lower($category), ['colour', 'color', 'dye', 'bigen', 'adore', 'crazy'])) {
        $shade = extractShade($name, $category);
        if ($shade !== '') {
            $axes['Shade'] = $shade;
        }
    }

    return array_filter($axes);
}

function extractSize(string $value): string
{
    preg_match_all('/\b\d+(?:\.\d+)?\s?(?:ml|mL|ML|l|L|litres?|oz|OZ|g|gm|gr|kg|KG|lb|LB|pcs|Pcs|pc|sachets?|applications?|app)\b/u', $value, $matches);

    return collect($matches[0] ?? [])
        ->map(fn (string $match): string => normalizeMeasure($match))
        ->unique()
        ->implode(' + ');
}

function extractStrength(string $value): string
{
    if (preg_match('/\b(?:regular|normal|mild|super|extra|sensitive|coarse|extra strength|regular strength|extra st|reg st)\b/i', $value, $match)) {
        return Str::title($match[0]);
    }

    if (preg_match('/\b(?:10|20|30|40)\s?(?:vol|volume)\b/i', $value, $match)) {
        return strtoupper(str_replace(' ', '', $match[0]));
    }

    return '';
}

function extractPack(string $value): string
{
    $packs = [];
    if (preg_match_all('/\b\d+\s?x\b/i', $value, $matches)) {
        foreach ($matches[0] as $match) {
            $packs[] = strtoupper(str_replace(' ', '', $match));
        }
    }
    if (preg_match('/\b(?:single|twin|double|mega pack|value pack|eazi-pack|pack of \d+|pack)\b/i', $value, $match)) {
        $packs[] = Str::title($match[0]);
    }

    return collect($packs)->unique()->implode(' + ');
}

function extractShade(string $name, string $category): string
{
    $clean = removeMeasuresFromText($name);
    $clean = removePackFromText($clean);

    $clean = preg_replace('/\b(?:for women|for men|mens|men|beard|permanent|powder|liquid|hair|colour|color|dye|cream|creme|gel|shampoo)\b/i', ' ', $clean) ?? $clean;
    $clean = cleanName($clean);

    if ($clean === '') {
        return '';
    }

    return Str::limit($clean, 80, '');
}

/**
 * @param array<string, string> $axes
 */
function buildFamilyName(string $name, string $brand, ?string $line, string $productType, array $axes, string $category): string
{
    $base = removeMeasuresFromText($name);
    $base = removePackFromText($base);

    foreach ($axes as $axis => $value) {
        if ($axis === 'Shade' || $axis === 'Strength') {
            $base = preg_replace('/\b'.preg_quote($value, '/').'\b/i', ' ', $base) ?? $base;
        }
    }

    if ($productType === 'Hair Colour / Dye' && containsAny(Str::lower($category), ['adore', 'crazy color', 'bigen', 'dark & lovely dye', 'sta sof fro dye', 'colour culture'])) {
        $base = canonicalName($category);
    }

    $base = cleanName($base);
    if ($base === '' || Str::length($base) < 3) {
        $base = $productType;
    }

    $prefix = $brand;
    if ($line !== null && $line !== '' && ! sameText($line, $brand) && ! str_contains(Str::lower($base), Str::lower($line))) {
        $prefix .= ' '.$line;
    }

    if (str_starts_with(Str::lower($base), Str::lower($brand))) {
        return cleanName($base);
    }

    return cleanName($prefix.' '.$base);
}

function buildProductName(string $brand, string $name): string
{
    if (str_starts_with(Str::lower($name), Str::lower($brand))) {
        return cleanName($name);
    }

    return cleanName($brand.' '.$name);
}

/**
 * @return array{family: ProductFamily, created: bool}
 */
function firstOrCreateJansonFamily(array $plan): array
{
    $brand = findOrCreateBrand($plan['brand_name']);
    $existing = ProductFamily::query()
        ->where('brand_name', $plan['brand_name'])
        ->where('family_name', $plan['family_name'])
        ->where('product_type_name', $plan['product_type_name'])
        ->first();

    $created = false;
    if (! $existing) {
        $created = true;
        $existing = new ProductFamily([
            'slug' => uniqueSlug('product_families', 'slug', $plan['family_name']),
        ]);
    }

    $existing->fill([
        'brand_id' => $brand->id,
        'root_catalogue_name' => rootCatalogueName($plan['product_type_name']),
        'brand_name' => $plan['brand_name'],
        'line_name' => $plan['line_name'],
        'product_type_name' => $plan['product_type_name'],
        'family_name' => $plan['family_name'],
        'description' => $existing->description,
        'source_url' => null,
        'status' => 'draft',
        'published_at' => $existing->published_at ?: now(),
        'sort_order' => brandSort($plan['brand_name']),
    ])->save();

    ProductSource::query()->updateOrCreate(
        [
            'product_family_id' => $existing->id,
            'product_id' => null,
            'source_type' => 'janson_family',
            'source_table' => 'janson_products',
            'source_id' => null,
        ],
        [
            'confidence' => 'B',
            'notes' => 'Draft family created from cleaned Janson source. Cross-check against shop photos/intake before activation.',
        ],
    );

    publishFamilyEcommerceProfile($existing);

    return ['family' => $existing, 'created' => $created];
}

/**
 * @return array{product: Product, created: bool}
 */
function firstOrCreateJansonProduct(ProductFamily $family, Brand $brand, array $plan, InventoryLocation $location): array
{
    $source = ProductSource::query()
        ->where('source_type', 'janson_product')
        ->where('source_table', 'janson_products')
        ->where('source_id', $plan['source_id'])
        ->whereNotNull('product_id')
        ->first();

    $product = $source?->product_id ? Product::query()->find($source->product_id) : null;
    $created = false;

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

    $product->fill([
        'product_family_id' => $family->id,
        'brand_id' => $brand->id,
        'name' => $plan['product_name'],
        'sku' => $product->sku,
        'barcode' => $product->barcode,
        'receipt_name' => Str::limit($plan['product_name'], 80, ''),
        'search_keywords' => implode(' ', array_filter([
            $plan['brand_name'],
            $plan['line_name'],
            $plan['product_type_name'],
            $plan['family_name'],
            $plan['product_name'],
            $plan['code'],
        ])),
        'description' => CustomerProductDescription::clean($product->description),
        'status' => 'draft',
        'is_pos_active' => false,
        'is_ecommerce_active' => false,
        'is_inventory_tracked' => true,
        'sort_order' => (int) ($plan['page'] * 1000 + $plan['page_row']),
    ])->save();

    syncProductVariants($family, $product, $plan['axes']);
    publishProductOperationalProfiles($family, $product, $location, $plan);
    publishProductSource($family, $product, $plan);

    return ['product' => $product, 'created' => $created];
}

/**
 * @param array<string, string> $axes
 */
function syncProductVariants(ProductFamily $family, Product $product, array $axes): void
{
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
            'price_notes' => $plan['cost_price'] !== null ? 'Janson listed GBP price; verify before retail use.' : null,
        ],
    );

    InventoryLevel::query()->updateOrCreate(
        [
            'product_id' => $product->id,
            'inventory_location_id' => $location->id,
        ],
        [
            'stock_quantity' => 0,
            'supplier' => 'Janson',
            'supplier_product_code' => $plan['code'],
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
            'source_type' => 'janson_product',
            'source_table' => 'janson_products',
            'source_id' => $plan['source_id'],
        ],
        [
            'source_url' => null,
            'confidence' => $plan['review_flags'] === [] ? 'B' : 'C',
            'notes' => implode(' ', array_filter([
                "Janson source row {$plan['source_row_id']}; page {$plan['page']} row {$plan['page_row']}; code {$plan['code']}; category {$plan['category']}.",
                $plan['special_note'] ? "Source note: {$plan['special_note']}." : null,
                $plan['review_flags'] !== [] ? 'Review flags: '.implode(', ', $plan['review_flags']).'.' : null,
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

function rootCatalogueName(string $productType): string
{
    if (containsAny(Str::lower($productType), ['clipper', 'dryer', 'straightener', 'trimmer', 'curling tong'])) {
        return 'Electrical';
    }
    if (containsAny(Str::lower($productType), ['soap', 'body', 'skin', 'glycerine', 'petroleum', 'scrub', 'cleanser', 'shaving'])) {
        return 'Skin Care';
    }
    if (containsAny(Str::lower($productType), ['colour', 'relaxer', 'shampoo', 'conditioner', 'hair', 'edge', 'mousse', 'pomade', 'curl'])) {
        return 'Hair Products';
    }

    return 'General Products';
}

function variantTypeForAxis(string $axis): string
{
    return match ($axis) {
        'Size' => 'measurement',
        'Shade' => 'colour_name',
        'Strength' => 'text',
        'Pack' => 'count',
        default => 'text',
    };
}

function variantGroupSort(string $axis): int
{
    return match ($axis) {
        'Shade' => 10,
        'Size' => 20,
        'Strength' => 30,
        'Pack' => 40,
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

function removeMeasuresFromText(string $value): string
{
    $value = preg_replace('/\b\d+(?:\.\d+)?\s?(?:ml|mL|ML|l|L|litres?|oz|OZ|g|gm|gr|kg|KG|lb|LB|pcs|Pcs|pc|sachets?|applications?|app)\b/u', ' ', $value) ?? $value;

    return cleanName($value);
}

function removePackFromText(string $value): string
{
    $value = preg_replace('/\b\d+\s?x\b/i', ' ', $value) ?? $value;
    $value = preg_replace('/\b(?:single|twin|double|mega pack|value pack|eazi-pack|pack of \d+)\b/i', ' ', $value) ?? $value;

    return cleanName($value);
}

function normalizeMeasure(string $value): string
{
    $value = preg_replace('/\s+/', '', trim($value)) ?? $value;
    $value = str_ireplace(['LITRES', 'LITRE'], 'L', $value);
    $value = str_ireplace(['PCS', 'PC'], 'pcs', $value);

    return str_ireplace(['ML', 'Mls', 'MLS'], 'ml', $value);
}

function cleanName(string $value): string
{
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;

    return trim($value, " \t\n\r\0\x0B-");
}

function canonicalName(string $value): string
{
    $known = [
        'DAX' => 'Dax',
        'ORS' => 'ORS',
        'TCB' => 'TCB',
        'KTC' => 'KTC',
        'E45' => 'E45',
    ];

    $upper = Str::upper(trim($value));

    return $known[$upper] ?? Str::headline(Str::lower($value));
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

function sameText(?string $a, ?string $b): bool
{
    return Str::lower(trim((string) $a)) === Str::lower(trim((string) $b));
}
