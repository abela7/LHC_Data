<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MamadoProduct;

$dryRun = in_array('--dry-run', $argv, true);
$onlyBrand = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--brand=')) {
        $onlyBrand = substr($arg, 8);
    }
}

function mv_clean_spaces(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\xc2\xa0", 'â€“', 'â€”'], [' ', '-', '-'], $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    $value = preg_replace('/\s+([,.)\]])/', '$1', $value) ?? $value;
    $value = preg_replace('/([(\[])\s+/', '$1', $value) ?? $value;

    return trim($value, " \t\n\r\0\x0B-");
}

function mv_title_token(string $value): string
{
    $value = mv_clean_spaces($value);
    $known = [
        'reg' => 'Regular',
        'nr' => 'Normal',
        'ext' => 'Extra Strength',
        'ex' => 'Extra Strength',
        'dbl' => 'Double Strength',
        'blk' => 'Black',
        'lt' => 'Light',
        'dk' => 'Dark',
    ];
    $lower = strtolower($value);

    if (isset($known[$lower])) {
        return $known[$lower];
    }

    return preg_replace_callback('/\b[A-Za-z][A-Za-z\'&\/.-]*\b/', function (array $match): string {
        $word = $match[0];

        if (preg_match('/^[A-Z]{2,}$/', $word)) {
            return $word;
        }

        if (str_contains($word, '/')) {
            return implode('/', array_map('mv_title_token', explode('/', $word)));
        }

        if (str_contains($word, "'")) {
            return implode("'", array_map(fn (string $part): string => ucfirst(strtolower($part)), explode("'", $word)));
        }

        return ucfirst(strtolower($word));
    }, $value) ?? $value;
}

function mv_normalize_colour(string $value): string
{
    $value = mv_clean_spaces($value);

    return preg_replace_callback('/[A-Za-z0-9\/.-]+/', function (array $match): string {
        $token = $match[0];

        if (str_contains($token, '/')) {
            return implode('/', array_map('mv_normalize_colour', explode('/', $token)));
        }

        if (preg_match('/\d/', $token) || strlen($token) <= 5) {
            return strtoupper($token);
        }

        return ucfirst(strtolower($token));
    }, $value) ?? $value;
}

function mv_add_part(array &$parts, string $label, ?string $value): void
{
    $value = mv_clean_spaces((string) $value);
    $value = trim($value, " \t\n\r\0\x0B.,;:-");

    if ($value === '') {
        return;
    }

    $key = strtolower($label);
    if (isset($parts[$key])) {
        if (strcasecmp($parts[$key]['value'], $value) === 0) {
            return;
        }

        return;
    }

    $parts[$key] = [
        'label' => $label,
        'value' => $value,
    ];
}

function mv_is_hair_extension_family(string $family): bool
{
    return (bool) preg_match('/\b(?:bulk|braid|locs?|twist|weave|wig|ponytail|curl|wave|dread|girl)\b/i', $family);
}

function mv_is_colour_family(string $family): bool
{
    return (bool) preg_match('/\b(?:hair colou?r|dye|semi-permanent|colorsilk|colour|color)\b/i', $family);
}

function mv_strength_value(string $value): ?string
{
    $value = mv_clean_spaces($value);
    $map = [
        'reg' => 'Regular',
        'regular' => 'Regular',
        'normal' => 'Normal',
        'nr' => 'Normal',
        'super' => 'Super',
        'sup' => 'Super',
        'mild' => 'Mild',
        'coarse' => 'Coarse',
        'medium' => 'Medium',
        'fine' => 'Fine',
        'sensitive' => 'Sensitive',
        'extra strength' => 'Extra Strength',
        'extra str' => 'Extra Strength',
        'extra str.' => 'Extra Strength',
        'maximum strength' => 'Maximum Strength',
        'double strength' => 'Double Strength',
        '2-double strength' => 'Double Strength',
    ];
    $lower = strtolower(trim($value, '[]() '));

    return $map[$lower] ?? null;
}

function mv_extract_size(string $description): ?string
{
    $text = str_replace('120z', '12oz', $description);

    if (preg_match('/(\d+(?:\.\d+)?)\s*(fl\.?\s*)?(oz|0z|ml|gms?|gm|g|kg|lb|lbs|litre|liter)/i', $text, $match)) {
        $unit = strtolower(str_replace([' ', '.'], '', $match[3]));
        $unit = $unit === '0z' ? 'oz' : $unit;
        $unit = $unit === 'gm' ? 'g' : $unit;
        $unit = $unit === 'gms' ? 'g' : $unit;

        return $match[1] . $unit;
    }

    return null;
}

function mv_extract_hair_parts(string $description, array &$parts): void
{
    if (preg_match('/\b([2-9])\s*x\s*(vp|pf)?\b/i', $description, $match)) {
        $bundle = $match[1] . 'x';
        if (! empty($match[2])) {
            $bundle .= ' ' . strtoupper($match[2]);
        }
        mv_add_part($parts, 'Bundle', $bundle);
    } elseif (preg_match('/\b([2-9])x(vp|pf)?\b/i', $description, $match)) {
        $bundle = $match[1] . 'x';
        if (! empty($match[2])) {
            $bundle .= ' ' . strtoupper($match[2]);
        }
        mv_add_part($parts, 'Bundle', $bundle);
    }

    if (preg_match('/\b(\d{1,2}(?:\/\d{1,2})?)\s*(?=\(|\[|$|\s*\(Col|\s*Col\b)/i', $description, $match)) {
        mv_add_part($parts, 'Length', $match[1]);
    }

    if (preg_match('/\b(?:Colour|Color|Col\.?)\s*:?\s*([A-Za-z0-9\/.-]+)\b/i', $description, $match)) {
        mv_add_part($parts, 'Colour', mv_normalize_colour($match[1]));
    }
}

function mv_bracket_parts(string $description, string $family, string $brand, array &$parts): void
{
    if (preg_match_all('/\[\s*([^\]]+?)\s*\]/', $description, $matches)) {
        foreach ($matches[1] as $value) {
            $value = mv_clean_spaces($value);
            if (preg_match('/^\d{3,}$/', $value) || preg_match('/^(?:[FR]\d{3,}|Ns|Bns)$/i', $value)) {
                continue;
            }

            if (preg_match('/^(?:new|per piece)$/i', $value) || preg_match('/\bprice\b/i', $value) || strcasecmp($value, $brand) === 0) {
                continue;
            }

            if ($strength = mv_strength_value($value)) {
                mv_add_part($parts, 'Strength', $strength);
                continue;
            }

            if (preg_match('/^(\d+)\s*\/\s*App$/i', $value, $match)) {
                mv_add_part($parts, 'Applications', $match[1] . ' App');
                continue;
            }

            if (mv_is_colour_family($family)) {
                mv_add_part($parts, 'Shade', mv_title_token($value));
            } elseif (mv_is_hair_extension_family($family)) {
                mv_add_part($parts, 'Colour', mv_normalize_colour($value));
            } else {
                mv_add_part($parts, 'Variant', mv_title_token($value));
            }
        }
    }

    if (preg_match_all('/\(\s*(?!\d+\s*pk\b)(?!\d{3,}\b)(?!\s*\d+\s*$)([^)]+?)\s*\)/i', $description, $matches)) {
        foreach ($matches[1] as $value) {
            $value = mv_clean_spaces($value);

            if (preg_match('/^(?:\d+|price|per|dozen|pk|pcs?|ea|each|bonus)/i', $value)) {
                continue;
            }

            if (preg_match('/^(?:[FR]\d{3,}|Ns|Bns)$/i', $value)) {
                continue;
            }

            if (preg_match('/^(?:new|per piece)$/i', $value) || preg_match('/\bprice\b/i', $value) || strcasecmp($value, $brand) === 0) {
                continue;
            }

            if (preg_match('/^(?:Colour|Color|Col\.?)\s*:?\s*(.+)$/i', $value, $match)) {
                if (preg_match('/^treated$/i', $match[1])) {
                    continue;
                }

                mv_add_part($parts, 'Colour', mv_normalize_colour($match[1]));
                continue;
            }

            if ($strength = mv_strength_value($value)) {
                mv_add_part($parts, 'Strength', $strength);
                continue;
            }

            if (preg_match('/^(\d+)\s*\/\s*App$/i', $value, $match)) {
                mv_add_part($parts, 'Applications', $match[1] . ' App');
                continue;
            }

            if (mv_is_colour_family($family)) {
                mv_add_part($parts, 'Shade', mv_title_token($value));
            } elseif (mv_is_hair_extension_family($family)) {
                mv_add_part($parts, 'Colour', mv_normalize_colour($value));
            } else {
                mv_add_part($parts, 'Variant', mv_title_token($value));
            }
        }
    }
}

function mv_brand_specific_parts(MamadoProduct $product, array &$parts): void
{
    $brand = (string) $product->brand_label;
    $family = (string) $product->family_name;
    $description = mv_clean_spaces((string) $product->item_description);

    if ($brand === 'Cherish') {
        return;
    }

    if ($brand === 'Creative Image Adore' && preg_match('/^Adore\s+(.+)$/i', $description, $match)) {
        mv_add_part($parts, 'Shade', mv_title_token($match[1]));
    }

    if ($brand === 'Manic Panic' && preg_match('/Manic Panic(?:\s+Cream)?\s*[\[(]\s*([^\])]+)\s*[\])]/i', $description, $match)) {
        mv_add_part($parts, 'Shade', mv_title_token($match[1]));
    }

    if ($brand === 'Bigen') {
        if (preg_match('/\b(?:Hair Colour|Beard|Ez\s*Colou?r|Speedy)\s+(.+?)(?:\s+Price\b|$)/i', $description, $match)) {
            mv_add_part($parts, 'Shade', mv_title_token($match[1]));
        }

        if (preg_match('/\bBigen\s+(\d+)\b/', $description, $match)) {
            mv_add_part($parts, 'Shade Code', $match[1]);
        }
    }

    if ($brand === 'Revlon' && preg_match('/#\s*(\d+)\s+(.+)$/i', $description, $match)) {
        mv_add_part($parts, 'Shade Code', $match[1]);
        mv_add_part($parts, 'Shade', mv_title_token($match[2]));
    }

    if ($brand === 'Sta-Sof-Fro' && preg_match('/Hair Dye\s+(.+?)(?:\d{4,}[A-Za-z]?|$)/i', $description, $match)) {
        mv_add_part($parts, 'Shade', mv_title_token($match[1]));
    }

    if (mv_is_colour_family($family)) {
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*-\s*\[([^\]]+)\]/', $description, $match)) {
            mv_add_part($parts, 'Shade Code', $match[1]);
            mv_add_part($parts, 'Shade', mv_title_token($match[2]));
        }

        if (preg_match('/\b(?:H\/Clr|Rev\/Clr|Hair Colou?r|H\/C)\s*\[([^\]]+)\]/i', $description, $match)) {
            mv_add_part($parts, 'Shade', mv_title_token($match[1]));
        }

        if (preg_match('/\b(\d{2,3}(?:\.\d+)?)\s+(?:H\/Clr|Rev\/Clr)\b/i', $description, $match)) {
            mv_add_part($parts, 'Shade Code', $match[1]);
        }

        if (preg_match('/\b(?:Go Intense|Gel H\/C|Liquid H\/Clr|Liq H\/Clr)\s+(.+?)(?:\s+Kit|\s+X\d+|\s+\(|$)/i', $description, $match)) {
            mv_add_part($parts, 'Shade', mv_title_token($match[1]));
        }

        if (preg_match('/\b(\d+(?:\.\d+)?)\s*$/', $description, $match) && ! isset($parts['shade code'])) {
            mv_add_part($parts, 'Shade Code', $match[1]);
        }
    }

    if (preg_match('/\b(?:Relaxer|Rlxr|Kit|Texturizer|T\/P|Text Soft Kit|Text Kit)\s*[-\/,]?\s*(Regular|Reg|Super|Sup|Mild|Coarse|Normal|Nr|Extra Strength|Ext)\b/i', $description, $match)) {
        mv_add_part($parts, 'Strength', mv_strength_value($match[1]) ?? mv_title_token($match[1]));
    }

    if (preg_match('/\b(Regular|Reg|Super|Sup|Mild|Coarse|Normal|Nr|Extra Strength|Ext)\s+(?:Relaxer|Rlxr|Kit|Texturizer|T\/P|Text Soft Kit|Text Kit)\b/i', $description, $match)) {
        mv_add_part($parts, 'Strength', mv_strength_value($match[1]) ?? mv_title_token($match[1]));
    }

    if (preg_match('/\b(?:Relaxer|Rlxr|No Lye|No-Lye|Texturizer|T\/P).{0,35}\b(Regular|Reg|Super|Sup|Mild|Coarse|Normal|Nr|Extra Strength|Ext)\b/i', $description, $match)) {
        mv_add_part($parts, 'Strength', mv_strength_value($match[1]) ?? mv_title_token($match[1]));
    }

    if (preg_match('/\b(Regualr)\b/i', $description)) {
        mv_add_part($parts, 'Strength', 'Regular');
    }

    if (preg_match('/\b(Maximum Strength|Extra Strength|Double Strength|Sensitive)\b/i', $description, $match)) {
        mv_add_part($parts, 'Strength', mv_strength_value($match[1]) ?? mv_title_token($match[1]));
    }

    if (preg_match('/\b(?:Cream|Gel|Pomade)\s+(Mild|Regular|Super|Extra Strength|Maximum Strength)\b/i', $description, $match)) {
        mv_add_part($parts, 'Strength', mv_strength_value($match[1]) ?? mv_title_token($match[1]));
    }
}

function mv_cherish_variant_parts(string $description): array
{
    $parts = [];
    mv_extract_hair_parts($description, $parts);

    if (preg_match('/\[\s*(?:Colour|Color|Col\.?)\s*:?\s*([^\]]+?)\s*\]\s*$/i', $description, $match)) {
        mv_add_part($parts, 'Colour', mv_normalize_colour($match[1]));
    } elseif (preg_match('/\(\s*(?:Colour|Color|Col\.?)\s*:?\s*([^)]+?)\s*\)\s*$/i', $description, $match)) {
        mv_add_part($parts, 'Colour', mv_normalize_colour($match[1]));
    }

    return $parts;
}

function mv_variant_parts(MamadoProduct $product): array
{
    $brand = (string) $product->brand_label;
    $family = (string) $product->family_name;
    $description = mv_clean_spaces((string) $product->item_description);
    $parts = [];

    if ($brand === 'Cherish') {
        return mv_cherish_variant_parts($description);
    }

    if (mv_is_hair_extension_family($family)) {
        mv_extract_hair_parts($description, $parts);
    }

    mv_bracket_parts($description, $family, $brand, $parts);
    mv_brand_specific_parts($product, $parts);

    if ($size = mv_extract_size($description)) {
        mv_add_part($parts, 'Size', $size);
    }

    if (mv_is_hair_extension_family($family) && preg_match('/\b(\d)\s*pc\b/i', $description, $match)) {
        mv_add_part($parts, 'Bundle', $match[1] . 'pc');
    }

    return $parts;
}

function mv_variant_name(array $parts): string
{
    return implode('; ', array_map(
        fn (array $part): string => $part['label'] . ': ' . $part['value'],
        array_values($parts),
    ));
}

$query = MamadoProduct::query()
    ->whereNotNull('brand_label')
    ->where('brand_label', '<>', '')
    ->whereNotNull('family_name')
    ->where('family_name', '<>', '')
    ->orderBy('brand_label')
    ->orderBy('family_name')
    ->orderBy('item_code');

if ($onlyBrand !== null && $onlyBrand !== '') {
    $query->where('brand_label', $onlyBrand);
}

$rows = $query->get();
$updated = 0;
$alreadyCorrect = 0;
$markedReviewPending = 0;
$clearedReviewPending = 0;
$missing = [];
$brandStats = [];

$reportDir = __DIR__ . '/../storage/app/mamado';
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$csv = fopen($reportDir . '/all-brand-variant-candidates.csv', 'w');
fputcsv($csv, ['brand', 'family_name', 'item_code', 'item_description', 'current_variant_name', 'candidate_variant_name']);

foreach ($rows as $product) {
    $brand = (string) $product->brand_label;
    $candidate = mv_variant_name(mv_variant_parts($product));

    $brandStats[$brand] ??= [
        'product_count' => 0,
        'candidate_count' => 0,
        'missing_count' => 0,
        'updated_count' => 0,
        'review_pending_count' => 0,
    ];
    $brandStats[$brand]['product_count']++;

    fputcsv($csv, [
        $brand,
        $product->family_name,
        $product->item_code,
        $product->item_description,
        $product->variant_name,
        $candidate,
    ]);

    if ($candidate === '') {
        $brandStats[$brand]['missing_count']++;
        $brandStats[$brand]['review_pending_count']++;
        $missing[] = [
            'brand' => $brand,
            'family_name' => $product->family_name,
            'item_code' => $product->item_code,
            'item_description' => $product->item_description,
        ];

        if ($product->status !== 'variant_review_pending') {
            $markedReviewPending++;

            if (! $dryRun) {
                $product->forceFill([
                    'variant_name' => null,
                    'status' => 'variant_review_pending',
                    'notes' => mv_clean_spaces(trim(($product->notes ? $product->notes . ' ' : '') . 'Variant review pending: no safe variant was parsed from the Mamado source description.')),
                ])->save();
            }
        }

        continue;
    }

    $brandStats[$brand]['candidate_count']++;

    if ($product->status === 'variant_review_pending') {
        $clearedReviewPending++;

        if (! $dryRun) {
            $product->forceFill(['status' => 'source_only'])->save();
        }
    }

    if ($product->variant_name === $candidate) {
        $alreadyCorrect++;
        continue;
    }

    $updated++;
    $brandStats[$brand]['updated_count']++;

    if (! $dryRun) {
        $product->forceFill(['variant_name' => $candidate])->save();
    }
}

fclose($csv);

$missingCsv = fopen($reportDir . '/all-brand-variant-missing.csv', 'w');
fputcsv($missingCsv, ['brand', 'family_name', 'item_code', 'item_description']);
foreach ($missing as $row) {
    fputcsv($missingCsv, $row);
}
fclose($missingCsv);

$summary = [
    'dry_run' => $dryRun,
    'only_brand' => $onlyBrand,
    'source_products' => $rows->count(),
    'variant_candidates' => $rows->count() - count($missing),
    'missing_variant_count' => count($missing),
    'updated_rows' => $updated,
    'already_correct_rows' => $alreadyCorrect,
    'marked_review_pending_rows' => $markedReviewPending,
    'cleared_review_pending_rows' => $clearedReviewPending,
    'report_path' => $reportDir . '/all-brand-variant-candidates.csv',
    'missing_report_path' => $reportDir . '/all-brand-variant-missing.csv',
    'brands' => $brandStats,
];

file_put_contents(
    $reportDir . '/all-brand-variant-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
