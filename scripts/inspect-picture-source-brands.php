<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$stats = DB::selectOne("
    SELECT
        COUNT(*) AS hits,
        COUNT(DISTINCT picture_id) AS pictures,
        COUNT(DISTINCT COALESCE(NULLIF(canonical_brand, ''), brand)) AS brands
    FROM observed_products
");

$products = DB::selectOne("
    SELECT COUNT(*) AS products
    FROM (
        SELECT COALESCE(NULLIF(canonical_brand, ''), brand) AS brand_name, product_name
        FROM observed_products
        GROUP BY brand_name, product_name
    ) grouped_picture_products
");

echo 'picture_hits: '.$stats->hits.PHP_EOL;
echo 'pictures: '.$stats->pictures.PHP_EOL;
echo 'brands: '.$stats->brands.PHP_EOL;
echo 'unique_products: '.$products->products.PHP_EOL;

echo PHP_EOL.'Top picture brands:'.PHP_EOL;
$rows = DB::select("
    SELECT
        COALESCE(NULLIF(canonical_brand, ''), brand) AS brand_name,
        COUNT(*) AS hits,
        COUNT(DISTINCT product_name) AS products,
        COUNT(DISTINCT picture_id) AS pictures
    FROM observed_products
    GROUP BY brand_name
    ORDER BY products DESC, brand_name ASC
    LIMIT 20
");

foreach ($rows as $row) {
    echo "- {$row->brand_name}: {$row->products} products, {$row->hits} hits, {$row->pictures} pictures".PHP_EOL;
}

