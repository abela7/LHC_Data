<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\HairExtensionIntake;
use Illuminate\Support\Str;

$apply = in_array('--apply', $argv, true);
$timestamp = date('Ymd-His');
$reportDir = public_path('reports');

if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$logPath = $reportDir."/hair-extension-material-type-normalization-{$timestamp}.csv";
$latestLogPath = $reportDir.'/hair-extension-material-type-normalization-latest.csv';

function clean_material_text(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function path_array(mixed $path): array
{
    return array_values(array_filter(array_map('clean_material_text', is_array($path) ? $path : [])));
}

function append_unique_path(mixed $path, array $additions): array
{
    $values = path_array($path);
    $seen = [];
    $out = [];

    foreach (array_merge($values, $additions) as $value) {
        $value = clean_material_text($value);
        if ($value === '') {
            continue;
        }

        $key = Str::lower($value);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $out[] = $value;
    }

    return $out;
}

$rules = [
    'Brazilian Hair Weave' => [
        'new_type' => 'Weave',
        'path_additions' => ['Brazilian Hair'],
        'reason' => 'Brazilian Hair is material/texture context. Physical product type is Weave.',
    ],
    'Virgin Remy Hair Weave' => [
        'new_type' => 'Weave',
        'path_additions' => ['Virgin Remy Hair'],
        'reason' => 'Virgin Remy Hair is material/quality context. Physical product type is Weave.',
    ],
    'Remy Hair Weave' => [
        'new_type' => 'Weave',
        'path_additions' => ['Remy Hair'],
        'reason' => 'Remy Hair is material/quality context. Physical product type is Weave.',
    ],
    'European Weave' => [
        'new_type' => 'Weave',
        'path_additions' => ['European Weave'],
        'reason' => 'European Weave is style/family context. Physical product type is Weave.',
    ],
    'Brazilian Hair Bulk' => [
        'new_type' => 'Bulk Hair',
        'path_additions' => ['Brazilian Hair'],
        'reason' => 'Brazilian Hair is material/texture context. Physical product type is Bulk Hair.',
    ],
    'Bulk Human Hair' => [
        'new_type' => 'Bulk Hair',
        'path_additions' => ['Human Hair'],
        'reason' => 'Human Hair is material context. Physical product type is Bulk Hair.',
    ],
];

$rows = [];
$changed = 0;

DB::transaction(function () use ($rules, $apply, &$rows, &$changed): void {
    foreach ($rules as $from => $rule) {
        $intakes = HairExtensionIntake::query()
            ->where('status', 'submitted')
            ->where('product_type_name', $from)
            ->orderBy('id')
            ->get();

        foreach ($intakes as $intake) {
            $oldPath = path_array($intake->classification_path);
            $newPath = append_unique_path($oldPath, $rule['path_additions']);

            $rows[] = [
                'intake_id' => $intake->id,
                'brand' => $intake->brand_name,
                'style_family' => $intake->style_name,
                'old_product_type' => $from,
                'new_product_type' => $rule['new_type'],
                'old_grouping_path' => implode(' > ', $oldPath),
                'new_grouping_path' => implode(' > ', $newPath),
                'reason' => $rule['reason'],
                'applied' => $apply ? 'yes' : 'no',
            ];

            if ($apply) {
                $intake->update([
                    'product_type_name' => $rule['new_type'],
                    'product_type_status' => 'known',
                    'product_type_unknown' => false,
                    'classification_path' => $newPath,
                    'last_synced_at' => now(),
                ]);
            }

            $changed++;
        }
    }
});

$csv = fopen($logPath, 'w');
fputcsv($csv, ['intake_id', 'brand', 'style_family', 'old_product_type', 'new_product_type', 'old_grouping_path', 'new_grouping_path', 'reason', 'applied']);
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
