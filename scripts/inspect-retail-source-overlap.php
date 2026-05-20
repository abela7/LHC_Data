<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function retail_overlap_key(?string $value): string
{
    return Str::of((string) $value)
        ->lower()
        ->replace('&', ' and ')
        ->replaceMatches('/[^a-z0-9]+/', ' ')
        ->squish()
        ->toString();
}

$rows = DB::table('product_sources as ps')
    ->join('product_families as pf', 'pf.id', '=', 'ps.product_family_id')
    ->leftJoin('products as p', 'p.id', '=', 'ps.product_id')
    ->whereIn('ps.source_type', ['janson_product', 'mamado_product'])
    ->where(function ($query): void {
        $query->whereNull('pf.root_catalogue_name')
            ->orWhere('pf.root_catalogue_name', '<>', 'Hair Extensions');
    })
    ->select([
        'ps.id as source_link_id',
        'ps.source_type',
        'ps.source_id',
        'ps.product_family_id',
        'ps.product_id',
        'pf.brand_name',
        'pf.family_name',
        'p.name as product_name',
        'p.sku',
        'p.barcode',
        'p.status',
        'p.description',
    ])
    ->get();

$retailBase = DB::table('product_sources as ps')
    ->join('product_families as pf', 'pf.id', '=', 'ps.product_family_id')
    ->whereIn('ps.source_type', ['janson_product', 'mamado_product'])
    ->whereNotNull('ps.product_id')
    ->where(function ($query): void {
        $query->whereNull('pf.root_catalogue_name')
            ->orWhere('pf.root_catalogue_name', '<>', 'Hair Extensions');
    });

$productGroups = $rows
    ->whereNotNull('product_id')
    ->groupBy(fn ($row) => retail_overlap_key($row->brand_name).'|'.retail_overlap_key($row->product_name));

$productBoth = $productGroups->filter(fn ($group) => $group->pluck('source_type')->unique()->count() > 1);
$productDuplicate = $productBoth->filter(fn ($group) => $group->pluck('product_id')->unique()->count() > 1);
$productAlreadyOne = $productBoth->filter(fn ($group) => $group->pluck('product_id')->unique()->count() === 1);

$familyGroups = $rows->groupBy(fn ($row) => retail_overlap_key($row->brand_name).'|'.retail_overlap_key($row->family_name));
$familyBoth = $familyGroups->filter(fn ($group) => $group->pluck('source_type')->unique()->count() > 1);
$familyDuplicate = $familyBoth->filter(fn ($group) => $group->pluck('product_family_id')->unique()->count() > 1);
$familyAlreadyOne = $familyBoth->filter(fn ($group) => $group->pluck('product_family_id')->unique()->count() === 1);

$brandGroups = $rows->groupBy(fn ($row) => retail_overlap_key($row->brand_name));
$brandNameVariance = $brandGroups->filter(fn ($group) => $group->pluck('brand_name')->unique()->count() > 1);

echo "Total source-linked retail rows: {$rows->count()}".PHP_EOL;
echo 'All source-linked SKU candidates: '.(clone $retailBase)->distinct('ps.product_id')->count('ps.product_id').PHP_EOL;
echo 'All source-linked families: '.(clone $retailBase)->distinct('ps.product_family_id')->count('ps.product_family_id').PHP_EOL;
echo 'All raw source brand names: '.(clone $retailBase)->distinct('pf.brand_name')->count('pf.brand_name').PHP_EOL;
echo 'Janson SKU candidates: '.(clone $retailBase)->where('ps.source_type', 'janson_product')->distinct('ps.product_id')->count('ps.product_id').PHP_EOL;
echo 'Mamado SKU candidates: '.(clone $retailBase)->where('ps.source_type', 'mamado_product')->distinct('ps.product_id')->count('ps.product_id').PHP_EOL;
echo "Product exact groups with both sources: {$productBoth->count()}".PHP_EOL;
echo "  Already one product record: {$productAlreadyOne->count()}".PHP_EOL;
echo "  Duplicate product records: {$productDuplicate->count()}".PHP_EOL;
echo "Family exact groups with both sources: {$familyBoth->count()}".PHP_EOL;
echo "  Already one family record: {$familyAlreadyOne->count()}".PHP_EOL;
echo "  Duplicate family records: {$familyDuplicate->count()}".PHP_EOL;
echo "Brand groups with spelling/case variance: {$brandNameVariance->count()}".PHP_EOL.PHP_EOL;

echo "Duplicate product samples:".PHP_EOL;
foreach ($productDuplicate->take(25) as $group) {
    $first = $group->first();

    echo "- {$first->brand_name} :: {$first->product_name}"
        ." | products=".$group->pluck('product_id')->unique()->implode(',')
        ." | families=".$group->pluck('product_family_id')->unique()->implode(',')
        ." | sources=".$group->pluck('source_type')->unique()->implode(',')
        .PHP_EOL;
}

echo PHP_EOL."Duplicate family samples:".PHP_EOL;
foreach ($familyDuplicate->take(25) as $group) {
    $first = $group->first();

    echo "- {$first->brand_name} :: {$first->family_name}"
        ." | families=".$group->pluck('product_family_id')->unique()->implode(',')
        ." | products=".$group->pluck('product_id')->filter()->unique()->implode(',')
        ." | sources=".$group->pluck('source_type')->unique()->implode(',')
        .PHP_EOL;

    foreach ($group->sortBy(['source_type', 'product_name', 'source_link_id']) as $row) {
        echo "  - {$row->source_type} product={$row->product_id} source_link={$row->source_link_id} name={$row->product_name}".PHP_EOL;
    }
}

echo PHP_EOL."Duplicate product row details:".PHP_EOL;
foreach ($productDuplicate as $group) {
    $first = $group->first();

    echo "{$first->brand_name} :: {$first->product_name}".PHP_EOL;
    foreach ($group->sortBy(['source_type', 'source_link_id']) as $row) {
        echo "  - source_link={$row->source_link_id} source={$row->source_type} source_id={$row->source_id} product={$row->product_id} family={$row->product_family_id} sku={$row->sku}".PHP_EOL;
    }
}

echo PHP_EOL."Brand spelling/case variance:".PHP_EOL;
foreach ($brandNameVariance as $key => $group) {
    echo "- {$key}: ".$group->pluck('brand_name')->unique()->implode(' | ').PHP_EOL;
}
