<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function brand_compare_key(?string $value): string
{
    return Str::of((string) $value)
        ->ascii()
        ->lower()
        ->replace(['&', '+'], ' and ')
        ->replaceMatches('/\b(?:ltd|limited|inc|llc|co)\b/', ' ')
        ->replaceMatches('/[^a-z0-9]+/', ' ')
        ->squish()
        ->toString();
}

function group_brands(Collection $rows, string $nameColumn): Collection
{
    return $rows
        ->filter(fn (object $row): bool => trim((string) $row->{$nameColumn}) !== '')
        ->groupBy(fn (object $row): string => brand_compare_key((string) $row->{$nameColumn}))
        ->map(function (Collection $rows, string $key) use ($nameColumn): array {
            $names = $rows->pluck($nameColumn)
                ->map(fn ($value): string => trim((string) $value))
                ->filter()
                ->unique()
                ->sortBy(fn (string $name): string => Str::lower($name))
                ->values();

            return [
                'key' => $key,
                'display_name' => $names->first() ?: 'Unknown',
                'names' => $names->all(),
                'products' => (int) $rows->sum('products'),
                'hits' => (int) $rows->sum('hits'),
            ];
        })
        ->sortBy('display_name', SORT_NATURAL | SORT_FLAG_CASE)
        ->values();
}

$pictureRows = DB::table('observed_products')
    ->selectRaw("COALESCE(NULLIF(canonical_brand, ''), brand) as brand_name, COUNT(DISTINCT product_name) as products, COUNT(*) as hits")
    ->groupBy('brand_name')
    ->get();

$sourceRows = DB::table('product_sources as ps')
    ->join('product_families as pf', 'pf.id', '=', 'ps.product_family_id')
    ->whereIn('ps.source_type', ['janson_product', 'mamado_product'])
    ->whereNotNull('ps.product_id')
    ->where(function ($query): void {
        $query->whereNull('pf.root_catalogue_name')
            ->orWhere('pf.root_catalogue_name', '<>', 'Hair Extensions');
    })
    ->selectRaw('pf.brand_name, ps.source_type, COUNT(DISTINCT ps.product_id) as products, COUNT(*) as hits')
    ->groupBy('pf.brand_name', 'ps.source_type')
    ->get();

$pictureBrands = group_brands($pictureRows, 'brand_name')->keyBy('key');
$jansonBrands = group_brands($sourceRows->where('source_type', 'janson_product')->values(), 'brand_name')->keyBy('key');
$mamadoBrands = group_brands($sourceRows->where('source_type', 'mamado_product')->values(), 'brand_name')->keyBy('key');
$supplierBrands = group_brands($sourceRows, 'brand_name')->keyBy('key');

$matchedBoth = $pictureBrands->filter(fn (array $brand, string $key): bool => $jansonBrands->has($key) && $mamadoBrands->has($key));
$matchedJansonOnly = $pictureBrands->filter(fn (array $brand, string $key): bool => $jansonBrands->has($key) && ! $mamadoBrands->has($key));
$matchedMamadoOnly = $pictureBrands->filter(fn (array $brand, string $key): bool => ! $jansonBrands->has($key) && $mamadoBrands->has($key));
$matchedAny = $pictureBrands->filter(fn (array $brand, string $key): bool => $supplierBrands->has($key));
$missingSupplier = $pictureBrands->filter(fn (array $brand, string $key): bool => ! $supplierBrands->has($key));

echo 'picture_brands: '.$pictureBrands->count().PHP_EOL;
echo 'janson_brands: '.$jansonBrands->count().PHP_EOL;
echo 'mamado_brands: '.$mamadoBrands->count().PHP_EOL;
echo 'supplier_combined_brands: '.$supplierBrands->count().PHP_EOL;
echo 'picture_matched_any_supplier: '.$matchedAny->count().PHP_EOL;
echo 'picture_matched_both_janson_mamado: '.$matchedBoth->count().PHP_EOL;
echo 'picture_matched_janson_only: '.$matchedJansonOnly->count().PHP_EOL;
echo 'picture_matched_mamado_only: '.$matchedMamadoOnly->count().PHP_EOL;
echo 'picture_missing_janson_mamado: '.$missingSupplier->count().PHP_EOL;

echo PHP_EOL.'Top picture brands missing Janson/Mamado:'.PHP_EOL;
foreach ($missingSupplier->sortByDesc('products')->take(30) as $brand) {
    echo "- {$brand['display_name']}: {$brand['products']} products, {$brand['hits']} hits".PHP_EOL;
}

echo PHP_EOL.'Top picture brands matched both:'.PHP_EOL;
foreach ($matchedBoth->sortByDesc('products')->take(30) as $brand) {
    echo "- {$brand['display_name']}: {$brand['products']} products, {$brand['hits']} hits".PHP_EOL;
}

