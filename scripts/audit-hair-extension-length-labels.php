<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::table('product_variant_options as o')
    ->join('product_variant_groups as g', 'g.id', '=', 'o.product_variant_group_id')
    ->join('product_families as f', 'f.id', '=', 'g.product_family_id')
    ->where('f.root_catalogue_name', 'Hair Extensions')
    ->where(function ($query): void {
        $query->whereRaw('LOWER(g.name) = ?', ['length'])
            ->orWhereRaw('LOWER(g.name) LIKE ?', ['%length%']);
    })
    ->select('o.label', DB::raw('COUNT(*) as c'))
    ->groupBy('o.label')
    ->orderByDesc('c')
    ->limit(50)
    ->get();

echo "Top retail length labels (Hair Extensions):\n";
foreach ($rows as $row) {
    echo "  {$row->c}\t{$row->label}\n";
}

echo "\nTotal distinct labels: ".DB::table('product_variant_options as o')
    ->join('product_variant_groups as g', 'g.id', '=', 'o.product_variant_group_id')
    ->join('product_families as f', 'f.id', '=', 'g.product_family_id')
    ->where('f.root_catalogue_name', 'Hair Extensions')
    ->where(function ($query): void {
        $query->whereRaw('LOWER(g.name) = ?', ['length'])
            ->orWhereRaw('LOWER(g.name) LIKE ?', ['%length%']);
    })
    ->distinct('o.label')
    ->count('o.label')."\n";
