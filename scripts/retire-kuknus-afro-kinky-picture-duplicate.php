<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$apply = in_array('--apply', $argv, true);
$timestamp = date('Ymd-His');

$duplicateStyleId = 4177;
$officialStyleId = 438;

$brand = DB::table('brand_catalogue_brands')
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Kuknus')
    ->first();

if (! $brand) {
    throw new RuntimeException('Kuknus brand was not found.');
}

$duplicate = DB::table('brand_catalogue_styles as s')
    ->join('brand_catalogue_product_types as pt', 'pt.id', '=', 's.brand_catalogue_product_type_id')
    ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
    ->where('s.id', $duplicateStyleId)
    ->where('s.brand_catalogue_brand_id', $brand->id)
    ->select('s.*', 'pt.name as product_type_name', 'pt.id as product_type_id', 'l.name as line_name', 'l.id as line_id')
    ->first();

$official = DB::table('brand_catalogue_styles as s')
    ->join('brand_catalogue_product_types as pt', 'pt.id', '=', 's.brand_catalogue_product_type_id')
    ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
    ->where('s.id', $officialStyleId)
    ->where('s.brand_catalogue_brand_id', $brand->id)
    ->select('s.*', 'pt.name as product_type_name', 'pt.id as product_type_id', 'l.name as line_name', 'l.id as line_id')
    ->first();

if (! $duplicate || ! $official) {
    echo json_encode([
        'mode' => $apply ? 'applied' : 'dry_run',
        'result' => 'duplicate_or_official_style_missing',
        'duplicate_style_found' => (bool) $duplicate,
        'official_style_found' => (bool) $official,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$linkedIntakes = DB::table('hair_extension_intakes')->where('brand_catalogue_style_id', $duplicateStyleId)->count();
$linkedFamilies = DB::table('product_families')->where('brand_catalogue_style_id', $duplicateStyleId)->count();
$styleImages = DB::table('catalogue_images')
    ->where('imageable_type', 'App\\Models\\BrandCatalogueStyle')
    ->where('imageable_id', $duplicateStyleId)
    ->count();

if ($linkedIntakes > 0 || $linkedFamilies > 0 || $styleImages > 0) {
    echo json_encode([
        'mode' => $apply ? 'applied' : 'dry_run',
        'result' => 'blocked_duplicate_has_live_links',
        'linked_intakes' => $linkedIntakes,
        'linked_families' => $linkedFamilies,
        'style_images' => $styleImages,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
}

$skuIds = DB::table('brand_catalogue_skus')
    ->where('brand_catalogue_style_id', $duplicateStyleId)
    ->pluck('id');
$variantIds = DB::table('brand_catalogue_variants')
    ->where('brand_catalogue_style_id', $duplicateStyleId)
    ->pluck('id');

$backup = [
    'reason' => 'Retiring duplicate Kuknus picture-only Afro Kinky Bulk 24 style. Official source style 438 is retained.',
    'duplicate_style' => $duplicate,
    'official_style' => $official,
    'duplicate_skus' => DB::table('brand_catalogue_skus')->whereIn('id', $skuIds)->get(),
    'duplicate_variants' => DB::table('brand_catalogue_variants')->whereIn('id', $variantIds)->get(),
    'duplicate_variant_options' => DB::table('brand_catalogue_variant_options')->whereIn('variant_id', $variantIds)->get(),
    'duplicate_sku_variant_options' => DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_sku_id', $skuIds)->get(),
    'duplicate_images' => DB::table('catalogue_images')
        ->where(function ($query) use ($duplicateStyleId, $skuIds) {
            $query->where(function ($styleQuery) use ($duplicateStyleId) {
                $styleQuery->where('imageable_type', 'App\\Models\\BrandCatalogueStyle')
                    ->where('imageable_id', $duplicateStyleId);
            })->orWhere(function ($skuQuery) use ($skuIds) {
                $skuQuery->where('imageable_type', 'App\\Models\\BrandCatalogueSku')
                    ->whereIn('imageable_id', $skuIds);
            });
        })
        ->get(),
];

$backupPath = null;
if ($apply) {
    $path = "catalogue-backups/kuknus-afro-kinky-picture-duplicate-retired-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $backupPath = Storage::disk('local')->path($path);

    DB::transaction(function () use ($duplicate, $duplicateStyleId, $skuIds, $variantIds): void {
        DB::table('catalogue_images')
            ->where(function ($query) use ($duplicateStyleId, $skuIds) {
                $query->where(function ($styleQuery) use ($duplicateStyleId) {
                    $styleQuery->where('imageable_type', 'App\\Models\\BrandCatalogueStyle')
                        ->where('imageable_id', $duplicateStyleId);
                })->orWhere(function ($skuQuery) use ($skuIds) {
                    $skuQuery->where('imageable_type', 'App\\Models\\BrandCatalogueSku')
                        ->whereIn('imageable_id', $skuIds);
                });
            })
            ->delete();

        DB::table('brand_catalogue_sku_variant_options')
            ->whereIn('brand_catalogue_sku_id', $skuIds)
            ->delete();
        DB::table('brand_catalogue_skus')
            ->whereIn('id', $skuIds)
            ->delete();
        DB::table('brand_catalogue_variant_options')
            ->whereIn('variant_id', $variantIds)
            ->delete();
        DB::table('brand_catalogue_variants')
            ->whereIn('id', $variantIds)
            ->delete();
        DB::table('brand_catalogue_styles')
            ->where('id', $duplicateStyleId)
            ->delete();

        $typeHasStyles = DB::table('brand_catalogue_styles')
            ->where('brand_catalogue_product_type_id', $duplicate->product_type_id)
            ->exists();
        $typeHasFamilies = DB::table('product_families')
            ->where('brand_catalogue_product_type_id', $duplicate->product_type_id)
            ->exists();
        $typeHasIntakes = DB::table('hair_extension_intakes')
            ->where('brand_catalogue_product_type_id', $duplicate->product_type_id)
            ->exists();
        $typeHasMaterials = DB::table('brand_catalogue_materials')
            ->where('brand_catalogue_product_type_id', $duplicate->product_type_id)
            ->exists();

        if (! $typeHasStyles && ! $typeHasFamilies && ! $typeHasIntakes && ! $typeHasMaterials) {
            DB::table('brand_catalogue_product_types')
                ->where('id', $duplicate->product_type_id)
                ->delete();
        }
    });
}

echo json_encode([
    'mode' => $apply ? 'applied' : 'dry_run',
    'result' => $apply ? 'retired_duplicate' : 'would_retire_duplicate',
    'duplicate_style_id' => $duplicateStyleId,
    'official_style_id' => $officialStyleId,
    'duplicate_skus' => $skuIds->count(),
    'duplicate_variants' => $variantIds->count(),
    'backup' => $backupPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
