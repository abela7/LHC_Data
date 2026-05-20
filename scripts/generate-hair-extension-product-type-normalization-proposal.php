<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\HairExtensionIntake;
use Illuminate\Support\Str;

$reportDir = public_path('reports');
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$timestamp = date('Ymd-His');
$csvPath = $reportDir."/hair-extension-product-type-normalization-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/hair-extension-product-type-normalization-latest.csv';
$htmlPath = $reportDir."/hair-extension-product-type-normalization-{$timestamp}.html";
$latestHtmlPath = $reportDir.'/hair-extension-product-type-normalization-latest.html';

function clean_text(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function key_text(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function path_string(mixed $value): string
{
    return is_array($value) ? implode(' > ', array_filter(array_map('clean_text', $value))) : clean_text($value);
}

function rule_for_type(string $currentType, ?string $brand = null, ?string $style = null): array
{
    $key = key_text($currentType);

    $direct = [
        'crochetbraid' => ['Crochet Braid', 'product_type_alias', '', 'A'],
        'crochetbraids' => ['Crochet Braid', 'product_type_alias', '', 'A'],
        'crochethair' => ['Crochet Braid', 'product_type_alias', '', 'A'],
        'crochet' => ['Crochet Braid', 'product_type_alias', '', 'A'],
        'crochetbraidhair' => ['Crochet Braid', 'product_type_alias', '', 'A'],
        'braid' => ['Braid', 'already_clean', '', 'A'],
        'braidinghair' => ['Braid', 'product_type_alias', '', 'A'],
        'bulkhair' => ['Bulk Hair', 'already_clean', '', 'A'],
        'bulk' => ['Bulk Hair', 'product_type_alias', '', 'A'],
        'bulkhumanhair' => ['Bulk Hair', 'product_type_alias_material_note', 'Human Hair', 'A'],
        'hairbulk' => ['Bulk Hair', 'product_type_alias', '', 'A'],
        'weave' => ['Weave', 'already_clean', '', 'A'],
        'wefthairextensions' => ['Weave', 'product_type_alias', '', 'A'],
        'drawstringponytail' => ['Ponytail', 'product_type_alias', '', 'A'],
        'ezponytail' => ['Ponytail', 'product_type_alias_line_note', 'EZ Ponytail', 'A'],
        'instantpony' => ['Ponytail', 'product_type_alias_line_note', 'Instant Pony', 'A'],
        'ponytailsdrawstrings' => ['Ponytail', 'product_type_alias', '', 'A'],
        'ponytail' => ['Ponytail', 'already_clean', '', 'A'],
        'ponytails' => ['Ponytails', 'already_clean', '', 'A'],
        'wraparoundponytail' => ['Ponytail', 'product_type_alias', '', 'A'],
        'clipinextensions' => ['Clip-in Extensions', 'already_clean', '', 'A'],
        'clipinhairextensions' => ['Clip-in Extensions', 'product_type_alias', '', 'A'],
        'syntheticclipins' => ['Synthetic Clip-Ins', 'already_clean', '', 'A'],
        'clipinfringe' => ['Bang / Fringe', 'specific_format', '', 'B'],
        'hairbunextension' => ['Bun', 'product_type_alias', '', 'A'],
        'bun' => ['Bun', 'already_clean_specialist', '', 'A'],
        'hairscrunchieextension' => ['Scrunchie', 'product_type_alias', '', 'A'],
        'scrunchie' => ['Scrunchie', 'already_clean_specialist', '', 'A'],
        'tapeinhairextensions' => ['Tape-in Extensions', 'product_type_alias', '', 'A'],
        'tapeinextensions' => ['Tape-in Extensions', 'already_clean_specialist', '', 'A'],
        'sticktiphairextensions' => ['Stick Tip Extensions', 'product_type_alias', '', 'A'],
        'sticktipextensions' => ['Stick Tip Extensions', 'already_clean_specialist', '', 'A'],
        'microloophairextensions' => ['Micro Loop Extensions', 'product_type_alias', '', 'A'],
        'microloopextensions' => ['Micro Loop Extensions', 'already_clean_specialist', '', 'A'],
        'nanoringhairextensions' => ['Nano Ring Extensions', 'product_type_alias', '', 'A'],
        'nanoringextensions' => ['Nano Ring Extensions', 'already_clean_specialist', '', 'A'],
        'fusionhairextensions' => ['Fusion Extensions', 'product_type_alias', '', 'A'],
        'fusionextensions' => ['Fusion Extensions', 'already_clean_specialist', '', 'A'],
        'prebondedfusionextensions' => ['Fusion Extensions', 'product_type_alias', 'Pre-bonded', 'A'],
        'laceclosure' => ['Closure / Frontal', 'product_type_alias', 'Lace Closure', 'A'],
        'closurefrontal' => ['Closure / Frontal', 'already_clean_specialist', '', 'A'],
        'bangfringe' => ['Bang / Fringe', 'already_clean_specialist', '', 'A'],
        'clipinfringe' => ['Bang / Fringe', 'specific_format', 'Clip-in Fringe', 'A'],
    ];

    if (isset($direct[$key])) {
        return [
            'target_type' => $direct[$key][0],
            'action' => $direct[$key][1],
            'move_to_grouping_or_material' => $direct[$key][2],
            'confidence' => $direct[$key][3],
            'notes' => $direct[$key][0] === $currentType ? 'Already clean.' : 'Normalize product type wording.',
        ];
    }

    $lineRules = [
        'boho' => ['Crochet Braid', 'move_to_grouping_path', 'BOHO', 'A', 'BOHO is a line/grouping, not the physical product type.'],
        'cherishjunior' => ['Crochet Braid', 'move_to_grouping_path', 'Cherish Junior', 'B', 'Usually a Cherish line. Confirm physical format if the item is loose bulk rather than crochet.'],
        'haircouture' => ['Weave', 'move_to_grouping_path', 'Hair Couture', 'B', 'Hair Couture is a line. Most current examples appear under Sleek hair/weave context.'],
        'crochettwistlochair' => ['Crochet Braid', 'move_to_grouping_path', 'Crochet, Twist & Loc Hair', 'A', 'Supplier category maps to crochet braid/loc/twist physical format.'],
        'cherishspiralfrenchcurl3xbraidprestretched' => ['Braid', 'split_type_style_common', 'Cherish > French Curl', 'B', 'Move Spiral French Curl to style/family and 3X to common variant.'],
    ];

    if (isset($lineRules[$key])) {
        return [
            'target_type' => $lineRules[$key][0],
            'action' => $lineRules[$key][1],
            'move_to_grouping_or_material' => $lineRules[$key][2],
            'confidence' => $lineRules[$key][3],
            'notes' => $lineRules[$key][4],
        ];
    }

    $materialRules = [
        'brazilianhairweave' => ['Weave', 'move_material_or_texture', 'Brazilian Hair', 'B', 'Brazilian describes material/texture/line; physical type is weave.'],
        'virginremyhairweave' => ['Weave', 'move_material_or_texture', 'Virgin Remy Hair', 'A', 'Virgin Remy is material/quality; physical type is weave.'],
        'remyhairweave' => ['Weave', 'move_material_or_texture', 'Remy Hair', 'A', 'Remy is material/quality; physical type is weave.'],
        'europeanweave' => ['Weave', 'move_to_style_or_grouping', 'European Weave', 'A', 'European Weave is the style/family; product type is weave.'],
        'brazilianhairbulk' => ['Bulk Hair', 'move_material_or_texture', 'Brazilian Hair', 'A', 'Brazilian describes material/texture; physical type is bulk hair.'],
    ];

    if (isset($materialRules[$key])) {
        return [
            'target_type' => $materialRules[$key][0],
            'action' => $materialRules[$key][1],
            'move_to_grouping_or_material' => $materialRules[$key][2],
            'confidence' => $materialRules[$key][3],
            'notes' => $materialRules[$key][4],
        ];
    }

    return [
        'target_type' => '',
        'action' => 'manual_review',
        'move_to_grouping_or_material' => '',
        'confidence' => 'D',
        'notes' => 'No safe normalization rule yet.',
    ];
}

$intakes = HairExtensionIntake::query()
    ->where('status', 'submitted')
    ->orderBy('product_type_name')
    ->orderBy('brand_name')
    ->orderBy('style_name')
    ->get();

$groups = $intakes->groupBy(fn (HairExtensionIntake $intake): string => clean_text($intake->product_type_name) ?: '(blank)');
$rows = [];

foreach ($groups as $currentType => $records) {
    $sample = $records->first();
    $rule = rule_for_type($currentType, $sample?->brand_name, $sample?->style_name);
    $sampleStyles = $records
        ->take(8)
        ->map(fn (HairExtensionIntake $intake): string => '#'.$intake->id.' '.$intake->brand_name.' / '.$intake->style_name.' / '.path_string($intake->classification_path))
        ->implode(' || ');

    $rows[] = [
        'current_product_type' => $currentType,
        'record_count' => $records->count(),
        'recommended_product_type' => $rule['target_type'],
        'action' => $rule['action'],
        'move_to_grouping_or_material' => $rule['move_to_grouping_or_material'],
        'confidence' => $rule['confidence'],
        'notes' => $rule['notes'],
        'sample_records' => $sampleStyles,
    ];
}

usort($rows, fn (array $a, array $b): int => [$a['confidence'], -$a['record_count']] <=> [$b['confidence'], -$b['record_count']]);

$csv = fopen($csvPath, 'w');
fputcsv($csv, array_keys($rows[0] ?? []));
foreach ($rows as $row) {
    fputcsv($csv, $row);
}
fclose($csv);
copy($csvPath, $latestCsvPath);

$html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
$html .= '<title>Hair Extension Product Type Normalization</title>';
$html .= '<style>body{font-family:Georgia,"Times New Roman",serif;background:#f7f1e8;color:#17201e;margin:0}.wrap{max-width:1300px;margin:0 auto;padding:28px}.panel{background:#fffdf8;border:1px solid #ded2bf;border-radius:22px;padding:22px;margin-bottom:18px}h1{margin:0;font-size:34px}table{width:100%;border-collapse:collapse;background:#fffdf8}th,td{border-bottom:1px solid #e6ddcf;padding:10px;text-align:left;vertical-align:top;font-size:14px}th{background:#efe5d6;position:sticky;top:0}.pill{border-radius:999px;padding:4px 8px;font-weight:bold}.A{background:#e5f5ec;color:#145a38}.B{background:#fff3cd;color:#6a4b00}.D{background:#f8d7da;color:#842029}.table{max-height:75vh;overflow:auto;border:1px solid #e6ddcf;border-radius:16px}a{color:#064f45}.btn{display:inline-block;border:1px solid #064f45;border-radius:12px;padding:10px 14px;text-decoration:none;margin-right:8px}</style></head><body><div class="wrap">';
$html .= '<div class="panel"><h1>Hair Extension Product Type Normalization Proposal</h1><p>This is a proposal only. No intake data has been changed.</p><p><a class="btn" href="'.htmlspecialchars(basename($csvPath)).'">Download CSV</a></p></div>';
$html .= '<div class="panel"><div class="table"><table><thead><tr>';
foreach (array_keys($rows[0] ?? []) as $header) {
    $html .= '<th>'.htmlspecialchars(str_replace('_', ' ', Str::title($header))).'</th>';
}
$html .= '</tr></thead><tbody>';
foreach ($rows as $row) {
    $html .= '<tr>';
    foreach ($row as $key => $value) {
        if ($key === 'confidence') {
            $html .= '<td><span class="pill '.htmlspecialchars($value).'">'.htmlspecialchars($value).'</span></td>';
        } else {
            $html .= '<td>'.htmlspecialchars((string) $value).'</td>';
        }
    }
    $html .= '</tr>';
}
$html .= '</tbody></table></div></div></div></body></html>';

file_put_contents($htmlPath, $html);
copy($htmlPath, $latestHtmlPath);

echo json_encode([
    'groups' => count($rows),
    'csv' => $csvPath,
    'html' => $htmlPath,
    'latest_csv' => $latestCsvPath,
    'latest_html' => $latestHtmlPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
