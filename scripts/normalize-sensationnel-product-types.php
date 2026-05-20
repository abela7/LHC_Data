<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueProductType;
use App\Models\ProductFamily;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$apply = in_array('--apply', $argv, true);
$timestamp = date('Ymd-His');

function spt_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function spt_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $i = 2;

    while (
        DB::table($table)
            ->where($scopeColumn, $scopeId)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
    ) {
        $slug = "{$base}-{$i}";
        $i++;
    }

    return $slug;
}

function spt_backup(BrandCatalogueBrand $brand, string $timestamp): string
{
    $styleIds = DB::table('brand_catalogue_styles')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->pluck('id');
    $variantIds = DB::table('brand_catalogue_variants')
        ->whereIn('brand_catalogue_style_id', $styleIds)
        ->pluck('id');
    $skuIds = DB::table('brand_catalogue_skus')
        ->whereIn('brand_catalogue_style_id', $styleIds)
        ->pluck('id');

    $backup = [
        'brand' => $brand->toArray(),
        'lines' => DB::table('brand_catalogue_lines')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'product_types' => DB::table('brand_catalogue_product_types')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'materials' => DB::table('brand_catalogue_materials')->whereIn('brand_catalogue_product_type_id', DB::table('brand_catalogue_product_types')->where('brand_catalogue_brand_id', $brand->id)->pluck('id'))->get(),
        'styles' => DB::table('brand_catalogue_styles')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'variants' => DB::table('brand_catalogue_variants')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'variant_options' => DB::table('brand_catalogue_variant_options')->whereIn('variant_id', $variantIds)->get(),
        'skus' => DB::table('brand_catalogue_skus')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'sku_variant_options' => DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_sku_id', $skuIds)->get(),
        'intakes' => DB::table('hair_extension_intakes')->where('brand_name', 'Sensationnel')->orWhere('brand_catalogue_brand_id', $brand->id)->get(),
        'product_families' => DB::table('product_families')->where('brand_catalogue_brand_id', $brand->id)->orWhere('brand_name', 'Sensationnel')->get(),
    ];

    $path = "catalogue-backups/sensationnel-product-type-normalization-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function spt_move_styles(BrandCatalogueProductType $from, BrandCatalogueProductType $to, bool $apply, array &$stats): void
{
    $styleRows = DB::table('brand_catalogue_styles')
        ->where('brand_catalogue_product_type_id', $from->id)
        ->orderBy('id')
        ->get(['id', 'name']);

    $stats['styles_moved'] += $styleRows->count();

    if (! $apply) {
        return;
    }

    foreach ($styleRows as $style) {
        DB::table('brand_catalogue_styles')
            ->where('id', $style->id)
            ->update([
                'brand_catalogue_product_type_id' => $to->id,
                'slug' => spt_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $to->id, $style->name, $style->id),
                'updated_at' => now(),
            ]);

        ProductFamily::query()
            ->where('brand_catalogue_style_id', $style->id)
            ->update([
                'brand_catalogue_product_type_id' => $to->id,
                'product_type_name' => $to->name,
                'updated_at' => now(),
            ]);
    }

    DB::table('hair_extension_intakes')
        ->where('brand_catalogue_product_type_id', $from->id)
        ->update([
            'brand_catalogue_product_type_id' => $to->id,
            'product_type_name' => $to->name,
            'updated_at' => now(),
        ]);

    DB::table('brand_catalogue_materials')
        ->where('brand_catalogue_product_type_id', $from->id)
        ->update([
            'brand_catalogue_product_type_id' => $to->id,
            'updated_at' => now(),
        ]);
}

function spt_delete_empty_type(BrandCatalogueProductType $type, bool $apply, array &$stats): void
{
    $hasStyles = DB::table('brand_catalogue_styles')->where('brand_catalogue_product_type_id', $type->id)->exists();
    $hasFamilies = DB::table('product_families')->where('brand_catalogue_product_type_id', $type->id)->exists();
    $hasIntakes = DB::table('hair_extension_intakes')->where('brand_catalogue_product_type_id', $type->id)->exists();
    $hasMaterials = DB::table('brand_catalogue_materials')->where('brand_catalogue_product_type_id', $type->id)->exists();

    if ($hasStyles || $hasFamilies || $hasIntakes || $hasMaterials) {
        $stats['aliases_kept_not_empty']++;

        return;
    }

    $stats['aliases_deleted']++;

    if ($apply) {
        $type->delete();
    }
}

$brand = BrandCatalogueBrand::query()
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Sensationnel')
    ->firstOrFail();

$stats = [
    'lines_checked' => 0,
    'types_renamed_to_weave' => 0,
    'aliases_merged' => 0,
    'aliases_deleted' => 0,
    'aliases_kept_not_empty' => 0,
    'styles_moved' => 0,
    'backup' => null,
];

if ($apply) {
    $stats['backup'] = spt_backup($brand, $timestamp);
}

$rows = [];
$aliasNames = ['Weaves', 'Short Weaves', 'Human Hair Weave'];

DB::transaction(function () use ($brand, $apply, $aliasNames, &$stats, &$rows): void {
    $lines = DB::table('brand_catalogue_lines')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->orderBy('name')
        ->get(['id', 'name']);

    foreach ($lines as $line) {
        $stats['lines_checked']++;

        $types = BrandCatalogueProductType::query()
            ->where('brand_catalogue_brand_id', $brand->id)
            ->where('brand_catalogue_line_id', $line->id)
            ->orderBy('id')
            ->get();

        $target = $types->first(fn (BrandCatalogueProductType $type): bool => Str::lower($type->name) === 'weave');
        $aliases = $types->filter(fn (BrandCatalogueProductType $type): bool => in_array($type->name, $aliasNames, true))->values();

        if ($aliases->isEmpty()) {
            continue;
        }

        if (! $target) {
            $target = $aliases->first(fn (BrandCatalogueProductType $type): bool => $type->name === 'Weaves')
                ?? $aliases->first();

            $stats['types_renamed_to_weave']++;
            $rows[] = [
                'line' => $line->name,
                'action' => 'rename',
                'from_type_id' => $target->id,
                'from_type' => $target->name,
                'to_type_id' => $target->id,
                'to_type' => 'Weave',
                'styles_moved' => 0,
            ];

            if ($apply) {
                $target->update([
                    'name' => 'Weave',
                    'slug' => spt_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', (int) $line->id, 'Weave', $target->id),
                ]);

                ProductFamily::query()
                    ->where('brand_catalogue_product_type_id', $target->id)
                    ->update([
                        'product_type_name' => 'Weave',
                        'updated_at' => now(),
                    ]);

                DB::table('hair_extension_intakes')
                    ->where('brand_catalogue_product_type_id', $target->id)
                    ->update([
                        'product_type_name' => 'Weave',
                        'updated_at' => now(),
                    ]);

                $target = $target->fresh();
            } else {
                $target->name = 'Weave';
            }
        }

        foreach ($aliases as $alias) {
            if ((int) $alias->id === (int) $target->id) {
                continue;
            }

            $before = $stats['styles_moved'];
            spt_move_styles($alias, $target, $apply, $stats);
            $moved = $stats['styles_moved'] - $before;
            $stats['aliases_merged']++;

            $rows[] = [
                'line' => $line->name,
                'action' => 'merge',
                'from_type_id' => $alias->id,
                'from_type' => $alias->name,
                'to_type_id' => $target->id,
                'to_type' => 'Weave',
                'styles_moved' => $moved,
            ];

            spt_delete_empty_type($alias, $apply, $stats);
        }
    }
});

$reportDir = public_path('reports');
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}
$csvPath = $reportDir."/sensationnel-product-type-normalization-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/sensationnel-product-type-normalization-latest.csv';
$csv = fopen($csvPath, 'w');
fputcsv($csv, ['line', 'action', 'from_type_id', 'from_type', 'to_type_id', 'to_type', 'styles_moved']);
foreach ($rows as $row) {
    fputcsv($csv, $row);
}
fclose($csv);
copy($csvPath, $latestCsvPath);

echo json_encode([
    'mode' => $apply ? 'applied' : 'dry_run',
    'stats' => $stats,
    'csv' => $csvPath,
    'latest_csv' => $latestCsvPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

if (! $apply) {
    echo "Run with --apply to write these changes.\n";
}
