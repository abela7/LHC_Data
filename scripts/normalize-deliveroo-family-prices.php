<?php

declare(strict_types=1);

/**
 * One-shot: set the same price on every variant in each deliveroo_official_products
 * family (brand_slug + family_name). Uses mode of non-null prices; ties → lowest.
 *
 * Usage: php scripts/normalize-deliveroo-family-prices.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DeliverooOfficialProduct;
use Illuminate\Support\Facades\DB;

$groups = DB::table('deliveroo_official_products')
    ->select('brand_slug', 'family_name')
    ->groupBy('brand_slug', 'family_name')
    ->get();

$updatedRows = 0;
$familiesTouched = 0;

foreach ($groups as $g) {
    $query = DeliverooOfficialProduct::query()
        ->where('brand_slug', $g->brand_slug)
        ->where(function ($q) use ($g): void {
            if ($g->family_name === null) {
                $q->whereNull('family_name');
            } else {
                $q->where('family_name', $g->family_name);
            }
        });

    $nonNullPrices = (clone $query)->whereNotNull('price')->pluck('price');

    if ($nonNullPrices->isEmpty()) {
        continue;
    }

    $counts = $nonNullPrices->map(fn ($p) => (string) $p)->countBy();
    $maxFreq = $counts->max();
    $candidates = $counts->filter(fn (int $c): bool => $c === $maxFreq)->keys();
    $target = $candidates->map(fn (string $p): float => (float) $p)->min();

    $changed = (clone $query)->update([
        'price' => $target,
        'currency' => 'GBP',
    ]);

    if ($changed > 0) {
        $familiesTouched++;
        $updatedRows += $changed;
    }
}

echo "Families updated: {$familiesTouched}".PHP_EOL;
echo "Rows updated: {$updatedRows}".PHP_EOL;
