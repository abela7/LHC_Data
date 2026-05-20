<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$sync = in_array('--sync', $argv, true);
$aliases = config('picture_brand_review.aliases', []);

if (! is_array($aliases) || $aliases === []) {
    echo "No aliases configured.\n";
    exit(0);
}

$summary = DB::transaction(function () use ($aliases, $sync): array {
    $summary = [
        'aliases' => count($aliases),
        'matched_aliases' => 0,
        'updated_rows' => 0,
        'untouched_aliases' => 0,
    ];

    foreach ($aliases as $sourceBrand => $target) {
        $sourceBrand = trim((string) $sourceBrand);
        $targetBrand = is_array($target) ? trim((string) ($target['brand'] ?? '')) : trim((string) $target);
        $targetLine = is_array($target) ? trim((string) ($target['line'] ?? '')) : '';

        if ($sourceBrand === '' || $targetBrand === '') {
            $summary['untouched_aliases']++;
            continue;
        }

        $rows = DB::table('observed_products')
            ->where(function ($query) use ($sourceBrand): void {
                $query->where('brand', $sourceBrand)
                    ->orWhere('canonical_brand', $sourceBrand)
                    ->orWhereRaw("COALESCE(NULLIF(canonical_brand, ''), brand) = ?", [$sourceBrand]);
            })
            ->select('id', 'picture_id', 'brand', 'canonical_brand', 'brand_line', 'product_name')
            ->orderBy('picture_id')
            ->orderBy('sort_order')
            ->get();

        if ($rows->isEmpty()) {
            $summary['untouched_aliases']++;
            echo "- {$sourceBrand} => {$targetBrand}: no matching picture rows.\n";
            continue;
        }

        $summary['matched_aliases']++;
        $sampleProducts = $rows
            ->pluck('product_name')
            ->filter()
            ->unique()
            ->take(5)
            ->implode(' | ');
        echo "- {$sourceBrand} => {$targetBrand}"
            .($targetLine !== '' ? " ({$targetLine})" : '')
            .": {$rows->count()} row(s)"
            .($sampleProducts !== '' ? " | {$sampleProducts}" : '')
            ."\n";

        foreach ($rows as $row) {
            $newLine = trim((string) $row->brand_line);
            if ($targetLine !== '') {
                $newLine = $newLine !== '' && strcasecmp($newLine, $targetLine) !== 0
                    ? $newLine.' / '.$targetLine
                    : $targetLine;
            }

            DB::table('observed_products')
                ->where('id', $row->id)
                ->update([
                    'canonical_brand' => $targetBrand,
                    'brand_line' => $newLine !== '' ? $newLine : $row->brand_line,
                    'updated_at' => now(),
                ]);
            $summary['updated_rows']++;
        }
    }

    if (! $sync) {
        DB::rollBack();
    }

    return $summary;
});

echo ($sync ? "\nPicture brand aliases applied.\n" : "\nPicture brand alias dry run.\n");
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

if (! $sync) {
    echo "Run with --sync to update observed_products canonical_brand safely.\n";
}

