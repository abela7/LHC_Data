<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Extra Hair Extensions product status breakdown ===\n\n";

$status = DB::table('products as p')
    ->join('product_families as f', 'f.id', '=', 'p.product_family_id')
    ->where('f.root_catalogue_name', 'Hair Extensions')
    ->select('p.status', DB::raw('COUNT(*) as c'))
    ->groupBy('p.status')
    ->get();
echo "Product status:\n";
foreach ($status as $row) {
    echo "  {$row->status}: {$row->c}\n";
}

$pub = DB::table('products as p')
    ->join('product_families as f', 'f.id', '=', 'p.product_family_id')
    ->leftJoin('product_ecommerce_profiles as e', function ($j): void {
        $j->on('e.product_id', '=', 'p.id')->where('e.profile_level', '=', 'sku');
    })
    ->where('f.root_catalogue_name', 'Hair Extensions')
    ->selectRaw('SUM(CASE WHEN e.is_published = 1 THEN 1 ELSE 0 END) as published')
    ->selectRaw('SUM(CASE WHEN e.is_published = 0 OR e.id IS NULL THEN 1 ELSE 0 END) as not_published')
    ->selectRaw('COUNT(*) as total')
    ->first();
echo "\nEcommerce SKU profile publish:\n";
echo "  published: {$pub->published}\n";
echo "  not published / no profile: {$pub->not_published}\n";
echo "  total products: {$pub->total}\n";

$pos = DB::table('products as p')
    ->join('product_families as f', 'f.id', '=', 'p.product_family_id')
    ->where('f.root_catalogue_name', 'Hair Extensions')
    ->selectRaw('SUM(is_pos_active) as pos_on')
    ->selectRaw('SUM(is_ecommerce_active) as ec_on')
    ->selectRaw('COUNT(*) as total')
    ->first();
echo "\nChannels:\n";
echo "  POS on: {$pos->pos_on} / {$pos->total}\n";
echo "  Ecommerce on: {$pos->ec_on} / {$pos->total}\n";

// Length groups that aren't really inch lengths (Long/Short)
echo "\nNon-inch Length group labels (retail):\n";
$weird = DB::select("
    SELECT o.label, g.name as grp, f.family_name, COUNT(DISTINCT p.id) as sku_count
    FROM product_variant_options o
    JOIN product_variant_groups g ON g.id = o.product_variant_group_id
    JOIN product_families f ON f.id = g.product_family_id
    LEFT JOIN product_variant_values pvv ON pvv.product_variant_option_id = o.id
    LEFT JOIN products p ON p.id = pvv.product_id
    WHERE f.root_catalogue_name = 'Hair Extensions'
      AND (LOWER(g.name) = 'length' OR LOWER(g.name) LIKE '%length%')
      AND o.label NOT REGEXP '^[0-9]+(\\\\.[0-9]+)?\"$'
      AND o.label NOT LIKE '%\"%'
    GROUP BY o.label, g.name, f.family_name
    ORDER BY sku_count DESC
");
foreach ($weird as $row) {
    echo "  [{$row->sku_count} SKUs] {$row->label} ({$row->grp}) — {$row->family_name}\n";
}

// Sample product names with inch but variant ok
echo "\nSample product names still containing 'inch' (variant may already be 18\"):\n";
$samples = DB::table('products as p')
    ->join('product_families as f', 'f.id', '=', 'p.product_family_id')
    ->where('f.root_catalogue_name', 'Hair Extensions')
    ->where(function ($q): void {
        $q->where('p.name', 'like', '% inch%')
            ->orWhere('p.name', 'like', '% Inch%');
    })
    ->select('p.name')
    ->limit(5)
    ->pluck('name');
foreach ($samples as $name) {
    echo "  - {$name}\n";
}

echo "\nPassion Twist name vs length variant label:\n";
$rows = DB::select("
    SELECT p.name, o.label AS length_label
    FROM products p
    JOIN product_variant_values pvv ON pvv.product_id = p.id
    JOIN product_variant_options o ON o.id = pvv.product_variant_option_id
    JOIN product_variant_groups g ON g.id = pvv.product_variant_group_id
    WHERE p.name LIKE 'Cherish Bulk Passion Twist 14 inch%'
      AND LOWER(g.name) LIKE '%length%'
    LIMIT 5
");
foreach ($rows as $row) {
    echo "  name: {$row->name}\n  length variant: {$row->length_label}\n\n";
}
