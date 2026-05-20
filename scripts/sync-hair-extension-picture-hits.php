<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$sync = in_array('--sync', $argv, true);
$now = now();

$rows = DB::table('observed_products as op')
    ->join('categories as c', 'c.id', '=', 'op.category_id')
    ->select([
        'op.id',
        'op.picture_id',
        'op.brand',
        'op.canonical_brand',
        'op.brand_line',
        'op.product_name',
    ])
    ->where(function ($query): void {
        $query
            ->where('c.slug', 'hair')
            ->orWhereIn('op.id', [15, 20, 22, 38]);
    })
    ->where('op.product_name', '!=', '')
    ->orderBy('op.picture_id')
    ->orderBy('op.sort_order')
    ->get();

if ($rows->isEmpty()) {
    echo "No Hair picture hits found.\n";
    exit(0);
}

$summary = [
    'picture_hits' => $rows->count(),
    'extension_hits' => 0,
    'skipped_non_extension' => 0,
    'skipped_unclear' => 0,
    'found_existing' => 0,
    'moved_from_picture_brand' => 0,
    'created_skus' => 0,
    'review_pending' => 0,
];

$report = [];

DB::beginTransaction();

try {
    foreach ($rows as $row) {
        $classification = classifyHairPictureHit($row);

        if (($classification['is_extension'] ?? false) !== true) {
            if (($classification['skip_type'] ?? '') === 'unclear') {
                $summary['skipped_unclear']++;
            } else {
                $summary['skipped_non_extension']++;
            }

            $report[] = reportRow($row, $classification, ($classification['skip_type'] ?? '') === 'unclear' ? 'skipped_unclear' : 'skipped_non_extension', null, null);
            continue;
        }

        $summary['extension_hits']++;

        if (($classification['review_pending'] ?? false) === true) {
            $summary['review_pending']++;
        }

        $targetBrand = ensureCatalogueBrand($classification['brand']);
        $targetLine = ensureCatalogueLine($targetBrand->id, $classification['line']);
        $targetType = ensureCatalogueProductType($targetBrand->id, $targetLine->id, $classification['product_type']);
        $targetStyle = findStyle($targetBrand->id, $classification['style_name'], $targetType->id)
            ?: findStyle($targetBrand->id, $classification['style_name'])
            ?: null;

        $existingTargetSku = findSkuUnderBrand($targetBrand->id, $classification['sku_name']);
        if ($existingTargetSku !== null) {
            $summary['found_existing']++;
            appendPictureEvidence($existingTargetSku->sku_id, $row, $classification);
            ensureSkuOptionsFromClassification($existingTargetSku->sku_id, $existingTargetSku->style_id, $classification);
            alignObservedProduct($row, $classification);

            if (needsStyleMove($existingTargetSku, $targetType->id)) {
                DB::table('brand_catalogue_styles')
                    ->where('id', $existingTargetSku->style_id)
                    ->update([
                        'brand_catalogue_brand_id' => $targetBrand->id,
                        'brand_catalogue_product_type_id' => $targetType->id,
                        'updated_at' => $GLOBALS['now'],
                    ]);
            }

            $report[] = reportRow($row, $classification, 'found_existing_sku', $existingTargetSku->sku_id, $existingTargetSku->style_id);
            continue;
        }

        $existingAnySku = findSkuInHairCatalogue($classification['sku_name']);
        if ($existingAnySku !== null) {
            $summary['moved_from_picture_brand']++;

            if ($targetStyle !== null && (int) $targetStyle->id !== (int) $existingAnySku->style_id) {
                moveSkuToStyle($existingAnySku->sku_id, $targetStyle->id, $classification);
                cleanupEmptyPictureTree((int) $existingAnySku->brand_id, (int) $existingAnySku->line_id, (int) $existingAnySku->product_type_id, (int) $existingAnySku->style_id);
                $styleId = (int) $targetStyle->id;
            } else {
                $styleId = moveStyleToProductType($existingAnySku->style_id, $targetBrand->id, $targetType->id, $classification['style_name']);
            }

            appendPictureEvidence($existingAnySku->sku_id, $row, $classification);
            alignObservedProduct($row, $classification);
            $report[] = reportRow($row, $classification, 'moved_existing_picture_sku', $existingAnySku->sku_id, $styleId);
            continue;
        }

        if ($targetStyle === null) {
            $targetStyle = createStyle($targetBrand->id, $targetType->id, $classification['style_name']);
        }

        $skuId = createSku($targetStyle->id, $classification, $row);
        linkSkuOptions($skuId, $targetStyle->id, $classification);
        alignObservedProduct($row, $classification);
        $summary['created_skus']++;
        $report[] = reportRow($row, $classification, 'created_picture_sku', $skuId, $targetStyle->id);
    }

    cleanupAllEmptyPictureOnlyBrands();

    $reportPath = writeReport($report, $sync);

    if (! $sync) {
        DB::rollBack();
    } else {
        DB::commit();
    }
} catch (Throwable $e) {
    DB::rollBack();
    throw $e;
}

echo $sync ? "Hair extension picture hits synced.\n" : "Hair extension picture hits dry run.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}
echo "report: {$reportPath}\n";
if (! $sync) {
    echo "Run with --sync to apply.\n";
}

function classifyHairPictureHit(object $row): array
{
    $observedBrand = cleanName((string) $row->brand);
    $canonicalBrand = cleanName((string) ($row->canonical_brand ?: $row->brand));
    $brandLine = cleanName((string) ($row->brand_line ?? ''));
    $product = cleanName((string) $row->product_name);
    $norm = normalizeText($product);

    if (($canonicalBrand === 'Urban' || $observedBrand === 'Urban') && $norm === '100 premium syn fibre') {
        return [
            'is_extension' => false,
            'skip_type' => 'unclear',
            'brand' => 'Urban',
            'line' => '',
            'product_type' => '',
            'style_name' => '',
            'sku_name' => $product,
            'reason' => 'Hair-extension brand is clear, but product text is too generic to create a sellable product safely.',
        ];
    }

    if (isNonExtensionHairProduct($norm)) {
        return [
            'is_extension' => false,
            'brand' => $canonicalBrand ?: $observedBrand,
            'line' => $brandLine,
            'product_type' => '',
            'style_name' => '',
            'sku_name' => $product,
            'reason' => 'Hair-care/styling product, not a hair extension product.',
        ];
    }

    $brand = $canonicalBrand ?: $observedBrand;
    $line = $brandLine !== '' ? $brandLine : $brand;
    $productType = inferExtensionProductType($product);
    $styleName = inferStyleName($product);
    $reviewPending = false;

    if ($brand === 'Kali' && $observedBrand === 'Kali Essential') {
        $line = 'Kali Essential';
    }

    if (in_array($canonicalBrand, ['Kuknus Collection', 'Kuknus Braid'], true) || in_array($observedBrand, ['Kuknus Collection', 'Kuknus Braid'], true)) {
        $brand = 'Kuknus';
        $line = 'Kuknus';
    }

    if (in_array($canonicalBrand, ['Smart Braid', 'Vivitress'], true) || in_array($observedBrand, ['Smart Braid', 'Vivitress'], true)) {
        $brand = 'Smart';
        $line = str_contains($norm, 'vivitress') || $observedBrand === 'Vivitress' ? 'Vivitress' : 'Smart Braid';
        $productType = $line === 'Vivitress' ? 'Crochet Hair' : 'Braiding Hair';
    }

    if (in_array($canonicalBrand, ['Sleek Hair', 'Noble'], true) || in_array($observedBrand, ['Sleek Hair', 'Sleek', 'Fashion Idol Express by Sleek', 'Noble'], true)) {
        $brand = 'Sleek';
        if ($observedBrand === 'Noble' || str_contains($norm, 'noble') || str_contains($norm, 'bohemian long volume')) {
            $line = 'Noble / Noble Gold';
            $productType = 'Synthetic Hair Weave';
        } elseif (str_contains($norm, 'fashion idol') || str_contains($norm, 'french curl')) {
            $line = 'Fashion Idol Express';
            $productType = 'Synthetic Braiding / Crochet Hair';
            $styleName = 'French Curl Braid';
        } elseif (str_contains($norm, 'remy couture')) {
            $line = 'Remy Couture';
            $productType = 'Human Hair Weave';
        } elseif (str_contains($norm, 'style icon')) {
            $line = 'Style Icon';
            $productType = 'Human Hair Weave';
            $styleName = 'Style Icon Remy Silky Weave';
        } else {
            $line = 'Sleek';
            $productType = 'Human Hair Weave';
            $reviewPending = true;
        }
    }

    if ($brand === 'Sensationnel') {
        if (str_contains($norm, 'soft n silky') || str_contains($norm, 'soft silky')) {
            $line = 'Sensationnel Soft N Silky';
            $productType = 'Synthetic Braiding Hair';
            $styleName = 'Afro Twist Braid';
        } elseif (str_contains($norm, 'premium too') && str_contains($norm, 'human')) {
            $line = 'Sensationnel Premium Too Human Hair';
            $productType = 'Human Hair Weave';
            $styleName = 'Premium Too 100% Human Hair';
            $reviewPending = true;
        } elseif (str_contains($norm, 'goddess remi')) {
            $line = 'Sensationnel Goddess Select';
            $productType = 'Weaves';
            $styleName = 'Goddess Remi Luxury Quality 100% Remi Human Hair';
            $reviewPending = true;
        }
    }

    if ($brand === 'Kali' && str_contains($norm, 'mega bohemian remi')) {
        $line = 'Kali';
        $productType = 'Braiding Hair';
        $styleName = 'Mega Bohemian Remi';
        $reviewPending = true;
    }

    if ($brand === 'Kuknus') {
        if (str_contains($norm, 'jamaican girl')) {
            $line = 'Kuknus';
            $productType = 'Ponytails / Drawstrings';
            $styleName = 'Jamaican Girl';
            $reviewPending = true;
        } elseif (str_contains($norm, 'jersey girl')) {
            $line = 'Kuknus';
            $productType = 'Ponytails / Drawstrings';
            $styleName = 'Jersey Girl';
            $reviewPending = true;
        }
    }

    if ($brand === 'Sensationnel' && str_contains($norm, 'premium now') && str_contains($norm, 'european straight')) {
        $line = 'Sensationnel Premium Now';
        $productType = 'Weaves';
        $styleName = 'Euro Straight';
        $reviewPending = true;
    }

    if ($brand === '1st Lady Platinum Collection' || $brand === 'Dignity' || $brand === 'Hair Code') {
        $productType = 'Human Hair Weave';
    }

    if ($brand === 'Evoke') {
        $productType = 'Micro Loop Remy Extensions';
    }

    if ($brand === 'EI Hair Extensions' && str_contains($norm, 'triple weft')) {
        $productType = 'Triple Weft Remy Hair';
    }

    return [
        'is_extension' => true,
        'brand' => $brand,
        'line' => $line,
        'product_type' => $productType,
        'style_name' => $styleName,
        'sku_name' => $product,
        'option_axes' => inferOptionAxes($product),
        'review_pending' => $reviewPending,
        'reason' => $reviewPending ? 'Extension product imported, but family/style should be reviewed in shop.' : 'Hair extension product.',
    ];
}

function isNonExtensionHairProduct(string $norm): bool
{
    $nonExtensionTerms = [
        'actiforce',
        'bees wax',
        'beeswax',
        'black castor oil hot oils',
        'braid gel',
        'braiding shine jelo',
        'creme hair dress',
        'hair dress',
        'loc twist braid edge',
        'lock gro',
        'magic fingers',
        'smoothie',
        'twist loc smoothie',
    ];

    foreach ($nonExtensionTerms as $term) {
        if (str_contains($norm, $term)) {
            return true;
        }
    }

    return false;
}

function inferExtensionProductType(string $product): string
{
    $norm = normalizeText($product);

    return match (true) {
        str_contains($norm, 'clip in') || str_contains($norm, 'clip-in') => 'Clip In Hair Extensions',
        str_contains($norm, 'micro loop') || str_contains($norm, 'micro-loop') => 'Micro Loop Remy Extensions',
        str_contains($norm, 'triple weft') => 'Triple Weft Remy Hair',
        str_contains($norm, 'closure') || str_contains($norm, 'frontal') => 'Human Hair Bundles & Closures',
        str_contains($norm, 'human hair') || str_contains($norm, 'remy') || str_contains($norm, 'virgin') || str_contains($norm, 'weave') || str_contains($norm, 'weft') => 'Human Hair Weave',
        str_contains($norm, 'bulk') => 'Bulk Hair',
        str_contains($norm, 'pony') || str_contains($norm, 'drawstring') => 'Ponytails',
        str_contains($norm, 'wig') => 'Wigs',
        str_contains($norm, 'crochet') || str_contains($norm, 'loc') || str_contains($norm, 'twist') => 'Crochet Hair',
        default => 'Braiding Hair',
    };
}

function inferStyleName(string $product): string
{
    $style = preg_replace('/\b(?:\d+X|[23456789]x)\b/i', '', $product) ?? $product;
    $style = preg_replace('/\b(?:Mega Pack|Eazi-Pack|Value Pack)\b/i', '', $style) ?? $style;
    $style = preg_replace('/\b\d+\s*(?:inch|inches|")\b/i', '', $style) ?? $style;
    $style = preg_replace('/\s+/', ' ', trim($style)) ?? trim($style);

    return $style !== '' ? $style : $product;
}

function inferOptionAxes(string $product): array
{
    $axes = [];

    if (preg_match('/(?:^|\b)(\d+)\s*(?:inch|inches|"|”)/i', $product, $match)) {
        $axes['Length'] = $match[1].'"';
    }

    if (preg_match('/\b([23456789]X|\d+x)\b/i', $product, $match)) {
        $axes['Pack'] = strtoupper($match[1]);
    }

    foreach (['Mega Pack', 'Eazi-Pack', 'Value Pack'] as $pack) {
        if (stripos($product, $pack) !== false) {
            $axes['Pack'] = isset($axes['Pack']) ? $axes['Pack'].' '.$pack : $pack;
        }
    }

    return $axes;
}

function ensureCatalogueBrand(string $name): object
{
    $brand = DB::table('brand_catalogue_brands')
        ->where('brand_catalogue_id', 1)
        ->where('name', $name)
        ->first();

    if ($brand !== null) {
        return $brand;
    }

    $id = DB::table('brand_catalogue_brands')->insertGetId([
        'brand_catalogue_id' => 1,
        'name' => $name,
        'slug' => uniqueSlug('brand_catalogue_brands', Str::slug($name)),
        'is_active' => 1,
        'sort_order' => 0,
        'created_at' => $GLOBALS['now'],
        'updated_at' => $GLOBALS['now'],
    ]);

    return DB::table('brand_catalogue_brands')->where('id', $id)->first();
}

function ensureCatalogueLine(int $brandId, string $name): object
{
    $line = DB::table('brand_catalogue_lines')
        ->where('brand_catalogue_brand_id', $brandId)
        ->where('name', $name)
        ->first();

    if ($line !== null) {
        return $line;
    }

    $id = DB::table('brand_catalogue_lines')->insertGetId([
        'brand_catalogue_brand_id' => $brandId,
        'name' => $name,
        'slug' => uniqueSlug('brand_catalogue_lines', Str::slug($name), ['brand_catalogue_brand_id' => $brandId]),
        'is_default' => 0,
        'is_active' => 1,
        'sort_order' => 0,
        'created_at' => $GLOBALS['now'],
        'updated_at' => $GLOBALS['now'],
    ]);

    return DB::table('brand_catalogue_lines')->where('id', $id)->first();
}

function ensureCatalogueProductType(int $brandId, int $lineId, string $name): object
{
    $type = DB::table('brand_catalogue_product_types')
        ->where('brand_catalogue_brand_id', $brandId)
        ->where('brand_catalogue_line_id', $lineId)
        ->where('name', $name)
        ->first();

    if ($type !== null) {
        return $type;
    }

    $id = DB::table('brand_catalogue_product_types')->insertGetId([
        'brand_catalogue_brand_id' => $brandId,
        'brand_catalogue_line_id' => $lineId,
        'name' => $name,
        'slug' => uniqueSlug('brand_catalogue_product_types', Str::slug($name), [
            'brand_catalogue_brand_id' => $brandId,
            'brand_catalogue_line_id' => $lineId,
        ]),
        'is_active' => 1,
        'sort_order' => 0,
        'created_at' => $GLOBALS['now'],
        'updated_at' => $GLOBALS['now'],
    ]);

    return DB::table('brand_catalogue_product_types')->where('id', $id)->first();
}

function findStyle(int $brandId, string $styleName, ?int $productTypeId = null): ?object
{
    $query = DB::table('brand_catalogue_styles')
        ->where('brand_catalogue_brand_id', $brandId)
        ->whereRaw('LOWER(name) = ?', [strtolower($styleName)]);

    if ($productTypeId !== null) {
        $query->where('brand_catalogue_product_type_id', $productTypeId);
    }

    return $query->orderBy('id')->first();
}

function createStyle(int $brandId, int $productTypeId, string $name): object
{
    $id = DB::table('brand_catalogue_styles')->insertGetId([
        'brand_catalogue_brand_id' => $brandId,
        'brand_catalogue_product_type_id' => $productTypeId,
        'name' => $name,
        'slug' => uniqueSlug('brand_catalogue_styles', Str::slug($name), [
            'brand_catalogue_brand_id' => $brandId,
            'brand_catalogue_product_type_id' => $productTypeId,
        ]),
        'material_name' => null,
        'is_active' => 1,
        'sort_order' => 0,
        'created_at' => $GLOBALS['now'],
        'updated_at' => $GLOBALS['now'],
    ]);

    return DB::table('brand_catalogue_styles')->where('id', $id)->first();
}

function findSkuUnderBrand(int $brandId, string $skuName): ?object
{
    return DB::table('brand_catalogue_skus as sku')
        ->join('brand_catalogue_styles as s', 's.id', '=', 'sku.brand_catalogue_style_id')
        ->join('brand_catalogue_product_types as pt', 'pt.id', '=', 's.brand_catalogue_product_type_id')
        ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
        ->join('brand_catalogue_brands as b', 'b.id', '=', 'l.brand_catalogue_brand_id')
        ->where('b.brand_catalogue_id', 1)
        ->where('b.id', $brandId)
        ->whereRaw('LOWER(sku.name) = ?', [strtolower($skuName)])
        ->select([
            'sku.id as sku_id',
            'sku.name as sku_name',
            'sku.note',
            's.id as style_id',
            's.brand_catalogue_product_type_id as product_type_id',
            'pt.id as pt_id',
            'l.id as line_id',
            'b.id as brand_id',
        ])
        ->first();
}

function findSkuInHairCatalogue(string $skuName): ?object
{
    return DB::table('brand_catalogue_skus as sku')
        ->join('brand_catalogue_styles as s', 's.id', '=', 'sku.brand_catalogue_style_id')
        ->join('brand_catalogue_product_types as pt', 'pt.id', '=', 's.brand_catalogue_product_type_id')
        ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
        ->join('brand_catalogue_brands as b', 'b.id', '=', 'l.brand_catalogue_brand_id')
        ->where('b.brand_catalogue_id', 1)
        ->whereRaw('LOWER(sku.name) = ?', [strtolower($skuName)])
        ->select([
            'sku.id as sku_id',
            'sku.name as sku_name',
            's.id as style_id',
            's.name as style_name',
            'pt.id as product_type_id',
            'l.id as line_id',
            'b.id as brand_id',
            'b.name as brand_name',
        ])
        ->orderByRaw("CASE WHEN sku.note LIKE '%Shop picture evidence%' THEN 0 ELSE 1 END")
        ->orderBy('sku.id')
        ->first();
}

function createSku(int $styleId, array $classification, object $row): int
{
    return DB::table('brand_catalogue_skus')->insertGetId([
        'brand_catalogue_style_id' => $styleId,
        'name' => $classification['sku_name'],
        'slug' => uniqueSlug('brand_catalogue_skus', Str::slug($classification['sku_name']), ['brand_catalogue_style_id' => $styleId]),
        'sku_code' => null,
        'barcode' => null,
        'option_signature' => optionSignature($classification['option_axes']),
        'description' => null,
        'note' => pictureEvidenceNote($row, $classification),
        'is_active' => 1,
        'sort_order' => 0,
        'created_at' => $GLOBALS['now'],
        'updated_at' => $GLOBALS['now'],
    ]);
}

function moveStyleToProductType(int $styleId, int $brandId, int $productTypeId, string $styleName): int
{
    DB::table('brand_catalogue_styles')
        ->where('id', $styleId)
        ->update([
            'brand_catalogue_brand_id' => $brandId,
            'brand_catalogue_product_type_id' => $productTypeId,
            'name' => $styleName,
            'slug' => uniqueSlug('brand_catalogue_styles', Str::slug($styleName), [
                'brand_catalogue_brand_id' => $brandId,
                'brand_catalogue_product_type_id' => $productTypeId,
            ], $styleId),
            'updated_at' => $GLOBALS['now'],
        ]);

    return $styleId;
}

function moveSkuToStyle(int $skuId, int $styleId, array $classification): void
{
    DB::table('brand_catalogue_sku_variant_options')->where('brand_catalogue_sku_id', $skuId)->delete();

    DB::table('brand_catalogue_skus')
        ->where('id', $skuId)
        ->update([
            'brand_catalogue_style_id' => $styleId,
            'option_signature' => optionSignature($classification['option_axes']),
            'updated_at' => $GLOBALS['now'],
        ]);

    linkSkuOptions($skuId, $styleId, $classification);
}

function needsStyleMove(object $sku, int $targetTypeId): bool
{
    return (int) $sku->product_type_id !== $targetTypeId;
}

function appendPictureEvidence(int $skuId, object $row, array $classification): void
{
    $sku = DB::table('brand_catalogue_skus')->where('id', $skuId)->first();
    if ($sku === null) {
        return;
    }

    $note = (string) ($sku->note ?? '');
    $evidence = pictureEvidenceNote($row, $classification);

    if (! str_contains($note, (string) $row->picture_id)) {
        $note = trim($note) !== '' ? trim($note).' '.$evidence : $evidence;
    }

    if (($classification['review_pending'] ?? false) === true && ! str_contains($note, 'Style/family review pending.')) {
        $note = trim($note).' Style/family review pending.';
    }

    DB::table('brand_catalogue_skus')
        ->where('id', $skuId)
        ->update([
            'note' => trim($note),
            'updated_at' => $GLOBALS['now'],
        ]);
}

function pictureEvidenceNote(object $row, array $classification): string
{
    $observedBrand = cleanName((string) $row->brand);
    $suffix = ($classification['review_pending'] ?? false) === true ? ' Style/family review pending.' : '';

    return sprintf(
        'Shop picture evidence: %s; observed as %s - %s.%s',
        $row->picture_id,
        $observedBrand,
        cleanName((string) $row->product_name),
        $suffix
    );
}

function linkSkuOptions(int $skuId, int $styleId, array $classification): void
{
    foreach (($classification['option_axes'] ?? []) as $axis => $value) {
        $variant = ensureVariant($styleId, $axis);
        $option = ensureVariantOption($variant->id, $value);

        DB::table('brand_catalogue_sku_variant_options')->updateOrInsert(
            [
                'brand_catalogue_sku_id' => $skuId,
                'brand_catalogue_variant_id' => $variant->id,
                'brand_catalogue_variant_option_id' => $option->id,
            ],
            [
                'created_at' => $GLOBALS['now'],
                'updated_at' => $GLOBALS['now'],
            ]
        );
    }
}

function ensureSkuOptionsFromClassification(int $skuId, int $styleId, array $classification): void
{
    if (($classification['option_axes'] ?? []) === []) {
        return;
    }

    $sku = DB::table('brand_catalogue_skus')->where('id', $skuId)->first();
    if ($sku === null || trim((string) $sku->option_signature) !== '') {
        return;
    }

    DB::table('brand_catalogue_skus')
        ->where('id', $skuId)
        ->update([
            'option_signature' => optionSignature($classification['option_axes']),
            'updated_at' => $GLOBALS['now'],
        ]);

    linkSkuOptions($skuId, $styleId, $classification);
}

function ensureVariant(int $styleId, string $name): object
{
    $variant = DB::table('brand_catalogue_variants')
        ->where('brand_catalogue_style_id', $styleId)
        ->where('name', $name)
        ->first();

    if ($variant !== null) {
        return $variant;
    }

    $type = match ($name) {
        'Length' => 'measurement',
        'Colour' => 'colour_code',
        default => 'text',
    };

    $id = DB::table('brand_catalogue_variants')->insertGetId([
        'brand_catalogue_style_id' => $styleId,
        'name' => $name,
        'variant_type' => $type,
        'sort_order' => $name === 'Length' ? 10 : 20,
        'created_at' => $GLOBALS['now'],
        'updated_at' => $GLOBALS['now'],
    ]);

    return DB::table('brand_catalogue_variants')->where('id', $id)->first();
}

function ensureVariantOption(int $variantId, string $value): object
{
    $option = DB::table('brand_catalogue_variant_options')
        ->where('variant_id', $variantId)
        ->where('label', $value)
        ->first();

    if ($option !== null) {
        return $option;
    }

    $id = DB::table('brand_catalogue_variant_options')->insertGetId([
        'variant_id' => $variantId,
        'label' => $value,
        'value' => $value,
        'sort_order' => 0,
        'created_at' => $GLOBALS['now'],
        'updated_at' => $GLOBALS['now'],
    ]);

    return DB::table('brand_catalogue_variant_options')->where('id', $id)->first();
}

function alignObservedProduct(object $row, array $classification): void
{
    DB::table('observed_products')
        ->where('id', $row->id)
        ->update([
            'canonical_brand' => $classification['brand'],
            'brand_line' => $classification['line'] !== $classification['brand'] ? $classification['line'] : null,
            'updated_at' => $GLOBALS['now'],
        ]);
}

function cleanupEmptyPictureTree(int $brandId, int $lineId, int $productTypeId, int $styleId): void
{
    if (DB::table('brand_catalogue_skus')->where('brand_catalogue_style_id', $styleId)->exists()) {
        return;
    }

    $variantIds = DB::table('brand_catalogue_variants')->where('brand_catalogue_style_id', $styleId)->pluck('id');
    if ($variantIds->isNotEmpty()) {
        DB::table('brand_catalogue_variant_options')->whereIn('variant_id', $variantIds)->delete();
        DB::table('brand_catalogue_variants')->whereIn('id', $variantIds)->delete();
    }

    DB::table('brand_catalogue_styles')->where('id', $styleId)->delete();

    if (! DB::table('brand_catalogue_styles')->where('brand_catalogue_product_type_id', $productTypeId)->exists()) {
        DB::table('brand_catalogue_product_types')->where('id', $productTypeId)->delete();
    }

    if (! DB::table('brand_catalogue_product_types')->where('brand_catalogue_line_id', $lineId)->exists()) {
        DB::table('brand_catalogue_lines')->where('id', $lineId)->delete();
    }

    $pictureOnlyBrands = ['Kuknus Braid', 'Kuknus Collection', 'Noble', 'Sleek Hair', 'Smart Braid', 'Vivitress'];
    $brand = DB::table('brand_catalogue_brands')->where('id', $brandId)->first();
    if ($brand !== null
        && in_array((string) $brand->name, $pictureOnlyBrands, true)
        && ! DB::table('brand_catalogue_lines')->where('brand_catalogue_brand_id', $brandId)->exists()
    ) {
        DB::table('brand_catalogue_brands')->where('id', $brandId)->delete();
    }
}

function cleanupAllEmptyPictureOnlyBrands(): void
{
    $pictureOnlyBrands = ['Kuknus Braid', 'Kuknus Collection', 'Noble', 'Sleek Hair', 'Smart Braid', 'Vivitress'];
    $brands = DB::table('brand_catalogue_brands')
        ->where('brand_catalogue_id', 1)
        ->whereIn('name', $pictureOnlyBrands)
        ->get();

    foreach ($brands as $brand) {
        $styleIds = DB::table('brand_catalogue_styles as s')
            ->join('brand_catalogue_product_types as pt', 'pt.id', '=', 's.brand_catalogue_product_type_id')
            ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
            ->where('l.brand_catalogue_brand_id', $brand->id)
            ->whereNotExists(function ($query): void {
                $query
                    ->select(DB::raw(1))
                    ->from('brand_catalogue_skus as sku')
                    ->whereColumn('sku.brand_catalogue_style_id', 's.id');
            })
            ->pluck('s.id');

        if ($styleIds->isNotEmpty()) {
            $variantIds = DB::table('brand_catalogue_variants')->whereIn('brand_catalogue_style_id', $styleIds)->pluck('id');
            if ($variantIds->isNotEmpty()) {
                DB::table('brand_catalogue_variant_options')->whereIn('variant_id', $variantIds)->delete();
                DB::table('brand_catalogue_variants')->whereIn('id', $variantIds)->delete();
            }

            DB::table('brand_catalogue_styles')->whereIn('id', $styleIds)->delete();
        }

        $typeIds = DB::table('brand_catalogue_product_types as pt')
            ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
            ->where('l.brand_catalogue_brand_id', $brand->id)
            ->whereNotExists(function ($query): void {
                $query
                    ->select(DB::raw(1))
                    ->from('brand_catalogue_styles as s')
                    ->whereColumn('s.brand_catalogue_product_type_id', 'pt.id');
            })
            ->pluck('pt.id');

        if ($typeIds->isNotEmpty()) {
            DB::table('brand_catalogue_product_types')->whereIn('id', $typeIds)->delete();
        }

        $lineIds = DB::table('brand_catalogue_lines as l')
            ->where('l.brand_catalogue_brand_id', $brand->id)
            ->whereNotExists(function ($query): void {
                $query
                    ->select(DB::raw(1))
                    ->from('brand_catalogue_product_types as pt')
                    ->whereColumn('pt.brand_catalogue_line_id', 'l.id');
            })
            ->pluck('l.id');

        if ($lineIds->isNotEmpty()) {
            DB::table('brand_catalogue_lines')->whereIn('id', $lineIds)->delete();
        }

        if (! DB::table('brand_catalogue_lines')->where('brand_catalogue_brand_id', $brand->id)->exists()) {
            DB::table('brand_catalogue_brands')->where('id', $brand->id)->delete();
        }
    }
}

function optionSignature(array $axes): ?string
{
    if ($axes === []) {
        return '';
    }

    ksort($axes);

    return collect($axes)
        ->map(fn (string $value, string $axis): string => $axis.':'.$value)
        ->implode('|');
}

function reportRow(object $row, array $classification, string $action, ?int $skuId, ?int $styleId): array
{
    return [
        'action' => $action,
        'picture_id' => $row->picture_id,
        'observed_brand' => cleanName((string) $row->brand),
        'target_brand' => $classification['brand'] ?? '',
        'target_line' => $classification['line'] ?? '',
        'product_type' => $classification['product_type'] ?? '',
        'style_name' => $classification['style_name'] ?? '',
        'sku_name' => $classification['sku_name'] ?? cleanName((string) $row->product_name),
        'sku_id' => $skuId,
        'style_id' => $styleId,
        'reason' => $classification['reason'] ?? '',
    ];
}

function writeReport(array $rows, bool $sync): string
{
    $dir = __DIR__.'/../storage/app/catalogue-reports';
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $path = $dir.'/hair-extension-picture-hits-'.($sync ? 'sync' : 'dry-run').'-'.date('Ymd-His').'.csv';
    $handle = fopen($path, 'wb');
    fputcsv($handle, ['action', 'picture_id', 'observed_brand', 'target_brand', 'target_line', 'product_type', 'style_name', 'sku_name', 'sku_id', 'style_id', 'reason']);

    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);

    return $path;
}

function uniqueSlug(string $table, string $baseSlug, array $scope = [], ?int $ignoreId = null): string
{
    $baseSlug = $baseSlug !== '' ? $baseSlug : 'item';
    $slug = $baseSlug;
    $counter = 2;

    while (true) {
        $query = DB::table($table)->where('slug', $slug);
        foreach ($scope as $column => $value) {
            $query->where($column, $value);
        }
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if (! $query->exists()) {
            return $slug;
        }

        $slug = $baseSlug.'-'.$counter;
        $counter++;
    }
}

function cleanName(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function normalizeText(string $value): string
{
    $value = Str::ascii($value);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}
