<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$backupPath = __DIR__.'/../storage/app/private/catalogue-backups/hair-catalogue-imported-floor-merge-20260520-204418.json';

if (! is_file($backupPath)) {
    fwrite(STDERR, "Backup not found: {$backupPath}\n");
    exit(1);
}

$backup = json_decode(file_get_contents($backupPath), true, flags: JSON_THROW_ON_ERROR);

function rib_row(array $rows, int $id): array
{
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $id) {
            return $row;
        }
    }

    throw new RuntimeException("Backup row {$id} not found.");
}

function rib_upsert(string $table, array $row): void
{
    DB::table($table)->updateOrInsert(
        ['id' => $row['id']],
        array_diff_key($row, ['id' => true]),
    );
}

DB::transaction(function () use ($backup): void {
    // Restore the Impression structural buckets that were removed by the merge.
    rib_upsert('brand_catalogue_lines', rib_row($backup['lines'], 48));
    rib_upsert('brand_catalogue_product_types', rib_row($backup['product_types'], 31));

    foreach ([92, 93, 15354] as $styleId) {
        rib_upsert('brand_catalogue_styles', rib_row($backup['styles'], $styleId));
    }

    foreach ([145, 146, 7612, 7613, 18203, 18204] as $variantId) {
        rib_upsert('brand_catalogue_variants', rib_row($backup['variants'], $variantId));
    }

    foreach ([649, 22529, 35836, 35837, 35838, 35839, 35840] as $optionId) {
        rib_upsert('brand_catalogue_variant_options', rib_row($backup['variant_options'], $optionId));
    }

    foreach ([645, 20703, 31857, 31858, 31859, 31860] as $skuId) {
        rib_upsert('brand_catalogue_skus', rib_row($backup['skus'], $skuId));
    }

    foreach ([1939, 50698, 63052, 63053, 63054, 63055, 63056, 63057, 63058] as $pivotId) {
        rib_upsert('brand_catalogue_sku_variant_options', rib_row($backup['sku_variant_options'], $pivotId));
    }

    foreach ([12095, 12097] as $familyId) {
        rib_upsert('product_families', rib_row($backup['product_families'], $familyId));
    }
});

echo "Impression catalogue restored from pre-merge backup.\n";
