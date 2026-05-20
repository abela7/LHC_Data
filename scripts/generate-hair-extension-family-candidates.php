<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\HairExtensionIntake;
use Illuminate\Support\Str;

$timestamp = date('Ymd-His');
$reportDir = public_path('reports');

if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$csvPath = $reportDir."/hair-extension-family-candidates-{$timestamp}.csv";
$htmlPath = $reportDir."/hair-extension-family-candidates-{$timestamp}.html";
$latestCsvPath = $reportDir.'/hair-extension-family-candidates-latest.csv';
$latestHtmlPath = $reportDir.'/hair-extension-family-candidates-latest.html';

function fc_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function fc_norm_key(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function fc_path_text(mixed $path): string
{
    return is_array($path) ? implode(' > ', array_filter(array_map('fc_clean', $path))) : fc_clean($path);
}

function fc_type(string $type): string
{
    $key = fc_norm_key($type);

    return [
        'crochetbraid' => 'Crochet Braid',
        'crochetbraids' => 'Crochet Braid',
        'crochethair' => 'Crochet Braid',
        'braid' => 'Braid',
        'braidinghair' => 'Braid',
        'bulk' => 'Bulk Hair',
        'bulkhair' => 'Bulk Hair',
        'bulkhumanhair' => 'Bulk Hair',
        'weave' => 'Weave',
        'wefthairextensions' => 'Weave',
        'ponytail' => 'Ponytail',
        'ponytails' => 'Ponytails',
        'drawstringponytail' => 'Ponytail',
        'clipinextensions' => 'Clip-in Extensions',
        'clipinhairextensions' => 'Clip-in Extensions',
        'syntheticclipins' => 'Synthetic Clip-Ins',
        'bangfringe' => 'Bang / Fringe',
        'bun' => 'Bun',
        'scrunchie' => 'Scrunchie',
        'closurefrontal' => 'Closure / Frontal',
        'fusionhairextensions' => 'Fusion Extensions',
        'fusionextensions' => 'Fusion Extensions',
        'prebondedfusionextensions' => 'Fusion Extensions',
        'tapeinextensions' => 'Tape-in Extensions',
        'sticktipextensions' => 'Stick Tip Extensions',
        'microloopextensions' => 'Micro Loop Extensions',
        'nanoringextensions' => 'Nano Ring Extensions',
    ][$key] ?? fc_clean($type);
}

function fc_variant_display(mixed $value): string
{
    return trim(preg_replace(['/ \)/', '/\( /', '/\s+/', '/\s+"/'], [')', '(', ' ', '"'], (string) $value) ?: '');
}

function fc_variant_key(mixed $value): string
{
    return fc_norm_key(fc_variant_display($value));
}

function fc_photo_url(HairExtensionIntake $intake): ?string
{
    $photo = $intake->photos->first();
    if ($photo && fc_clean($photo->storage_path) !== '') {
        return '../storage/'.ltrim($photo->storage_path, '/');
    }

    if (fc_clean($intake->photo_path) !== '') {
        return '../storage/'.ltrim($intake->photo_path, '/');
    }

    return null;
}

function fc_sku_rows(HairExtensionIntake $intake): array
{
    $structure = $intake->variant_structure ?: [];
    $mainAxis = fc_clean($structure['main_axis'] ?? 'Main') ?: 'Main';
    $rows = [];

    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $common = [];
        foreach (($sku['common_attributes'] ?? []) as $name => $values) {
            foreach ((array) $values as $value) {
                if (fc_clean($value) !== '') {
                    $common[] = fc_clean($name).': '.fc_variant_display($value);
                }
            }
        }

        $rows[] = [
            'main_axis' => fc_clean($sku['main_axis'] ?? $mainAxis) ?: $mainAxis,
            'main_value' => fc_variant_display($sku['main_value'] ?? ''),
            'sub_axis' => fc_clean($sku['sub_axis'] ?? 'Sub') ?: 'Sub',
            'sub_value' => fc_variant_display($sku['sub_value'] ?? ''),
            'common' => $common,
        ];
    }

    if ($rows !== []) {
        return $rows;
    }

    foreach (($structure['groups'] ?? []) as $group) {
        $subValues = $group['sub_values'] ?? [];
        if ($subValues === []) {
            $rows[] = [
                'main_axis' => $mainAxis,
                'main_value' => fc_variant_display($group['main_value'] ?? ''),
                'sub_axis' => fc_clean($group['sub_axis'] ?? 'Sub') ?: 'Sub',
                'sub_value' => '',
                'common' => [],
            ];
            continue;
        }

        foreach ($subValues as $subValue) {
            $rows[] = [
                'main_axis' => $mainAxis,
                'main_value' => fc_variant_display($group['main_value'] ?? ''),
                'sub_axis' => fc_clean($group['sub_axis'] ?? 'Sub') ?: 'Sub',
                'sub_value' => fc_variant_display($subValue),
                'common' => [],
            ];
        }
    }

    return $rows ?: [[
        'main_axis' => 'Single',
        'main_value' => 'Single product',
        'sub_axis' => 'Sub',
        'sub_value' => '',
        'common' => [],
    ]];
}

$families = [];

HairExtensionIntake::query()
    ->with(['photos'])
    ->where('status', 'submitted')
    ->orderBy('brand_name')
    ->orderBy('style_name')
    ->orderBy('id')
    ->get()
    ->each(function (HairExtensionIntake $intake) use (&$families): void {
        $brand = fc_clean($intake->brand_name) ?: 'Unknown';
        $path = fc_path_text($intake->classification_path);
        $type = fc_type((string) $intake->product_type_name);
        $style = fc_clean($intake->style_name) ?: fc_clean($intake->observed_product_name) ?: 'Unknown family';
        $key = implode('|', [fc_norm_key($brand), fc_norm_key($path), fc_norm_key($type), fc_norm_key($style)]);

        if (! isset($families[$key])) {
            $families[$key] = [
                'brand' => $brand,
                'grouping_path' => $path,
                'product_type' => $type,
                'style_family' => $style,
                'intake_ids' => [],
                'photo_urls' => [],
                'main_axis' => '',
                'sub_axis' => '',
                'main_values' => [],
                'sub_values' => [],
                'common_values' => [],
                'sku_keys' => [],
                'sku_rows' => [],
                'catalogue_linked_count' => 0,
            ];
        }

        $families[$key]['intake_ids'][] = $intake->id;
        if ($intake->brand_catalogue_style_id) {
            $families[$key]['catalogue_linked_count']++;
        }

        if ($photoUrl = fc_photo_url($intake)) {
            $families[$key]['photo_urls'][$photoUrl] = $photoUrl;
        }

        foreach (fc_sku_rows($intake) as $sku) {
            if ($families[$key]['main_axis'] === '' && fc_clean($sku['main_axis']) !== '') {
                $families[$key]['main_axis'] = fc_clean($sku['main_axis']);
            }
            if ($families[$key]['sub_axis'] === '' && fc_clean($sku['sub_axis']) !== '') {
                $families[$key]['sub_axis'] = fc_clean($sku['sub_axis']);
            }

            if (fc_clean($sku['main_value']) !== '') {
                $families[$key]['main_values'][fc_variant_key($sku['main_value'])] = fc_variant_display($sku['main_value']);
            }
            if (fc_clean($sku['sub_value']) !== '') {
                $families[$key]['sub_values'][fc_variant_key($sku['sub_value'])] = fc_variant_display($sku['sub_value']);
            }
            foreach ($sku['common'] as $common) {
                $families[$key]['common_values'][fc_variant_key($common)] = $common;
            }

            $skuKey = implode('|', [
                fc_variant_key($sku['main_value']),
                fc_variant_key($sku['sub_value']),
                fc_norm_key(implode(',', $sku['common'])),
            ]);

            if (! isset($families[$key]['sku_keys'][$skuKey])) {
                $families[$key]['sku_keys'][$skuKey] = true;
                $families[$key]['sku_rows'][] = trim(implode(' / ', array_filter([
                    fc_clean($sku['main_value']) !== '' ? fc_clean($sku['main_axis']).': '.fc_variant_display($sku['main_value']) : '',
                    fc_clean($sku['sub_value']) !== '' ? fc_clean($sku['sub_axis']).': '.fc_variant_display($sku['sub_value']) : '',
                    implode(' / ', $sku['common']),
                ]))) ?: 'Single product';
            }
        }
    });

uasort($families, function (array $a, array $b): int {
    return [$a['brand'], $a['product_type'], $a['style_family']] <=> [$b['brand'], $b['product_type'], $b['style_family']];
});

$rows = [];
foreach ($families as $family) {
    $rows[] = [
        'brand' => $family['brand'],
        'grouping_path' => $family['grouping_path'],
        'product_type' => $family['product_type'],
        'style_family' => $family['style_family'],
        'intake_count' => count($family['intake_ids']),
        'intake_ids' => implode(', ', $family['intake_ids']),
        'photo_count' => count($family['photo_urls']),
        'catalogue_linked_count' => $family['catalogue_linked_count'],
        'main_axis' => $family['main_axis'] ?: 'Main',
        'main_values' => implode(', ', array_values($family['main_values'])),
        'sub_axis' => $family['sub_axis'] ?: 'Sub',
        'sub_values' => implode(', ', array_values($family['sub_values'])),
        'common_values' => implode(', ', array_values($family['common_values'])),
        'candidate_sku_count' => count($family['sku_rows']),
        'candidate_skus' => implode(' | ', $family['sku_rows']),
    ];
}

$csv = fopen($csvPath, 'w');
fputcsv($csv, array_keys($rows[0] ?? []));
foreach ($rows as $row) {
    fputcsv($csv, $row);
}
fclose($csv);
copy($csvPath, $latestCsvPath);

$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
$html .= '<title>Hair Extension Family Candidates</title>';
$html .= '<style>
body{margin:0;background:#f5efe4;color:#17201e;font-family:Georgia,"Times New Roman",serif}
.wrap{max-width:1500px;margin:0 auto;padding:28px}.hero,.section{background:#fffdf8;border:1px solid #ded2bf;border-radius:24px;padding:24px;box-shadow:0 12px 30px rgba(44,36,24,.08)}
h1{margin:0;font-size:38px}.muted{color:#66716c}.toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.btn{border:1px solid #244b43;color:#244b43;text-decoration:none;border-radius:12px;padding:10px 14px;background:#fff}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin:20px 0}.card{background:#fffdf8;border:1px solid #ded2bf;border-radius:18px;padding:16px}.card strong{display:block;font-size:30px}
input{width:100%;box-sizing:border-box;border:1px solid #d8cbb8;border-radius:14px;padding:12px;margin:14px 0;font-size:16px}.table-wrap{max-height:78vh;overflow:auto;border:1px solid #ded2bf;border-radius:18px}
table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #e8dece;padding:10px;text-align:left;vertical-align:top}th{position:sticky;top:0;background:#efe4d2;z-index:1}.nowrap{white-space:nowrap}.sku{max-width:520px}.thumbs{display:flex;gap:6px;flex-wrap:wrap}.thumb{width:42px;height:42px;object-fit:cover;border-radius:9px;border:1px solid #d8cbb8}
</style></head><body><div class="wrap">';
$html .= '<div class="hero"><p class="muted">Generated '.htmlspecialchars(date('Y-m-d H:i:s')).'</p><h1>Hair Extension Family Candidates</h1><p>This groups clean shop-floor intakes into likely product families. Use it as the bridge between raw submitted observations and final sellable product families.</p><div class="toolbar"><a class="btn" href="'.htmlspecialchars(basename($csvPath)).'">Download CSV</a><a class="btn" href="/LHC_Data/public/reports/hair-extension-intake-audit-latest.html">Open Intake Audit</a></div></div>';
$html .= '<div class="grid"><div class="card"><span class="muted">Submitted intakes</span><strong>'.number_format(HairExtensionIntake::where('status', 'submitted')->count()).'</strong></div><div class="card"><span class="muted">Candidate families</span><strong>'.number_format(count($rows)).'</strong></div><div class="card"><span class="muted">Candidate SKUs</span><strong>'.number_format(array_sum(array_column($rows, 'candidate_sku_count'))).'</strong></div></div>';
$html .= '<div class="section"><h2>Family Candidate Table</h2><input id="filter" placeholder="Filter brand, type, style, variant..." oninput="filterRows()"><div class="table-wrap"><table id="families"><thead><tr>';
foreach (['Brand', 'Path', 'Type', 'Style / Family', 'Intakes', 'Photos', 'Axes', 'Variant Values', 'Candidate SKUs'] as $header) {
    $html .= '<th>'.htmlspecialchars($header).'</th>';
}
$html .= '</tr></thead><tbody>';

foreach ($families as $family) {
    $rowText = strtolower(json_encode($family, JSON_UNESCAPED_SLASHES));
    $html .= '<tr data-search="'.htmlspecialchars($rowText).'">';
    $html .= '<td class="nowrap"><strong>'.htmlspecialchars($family['brand']).'</strong></td>';
    $html .= '<td>'.htmlspecialchars($family['grouping_path'] ?: 'N/A').'</td>';
    $html .= '<td>'.htmlspecialchars($family['product_type']).'</td>';
    $html .= '<td><strong>'.htmlspecialchars($family['style_family']).'</strong></td>';
    $html .= '<td class="nowrap">'.number_format(count($family['intake_ids'])).'<br><span class="muted">#'.htmlspecialchars(implode(', #', $family['intake_ids'])).'</span></td>';
    $html .= '<td><div class="thumbs">';
    foreach (array_slice(array_values($family['photo_urls']), 0, 6) as $url) {
        $html .= '<a href="'.htmlspecialchars($url).'"><img class="thumb" src="'.htmlspecialchars($url).'" loading="lazy"></a>';
    }
    $html .= '</div></td>';
    $html .= '<td>'.htmlspecialchars(($family['main_axis'] ?: 'Main').' / '.($family['sub_axis'] ?: 'Sub')).'</td>';
    $html .= '<td><span class="muted">Main:</span> '.htmlspecialchars(implode(', ', array_values($family['main_values'])) ?: 'N/A').'<br><span class="muted">Sub:</span> '.htmlspecialchars(implode(', ', array_values($family['sub_values'])) ?: 'N/A').'<br><span class="muted">Common:</span> '.htmlspecialchars(implode(', ', array_values($family['common_values'])) ?: 'N/A').'</td>';
    $html .= '<td class="sku"><strong>'.number_format(count($family['sku_rows'])).'</strong><br>'.htmlspecialchars(implode(' | ', array_slice($family['sku_rows'], 0, 24))).(count($family['sku_rows']) > 24 ? ' ...' : '').'</td>';
    $html .= '</tr>';
}

$html .= '</tbody></table></div></div><script>function filterRows(){const q=document.getElementById("filter").value.toLowerCase();document.querySelectorAll("#families tbody tr").forEach(tr=>tr.style.display=tr.dataset.search.includes(q)?"":"none");}</script></div></body></html>';

file_put_contents($htmlPath, $html);
copy($htmlPath, $latestHtmlPath);

echo json_encode([
    'submitted_intakes' => HairExtensionIntake::where('status', 'submitted')->count(),
    'candidate_families' => count($rows),
    'candidate_skus' => array_sum(array_column($rows, 'candidate_sku_count')),
    'csv' => $csvPath,
    'html' => $htmlPath,
    'latest_csv' => $latestCsvPath,
    'latest_html' => $latestHtmlPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
