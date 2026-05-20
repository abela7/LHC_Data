<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use App\Models\ProductFamily;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$apply = in_array('--apply', $argv, true);
$timestamp = date('Ymd-His');
$reportDir = public_path('reports');

if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$majorBrandNames = [
    'Cherish',
    'Sleek',
    'Koko',
    'Kuknus',
    'Sensationnel',
    'EI Hair Extensions',
    'Obsession',
    'Kali',
    'Smart',
    'X-Pression',
    'Impression',
];

$brandArg = collect($argv)
    ->first(fn (string $arg): bool => str_starts_with($arg, '--brand='));
$requestedBrand = $brandArg ? trim(substr($brandArg, 8), " \t\n\r\0\x0B\"'") : null;

$csvPath = $reportDir."/hair-catalogue-imported-floor-merge-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/hair-catalogue-imported-floor-merge-latest.csv';

function mif_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function mif_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function mif_tokens(string $value): array
{
    preg_match_all('/[a-z0-9]+/i', Str::lower($value), $matches);

    return $matches[0] ?? [];
}

function mif_family_core(string $styleName, string $brandName): string
{
    $tokens = mif_tokens($styleName);
    $brandTokens = array_flip(mif_tokens($brandName));
    $generic = array_flip([
        'hair', 'human', 'synthetic', 'kanekalon', 'fibre', 'fiber',
        'extension', 'extensions', 'piece', 'pieces', 'pack', 'packs',
        'x', 'more', 'value', 'collection', 'series', 'style',
    ]);

    $kept = [];
    foreach ($tokens as $token) {
        if (isset($brandTokens[$token]) || isset($generic[$token])) {
            continue;
        }
        $kept[] = $token;
    }

    return implode('', $kept) ?: mif_norm($styleName);
}

function mif_type_group(?string $typeName): string
{
    $key = mif_norm((string) $typeName);

    if ($key === '') {
        return 'unknown';
    }

    if (str_contains($key, 'clipin') || str_contains($key, 'clipins')) {
        return 'clip_in';
    }

    if (str_contains($key, 'closure') || str_contains($key, 'frontal')) {
        return 'closure_frontal';
    }

    if (str_contains($key, 'tapein')) {
        return 'tape_in';
    }

    if (str_contains($key, 'sticktip') || str_contains($key, 'prebonded')) {
        return 'stick_tip';
    }

    if (str_contains($key, 'microloop')) {
        return 'micro_loop';
    }

    if (str_contains($key, 'nanoring')) {
        return 'nano_ring';
    }

    if (str_contains($key, 'ponytail') || str_contains($key, 'ponytails') || str_contains($key, 'drawstring')) {
        return 'ponytail';
    }

    if (str_contains($key, 'scrunch')) {
        return 'scrunchie';
    }

    if (str_contains($key, 'bun')) {
        return 'bun';
    }

    if (str_contains($key, 'fringe') || str_contains($key, 'bang')) {
        return 'fringe';
    }

    if (str_contains($key, 'wig')) {
        return 'wig';
    }

    if (str_contains($key, 'crochet')) {
        return 'crochet';
    }

    if (str_contains($key, 'bulk')) {
        return 'bulk';
    }

    if (str_contains($key, 'weave') || str_contains($key, 'weaving')) {
        return 'weave';
    }

    if (str_contains($key, 'braid') || str_contains($key, 'braiding')) {
        return 'braid';
    }

    return $key;
}

function mif_style_url(BrandCatalogue $catalogue, BrandCatalogueStyle $style): string
{
    $style->loadMissing('productType.line');
    $productType = $style->productType;
    $line = $productType?->line;

    if (! $productType || ! $line) {
        return '';
    }

    return route('brand-catalogue.styles.show', [
        $catalogue,
        $style->brand_catalogue_brand_id,
        $line->id,
        $productType->id,
        $style->id,
    ]);
}

function mif_backup($brands, string $timestamp): ?string
{
    if ($brands->isEmpty()) {
        return null;
    }

    $brandIds = $brands->pluck('id');
    $styleIds = DB::table('brand_catalogue_styles')
        ->whereIn('brand_catalogue_brand_id', $brandIds)
        ->pluck('id');
    $variantIds = DB::table('brand_catalogue_variants')
        ->whereIn('brand_catalogue_style_id', $styleIds)
        ->pluck('id');
    $skuIds = DB::table('brand_catalogue_skus')
        ->whereIn('brand_catalogue_style_id', $styleIds)
        ->pluck('id');
    $productTypeIds = DB::table('brand_catalogue_product_types')
        ->whereIn('brand_catalogue_brand_id', $brandIds)
        ->pluck('id');

    $backup = [
        'brands' => DB::table('brand_catalogue_brands')->whereIn('id', $brandIds)->get(),
        'lines' => DB::table('brand_catalogue_lines')->whereIn('brand_catalogue_brand_id', $brandIds)->get(),
        'product_types' => DB::table('brand_catalogue_product_types')->whereIn('brand_catalogue_brand_id', $brandIds)->get(),
        'materials' => DB::table('brand_catalogue_materials')->whereIn('brand_catalogue_product_type_id', $productTypeIds)->get(),
        'styles' => DB::table('brand_catalogue_styles')->whereIn('brand_catalogue_brand_id', $brandIds)->get(),
        'variants' => DB::table('brand_catalogue_variants')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'variant_options' => DB::table('brand_catalogue_variant_options')->whereIn('variant_id', $variantIds)->get(),
        'skus' => DB::table('brand_catalogue_skus')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'sku_variant_options' => DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_sku_id', $skuIds)->get(),
        'catalogue_images' => DB::table('catalogue_images')
            ->where('imageable_type', BrandCatalogueStyle::class)
            ->whereIn('imageable_id', $styleIds)
            ->get(),
        'intakes' => DB::table('hair_extension_intakes')->whereIn('brand_catalogue_brand_id', $brandIds)->get(),
        'product_families' => DB::table('product_families')->whereIn('brand_catalogue_brand_id', $brandIds)->get(),
    ];

    $path = "catalogue-backups/hair-catalogue-imported-floor-merge-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function mif_option_key(BrandCatalogueVariantOption $option): string
{
    return mif_norm($option->label.'|'.$option->value);
}

function mif_update_sku_signature(BrandCatalogueSku $sku): void
{
    $pairs = DB::table('brand_catalogue_sku_variant_options')
        ->where('brand_catalogue_sku_id', $sku->id)
        ->orderBy('brand_catalogue_variant_id')
        ->orderBy('brand_catalogue_variant_option_id')
        ->get(['brand_catalogue_variant_id', 'brand_catalogue_variant_option_id'])
        ->map(fn (object $row): string => "{$row->brand_catalogue_variant_id}:{$row->brand_catalogue_variant_option_id}")
        ->all();

    if ($pairs === []) {
        return;
    }

    $sku->update([
        'option_signature' => implode('|', $pairs),
        'updated_at' => now(),
    ]);
}

function mif_merge_variant_options(BrandCatalogueStyle $source, BrandCatalogueStyle $target): array
{
    $stats = [
        'variants_moved' => 0,
        'variants_merged' => 0,
        'options_moved' => 0,
        'options_merged' => 0,
    ];

    $source->load('variants.options');
    $target->load('variants.options');

    foreach ($source->variants as $sourceVariant) {
        $targetVariant = $target->variants->first(function (BrandCatalogueVariant $variant) use ($sourceVariant): bool {
            return mif_norm($variant->name) === mif_norm($sourceVariant->name);
        });

        if (! $targetVariant) {
            $sourceVariant->update([
                'brand_catalogue_style_id' => $target->id,
                'updated_at' => now(),
            ]);
            $stats['variants_moved']++;
            continue;
        }

        foreach ($sourceVariant->options as $sourceOption) {
            $targetOption = $targetVariant->options->first(function (BrandCatalogueVariantOption $option) use ($sourceOption): bool {
                return mif_option_key($option) === mif_option_key($sourceOption);
            });

            if ($targetOption) {
                DB::table('brand_catalogue_sku_variant_options')
                    ->where('brand_catalogue_variant_option_id', $sourceOption->id)
                    ->update([
                        'brand_catalogue_variant_id' => $targetVariant->id,
                        'brand_catalogue_variant_option_id' => $targetOption->id,
                        'updated_at' => now(),
                    ]);
                $sourceOption->delete();
                $stats['options_merged']++;
            } else {
                $sourceOption->update([
                    'variant_id' => $targetVariant->id,
                    'updated_at' => now(),
                ]);
                DB::table('brand_catalogue_sku_variant_options')
                    ->where('brand_catalogue_variant_option_id', $sourceOption->id)
                    ->update([
                        'brand_catalogue_variant_id' => $targetVariant->id,
                        'updated_at' => now(),
                    ]);
                $stats['options_moved']++;
            }
        }

        if (! $sourceVariant->options()->exists()) {
            $sourceVariant->delete();
        }
        $stats['variants_merged']++;
    }

    return $stats;
}

function mif_merge_style(BrandCatalogue $catalogue, BrandCatalogueStyle $source, BrandCatalogueStyle $target): array
{
    $source->loadMissing('productType.line', 'variants.options', 'skus');
    $target->loadMissing('productType.line', 'variants.options', 'skus');

    $variantStats = mif_merge_variant_options($source, $target);

    $skuIds = BrandCatalogueSku::query()
        ->where('brand_catalogue_style_id', $source->id)
        ->pluck('id')
        ->all();

    BrandCatalogueSku::query()
        ->whereIn('id', $skuIds)
        ->update([
            'brand_catalogue_style_id' => $target->id,
            'updated_at' => now(),
        ]);

    BrandCatalogueSku::query()
        ->whereIn('id', $skuIds)
        ->get()
        ->each(fn (BrandCatalogueSku $sku) => mif_update_sku_signature($sku));

    $imageCount = DB::table('catalogue_images')
        ->where('imageable_type', BrandCatalogueStyle::class)
        ->where('imageable_id', $source->id)
        ->update([
            'imageable_id' => $target->id,
            'updated_at' => now(),
        ]);

    ProductFamily::query()
        ->where('brand_catalogue_style_id', $source->id)
        ->update([
            'brand_catalogue_line_id' => $target->productType?->line?->id,
            'brand_catalogue_product_type_id' => $target->brand_catalogue_product_type_id,
            'brand_catalogue_style_id' => $target->id,
            'line_name' => $target->productType?->line?->name,
            'product_type_name' => $target->productType?->name,
            'updated_at' => now(),
        ]);

    $note = trim((string) $target->note);
    $mergeNote = "Merged imported style #{$source->id} ({$source->name}) into this shop-floor style on ".date('Y-m-d').'.';
    if (! str_contains($note, $mergeNote)) {
        $target->update([
            'note' => trim($note."\n".$mergeNote),
            'updated_at' => now(),
        ]);
    }

    $sourceProductType = $source->productType;
    $sourceLine = $sourceProductType?->line;

    $source->delete();

    if ($sourceProductType && ! $sourceProductType->styles()->exists()) {
        $sourceProductType->delete();
    }

    if ($sourceLine && ! $sourceLine->is_default && ! $sourceLine->productTypes()->exists()) {
        $sourceLine->delete();
    }

    return [
        'variants_moved' => $variantStats['variants_moved'],
        'variants_merged' => $variantStats['variants_merged'],
        'options_moved' => $variantStats['options_moved'],
        'options_merged' => $variantStats['options_merged'],
        'skus_moved' => count($skuIds),
        'images_moved' => (int) $imageCount,
        'target_url' => mif_style_url($catalogue, $target->fresh('productType.line')),
    ];
}

function mif_candidate_pairs(BrandCatalogue $catalogue, BrandCatalogueBrand $brand): array
{
    $styles = BrandCatalogueStyle::query()
        ->with('productType.line')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->get()
        ->map(function (BrandCatalogueStyle $style) use ($brand): array {
            $intakeCount = DB::table('hair_extension_intakes')
                ->where('status', 'submitted')
                ->where('brand_catalogue_style_id', $style->id)
                ->count();
            $skuCount = DB::table('brand_catalogue_skus')
                ->where('brand_catalogue_style_id', $style->id)
                ->count();
            $variantCount = DB::table('brand_catalogue_variants')
                ->where('brand_catalogue_style_id', $style->id)
                ->count();

            return [
                'style' => $style,
                'core' => mif_family_core($style->name, $brand->name),
                'intake_count' => $intakeCount,
                'sku_count' => $skuCount,
                'variant_count' => $variantCount,
            ];
        });

    $targetsByCore = $styles
        ->filter(fn (array $row): bool => $row['intake_count'] > 0)
        ->groupBy('core');

    $pairs = [];

    foreach ($styles->filter(fn (array $row): bool => $row['intake_count'] === 0 && ($row['sku_count'] > 0 || $row['variant_count'] > 0)) as $sourceRow) {
        $targets = $targetsByCore->get($sourceRow['core'], collect());

        if ($targets->count() !== 1) {
            $pairs[] = [
                'status' => $targets->isEmpty() ? 'skip_no_floor_match' : 'skip_ambiguous_floor_match',
                'brand' => $brand,
                'source' => $sourceRow['style'],
                'target' => null,
                'core' => $sourceRow['core'],
                'reason' => $targets->isEmpty()
                    ? 'No V2 shop-floor style has the same normalized family core.'
                    : 'More than one V2 shop-floor style has the same normalized family core.',
            ];
            continue;
        }

        $targetRow = $targets->first();
        $source = $sourceRow['style'];
        $target = $targetRow['style'];
        $sourceTypeGroup = mif_type_group($source->productType?->name);
        $targetTypeGroup = mif_type_group($target->productType?->name);

        if ($sourceTypeGroup !== $targetTypeGroup) {
            $pairs[] = [
                'status' => 'skip_type_mismatch',
                'brand' => $brand,
                'source' => $source,
                'target' => $target,
                'core' => $sourceRow['core'],
                'reason' => "Same normalized family core, but product type groups differ ({$sourceTypeGroup} vs {$targetTypeGroup}). This must be reviewed manually.",
            ];
            continue;
        }

        if ((int) $source->id === (int) $target->id) {
            continue;
        }

        $pairs[] = [
            'status' => 'merge',
            'brand' => $brand,
            'source' => $source,
            'target' => $target,
            'core' => $sourceRow['core'],
            'reason' => "Same brand and normalized family core '{$sourceRow['core']}'; source has imported SKUs/variants and no shop-floor intake; target has {$targetRow['intake_count']} V2 shop-floor intake(s).",
        ];
    }

    return $pairs;
}

$catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();
$brandQuery = BrandCatalogueBrand::query()
    ->where('brand_catalogue_id', $catalogue->id)
    ->whereIn('name', $requestedBrand ? [$requestedBrand] : $majorBrandNames)
    ->orderByRaw('FIELD(name, "'.implode('","', array_map('addslashes', $majorBrandNames)).'")');

$brands = $brandQuery->get();

$backupPath = null;
$rows = [];
$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'brands_checked' => $brands->count(),
    'merges' => 0,
    'skipped_no_floor_match' => 0,
    'skipped_ambiguous_floor_match' => 0,
    'skipped_type_mismatch' => 0,
    'skus_moved' => 0,
    'variants_moved' => 0,
    'options_moved' => 0,
    'images_moved' => 0,
    'backup' => null,
];

if ($apply) {
    $backupPath = mif_backup($brands, $timestamp);
    $summary['backup'] = $backupPath;
}

DB::transaction(function () use ($catalogue, $brands, $apply, &$rows, &$summary): void {
    foreach ($brands as $brand) {
        foreach (mif_candidate_pairs($catalogue, $brand) as $candidate) {
            /** @var BrandCatalogueStyle $source */
            $source = $candidate['source'];
            /** @var BrandCatalogueStyle|null $target */
            $target = $candidate['target'];

            $row = [
                'status' => $candidate['status'],
                'brand_id' => $brand->id,
                'brand' => $brand->name,
                'core' => $candidate['core'],
                'source_style_id' => $source->id,
                'source_line' => $source->productType?->line?->name,
                'source_type' => $source->productType?->name,
                'source_style' => $source->name,
                'target_style_id' => $target?->id,
                'target_line' => $target?->productType?->line?->name,
                'target_type' => $target?->productType?->name,
                'target_style' => $target?->name,
                'skus_moved' => 0,
                'variants_moved' => 0,
                'variants_merged' => 0,
                'options_moved' => 0,
                'options_merged' => 0,
                'images_moved' => 0,
                'target_url' => $target ? mif_style_url($catalogue, $target) : '',
                'reason' => $candidate['reason'],
            ];

            if ($candidate['status'] !== 'merge' || ! $target) {
                if ($candidate['status'] === 'skip_no_floor_match') {
                    $summary['skipped_no_floor_match']++;
                } elseif ($candidate['status'] === 'skip_ambiguous_floor_match') {
                    $summary['skipped_ambiguous_floor_match']++;
                } elseif ($candidate['status'] === 'skip_type_mismatch') {
                    $summary['skipped_type_mismatch']++;
                }

                $rows[] = $row;
                continue;
            }

            $summary['merges']++;

            if ($apply) {
                $stats = mif_merge_style($catalogue, $source, $target);
                foreach (['skus_moved', 'variants_moved', 'variants_merged', 'options_moved', 'options_merged', 'images_moved', 'target_url'] as $key) {
                    $row[$key] = $stats[$key];
                }
                $summary['skus_moved'] += $stats['skus_moved'];
                $summary['variants_moved'] += $stats['variants_moved'] + $stats['variants_merged'];
                $summary['options_moved'] += $stats['options_moved'] + $stats['options_merged'];
                $summary['images_moved'] += $stats['images_moved'];
                $row['status'] = 'merged';
            }

            $rows[] = $row;
        }
    }

    if (! $apply) {
        DB::rollBack();
    }
});

$handle = fopen($csvPath, 'w');
fputcsv($handle, [
    'status',
    'brand_id',
    'brand',
    'core',
    'source_style_id',
    'source_line',
    'source_type',
    'source_style',
    'target_style_id',
    'target_line',
    'target_type',
    'target_style',
    'skus_moved',
    'variants_moved',
    'variants_merged',
    'options_moved',
    'options_merged',
    'images_moved',
    'target_url',
    'reason',
]);

foreach ($rows as $row) {
    fputcsv($handle, $row);
}
fclose($handle);
copy($csvPath, $latestCsvPath);

echo "Hair catalogue imported-to-floor merge {$summary['mode']}.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: ".($value ?? 'none')."\n";
}
echo "report: {$csvPath}\n";
echo "latest: {$latestCsvPath}\n";
if (! $apply) {
    echo "Run with --apply to perform only the rows marked merge.\n";
}
