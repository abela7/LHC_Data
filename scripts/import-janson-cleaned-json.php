<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$path = $argv[1] ?? 'C:\Users\Abela\Desktop\Khan\janson_products.cleaned.json';

if (! Schema::hasTable('janson_products')) {
    fwrite(STDERR, "janson_products table does not exist. Run php artisan migrate first.\n");
    exit(1);
}

if (! is_file($path)) {
    fwrite(STDERR, "Cleaned Janson JSON not found: {$path}\n");
    exit(1);
}

$payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$products = $payload['products'] ?? [];

if (! is_array($products) || $products === []) {
    fwrite(STDERR, "No products found in {$path}\n");
    exit(1);
}

$now = now();
$rows = [];

foreach ($products as $index => $product) {
    if (! is_array($product)) {
        continue;
    }

    $sourceRowId = trim((string) ($product['source_row_id'] ?? ''));
    if ($sourceRowId === '') {
        $sourceRowId = sprintf(
            'JANSON-P%02d-R%03d-%s',
            (int) ($product['page'] ?? 0),
            (int) ($product['page_row'] ?? ($index + 1)),
            (string) ($product['code'] ?? 'NO-CODE')
        );
    }

    $rows[] = [
        'source_row_id' => $sourceRowId,
        'row_index' => (int) ($product['row_index'] ?? ($index + 1)),
        'page' => nullableInt($product['page'] ?? null),
        'page_row' => nullableInt($product['page_row'] ?? null),
        'code' => nullableString($product['code'] ?? null),
        'source_code' => nullableString($product['source_code'] ?? null),
        'category' => nullableString($product['category'] ?? null),
        'source_category' => nullableString($product['source_category'] ?? null),
        'name' => trim((string) ($product['name'] ?? '')),
        'source_name' => nullableString($product['source_name'] ?? null),
        'price_gbp' => nullableDecimal($product['price_gbp'] ?? null),
        'currency' => 'GBP',
        'flags' => json_encode(array_values($product['flags'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'is_new' => (bool) ($product['is_new'] ?? false),
        'special_note' => nullableString($product['special_note'] ?? null),
        'review_flags' => json_encode(array_values($product['review_flags'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'raw_payload' => json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

DB::transaction(function () use ($rows): void {
    DB::table('janson_products')->delete();

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('janson_products')->insert($chunk);
    }
});

echo "Janson cleaned JSON imported.\n";
echo 'products: '.count($rows)."\n";
echo 'categories: '.DB::table('janson_products')->distinct()->count('category')."\n";
echo 'review_flagged: '.DB::table('janson_products')->whereRaw('JSON_LENGTH(review_flags) > 0')->count()."\n";

function nullableString(mixed $value): ?string
{
    $value = trim((string) ($value ?? ''));

    return $value === '' ? null : $value;
}

function nullableInt(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return (int) $value;
}

function nullableDecimal(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    return number_format((float) $value, 2, '.', '');
}
