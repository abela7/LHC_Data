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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = parseArgs($argv);
$sync = array_key_exists('sync', $args);
$writeReport = ! array_key_exists('no-report', $args);

$observedRows = observedPictureRows();

if ($observedRows->isEmpty()) {
    echo "No observed picture products found.\n";
    exit(0);
}

$groups = groupPictureProducts($observedRows);
$existingIndex = loadExistingCatalogueIndex();
$sourceEvidence = loadSourceEvidence($groups);

$result = DB::transaction(function () use ($groups, $existingIndex, $sourceEvidence, $sync): array {
    $summary = [
        'picture_hits' => 0,
        'unique_picture_products' => $groups->count(),
        'matched_existing_skus' => 0,
        'updated_existing_skus' => 0,
        'created_skus' => 0,
        'created_families' => 0,
        'created_brands' => 0,
        'created_product_types' => 0,
        'review_pending' => 0,
    ];
    $reportRows = [];

    foreach ($groups as $group) {
        $summary['picture_hits'] += $group['hit_count'];
        $classification = classifyPictureProduct($group);
        $match = findExistingSkuMatch($group, $classification, $existingIndex);
        $action = 'dry_run';
        $skuId = null;
        $familyId = null;

        if ($match !== null) {
            $summary['matched_existing_skus']++;
            $skuId = $match['sku_id'];
            $familyId = $match['style_id'];
            $action = $sync ? 'matched_existing_sku' : 'would_match_existing_sku';

            if ($sync) {
                $sku = BrandCatalogueSku::query()->find($skuId);
                if ($sku && appendPictureEvidence($sku, $group, $sourceEvidence[$group['group_key']] ?? [])) {
                    $summary['updated_existing_skus']++;
                }
            }
        } else {
            $plan = planMissingSku($group, $classification, $existingIndex);
            $action = $sync ? 'created_or_updated_picture_sku' : 'would_create_picture_sku';

            if ($sync) {
                $created = syncPictureSku($group, $classification, $plan, $sourceEvidence[$group['group_key']] ?? []);
                $skuId = $created['sku_id'];
                $familyId = $created['style_id'];
                $summary['created_skus'] += $created['created_sku'] ? 1 : 0;
                $summary['created_families'] += $created['created_style'] ? 1 : 0;
                $summary['created_brands'] += $created['created_brand'] ? 1 : 0;
                $summary['created_product_types'] += $created['created_product_type'] ? 1 : 0;
            }
        }

        if (($classification['review_pending'] ?? false) === true) {
            $summary['review_pending']++;
        }

        $reportRows[] = [
            'action' => $action,
            'picture_ids' => implode(', ', $group['picture_ids']),
            'hit_count' => $group['hit_count'],
            'brand' => $classification['brand'],
            'observed_product_name' => $group['product_name'],
            'catalogue' => $classification['catalogue_name'],
            'product_type' => $classification['product_type'],
            'family_name' => $match['style_name'] ?? ($plan['style_name'] ?? null) ?? $classification['style_name'],
            'sku_name' => $match['sku_name'] ?? $classification['sku_name'],
            'variant_axes' => optionSignatureFromAxes($classification['axes']),
            'sku_id' => $skuId,
            'family_id' => $familyId,
            'match_reason' => $match['reason'] ?? ($classification['review_pending'] ? 'review_pending' : 'new_picture_product'),
            'pdf_matches' => implode(' | ', array_slice($sourceEvidence[$group['group_key']]['pdf_matches'] ?? [], 0, 3)),
            'mamado_matches' => implode(' | ', array_slice($sourceEvidence[$group['group_key']]['mamado_matches'] ?? [], 0, 3)),
            'janson_matches' => implode(' | ', array_slice($sourceEvidence[$group['group_key']]['janson_matches'] ?? [], 0, 3)),
        ];
    }

    if (! $sync) {
        DB::rollBack();
    }

    return [$summary, $reportRows];
});

[$summary, $reportRows] = $result;
$reportPath = null;

if ($writeReport) {
    $reportPath = writeReport($reportRows, $sync);
}

echo ($sync ? "Picture products synced into central catalogue.\n" : "Picture products dry run.\n");
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}
if ($reportPath !== null) {
    echo "report: {$reportPath}\n";
}
if (! $sync) {
    echo "Run with --sync to write the missing/updated catalogue SKU candidates.\n";
}

/**
 * @param array<int, string> $argv
 * @return array<string, string|bool>
 */
function parseArgs(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$key, $value] = explode('=', $arg, 2);
                $args[$key] = $value;
            } else {
                $args[$arg] = true;
            }
        }
    }

    return $args;
}

/**
 * @return Collection<int, object>
 */
function observedPictureRows(): Collection
{
    return DB::table('observed_products as op')
        ->leftJoin('categories as c', 'c.id', '=', 'op.category_id')
        ->select([
            'op.picture_id',
            'op.sort_order',
            'op.brand',
            'op.canonical_brand',
            'op.brand_line',
            'op.product_name',
            'c.slug as category_slug',
        ])
        ->where('op.product_name', '!=', '')
        ->orderBy('op.picture_id')
        ->orderBy('op.sort_order')
        ->get();
}

/**
 * @param Collection<int, object> $rows
 * @return Collection<int, array<string, mixed>>
 */
function groupPictureProducts(Collection $rows): Collection
{
    return $rows
        ->map(function (object $row): array {
            $brand = cleanName((string) ($row->canonical_brand ?: $row->brand ?: 'Unknown'));
            $line = cleanName((string) ($row->brand_line ?: ''));
            $productName = cleanName((string) $row->product_name);
            $key = normalizeKey($brand.'|'.$line.'|'.$productName);

            return [
                'group_key' => $key,
                'brand' => $brand,
                'line' => $line,
                'product_name' => $productName,
                'picture_id' => (string) $row->picture_id,
                'category_slug' => (string) ($row->category_slug ?? ''),
            ];
        })
        ->groupBy('group_key')
        ->map(function (Collection $items, string $key): array {
            $first = $items->first();
            $categoryCounts = $items->pluck('category_slug')->filter()->countBy()->sortDesc();

            return [
                'group_key' => $key,
                'brand' => $first['brand'],
                'line' => $first['line'],
                'product_name' => $first['product_name'],
                'category_slug' => (string) ($categoryCounts->keys()->first() ?? ''),
                'picture_ids' => $items->pluck('picture_id')->unique()->sort()->values()->all(),
                'hit_count' => $items->count(),
            ];
        })
        ->values();
}

/**
 * @return array<string, list<array<string, mixed>>>
 */
function loadExistingCatalogueIndex(): array
{
    $rows = DB::table('brand_catalogues as c')
        ->join('brand_catalogue_brands as b', 'b.brand_catalogue_id', '=', 'c.id')
        ->join('brand_catalogue_lines as l', 'l.brand_catalogue_brand_id', '=', 'b.id')
        ->join('brand_catalogue_product_types as pt', 'pt.brand_catalogue_line_id', '=', 'l.id')
        ->join('brand_catalogue_styles as s', 's.brand_catalogue_product_type_id', '=', 'pt.id')
        ->leftJoin('brand_catalogue_skus as sku', 'sku.brand_catalogue_style_id', '=', 's.id')
        ->select([
            'c.id as catalogue_id',
            'c.name as catalogue_name',
            'c.slug as catalogue_slug',
            'b.id as brand_id',
            'b.name as brand_name',
            'l.id as line_id',
            'l.name as line_name',
            'pt.id as product_type_id',
            'pt.name as product_type_name',
            's.id as style_id',
            's.name as style_name',
            'sku.id as sku_id',
            'sku.name as sku_name',
            'sku.option_signature',
        ])
        ->orderBy('c.id')
        ->orderBy('b.name')
        ->orderBy('s.name')
        ->get();

    $index = [];
    foreach ($rows as $row) {
        $key = indexKey((string) $row->catalogue_slug, (string) $row->brand_name);
        $index[$key][] = [
            'catalogue_id' => (int) $row->catalogue_id,
            'catalogue_name' => (string) $row->catalogue_name,
            'catalogue_slug' => (string) $row->catalogue_slug,
            'brand_id' => (int) $row->brand_id,
            'brand_name' => (string) $row->brand_name,
            'line_id' => (int) $row->line_id,
            'line_name' => (string) $row->line_name,
            'product_type_id' => (int) $row->product_type_id,
            'product_type_name' => (string) $row->product_type_name,
            'style_id' => (int) $row->style_id,
            'style_name' => (string) $row->style_name,
            'style_norm' => normalizeProductName((string) $row->style_name),
            'style_core_norm' => normalizeProductName(removeBrandPrefix((string) $row->style_name, (string) $row->brand_name)),
            'sku_id' => $row->sku_id ? (int) $row->sku_id : null,
            'sku_name' => $row->sku_name ? (string) $row->sku_name : null,
            'sku_norm' => normalizeProductName((string) ($row->sku_name ?? '')),
            'sku_core_norm' => normalizeProductName(removeBrandPrefix((string) ($row->sku_name ?? ''), (string) $row->brand_name)),
            'option_signature' => (string) ($row->option_signature ?? ''),
        ];
    }

    return $index;
}

/**
 * @param Collection<int, array<string, mixed>> $groups
 * @return array<string, array<string, list<string>>>
 */
function loadSourceEvidence(Collection $groups): array
{
    $evidence = [];
    $pdfRows = DB::table('pdf_catalogue_products')
        ->select('source_name', 'page_number', 'brand', 'product_name', 'product_code')
        ->get();

    $mamadoRows = DB::table('mamado_products')
        ->select('item_code', 'item_description', 'source_order_number', 'source_order_date', 'brand_label')
        ->get();

    $jansonRows = Schema::hasTable('janson_products')
        ? DB::table('janson_products')
            ->select('id', 'code', 'name', 'category', 'page', 'page_row', 'review_flags')
            ->get()
        : collect();

    $pdfIndex = buildSourceIndex($pdfRows, 'product_name', 'brand');
    $mamadoIndex = buildSourceIndex($mamadoRows, 'item_description', 'brand_label');
    $jansonIndex = buildSourceIndex($jansonRows, 'name', 'category');

    foreach ($groups as $group) {
        $groupKey = $group['group_key'];
        $productNorm = normalizeProductName($group['product_name']);
        $brandNorm = normalizeProductName($group['brand']);
        $evidence[$groupKey] = ['pdf_matches' => [], 'mamado_matches' => [], 'janson_matches' => []];

        foreach (candidateSourceRows($pdfIndex, $productNorm, $brandNorm) as $row) {
            if (! safeSourceEvidenceMatch($productNorm, $row['product_norm'], $brandNorm, $row['brand_norm'])) {
                continue;
            }

            $source = $row['raw'];
            $evidence[$groupKey]['pdf_matches'][] = "{$source->source_name} p{$source->page_number}: {$source->brand} - {$source->product_name}".($source->product_code ? " ({$source->product_code})" : '');
        }

        foreach (candidateSourceRows($mamadoIndex, $productNorm, $brandNorm) as $row) {
            if (! safeSourceEvidenceMatch($productNorm, $row['product_norm'], $brandNorm, $row['brand_norm'])) {
                continue;
            }

            $source = $row['raw'];
            $evidence[$groupKey]['mamado_matches'][] = "order {$source->source_order_number} {$source->source_order_date}: {$source->item_description}".($source->item_code ? " ({$source->item_code})" : '');
        }

        foreach (candidateSourceRows($jansonIndex, $productNorm, $brandNorm) as $row) {
            if (! safeSourceEvidenceMatch($productNorm, $row['product_norm'], $brandNorm, $row['brand_norm'])) {
                continue;
            }

            $source = $row['raw'];
            $flags = json_decode((string) ($source->review_flags ?? '[]'), true);
            $flagText = is_array($flags) && $flags !== [] ? ' review flags: '.implode(',', array_slice($flags, 0, 2)) : '';
            $evidence[$groupKey]['janson_matches'][] = "Janson p{$source->page} r{$source->page_row}: {$source->category} - {$source->name}".($source->code ? " ({$source->code})" : '').$flagText;
        }

        $evidence[$groupKey]['pdf_matches'] = array_values(array_unique(array_slice($evidence[$groupKey]['pdf_matches'], 0, 8)));
        $evidence[$groupKey]['mamado_matches'] = array_values(array_unique(array_slice($evidence[$groupKey]['mamado_matches'], 0, 8)));
        $evidence[$groupKey]['janson_matches'] = array_values(array_unique(array_slice($evidence[$groupKey]['janson_matches'], 0, 8)));
    }

    return $evidence;
}

/**
 * @param Collection<int, object> $rows
 * @return array{rows: array<int, array<string, mixed>>, token_index: array<string, array<int, true>>, brand_index: array<string, array<int, true>>}
 */
function buildSourceIndex(Collection $rows, string $productField, string $brandField): array
{
    $preparedRows = [];
    $tokenIndex = [];
    $brandIndex = [];

    foreach ($rows->values() as $index => $row) {
        $productNorm = normalizeProductName((string) ($row->{$productField} ?? ''));
        $brandNorm = normalizeProductName((string) ($row->{$brandField} ?? ''));
        $preparedRows[$index] = [
            'raw' => $row,
            'product_norm' => $productNorm,
            'brand_norm' => $brandNorm,
        ];

        foreach (significantTokens($productNorm) as $token) {
            $tokenIndex[$token][$index] = true;
        }
        if ($brandNorm !== '') {
            $brandIndex[$brandNorm][$index] = true;
        }
    }

    return [
        'rows' => $preparedRows,
        'token_index' => $tokenIndex,
        'brand_index' => $brandIndex,
    ];
}

/**
 * @param array{rows: array<int, array<string, mixed>>, token_index: array<string, array<int, true>>, brand_index: array<string, array<int, true>>} $index
 * @return list<array<string, mixed>>
 */
function candidateSourceRows(array $index, string $productNorm, string $brandNorm): array
{
    $candidateIds = [];

    foreach (significantTokens($productNorm) as $token) {
        foreach ($index['token_index'][$token] ?? [] as $id => $_) {
            $candidateIds[$id] = true;
        }
    }

    if ($brandNorm !== '') {
        foreach ($index['brand_index'][$brandNorm] ?? [] as $id => $_) {
            $candidateIds[$id] = true;
        }
    }

    $rows = [];
    foreach (array_keys($candidateIds) as $id) {
        $rows[] = $index['rows'][$id];
    }

    return $rows;
}

/**
 * @param array<string, mixed> $group
 * @return array<string, mixed>
 */
function classifyPictureProduct(array $group): array
{
    $brand = cleanName((string) ($group['brand'] ?: 'Unknown'));
    $line = cleanName((string) ($group['line'] ?: $brand));
    $productName = cleanName((string) $group['product_name']);
    $categorySlug = (string) ($group['category_slug'] ?? '');
    $productType = resolveProductType($productName, $brand, $categorySlug);
    $catalogueName = resolveCatalogueName($productName, $brand, $productType, $categorySlug);
    $catalogueSlug = catalogueSlug($catalogueName);
    $axes = extractAxes($productName, $productType, $catalogueSlug);
    $styleName = buildStyleName($productName, $brand, $line, $productType, $catalogueSlug, $axes);
    $variant = extractProductVariant($productName, $styleName, $productType, $axes, $brand);

    if ($variant !== '' && ! isset($axes['Product Variant']) && ! isset($axes['Shade'])) {
        $axis = $productType === 'Hair Colour / Dye' ? 'Shade' : 'Product Variant';
        $axes[$axis] = $variant;
    }

    return [
        'brand' => $brand,
        'line' => $line,
        'product_type' => $productType,
        'catalogue_name' => $catalogueName,
        'catalogue_slug' => $catalogueSlug,
        'style_name' => $styleName,
        'sku_name' => buildSkuName($brand, $productName, $catalogueSlug),
        'axes' => dedupeAxes($axes),
        'review_pending' => $brand === 'Unknown' || trim($productName) === '',
    ];
}

/**
 * @param array<string, mixed> $group
 * @param array<string, mixed> $classification
 * @param array<string, list<array<string, mixed>>> $existingIndex
 * @return array<string, mixed>|null
 */
function findExistingSkuMatch(array $group, array $classification, array $existingIndex): ?array
{
    $rows = $existingIndex[indexKey($classification['catalogue_slug'], $classification['brand'])] ?? [];
    if ($rows === []) {
        return null;
    }

    $productNorm = normalizeProductName($group['product_name']);
    $fullNorm = normalizeProductName($classification['brand'].' '.$group['product_name']);
    $measurelessProductNorm = stripMeasuresFromNorm($productNorm);
    $measurelessFullNorm = stripMeasuresFromNorm($fullNorm);

    foreach ($rows as $row) {
        if (! $row['sku_id']) {
            continue;
        }

        if (in_array($row['sku_norm'], [$productNorm, $fullNorm], true)
            || in_array($row['sku_core_norm'], [$productNorm, $measurelessProductNorm], true)
            || ($row['sku_norm'] !== '' && stripMeasuresFromNorm($row['sku_norm']) === $measurelessFullNorm)
        ) {
            return $row + ['reason' => 'sku_exact_or_measureless'];
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $group
 * @param array<string, mixed> $classification
 * @param array<string, list<array<string, mixed>>> $existingIndex
 * @return array<string, mixed>
 */
function planMissingSku(array $group, array $classification, array $existingIndex): array
{
    $rows = $existingIndex[indexKey($classification['catalogue_slug'], $classification['brand'])] ?? [];
    $style = chooseExistingStyle($rows, $group, $classification);

    return [
        'style_id' => $style['style_id'] ?? null,
        'style_name' => $style['style_name'] ?? $classification['style_name'],
        'reason' => $style ? 'existing_family' : 'new_family',
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array<string, mixed> $group
 * @param array<string, mixed> $classification
 * @return array<string, mixed>|null
 */
function chooseExistingStyle(array $rows, array $group, array $classification): ?array
{
    if ($rows === []) {
        return null;
    }

    $productNorm = normalizeProductName($group['product_name']);
    $styleTargetNorm = normalizeProductName($classification['style_name']);
    $productType = normalizeProductName($classification['product_type']);

    foreach ($rows as $row) {
        if ($row['style_norm'] === $styleTargetNorm || $row['style_core_norm'] === $styleTargetNorm) {
            return $row;
        }
    }

    foreach ($rows as $row) {
        if ($row['style_core_norm'] !== '' && str_contains($productNorm, $row['style_core_norm']) && strlen($row['style_core_norm']) > 8) {
            return $row;
        }
    }

    $sameTypeRows = array_values(array_filter(
        $rows,
        fn (array $row): bool => normalizeProductName((string) $row['product_type_name']) === $productType
    ));

    $styleIds = [];
    foreach ($sameTypeRows as $row) {
        $styleIds[$row['style_id']] = $row;
    }

    if (count($styleIds) === 1) {
        return array_values($styleIds)[0];
    }

    return null;
}

/**
 * @param array<string, mixed> $group
 * @param array<string, mixed> $classification
 * @param array<string, mixed> $plan
 * @param array<string, list<string>> $sourceEvidence
 * @return array<string, mixed>
 */
function syncPictureSku(array $group, array $classification, array $plan, array $sourceEvidence): array
{
    $catalogue = firstOrCreateCatalogue($classification['catalogue_name'], $classification['catalogue_slug']);
    $brandResult = firstOrCreateBrand($catalogue, $classification['brand']);
    $brand = $brandResult['brand'];
    $line = firstOrCreateLine($brand, $classification['line']);
    $productTypeResult = firstOrCreateProductType($brand, $line, $classification['product_type']);
    $productType = $productTypeResult['product_type'];

    $style = null;
    $createdStyle = false;
    if ($plan['style_id']) {
        $style = BrandCatalogueStyle::query()->find((int) $plan['style_id']);
    }

    if (! $style) {
        $style = BrandCatalogueStyle::query()
            ->where('brand_catalogue_product_type_id', $productType->id)
            ->where('name', $plan['style_name'])
            ->first();
    }

    if (! $style) {
        $style = new BrandCatalogueStyle([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $productType->id,
            'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $plan['style_name']),
        ]);
        $createdStyle = true;
    }

    $style->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_product_type_id' => $productType->id,
        'brand_catalogue_material_id' => null,
        'material_name' => materialForCatalogue($classification['catalogue_slug']),
        'name' => $plan['style_name'],
        'note' => appendText(
            (string) $style->note,
            'Shop picture confirmed family. Review customer description, image, barcode, live stock and price before ecommerce activation.'
        ),
        'is_active' => true,
        'sort_order' => styleSort($plan['style_name']),
    ])->save();

    $axes = $classification['axes'];
    $signature = optionSignatureFromAxes($axes);
    if ($signature === '' && BrandCatalogueSku::query()->where('brand_catalogue_style_id', $style->id)->where('option_signature', '')->exists()) {
        $axes['Shop Product'] = $group['product_name'];
        $signature = optionSignatureFromAxes($axes);
    }

    $sku = BrandCatalogueSku::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->where('option_signature', $signature)
        ->first();

    $createdSku = false;
    if (! $sku) {
        $sku = new BrandCatalogueSku([
            'brand_catalogue_style_id' => $style->id,
            'slug' => scopedSlug(BrandCatalogueSku::query()->where('brand_catalogue_style_id', $style->id), $classification['sku_name']),
        ]);
        $createdSku = true;
    }

    $sku->fill([
        'name' => $classification['sku_name'],
        'sku_code' => $sku->sku_code,
        'barcode' => $sku->barcode,
        'option_signature' => $signature,
        'description' => $sku->description,
        'note' => skuPictureNote((string) $sku->note, $group, $sourceEvidence),
        'url' => $sku->url,
        'is_active' => true,
        'sort_order' => (int) $sku->sort_order ?: 5000,
    ])->save();

    syncSkuOptions($sku, $axes);

    return [
        'sku_id' => $sku->id,
        'style_id' => $style->id,
        'created_sku' => $createdSku,
        'created_style' => $createdStyle,
        'created_brand' => $brandResult['created'],
        'created_product_type' => $productTypeResult['created'],
    ];
}

/**
 * @param array<string, mixed> $group
 * @param array<string, list<string>> $sourceEvidence
 */
function appendPictureEvidence(BrandCatalogueSku $sku, array $group, array $sourceEvidence): bool
{
    $before = (string) $sku->note;
    $sku->note = skuPictureNote($before, $group, $sourceEvidence);
    if ($sku->note === $before) {
        return false;
    }

    $sku->save();

    return true;
}

function firstOrCreateCatalogue(string $name, string $slug): BrandCatalogue
{
    return BrandCatalogue::query()->firstOrCreate(
        ['slug' => $slug],
        [
            'name' => $name,
            'note' => "Structured product workspace for {$name}.",
            'is_active' => true,
            'sort_order' => catalogueSort($slug),
        ],
    );
}

/**
 * @return array{brand: BrandCatalogueBrand, created: bool}
 */
function firstOrCreateBrand(BrandCatalogue $catalogue, string $brandName): array
{
    $brand = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where('name', $brandName)
        ->first();

    if ($brand) {
        return ['brand' => $brand, 'created' => false];
    }

    return [
        'brand' => BrandCatalogueBrand::query()->create([
            'brand_catalogue_id' => $catalogue->id,
            'name' => $brandName,
            'slug' => scopedSlug(BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id), $brandName),
            'note' => 'Created from shop picture evidence.',
            'is_active' => true,
            'sort_order' => brandSort($brandName),
        ]),
        'created' => true,
    ];
}

function firstOrCreateLine(BrandCatalogueBrand $brand, string $lineName): BrandCatalogueLine
{
    $lineName = $lineName !== '' ? $lineName : $brand->name;
    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', $lineName)
        ->first();

    if ($line) {
        return $line;
    }

    return BrandCatalogueLine::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'name' => $lineName,
        'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), $lineName),
        'note' => 'Created from shop picture evidence.',
        'url' => null,
        'is_default' => sameText($lineName, $brand->name),
        'is_active' => true,
        'sort_order' => lineSort($lineName),
    ]);
}

/**
 * @return array{product_type: BrandCatalogueProductType, created: bool}
 */
function firstOrCreateProductType(BrandCatalogueBrand $brand, BrandCatalogueLine $line, string $productTypeName): array
{
    $productType = BrandCatalogueProductType::query()
        ->where('brand_catalogue_line_id', $line->id)
        ->where('name', $productTypeName)
        ->first();

    if ($productType) {
        return ['product_type' => $productType, 'created' => false];
    }

    return [
        'product_type' => BrandCatalogueProductType::query()->create([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_line_id' => $line->id,
            'name' => $productTypeName,
            'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $productTypeName),
            'note' => 'Created from shop picture evidence.',
            'url' => null,
            'is_active' => true,
            'sort_order' => productTypeSort($productTypeName),
        ]),
        'created' => true,
    ];
}

/**
 * @param array<string, string> $axes
 */
function syncSkuOptions(BrandCatalogueSku $sku, array $axes): void
{
    DB::table('brand_catalogue_sku_variant_options')->where('brand_catalogue_sku_id', $sku->id)->delete();

    $sort = 10;
    foreach ($axes as $axis => $value) {
        if ($value === '') {
            continue;
        }

        $variant = BrandCatalogueVariant::query()->updateOrCreate(
            [
                'brand_catalogue_style_id' => $sku->brand_catalogue_style_id,
                'name' => $axis,
            ],
            [
                'variant_type' => variantTypeForAxis($axis),
                'sort_order' => $sort,
            ],
        );

        $option = BrandCatalogueVariantOption::query()->updateOrCreate(
            [
                'variant_id' => $variant->id,
                'label' => $value,
            ],
            [
                'value' => $value,
                'sort_order' => optionSort($value),
            ],
        );

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

        $sort += 10;
    }
}

function resolveCatalogueName(string $productName, string $brand, string $productType, string $categorySlug): string
{
    $text = Str::lower($productName.' '.$brand.' '.$productType);

    if ($categorySlug === 'hair' || containsAny($text, ['braid', 'weave', 'wig', 'bulk', 'clip-in', 'clip in', 'locs', 'crochet', 'ponytail', 'drawstring', 'closure', 'frontal', 'extension'])) {
        return 'Hair Extensions';
    }
    if (containsAny($text, ['dryer', 'straightener', 'trimmer', 'clipper', 'shaver', 'curling tong', 'blade'])) {
        return 'Electrical';
    }
    if (containsAny($text, ['comb', 'brush', 'wig head', 'applicator', 'spray bottle', 'tweezer', 'razor', 'rubber band', 'bobby pin'])) {
        return 'Accessories';
    }
    if (containsAny($text, ['lipstick', 'lip gloss', 'powder', 'nail polish', 'cosmetic', 'makeup', 'foundation', 'concealer', 'eyeliner', 'mascara'])) {
        return 'Makeup';
    }
    if (containsAny($text, ['cologne', 'aftershave', 'bayrum', 'florida water', 'eau de', 'fragrance', 'perfume'])) {
        return 'Fragrances';
    }
    if (containsAny($text, ['shampoo', 'conditioner', 'leave-in', 'leave in', 'relaxer', 'texturizer', 'hair oil', 'edge', 'mousse', 'spritz', 'hair color', 'hair colour', 'curl activator'])) {
        return 'Hair Products';
    }

    return 'Skin Care';
}

function resolveProductType(string $productName, string $brand, string $categorySlug): string
{
    $text = Str::lower($productName.' '.$brand);

    if ($categorySlug === 'hair' || containsAny($text, ['braid', 'weave', 'wig', 'bulk', 'clip-in', 'clip in', 'locs', 'crochet', 'ponytail', 'drawstring', 'closure', 'frontal', 'extension'])) {
        if (containsAny($text, ['lace wig', 'lace front'])) return 'Lace Wigs';
        if (containsAny($text, ['wig'])) return 'Wigs';
        if (containsAny($text, ['ponytail', 'drawstring'])) return 'Ponytails';
        if (containsAny($text, ['clip-in', 'clip in'])) return 'Clip In Hair Extensions';
        if (containsAny($text, ['weave', 'weft'])) return 'Weaves';
        if (containsAny($text, ['bulk'])) return 'Bulk Hair';
        if (containsAny($text, ['closure', 'frontal'])) return 'Closures / Frontals';
        if (containsAny($text, ['puff', 'bun'])) return 'Hair Puffs / Hair Pieces';

        return 'Braiding Hair';
    }

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
    if (containsAny($text, ['foundation', 'concealer', 'powder', 'cosmetic', 'makeup', 'eyeliner', 'mascara'])) return 'Cosmetic';
    if (containsAny($text, ['cologne', 'aftershave', 'bayrum', 'florida water', 'eau de', 'perfume'])) return 'Cologne / Aftershave';
    if (containsAny($text, ['hair colour spray'])) return 'Hair Colour Spray';
    if (containsAny($text, ['hair color', 'hair colour', 'dye', 'henna', 'semi permanent', 'bigen'])) return 'Hair Colour / Dye';
    if (containsAny($text, ['peroxide', 'bleach powder'])) return 'Developer / Bleach';
    if (containsAny($text, ['relaxer'])) return 'Relaxer';
    if (containsAny($text, ['texturizer'])) return 'Texturizer';
    if (containsAny($text, ['shampoo'])) return 'Shampoo';
    if (containsAny($text, ['conditioner', 'cond '])) return containsAny($text, ['leave in', 'leave-in']) ? 'Leave-In Conditioner' : 'Conditioner';
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
    if (containsAny($text, ['sanitizer', 'sanitiser', 'rubbing alcohol', 'antiseptic'])) return 'Health / Hygiene';
    if (containsAny($text, ['shave', 'shaving', 'bump'])) return 'Shaving / Bump Care';
    if (containsAny($text, ['body oil', 'pure oil', 'vitamin e oil', 'rose water'])) return 'Body Oil';
    if (containsAny($text, ['cream', 'creme'])) return containsAny($text, ['hair', 'curl', 'scalp']) ? 'Hair Cream' : 'Skin Cream';
    if (containsAny($text, ['gel'])) return containsAny($text, ['hair', 'edge', 'styling']) ? 'Styling Gel' : 'Skin Gel';
    if (containsAny($text, ['oil'])) return containsAny($text, ['hair', 'scalp']) ? 'Hair Oil' : 'Body Oil';

    return 'General Product';
}

/**
 * @param array<string, string> $axes
 */
function buildStyleName(string $productName, string $brand, string $line, string $productType, string $catalogueSlug, array $axes): string
{
    $base = removeMeasuresFromText($productName);
    $base = removePackFromText($base);

    if ($catalogueSlug === 'hair-extensions') {
        return cleanName(removeBrandPrefix($base, $brand)) ?: $productName;
    }

    $variant = extractProductVariant($productName, $base, $productType, $axes, $brand);
    if ($variant !== '') {
        $base = preg_replace('/\b'.preg_quote($variant, '/').'\b$/i', '', $base) ?? $base;
    }

    $base = cleanName($base);
    if ($base === '' || sameText($base, $productType)) {
        $base = $productType;
    }

    $prefix = $brand;
    if ($line !== '' && ! sameText($line, $brand) && ! str_contains(Str::lower($base), Str::lower($line))) {
        $prefix .= ' '.$line;
    }

    if (str_starts_with(Str::lower($base), Str::lower($brand))) {
        return cleanName($base);
    }

    return cleanName($prefix.' '.$base);
}

function buildSkuName(string $brand, string $productName, string $catalogueSlug): string
{
    if ($catalogueSlug === 'hair-extensions') {
        return cleanName($productName);
    }

    if (str_starts_with(Str::lower($productName), Str::lower($brand))) {
        return cleanName($productName);
    }

    return cleanName($brand.' '.$productName);
}

/**
 * @return array<string, string>
 */
function extractAxes(string $productName, string $productType, string $catalogueSlug): array
{
    $axes = [];

    if ($catalogueSlug === 'hair-extensions') {
        $length = extractLength($productName);
        if ($length !== '') {
            $axes['Length'] = $length;
        }
    }

    $size = extractSize($productName);
    if ($size !== '') {
        $axes['Size'] = $size;
    }

    $strength = extractStrength($productName);
    if ($strength !== '') {
        $axes['Strength'] = $strength;
    }

    $pack = extractPack($productName);
    if ($pack !== '') {
        $axes['Pack'] = $pack;
    }

    return $axes;
}

/**
 * @param array<string, string> $axes
 */
function extractProductVariant(string $productName, string $styleName, string $productType, array $axes, string $brand): string
{
    $value = ' '.$productName.' ';

    foreach (array_filter([$brand, $styleName, $productType, ...array_values($axes)]) as $remove) {
        $value = preg_replace('/\b'.preg_quote($remove, '/').'\b/i', ' ', $value) ?? $value;
    }

    $removeWords = [
        'body', 'skin', 'hair', 'face', 'facial', 'jar', 'tube', 'pump', 'bottle', 'cream', 'creme',
        'lotion', 'soap', 'oil', 'shampoo', 'conditioner', 'cond', 'leave in', 'leave-in', 'gel',
        'wax', 'spray', 'mousse', 'foam', 'mask', 'masque', 'treatment', 'relaxer', 'texturizer',
        'colour', 'color', 'dye', 'henna', 'powder', 'serum', 'scrub', 'cleanser', 'tonic', 'toner',
        'braid', 'weave', 'wig', 'bulk', 'clip', 'extensions', 'extension', 'with', 'for',
    ];

    foreach ($removeWords as $word) {
        $value = preg_replace('/\b'.preg_quote($word, '/').'\b/i', ' ', $value) ?? $value;
    }

    $value = removeMeasuresFromText($value);
    $value = removePackFromText($value);
    $value = cleanName(trim($value, " \t\n\r\0\x0B-+/(),.\"'"));

    if ($value === '' || Str::length($value) < 2 || sameText($value, $productName)) {
        return '';
    }

    return Str::limit($value, 80, '');
}

function extractLength(string $value): string
{
    preg_match_all('/\b\d{1,2}(?:\/\d{1,2})*(?:\s?(?:inch|inches|in|"))\b/i', $value, $matches);

    return collect($matches[0] ?? [])
        ->map(fn (string $match): string => normalizeLength($match))
        ->unique()
        ->implode(' + ');
}

function extractSize(string $value): string
{
    preg_match_all('/\b\d+(?:\.\d+)?\s?(?:ml|mL|ML|l|L|litres?|oz|OZ|g|gm|gr|kg|KG|lb|LB|pcs|Pcs|pc|sachets?|applications?|app)\b/u', $value, $matches);

    return collect($matches[0] ?? [])
        ->map(fn (string $match): string => normalizeMeasure($match))
        ->unique()
        ->implode(' + ');
}

function extractPack(string $value): string
{
    $packs = [];
    if (preg_match_all('/\b\d+\s?x\b/i', $value, $matches)) {
        foreach ($matches[0] as $match) {
            $packs[] = strtoupper(str_replace(' ', '', $match));
        }
    }
    if (preg_match('/\b(?:single|twin|double|mega pack|value pack|eazi-pack|pack)\b/i', $value, $match)) {
        $packs[] = Str::title($match[0]);
    }

    return collect($packs)->unique()->implode(' + ');
}

function extractStrength(string $value): string
{
    if (preg_match('/\b(?:regular|normal|mild|super|extra|sensitive|coarse)\b/i', $value, $match)) {
        return Str::title($match[0]);
    }

    if (preg_match('/\b(?:10|20|30|40)\s?(?:vol|volume)\b/i', $value, $match)) {
        return strtoupper(str_replace(' ', '', $match[0]));
    }

    return '';
}

function removeMeasuresFromText(string $value): string
{
    $value = preg_replace('/\b\d{1,2}(?:\/\d{1,2})*(?:\s?(?:inch|inches|in|"))\b/i', ' ', $value) ?? $value;
    $value = preg_replace('/\b\d+(?:\.\d+)?\s?(?:ml|mL|ML|l|L|litres?|oz|OZ|g|gm|gr|kg|KG|lb|LB|pcs|Pcs|pc|sachets?|applications?|app)\b/u', ' ', $value) ?? $value;

    return cleanName($value);
}

function removePackFromText(string $value): string
{
    $value = preg_replace('/\b\d+\s?x\b/i', ' ', $value) ?? $value;
    $value = preg_replace('/\b(?:single|twin|double|mega pack|value pack|eazi-pack)\b/i', ' ', $value) ?? $value;

    return cleanName($value);
}

function normalizeMeasure(string $value): string
{
    $value = preg_replace('/\s+/', '', trim($value)) ?? $value;
    $value = str_ireplace(['LITRES', 'LITRE'], 'L', $value);
    $value = str_ireplace(['PCS', 'PC'], 'pcs', $value);

    return str_ireplace(['ML', 'Mls', 'MLS'], 'ml', $value);
}

function normalizeLength(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s*(?:inch|inches|in)$/i', '"', $value) ?? $value;
    $value = preg_replace('/\s+/', '', $value) ?? $value;

    return $value;
}

/**
 * @param array<string, string> $axes
 * @return array<string, string>
 */
function dedupeAxes(array $axes): array
{
    $clean = [];
    foreach ($axes as $axis => $value) {
        $value = cleanName((string) $value);
        if ($value !== '') {
            $clean[$axis] = $value;
        }
    }

    return $clean;
}

/**
 * @param array<string, string> $axes
 */
function optionSignatureFromAxes(array $axes): string
{
    ksort($axes);

    return collect($axes)
        ->filter(fn (string $value): bool => $value !== '')
        ->map(fn (string $value, string $axis): string => Str::slug($axis).':'.Str::slug($value))
        ->implode('|');
}

/**
 * @param array<string, mixed> $group
 * @param array<string, list<string>> $sourceEvidence
 */
function skuPictureNote(string $existingNote, array $group, array $sourceEvidence): string
{
    $note = $existingNote;
    $pictureText = 'Shop picture evidence: '.implode(', ', array_slice($group['picture_ids'], 0, 12));
    if (count($group['picture_ids']) > 12) {
        $pictureText .= ' +'.(count($group['picture_ids']) - 12).' more';
    }
    $pictureText .= "; observed as {$group['brand']} - {$group['product_name']}.";
    $note = appendText($note, $pictureText);

    if (! empty($sourceEvidence['pdf_matches'])) {
        $note = appendText($note, 'PDF staging match: '.implode(' | ', array_slice($sourceEvidence['pdf_matches'], 0, 3)).'.');
    }
    if (! empty($sourceEvidence['mamado_matches'])) {
        $note = appendText($note, 'Mamado match: '.implode(' | ', array_slice($sourceEvidence['mamado_matches'], 0, 3)).'.');
    }
    if (! empty($sourceEvidence['janson_matches'])) {
        $note = appendText($note, 'Janson match: '.implode(' | ', array_slice($sourceEvidence['janson_matches'], 0, 3)).'.');
    }
    $note = appendText($note, 'Review packaging, variant, image, barcode, live stock and retail price before ecommerce activation.');

    return $note;
}

function appendText(string $existing, string $addition): string
{
    $existing = trim($existing);
    $addition = trim($addition);
    if ($addition === '') {
        return $existing;
    }
    if ($existing !== '' && str_contains($existing, $addition)) {
        return $existing;
    }
    if ($existing !== '' && str_contains($existing, 'Shop picture evidence:') && str_starts_with($addition, 'Shop picture evidence:')) {
        return $existing;
    }

    return trim($existing.($existing !== '' ? ' ' : '').$addition);
}

/**
 * @param list<array<string, mixed>> $rows
 */
function writeReport(array $rows, bool $sync): string
{
    $directory = storage_path('app/reports');
    if (! is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $path = $directory.'/picture-product-reconciliation-'.($sync ? 'sync' : 'dry-run').'-'.now()->format('Ymd-His').'.csv';
    $handle = fopen($path, 'w');
    if ($handle === false) {
        throw new RuntimeException("Unable to write report: {$path}");
    }

    if ($rows !== []) {
        fputcsv($handle, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
    }

    fclose($handle);

    return $path;
}

function catalogueSlug(string $name): string
{
    return match ($name) {
        'Hair Extensions' => 'hair-extensions',
        'Hair Products' => 'hair-products',
        'Skin Care' => 'skin-care',
        'Accessories' => 'accessories',
        'Electrical' => 'electrical',
        'Fragrances' => 'fragrances',
        'Makeup' => 'makeup',
        default => Str::slug($name),
    };
}

function catalogueSort(string $slug): int
{
    return match ($slug) {
        'hair-extensions' => 10,
        'hair-products' => 20,
        'skin-care' => 30,
        'accessories' => 40,
        'electrical' => 50,
        'fragrances' => 60,
        'makeup' => 70,
        default => 500,
    };
}

function materialForCatalogue(string $catalogueSlug): ?string
{
    return $catalogueSlug === 'hair-extensions' ? 'Review material' : null;
}

function variantTypeForAxis(string $axis): string
{
    return match ($axis) {
        'Length', 'Size' => 'measurement',
        'Shade' => 'colour_name',
        'Colour', 'Color' => 'colour_code',
        'Pack' => 'count',
        default => 'text',
    };
}

function optionSort(string $value): int
{
    if (preg_match('/^\d+/', $value, $match)) {
        return ((int) $match[0]) * 10;
    }

    return brandSort($value);
}

function productTypeSort(string $productType): int
{
    $order = [
        'Braiding Hair' => 10,
        'Bulk Hair' => 20,
        'Ponytails' => 30,
        'Weaves' => 40,
        'Wigs' => 50,
        'Lace Wigs' => 55,
        'Clip In Hair Extensions' => 60,
        'Body Lotion' => 100,
        'Skin Cream' => 110,
        'Soap' => 120,
        'Shower Gel' => 130,
        'Body Oil' => 140,
        'Skin Serum' => 150,
        'Shampoo' => 200,
        'Conditioner' => 210,
        'Leave-In Conditioner' => 220,
        'Hair Oil' => 230,
        'Styling Gel' => 240,
        'Edge Control' => 250,
        'Hair Colour / Dye' => 300,
        'Relaxer' => 310,
        'Texturizer' => 320,
        'Comb / Brush' => 400,
        'Clipper / Shaver' => 500,
    ];

    return $order[$productType] ?? 900;
}

function styleSort(string $styleName): int
{
    return brandSort($styleName) * 10;
}

function brandSort(string $value): int
{
    $letter = Str::upper(trim($value))[0] ?? 'Z';

    return max(1, ord($letter) - 64) * 10;
}

function lineSort(string $line): int
{
    return sameText($line, 'Unknown') ? 9990 : brandSort($line);
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

function indexKey(string $catalogueSlug, string $brand): string
{
    return $catalogueSlug.'|'.normalizeKey($brand);
}

function normalizeKey(string $value): string
{
    return Str::of($value)
        ->lower()
        ->replaceMatches('/[^a-z0-9]+/u', ' ')
        ->squish()
        ->value();
}

function normalizeProductName(string $value): string
{
    return normalizeKey(removePossessive(cleanName($value)));
}

function stripMeasuresFromNorm(string $value): string
{
    $value = preg_replace('/\b\d+(?:\.\d+)?\s?(?:ml|l|oz|g|gm|kg|lb|pcs|pc|app|inch|inches|in)\b/i', ' ', $value) ?? $value;
    $value = preg_replace('/\b\d{1,2}\b/', ' ', $value) ?? $value;

    return normalizeKey($value);
}

function weakProductMatch(string $left, string $right): bool
{
    if ($left === '' || $right === '') {
        return false;
    }
    if ($left === $right) {
        return true;
    }

    $leftSmall = stripMeasuresFromNorm($left);
    $rightSmall = stripMeasuresFromNorm($right);

    if ($leftSmall !== ''
        && $rightSmall !== ''
        && (str_contains($leftSmall, $rightSmall) || str_contains($rightSmall, $leftSmall))
        && min(strlen($leftSmall), strlen($rightSmall)) >= 10) {
        return true;
    }

    $leftTokens = significantTokens($leftSmall);
    $rightTokens = significantTokens($rightSmall);
    $shared = array_intersect($leftTokens, $rightTokens);
    $shorter = min(count($leftTokens), count($rightTokens));

    return count($shared) >= 2 && $shorter > 0 && (count($shared) / $shorter) >= 0.5;
}

function safeProductMatch(string $left, string $right): bool
{
    if ($left === '' || $right === '') {
        return false;
    }
    if ($left === $right) {
        return true;
    }

    $leftSmall = stripMeasuresFromNorm($left);
    $rightSmall = stripMeasuresFromNorm($right);

    if ($leftSmall !== '' && $leftSmall === $rightSmall) {
        return true;
    }

    if ($leftSmall !== ''
        && $rightSmall !== ''
        && (str_contains($leftSmall, $rightSmall) || str_contains($rightSmall, $leftSmall))
        && min(strlen($leftSmall), strlen($rightSmall)) >= 12) {
        return true;
    }

    $leftTokens = significantTokens($leftSmall);
    $rightTokens = significantTokens($rightSmall);
    $shared = array_intersect($leftTokens, $rightTokens);
    $shorter = min(count($leftTokens), count($rightTokens));

    if ($shorter === 0) {
        return false;
    }

    $ratio = count($shared) / $shorter;

    return (count($shared) >= 3 && $ratio >= 0.65) || (count($shared) >= 2 && $ratio >= 0.8);
}

function safeSourceEvidenceMatch(string $productNorm, string $sourceProductNorm, string $brandNorm, string $sourceBrandNorm): bool
{
    if (! sourceBrandCompatible($brandNorm, $sourceBrandNorm, $productNorm, $sourceProductNorm)) {
        return false;
    }

    if (! safeProductMatch($productNorm, $sourceProductNorm)) {
        return false;
    }

    if (! productFormCompatible($productNorm, $sourceProductNorm)) {
        return false;
    }

    $requiredTokens = discriminatingTokens($productNorm, $brandNorm, $sourceBrandNorm);
    if ($requiredTokens === []) {
        return true;
    }

    $sourceTokens = significantTokens($sourceProductNorm.' '.$sourceBrandNorm);
    $missing = array_values(array_diff($requiredTokens, $sourceTokens));
    $allowedMissing = count($requiredTokens) >= 5 ? 1 : 0;

    return count($missing) <= $allowedMissing;
}

function sourceBrandCompatible(string $brandNorm, string $sourceBrandNorm, string $productNorm, string $sourceProductNorm): bool
{
    if ($brandNorm === '' || $brandNorm === 'unknown' || $sourceBrandNorm === '') {
        return true;
    }

    $genericSources = [
        'miscellaneous',
        'clearance list',
        'hair care',
        'skin care',
        'hair colours',
        'soaps',
        'electricals',
    ];

    if (in_array($sourceBrandNorm, $genericSources, true)) {
        return true;
    }

    if ($brandNorm === $sourceBrandNorm
        || str_contains($sourceBrandNorm, $brandNorm)
        || str_contains($brandNorm, $sourceBrandNorm)) {
        return true;
    }

    return str_contains($sourceProductNorm, $brandNorm) || str_contains($productNorm, $sourceBrandNorm);
}

function productFormCompatible(string $productNorm, string $sourceProductNorm): bool
{
    $forms = [
        'soap' => ['soap'],
        'shampoo' => ['shampoo', 'shamp'],
        'conditioner' => ['conditioner', 'cond'],
        'lotion' => ['lotion', 'milk'],
        'cream' => ['cream', 'creme'],
        'gel' => ['gel'],
        'oil' => ['oil'],
        'serum' => ['serum'],
        'spray' => ['spray', 'spritz'],
        'mousse' => ['mousse', 'foam'],
        'relaxer' => ['relaxer'],
        'colour' => ['colour', 'color', 'dye', 'henna'],
    ];

    foreach ($forms as $tokens) {
        $leftHas = containsAnyToken($productNorm, $tokens);
        if (! $leftHas) {
            continue;
        }

        return containsAnyToken($sourceProductNorm, $tokens);
    }

    return true;
}

/**
 * @param list<string> $tokens
 */
function containsAnyToken(string $value, array $tokens): bool
{
    foreach ($tokens as $token) {
        if (preg_match('/\b'.preg_quote($token, '/').'\b/i', $value)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function discriminatingTokens(string $productNorm, string $brandNorm, string $sourceBrandNorm): array
{
    $generic = array_fill_keys([
        'afro', 'anti', 'beauty', 'black', 'body', 'bottle', 'braid', 'brown', 'care', 'castor',
        'classic', 'color', 'colour', 'cream', 'creme', 'dark', 'deep', 'dye', 'formula', 'gel',
        'hair', 'herbal', 'intensive', 'jamaican', 'light', 'lotion', 'moisture', 'natural',
        'original', 'permanent', 'powder', 'product', 'pure', 'skin', 'soap', 'spray', 'strong',
        'synthetic', 'treatment', 'white',
    ], true);

    foreach (array_merge(significantTokens($brandNorm), significantTokens($sourceBrandNorm)) as $token) {
        $generic[$token] = true;
    }

    return collect(explode(' ', normalizeKey($productNorm)))
        ->filter(fn (string $token): bool => preg_match('/^\d{2,}$/', $token) || strlen($token) >= 4)
        ->reject(fn (string $token): bool => isset($generic[$token]))
        ->unique()
        ->values()
        ->all();
}

/**
 * @return list<string>
 */
function significantTokens(string $value): array
{
    $stop = array_fill_keys([
        'the', 'and', 'with', 'for', 'hair', 'skin', 'body', 'cream', 'lotion', 'oil', 'gel',
        'soap', 'product', 'classic', 'original',
    ], true);

    return collect(explode(' ', normalizeKey($value)))
        ->filter(fn (string $word): bool => strlen($word) >= 4 && ! isset($stop[$word]))
        ->unique()
        ->values()
        ->all();
}

function removeBrandPrefix(string $value, string $brand): string
{
    $value = cleanName($value);
    $brand = cleanName($brand);
    if ($brand !== '' && str_starts_with(Str::lower($value), Str::lower($brand))) {
        return cleanName(substr($value, strlen($brand)));
    }

    return $value;
}

function removePossessive(string $value): string
{
    return str_replace(["'s", "’s"], '', $value);
}

function cleanName(string $value): string
{
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = str_replace(['&Amp;'], ['&'], $value);
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;
    $value = preg_replace('/\s+([,.)])/', '$1', $value) ?? $value;

    return trim($value, " \t\n\r\0\x0B-");
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

function sameText(string $a, string $b): bool
{
    return Str::lower(trim($a)) === Str::lower(trim($b));
}
