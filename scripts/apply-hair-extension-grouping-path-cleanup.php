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

$logPath = $reportDir."/hair-extension-grouping-path-cleanup-{$timestamp}.csv";
$latestLogPath = $reportDir.'/hair-extension-grouping-path-cleanup-latest.csv';

function clean_gp_value(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function clean_gp_path(array $path): array
{
    $seen = [];
    $result = [];

    foreach ($path as $value) {
        $value = clean_gp_value($value);
        if ($value === '') {
            continue;
        }

        $key = strtolower($value);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $result[] = $value;
    }

    return $result;
}

$pathRules = [
    25 => ['French Curl', 'Pre-Stretched'],
    26 => ['Cherish Junior', 'French Curl'],
    28 => ['Bulk'],
    31 => ['Bulk'],
    32 => ['Bulk'],
    34 => ['Bulk'],
    35 => ['Bulk'],
    42 => ['X-Pression Braids'],
    43 => ['X-Pression Braids'],
    44 => ['X-Pression Braids'],
    45 => ['X-Pression Braids'],
    46 => ['Outre', 'X-Pression Twisted Up'],
    47 => ['Outre', 'X-Pression Twisted Up'],
    48 => ['X-Pression Weave On'],
    55 => ['Crochet, Twist & Loc Hair'],
    56 => ['Crochet, Twist & Loc Hair'],
    73 => ['100% Human Hair', 'NaturalMix'],
    79 => ['Bulk'],
    80 => ['Bulk'],
    81 => ['Bulk'],
    92 => ['Top Hair Fashion'],
    93 => ['Twist & Loc Hair'],
    95 => ['Crochet, Twist & Loc Hair'],
    96 => ['Twist & Loc Hair'],
    101 => ['Urban French Curl'],
    104 => ['Crochet, Twist & Loc Hair'],
    131 => ['Twist & Loc Hair'],
    132 => ['Twist & Loc Hair'],
    133 => ['Twist & Loc Hair'],
    139 => ['Twist & Loc Hair'],
];

$markTypeKnownIds = [
    127, 128, 129, 130, 131, 132, 133, 134, 135, 136, 137, 138, 139, 140,
];

$rows = [];

DB::transaction(function () use ($apply, $pathRules, $markTypeKnownIds, &$rows): void {
    $ids = array_values(array_unique(array_merge(array_keys($pathRules), $markTypeKnownIds)));

    HairExtensionIntake::query()
        ->whereIn('id', $ids)
        ->orderBy('id')
        ->get()
        ->each(function (HairExtensionIntake $intake) use ($apply, $pathRules, $markTypeKnownIds, &$rows): void {
            $oldPath = is_array($intake->classification_path) ? $intake->classification_path : [];
            $newPath = array_key_exists($intake->id, $pathRules)
                ? clean_gp_path($pathRules[$intake->id])
                : clean_gp_path($oldPath);

            $oldTypeStatus = $intake->product_type_status ?: 'known';
            $newTypeStatus = in_array($intake->id, $markTypeKnownIds, true) ? 'known' : $oldTypeStatus;

            $changed = $oldPath !== $newPath || $oldTypeStatus !== $newTypeStatus;
            if (! $changed) {
                return;
            }

            $rows[] = [
                'intake_id' => $intake->id,
                'brand' => $intake->brand_name,
                'product_type' => $intake->product_type_name,
                'style_family' => $intake->style_name,
                'old_grouping_path' => implode(' > ', clean_gp_path($oldPath)),
                'new_grouping_path' => implode(' > ', $newPath),
                'old_product_type_status' => $oldTypeStatus,
                'new_product_type_status' => $newTypeStatus,
                'reason' => array_key_exists($intake->id, $pathRules)
                    ? 'Added obvious product-line/grouping path from brand/style/type context.'
                    : 'Product type was already clear; status changed from not_sure to known.',
                'applied' => $apply ? 'yes' : 'no',
            ];

            if ($apply) {
                $intake->update([
                    'classification_path' => $newPath,
                    'product_type_status' => $newTypeStatus,
                    'product_type_unknown' => false,
                    'last_synced_at' => now(),
                ]);
            }
        });
});

$csv = fopen($logPath, 'w');
fputcsv($csv, ['intake_id', 'brand', 'product_type', 'style_family', 'old_grouping_path', 'new_grouping_path', 'old_product_type_status', 'new_product_type_status', 'reason', 'applied']);
foreach ($rows as $row) {
    fputcsv($csv, $row);
}
fclose($csv);
copy($logPath, $latestLogPath);

echo json_encode([
    'mode' => $apply ? 'applied' : 'dry_run',
    'changed_count' => count($rows),
    'log' => $logPath,
    'latest_log' => $latestLogPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

if (! $apply) {
    echo "Run with --apply to write these changes.\n";
}
