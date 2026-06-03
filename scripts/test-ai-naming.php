<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ProductFamily;
use App\Services\OpenAiRetailNamingService;

$family = ProductFamily::query()
    ->withCount('products')
    ->orderBy('products_count')
    ->having('products_count', '>', 0)
    ->first();

if (! $family) {
    fwrite(STDERR, "No family with products found.\n");
    exit(1);
}

echo 'Family #'.$family->id.' ('.$family->products_count." SKUs)\n";
echo 'Model: '.config('services.openai.retail_naming_model')."\n";

try {
    $result = app(OpenAiRetailNamingService::class)->suggest($family);
    echo 'OK: '.count($result['suggestions'])." suggestions\n";
    if (! empty($result['warnings'])) {
        echo 'Warnings: '.implode(' | ', $result['warnings'])."\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
