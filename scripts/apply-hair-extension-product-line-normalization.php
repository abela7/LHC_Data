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

$logPath = $reportDir."/hair-extension-product-line-normalization-{$timestamp}.csv";
$latestLogPath = $reportDir.'/hair-extension-product-line-normalization-latest.csv';

function clean_line_value(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function path_values(mixed $path): array
{
    $values = is_array($path) ? $path : [];

    return array_values(array_filter(array_map('clean_line_value', $values)));
}

function append_path(mixed $path, array $additions): array
{
    $values = path_values($path);
    $seen = [];
    $result = [];

    foreach (array_merge($values, $additions) as $value) {
        $value = clean_line_value($value);
        if ($value === '') {
            continue;
        }

        $key = Str::lower($value);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $result[] = $value;
    }

    return $result;
}

function line_rule_for(HairExtensionIntake $intake): ?array
{
    $type = clean_line_value($intake->product_type_name);
    $style = clean_line_value($intake->style_name);
    $path = path_values($intake->classification_path);
    $pathText = Str::lower(implode(' > ', $path));

    if ($type === 'BOHO') {
        return [
            'new_type' => 'Crochet Braid',
            'new_path' => append_path($path, ['BOHO']),
            'new_style' => $style,
            'reason' => 'BOHO is a Cherish product line/grouping, not a physical product type.',
        ];
    }

    if ($type === 'EZ Ponytail') {
        return [
            'new_type' => 'Ponytail',
            'new_path' => append_path($path, ['EZ Ponytail']),
            'new_style' => $style,
            'reason' => 'EZ Ponytail is a ponytail line/format. Product type should be Ponytail.',
        ];
    }

    if ($type === 'Instant Pony') {
        return [
            'new_type' => 'Ponytail',
            'new_path' => append_path($path, ['Instant Pony']),
            'new_style' => $style,
            'reason' => 'Instant Pony is a ponytail line/format. Product type should be Ponytail.',
        ];
    }

    if ($type === 'Crochet, Twist & Loc Hair') {
        $newStyle = preg_replace('/^Cherish\s+Bulk\s+/i', '', $style) ?: $style;

        return [
            'new_type' => 'Crochet Braid',
            'new_path' => append_path($path, ['Bulk']),
            'new_style' => clean_line_value($newStyle),
            'reason' => 'Supplier category moved out of product type. Cherish Bulk line moved to grouping path.',
        ];
    }

    if ($type === 'Hair Couture') {
        $targetType = str_contains($pathText, 'ponytail') ? 'Ponytail' : 'Clip-in Extensions';

        return [
            'new_type' => $targetType,
            'new_path' => append_path($path, ['Hair Couture']),
            'new_style' => $style,
            'reason' => 'Hair Couture is a Sleek line. Physical type inferred from existing grouping path.',
        ];
    }

    if ($type === 'Cherish junior') {
        $styleKey = preg_replace('/[^a-z0-9]+/', '', Str::lower($style)) ?: '';
        $bulkStyles = ['deeptwist', 'waterbulk', 'bubblycurl'];
        $newType = in_array($styleKey, $bulkStyles, true) ? 'Bulk Hair' : 'Crochet Braid';

        return [
            'new_type' => $newType,
            'new_path' => append_path($path, ['Cherish Junior']),
            'new_style' => $style,
            'reason' => 'Cherish Junior is a line. Product type chosen by observed style/family.',
        ];
    }

    return null;
}

$rows = [];
$changed = 0;

DB::transaction(function () use ($apply, &$rows, &$changed): void {
    HairExtensionIntake::query()
        ->where('status', 'submitted')
        ->whereIn('product_type_name', [
            'BOHO',
            'EZ Ponytail',
            'Instant Pony',
            'Crochet, Twist & Loc Hair',
            'Hair Couture',
            'Cherish junior',
        ])
        ->orderBy('id')
        ->chunkById(100, function ($intakes) use ($apply, &$rows, &$changed): void {
            foreach ($intakes as $intake) {
                $rule = line_rule_for($intake);
                if (! $rule) {
                    continue;
                }

                $oldPath = path_values($intake->classification_path);
                $rows[] = [
                    'intake_id' => $intake->id,
                    'brand' => $intake->brand_name,
                    'old_product_type' => $intake->product_type_name,
                    'new_product_type' => $rule['new_type'],
                    'old_grouping_path' => implode(' > ', $oldPath),
                    'new_grouping_path' => implode(' > ', $rule['new_path']),
                    'old_style' => $intake->style_name,
                    'new_style' => $rule['new_style'],
                    'reason' => $rule['reason'],
                    'applied' => $apply ? 'yes' : 'no',
                ];

                if ($apply) {
                    $intake->update([
                        'product_type_name' => $rule['new_type'],
                        'product_type_status' => 'known',
                        'product_type_unknown' => false,
                        'classification_path' => $rule['new_path'],
                        'style_name' => $rule['new_style'],
                        'style_family_status' => 'known',
                        'style_unknown' => false,
                        'last_synced_at' => now(),
                    ]);
                }

                $changed++;
            }
        });
});

$csv = fopen($logPath, 'w');
fputcsv($csv, ['intake_id', 'brand', 'old_product_type', 'new_product_type', 'old_grouping_path', 'new_grouping_path', 'old_style', 'new_style', 'reason', 'applied']);
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
