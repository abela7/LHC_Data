<?php

use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueLine;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dryRun = in_array('--dry-run', $argv, true);
$fresh = in_array('--fresh', $argv, true);
$pdfPath = argumentValue('--pdf=', $argv)
    ?: "C:\\Users\\Abela\\Desktop\\Khan\\JANSON PRODUCT LIST Dec'25.pdf";

$payload = extractJansonPdf($pdfPath);
$records = collect($payload['records'] ?? [])
    ->map(fn (array $record): array => structureRecord($record))
    ->filter(fn (array $record): bool => $record['code'] !== '' && $record['sku_name'] !== '')
    ->values();

$records = markDuplicateVariantSignatures($records);
$groups = $records->groupBy(fn (array $record): string => implode('|', [
    $record['catalogue_name'],
    $record['brand'],
    $record['line'],
    $record['product_type'],
    $record['style_name'],
]));

if ($dryRun) {
    echo "Janson Beauty PDF dry run.\n";
    echo 'PDF: '.$pdfPath."\n";
    echo 'Pages: '.($payload['page_count'] ?? 'unknown')."\n";
    echo 'Rows extracted: '.count($payload['records'] ?? [])."\n";
    echo 'Structured SKUs: '.$records->count()."\n";
    echo 'Style families: '.$groups->count()."\n\n";

    echo "By catalogue:\n";
    $records
        ->groupBy('catalogue_name')
        ->sortKeys()
        ->each(fn (Collection $items, string $catalogue): null => printLine("  {$catalogue}: {$items->count()} SKUs, ".$items->pluck('style_name')->unique()->count().' families'));

    echo "\nTop brands:\n";
    $records
        ->groupBy('brand')
        ->map->count()
        ->sortDesc()
        ->take(50)
        ->each(fn (int $count, string $brand): null => printLine("  {$brand}: {$count}"));

    echo "\nSample families:\n";
    $groups
        ->take(80)
        ->each(function (Collection $items): void {
            $first = $items->first();
            echo "  {$first['catalogue_name']} > {$first['brand']} > {$first['line']} > {$first['product_type']} > {$first['style_name']} ({$items->count()} SKUs)\n";
        });

    exit(0);
}

$summary = DB::transaction(function () use ($records, $groups, $fresh): array {
    $catalogueDefinitions = catalogueDefinitions();
    $targetSlugs = array_column($catalogueDefinitions, 'slug');

    if ($fresh) {
        BrandCatalogue::query()
            ->whereIn('slug', $targetSlugs)
            ->delete();
    }

    $catalogues = [];
    foreach ($catalogueDefinitions as $definition) {
        $catalogue = BrandCatalogue::query()->firstOrCreate(
            ['slug' => $definition['slug']],
            [
                'name' => $definition['name'],
                'note' => $definition['note'],
                'is_active' => true,
                'sort_order' => $definition['sort_order'],
            ],
        );

        $catalogue->fill([
            'name' => $definition['name'],
            'note' => $definition['note'],
            'is_active' => true,
            'sort_order' => $definition['sort_order'],
        ])->save();

        $catalogues[$definition['name']] = $catalogue;
    }

    $codes = $records->pluck('code')->unique()->values();
    $sourceEvidence = sourceEvidenceForCodes($codes);

    $createdSkus = 0;
    $updatedSkus = 0;
    $brandIds = [];
    $lineIds = [];
    $productTypeIds = [];
    $styleIds = [];

    foreach ($groups as $groupRecords) {
        $first = $groupRecords->first();
        $catalogue = $catalogues[$first['catalogue_name']];

        $brand = BrandCatalogueBrand::query()->firstOrCreate(
            [
                'brand_catalogue_id' => $catalogue->id,
                'name' => $first['brand'],
            ],
            [
                'slug' => scopedSlug(BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id), $first['brand']),
                'note' => null,
                'is_active' => true,
                'sort_order' => brandSort($first['brand']),
            ],
        );

        $brand->fill([
            'is_active' => true,
            'sort_order' => min((int) $brand->sort_order, brandSort($first['brand'])),
        ])->save();

        $line = BrandCatalogueLine::query()->firstOrCreate(
            [
                'brand_catalogue_brand_id' => $brand->id,
                'name' => $first['line'],
            ],
            [
                'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), $first['line']),
                'note' => null,
                'url' => null,
                'is_default' => Str::lower($first['line']) === Str::lower($first['brand']),
                'is_active' => true,
                'sort_order' => lineSort($first['line']),
            ],
        );

        $line->fill([
            'is_default' => Str::lower($first['line']) === Str::lower($first['brand']),
            'is_active' => true,
            'sort_order' => min((int) $line->sort_order, lineSort($first['line'])),
        ])->save();

        $productType = BrandCatalogueProductType::query()->firstOrCreate(
            [
                'brand_catalogue_line_id' => $line->id,
                'name' => $first['product_type'],
            ],
            [
                'brand_catalogue_brand_id' => $brand->id,
                'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $first['product_type']),
                'note' => null,
                'url' => null,
                'is_active' => true,
                'sort_order' => productTypeSort($first['product_type']),
            ],
        );

        $productType->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'is_active' => true,
            'sort_order' => productTypeSort($first['product_type']),
        ])->save();

        $style = BrandCatalogueStyle::query()
            ->where('brand_catalogue_product_type_id', $productType->id)
            ->where('name', $first['style_name'])
            ->first();

        if (! $style) {
            $style = new BrandCatalogueStyle([
                'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $first['style_name']),
            ]);
        }

        $style->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $productType->id,
            'brand_catalogue_material_id' => null,
            'material_name' => null,
            'name' => $first['style_name'],
            'note' => safeFamilyNote($first['style_name'], $first['product_type']),
            'url' => null,
            'is_active' => true,
            'sort_order' => styleSort($first['style_name']),
        ])->save();

        [$created, $updated] = syncStyleSkus($style, $groupRecords, $sourceEvidence);
        $createdSkus += $created;
        $updatedSkus += $updated;

        $brandIds[] = $brand->id;
        $lineIds[] = $line->id;
        $productTypeIds[] = $productType->id;
        $styleIds[] = $style->id;
    }

    return [
        'catalogues' => count($catalogues),
        'brands' => count(array_unique($brandIds)),
        'lines' => count(array_unique($lineIds)),
        'product_types' => count(array_unique($productTypeIds)),
        'families' => count(array_unique($styleIds)),
        'skus_created' => $createdSkus,
        'skus_updated' => $updatedSkus,
        'total_skus' => $records->count(),
        'index_url' => url('/brand-catalogue'),
    ];
});

echo "Janson Beauty PDF catalogue imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @param array<int, string> $argv
 */
function argumentValue(string $prefix, array $argv): ?string
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function extractJansonPdf(string $pdfPath): array
{
    if (! is_file($pdfPath)) {
        throw new RuntimeException("PDF not found: {$pdfPath}");
    }

    $pythonCandidates = array_filter([
        getenv('PYTHON') ?: null,
        'C:\\Users\\Abela\\AppData\\Local\\Python\\bin\\python.exe',
        'C:\\Users\\Abela\\AppData\\Local\\Python\\pythoncore-3.14-64\\python.exe',
        'py',
        'python3',
        'python',
    ]);

    $extractor = __DIR__.DIRECTORY_SEPARATOR.'extract_janson_product_list.py';
    $lastError = '';

    foreach ($pythonCandidates as $python) {
        if (str_contains($python, '\\') && ! is_file($python)) {
            continue;
        }

        $command = escapeshellarg($python).' '.escapeshellarg($extractor).' --json '.escapeshellarg($pdfPath);
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, __DIR__);
        if (! is_resource($process)) {
            continue;
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode === 0) {
            return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        }

        $lastError = trim($stderr ?: $stdout);
    }

    throw new RuntimeException('Could not extract Janson PDF. '.$lastError);
}

/**
 * @return array<int, array{name:string,slug:string,note:string,sort_order:int}>
 */
function catalogueDefinitions(): array
{
    return [
        [
            'name' => 'Hair Products',
            'slug' => 'hair-products',
            'note' => 'Structured product workspace for hair care, styling, relaxers, oils and colour products.',
            'sort_order' => 20,
        ],
        [
            'name' => 'Skin Care',
            'slug' => 'skin-care',
            'note' => 'Structured product workspace for body care, skin care, soaps, lotions and treatments.',
            'sort_order' => 30,
        ],
        [
            'name' => 'Accessories',
            'slug' => 'accessories',
            'note' => 'Structured product workspace for beauty accessories, applicators, combs, brushes and support items.',
            'sort_order' => 40,
        ],
        [
            'name' => 'Electrical',
            'slug' => 'electrical',
            'note' => 'Structured product workspace for clippers, trimmers, dryers, straighteners, blades and electrical parts.',
            'sort_order' => 50,
        ],
        [
            'name' => 'Fragrances',
            'slug' => 'fragrances',
            'note' => 'Structured product workspace for colognes, aftershaves, fragrance waters and related toiletries.',
            'sort_order' => 60,
        ],
        [
            'name' => 'Makeup',
            'slug' => 'makeup',
            'note' => 'Structured product workspace for cosmetics and makeup products.',
            'sort_order' => 70,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function structureRecord(array $record): array
{
    $code = Str::upper(trim((string) ($record['code'] ?? '')));
    $description = cleanProductText((string) ($record['description'] ?? ''));
    $heading = cleanSourceHeading((string) ($record['family_heading'] ?? ''));

    [$brand, $line] = resolveBrandAndLine($heading, $code, $description);
    $productType = resolveProductType($description, $heading, $brand);
    $catalogueName = resolveCatalogueName($description, $heading, $brand, $productType);
    $styleName = buildStyleName($brand, $line, $productType);
    $size = extractSize($description);
    $strength = extractStrength($description);
    $pack = extractPack($description);
    $variantLabel = extractVariantLabel($description, $brand, $line, $productType, $size, $strength, $pack);
    $skuName = buildSkuName($description, $brand, $line);

    $axes = [];
    $productAxis = $productType === 'Hair Colour / Dye' ? 'Shade' : 'Product Variant';
    if ($variantLabel !== '' && $variantLabel !== 'Standard') {
        $axes[$productAxis] = $variantLabel;
    }
    if ($size !== '') {
        $axes['Size'] = $size;
    }
    if ($strength !== '') {
        $axes['Strength'] = $strength;
    }
    if ($pack !== '') {
        $axes['Pack'] = $pack;
    }

    return [
        'catalogue_name' => $catalogueName,
        'brand' => $brand,
        'line' => $line,
        'product_type' => $productType,
        'style_name' => $styleName,
        'code' => $code,
        'sku_name' => $skuName,
        'variant_label' => $variantLabel,
        'axes' => $axes,
        'page' => (int) ($record['page'] ?? 0),
        'side' => (string) ($record['side'] ?? ''),
        'source_heading' => $heading,
        'source_price' => trim((string) ($record['price'] ?? '')),
        'quantity_marker' => trim((string) ($record['quantity_marker'] ?? '')),
        'flags' => trim((string) ($record['flags'] ?? '')),
        'raw' => trim((string) ($record['raw'] ?? '')),
    ];
}

function cleanProductText(string $value): string
{
    $value = str_replace('Ł', '£', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = preg_replace('/\s+\*{2,}\s*NEW\s*\*{0,}/i', ' NEW', $value) ?? $value;
    $value = str_replace([' XXX', ' XX'], '', $value);

    return trim($value);
}

function cleanSourceHeading(string $value): string
{
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;
    $value = preg_replace('/\s*\*{2,}\s*NEW\s*\*{0,}/i', '', $value) ?? $value;
    $value = preg_replace('/\s*-\s*SPECIAL OFFER.*$/i', '', $value) ?? $value;
    $value = preg_replace('/\s+Price\s+QTY$/i', '', $value) ?? $value;

    return trim($value);
}

/**
 * @return array{0:string,1:string}
 */
function resolveBrandAndLine(string $heading, string $code, string $description): array
{
    $headingUpper = Str::upper($heading);
    $descriptionUpper = Str::upper($description);
    $genericHeadings = [
        '',
        'NEW IN STOCK',
        'OFFER OF THE MONTH',
        'CLEARANCE LIST',
        'HAIR CARE',
        'SKIN CARE',
        'HEALTH CARE',
        'SOAPS',
        'BODY LOTIONS',
        'MISCELLANEOUS',
        'ELECTRICALS',
        'HAIR COLOURS',
    ];

    $special = [
        'A/B ULTIMATE ORIGINALS' => ["Africa's Best", 'Ultimate Originals'],
        'A PRIDE MOISTURE MIRACLE' => ['African Pride', 'Moisture Miracle'],
        'AFIRCA BLACK SOAPS.' => ['African Black Soaps', 'African Black Soaps'],
        'AFRICA BLACK SOAPS.' => ['African Black Soaps', 'African Black Soaps'],
        'ALIZA ACCESSORIES OFFER' => ['Aliza', 'Accessories'],
        'AMPRO SHINE N JAM' => ['Ampro', 'Shine N Jam'],
        'AS I AM JAMAICAN BLACK CASTOR OIL' => ['As I Am', 'Jamaican Black Castor Oil'],
        'AS I AM LONG & LUXE' => ['As I Am', 'Long & Luxe'],
        'AS I AM DRY & ITCHY' => ['As I Am', 'Dry & Itchy'],
        'AS I AM BORN CURLY' => ['As I Am', 'Born Curly'],
        'AS I AM ROSEMARY' => ['As I Am', 'Rosemary'],
        'AS I AM RICE WATER' => ['As I Am', 'Rice Water'],
        'BABYLISS' => ['Babyliss', 'Babyliss'],
        'CANTU SHEA BUTTER NATURAL' => ['Cantu', 'Shea Butter Natural'],
        'CANTU SHEA BUTTER' => ['Cantu', 'Shea Butter'],
        'CANTU AVOCADO' => ['Cantu', 'Avocado'],
        'CANTU KIDS' => ['Cantu', 'Kids'],
        'CAROTONE / CAROLISS' => ['Carotone', 'Caroliss'],
        'CON BUTTER BLEND & FLAXSEED' => ['Creme of Nature', 'Butter Blend & Flaxseed'],
        'CREME OF NATURE ARGAN COLOR' => ['Creme of Nature', 'Argan Oil Color'],
        'CREME OF NATURE ARGAN CURLY' => ['Creme of Nature', 'Argan Curl'],
        'CREME OF NATURE ARGAN' => ['Creme of Nature', 'Argan Oil'],
        'CREME OF NATURE PURE HONEY' => ['Creme of Nature', 'Pure Honey'],
        'D & L - BEAUTIFUL BEGINNINGS' => ['Dark & Lovely', 'Beautiful Beginnings'],
        'DABUR VATIKA' => ['Dabur', 'Vatika'],
        'DABUR HENNA' => ['Dabur', 'Vatika Henna'],
        'DAGGET & RAMSDELL' => ['Daggett & Ramsdell', 'Daggett & Ramsdell'],
        'DIFEEL HAIR OIL' => ['Difeel', 'Hair Oil'],
        'EBIN 24HR EDGE TAMER' => ['Ebin', '24 Hour Edge Tamer'],
        'EBIN 24HR EDGE SLEEK' => ['Ebin', '24 Hour Edge Sleek'],
        'EBIN WONDER LACE SPRAY' => ['Ebin', 'Wonder Lace Spray'],
        'ECO KURVY - KOLLY' => ['Eco Style', 'Kurly Koily'],
        'ECO STYLING GEL' => ['Eco Style', 'Styling Gel'],
        'FAIR & WHITE GOLD' => ['Fair & White', 'Gold'],
        'FAIR N WHITE GLUTATHION' => ['Fair & White', 'Glutathion'],
        'FAIR N WHITE SO LEMON' => ['Fair & White', 'So Lemon'],
        'FAIR AND WHITE EXCLUSIVE' => ['Fair & White', 'Exclusive'],
        'FAIR AND WHITE MIX BRIGHTENING' => ['Fair & White', 'Mix Brightening'],
        'FAIR AND WHITE CARROT' => ['Fair & White', 'Carrot'],
        'FAIR & WHITE SO WHITE' => ['Fair & White', 'So White'],
        'JAMAICAN MANGO & LIME' => ['Jamaican Mango & Lime', 'Jamaican Mango & Lime'],
        'JUST FOR ME KIDS' => ['Just For Me', 'Kids'],
        'LUSTER\'S PINK KIDS' => ["Luster's Pink", 'Kids'],
        'LUSTER\'S S CURL' => ["Luster's S-Curl", "Luster's S-Curl"],
        'MAKARI EXCLUSIVE (BROWN)' => ['Makari', 'Exclusive'],
        'MAKARI EXTREME ARGAN & CARROT' => ['Makari', 'Extreme Argan & Carrot'],
        'MAKARI NATURALLE' => ['Makari', 'Naturalle'],
        'MAKARI (WHITE)' => ['Makari', 'White'],
        'MAMADO PURE OILS' => ['Mamado', 'Pure Oils'],
        'MIELLE POMEGRANTE & HONEY' => ['Mielle Organics', 'Pomegranate & Honey'],
        'MIELLE ROSEMARY MINT' => ['Mielle Organics', 'Rosemary Mint'],
        'PALMER\'S SKIN SUCCESS' => ["Palmer's", 'Skin Success'],
        'PALMERS COCOA BUTTER' => ["Palmer's", 'Cocoa Butter Formula'],
        'PALMERS COCONUT' => ["Palmer's", 'Coconut Oil Formula'],
        'PALMERS SHEA BUTTER' => ["Palmer's", 'Shea Butter Formula'],
        'PALMERS OLIVE OIL' => ["Palmer's", 'Olive Oil Formula'],
        'S M JAMAICAN BLACK CASTOR OIL' => ['Shea Moisture', 'Jamaican Black Castor Oil'],
        'S M MANUKA HONEY & MAFURA OIL' => ['Shea Moisture', 'Manuka Honey & Mafura Oil'],
        'S MOISTURE VIRGIN COCONUT OIL' => ['Shea Moisture', 'Virgin Coconut Oil'],
        'SHEA MOISTURE COCONUT & HIBISCUS' => ['Shea Moisture', 'Coconut & Hibiscus'],
        'SHEA MOISTURE RAW SHEA BUTTER' => ['Shea Moisture', 'Raw Shea Butter'],
        'SHEA MOISTURE KIDS' => ['Shea Moisture', 'Kids'],
        'SOFT N FREE BLACK CASTOR OIL' => ["Soft 'N Free", 'Black Castor Oil'],
        'SOFT N FREE NATURAL' => ["Soft 'N Free", 'Natural'],
        'SOFT N FREE' => ["Soft 'N Free", "Soft 'N Free"],
        'SOFT N\'FREE PRETTY' => ["Soft 'N Free", 'Pretty'],
        'SOFT \'N WHITE SWISS PAPAYA' => ["Soft 'N White", 'Swiss Papaya'],
        'SUNNY ISLES JBCO' => ['Sunny Isle', 'Jamaican Black Castor Oil'],
        'SUNNY ISLES KIDS' => ['Sunny Isle', 'Kids'],
        'TALIAH WAAJID APPLE & ALOE' => ['Taliah Waajid', 'Apple & Aloe'],
        'TALIAH WAAJID KINKY WAVY' => ['Taliah Waajid', 'Kinky Wavy'],
        'TALIAH WAAJID LOVE MY LOCS' => ['Taliah Waajid', 'Love My Locs'],
        'TALIAH WAAJID LOVE MY NATURAL HAIR' => ['Taliah Waajid', 'Love My Natural Hair'],
    ];

    foreach ($special as $needle => $value) {
        if (str_starts_with($headingUpper, $needle)) {
            return $value;
        }
    }

    if (! in_array($headingUpper, $genericHeadings, true)) {
        foreach (knownBrandPrefixes() as $prefix => $brand) {
            if (str_starts_with($headingUpper, $prefix)) {
                $line = trim(substr($heading, strlen($prefix)));
                $line = cleanLineName($line === '' ? $brand : $line);

                return [$brand, $line];
            }
        }

        if ($heading !== '') {
            $brand = canonicalName($heading);

            return [$brand, $brand];
        }
    }

    $prefix = codePrefix($code);
    $prefixMap = prefixBrandMap();
    if (isset($prefixMap[$prefix])) {
        return $prefixMap[$prefix];
    }

    foreach (knownDescriptionBrands() as $needle => $brand) {
        if (str_starts_with($descriptionUpper, $needle)) {
            return [$brand, $brand];
        }
    }

    return ['Unknown', 'Unknown'];
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
        'JOHNSONS' => "Johnson's",
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
 * @return array<string, array{0:string,1:string}>
 */
function prefixBrandMap(): array
{
    return [
        'A3' => ['A3', 'A3'],
        'ABK' => ["Africa's Best", 'Kids'],
        'ABO' => ['African Essence', 'African Essence'],
        'AFB' => ["Africa's Best", "Africa's Best"],
        'AFP' => ['African Pride', 'Olive Miracle'],
        'APK' => ['African Pride', 'Dream Kids'],
        'APG' => ['Aphogee', 'Aphogee'],
        'APM' => ['African Pride', 'Moisture Miracle'],
        'ASI' => ['As I Am', 'As I Am'],
        'ALD' => ['All Day Locks', 'All Day Locks'],
        'ALZ' => ['Aliza', 'Aliza'],
        'AMD' => ['American Dream', 'American Dream'],
        'AST' => ['Astral', 'Astral'],
        'ATO' => ['Atone', 'Atone'],
        'AUC' => ["Aunt Jackie's", "Aunt Jackie's"],
        'AUJ' => ["Aunt Jackie's", "Aunt Jackie's"],
        'BAB' => ['Babyliss', 'Babyliss'],
        'BAT' => ['Batana', 'Batana Oils'],
        'BBS' => ['Bronner Brothers', 'Pump It Up'],
        'BEN' => ['Benjamins', 'Benjamins'],
        'BIS' => ['Bigen', 'Bigen Speedy'],
        'BM' => ['Blue Magic', 'Blue Magic'],
        'BON' => ['Bonfi Natural', 'Bonfi Natural'],
        'BUP' => ['Bump Patrol', 'Bump Patrol'],
        'BUS' => ['Bump Stopper', 'Bump Stopper'],
        'BTT' => ['Beautiful Textures', 'Beautiful Textures'],
        'CAL' => ['Caro Light', 'Caro Light'],
        'CAM' => ['Camille Rose', 'Camille Rose'],
        'CAN' => ['Cantu', 'Cantu'],
        'CAR' => ['Carotein', 'Carotein'],
        'CAV' => ['Cantu', 'Avocado'],
        'CHE' => ['Chear', 'Chear'],
        'CID' => ['Cidal', 'Cidal'],
        'CIN' => ['Cinthol', 'Cinthol'],
        'CLA' => ['Clairissime', 'Clairissime'],
        'CLE' => ['Clear Essence', 'Clear Essence'],
        'CLM' => ['Clairmen', 'Clairmen'],
        'CNA' => ['Creme of Nature', 'Argan Oil'],
        'CND' => ['Creme of Nature', 'Argan Oil Color'],
        'CON' => ['Creme of Nature', 'Creme of Nature'],
        'COC' => ['Creme of Nature', 'Creme of Nature'],
        'COR' => ['Health Care', 'Health Care'],
        'CPG' => ['Clere', 'Glycerine'],
        'CPH' => ['Creme of Nature', 'Pure Honey'],
        'CUR' => ['Crusader', 'Crusader'],
        'CUK' => ['Curly Kids', 'Curly Kids'],
        'DAB' => ['Dabur', 'Dabur'],
        'DAD' => ['Dabur', 'Vatika Henna'],
        'DAX' => ['Dax', 'Dax'],
        'DES' => ['Design Essentials', 'Design Essentials'],
        'DIF' => ['Difeel', 'Hair Oil'],
        'DL' => ['Dark & Lovely', 'Dark & Lovely'],
        'DNL' => ['Dark & Lovely', 'Dark & Lovely'],
        'DNR' => ['Daggett & Ramsdell', 'Daggett & Ramsdell'],
        'DOG' => ['Doo Gro', 'Doo Gro'],
        'DOP' => ['Dop', 'Dop'],
        'DOU' => ['The Doux', 'The Doux'],
        'DOV' => ['Dove', 'Dove'],
        'DRM' => ["Dr. Miracle's", "Dr. Miracle's"],
        'DTO' => ['Dettol', 'Dettol'],
        'DUO' => ['Dudu Osun', 'Dudu Osun'],
        'E45' => ['E45', 'E45'],
        'EBI' => ['Ebin', 'Ebin'],
        'ECO' => ['Eco Style', 'Eco Style'],
        'EDE' => ['Eden', 'Eden'],
        'ESE' => ['Eversheen', 'Eversheen'],
        'EUT' => ['Euthymol', 'Euthymol'],
        'FAA' => ['Fantasia IC', 'Fantasia IC'],
        'FAL' => ['Fair Lady', 'Fair Lady'],
        'FAN' => ['Fantasia IC', 'Fantasia IC'],
        'FNC' => ['Fair & White', 'Carrot'],
        'FNE' => ['Fair & White', 'Exclusive'],
        'FNG' => ['Fair & White', 'Gold'],
        'FNL' => ['Fair & White', 'So Lemon'],
        'FNM' => ['Fair & White', 'Mix Brightening'],
        'FNS' => ['Fair & White', 'So White'],
        'FNW' => ['Fair & White', 'Fair & White'],
        'FWG' => ['Fair & White', 'Glutathion'],
        'FER' => ['Ferrol', 'Ferrol'],
        'GAB' => ['Gabri', 'Gabri'],
        'GLY' => ['Glysolid', 'Glysolid'],
        'GOR' => ['Moco de Gorila', 'Moco de Gorila'],
        'GRO' => ['Groganics', 'Groganics'],
        'GUM' => ['Gummy', 'Gummy'],
        'HAZ' => ['Haz', 'Haz'],
        'HEN' => ['Henna', 'Henna'],
        'HOL' => ['Hollywood', 'Hollywood'],
        'HOW' => ['Hollywood', 'Hollywood'],
        'IGE' => ['Igel', 'Igel'],
        'IML' => ['Imperial Leather', 'Imperial Leather'],
        'IRS' => ['Irish Spring', 'Irish Spring'],
        'JER' => ['Jergens', 'Jergens'],
        'JFM' => ['Just For Me', 'Kids'],
        'JML' => ['Jamaican Mango & Lime', 'Jamaican Mango & Lime'],
        'JOH' => ["Johnson's", "Johnson's"],
        'KOJ' => ['Kojie-San', 'Kojie-San'],
        'KTC' => ['KTC', 'KTC'],
        'LIM' => ['Taliah Waajid', 'Kinky Wavy'],
        'LOT' => ['Lotta Body', 'Lotta Body'],
        'LUP' => ["Luster's Pink", "Luster's Pink"],
        'LUS' => ["Luster's S-Curl", "Luster's S-Curl"],
        'MAG' => ['Magic Collection', 'Magic Collection'],
        'MAM' => ['Mamado', 'Pure Oils'],
        'MAW' => ['Maxi White', 'Maxi White'],
        'MIE' => ['Mielle Organics', 'Mielle Organics'],
        'MIZ' => ['Mizani', 'Mizani'],
        'MKN' => ['Makari', 'Naturalle'],
        'MOG' => ['Moco de Gorila', 'Moco de Gorila'],
        'MOT' => ['Motions', 'Motions'],
        'MUR' => ["Murray's", "Murray's"],
        'MZA' => ['Mazuri Organics', 'Mazuri Organics'],
        'NAI' => ['Nairobi', 'Nairobi'],
        'NMB' => ['NMB', 'NMB'],
        'NIV' => ['Nivea', 'Nivea'],
        'NUB' => ['Nubian Queen', 'Nubian Queen'],
        'ORS' => ['ORS', 'ORS'],
        'ORK' => ['ORS', 'Olive Oil Girls'],
        'PAL' => ["Palmer's", "Palmer's"],
        'PAM' => ['Palmolive', 'Palmolive'],
        'PEA' => ['Pears', 'Pears'],
        'POO' => ["Palmer's", 'Olive Oil Formula'],
        'PSS' => ["Palmer's", 'Skin Success'],
        'QUH' => ['Queen Helene', 'Queen Helene'],
        'QUE' => ['Queen Elizabeth', 'Queen Elizabeth'],
        'RAW' => ['Raw Extra Virgin Oils', 'Raw Extra Virgin Oils'],
        'RAZ' => ['Razac', 'Razac'],
        'RBE' => ['Rubee', 'Rubee'],
        'REO' => ['Red One', 'Red One'],
        'RIC' => ['Rico', 'Rico'],
        'RIN' => ['Rinju', 'Rinju'],
        'RUS' => ['Rusk', 'Rusk'],
        'SHE' => ['Shea Moisture', 'Shea Moisture'],
        'SIL' => ['Silicon Mix', 'Silicon Mix'],
        'SIM' => ['Simple', 'Simple'],
        'SKA' => ['Skala', 'Skala'],
        'SKI' => ['Skin Light', 'Skin Light'],
        'SNF' => ["Soft 'N Free", "Soft 'N Free"],
        'SNW' => ["Soft 'N White", 'Swiss Papaya'],
        'SSF' => ['Sta-Sof-Fro', 'Sta-Sof-Fro'],
        'SUL' => ['Sulfur 8', 'Sulfur 8'],
        'SUN' => ['Sunny Isle', 'Jamaican Black Castor Oil'],
        'TAW' => ['Taliah Waajid', 'Taliah Waajid'],
        'TCB' => ['TCB', 'TCB'],
        'UBG' => ['Universal Beauty', 'Styling Gel'],
        'ULG' => ['Ultra Glow', 'Ultra Glow'],
        'VAS' => ['Vaseline', 'Vaseline'],
        'VIG' => ['Virgin Hair Fertilizer', 'Virgin Hair Fertilizer'],
        'WBL' => ['Wahl', 'Clipper Blades'],
        'WCA' => ['Wahl', 'Clipper Attachments'],
        'WCL' => ['Wahl', 'Clippers'],
        'WCT' => ['Wahl', 'Curling Tongs'],
        'WHD' => ['Wahl', 'Hair Dryers'],
        'WHS' => ['Wahl', 'Hair Straighteners'],
        'WTR' => ['Wahl', 'Trimmers'],
        'XPR' => ['X-Pression', 'Hair Extensions'],
        'XTR' => ['Xtreme', 'Styling Gel'],
    ];
}

/**
 * @return array<string, string>
 */
function knownDescriptionBrands(): array
{
    return [
        'AMERICAN DREAM' => 'American Dream',
        'GABRI' => 'Gabri',
        'PALMER' => "Palmer's",
        'VASELINE' => 'Vaseline',
        'DABUR' => 'Dabur',
        'FAIR' => 'Fair & White',
        'CANTU' => 'Cantu',
        'CREME OF NATURE' => 'Creme of Nature',
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

function resolveCatalogueName(string $description, string $heading, string $brand, string $productType): string
{
    $text = Str::lower($description.' '.$heading.' '.$brand.' '.$productType);

    if (containsAny($text, ['dryer', 'straightener', 'trimmer', 'clipper', 'shaver', 'curling tong', 'blade', 'replacement part', 'power pik'])) {
        return 'Electrical';
    }

    if (containsAny($text, ['comb', 'brush', 'wig head', 'applicator', 'spray bottle', 'tweezers', 'razor', 'rubber bands', 'bobby pins'])) {
        return 'Accessories';
    }

    if (containsAny($text, ['lipstick', 'lip gloss', 'powder', 'nail polish', 'cosmetic', 'makeup'])) {
        return 'Makeup';
    }

    if (containsAny($text, ['cologne', 'aftershave', 'bayrum', 'florida water', 'eau de', 'fragrance'])) {
        return 'Fragrances';
    }

    if (isSkinCareProduct($description, $heading, $brand, $productType)) {
        return 'Skin Care';
    }

    return 'Hair Products';
}

function resolveProductType(string $description, string $heading, string $brand): string
{
    $text = Str::lower($description.' '.$heading.' '.$brand);
    $hairBrand = isKnownHairCareBrand($brand, $heading);

    if (containsAny($text, ['hair dryer', 'dryer'])) return 'Hair Dryer';
    if (containsAny($text, ['straightener'])) return 'Hair Straightener';
    if (containsAny($text, ['curling tong'])) return 'Curling Tong';
    if (containsAny($text, ['trimmer'])) return 'Trimmer';
    if (containsAny($text, ['clipper blade', 'replacement blade', 'blade set'])) return 'Clipper Blade / Part';
    if (containsAny($text, ['clipper', 'shaver'])) return 'Clipper / Shaver';
    if (containsAny($text, ['comb', 'brush'])) return 'Comb / Brush';
    if (containsAny($text, ['wig head'])) return 'Wig Head';
    if (containsAny($text, ['applicator bottle', 'spray bottle'])) return 'Bottle / Applicator';
    if (containsAny($text, ['rubber band', 'bobby pin', 'hair band', 'hair bead'])) return 'Hair Accessory';
    if (containsAny($text, ['tweezer', 'razor'])) return 'Beauty Tool';
    if (containsAny($text, ['lipstick', 'lip gloss'])) return 'Lip Product';
    if (containsAny($text, ['powder', 'cosmetic', 'makeup'])) return 'Cosmetic';
    if (containsAny($text, ['cologne', 'aftershave', 'bayrum', 'florida water', 'eau de'])) return 'Cologne / Aftershave';
    if (containsAny($text, ['hair colour spray'])) return 'Hair Colour Spray';
    if (containsAny($text, ['hair color', 'hair colour', 'dye', 'henna', 'adore colours', 'semi permanent', 'bigen'])) return 'Hair Colour / Dye';
    if (containsAny($text, ['peroxide', 'bleach powder'])) return 'Developer / Bleach';
    if (containsAny($text, ['relaxer'])) return 'Relaxer';
    if (containsAny($text, ['texturizer', 'texture softening'])) return 'Texturizer';
    if (containsAny($text, ['shampoo'])) return 'Shampoo';
    if (containsAny($text, ['conditioner', 'cond '])) return containsAny($text, ['leave in', 'leave-in']) ? 'Leave-In Conditioner' : 'Conditioner';
    if (containsAny($text, ['detangler'])) return 'Detangler';
    if (containsAny($text, ['hair mask', 'masque', 'treatment', 'reconstructor'])) return 'Hair Treatment / Masque';
    if ($hairBrand && containsAny($text, ['serum'])) return 'Hair Serum';
    if (containsAny($text, ['edge control', 'edge gel', 'edge wax', 'edge tamer'])) return 'Edge Control';
    if (containsAny($text, ['wax'])) return 'Hair Wax';
    if (containsAny($text, ['mousse', 'foam wrap', 'foaming mousse', 'styling foam'])) return 'Mousse / Foam';
    if (containsAny($text, ['spray', 'spritz', 'oil sheen'])) return 'Hair Spray';
    if (containsAny($text, ['styling gel', 'hair gel', 'gel ']) && ! isSkinCareProduct($description, $heading, $brand, '')) return 'Styling Gel';
    if (containsAny($text, ['pomade', 'hairdress', 'hair food'])) return 'Pomade / Hairdress';
    if (containsAny($text, ['curl cream', 'curling cream', 'custard', 'pudding', 'souffle', 'curl activator'])) return 'Curl Cream / Custard';
    if (containsAny($text, ['hair oil', 'scalp oil', 'black castor oil', 'jbco', 'oil moisturizer'])) return 'Hair Oil';
    if (containsAny($text, ['beard'])) return 'Beard Care';
    if (containsAny($text, ['soap'])) return 'Soap';
    if (containsAny($text, ['shower gel'])) return 'Shower Gel';
    if (containsAny($text, ['body lotion', 'skin lotion', 'hand body lotion', 'body milk', 'clearing milk', 'fade milk'])) return 'Body Lotion';
    if (containsAny($text, ['body cream', 'beauty cream', 'face cream', 'facial cream', 'fade cream', 'lightening cream', 'whitening cream', 'complexion cream'])) return 'Skin Cream';
    if (containsAny($text, ['serum', 'elixir'])) return 'Skin Serum';
    if (containsAny($text, ['glycerine', 'glycerin'])) return 'Glycerine';
    if (containsAny($text, ['petroleum jelly', 'soft skin jelly'])) return 'Petroleum Jelly';
    if (containsAny($text, ['scrub', 'exfoliator'])) return 'Scrub / Exfoliator';
    if (containsAny($text, ['cleanser', 'tonic', 'toner', 'astringent'])) return 'Cleanser / Toner';
    if (containsAny($text, ['sanitizer', 'sanitiser', 'rubbing alcohol', 'antiseptic'])) return 'Health / Hygiene';
    if (containsAny($text, ['shave', 'shaving', 'bump'])) return 'Shaving / Bump Care';
    if (containsAny($text, ['body oil', 'pure oil', 'vitamin e oil', 'rose water'])) return 'Body Oil';
    if (containsAny($text, ['olive oil', 'coconut oil', 'argan oil']) && ! $hairBrand && hasExplicitSkinTerms($text)) return 'Body Oil';
    if (containsAny($text, ['cream'])) return ($hairBrand || isHairProduct($text)) && ! hasExplicitSkinTerms($text) ? 'Hair Cream' : 'Skin Cream';
    if (containsAny($text, ['gel'])) return ($hairBrand || isHairProduct($text)) && ! hasExplicitSkinTerms($text) ? 'Styling Gel' : 'Skin Gel';
    if (containsAny($text, ['oil'])) return ($hairBrand || isHairProduct($text)) && ! hasExplicitSkinTerms($text) ? 'Hair Oil' : 'Body Oil';

    return isSkinCareProduct($description, $heading, $brand, '') ? 'Skin Care Product' : 'Hair Care Product';
}

function isHairProduct(string $text): bool
{
    return containsAny($text, ['hair', 'curl', 'braid', 'edge', 'scalp', 'lock', 'twist', 'shampoo', 'conditioner', 'relaxer', 'pomade']);
}

function isSkinCareProduct(string $description, string $heading, string $brand, string $productType): bool
{
    $text = Str::lower($description.' '.$heading.' '.$brand.' '.$productType);
    if (isKnownHairCareBrand($brand, $heading)) {
        return hasHardSkinTerms($text);
    }

    return hasExplicitSkinTerms($text) || containsAny($text, [
        'skin',
        'body',
        'face',
        'facial',
        'soap',
        'cocoa butter',
        'glycerine',
        'glycerin',
        'lotion',
        'shower gel',
        'scrub',
        'petroleum',
        'vaseline',
        'lightening',
        'whitening',
        'brightening',
        'dark spot',
        'bump',
        'shaving',
        'sanitizer',
        'sanitiser',
        'antiseptic',
        'rose water',
    ]);
}

function hasHardSkinTerms(string $text): bool
{
    return containsAny($text, [
        'skin',
        'face',
        'facial',
        'soap',
        'glycerine',
        'glycerin',
        'shower gel',
        'scrub',
        'petroleum',
        'vaseline',
        'lightening',
        'whitening',
        'brightening',
        'dark spot',
        'bump',
        'shaving',
        'sanitizer',
        'sanitiser',
        'antiseptic',
        'rose water',
        'aftershave',
        'cologne',
        'hand cream',
        'body lotion',
        'body cream',
        'body oil',
        'body milk',
        'tonic',
        'toner',
        'cleanser',
    ]);
}

function hasExplicitSkinTerms(string $text): bool
{
    return containsAny($text, [
        'skin',
        'body',
        'face',
        'facial',
        'soap',
        'glycerine',
        'glycerin',
        'shower gel',
        'scrub',
        'petroleum',
        'vaseline',
        'lightening',
        'whitening',
        'brightening',
        'dark spot',
        'bump',
        'shaving',
        'sanitizer',
        'sanitiser',
        'antiseptic',
        'rose water',
        'aftershave',
        'cologne',
        'hand cream',
    ]);
}

function isKnownHairCareBrand(string $brand, string $heading): bool
{
    $text = Str::lower($brand.' '.$heading);

    return containsAny($text, [
        'africa\'s best',
        'african pride',
        'all day locks',
        'aphogee',
        'as i am',
        'aunt jackie',
        'blue magic',
        'cantu',
        'camille rose',
        'creme of nature',
        'dark & lovely',
        'dax',
        'difeel',
        'doo gro',
        'dr. miracle',
        'ebin',
        'eco style',
        'fantasia',
        'groganics',
        'jamaican mango',
        'just for me',
        'keracare',
        'luster',
        'mielle',
        'motions',
        'ors',
        'red one',
        'shea moisture',
        'soft \'n free',
        'sta-sof-fro',
        'sulfur',
        'sunny isle',
        'taliah waajid',
        'x-pression',
    ]);
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

function buildStyleName(string $brand, string $line, string $productType): string
{
    $parts = [$brand];

    if (! sameText($line, $brand) && ! str_contains(Str::lower($line), Str::lower($productType))) {
        $parts[] = $line;
    } elseif (! sameText($line, $brand) && $productType === 'Hair Care Product') {
        $parts[] = $line;
    }

    if (! str_contains(Str::lower(implode(' ', $parts)), Str::lower($productType))) {
        $parts[] = $productType;
    }

    return cleanCommercialName(implode(' ', array_filter($parts)));
}

function buildSkuName(string $description, string $brand, string $line): string
{
    $lower = Str::lower($description);
    $prefixParts = [$brand];

    if (! sameText($line, $brand) && ! str_contains($lower, Str::lower($line))) {
        $prefixParts[] = $line;
    }

    $prefix = cleanCommercialName(implode(' ', array_filter($prefixParts)));

    if (str_starts_with($lower, Str::lower($brand))) {
        return cleanCommercialName($description);
    }

    return cleanCommercialName($prefix.' '.$description);
}

function extractSize(string $description): string
{
    preg_match_all('/\b\d+(?:\.\d+)?\s?(?:ml|mL|ML|l|L|litres?|oz|OZ|g|gm|gr|kg|KG|lb|LB|pcs|Pcs|pc|sachets?|applications?|app)\b/u', $description, $matches);
    $values = collect($matches[0] ?? [])
        ->map(fn (string $value): string => normaliseMeasure($value))
        ->unique()
        ->values();

    return $values->implode(' + ');
}

function extractPack(string $description): string
{
    $values = [];
    if (preg_match('/\b(?:twin|single|double)\b/i', $description, $match)) {
        $values[] = Str::title($match[0]);
    }
    if (preg_match('/\b\d+\s?(?:pack|packs|pcs|pc|sachets|sachet|per jar|per pack)\b/i', $description, $match)) {
        $values[] = normaliseMeasure($match[0]);
    }
    if (preg_match('/\b\d+\s?x\s?\d+\b/i', $description, $match)) {
        $values[] = strtoupper(str_replace(' ', '', $match[0]));
    }

    return collect($values)->unique()->implode(' + ');
}

function extractStrength(string $description): string
{
    if (preg_match('/\b(?:regular|normal|mild|super|extra|sensitive|coarse)\b/i', $description, $match)) {
        return Str::title($match[0]);
    }

    if (preg_match('/\b(?:10|20|30|40)\s?(?:vol|volume)\b/i', $description, $match)) {
        return strtoupper(str_replace(' ', ' ', $match[0]));
    }

    return '';
}

function extractVariantLabel(string $description, string $brand, string $line, string $productType, string $size, string $strength, string $pack): string
{
    $value = ' '.$description.' ';
    foreach (array_filter([$brand, $line, $productType, $size, $strength, $pack]) as $remove) {
        $value = preg_replace('/\b'.preg_quote($remove, '/').'\b/i', ' ', $value) ?? $value;
    }

    $removeWords = [
        'body', 'skin', 'hair', 'face', 'facial', 'jar', 'tube', 'pump', 'bottle', 'cream', 'lotion',
        'soap', 'oil', 'shampoo', 'conditioner', 'cond', 'leave in', 'leave-in', 'gel', 'wax', 'spray',
        'mousse', 'foam', 'mask', 'masque', 'treatment', 'relaxer', 'texturizer', 'colour', 'color',
        'dye', 'henna', 'powder', 'serum', 'scrub', 'cleanser', 'tonic', 'toner', 'with', 'for',
    ];

    foreach ($removeWords as $word) {
        $value = preg_replace('/\b'.preg_quote($word, '/').'\b/i', ' ', $value) ?? $value;
    }

    $value = preg_replace('/\b\d+(?:\.\d+)?\s?(?:ml|mL|ML|l|L|litres?|oz|OZ|g|gm|gr|kg|KG|lb|LB|pcs|Pcs|pc|sachets?|applications?|app)\b/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = trim($value, " \t\n\r\0\x0B-+/(),.");

    if ($value === '') {
        return 'Standard';
    }

    return Str::limit(cleanCommercialName($value), 80, '');
}

function normaliseMeasure(string $value): string
{
    $value = preg_replace('/\s+/', '', trim($value)) ?? $value;
    $value = str_ireplace(['LITRES', 'LITRE'], 'L', $value);
    $value = str_ireplace(['PCS', 'PC'], 'pcs', $value);

    return str_ireplace(['ML', 'Mls', 'MLS'], 'ml', $value);
}

/**
 * @param Collection<int, array<string, mixed>> $records
 * @return Collection<int, array<string, mixed>>
 */
function markDuplicateVariantSignatures(Collection $records): Collection
{
    return $records
        ->groupBy(fn (array $record): string => implode('|', [
            $record['catalogue_name'],
            $record['brand'],
            $record['line'],
            $record['product_type'],
            $record['style_name'],
        ]))
        ->flatMap(function (Collection $group): array {
            $seen = [];

            return $group
                ->map(function (array $record) use (&$seen, $group): array {
                    if ($record['axes'] === [] && $group->count() > 1) {
                        $record['axes']['Product Variant'] = $record['variant_label'] !== 'Standard'
                            ? $record['variant_label']
                            : 'Review pending - '.$record['code'];
                    }

                    $signature = optionSignatureFromAxes($record['axes']);
                    if (isset($seen[$signature])) {
                        $record['axes']['Product Variant'] = ($record['axes']['Product Variant'] ?? 'Review pending').' - '.$record['code'];
                        $signature = optionSignatureFromAxes($record['axes']);
                    }

                    $seen[$signature] = true;
                    $record['option_signature'] = $signature;

                    return $record;
                })
                ->all();
        })
        ->values();
}

/**
 * @param Collection<int, string> $codes
 * @return array<string, array<string, mixed>>
 */
function sourceEvidenceForCodes(Collection $codes): array
{
    $evidence = [];

    DB::table('pdf_catalogue_products')
        ->whereIn('product_code', $codes)
        ->select('product_code', 'source_name', 'page_number', 'brand', 'product_name')
        ->orderBy('source_name')
        ->orderBy('page_number')
        ->get()
        ->each(function (object $row) use (&$evidence): void {
            $code = (string) $row->product_code;
            $evidence[$code]['pdf_matches'][] = "{$row->source_name} p{$row->page_number}: {$row->brand} - {$row->product_name}";
        });

    DB::table('mamado_products')
        ->whereIn('item_code', $codes)
        ->select('item_code', 'item_description', 'source_order_number', 'source_order_date')
        ->orderByDesc('source_order_date')
        ->get()
        ->each(function (object $row) use (&$evidence): void {
            $code = (string) $row->item_code;
            $evidence[$code]['mamado_matches'][] = "order {$row->source_order_number} {$row->source_order_date}: {$row->item_description}";
        });

    return $evidence;
}

/**
 * @param Collection<int, array<string, mixed>> $records
 * @param array<string, array<string, mixed>> $sourceEvidence
 * @return array{0:int,1:int}
 */
function syncStyleSkus(BrandCatalogueStyle $style, Collection $records, array $sourceEvidence): array
{
    $variantModels = syncVariants($style, $records);
    $created = 0;
    $updated = 0;

    foreach ($records->values() as $index => $record) {
        $sku = BrandCatalogueSku::query()
            ->where('brand_catalogue_style_id', $style->id)
            ->where('sku_code', $record['code'])
            ->first();

        if (! $sku) {
            $sku = new BrandCatalogueSku([
                'brand_catalogue_style_id' => $style->id,
                'slug' => scopedSlug(BrandCatalogueSku::query()->where('brand_catalogue_style_id', $style->id), $record['sku_name']),
            ]);
            $created++;
        } else {
            $updated++;
        }

        $sku->fill([
            'name' => $record['sku_name'],
            'sku_code' => $record['code'],
            'barcode' => null,
            'option_signature' => $record['option_signature'] ?? optionSignatureFromAxes($record['axes']),
            'description' => null,
            'note' => skuSourceNote($record, $sourceEvidence[$record['code']] ?? []),
            'url' => null,
            'is_active' => true,
            'sort_order' => ($record['page'] * 1000) + $index,
        ])->save();

        syncSkuOptions($sku, $record['axes'], $variantModels);
    }

    return [$created, $updated];
}

/**
 * @param Collection<int, array<string, mixed>> $records
 * @return array<string, array{variant:BrandCatalogueVariant,options:array<string,BrandCatalogueVariantOption>}>
 */
function syncVariants(BrandCatalogueStyle $style, Collection $records): array
{
    $axes = [];
    foreach ($records as $record) {
        foreach ($record['axes'] as $axis => $value) {
            if ($value !== '') {
                $axes[$axis][] = $value;
            }
        }
    }

    $models = [];
    $sort = 10;
    foreach ($axes as $axis => $values) {
        $variant = BrandCatalogueVariant::query()->updateOrCreate(
            [
                'brand_catalogue_style_id' => $style->id,
                'name' => $axis,
            ],
            [
                'variant_type' => variantTypeForAxis($axis),
                'sort_order' => $sort,
            ],
        );

        $options = [];
        foreach (collect($values)->unique()->values() as $index => $value) {
            $option = BrandCatalogueVariantOption::query()->updateOrCreate(
                [
                    'variant_id' => $variant->id,
                    'label' => $value,
                ],
                [
                    'value' => $value,
                    'sort_order' => ($index + 1) * 10,
                ],
            );
            $options[$value] = $option;
        }

        $models[$axis] = [
            'variant' => $variant,
            'options' => $options,
        ];

        $sort += 10;
    }

    return $models;
}

function variantTypeForAxis(string $axis): string
{
    return match ($axis) {
        'Size' => 'measurement',
        'Shade' => 'colour_name',
        'Pack' => 'count',
        default => 'text',
    };
}

/**
 * @param array<string, string> $axes
 * @param array<string, array{variant:BrandCatalogueVariant,options:array<string,BrandCatalogueVariantOption>}> $variantModels
 */
function syncSkuOptions(BrandCatalogueSku $sku, array $axes, array $variantModels): void
{
    DB::table('brand_catalogue_sku_variant_options')
        ->where('brand_catalogue_sku_id', $sku->id)
        ->delete();

    foreach ($axes as $axis => $value) {
        $variant = $variantModels[$axis]['variant'] ?? null;
        $option = $variantModels[$axis]['options'][$value] ?? null;
        if (! $variant || ! $option) {
            continue;
        }

        DB::table('brand_catalogue_sku_variant_options')->insert([
            'brand_catalogue_sku_id' => $sku->id,
            'brand_catalogue_variant_id' => $variant->id,
            'brand_catalogue_variant_option_id' => $option->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/**
 * @param array<string, string> $axes
 */
function optionSignatureFromAxes(array $axes): string
{
    ksort($axes);

    return collect($axes)
        ->map(fn (string $value, string $axis): string => Str::slug($axis).':'.Str::slug($value))
        ->implode('|');
}

/**
 * @param array<string, mixed> $record
 * @param array<string, mixed> $evidence
 */
function skuSourceNote(array $record, array $evidence): string
{
    $parts = [
        "Source row: Janson Beauty December 2025 PDF page {$record['page']} {$record['side']}; code {$record['code']}; heading {$record['source_heading']}.",
    ];

    if ($record['quantity_marker'] !== '') {
        $parts[] = 'PDF marker: '.$record['quantity_marker'].'.';
    }
    if ($record['source_price'] !== '') {
        $parts[] = 'PDF offer price marker: '.$record['source_price'].'.';
    }
    if ($record['flags'] !== '') {
        $parts[] = 'PDF flag: '.$record['flags'].'.';
    }
    if (! empty($evidence['pdf_matches'])) {
        $parts[] = 'Existing PDF staging code match: '.implode(' | ', array_slice($evidence['pdf_matches'], 0, 3)).'.';
    }
    if (! empty($evidence['mamado_matches'])) {
        $parts[] = 'Mamado order code match: '.implode(' | ', array_slice($evidence['mamado_matches'], 0, 3)).'.';
    }

    $parts[] = 'Review packaging, image, barcode, live stock and retail price before activating ecommerce.';

    return implode(' ', $parts);
}

function safeFamilyNote(string $styleName, string $productType): string
{
    return "{$styleName} family structured for {$productType} products. Add customer-facing description and verified images before ecommerce activation.";
}

function canonicalName(string $value): string
{
    $value = cleanLineName($value);

    $known = [
        'A3' => 'A3',
        'E45' => 'E45',
        'ORS' => 'ORS',
        'KTC' => 'KTC',
        'TCB' => 'TCB',
        'DAX' => 'Dax',
        'NYXON' => 'Nyxon',
        'X-PRESSIONS' => 'X-Pression',
    ];

    $upper = Str::upper($value);

    return $known[$upper] ?? Str::headline(Str::lower($value));
}

function cleanLineName(string $value): string
{
    $value = preg_replace('/\s*\*+\s*NEW\s*\*+/i', '', $value) ?? $value;
    $value = preg_replace('/\s*\*{2,}.*$/', '', $value) ?? $value;
    $value = str_replace(['&Amp;'], ['&'], $value);
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;

    return cleanCommercialName($value);
}

function cleanCommercialName(string $value): string
{
    $value = str_replace(['  ', ' - - '], [' ', ' - '], trim($value));
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = preg_replace('/\s+([,.)])/', '$1', $value) ?? $value;

    return trim($value);
}

function sameText(string $a, string $b): bool
{
    return Str::lower(trim($a)) === Str::lower(trim($b));
}

function scopedSlug($query, string $name, ?int $exceptId = null): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $suffix = 2;

    while ((clone $query)
        ->where('slug', $slug)
        ->when($exceptId, fn ($builder) => $builder->where('id', '!=', $exceptId))
        ->exists()) {
        $slug = $base.'-'.$suffix;
        $suffix++;
    }

    return $slug;
}

function brandSort(string $brand): int
{
    return max(1, ord(Str::upper($brand)[0] ?? 'Z') - 64) * 10;
}

function lineSort(string $line): int
{
    return sameText($line, 'Unknown') ? 9990 : brandSort($line);
}

function productTypeSort(string $productType): int
{
    $order = [
        'Body Lotion' => 10,
        'Skin Cream' => 20,
        'Soap' => 30,
        'Shower Gel' => 40,
        'Body Oil' => 50,
        'Skin Serum' => 60,
        'Shampoo' => 100,
        'Conditioner' => 110,
        'Leave-In Conditioner' => 120,
        'Hair Oil' => 130,
        'Styling Gel' => 140,
        'Edge Control' => 150,
        'Hair Colour / Dye' => 200,
        'Relaxer' => 210,
        'Texturizer' => 220,
        'Comb / Brush' => 300,
        'Clipper / Shaver' => 400,
    ];

    return $order[$productType] ?? 500;
}

function styleSort(string $styleName): int
{
    return brandSort($styleName) * 10;
}

function printLine(string $line): null
{
    echo $line."\n";

    return null;
}
