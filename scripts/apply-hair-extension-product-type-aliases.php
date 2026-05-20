<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\HairExtensionIntake;

$apply = in_array('--apply', $argv, true);
$timestamp = date('Ymd-His');
$reportDir = public_path('reports');

if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$logPath = $reportDir."/hair-extension-product-type-aliases-{$timestamp}.csv";
$latestLogPath = $reportDir.'/hair-extension-product-type-aliases-latest.csv';

// Only aliases that do not require product-line/material decisions.
$aliases = [
    'Braiding Hair' => 'Braid',
    'BULK' => 'Bulk Hair',
    'Hair Bulk' => 'Bulk Hair',
    'Clip-In Hair Extensions' => 'Clip-in Extensions',
    'Crochet' => 'Crochet Braid',
    'Crochet Braid Hair' => 'Crochet Braid',
    'Crochet Braids' => 'Crochet Braid',
    'Crochet Hair' => 'Crochet Braid',
    'Drawstring Ponytail' => 'Ponytail',
    'Ponytails / Drawstrings' => 'Ponytail',
    'Wrap-around Ponytail' => 'Ponytail',
    'Hair Bun Extension' => 'Bun',
    'Hair Scrunchie Extension' => 'Scrunchie',
    'Lace Closure' => 'Closure / Frontal',
    'Micro Loop Hair Extensions' => 'Micro Loop Extensions',
    'Nano Ring Hair Extensions' => 'Nano Ring Extensions',
    'Stick Tip Hair Extensions' => 'Stick Tip Extensions',
    'Tape-In Hair Extensions' => 'Tape-in Extensions',
    'Weft Hair Extensions' => 'Weave',
];

$rows = [];
$changed = 0;

DB::transaction(function () use ($aliases, $apply, &$rows, &$changed): void {
    foreach ($aliases as $from => $to) {
        $intakes = HairExtensionIntake::query()
            ->where('status', 'submitted')
            ->where('product_type_name', $from)
            ->orderBy('id')
            ->get();

        foreach ($intakes as $intake) {
            $rows[] = [
                'intake_id' => $intake->id,
                'brand' => $intake->brand_name,
                'style_family' => $intake->style_name,
                'old_product_type' => $from,
                'new_product_type' => $to,
                'old_product_type_status' => $intake->product_type_status,
                'new_product_type_status' => 'known',
                'applied' => $apply ? 'yes' : 'no',
            ];

            if ($apply) {
                $intake->update([
                    'product_type_name' => $to,
                    'product_type_status' => 'known',
                    'product_type_unknown' => false,
                    'last_synced_at' => now(),
                ]);
            }

            $changed++;
        }
    }
});

$csv = fopen($logPath, 'w');
fputcsv($csv, ['intake_id', 'brand', 'style_family', 'old_product_type', 'new_product_type', 'old_product_type_status', 'new_product_type_status', 'applied']);
foreach ($rows as $row) {
    fputcsv($csv, $row);
}
fclose($csv);
copy($logPath, $latestLogPath);

echo json_encode([
    'mode' => $apply ? 'applied' : 'dry_run',
    'changed_count' => $changed,
    'log' => $logPath,
    'latest_log' => $latestLogPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

if (! $apply) {
    echo "Run with --apply to write these changes.\n";
}
