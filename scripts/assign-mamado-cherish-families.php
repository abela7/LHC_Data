<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MamadoProduct;

$dryRun = in_array('--dry-run', $argv, true);

function cherish_clean_spaces(string $value): string
{
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    $value = preg_replace('/\s+-\s+/', ' - ', $value) ?? $value;

    return trim($value);
}

function cherish_title_token(string $value): string
{
    $preserve = [
        'PS' => true,
        'VP' => true,
    ];

    return preg_replace_callback('/\b[A-Za-z][A-Za-z0-9\/]*\b/', function (array $match) use ($preserve): string {
        $upper = strtoupper($match[0]);

        if (isset($preserve[$upper])) {
            return $upper;
        }

        return ucfirst(strtolower($match[0]));
    }, $value) ?? $value;
}

function cherish_family_from_description(string $description): string
{
    $family = trim($description);

    $family = preg_replace('/\s*\(\s*(?:Col\.|Color|Colour)\s*:?\s*[^)]*\)\s*$/i', '', $family) ?? $family;
    $family = preg_replace('/\s*\[\s*(?:Col|Color|Colour)\s*:?\s*[^\]]*\]\s*$/i', '', $family) ?? $family;
    $family = cherish_clean_spaces($family);

    $family = preg_replace('/^Cherish\s+Junior\s+Blk\s*[:\-]\s*/i', 'Cherish Junior Bulk - ', $family) ?? $family;
    $family = preg_replace('/^Cherish\s+Junior\s+Bulk\s*[:\-]\s*/i', 'Cherish Junior Bulk - ', $family) ?? $family;
    $family = preg_replace('/^Cherish\s+Bulk\s*[:\-]\s*/i', 'Cherish Bulk - ', $family) ?? $family;
    $family = preg_replace('/^Cherish\s*:\s*/i', 'Cherish - ', $family) ?? $family;

    $family = str_ireplace([
        'Pre-Str.',
        'Pre-Str ',
        'Ps Spiral',
        'P/S Spiral',
    ], [
        'Pre-Stretched',
        'Pre-Stretched ',
        'Pre-Stretched Spiral',
        'Pre-Stretched Spiral',
    ], $family);

    $family = cherish_clean_spaces($family);

    $parts = explode(' - ', $family, 2);
    $prefix = $parts[0] ?? 'Cherish';
    $style = $parts[1] ?? '';

    if ($style === '') {
        $style = preg_replace('/^Cherish\s+/i', '', $family) ?? $family;
        $prefix = 'Cherish';
    }

    $style = cherish_clean_spaces($style);

    $tokens = preg_split('/\s+/', $style) ?: [];
    $filteredTokens = [];

    foreach ($tokens as $token) {
        $normalized = strtolower(trim($token, " \t\n\r\0\x0B.,"));

        if ($normalized === '') {
            continue;
        }

        if (preg_match('/^\d+x(?:vp)?$/i', $normalized)) {
            continue;
        }

        if (preg_match('/^\d+(?:\/\d+)+$/', $normalized)) {
            continue;
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            continue;
        }

        if ($normalized === '3xvp') {
            continue;
        }

        $filteredTokens[] = $token;
    }

    $style = implode(' ', $filteredTokens);
    $style = cherish_clean_spaces($style);

    $style = str_ireplace([
        'Blk ',
    ], [
        'Bulk ',
    ], $style);

    $family = cherish_clean_spaces($prefix.' - '.$style);
    $family = cherish_title_token($family);

    return match ($family) {
        'Cherish Bulk - Pre Stretched Body Wave' => 'Cherish Bulk - Pre-Stretched Body Wave',
        'Cherish Bulk - Pre Stretched Spiral French Curl' => 'Cherish Bulk - Pre-Stretched Spiral French Curl',
        default => $family,
    };
}

$groups = [];
$updated = 0;
$alreadyCorrect = 0;

foreach (MamadoProduct::query()
    ->where('brand_label', 'Cherish')
    ->orderBy('item_code')
    ->get() as $product) {
    $family = cherish_family_from_description($product->item_description);

    if (! isset($groups[$family])) {
        $groups[$family] = [
            'count' => 0,
            'sample_codes' => [],
            'sample_descriptions' => [],
        ];
    }

    $groups[$family]['count']++;

    if (count($groups[$family]['sample_codes']) < 8) {
        $groups[$family]['sample_codes'][] = $product->item_code;
        $groups[$family]['sample_descriptions'][] = $product->item_description;
    }

    if ($product->family_name === $family) {
        $alreadyCorrect++;
        continue;
    }

    $updated++;

    if (! $dryRun) {
        $product->forceFill(['family_name' => $family])->save();
    }
}

uasort($groups, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

$reportDir = __DIR__ . '/../storage/app/mamado';

if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$csv = fopen($reportDir . '/cherish-family-candidates.csv', 'w');
fputcsv($csv, ['family_name', 'product_count', 'sample_item_codes', 'sample_descriptions']);

foreach ($groups as $family => $group) {
    fputcsv($csv, [
        $family,
        $group['count'],
        implode(', ', $group['sample_codes']),
        implode(' || ', $group['sample_descriptions']),
    ]);
}

fclose($csv);

$summary = [
    'dry_run' => $dryRun,
    'brand' => 'Cherish',
    'source_products' => array_sum(array_column($groups, 'count')),
    'family_count' => count($groups),
    'updated_rows' => $updated,
    'already_correct_rows' => $alreadyCorrect,
    'report_path' => $reportDir . '/cherish-family-candidates.csv',
    'families' => array_map(
        fn (array $group): int => $group['count'],
        $groups,
    ),
];

file_put_contents(
    $reportDir . '/cherish-family-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
