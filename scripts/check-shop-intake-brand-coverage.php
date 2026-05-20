<?php

/*
 * Re-runnable utility to confirm a given brand name reaches the Shop Product
 * Intake brand combobox.
 *
 * Usage:
 *   php scripts/check-shop-intake-brand-coverage.php Directions
 *   php scripts/check-shop-intake-brand-coverage.php "African Pride"
 *
 * Prints: total brand count exposed by the controller, plus where the search
 * term lives across product_families, brands, mamado, pdf, deliveroo so we
 * can see if any new source needs to be folded into ShopProductIntakeController::brandOptions().
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\ShopProductIntakeController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$query = trim((string) ($argv[1] ?? 'Directions'));
$lower = strtolower($query);

echo str_repeat('=', 64)."\n";
echo "Shop intake brand coverage: '{$query}'\n";
echo str_repeat('=', 64)."\n";

$controller = new ShopProductIntakeController();
$brands = (new ReflectionClass($controller))->getMethod('brandOptions');
$brands->setAccessible(true);
$list = $brands->invoke($controller);

echo "\n[Controller] total brands offered : ".$list->count()."\n";
$matches = $list->filter(fn ($r) => stripos((string) $r['name'], $query) !== false);
echo "[Controller] matching '{$query}'      : ".$matches->count()."\n";
foreach ($matches as $row) {
    echo "    - {$row['name']} (families={$row['family_count']})\n";
}

echo "\n[Sources] where '{$query}' lives in raw tables:\n";

$sources = [
    ['brands', 'name'],
    ['product_families', 'brand_name'],
    ['mamado_products', 'brand_label'],
    ['pdf_catalogue_products', 'brand'],
    ['deliveroo_official_products', 'brand_label'],
];

foreach ($sources as [$table, $col]) {
    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
        continue;
    }

    $count = DB::table($table)->whereRaw("lower({$col}) like ?", ["%{$lower}%"])->count();
    echo sprintf("    %-32s : %d row(s)\n", "{$table}.{$col}", $count);
}

echo "\nDone.\n";
