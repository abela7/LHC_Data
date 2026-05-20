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
$pdfPath = argumentValue('--pdf=', $argv)
    ?: 'C:\\Users\\Abela\\Desktop\\Khan\\Product List 25-2 (03_10_25).pdf';

$rawRecords = extractPdfRecords($pdfPath);
$sourceRows = normaliseSourceRows($rawRecords);
$sourceSkus = buildSourceSkus($sourceRows);
$groups = $sourceSkus->groupBy(fn (array $sku): string => implode('|', [
    $sku['line'],
    $sku['product_type'],
    $sku['family'],
]));

if ($dryRun) {
    echo "Angels / TOP Hair Fashion PDF dry run.\n";
    echo 'PDF rows: '.count($rawRecords)."\n";
    echo 'Source product rows: '.$sourceRows->count()."\n";
    echo 'Style groups: '.$groups->count()."\n";
    echo 'SKU variants: '.$sourceSkus->count()."\n\n";

    $groups
        ->sortKeys()
        ->each(function (Collection $skus, string $key): void {
            $first = $skus->first();
            echo "{$first['line']} > {$first['product_type']} > {$first['family']}\n";
            echo '  skus: '.$skus->count().' | source rows: '.$skus->pluck('source_key')->unique()->count()."\n";
        });

    exit(0);
}

$summary = DB::transaction(function () use ($groups, $pdfPath): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();

    $brand = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where('slug', 'angels')
        ->first();

    if (! $brand) {
        $brand = BrandCatalogueBrand::query()->create([
            'brand_catalogue_id' => $catalogue->id,
            'name' => 'Angels',
            'slug' => scopedSlug(BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id), 'Angels'),
            'is_active' => true,
            'sort_order' => 40,
        ]);
    }

    $brand->fill([
        'note' => mergeNote(
            $brand->note,
            'Reference structure imported from TOP Hair Fashion Ltd Product List 25-2 PDF. The PDF contains mixed TOP Hair Fashion, Angels, Angels Collection and Divine Collection packaging; confirm exact retail branding in shop before publishing.'
        ),
        'is_active' => true,
    ])->save();

    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('is_default', true)
        ->first();

    if (! $line) {
        $line = BrandCatalogueLine::query()->firstOrCreate(
            [
                'brand_catalogue_brand_id' => $brand->id,
                'name' => 'Angels',
            ],
            [
                'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), 'Angels'),
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 0,
            ],
        );
    }

    $line->fill([
        'note' => mergeNote(
            $line->note,
            'Angels main brand catalogue. TOP Hair Fashion Ltd is kept only as the PDF/source reference, not as a visible catalogue layer.'
        ),
        'is_default' => true,
        'is_active' => true,
        'sort_order' => 0,
    ])->save();

    $legacyTopHairLine = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', 'Top Hair Fashion')
        ->where('id', '!=', $line->id)
        ->first();

    if ($legacyTopHairLine) {
        BrandCatalogueProductType::query()
            ->where('brand_catalogue_line_id', $legacyTopHairLine->id)
            ->update(['brand_catalogue_line_id' => $line->id]);

        if ($legacyTopHairLine->productTypes()->count() === 0) {
            $legacyTopHairLine->delete();
        }
    }

    $createdSkus = 0;
    $updatedSkus = 0;
    $productTypeIds = [];
    $styleIds = [];

    foreach ($groups as $groupSkus) {
        $first = $groupSkus->first();

        $productType = BrandCatalogueProductType::query()->firstOrCreate(
            [
                'brand_catalogue_line_id' => $line->id,
                'name' => $first['product_type'],
            ],
            [
                'brand_catalogue_brand_id' => $brand->id,
                'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $first['product_type']),
                'is_active' => true,
            ],
        );

        $productType->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'note' => mergeNote($productType->note, 'Structured from TOP Hair Fashion PDF product categories.'),
            'is_active' => true,
            'sort_order' => productTypeSort($first['product_type']),
        ])->save();

        $style = BrandCatalogueStyle::query()
            ->where('brand_catalogue_product_type_id', $productType->id)
            ->where('name', $first['family'])
            ->first();

        if (! $style) {
            $style = new BrandCatalogueStyle([
                'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $first['family']),
            ]);
        }

        $style->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $productType->id,
            'brand_catalogue_material_id' => null,
            'material_name' => $style->material_name ?: $first['material'],
            'name' => $first['family'],
            'note' => mergeNote($style->note, styleNote($groupSkus, $pdfPath)),
            'is_active' => true,
            'sort_order' => $style->exists ? $style->sort_order : styleSort($first['family']),
        ])->save();

        [$created, $updated] = syncStyleVariantsAndSkus($style, $groupSkus);
        $createdSkus += $created;
        $updatedSkus += $updated;
        $productTypeIds[] = $productType->id;
        $styleIds[] = $style->id;
    }

    return [
        'brand_id' => $brand->id,
        'line_id' => $line->id,
        'product_types_touched' => count(array_unique($productTypeIds)),
        'styles_touched' => count(array_unique($styleIds)),
        'skus_created' => $createdSkus,
        'skus_updated' => $updatedSkus,
        'retail_products_created' => 0,
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
    ];
});

echo "Angels / TOP Hair Fashion PDF structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @param array<int, string> $argv
 */
function argumentValue(string $prefix, array $argv): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }

    return null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function extractPdfRecords(string $pdfPath): array
{
    if (! is_file($pdfPath)) {
        throw new RuntimeException("PDF not found: {$pdfPath}");
    }

    $pythonCandidates = array_filter([
        getenv('PYTHON') ?: null,
        'C:\\Users\\Abela\\AppData\\Local\\Python\\bin\\python3.exe',
        'python3',
        'python',
    ]);

    $extractor = __DIR__.DIRECTORY_SEPARATOR.'extract_angels_top_hair_pdf.py';
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
            $records = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

            return is_array($records) ? $records : [];
        }

        $lastError = trim($stderr ?: $stdout);
    }

    throw new RuntimeException('Could not extract PDF rows. '.$lastError);
}

/**
 * @param array<int, array<string, mixed>> $records
 * @return Collection<int, array<string, mixed>>
 */
function normaliseSourceRows(array $records): Collection
{
    return collect($records)
        ->flatMap(function (array $record): array {
            $title = normaliseTitle((string) ($record['title'] ?? ''));
            if ($title === '') {
                return [];
            }

            $codes = collect($record['codes'] ?? [])
                ->map(fn ($code): string => Str::upper(trim((string) $code)))
                ->filter()
                ->unique()
                ->values();

            if ($codes->isEmpty()) {
                return [];
            }

            if (Str::lower($title) === 'avvis fancy colours') {
                return $codes
                    ->map(function (string $code) use ($record): array {
                        $title = $code === 'WE009'
                            ? 'Avvis Short - Super Braids (15")'
                            : 'Avvis Long - Super Braids (24")';

                        return sourceRow($record, $title, $code, 'Fancy Colours');
                    })
                    ->all();
            }

            if (Str::lower($title) === 'natural locs - 3 tone colours') {
                return [sourceRow($record, 'Natural Locs-Looped, 18", 36 pcs', $codes->first(), '3 Tone Colours')];
            }

            return $codes
                ->map(fn (string $code): array => sourceRow($record, $title, $code))
                ->all();
        })
        ->values();
}

/**
 * @param array<string, mixed> $record
 * @return array<string, mixed>
 */
function sourceRow(array $record, string $title, string $code, ?string $colourGroup = null): array
{
    return [
        'page' => (int) ($record['page'] ?? 0),
        'column' => (int) ($record['column'] ?? 0),
        'title' => normaliseTitle($title),
        'code' => Str::upper($code),
        'price' => (string) ($record['price'] ?? ''),
        'colours' => cleanSpaces((string) ($record['colours'] ?? '')),
        'colour_group' => $colourGroup,
        'source_key' => implode(':', [
            (int) ($record['page'] ?? 0),
            (int) ($record['column'] ?? 0),
            Str::upper($code),
            normaliseTitle($title),
            $colourGroup ?: '',
        ]),
    ];
}

/**
 * @param Collection<int, array<string, mixed>> $rows
 * @return Collection<int, array<string, mixed>>
 */
function buildSourceSkus(Collection $rows): Collection
{
    return $rows->flatMap(function (array $row): array {
        $classified = classifySourceRow($row);
        $colours = colourOptions($row['colours']);
        $colourRows = $colours === [] ? [null] : $colours;

        return collect($colourRows)
            ->map(function (?string $colour, int $index) use ($row, $classified): array {
                $variants = $classified['variants'];

                if ($colour !== null) {
                    $variants['Colour'] = $colour;
                } elseif ($variants === []) {
                    $variants['Review Status'] = 'Variant review pending';
                }

                $variants = orderVariants($variants);

                return [
                    'line' => 'Angels',
                    'product_type' => $classified['product_type'],
                    'family' => $classified['family'],
                    'material' => $classified['material'],
                    'variants' => $variants,
                    'sku_name' => skuName($classified['family'], $variants),
                    'source_key' => $row['source_key'].'#'.$index,
                    'source' => $row,
                ];
            })
            ->all();
    })->values();
}

/**
 * @param array<string, mixed> $row
 * @return array{product_type:string,family:string,material:string,variants:array<string,string>}
 */
function classifySourceRow(array $row): array
{
    $title = normaliseTitle($row['title']);
    $variants = titleVariants($title);

    if (! empty($row['colour_group'])) {
        $variants['Colour Group'] = (string) $row['colour_group'];
    }

    return [
        'product_type' => productTypeName($title, $row['code']),
        'family' => familyName($title),
        'material' => materialName($title, $row['code']),
        'variants' => $variants,
    ];
}

function normaliseTitle(string $title): string
{
    $title = cleanSpaces($title);
    $title = str_replace(['-(', '- ', ' ,'], ['- (', ' - ', ','], $title);
    $title = preg_replace('/\s*-\s*/', ' - ', $title) ?? $title;
    $title = preg_replace('/\s*,\s*/', ', ', $title) ?? $title;
    $title = str_ireplace([
        'REGGAE',
        'SOPRANO',
        'chochet',
        'Spirial',
        'Mambo Twist-',
        'Natural Locs-',
        '1pack',
    ], [
        'Reggae',
        'Soprano',
        'Crochet',
        'Spiral',
        'Mambo Twist -',
        'Natural Locs -',
        '1 pack',
    ], $title);

    return cleanSpaces($title);
}

function productTypeName(string $title, string $code): string
{
    $lower = Str::lower($title);

    if (str_contains($lower, 'clip extension')) {
        return 'Clip Extensions';
    }

    if (str_contains($lower, 'lace front wig')) {
        return 'Lace Wigs';
    }

    if (str_contains($lower, 'wig') || str_starts_with($code, 'WW')) {
        return 'Wigs';
    }

    if (str_contains($lower, 'draw string') || str_contains($lower, 'ponytail') || preg_match('/\bpony\b/i', $title)) {
        return 'Ponytails / Draw Strings';
    }

    if (str_contains($lower, 'scrunch')) {
        return 'Hair Scrunches';
    }

    if (str_contains($lower, 'head band')) {
        return 'Hair Accessories';
    }

    if (str_contains($lower, 'weave') || str_starts_with($code, 'WA')) {
        return 'Weaves';
    }

    if (str_contains($lower, 'bulk')) {
        return 'Bulk Hair';
    }

    if (Str::contains($lower, ['locs', 'locks', 'dread'])) {
        return 'Crochet Locs';
    }

    if (str_contains($lower, 'crochet') || str_contains($lower, 'looped')) {
        return 'Crochet Hair';
    }

    if (Str::contains($lower, ['braid', 'twist'])) {
        return 'Braiding Hair';
    }

    return 'Hair Extensions';
}

function materialName(string $title, string $code): string
{
    $lower = Str::lower($title);

    if (str_contains($lower, 'brazilian human hair')) {
        return 'Brazilian Human Hair';
    }

    if (str_contains($lower, 'human hair')) {
        return 'Human Hair';
    }

    if (Str::contains($lower, ['synthetic fibre', 'synthetic weave'])) {
        return 'Synthetic Hair';
    }

    if (Str::startsWith($code, ['WZ', 'WE', 'WD', 'WS', 'WH'])) {
        return 'Synthetic Hair';
    }

    return 'Hair';
}

function familyName(string $title): string
{
    $lower = Str::lower($title);

    $special = [
        'clip extension-multi layer' => 'Clip Extension - Multi Layer',
        'clip extension - multi layer' => 'Clip Extension - Multi Layer',
        'clip extension' => 'Clip Extension (Synthetic Fibre - Futura)',
        'brazilian natural' => 'Brazilian Natural',
        'mambo twist' => 'Mambo Twist Braids',
        'avvis' => 'Avvis Super Braids',
        'box braids - looped crochet' => 'Box Braids - Looped Crochet',
        'hair scrunch' => 'Hair Scrunch',
        'head band' => 'Head Band',
    ];

    foreach ($special as $needle => $family) {
        if (str_contains($lower, $needle)) {
            return $family;
        }
    }

    $family = $title;
    $family = preg_replace('/\s*\([^)]*\d+[^)]*\)/i', ' ', $family) ?? $family;
    $family = preg_replace('/\s*\((?:normal|bigger)\s+curl\)/i', ' ', $family) ?? $family;
    $family = preg_replace('/\s*,?\s*\b\d+(?:\.\d+)?\s*"/i', ' ', $family) ?? $family;
    $family = preg_replace('/\s*,?\s*\b\d+(?:\.\d+)?\s*g\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\s*,?\s*\b\d+\s*x\s*\d+\s*pcs\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\s*,?\s*\b\d+\s*(?:pcs?|pieces?|strands?|bundles?)\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\s*,?\s*\b\d+\s*pack\s+solution\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\s*,?\s*x\s*\d+\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\s*,\s*(?:long|short)\s*$/i', ' ', $family) ?? $family;
    $family = preg_replace('/\s+,\s+/', ', ', $family) ?? $family;
    $family = preg_replace('/(?:\s*,\s*){2,}/', ', ', $family) ?? $family;
    $family = preg_replace('/\s*[,;-]\s*$/', '', $family) ?? $family;
    $family = cleanFamilyName($family);
    $family = titleStyle($family);

    return cleanFamilyName($family);
}

function cleanFamilyName(string $family): string
{
    $family = cleanSpaces($family);
    $family = preg_replace('/\s*-\s*,\s*/', ' - ', $family) ?? $family;
    $family = preg_replace('/(?:\s*,\s*){2,}/', ', ', $family) ?? $family;
    $family = preg_replace('/\s*,\s*$/', '', $family) ?? $family;
    $family = preg_replace('/\s*-\s*$/', '', $family) ?? $family;
    $family = preg_replace('/\b(Locs\s*-\s*Crochet)\s+Long$/i', '$1', $family) ?? $family;

    return cleanSpaces($family);
}

/**
 * @return array<string, string>
 */
function titleVariants(string $title): array
{
    $variants = [];

    if (preg_match('/Hair Scrunch\s*\(([^)-]+)\s*-\s*([^)]+)\)/i', $title, $match)) {
        $variants['Curl Pattern'] = titleStyle($match[1]);
        $variants['Size'] = titleStyle($match[2]);
    }

    if (preg_match('/Brazilian Natural\s*-\s*([^,]+)/i', $title, $match)) {
        $variants['Texture'] = cleanTexture($match[1]);
    }

    if (preg_match('/Clip Extension\s*-\s*Multi Layer,\s*([^,(]+)/i', $title, $match)) {
        $variants['Texture'] = cleanTexture($match[1]);
    }

    if (preg_match('/Avvis\s+(Long|Short)\s*-/i', $title, $match)) {
        $variants['Length Label'] = titleStyle($match[1]);
    }

    if (preg_match('/Mambo Twist\s*-\s*Braids[,.]?\s*(long|short)/i', $title, $match)) {
        $variants['Length Label'] = titleStyle($match[1]);
    }

    if (preg_match('/\((normal|bigger)\s+curl\)/i', $title, $match)) {
        $variants['Curl Type'] = titleStyle($match[1].' Curl');
    }

    if (preg_match('/\b(1\s*pack\s+solution)\b/i', $title, $match)) {
        $variants['Pack Format'] = cleanSpaces($match[1]);
    }

    if (preg_match('/\b(\d+\s*in\s*\d+)\b/i', $title, $match)) {
        $variants['Pack Format'] = cleanSpaces($match[1]);
    }

    if (preg_match('/\b(\d+\s*x\s*\d+\s*pcs)\b/i', $title, $match)) {
        $variants['Pack Count'] = normaliseCountValue($match[1]);
    }

    if (preg_match('/\b(\d+)\s*pcs?\b/i', $title, $match)) {
        $variants['Piece Count'] = $match[1].' pcs';
    }

    if (preg_match('/\b(\d+)\s*strands?\b/i', $title, $match)) {
        $variants['Strand Count'] = $match[1].' strands';
    }

    if (preg_match('/\b(\d+)\s*bundles?\b/i', $title, $match)) {
        $variants['Bundle Count'] = $match[1].' bundles';
    }

    if (preg_match('/\b(\d+(?:\.\d+)?)\s*g\b/i', $title, $match)) {
        $variants['Weight'] = $match[1].'g';
    }

    preg_match_all('/(\d+(?:\.\d+)?)\s*"/', $title, $lengthMatches);
    $lengths = collect($lengthMatches[1] ?? [])
        ->map(fn (string $value): string => rtrim(rtrim($value, '0'), '.').'"')
        ->unique()
        ->values();

    if ($lengths->count() === 1) {
        $variants['Length'] = $lengths->first();
    } elseif ($lengths->count() > 1) {
        $variants['Included Lengths'] = $lengths->implode(', ');
    }

    return orderVariants($variants);
}

function cleanTexture(string $texture): string
{
    $texture = cleanSpaces($texture);
    $map = [
        'B. Wave' => 'B. Wave',
        'L. Deep' => 'L. Deep',
    ];

    return $map[$texture] ?? titleStyle($texture);
}

function normaliseCountValue(string $value): string
{
    $value = preg_replace('/\s*x\s*/i', ' x ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return cleanSpaces($value);
}

/**
 * @return array<int, string>
 */
function colourOptions(string $text): array
{
    $text = cleanSpaces($text);
    if ($text === '') {
        return [];
    }

    if (preg_match('/natural\s+black/i', $text)) {
        return ['Natural Black'];
    }

    if (preg_match('/natural\s+colour/i', $text)) {
        return ['Natural Colour'];
    }

    if (preg_match('/T1B\s+base\s*:/i', $text)) {
        preg_match_all('/\+([A-Za-z0-9\/]+)/', $text, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $value): string => normaliseColour('T1B/'.$value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    $text = str_ireplace([
        '(+GBP.50)',
        '(+GBP 0.50)',
        'c o l o urs',
        'c o l o u r s',
        '1 B',
        'T 1 B',
        '9 9 J',
        '3 5 0',
        '9 0 0',
        'B /',
        '/ ',
    ], [
        '',
        '',
        'colours',
        'colours',
        '1B',
        'T1B',
        '99J',
        '350',
        '900',
        'B/',
        '/',
    ], $text);

    $text = preg_replace('/\((?:\d+\s*)?colou?rs?\)/i', '', $text) ?? $text;
    $text = preg_replace('/\b27,613\b/', '27/613', $text) ?? $text;
    $text = preg_replace('/\b(27|30|33|350|613|99J|Grey|Pink|Blue|Purple|Red|Yellow)\s+(?=(?:T?1B|P\d|F\d|[0-9]|99J|Grey|Pink|Blue|Purple|Red|Yellow))/i', '$1, ', $text) ?? $text;
    $text = preg_replace('/(T?1B\/[A-Za-z0-9]+)\s+(?=T?1B\/)/i', '$1, ', $text) ?? $text;
    $text = preg_replace('/(P\d+\/[A-Za-z0-9]+)\s+(?=P\d+\/)/i', '$1, ', $text) ?? $text;

    return collect(preg_split('/\s*,\s*/', $text) ?: [])
        ->map(fn (string $part): string => normaliseColour($part))
        ->filter()
        ->reject(fn (string $part): bool => preg_match('/^colou?rs?$/i', $part) === 1)
        ->unique()
        ->values()
        ->all();
}

function normaliseColour(string $colour): string
{
    $colour = trim($colour, " \t\n\r\0\x0B,.;:");
    $colour = preg_replace('/\s*\/\s*/', '/', $colour) ?? $colour;
    $colour = cleanSpaces($colour);
    $colour = preg_replace('/\s+/', '', $colour) ?? $colour;

    if ($colour === '') {
        return '';
    }

    $upper = Str::upper($colour);
    $named = [
        'GREY' => 'Grey',
        'PINK' => 'Pink',
        'BLUE' => 'Blue',
        'PURPLE' => 'Purple',
        'RED' => 'Red',
        'YELLOW' => 'Yellow',
        'NATURALBLACK' => 'Natural Black',
        'NATURALCOLOUR' => 'Natural Colour',
    ];

    return $named[$upper] ?? $upper;
}

/**
 * @param Collection<int, array<string, mixed>> $groupSkus
 * @return array{0:int,1:int}
 */
function syncStyleVariantsAndSkus(BrandCatalogueStyle $style, Collection $groupSkus): array
{
    $variantNames = $groupSkus
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

        $values = $groupSkus
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

    foreach ($groupSkus->values() as $index => $sourceSku) {
        $variants = orderVariants($sourceSku['variants']);
        $signature = optionSignature($variants);
        $source = $sourceSku['source'];
        $skuName = $sourceSku['sku_name'];

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
            'sku_code' => $source['code'],
            'barcode' => $sku->barcode,
            'description' => $sku->description,
            'note' => mergeNote($sku->note, sourceSkuNote($source)),
            'is_active' => true,
            'sort_order' => $sku->exists ? $sku->sort_order : $index * 10,
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

    return [$created, $updated];
}

/**
 * @param Collection<int, array<string, mixed>> $groupSkus
 */
function styleNote(Collection $groupSkus, string $pdfPath): string
{
    $titles = $groupSkus
        ->pluck('source.title')
        ->unique()
        ->take(6)
        ->implode('; ');

    return 'Reference family imported from TOP Hair Fashion Ltd Product List 25-2 PDF. Source rows: '.$titles.'. Confirm shop stock, exact brand line and final retail images before publishing. Local source PDF: '.$pdfPath.'.';
}

/**
 * @param array<string, mixed> $source
 */
function sourceSkuNote(array $source): string
{
    $note = "TOP Hair Fashion PDF page {$source['page']}, column {$source['column']}; source title: {$source['title']}; item code {$source['code']}; PDF gross unit price GBP {$source['price']}.";

    if (str_contains($source['colours'], '+GBP.50') || str_contains($source['colours'], '+GBP 0.50')) {
        $note .= ' PDF colour note: selected colour has +GBP 0.50 surcharge.';
    }

    if (! empty($source['colour_group'])) {
        $note .= ' Colour group: '.$source['colour_group'].'.';
    }

    return $note;
}

function productTypeSort(string $productType): int
{
    return match ($productType) {
        'Braiding Hair' => 10,
        'Crochet Hair' => 20,
        'Crochet Locs' => 30,
        'Bulk Hair' => 40,
        'Clip Extensions' => 50,
        'Weaves' => 60,
        'Ponytails / Draw Strings' => 70,
        'Wigs' => 80,
        'Lace Wigs' => 90,
        'Hair Scrunches' => 100,
        'Hair Accessories' => 110,
        default => 900,
    };
}

function styleSort(string $family): int
{
    return (int) (crc32(Str::lower($family)) % 10000);
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
    return collect(orderVariants($variants))
        ->map(fn (string $value, string $name): string => $name.':'.$value)
        ->implode('|');
}

/**
 * @param array<string, string> $variants
 */
function skuName(string $family, array $variants): string
{
    $parts = collect(orderVariants($variants))
        ->map(fn (string $value, string $name): string => $name === 'Colour' ? 'Colour '.$value : $value)
        ->values()
        ->all();

    return cleanSpaces($family.($parts === [] ? '' : ' - '.implode(' - ', $parts)));
}

function variantSortOrder(string $name): int
{
    return match ($name) {
        'Texture' => 10,
        'Curl Pattern' => 15,
        'Curl Type' => 16,
        'Length Label' => 20,
        'Length' => 25,
        'Included Lengths' => 26,
        'Size' => 30,
        'Pack Format' => 35,
        'Pack Count' => 40,
        'Piece Count' => 41,
        'Strand Count' => 42,
        'Bundle Count' => 43,
        'Weight' => 50,
        'Colour Group' => 60,
        'Colour' => 70,
        default => 900,
    };
}

function variantType(string $name): string
{
    return match ($name) {
        'Length', 'Included Lengths', 'Weight' => 'measurement',
        'Pack Count', 'Piece Count', 'Strand Count', 'Bundle Count' => 'count',
        'Colour' => 'colour_code',
        default => 'text',
    };
}

function naturalSortKey(string $value): string
{
    if (preg_match('/^\d+(?:\.\d+)?/i', $value, $match)) {
        return str_pad((string) ((float) $match[0] * 100), 10, '0', STR_PAD_LEFT).Str::lower($value);
    }

    return Str::lower($value);
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

function titleStyle(string $value): string
{
    $value = cleanSpaces($value);
    if ($value === '') {
        return '';
    }

    $words = preg_split('/\s+/', Str::lower($value)) ?: [];

    return cleanSpaces(implode(' ', array_map(function (string $word): string {
        if (preg_match('/^(?:3x|4x|2x|99j|t1b|1b|bk|bka|bkx|brd?|bre|brh|bdl?|bdb|brj|bru|wa|we|ww|wd|ws|wh|wz)$/i', $word)) {
            return Str::upper($word);
        }

        return collect(explode('-', $word))
            ->map(fn (string $part): string => Str::ucfirst($part))
            ->implode('-');
    }, $words)));
}
