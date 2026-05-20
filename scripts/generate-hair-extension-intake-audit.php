<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\HairExtensionIntake;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$timestamp = date('Ymd-His');
$reportDir = public_path('reports');
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$csvPath = $reportDir."/hair-extension-intake-audit-{$timestamp}.csv";
$htmlPath = $reportDir."/hair-extension-intake-audit-{$timestamp}.html";
$latestHtmlPath = $reportDir.'/hair-extension-intake-audit-latest.html';
$latestCsvPath = $reportDir.'/hair-extension-intake-audit-latest.csv';

function clean_value(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function filled_value(mixed $value): bool
{
    return clean_value($value) !== '';
}

function norm_key(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function path_text(mixed $path): string
{
    return is_array($path) ? implode(' > ', array_filter(array_map('clean_value', $path))) : clean_value($path);
}

function axis_summary(HairExtensionIntake $intake): array
{
    $structure = $intake->variant_structure ?: [];
    $groups = $structure['groups'] ?? [];
    $mainValues = [];
    $subValues = [];
    $subAxis = null;
    $commonValues = [];

    foreach ($groups as $group) {
        if (filled_value($group['main_value'] ?? null)) {
            $mainValues[] = clean_value($group['main_value']);
        }

        if (filled_value($group['sub_axis'] ?? null)) {
            $subAxis = clean_value($group['sub_axis']);
        }

        foreach (($group['sub_values'] ?? []) as $subValue) {
            if (filled_value($subValue)) {
                $subValues[] = clean_value($subValue);
            }
        }
    }

    foreach (($structure['common_variants'] ?? []) as $commonVariant) {
        foreach (($commonVariant['values'] ?? []) as $commonValue) {
            if (filled_value($commonValue)) {
                $commonValues[] = clean_value($commonValue);
            }
        }
    }

    $mainValues = array_values(array_unique($mainValues));
    $subValues = array_values(array_unique($subValues));
    $commonValues = array_values(array_unique($commonValues));

    return [
        'main_axis' => clean_value($structure['main_axis'] ?? 'Main'),
        'main_values' => $mainValues,
        'sub_axis' => $subAxis ?: 'Sub',
        'sub_values' => $subValues,
        'common_values' => $commonValues,
        'combo_count' => max(1, array_reduce($groups, fn (int $carry, array $group): int => $carry + max(1, count($group['sub_values'] ?? [])), 0)),
    ];
}

function normalize_product_type(?string $type): array
{
    $raw = clean_value($type);
    $key = norm_key($raw);

    $map = [
        'crochetbraid' => 'Crochet Braid',
        'crochetbraids' => 'Crochet Braid',
        'crochethair' => 'Crochet Braid',
        'crochetbraidhair' => 'Crochet Braid',
        'crochet' => 'Crochet Braid',
        'braid' => 'Braid',
        'braidinghair' => 'Braid',
        'bulkhair' => 'Bulk Hair',
        'bulkhumanhair' => 'Bulk Hair',
        'bulk' => 'Bulk Hair',
        'weave' => 'Weave',
        'wefthairextensions' => 'Weave',
        'drawstringponytail' => 'Ponytail',
        'ponytail' => 'Ponytail',
        'ponytails' => 'Ponytails',
        'ponytailsdrawstrings' => 'Ponytail',
        'ezponytail' => 'Ponytail',
        'wraparoundponytail' => 'Ponytail',
        'clipinextensions' => 'Clip-in Extensions',
        'clipinhairextensions' => 'Clip-in Extensions',
        'syntheticclipins' => 'Synthetic Clip-Ins',
        'clipinfringe' => 'Bang / Fringe',
        'bangfringe' => 'Bang / Fringe',
        'hairbunextension' => 'Bun',
        'bun' => 'Bun',
        'hairscrunchieextension' => 'Scrunchie',
        'scrunchie' => 'Scrunchie',
        'closurefrontal' => 'Closure / Frontal',
        'fusionhairextensions' => 'Fusion Extensions',
        'fusionextensions' => 'Fusion Extensions',
        'prebondedfusionextensions' => 'Fusion Extensions',
        'tapeinextensions' => 'Tape-in Extensions',
        'sticktipextensions' => 'Stick Tip Extensions',
        'microloopextensions' => 'Micro Loop Extensions',
        'nanoringextensions' => 'Nano Ring Extensions',
    ];

    if ($raw === '') {
        return ['normalized' => '', 'status' => 'missing'];
    }

    if (isset($map[$key])) {
        return ['normalized' => $map[$key], 'status' => $raw === $map[$key] ? 'clean' : 'normalize'];
    }

    return ['normalized' => '', 'status' => 'suspect'];
}

function common_variant_issues(array $commonValues): array
{
    $issues = [];
    $normalizations = [];

    foreach ($commonValues as $value) {
        $value = clean_value($value);
        if ($value === '') {
            continue;
        }

        if (preg_match('/\b(\d+)\s*x\b/i', $value, $match)) {
            $normalized = strtoupper($match[1].'X');
            if ($value !== $normalized) {
                $normalizations[] = "{$value} -> {$normalized}";
            }
            continue;
        }

        if (preg_match('/\b(\d+)\s*(pack|packs|pc|pcs|piece|pieces|bundle|bundles)\b/i', $value, $match)) {
            continue;
        }

        $issues[] = $value;
    }

    return [$issues, $normalizations];
}

function storage_url_for(?object $photo, HairExtensionIntake $intake): ?string
{
    if ($photo && filled_value($photo->storage_path)) {
        return '../storage/'.ltrim($photo->storage_path, '/');
    }

    if (filled_value($intake->photo_path)) {
        return '../storage/'.ltrim($intake->photo_path, '/');
    }

    return null;
}

function severity_status(array $blockers, array $warnings): string
{
    if ($blockers !== []) {
        return 'needs_fix';
    }

    if (count($warnings) >= 3) {
        return 'review';
    }

    if ($warnings !== []) {
        return 'minor_cleanup';
    }

    return 'stage1_clean';
}

function score_for(array $blockers, array $warnings): int
{
    return max(0, 100 - (count($blockers) * 18) - (count($warnings) * 6));
}

$intakes = HairExtensionIntake::query()
    ->with(['photos', 'brand', 'productType', 'style', 'store', 'section', 'subsection'])
    ->where('status', 'submitted')
    ->orderBy('brand_name')
    ->orderBy('style_name')
    ->orderBy('id')
    ->get();

$batchItems = DB::table('shop_photo_batch_items')
    ->join('shop_photo_batches', 'shop_photo_batches.id', '=', 'shop_photo_batch_items.shop_photo_batch_id')
    ->whereNotNull('shop_photo_batch_items.hair_extension_intake_id')
    ->select(
        'shop_photo_batch_items.hair_extension_intake_id',
        'shop_photo_batch_items.sequence',
        'shop_photo_batch_items.filename',
        'shop_photo_batches.slug as batch_slug'
    )
    ->get()
    ->keyBy('hair_extension_intake_id');

$duplicateBuckets = [];
foreach ($intakes as $intake) {
    $axis = axis_summary($intake);
    $duplicateKey = implode('|', [
        norm_key($intake->brand_name),
        norm_key(normalize_product_type($intake->product_type_name)['normalized'] ?: $intake->product_type_name),
        norm_key($intake->style_name),
        norm_key(path_text($intake->classification_path)),
        norm_key(implode(',', $axis['main_values'])),
        norm_key(implode(',', $axis['sub_values'])),
        norm_key(implode(',', $axis['common_values'])),
    ]);
    $duplicateBuckets[$duplicateKey][] = $intake->id;
}

$duplicateMap = [];
foreach ($duplicateBuckets as $ids) {
    if (count($ids) > 1) {
        foreach ($ids as $id) {
            $duplicateMap[$id] = $ids;
        }
    }
}

$rows = [];
$summary = [
    'total' => $intakes->count(),
    'stage1_clean' => 0,
    'minor_cleanup' => 0,
    'review' => 0,
    'needs_fix' => 0,
    'with_photo' => 0,
    'missing_photo' => 0,
    'with_location' => 0,
    'linked_catalogue_style' => 0,
    'duplicate_records' => 0,
];
$brandSummary = [];
$typeSummary = [];

foreach ($intakes as $intake) {
    $axis = axis_summary($intake);
    [$badCommon, $commonNormalizations] = common_variant_issues($axis['common_values']);
    $typeInfo = normalize_product_type($intake->product_type_name);
    $primaryPhoto = $intake->photos->first();
    $photoUrl = storage_url_for($primaryPhoto, $intake);
    $batch = $batchItems->get($intake->id);
    $blockers = [];
    $warnings = [];
    $info = [];
    $actions = [];

    if (! filled_value($intake->brand_name)) {
        $blockers[] = 'Missing brand';
        $actions[] = 'Add brand';
    }

    if (! filled_value($intake->style_name)) {
        $blockers[] = 'Missing style/family';
        $actions[] = 'Add style/family';
    }

    if ($typeInfo['status'] === 'missing') {
        $blockers[] = 'Missing product type';
        $actions[] = 'Add product type';
    } elseif ($typeInfo['status'] === 'suspect') {
        $warnings[] = 'Product type looks like a line/style, not a major type';
        $actions[] = 'Move line/style text to grouping path and choose a major product type';
    } elseif ($typeInfo['status'] === 'normalize') {
        $warnings[] = 'Product type should be normalized to '.$typeInfo['normalized'];
        $actions[] = 'Normalize product type';
    }

    if (empty($intake->classification_path)) {
        $warnings[] = 'Missing grouping path';
        $actions[] = 'Add grouping path when product line/sub-brand is known';
    }

    if (empty($intake->variant_structure)) {
        $info[] = 'No variant map yet';
        $actions[] = 'Add observed variant map later if this family has variants';
    } elseif ($axis['main_values'] === [] && $axis['sub_values'] === [] && $axis['common_values'] === []) {
        $info[] = 'No visible variants recorded; treat as single product/family until SKU capture';
    }

    if (! $photoUrl) {
        $info[] = 'Missing photo; acceptable for reference-only intake';
        $actions[] = 'Add or link product photo later if this becomes ecommerce-facing';
    }

    foreach ($badCommon as $badValue) {
        $warnings[] = 'Common variant contains feature: '.$badValue;
        $actions[] = 'Move feature text to notes; keep common variant as pack/count only';
    }

    foreach ($commonNormalizations as $normalization) {
        $warnings[] = 'Common variant format can be normalized: '.$normalization;
        $actions[] = 'Normalize pack count label';
    }

    if (($intake->product_type_status ?? 'known') === 'not_sure') {
        $info[] = 'Product type status still marked not sure';
        $actions[] = 'Confirm product type';
    }

    if (($intake->catalogue_style_status ?? 'known') !== 'known') {
        $info[] = 'Catalogue style not linked/confirmed';
        $actions[] = 'Link or confirm against catalogue later';
    }

    if (isset($duplicateMap[$intake->id])) {
        $info[] = 'Possible duplicate of IDs: '.implode(', ', array_diff($duplicateMap[$intake->id], [$intake->id]));
        $actions[] = 'Review duplicate group before publishing to live products';
    }

    $hasLocation = $intake->store_id || $intake->section_id || $intake->subsection_id || filled_value($intake->shelf_location);
    if (! $hasLocation) {
        $info[] = 'No shelf/location yet';
    }

    $status = severity_status($blockers, $warnings);
    $score = score_for($blockers, $warnings);

    $summary[$status]++;
    $summary[$photoUrl ? 'with_photo' : 'missing_photo']++;
    if ($hasLocation) {
        $summary['with_location']++;
    }
    if ($intake->brand_catalogue_style_id) {
        $summary['linked_catalogue_style']++;
    }
    if (isset($duplicateMap[$intake->id])) {
        $summary['duplicate_records']++;
    }

    $brand = clean_value($intake->brand_name) ?: 'Unknown';
    $brandSummary[$brand]['total'] = ($brandSummary[$brand]['total'] ?? 0) + 1;
    $brandSummary[$brand][$status] = ($brandSummary[$brand][$status] ?? 0) + 1;

    $normalizedType = $typeInfo['normalized'] ?: (clean_value($intake->product_type_name) ?: 'Unknown');
    $typeSummary[$normalizedType] = ($typeSummary[$normalizedType] ?? 0) + 1;

    $rows[] = [
        'id' => $intake->id,
        'quality_status' => $status,
        'score' => $score,
        'brand' => $brand,
        'grouping_path' => path_text($intake->classification_path),
        'product_type' => clean_value($intake->product_type_name),
        'normalized_type' => $normalizedType,
        'style_family' => clean_value($intake->style_name),
        'observed_name' => clean_value($intake->observed_product_name),
        'main_axis' => $axis['main_axis'],
        'main_values' => implode(', ', $axis['main_values']),
        'sub_axis' => $axis['sub_axis'],
        'sub_values' => implode(', ', $axis['sub_values']),
        'common_values' => implode(', ', $axis['common_values']),
        'sellable_combos_seen' => $axis['combo_count'],
        'photo_count' => $intake->photos->count() + (filled_value($intake->photo_path) ? 1 : 0),
        'photo_url' => $photoUrl ?: '',
        'location' => trim(implode(' > ', array_filter([
            $intake->store?->name,
            $intake->section?->name,
            $intake->subsection?->name,
            $intake->shelf_location,
        ]))),
        'catalogue_brand_linked' => $intake->brand_catalogue_brand_id ? 'yes' : 'no',
        'catalogue_type_linked' => $intake->brand_catalogue_product_type_id ? 'yes' : 'no',
        'catalogue_style_linked' => $intake->brand_catalogue_style_id ? 'yes' : 'no',
        'product_type_status' => clean_value($intake->product_type_status),
        'style_family_status' => clean_value($intake->style_family_status),
        'catalogue_style_status' => clean_value($intake->catalogue_style_status),
        'batch_source' => $batch ? ($batch->batch_slug.' / '.$batch->filename) : '',
        'issues' => implode(' | ', array_values(array_unique(array_merge($blockers, $warnings, $info)))),
        'recommended_actions' => implode(' | ', array_values(array_unique($actions))),
        'notes_excerpt' => Str::limit(str_replace(["\r", "\n"], ' ', clean_value($intake->visible_text_notes)), 220),
        'submitted_at' => optional($intake->submitted_at)->format('Y-m-d H:i:s'),
    ];
}

uasort($brandSummary, fn ($a, $b) => ($b['total'] ?? 0) <=> ($a['total'] ?? 0));
arsort($typeSummary);

$csv = fopen($csvPath, 'w');
fputcsv($csv, array_keys($rows[0] ?? []));
foreach ($rows as $row) {
    fputcsv($csv, $row);
}
fclose($csv);
copy($csvPath, $latestCsvPath);

$statusLabels = [
    'stage1_clean' => 'Stage 1 clean',
    'minor_cleanup' => 'Minor cleanup',
    'review' => 'Review',
    'needs_fix' => 'Needs fix',
];

$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
$html .= '<title>Hair Extension Intake Audit</title>';
$html .= '<style>
body{margin:0;background:#f6f1e8;color:#17201e;font-family:Georgia,"Times New Roman",serif}
.wrap{max-width:1500px;margin:0 auto;padding:28px}
.hero{background:#fffdf8;border:1px solid #ddd2c0;border-radius:24px;padding:26px;box-shadow:0 12px 30px rgba(44,36,24,.08)}
h1{margin:0;font-size:38px}.muted{color:#6f746d}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin:20px 0}
.card{background:#fffdf8;border:1px solid #ddd2c0;border-radius:18px;padding:16px}.card strong{display:block;font-size:30px}
.section{margin-top:22px;background:#fffdf8;border:1px solid #ddd2c0;border-radius:22px;padding:20px}
table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #e5dccd;padding:10px;text-align:left;vertical-align:top}th{position:sticky;top:0;background:#f0e7d8;z-index:1}
.table-wrap{max-height:78vh;overflow:auto;border:1px solid #e5dccd;border-radius:16px}.pill{display:inline-block;padding:4px 8px;border-radius:999px;font-weight:700;font-size:12px}
.stage1_clean{background:#e5f5ec;color:#145a38}.minor_cleanup{background:#fff3cd;color:#6a4b00}.review{background:#ffe4cc;color:#7a3100}.needs_fix{background:#f8d7da;color:#842029}
.thumb{width:52px;height:52px;object-fit:cover;border-radius:10px;border:1px solid #ddd2c0;background:#eee}
.issues{max-width:360px}.nowrap{white-space:nowrap}.small{font-size:12px}.brand-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.brand-row{display:flex;justify-content:space-between;gap:10px;border-bottom:1px solid #eee1ce;padding:8px 0}.toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.btn{display:inline-block;border:1px solid #224c43;color:#224c43;text-decoration:none;border-radius:12px;padding:10px 14px;background:#fff}
input{width:100%;box-sizing:border-box;border:1px solid #d8cbb8;border-radius:14px;padding:12px;margin:14px 0;font-size:16px}
</style></head><body><div class="wrap">';
$html .= '<div class="hero"><p class="muted">Generated '.htmlspecialchars(date('Y-m-d H:i:s')).'</p><h1>Hair Extension Intake Audit</h1><p>This report audits every submitted V2 hair-extension intake for structure quality, variant clarity, photo coverage, catalogue links, duplicate risk, and cleanup actions.</p>';
$html .= '<div class="toolbar"><a class="btn" href="'.htmlspecialchars(basename($csvPath)).'">Download CSV</a><a class="btn" href="/LHC_Data/public/hair-extension-product-intake/submitted">Open Submitted Page</a></div></div>';
$html .= '<div class="grid">';
foreach ([
    'Submitted' => $summary['total'],
    'Stage 1 clean' => $summary['stage1_clean'],
    'Minor cleanup' => $summary['minor_cleanup'],
    'Review' => $summary['review'],
    'Needs fix' => $summary['needs_fix'],
    'With photo' => $summary['with_photo'],
    'Missing photo' => $summary['missing_photo'],
    'With location' => $summary['with_location'],
    'Catalogue style linked' => $summary['linked_catalogue_style'],
    'Duplicate-risk records' => $summary['duplicate_records'],
] as $label => $value) {
    $html .= '<div class="card"><span class="muted">'.htmlspecialchars($label).'</span><strong>'.number_format($value).'</strong></div>';
}
$html .= '</div>';

$html .= '<div class="section"><h2>Brand Summary</h2><div class="brand-grid">';
foreach ($brandSummary as $brand => $data) {
    $html .= '<div class="card"><div class="brand-row"><strong style="font-size:18px">'.htmlspecialchars($brand).'</strong><span>'.number_format($data['total']).'</span></div>';
    foreach ($statusLabels as $key => $label) {
        $html .= '<div class="brand-row small"><span>'.htmlspecialchars($label).'</span><span>'.number_format($data[$key] ?? 0).'</span></div>';
    }
    $html .= '</div>';
}
$html .= '</div></div>';

$html .= '<div class="section"><h2>Normalized Product Type Summary</h2><div class="brand-grid">';
foreach ($typeSummary as $type => $count) {
    $html .= '<div class="brand-row"><span>'.htmlspecialchars($type).'</span><strong>'.number_format($count).'</strong></div>';
}
$html .= '</div></div>';

$html .= '<div class="section"><h2>Every Submitted Intake</h2><input id="filter" placeholder="Filter by brand, style, issue, product type, variant..." oninput="filterRows()"><div class="table-wrap"><table id="audit"><thead><tr>';
$headers = ['ID', 'Photo', 'Quality', 'Score', 'Brand', 'Path', 'Type', 'Style / Family', 'Variants', 'Catalogue', 'Source', 'Issues', 'Actions'];
foreach ($headers as $header) {
    $html .= '<th>'.htmlspecialchars($header).'</th>';
}
$html .= '</tr></thead><tbody>';

foreach ($rows as $row) {
    $variantText = trim(implode(' | ', array_filter([
        $row['main_axis'].': '.$row['main_values'],
        $row['sub_axis'].': '.$row['sub_values'],
        $row['common_values'] ? 'Common: '.$row['common_values'] : '',
        'Combos: '.$row['sellable_combos_seen'],
    ])));
    $catalogueText = 'Brand '.$row['catalogue_brand_linked'].' / Type '.$row['catalogue_type_linked'].' / Style '.$row['catalogue_style_linked'];
    $search = strtolower(implode(' ', $row));
    $html .= '<tr data-search="'.htmlspecialchars($search).'">';
    $html .= '<td class="nowrap">#'.htmlspecialchars((string) $row['id']).'</td>';
    $html .= '<td>'.($row['photo_url'] ? '<a href="'.htmlspecialchars($row['photo_url']).'"><img class="thumb" src="'.htmlspecialchars($row['photo_url']).'" loading="lazy"></a>' : '<span class="muted">No photo</span>').'</td>';
    $html .= '<td><span class="pill '.htmlspecialchars($row['quality_status']).'">'.htmlspecialchars($statusLabels[$row['quality_status']] ?? $row['quality_status']).'</span></td>';
    $html .= '<td>'.htmlspecialchars((string) $row['score']).'</td>';
    $html .= '<td>'.htmlspecialchars($row['brand']).'</td>';
    $html .= '<td>'.htmlspecialchars($row['grouping_path'] ?: 'N/A').'</td>';
    $html .= '<td>'.htmlspecialchars($row['product_type']).'<br><span class="muted small">=> '.htmlspecialchars($row['normalized_type']).'</span></td>';
    $html .= '<td><strong>'.htmlspecialchars($row['style_family'] ?: 'N/A').'</strong><br><span class="muted small">'.htmlspecialchars($row['observed_name']).'</span></td>';
    $html .= '<td>'.htmlspecialchars($variantText).'</td>';
    $html .= '<td class="small">'.htmlspecialchars($catalogueText).'<br>'.htmlspecialchars($row['catalogue_style_status']).'</td>';
    $html .= '<td class="small">'.htmlspecialchars($row['batch_source'] ?: 'Manual/V2').'</td>';
    $html .= '<td class="issues">'.htmlspecialchars($row['issues'] ?: 'None').'</td>';
    $html .= '<td class="issues">'.htmlspecialchars($row['recommended_actions'] ?: 'No action').'</td>';
    $html .= '</tr>';
}

$html .= '</tbody></table></div></div>';
$html .= '<script>function filterRows(){const q=document.getElementById("filter").value.toLowerCase();document.querySelectorAll("#audit tbody tr").forEach(tr=>{tr.style.display=tr.dataset.search.includes(q)?"":"none";});}</script>';
$html .= '</div></body></html>';

file_put_contents($htmlPath, $html);
copy($htmlPath, $latestHtmlPath);

echo json_encode([
    'submitted' => $summary['total'],
    'stage1_clean' => $summary['stage1_clean'],
    'minor_cleanup' => $summary['minor_cleanup'],
    'review' => $summary['review'],
    'needs_fix' => $summary['needs_fix'],
    'csv' => $csvPath,
    'html' => $htmlPath,
    'latest_html' => $latestHtmlPath,
    'latest_csv' => $latestCsvPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
