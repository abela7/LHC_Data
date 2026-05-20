<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$apply = in_array('--apply', $argv, true);
$timestamp = date('Ymd-His');

function kop_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function kop_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $suffix = 2;

    while (
        DB::table($table)
            ->where($scopeColumn, $scopeId)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
    ) {
        $slug = "{$base}-{$suffix}";
        $suffix++;
    }

    return $slug;
}

function kop_backup(object $brand, string $timestamp): string
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
    $productTypeIds = DB::table('brand_catalogue_product_types')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->pluck('id');

    $backup = [
        'brand' => $brand,
        'lines' => DB::table('brand_catalogue_lines')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'product_types' => DB::table('brand_catalogue_product_types')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'materials' => DB::table('brand_catalogue_materials')->whereIn('brand_catalogue_product_type_id', $productTypeIds)->get(),
        'styles' => DB::table('brand_catalogue_styles')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'variants' => DB::table('brand_catalogue_variants')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'variant_options' => DB::table('brand_catalogue_variant_options')->whereIn('variant_id', $variantIds)->get(),
        'skus' => DB::table('brand_catalogue_skus')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'sku_variant_options' => DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_sku_id', $skuIds)->get(),
        'intakes' => DB::table('hair_extension_intakes')->where('brand_name', 'Koko')->orWhere('brand_catalogue_brand_id', $brand->id)->get(),
        'product_families' => DB::table('product_families')->where('brand_catalogue_brand_id', $brand->id)->orWhere('brand_name', 'Koko')->get(),
    ];

    $path = "catalogue-backups/koko-official-product-type-normalization-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function kop_ensure_line(object $brand, string $name, bool $apply, array &$stats, array &$lineCache): object
{
    $key = kop_norm($name);
    if (isset($lineCache[$key])) {
        return $lineCache[$key];
    }

    $line = DB::table('brand_catalogue_lines')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', $name)
        ->first();

    if (! $line && $apply) {
        $id = DB::table('brand_catalogue_lines')->insertGetId([
            'brand_catalogue_brand_id' => $brand->id,
            'name' => $name,
            'slug' => kop_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $name),
            'note' => 'Created while normalising Koko official product types into shop-floor structure.',
            'url' => 'https://koko-hair.co.uk/',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $line = DB::table('brand_catalogue_lines')->where('id', $id)->first();
        $stats['lines_created']++;
    }

    return $lineCache[$key] = ($line ?: (object) ['id' => 0, 'name' => $name]);
}

function kop_ensure_type(object $brand, object $line, string $name, bool $apply, array &$stats, array &$typeCache): object
{
    $key = $line->id.'|'.kop_norm($name);
    if (isset($typeCache[$key])) {
        return $typeCache[$key];
    }

    $type = DB::table('brand_catalogue_product_types')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('brand_catalogue_line_id', $line->id)
        ->where('name', $name)
        ->first();

    if (! $type && $apply) {
        $id = DB::table('brand_catalogue_product_types')->insertGetId([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_line_id' => $line->id,
            'name' => $name,
            'slug' => kop_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', (int) $line->id, $name),
            'note' => 'Created while normalising Koko official product types into shop-floor structure.',
            'url' => $line->url ?? 'https://koko-hair.co.uk/',
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $type = DB::table('brand_catalogue_product_types')->where('id', $id)->first();
        $stats['product_types_created']++;
    }

    return $typeCache[$key] = ($type ?: (object) ['id' => 0, 'name' => $name]);
}

function kop_target_for_style(object $style): ?array
{
    $line = $style->line_name;
    $type = $style->product_type_name;
    $name = $style->style_name;
    $key = kop_norm($name);

    if ($line === 'Ponytails' && $type === 'Synthetic Ponytails') {
        return ['Hair Ponytail', 'Ponytail'];
    }

    if ($line === 'Hair Extensions' && $type === 'Synthetic Clip-In Extensions') {
        return ['Clip-In Hair Extensions', 'Clip-in Extensions'];
    }

    if ($line === 'Dip Dye Collection' && $type === 'Dip Dye Hairpieces') {
        if (str_contains($key, 'ponytail')) {
            return ['Hair Ponytail', 'Ponytail'];
        }
        if (str_contains($key, 'clipin') || str_contains($key, 'extensions')) {
            return ['Clip-In Hair Extensions', 'Clip-in Extensions'];
        }
    }

    if ($line === 'Hair Extensions' && $type === 'Braiding & Bulk Hair') {
        if (str_contains($key, 'bulk')) {
            return ['Hair Braiding', 'Bulk Hair'];
        }
        if (str_contains($key, 'braid')) {
            return ['Hair Braiding', 'Braid'];
        }
    }

    if ($line === 'Hairpieces' && $type === 'Synthetic Buns') {
        return ['Hair Bun', 'Bun'];
    }

    if ($line === 'Hairpieces' && $type === 'Synthetic Fringes') {
        return ['Hair Fringe', 'Bang / Fringe'];
    }

    if ($line === 'Hairpieces' && $type === 'Synthetic Scrunchies') {
        return ['Hair Bun', 'Scrunchie'];
    }

    if ($line === 'Hairpieces' && $type === 'Party Hairpieces') {
        if (str_contains($key, 'clipin') || str_contains($key, 'extension')) {
            return ['Clip-In Hair Extensions', 'Clip-in Extensions'];
        }
        if (str_contains($key, 'braid')) {
            return ['Hair Braiding', 'Braid'];
        }
    }

    return null;
}

function kop_delete_empty_old_buckets(object $brand, bool $apply, array &$stats): void
{
    $types = DB::table('brand_catalogue_product_types')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->orderBy('id')
        ->get();

    foreach ($types as $type) {
        $inUse = DB::table('brand_catalogue_styles')->where('brand_catalogue_product_type_id', $type->id)->exists()
            || DB::table('hair_extension_intakes')->where('brand_catalogue_product_type_id', $type->id)->exists()
            || DB::table('product_families')->where('brand_catalogue_product_type_id', $type->id)->exists()
            || DB::table('brand_catalogue_materials')->where('brand_catalogue_product_type_id', $type->id)->exists();

        if (! $inUse) {
            $stats['empty_product_types_deleted']++;
            if ($apply) {
                DB::table('brand_catalogue_product_types')->where('id', $type->id)->delete();
            }
        }
    }

    $lines = DB::table('brand_catalogue_lines')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->orderBy('id')
        ->get();

    foreach ($lines as $line) {
        $inUse = DB::table('brand_catalogue_product_types')->where('brand_catalogue_line_id', $line->id)->exists()
            || DB::table('product_families')->where('brand_catalogue_line_id', $line->id)->exists();

        if (! $inUse) {
            $stats['empty_lines_deleted']++;
            if ($apply) {
                DB::table('brand_catalogue_lines')->where('id', $line->id)->delete();
            }
        }
    }
}

$brand = DB::table('brand_catalogue_brands')
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Koko')
    ->first();

if (! $brand) {
    throw new RuntimeException('Koko brand was not found.');
}

$stats = [
    'mode' => $apply ? 'applied' : 'dry_run',
    'styles_moved' => 0,
    'lines_created' => 0,
    'product_types_created' => 0,
    'intakes_updated' => 0,
    'product_families_updated' => 0,
    'empty_product_types_deleted' => 0,
    'empty_lines_deleted' => 0,
    'backup' => null,
];

$rows = [];
if ($apply) {
    $stats['backup'] = kop_backup($brand, $timestamp);
}

DB::transaction(function () use ($brand, $apply, &$stats, &$rows): void {
    $lineCache = [];
    $typeCache = [];

    $styles = DB::table('brand_catalogue_styles as s')
        ->join('brand_catalogue_product_types as pt', 'pt.id', '=', 's.brand_catalogue_product_type_id')
        ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
        ->where('s.brand_catalogue_brand_id', $brand->id)
        ->select(
            's.id as style_id',
            's.name as style_name',
            's.brand_catalogue_product_type_id as source_type_id',
            'pt.name as product_type_name',
            'l.name as line_name'
        )
        ->orderBy('s.id')
        ->get();

    foreach ($styles as $style) {
        $target = kop_target_for_style($style);
        if (! $target) {
            continue;
        }

        [$targetLineName, $targetTypeName] = $target;
        $targetLine = kop_ensure_line($brand, $targetLineName, $apply, $stats, $lineCache);
        $targetType = kop_ensure_type($brand, $targetLine, $targetTypeName, $apply, $stats, $typeCache);

        $rows[] = [
            'style_id' => $style->style_id,
            'style_name' => $style->style_name,
            'from_line' => $style->line_name,
            'from_type' => $style->product_type_name,
            'to_line' => $targetLineName,
            'to_type' => $targetTypeName,
        ];

        if (! $apply || (int) $style->source_type_id === (int) $targetType->id) {
            if ((int) $style->source_type_id !== (int) $targetType->id) {
                $stats['styles_moved']++;
            }
            continue;
        }

        DB::table('brand_catalogue_styles')
            ->where('id', $style->style_id)
            ->update([
                'brand_catalogue_product_type_id' => $targetType->id,
                'slug' => kop_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $targetType->id, $style->style_name, (int) $style->style_id),
                'updated_at' => now(),
            ]);

        $stats['styles_moved']++;

        $stats['intakes_updated'] += DB::table('hair_extension_intakes')
            ->where('brand_catalogue_style_id', $style->style_id)
            ->update([
                'classification_path' => json_encode([$targetLineName]),
                'product_type_name' => $targetTypeName,
                'brand_catalogue_product_type_id' => $targetType->id,
                'updated_at' => now(),
            ]);

        $stats['product_families_updated'] += DB::table('product_families')
            ->where('brand_catalogue_style_id', $style->style_id)
            ->update([
                'brand_catalogue_line_id' => $targetLine->id,
                'brand_catalogue_product_type_id' => $targetType->id,
                'line_name' => $targetLineName,
                'product_type_name' => $targetTypeName,
                'updated_at' => now(),
            ]);
    }

    kop_delete_empty_old_buckets($brand, $apply, $stats);
});

$reportDir = public_path('reports');
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$csvPath = $reportDir."/koko-official-product-type-normalization-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/koko-official-product-type-normalization-latest.csv';
$csv = fopen($csvPath, 'w');
fputcsv($csv, ['style_id', 'style_name', 'from_line', 'from_type', 'to_line', 'to_type']);
foreach ($rows as $row) {
    fputcsv($csv, $row);
}
fclose($csv);
copy($csvPath, $latestCsvPath);

echo json_encode($stats + [
    'report' => $csvPath,
    'latest_report' => $latestCsvPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
