<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$apply = in_array('--apply', $argv, true);
$timestamp = date('Ymd-His');

function kob_slug(string $value): string
{
    return Str::slug($value) ?: 'item';
}

function kob_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
{
    $base = kob_slug($name);
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

function kob_backup(object $brand, string $timestamp): string
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
        'intakes' => DB::table('hair_extension_intakes')->where('brand_catalogue_brand_id', $brand->id)->orWhere('brand_name', 'Kuknus')->get(),
        'product_families' => DB::table('product_families')->where('brand_catalogue_brand_id', $brand->id)->orWhere('brand_name', 'Kuknus')->get(),
    ];

    $path = "catalogue-backups/kuknus-official-braiding-normalization-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function kob_ensure_line(object $brand, string $name, bool $apply): ?object
{
    $line = DB::table('brand_catalogue_lines')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', $name)
        ->first();

    if ($line || ! $apply) {
        return $line;
    }

    $id = DB::table('brand_catalogue_lines')->insertGetId([
        'brand_catalogue_brand_id' => $brand->id,
        'name' => $name,
        'slug' => kob_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $name),
        'note' => 'Created while normalising Kuknus official braiding products into shop-floor structure.',
        'url' => 'https://kuknus.co.uk/index.php?route=product/category&path=17',
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('brand_catalogue_lines')->where('id', $id)->first();
}

function kob_ensure_type(object $brand, object $line, string $name, bool $apply): ?object
{
    $type = DB::table('brand_catalogue_product_types')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('brand_catalogue_line_id', $line->id)
        ->where('name', $name)
        ->first();

    if ($type || ! $apply) {
        return $type;
    }

    $id = DB::table('brand_catalogue_product_types')->insertGetId([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_line_id' => $line->id,
        'name' => $name,
        'slug' => kob_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', (int) $line->id, $name),
        'note' => 'Created while normalising Kuknus official braiding products into shop-floor structure.',
        'url' => 'https://kuknus.co.uk/index.php?route=product/category&path=17',
        'is_active' => true,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('brand_catalogue_product_types')->where('id', $id)->first();
}

$brand = DB::table('brand_catalogue_brands')
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Kuknus')
    ->first();

if (! $brand) {
    throw new RuntimeException('Kuknus brand was not found in brand catalogue 1.');
}

$styleTargets = [
    'Baby Box/Bulk' => 'Bulk Hair',
    'Baby Brazilian' => 'Bulk Hair',
    'Baby Corkscrew Bulk' => 'Bulk Hair',
    'Baby Deep Twist Bulk' => 'Bulk Hair',
    'Baby Dreadlocks Bulk' => 'Bulk Hair',
    'Baby Senegalese Bulk' => 'Bulk Hair',
    'Baby Water Bulk' => 'Bulk Hair',
    'Carribean Bulk' => 'Bulk Hair',
    'Water Bulk' => 'Bulk Hair',
    'Baby Marley Braid' => 'Braid',
    'EZ Braid' => 'Braid',
    'Marley Braid' => 'Braid',
];

$stats = [
    'mode' => $apply ? 'applied' : 'dry_run',
    'styles_planned' => 0,
    'styles_moved' => 0,
    'target_types_created' => 0,
    'old_types_deleted' => 0,
    'backup' => null,
];

$rows = [];

if ($apply) {
    $stats['backup'] = kob_backup($brand, $timestamp);
}

DB::transaction(function () use ($apply, $brand, $styleTargets, &$stats, &$rows): void {
    $oldType = DB::table('brand_catalogue_product_types')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', 'Braiding Hair')
        ->first();

    if (! $oldType) {
        return;
    }

    $targetLine = kob_ensure_line($brand, 'Hair Braiding', $apply);
    if (! $targetLine) {
        throw new RuntimeException('Hair Braiding line is missing. Run with --apply to create it, or run the Kuknus V2 sync first.');
    }

    $targetTypes = [];
    foreach (array_values(array_unique($styleTargets)) as $typeName) {
        $beforeExists = DB::table('brand_catalogue_product_types')
            ->where('brand_catalogue_brand_id', $brand->id)
            ->where('brand_catalogue_line_id', $targetLine->id)
            ->where('name', $typeName)
            ->exists();

        $targetTypes[$typeName] = kob_ensure_type($brand, $targetLine, $typeName, $apply);

        if (! $targetTypes[$typeName]) {
            $targetTypes[$typeName] = (object) [
                'id' => "new:{$typeName}",
                'name' => $typeName,
                'brand_catalogue_line_id' => $targetLine->id,
            ];
        }

        if (! $beforeExists && $apply) {
            $stats['target_types_created']++;
        }
    }

    foreach ($styleTargets as $styleName => $typeName) {
        $style = DB::table('brand_catalogue_styles')
            ->where('brand_catalogue_brand_id', $brand->id)
            ->where('brand_catalogue_product_type_id', $oldType->id)
            ->where('name', $styleName)
            ->first();

        if (! $style) {
            $rows[] = [
                'style_id' => null,
                'style_name' => $styleName,
                'from_type_id' => $oldType->id,
                'from_type' => $oldType->name,
                'to_line_id' => $targetLine->id,
                'to_line' => $targetLine->name,
                'to_type_id' => $targetTypes[$typeName]->id,
                'to_type' => $typeName,
                'result' => 'source_style_not_found',
            ];
            continue;
        }

        $stats['styles_planned']++;
        $targetType = $targetTypes[$typeName];

        $rows[] = [
            'style_id' => $style->id,
            'style_name' => $style->name,
            'from_type_id' => $oldType->id,
            'from_type' => $oldType->name,
            'to_line_id' => $targetLine->id,
            'to_line' => $targetLine->name,
            'to_type_id' => $targetType->id,
            'to_type' => $typeName,
            'result' => 'planned_move',
        ];

        if (! $apply) {
            continue;
        }

        DB::table('brand_catalogue_styles')
            ->where('id', $style->id)
            ->update([
                'brand_catalogue_product_type_id' => $targetType->id,
                'slug' => kob_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $targetType->id, $style->name, (int) $style->id),
                'updated_at' => now(),
            ]);

        DB::table('hair_extension_intakes')
            ->where('brand_catalogue_style_id', $style->id)
            ->update([
                'classification_path' => json_encode(['Hair Braiding']),
                'brand_catalogue_product_type_id' => $targetType->id,
                'product_type_name' => $typeName,
                'updated_at' => now(),
            ]);

        DB::table('product_families')
            ->where('brand_catalogue_style_id', $style->id)
            ->update([
                'brand_catalogue_line_id' => $targetLine->id,
                'brand_catalogue_product_type_id' => $targetType->id,
                'line_name' => 'Hair Braiding',
                'product_type_name' => $typeName,
                'updated_at' => now(),
            ]);

        $stats['styles_moved']++;
    }

    if ($apply) {
        $hasStyles = DB::table('brand_catalogue_styles')->where('brand_catalogue_product_type_id', $oldType->id)->exists();
        $hasIntakes = DB::table('hair_extension_intakes')->where('brand_catalogue_product_type_id', $oldType->id)->exists();
        $hasFamilies = DB::table('product_families')->where('brand_catalogue_product_type_id', $oldType->id)->exists();
        $hasMaterials = DB::table('brand_catalogue_materials')->where('brand_catalogue_product_type_id', $oldType->id)->exists();

        if (! $hasStyles && ! $hasIntakes && ! $hasFamilies && ! $hasMaterials) {
            DB::table('brand_catalogue_product_types')->where('id', $oldType->id)->delete();
            $stats['old_types_deleted']++;
        }
    }
});

$reportDir = public_path('reports');
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$csvPath = $reportDir."/kuknus-official-braiding-normalization-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/kuknus-official-braiding-normalization-latest.csv';
$csv = fopen($csvPath, 'w');
fputcsv($csv, ['style_id', 'style_name', 'from_type_id', 'from_type', 'to_line_id', 'to_line', 'to_type_id', 'to_type', 'result']);
foreach ($rows as $row) {
    fputcsv($csv, $row);
}
fclose($csv);
copy($csvPath, $latestCsvPath);

echo json_encode($stats + [
    'report' => $csvPath,
    'latest_report' => $latestCsvPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
