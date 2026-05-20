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

$lineGroups = sleekSelectedLineGroups();
$families = collect(buildOfficialFamilies($lineGroups));
$skuCount = $families->sum(fn (array $family): int => count($family['skus']));

if ($dryRun) {
    echo "Sleek selected official lines dry run.\n";
    echo "Master brand: Sleek\n";
    echo 'Lines: '.count($lineGroups)."\n";
    echo 'Source families: '.$families->count()."\n";
    echo "SKU variants: {$skuCount}\n\n";

    $families
        ->groupBy('line_name')
        ->each(function (Collection $lineFamilies, string $lineName): void {
            echo "{$lineName}: {$lineFamilies->count()} families / ".$lineFamilies->sum(fn (array $family): int => count($family['skus']))." SKUs\n";

            $lineFamilies->each(function (array $family): void {
                echo "- {$family['product_type']} > {$family['name']}: ".count($family['skus'])." SKUs\n";
            });

            echo "\n";
        });

    exit(0);
}

$summary = DB::transaction(function () use ($families, $lineGroups): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();
    $brand = findOrCreateSleekBrand($catalogue);

    $brand->fill([
        'name' => 'Sleek',
        'slug' => uniqueBrandSlug($catalogue, 'sleek', $brand->id),
        'url' => 'https://www.sleek.co.uk/',
        'note' => mergeNote($brand->note, 'Master brand for official Sleek hair ranges. Reference structures are imported from official Sleek range pages and product pages and must be stock-checked before publishing retail products.'),
        'is_active' => true,
    ])->save();

    $lineModels = [];
    foreach ($lineGroups as $lineName => $lineConfig) {
        $lineModels[$lineName] = findOrCreateLine($brand, $lineName, $lineConfig['url'], $lineConfig['sort_order']);
    }

    $productTypes = [];
    foreach ($families->groupBy(fn (array $family): string => $family['line_name'].'|'.$family['product_type']) as $key => $typeFamilies) {
        /** @var Collection<int, array<string, mixed>> $typeFamilies */
        $first = $typeFamilies->first();
        $line = $lineModels[$first['line_name']];
        $typeIndex = array_search($first['product_type'], $families
            ->where('line_name', $first['line_name'])
            ->pluck('product_type')
            ->unique()
            ->values()
            ->all(), true);

        $productTypes[$key] = findOrCreateProductType(
            $brand,
            $line,
            $first['product_type'],
            $first['line_url'],
            (((int) $typeIndex) + 1) * 10,
            $first['line_name'],
        );
    }

    $createdStyles = 0;
    $updatedStyles = 0;
    $createdSkus = 0;
    $updatedSkus = 0;
    $styleIds = [];

    foreach ($families as $index => $family) {
        $line = $lineModels[$family['line_name']];
        $productType = $productTypes[$family['line_name'].'|'.$family['product_type']];
        $style = findExistingLineStyle($line, $family['name']);

        if (! $style) {
            $style = new BrandCatalogueStyle([
                'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $family['name']),
            ]);
            $createdStyles++;
        } else {
            $updatedStyles++;
        }

        $style->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $productType->id,
            'brand_catalogue_material_id' => null,
            'material_name' => $family['material_name'],
            'name' => $family['name'],
            'note' => mergeNote($style->note, styleNote($family)),
            'url' => $family['url'],
            'is_active' => true,
            'sort_order' => $style->exists ? $style->sort_order : $index * 10,
        ])->save();

        syncStyleImages($style, $family['image_urls'], $family);
        [$created, $updated] = syncVariantsAndSkus($style, collect($family['skus']), $family);

        $createdSkus += $created;
        $updatedSkus += $updated;
        $styleIds[] = $style->id;
    }

    return [
        'brand_id' => $brand->id,
        'lines_touched' => count($lineModels),
        'product_types_touched' => count($productTypes),
        'styles_created' => $createdStyles,
        'styles_updated' => $updatedStyles,
        'styles_total_touched' => count(array_unique($styleIds)),
        'skus_created' => $createdSkus,
        'skus_updated' => $updatedSkus,
        'source_skus' => $families->sum(fn (array $family): int => count($family['skus'])),
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
    ];
});

echo "Sleek selected official line structures imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return array<string, array<string, mixed>>
 */
function sleekSelectedLineGroups(): array
{
    return [
        'Sleek Brazilian' => [
            'url' => 'https://www.sleek.co.uk/sleek-brazilian',
            'sort_order' => 20,
            'pages' => [
                pageFamily('Brazilian Virgin Straight', 'https://www.sleek.co.uk/bvs', 'Human Hair Weave', '100% Brazilian Virgin Hair'),
                pageFamily('Brazilian Virgin Wavy', 'https://www.sleek.co.uk/bvw', 'Human Hair Weave', '100% Brazilian Virgin Hair'),
                pageFamily('Deep Wave Bulk', 'https://www.sleek.co.uk/deep-wave-bulk', 'Human Hair Bulk Hair', '100% Human Hair'),
                pageFamily('Brazilian Virgin Kinky', 'https://www.sleek.co.uk/bvk-1', 'Human Hair Weave', '100% Brazilian Virgin Hair'),
                pageFamily('Virgin Afro Kinky Bulk', 'https://www.sleek.co.uk/human-hair-afro-kinky', 'Human Hair Bulk Hair', '100% Human Hair'),
            ],
            'manual_families' => [
                manualFamily(
                    'Brazilian Virgin Deep',
                    'https://www.sleek.co.uk/brazilian-virgin-hair',
                    'Human Hair Weave',
                    '100% Brazilian Virgin Hair',
                    'BVD',
                    'Packed in plastic bags, this untreated virgin hair bundle is available in a deep curly pattern.',
                    [],
                    [
                        lengthColours('14"', ['1B'], '95g'),
                        lengthColours('16"', ['1B'], '95g'),
                        lengthColours('18"', ['1B'], '95g'),
                        lengthColours('20"', ['1B'], '95g'),
                        lengthColours('22"', ['1B'], '95g'),
                        lengthColours('24"', ['1B'], '95g'),
                    ],
                    'Official Sleek Brazilian Virgin Hair page lists this product, but no separate family-specific image was visible, so image is left blank.',
                ),
                manualFamily(
                    'Brazilian Blonde Straight',
                    'https://www.sleek.co.uk/brazilian-virgin-hair',
                    'Human Hair Weave',
                    '100% Brazilian Virgin Hair',
                    'BBS',
                    'Packed in plastic bags, this untreated blonde straight bundle is listed in colour 613.',
                    [],
                    [
                        lengthColours('12"', ['613'], '95g'),
                        lengthColours('14"', ['613'], '95g'),
                        lengthColours('16"', ['613'], '95g'),
                        lengthColours('18"', ['613'], '95g'),
                        lengthColours('20"', ['613'], '95g'),
                        lengthColours('22"', ['613'], '95g'),
                        lengthColours('24"', ['613'], '95g'),
                        lengthColours('26"', ['613'], '95g'),
                    ],
                    'Official Sleek Brazilian Virgin Hair page lists this product, but no separate family-specific image was visible, so image is left blank.',
                ),
                manualFamily(
                    'Brazilian Blonde Body Wave',
                    'https://www.sleek.co.uk/brazilian-virgin-hair',
                    'Human Hair Weave',
                    '100% Brazilian Virgin Hair',
                    'BBB',
                    'Packed in plastic bags, this untreated blonde body wave bundle is listed in colour 613.',
                    [],
                    [
                        lengthColours('12"', ['613'], '95g'),
                        lengthColours('14"', ['613'], '95g'),
                        lengthColours('16"', ['613'], '95g'),
                        lengthColours('18"', ['613'], '95g'),
                        lengthColours('20"', ['613'], '95g'),
                        lengthColours('22"', ['613'], '95g'),
                        lengthColours('24"', ['613'], '95g'),
                        lengthColours('26"', ['613'], '95g'),
                        lengthColours('28"', ['613'], '95g'),
                    ],
                    'Official Sleek Brazilian Virgin Hair page lists this product, but no separate family-specific image was visible, so image is left blank.',
                ),
            ],
        ],
        'European Weave' => [
            'url' => 'https://www.sleek.co.uk/ew-indian-ew',
            'sort_order' => 50,
            'pages' => [
                pageFamily('European Weave (EW)', 'https://www.sleek.co.uk/ew-weave', 'Human Hair Weave', 'Human Hair'),
                pageFamily('EW Indian', 'https://www.sleek.co.uk/ewindian', 'Human Hair Weave', '100% Human Hair'),
                pageFamily('EW Indian 4pcs Clip Ins', 'https://www.sleek.co.uk/ew-indian-4pcs', 'Human Hair Clip-Ins', '100% Human Hair'),
                pageFamily('EW Indian 4pcs Clip Ins SP', 'https://www.sleek.co.uk/ew-indian-4pcs-sp', 'Human Hair Clip-Ins', '100% Human Hair'),
                pageFamily('EW Indian 7pcs Clip Ins', 'https://www.sleek.co.uk/ew-indian-7pcs', 'Human Hair Clip-Ins', '100% Human Hair'),
            ],
        ],
        'Fashion Idol Classic Brazilian' => [
            'url' => 'https://www.sleek.co.uk/fashion-idol-classic-brazilian',
            'sort_order' => 100,
            'pages' => [
                pageFamily('Brasilia Weave', 'https://www.sleek.co.uk/brasilia-weave', 'Synthetic Hair Weave', 'Synthetic Hair'),
                pageFamily('Claro Weave 3pcs', 'https://www.sleek.co.uk/claro', 'Synthetic Hair Weave', 'Synthetic Tongable Hair'),
                pageFamily('Crimpy Yaki Weave', 'https://www.sleek.co.uk/crimp-yaki-weave', 'Synthetic Hair Weave', 'Synthetic Hair'),
                pageFamily('Duchess Weave', 'https://www.sleek.co.uk/duchess-weave', 'Synthetic Hair Weave', 'Synthetic Hair'),
                pageFamily('Elia Weave 2pcs', 'https://www.sleek.co.uk/elia', 'Synthetic Hair Weave', 'Synthetic Tongable Hair'),
                pageFamily('Figo Weave 2pcs', 'https://www.sleek.co.uk/figo-weave', 'Synthetic Hair Weave', 'Synthetic Tongable Hair'),
                pageFamily('Glossy Weave 2pcs', 'https://www.sleek.co.uk/glossy-weave', 'Synthetic Hair Weave', 'Synthetic Hair'),
                pageFamily('Hot Afro Yaki Weave', 'https://www.sleek.co.uk/hot-afro-yaki', 'Synthetic Hair Weave', 'Synthetic Hair'),
                pageFamily('Hot Elegance Weave', 'https://www.sleek.co.uk/hot-elegance-weave', 'Synthetic Hair Weave', 'Synthetic Hair'),
                pageFamily('Hot Natural Yaki', 'https://www.sleek.co.uk/hot-natural-yaki', 'Synthetic Hair Weave', 'Synthetic Hair'),
                pageFamily('Ivory Weave', 'https://www.sleek.co.uk/ivory-weave', 'Synthetic Hair Weave', 'Synthetic Hair'),
                pageFamily('Izzy Weave 2pcs', 'https://www.sleek.co.uk/izzy', 'Synthetic Hair Weave', 'Synthetic Tongable Hair'),
                pageFamily('Maya Weave 2pcs', 'https://www.sleek.co.uk/maya', 'Synthetic Hair Weave', 'Synthetic Tongable Hair'),
                pageFamily('Peru Weave', 'https://www.sleek.co.uk/peru-weave', 'Synthetic Hair Weave', 'Synthetic Tongable Hair'),
                pageFamily('Rio Natural Weave 2pcs', 'https://www.sleek.co.uk/rio-weave-1', 'Synthetic Hair Weave', 'Synthetic Tongable Hair'),
                pageFamily('Sara Weave 2pcs', 'https://www.sleek.co.uk/sara', 'Synthetic Hair Weave', 'Synthetic Tongable Hair'),
                pageFamily('Zayla Weave 2pcs', 'https://www.sleek.co.uk/zayla', 'Synthetic Hair Weave', 'Synthetic Tongable Hair'),
            ],
        ],
        'Remy Gorgeous' => [
            'url' => 'https://www.sleek.co.uk/remy-gorgeous',
            'sort_order' => 140,
            'pages' => [
                pageFamily('Silky Straight', 'https://www.sleek.co.uk/silkystraight', 'Synthetic Hair Weave', '100% Tongable Synthetic Hair'),
                pageFamily('Body Wave', 'https://www.sleek.co.uk/bodywave', 'Synthetic Hair Weave', 'Synthetic Hair'),
                pageFamily('Water Wave Bulk', 'https://www.sleek.co.uk/waterwavebulk', 'Synthetic Bulk Hair', '100% Tongable Synthetic Hair'),
                pageFamily('Deep Wave', 'https://www.sleek.co.uk/deepwave', 'Synthetic Hair Weave', 'Synthetic Hair'),
                pageFamily('Spanish Wave Bulk', 'https://www.sleek.co.uk/spanishwavebulk', 'Synthetic Bulk Hair', '100% Tongable Synthetic Hair'),
                pageFamily('Water Wave', 'https://www.sleek.co.uk/waterwave', 'Synthetic Hair Weave', 'Synthetic Hair'),
                pageFamily('Deep Wave Bulk', 'https://www.sleek.co.uk/deepwavebulk', 'Synthetic Bulk Hair', '100% Tongable Synthetic Hair'),
                pageFamily('Perm Yaki', 'https://www.sleek.co.uk/mi-milla', 'Synthetic Hair Weave', '100% Tongable Synthetic Hair'),
            ],
        ],
    ];
}

function pageFamily(string $name, string $url, string $productType, string $materialName): array
{
    return [
        'name' => $name,
        'url' => $url,
        'product_type' => $productType,
        'material_name' => $materialName,
    ];
}

/**
 * @param array<int, string> $imageUrls
 * @param array<int, array<int, array<string, string|null>>> $skuGroups
 */
function manualFamily(
    string $name,
    string $url,
    string $productType,
    string $materialName,
    string $code,
    string $description,
    array $imageUrls,
    array $skuGroups,
    string $reviewNote = '',
): array {
    return [
        'name' => $name,
        'url' => $url,
        'product_type' => $productType,
        'material_name' => $materialName,
        'code' => $code,
        'description' => $description,
        'image_urls' => $imageUrls,
        'skus' => collect($skuGroups)->flatten(1)->values()->all(),
        'review_note' => $reviewNote,
    ];
}

/**
 * @param array<string, array<string, mixed>> $lineGroups
 * @return array<int, array<string, mixed>>
 */
function buildOfficialFamilies(array $lineGroups): array
{
    $families = [];

    foreach ($lineGroups as $lineName => $lineConfig) {
        foreach ($lineConfig['pages'] as $page) {
            $html = fetchOfficialHtml($page['url']);
            $detailLines = extractDetailLines($html);
            [$skus, $code, $familyWeight] = parseSkuRecords($detailLines);

            if ($skus === []) {
                throw new RuntimeException("No safe SKU records parsed from {$page['url']}");
            }

            $families[] = [
                'line_name' => $lineName,
                'line_url' => $lineConfig['url'],
                'line_sort_order' => $lineConfig['sort_order'],
                'name' => $page['name'],
                'product_type' => $page['product_type'],
                'material_name' => $page['material_name'],
                'url' => $page['url'],
                'code' => $code ?: '',
                'description' => extractDescription($detailLines, $page['name']),
                'image_urls' => extractImageUrls($html),
                'family_weight' => $familyWeight,
                'skus' => $skus,
                'review_note' => '',
            ];
        }

        foreach (($lineConfig['manual_families'] ?? []) as $manualFamily) {
            $manualFamily['line_name'] = $lineName;
            $manualFamily['line_url'] = $lineConfig['url'];
            $manualFamily['line_sort_order'] = $lineConfig['sort_order'];
            $manualFamily['family_weight'] = null;
            $families[] = $manualFamily;
        }
    }

    return $families;
}

function fetchOfficialHtml(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125 Safari/537.36',
    ]);

    $html = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (! is_string($html) || $html === '' || $status >= 400) {
        throw new RuntimeException("Could not fetch {$url}. HTTP {$status}. {$error}");
    }

    return $html;
}

/**
 * @return array<int, string>
 */
function extractDetailLines(string $html): array
{
    preg_match_all('/<div class="sqs-html-content"[^>]*>(.*?)<\/div>/is', $html, $matches);

    $lines = [];
    foreach ($matches[1] as $block) {
        $blockLines = htmlBlockLines($block);

        if (collect($blockLines)->contains(fn (string $line): bool => preg_match('/(Length|LENGTH|COLS|COL:|Order Code|ORDER CODE|Weight|WEIGHT|COLOURS)/i', $line) === 1)) {
            array_push($lines, ...$blockLines);
        }
    }

    return $lines;
}

/**
 * @return array<int, string>
 */
function htmlBlockLines(string $html): array
{
    $text = preg_replace('/(?is)<br\s*\/?>/', "\n", $html) ?? $html;
    $text = preg_replace('/(?is)<\/(p|h1|h2|h3|h4|li|div)>/', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

    return collect(preg_split('/\R/', $text) ?: [])
        ->map(fn (string $line): string => cleanSpaces($line))
        ->filter()
        ->values()
        ->all();
}

/**
 * @return array<int, string>
 */
function extractImageUrls(string $html): array
{
    preg_match_all('/https:\/\/images\.squarespace-cdn\.com\/content\/v1\/[^"\'<>\s]+/i', $html, $matches);

    return collect($matches[0] ?? [])
        ->map(fn (string $url): string => preg_replace('/\?.*$/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5)) ?? $url)
        ->filter(fn (string $url): bool => preg_match('/\.(jpg|jpeg|png|webp)$/i', $url) === 1)
        ->reject(fn (string $url): bool => preg_match('/favicon|logo|Untitled|newsletter|banner|Background|Insta|private/i', $url) === 1)
        ->unique()
        ->take(1)
        ->values()
        ->all();
}

/**
 * @param array<int, string> $lines
 * @return array{0:array<int, array<string, string|null>>,1:?string,2:?string}
 */
function parseSkuRecords(array $lines): array
{
    $records = [];
    $code = null;
    $weight = null;
    $lengthText = null;
    $colourText = null;

    foreach ($lines as $index => $line) {
        $line = normaliseSourceText($line);

        if (preg_match('/^WEIGHT\s*:\s*(.+)$/i', $line, $match)) {
            $weight = cleanSpaces($match[1]);
        }

        if (preg_match('/^ORDER CODE\s*:\s*(.+)$/i', $line, $match)) {
            $code = cleanSpaces($match[1]);
        }

        if (preg_match('/^LENGTHS?\s*:\s*(.+)$/i', $line, $match)) {
            $lengthText = $match[1];
        }

        if (preg_match('/^COLS?\s*:?\s*(.+)$/i', $line, $match)) {
            $colourText = $match[1];
        }

        if (
            preg_match('/Please note for\s+(\d+)\s*"\s*\(code is\s*([^)]*)\)\s*weight\s*-\s*([^:]+):/i', $line, $match)
            && isset($lines[$index + 1])
        ) {
            foreach (parseColours($lines[$index + 1]) as $colour) {
                $records[] = [
                    'Length' => ((int) $match[1]).'"',
                    'Colour' => $colour,
                    'weight' => cleanSpaces($match[3]),
                    'code' => cleanSpaces($match[2]),
                ];
            }
        }

        foreach (parseLengthColourMappingLine($line) as $record) {
            $records[] = $record + ['code' => null];
        }
    }

    if ($records === [] && $lengthText !== null && $colourText !== null) {
        foreach (parseLengths($lengthText) as $length) {
            foreach (parseColours($colourText) as $colour) {
                $records[] = [
                    'Length' => $length,
                    'Colour' => $colour,
                    'weight' => $weight,
                    'code' => null,
                ];
            }
        }
    }

    $records = collect($records)
        ->filter(fn (array $record): bool => ($record['Length'] ?? '') !== '' || ($record['Colour'] ?? '') !== '')
        ->unique(fn (array $record): string => implode('|', [
            $record['Length'] ?? '',
            $record['Colour'] ?? '',
            $record['weight'] ?? '',
            $record['code'] ?? '',
        ]))
        ->values()
        ->all();

    return [$records, $code, $weight];
}

/**
 * @return array<int, array<string, string|null>>
 */
function parseLengthColourMappingLine(string $line): array
{
    $line = normaliseSourceText($line);

    if (preg_match('/^Please note/i', $line) === 1) {
        return [];
    }

    if (preg_match('/^(.+?)\s*:\s*(.+)$/', $line, $match) !== 1 && preg_match('/^(.+?)\s+-\s+(.+)$/', $line, $match) !== 1) {
        return [];
    }

    $lengths = parseLengths($match[1]);
    if ($lengths === []) {
        return [];
    }

    $weight = null;
    if (preg_match('/\(([^)]*g[^)]*)\)/i', $match[1], $weightMatch) === 1) {
        $weight = cleanSpaces($weightMatch[1]);
    }

    $colours = parseColours($match[2]);
    if ($colours === []) {
        return [];
    }

    $records = [];
    foreach ($lengths as $length) {
        foreach ($colours as $colour) {
            $records[] = [
                'Length' => $length,
                'Colour' => $colour,
                'weight' => $weight,
            ];
        }
    }

    return $records;
}

/**
 * @return array<int, string>
 */
function parseLengths(string $value): array
{
    $value = normaliseSourceText($value);
    preg_match_all('/(\d+)\s*"\s*(S)?/i', $value, $matches, PREG_SET_ORDER);

    return collect($matches)
        ->map(fn (array $match): string => ((int) $match[1]).'"'.(! empty($match[2]) ? ' S' : ''))
        ->unique()
        ->values()
        ->all();
}

/**
 * @return array<int, string>
 */
function parseColours(string $value): array
{
    $value = normaliseSourceText($value);

    return collect(preg_split('/\s*,\s*/', $value) ?: [])
        ->map(fn (string $colour): string => normaliseColour($colour))
        ->filter()
        ->unique()
        ->values()
        ->all();
}

function normaliseSourceText(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
    $value = str_replace(["\xE2\x80\x9D", "\xE2\x80\x9C", "\xE2\x80\x99", "\xE2\x80\x98"], '"', $value);
    $value = str_replace(['’’', '”', '“', '’', '‘'], '"', $value);
    $value = str_replace(
        ['2TB/DARKRED 4', 'T18/60 TT1B/30', 'TT1B/27/ 613', 'P24/613,,'],
        ['2TB/DARKRED, 4', 'T18/60, TT1B/30', 'TT1B/27/613', 'P24/613,'],
        $value,
    );

    return cleanSpaces($value);
}

function normaliseColour(string $colour): string
{
    $colour = trim(normaliseSourceText($colour), " \t\n\r\0\x0B,.;");
    $colour = preg_replace('/\s*\/\s*/', '/', $colour) ?? $colour;

    return Str::upper($colour);
}

/**
 * @return array<int, array<string, string|null>>
 */
function lengthColours(string $length, array $colours, ?string $weight): array
{
    return collect($colours)
        ->map(fn (string $colour): array => [
            'Length' => $length,
            'Colour' => normaliseColour($colour),
            'weight' => $weight,
            'code' => null,
        ])
        ->all();
}

/**
 * @param array<int, string> $lines
 */
function extractDescription(array $lines, string $name): string
{
    $nameKey = compareKey($name);
    $startIndex = null;

    foreach ($lines as $index => $line) {
        if (compareKey($line) === $nameKey) {
            $startIndex = $index + 1;
            break;
        }
    }

    if ($startIndex === null) {
        return '';
    }

    $description = [];
    for ($index = $startIndex; $index < count($lines); $index++) {
        $line = normaliseSourceText($lines[$index]);

        if (isFieldLine($line) || isMappingCandidate($line)) {
            break;
        }

        if (isLabelLine($line)) {
            continue;
        }

        $description[] = rtrim($line, '.').'.';
    }

    return cleanSpaces(implode(' ', $description));
}

function compareKey(string $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower($value)) ?? '';
}

function isFieldLine(string $line): bool
{
    return preg_match('/^(LENGTH|LENGTHS|COLS|COL|ORDER CODE|WEIGHT|WEFT WIDTH|LENGTH\s*&\s*COLS|LENGTHS\s*\/\s*COLOURS|LENGTHS\s*\/\s*COLS|Please note)/i', $line) === 1;
}

function isMappingCandidate(string $line): bool
{
    $line = normaliseSourceText($line);

    return preg_match('/^\d+\s*"\s*S?\s*[:\-]/i', $line) === 1
        || preg_match('/^\d+\s*"\s*,.*[:\-]/i', $line) === 1;
}

function isLabelLine(string $line): bool
{
    if (preg_match('/\.$/', $line) === 1) {
        return false;
    }

    $upper = Str::upper($line);

    return $line === $upper
        || str_contains($upper, 'SLEEK BRAZILIAN')
        || str_contains($upper, 'FASHION IDOL')
        || str_contains($upper, 'REMY GORGEOUS')
        || str_contains($upper, 'EW INDIAN')
        || str_contains($upper, 'SYNTHETIC WEAVE')
        || str_contains($upper, 'HUMAN HAIR WEAVE')
        || str_contains($upper, 'HUMAN HAIR BULK');
}

function findOrCreateSleekBrand(BrandCatalogue $catalogue): BrandCatalogueBrand
{
    $brand = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where(function ($query): void {
            $query
                ->whereIn('slug', ['sleek', 'sleek-hair'])
                ->orWhereIn('name', ['Sleek', 'Sleek Hair']);
        })
        ->first();

    if ($brand) {
        return $brand;
    }

    return BrandCatalogueBrand::query()->create([
        'brand_catalogue_id' => $catalogue->id,
        'name' => 'Sleek',
        'slug' => uniqueBrandSlug($catalogue, 'sleek'),
        'is_active' => true,
        'sort_order' => 160,
    ]);
}

function findOrCreateLine(BrandCatalogueBrand $brand, string $name, string $url, int $sortOrder): BrandCatalogueLine
{
    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', $name)
        ->first();

    if (! $line) {
        $line = new BrandCatalogueLine([
            'brand_catalogue_brand_id' => $brand->id,
            'name' => $name,
            'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), $name),
        ]);
    }

    $line->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'name' => $name,
        'note' => mergeNote($line->note, "{$name} is treated as a sub-brand/line under the Sleek master brand."),
        'url' => $url,
        'is_default' => false,
        'is_active' => true,
        'sort_order' => $line->exists ? $line->sort_order : $sortOrder,
    ])->save();

    return $line;
}

function findOrCreateProductType(BrandCatalogueBrand $brand, BrandCatalogueLine $line, string $name, string $url, int $sortOrder, string $lineName): BrandCatalogueProductType
{
    $productType = BrandCatalogueProductType::query()->firstOrCreate(
        [
            'brand_catalogue_line_id' => $line->id,
            'name' => $name,
        ],
        [
            'brand_catalogue_brand_id' => $brand->id,
            'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $name),
            'is_active' => true,
            'sort_order' => $sortOrder,
        ],
    );

    $productType->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'note' => mergeNote($productType->note, "Structured from official Sleek {$lineName} pages."),
        'url' => $url,
        'is_active' => true,
        'sort_order' => $sortOrder,
    ])->save();

    return $productType;
}

function findExistingLineStyle(BrandCatalogueLine $line, string $name): ?BrandCatalogueStyle
{
    $productTypeIds = BrandCatalogueProductType::query()
        ->where('brand_catalogue_line_id', $line->id)
        ->pluck('id');

    if ($productTypeIds->isEmpty()) {
        return null;
    }

    return BrandCatalogueStyle::query()
        ->whereIn('brand_catalogue_product_type_id', $productTypeIds)
        ->where('name', $name)
        ->first();
}

/**
 * @param array<string, mixed> $family
 */
function styleNote(array $family): string
{
    $parts = [
        "Family/style imported from the official Sleek {$family['line_name']} product page.",
    ];

    if ($family['code'] !== '') {
        $parts[] = "Order code {$family['code']}.";
    }

    if ($family['description'] !== '') {
        $parts[] = $family['description'];
    }

    if (($family['review_note'] ?? '') !== '') {
        $parts[] = $family['review_note'];
    }

    return implode(' ', $parts);
}

/**
 * @param array<string, mixed> $family
 */
function syncStyleImages(BrandCatalogueStyle $style, array $imageUrls, array $family): void
{
    CatalogueImage::query()
        ->where('imageable_type', BrandCatalogueStyle::class)
        ->where('imageable_id', $style->id)
        ->where('source_label', "Sleek official {$family['line_name']} product page")
        ->when($imageUrls !== [], fn ($query) => $query->whereNotIn('external_url', $imageUrls))
        ->delete();

    foreach ($imageUrls as $index => $imageUrl) {
        CatalogueImage::query()->updateOrCreate(
            [
                'imageable_type' => BrandCatalogueStyle::class,
                'imageable_id' => $style->id,
                'external_url' => $imageUrl,
            ],
            [
                'image_role' => 'source_image',
                'storage_disk' => null,
                'storage_path' => null,
                'original_filename' => basename(parse_url($imageUrl, PHP_URL_PATH) ?: ''),
                'mime_type' => null,
                'file_size' => null,
                'sort_order' => $index * 10,
                'is_primary' => $index === 0,
                'source_label' => "Sleek official {$family['line_name']} product page",
                'usage_context' => 'all',
                'notes' => "Official source image for {$family['name']}.",
            ],
        );
    }
}

/**
 * @param Collection<int, array<string, string|null>> $records
 * @param array<string, mixed> $family
 * @return array{0:int,1:int}
 */
function syncVariantsAndSkus(BrandCatalogueStyle $style, Collection $records, array $family): array
{
    if ($records->isEmpty()) {
        return [0, 0];
    }

    $variantNames = $records
        ->flatMap(fn (array $record): array => array_keys(array_filter(
            $record,
            fn ($value, string $key): bool => in_array($key, ['Length', 'Colour'], true) && (string) $value !== '',
            ARRAY_FILTER_USE_BOTH,
        )))
        ->unique()
        ->values();

    $variants = [];
    foreach ($variantNames as $index => $variantName) {
        $variants[$variantName] = BrandCatalogueVariant::query()->updateOrCreate(
            [
                'brand_catalogue_style_id' => $style->id,
                'name' => $variantName,
            ],
            [
                'variant_type' => $variantName === 'Length' ? 'measurement' : 'colour_code',
                'sort_order' => ($index + 1) * 10,
            ],
        );
    }

    $optionMaps = [];
    foreach ($variants as $variantName => $variant) {
        $values = $records
            ->pluck($variantName)
            ->filter(fn ($value): bool => (string) $value !== '')
            ->unique()
            ->sortBy(fn (string $value): string => $variantName === 'Length' ? sprintf('%05d:%s', lengthNumber($value), $value) : colourSortKey($value))
            ->values()
            ->all();

        $optionMaps[$variantName] = syncOptions($variant, $values);
    }

    $created = 0;
    $updated = 0;

    foreach ($records as $index => $record) {
        $selected = [];
        foreach (array_keys($variants) as $variantName) {
            if ((string) ($record[$variantName] ?? '') !== '') {
                $selected[$variantName] = (string) $record[$variantName];
            }
        }

        $signature = optionSignature($selected);
        $name = skuName($style->name, $selected);

        $sku = BrandCatalogueSku::query()
            ->where('brand_catalogue_style_id', $style->id)
            ->where('option_signature', $signature)
            ->first();

        if (! $sku) {
            $sku = new BrandCatalogueSku([
                'brand_catalogue_style_id' => $style->id,
                'option_signature' => $signature,
                'slug' => scopedSlug(BrandCatalogueSku::query()->where('brand_catalogue_style_id', $style->id), $name),
            ]);
            $created++;
        } else {
            $updated++;
        }

        $sku->fill([
            'name' => $name,
            'sku_code' => $record['code'] ?: $family['code'],
            'barcode' => $sku->barcode,
            'description' => $sku->description,
            'note' => mergeNote($sku->note, skuNote($family, $record)),
            'url' => $family['url'],
            'is_active' => true,
            'sort_order' => $sku->exists ? $sku->sort_order : $index * 10,
        ])->save();

        DB::table('brand_catalogue_sku_variant_options')
            ->where('brand_catalogue_sku_id', $sku->id)
            ->delete();

        $rows = [];
        foreach ($selected as $variantName => $value) {
            $rows[] = [
                'brand_catalogue_sku_id' => $sku->id,
                'brand_catalogue_variant_id' => $variants[$variantName]->id,
                'brand_catalogue_variant_option_id' => $optionMaps[$variantName][$value],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('brand_catalogue_sku_variant_options')->insert($rows);
        }
    }

    return [$created, $updated];
}

/**
 * @param array<int, string> $values
 * @return array<string, int>
 */
function syncOptions(BrandCatalogueVariant $variant, array $values): array
{
    $map = [];

    foreach ($values as $index => $value) {
        $option = BrandCatalogueVariantOption::query()->updateOrCreate(
            [
                'variant_id' => $variant->id,
                'label' => $value,
            ],
            [
                'value' => $value,
                'sort_order' => $index * 10,
            ],
        );

        $map[$value] = $option->id;
    }

    return $map;
}

/**
 * @param array<string, string> $selected
 */
function optionSignature(array $selected): string
{
    return collect($selected)
        ->map(fn (string $value, string $name): string => $name.':'.$value)
        ->implode('|');
}

/**
 * @param array<string, string> $selected
 */
function skuName(string $styleName, array $selected): string
{
    $parts = [$styleName];

    if (isset($selected['Length'])) {
        $parts[] = $selected['Length'];
    }

    if (isset($selected['Colour'])) {
        $parts[] = 'Colour '.$selected['Colour'];
    }

    return implode(' - ', $parts);
}

/**
 * @param array<string, mixed> $family
 * @param array<string, string|null> $record
 */
function skuNote(array $family, array $record): string
{
    $code = $record['code'] ?: $family['code'];
    $parts = ["Official Sleek {$family['line_name']} product page lists this SKU."];

    if ($code !== '') {
        $parts[] = "Order code {$code}.";
    }

    if (($record['weight'] ?? null) !== null) {
        $parts[] = 'Weight: '.$record['weight'].'.';
    } elseif (($family['family_weight'] ?? null) !== null) {
        $parts[] = 'Weight: '.$family['family_weight'].'.';
    }

    return implode(' ', $parts);
}

function lengthNumber(string $length): int
{
    if (preg_match('/\d+/', $length, $match) === 1) {
        return (int) $match[0];
    }

    return 0;
}

function colourSortKey(string $colour): string
{
    if (preg_match('/^\d+$/', $colour) === 1) {
        return sprintf('0%05d', (int) $colour);
    }

    if (preg_match('/^\d+[A-Z]$/', $colour) === 1) {
        return sprintf('1%05d%s', (int) $colour, substr($colour, -1));
    }

    return '2'.$colour;
}

function uniqueBrandSlug(BrandCatalogue $catalogue, string $slug, ?int $exceptId = null): string
{
    $base = Str::slug($slug) ?: 'item';
    $candidate = $base;
    $suffix = 2;

    while (
        BrandCatalogueBrand::query()
            ->where('brand_catalogue_id', $catalogue->id)
            ->where('slug', $candidate)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists()
    ) {
        $candidate = $base.'-'.$suffix;
        $suffix++;
    }

    return $candidate;
}

function scopedSlug($query, string $name): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $suffix = 2;

    while ((clone $query)->where('slug', $slug)->exists()) {
        $slug = $base.'-'.$suffix;
        $suffix++;
    }

    return $slug;
}

function mergeNote(?string $existing, string $addition): string
{
    $existing = cleanSpaces((string) $existing);
    $addition = cleanSpaces($addition);

    if ($addition === '') {
        return $existing;
    }

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
