<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DeliverooOfficialProduct;

$rows = DeliverooOfficialProduct::query()
    ->whereNull('price')
    ->orderBy('brand_slug')
    ->orderBy('family_name')
    ->orderBy('official_name')
    ->get(['id', 'brand_label', 'brand_slug', 'family_name', 'variant_name', 'official_name']);

echo 'Total without price: '.$rows->count().PHP_EOL.PHP_EOL;
echo 'id | brand | family | variant | official_name'.PHP_EOL;
echo str_repeat('-', 120).PHP_EOL;

foreach ($rows as $r) {
    echo $r->id.' | '.$r->brand_label.' | '.($r->family_name ?? '').' | '.($r->variant_name ?? '').' | '.$r->official_name.PHP_EOL;
}
