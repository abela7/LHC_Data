<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueLine;
use App\Models\BrandCatalogueProductType;
use App\Models\ProductFamily;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$apply = in_array('--apply', $argv, true);
$timestamp = date('Ymd-His');

function sbt_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
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

function sbt_backup(BrandCatalogueBrand $brand, string $timestamp): string
{
    $styleIds = DB::table('brand_catalogue_styles')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->pluck('id');
    $productTypeIds = DB::table('brand_catalogue_product_types')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->pluck('id');

    $backup = [
        'brand' => $brand->toArray(),
        'lines' => DB::table('brand_catalogue_lines')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'product_types' => DB::table('brand_catalogue_product_types')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'materials' => DB::table('brand_catalogue_materials')->whereIn('brand_catalogue_product_type_id', $productTypeIds)->get(),
        'styles' => DB::table('brand_catalogue_styles')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'intakes' => DB::table('hair_extension_intakes')->where('brand_name', 'Sensationnel')->orWhere('brand_catalogue_brand_id', $brand->id)->get(),
        'product_families' => DB::table('product_families')->where('brand_catalogue_brand_id', $brand->id)->orWhere('brand_name', 'Sensationnel')->get(),
    ];

    $path = "catalogue-backups/sensationnel-braid-type-normalization-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function sbt_style_count(BrandCatalogueProductType $type): int
{
    return DB::table('brand_catalogue_styles')->where('brand_catalogue_product_type_id', $type->id)->count();
}

function sbt_delete_if_empty(BrandCatalogueProductType $type, bool $apply, array &$stats): void
{
    $hasStyles = DB::table('brand_catalogue_styles')->where('brand_catalogue_product_type_id', $type->id)->exists();
    $hasFamilies = DB::table('product_families')->where('brand_catalogue_product_type_id', $type->id)->exists();
    $hasIntakes = DB::table('hair_extension_intakes')->where('brand_catalogue_product_type_id', $type->id)->exists();
    $hasMaterials = DB::table('brand_catalogue_materials')->where('brand_catalogue_product_type_id', $type->id)->exists();

    if ($hasStyles || $hasFamilies || $hasIntakes || $hasMaterials) {
        $stats['types_kept_not_empty']++;

        return;
    }

    $stats['types_deleted']++;
    if ($apply) {
        $type->delete();
    }
}

function sbt_delete_empty_line(BrandCatalogueLine $line, bool $apply, array &$stats): void
{
    $hasTypes = DB::table('brand_catalogue_product_types')->where('brand_catalogue_line_id', $line->id)->exists();
    $hasFamilies = DB::table('product_families')->where('brand_catalogue_line_id', $line->id)->exists();

    if ($hasTypes || $hasFamilies) {
        return;
    }

    $stats['empty_lines_deleted']++;
    if ($apply) {
        $line->delete();
    }
}

$brand = BrandCatalogueBrand::query()
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Sensationnel')
    ->firstOrFail();

$stats = [
    'types_renamed_to_braid' => 0,
    'types_merged_to_braid' => 0,
    'types_deleted' => 0,
    'types_kept_not_empty' => 0,
    'styles_moved' => 0,
    'empty_lines_deleted' => 0,
    'backup' => null,
];

if ($apply) {
    $stats['backup'] = sbt_backup($brand, $timestamp);
}

$rows = [];
$aliases = ['Braiding Hair', 'Synthetic Braiding Hair', 'Twist Hair'];

DB::transaction(function () use ($brand, $apply, $aliases, &$stats, &$rows): void {
    $lines = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->orderBy('name')
        ->get();

    foreach ($lines as $line) {
        $types = BrandCatalogueProductType::query()
            ->where('brand_catalogue_brand_id', $brand->id)
            ->where('brand_catalogue_line_id', $line->id)
            ->orderBy('id')
            ->get();

        $aliasTypes = $types
            ->filter(fn (BrandCatalogueProductType $type): bool => in_array($type->name, $aliases, true))
            ->values();

        if ($aliasTypes->isEmpty()) {
            continue;
        }

        $target = $types->first(fn (BrandCatalogueProductType $type): bool => Str::lower($type->name) === 'braid');
        $aliasWithStyles = $aliasTypes
            ->sortByDesc(fn (BrandCatalogueProductType $type): int => sbt_style_count($type))
            ->first();

        if (! $target && sbt_style_count($aliasWithStyles) > 0) {
            $target = $aliasWithStyles;
            $stats['types_renamed_to_braid']++;
            $rows[] = [$line->name, 'rename', $target->id, $target->name, $target->id, 'Braid', 0];

            if ($apply) {
                $target->update([
                    'name' => 'Braid',
                    'slug' => sbt_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', $line->id, 'Braid', $target->id),
                ]);

                ProductFamily::query()
                    ->where('brand_catalogue_product_type_id', $target->id)
                    ->update([
                        'product_type_name' => 'Braid',
                        'updated_at' => now(),
                    ]);

                DB::table('hair_extension_intakes')
                    ->where('brand_catalogue_product_type_id', $target->id)
                    ->update([
                        'product_type_name' => 'Braid',
                        'updated_at' => now(),
                    ]);

                $target = $target->fresh();
            } else {
                $target->name = 'Braid';
            }
        }

        foreach ($aliasTypes as $alias) {
            if ($target && (int) $alias->id === (int) $target->id) {
                continue;
            }

            $styleRows = DB::table('brand_catalogue_styles')
                ->where('brand_catalogue_product_type_id', $alias->id)
                ->orderBy('id')
                ->get(['id', 'name']);

            if ($target && $styleRows->isNotEmpty()) {
                $stats['types_merged_to_braid']++;
                $stats['styles_moved'] += $styleRows->count();
                $rows[] = [$line->name, 'merge', $alias->id, $alias->name, $target->id, 'Braid', $styleRows->count()];

                if ($apply) {
                    foreach ($styleRows as $style) {
                        DB::table('brand_catalogue_styles')
                            ->where('id', $style->id)
                            ->update([
                                'brand_catalogue_product_type_id' => $target->id,
                                'slug' => sbt_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $target->id, $style->name, $style->id),
                                'updated_at' => now(),
                            ]);

                        ProductFamily::query()
                            ->where('brand_catalogue_style_id', $style->id)
                            ->update([
                                'brand_catalogue_product_type_id' => $target->id,
                                'product_type_name' => 'Braid',
                                'updated_at' => now(),
                            ]);
                    }
                }
            } else {
                $rows[] = [$line->name, 'delete-empty', $alias->id, $alias->name, $target?->id ?: '', $target ? 'Braid' : '', 0];
            }

            if ($apply && $target) {
                DB::table('brand_catalogue_materials')
                    ->where('brand_catalogue_product_type_id', $alias->id)
                    ->update([
                        'brand_catalogue_product_type_id' => $target->id,
                        'updated_at' => now(),
                    ]);

                DB::table('hair_extension_intakes')
                    ->where('brand_catalogue_product_type_id', $alias->id)
                    ->update([
                        'brand_catalogue_product_type_id' => $target->id,
                        'product_type_name' => 'Braid',
                        'updated_at' => now(),
                    ]);
            }

            sbt_delete_if_empty($alias, $apply, $stats);
        }

        if ($apply) {
            sbt_delete_empty_line($line->fresh(), $apply, $stats);
        } else {
            sbt_delete_empty_line($line, $apply, $stats);
        }
    }
});

$reportDir = public_path('reports');
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}
$csvPath = $reportDir."/sensationnel-braid-type-normalization-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/sensationnel-braid-type-normalization-latest.csv';
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
