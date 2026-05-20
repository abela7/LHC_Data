<?php

declare(strict_types=1);

/**
 * One-shot: turn on POS, website and inventory for retail products still on the
 * old catalogue-publish defaults (draft, POS off, website off, inventory on).
 *
 * Usage: php scripts/enable-default-retail-channels.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Models\ProductEcommerceProfile;
use Illuminate\Support\Facades\DB;

$productIds = Product::query()
    ->where('status', 'draft')
    ->where('is_pos_active', false)
    ->where('is_ecommerce_active', false)
    ->where('is_inventory_tracked', true)
    ->pluck('id');

$count = $productIds->count();

if ($count === 0) {
    echo "No products matched the old default pattern.\n";
    exit(0);
}

DB::transaction(function () use ($productIds): void {
    Product::query()
        ->whereIn('id', $productIds)
        ->update([
            'status' => 'active',
            'is_pos_active' => true,
            'is_ecommerce_active' => true,
            'is_inventory_tracked' => true,
        ]);

    ProductEcommerceProfile::query()
        ->whereIn('product_id', $productIds)
        ->where('profile_level', 'sku')
        ->update(['is_published' => true]);
});

echo "Updated {$count} product(s): POS, website and inventory on; status active.\n";
