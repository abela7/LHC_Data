<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MamadoProduct;

$dryRun = in_array('--dry-run', $argv, true);

function cherish_variant_clean_spaces(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function cherish_variant_normalize_colour(string $colour): string
{
    $colour = cherish_variant_clean_spaces($colour);

    $formatToken = function (string $token): string {
        $knownWords = [
            'caramelt' => true,
            'copper' => true,
            'grey' => true,
            'red' => true,
            'silver' => true,
            'tcopper' => true,
        ];
        $lower = strtolower($token);

        if (isset($knownWords[$lower])) {
            return ucfirst($lower);
        }

        if (str_contains($token, '/')) {
            return implode('/', array_map(function (string $part) use ($knownWords): string {
                $partLower = strtolower($part);

                if (isset($knownWords[$partLower])) {
                    return ucfirst($partLower);
                }

                if (preg_match('/\d/', $part) || strlen($part) <= 5) {
                    return strtoupper($part);
                }

                return ucfirst(strtolower($part));
            }, explode('/', $token)));
        }

        if (preg_match('/\d/', $token) || strlen($token) <= 5) {
            return strtoupper($token);
        }

        return ucfirst(strtolower($token));
    };

    if (str_contains($colour, ' ')) {
        return preg_replace_callback(
            '/[A-Za-z0-9\/]+/',
            function (array $match) use ($formatToken): string {
                $token = $match[0];

                if (preg_match('/\d|\//', $token)) {
                    return $formatToken($token);
                }

                return ucfirst(strtolower($token));
            },
            $colour,
        ) ?? $colour;
    }

    return preg_replace_callback(
        '/[A-Za-z0-9\/]+/',
        fn (array $match): string => $formatToken($match[0]),
        $colour,
    ) ?? $colour;
}

function cherish_variant_parts(string $description): array
{
    $working = cherish_variant_clean_spaces($description);
    $colour = null;

    if (preg_match('/\[\s*(?:Colour|Color|Col\.?)\s*:?\s*([^\]]+?)\s*\]\s*$/i', $working, $match)) {
        $colour = cherish_variant_normalize_colour($match[1]);
        $working = trim(substr($working, 0, -strlen($match[0])));
    } elseif (preg_match('/\(\s*(?:Colour|Color|Col\.?)\s*:?\s*([^)]+?)\s*\)\s*$/i', $working, $match)) {
        $colour = cherish_variant_normalize_colour($match[1]);
        $working = trim(substr($working, 0, -strlen($match[0])));
    }

    $tokens = preg_split('/\s+/', $working) ?: [];
    $bundle = null;
    $length = null;

    foreach ($tokens as $token) {
        $candidate = strtolower(trim($token, " \t\n\r\0\x0B.,:;"));

        if (preg_match('/^([2-9])xvp$/i', $candidate, $match)) {
            $bundle = $match[1].'x VP';
            continue;
        }

        if (preg_match('/^([2-9])x$/i', $candidate, $match)) {
            $bundle = $match[1].'x';
            continue;
        }

        if (preg_match('/^\d+(?:\/\d+)+$/', $candidate)) {
            $length = $candidate;
            continue;
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $candidate)) {
            $length = $candidate;
        }
    }

    $parts = [];

    if ($bundle !== null) {
        $parts[] = 'Bundle: '.$bundle;
    }

    if ($length !== null) {
        $parts[] = 'Length: '.$length;
    }

    if ($colour !== null && $colour !== '') {
        $parts[] = 'Colour: '.$colour;
    }

    return $parts;
}

$rows = MamadoProduct::query()
    ->where('brand_label', 'Cherish')
    ->orderBy('family_name')
    ->orderBy('item_code')
    ->get();

$updated = 0;
$alreadyCorrect = 0;
$missingVariant = [];
$samples = [];

foreach ($rows as $product) {
    $parts = cherish_variant_parts($product->item_description);
    $variantName = implode('; ', $parts);

    if ($variantName === '') {
        $missingVariant[] = [
            'item_code' => $product->item_code,
            'family_name' => $product->family_name,
            'item_description' => $product->item_description,
        ];

        continue;
    }

    if (count($samples) < 80) {
        $samples[] = [
            'item_code' => $product->item_code,
            'family_name' => $product->family_name,
            'item_description' => $product->item_description,
            'variant_name' => $variantName,
        ];
    }

    if ($product->variant_name === $variantName) {
        $alreadyCorrect++;
        continue;
    }

    $updated++;

    if (! $dryRun) {
        $product->forceFill(['variant_name' => $variantName])->save();
    }
}

$reportDir = __DIR__ . '/../storage/app/mamado';

if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$csv = fopen($reportDir . '/cherish-variant-candidates.csv', 'w');
fputcsv($csv, ['item_code', 'family_name', 'item_description', 'variant_name']);

foreach ($rows as $product) {
    fputcsv($csv, [
        $product->item_code,
        $product->family_name,
        $product->item_description,
        implode('; ', cherish_variant_parts($product->item_description)),
    ]);
}

fclose($csv);

file_put_contents(
    $reportDir . '/cherish-variant-missing.json',
    json_encode($missingVariant, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);

$summary = [
    'dry_run' => $dryRun,
    'brand' => 'Cherish',
    'source_products' => $rows->count(),
    'variant_candidates' => $rows->count() - count($missingVariant),
    'missing_variant_count' => count($missingVariant),
    'updated_rows' => $updated,
    'already_correct_rows' => $alreadyCorrect,
    'report_path' => $reportDir . '/cherish-variant-candidates.csv',
    'missing_report_path' => $reportDir . '/cherish-variant-missing.json',
    'sample_variants' => $samples,
];

file_put_contents(
    $reportDir . '/cherish-variant-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
