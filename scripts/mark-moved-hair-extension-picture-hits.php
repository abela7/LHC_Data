<?php

use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$reportDir = storage_path('app/catalogue-reports');
$reports = glob($reportDir.'/hair-extension-picture-hits-sync-*.csv') ?: [];
rsort($reports);

if ($reports === []) {
    fwrite(STDERR, "No hair-extension sync report found.\n");
    exit(1);
}

$reportPath = $reports[0];
$handle = fopen($reportPath, 'rb');

if ($handle === false) {
    fwrite(STDERR, "Could not open report: {$reportPath}\n");
    exit(1);
}

$headers = fgetcsv($handle);
$extensionRows = [];

while (($row = fgetcsv($handle)) !== false) {
    $record = array_combine($headers, $row);

    if (! str_starts_with((string) $record['action'], 'skipped_')) {
        $extensionRows[] = $record;
    }
}

fclose($handle);

DB::transaction(function () use ($extensionRows, $reportPath): void {
    $now = now();

    DB::table('categories')->updateOrInsert(
        ['slug' => 'hair-extension-moved'],
        [
            'name' => 'Moved Hair Extensions',
            'sort_order' => 40,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    $categoryId = DB::table('categories')->where('slug', 'hair-extension-moved')->value('id');
    $updated = 0;
    $misses = [];

    foreach ($extensionRows as $record) {
        $affected = DB::table('observed_products')
            ->where('picture_id', $record['picture_id'])
            ->where('product_name', $record['sku_name'])
            ->update([
                'category_id' => $categoryId,
                'updated_at' => $now,
            ]);

        $updated += $affected;

        if ($affected === 0) {
            $misses[] = $record['picture_id'].' | '.$record['sku_name'];
        }
    }

    echo "source_report: {$reportPath}\n";
    echo 'extension_records: '.count($extensionRows)."\n";
    echo "updated_rows: {$updated}\n";

    if ($misses !== []) {
        echo "misses:\n".implode("\n", $misses)."\n";
    }
});
