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
    ?: 'C:\\Users\\Abela\\Desktop\\Khan\\ECHO EW LIST 2023.pdf';

$records = collect(extractPdfRecords($pdfPath))
    ->map(fn (array $record): array => [
        'page' => (int) ($record['page'] ?? 0),
        'row' => (int) ($record['row'] ?? 0),
        'block' => (int) ($record['block'] ?? 0),
        'item' => cleanSpaces((string) ($record['item'] ?? '')),
        'length' => cleanSpaces((string) ($record['length'] ?? '')),
        'colour' => normaliseColour((string) ($record['colour'] ?? '')),
        'qty' => cleanSpaces((string) ($record['qty'] ?? '')),
    ])
    ->filter(fn (array $record): bool => $record['length'] !== '' && $record['colour'] !== '')
    ->unique(fn (array $record): string => $record['length'].'|'.$record['colour'])
    ->values();

$byLength = $records->groupBy('length');

if ($dryRun) {
    echo "Echo Collection European Weave PDF dry run.\n";
    echo "Brand: Echo Collection\n";
    echo "Product type: Weaves\n";
    echo "Family: European Weave\n";
    echo "Material: 100% Human Hair\n";
    echo 'Source rows: '.$records->count()."\n";
    echo 'Length groups: '.$byLength->count()."\n\n";

    $byLength
        ->sortKeysUsing(fn (string $a, string $b): int => lengthNumber($a) <=> lengthNumber($b))
        ->each(function (Collection $lengthRecords, string $length): void {
            echo "{$length}: ".$lengthRecords->count().' colours'."\n";
            echo '  '.$lengthRecords->pluck('colour')->implode(', ')."\n";
        });

    exit(0);
}

$summary = DB::transaction(function () use ($records, $pdfPath): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();

    $brand = BrandCatalogueBrand::query()->firstOrCreate(
        [
            'brand_catalogue_id' => $catalogue->id,
            'slug' => 'echo-collection',
        ],
        [
            'name' => 'Echo Collection',
            'is_active' => true,
            'sort_order' => 70,
        ],
    );

    $brand->fill([
        'name' => 'Echo Collection',
        'note' => mergeNote($brand->note, 'Reference structure imported from ECHO EW LIST 2023 PDF. EW expands to European Weave. Material confirmed as 100% Human Hair.'),
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
                'name' => 'Echo Collection',
            ],
            [
                'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), 'Echo Collection'),
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 0,
            ],
        );
    }

    $line->fill([
        'name' => 'Echo Collection',
        'note' => mergeNote($line->note, 'Default Echo Collection line.'),
        'is_default' => true,
        'is_active' => true,
        'sort_order' => 0,
    ])->save();

    $productType = BrandCatalogueProductType::query()->firstOrCreate(
        [
            'brand_catalogue_line_id' => $line->id,
            'name' => 'Weaves',
        ],
        [
            'brand_catalogue_brand_id' => $brand->id,
            'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), 'Weaves'),
            'is_active' => true,
            'sort_order' => 10,
        ],
    );

    $productType->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'note' => mergeNote($productType->note, 'Echo Collection weave products.'),
        'is_active' => true,
        'sort_order' => 10,
    ])->save();

    $style = BrandCatalogueStyle::query()
        ->where('brand_catalogue_product_type_id', $productType->id)
        ->where('name', 'European Weave')
        ->first();

    if (! $style) {
        $style = new BrandCatalogueStyle([
            'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), 'European Weave'),
        ]);
    }

    $style->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_product_type_id' => $productType->id,
        'brand_catalogue_material_id' => null,
        'material_name' => '100% Human Hair',
        'name' => 'European Weave',
        'note' => mergeNote($style->note, 'European Weave imported from ECHO EW LIST 2023 PDF. Source columns are ITEM, COLOR and QTY; QTY is not product identity. Local source PDF: '.$pdfPath.'.'),
        'is_active' => true,
        'sort_order' => $style->exists ? $style->sort_order : 10,
    ])->save();

    [$created, $updated] = syncVariantsAndSkus($style, $records);

    return [
        'brand_id' => $brand->id,
        'line_id' => $line->id,
        'product_type_id' => $productType->id,
        'style_id' => $style->id,
        'length_groups' => $records->pluck('length')->unique()->count(),
        'source_skus' => $records->count(),
        'skus_created' => $created,
        'skus_updated' => $updated,
        'retail_products_created' => 0,
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
    ];
});

echo "Echo Collection European Weave PDF imported.\n";
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

    $extractor = __DIR__.DIRECTORY_SEPARATOR.'extract_echo_ew_pdf.py';
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

    throw new RuntimeException('Could not extract Echo EW PDF rows. '.$lastError);
}

/**
 * @param Collection<int, array<string, mixed>> $records
 * @return array{0:int,1:int}
 */
function syncVariantsAndSkus(BrandCatalogueStyle $style, Collection $records): array
{
    $lengthVariant = BrandCatalogueVariant::query()->updateOrCreate(
        [
            'brand_catalogue_style_id' => $style->id,
            'name' => 'Length',
        ],
        [
            'variant_type' => 'measurement',
            'sort_order' => 10,
        ],
    );

    $colourVariant = BrandCatalogueVariant::query()->updateOrCreate(
        [
            'brand_catalogue_style_id' => $style->id,
            'name' => 'Colour',
        ],
        [
            'variant_type' => 'colour_code',
            'sort_order' => 20,
        ],
    );

    $lengthOptions = syncOptions(
        $lengthVariant,
        $records
            ->pluck('length')
            ->unique()
            ->sortBy(fn (string $length): int => lengthNumber($length))
            ->values()
            ->all(),
    );

    $colourOptions = syncOptions(
        $colourVariant,
        $records->pluck('colour')->unique()->values()->all(),
    );

    $created = 0;
    $updated = 0;

    foreach ($records as $index => $record) {
        $variants = [
            'Length' => $record['length'],
            'Colour' => $record['colour'],
        ];
        $signature = optionSignature($variants);
        $name = skuName($variants);

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
            'sku_code' => $sku->sku_code,
            'barcode' => $sku->barcode,
            'description' => $sku->description,
            'note' => mergeNote($sku->note, sourceSkuNote($record)),
            'is_active' => true,
            'sort_order' => $sku->exists ? $sku->sort_order : $index * 10,
        ])->save();

        DB::table('brand_catalogue_sku_variant_options')
            ->where('brand_catalogue_sku_id', $sku->id)
            ->delete();

        DB::table('brand_catalogue_sku_variant_options')->insert([
            [
                'brand_catalogue_sku_id' => $sku->id,
                'brand_catalogue_variant_id' => $lengthVariant->id,
                'brand_catalogue_variant_option_id' => $lengthOptions[$record['length']],
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'brand_catalogue_sku_id' => $sku->id,
                'brand_catalogue_variant_id' => $colourVariant->id,
                'brand_catalogue_variant_option_id' => $colourOptions[$record['colour']],
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
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
 * @param array<string, string|int> $record
 */
function sourceSkuNote(array $record): string
{
    $note = "ECHO EW LIST 2023 PDF page {$record['page']}, row {$record['row']}, block {$record['block']}; source item {$record['item']}; colour {$record['colour']}.";

    if ((string) $record['qty'] !== '') {
        $note .= ' Source QTY '.$record['qty'].' retained only as source note.';
    }

    return $note;
}

/**
 * @param array<string, string> $variants
 */
function optionSignature(array $variants): string
{
    return collect($variants)
        ->map(fn (string $value, string $name): string => $name.':'.$value)
        ->implode('|');
}

/**
 * @param array<string, string> $variants
 */
function skuName(array $variants): string
{
    return 'European Weave - '.$variants['Length'].' - Colour '.$variants['Colour'];
}

function lengthNumber(string $length): int
{
    if (preg_match('/\d+/', $length, $match)) {
        return (int) $match[0];
    }

    return 0;
}

function normaliseColour(string $colour): string
{
    return cleanSpaces(Str::upper(trim($colour)));
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
