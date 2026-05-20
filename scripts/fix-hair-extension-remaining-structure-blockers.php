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

$logPath = $reportDir."/hair-extension-remaining-structure-fixes-{$timestamp}.csv";
$latestLogPath = $reportDir.'/hair-extension-remaining-structure-fixes-latest.csv';

function rebuild_sku_matrix(array $structure): array
{
    $mainAxis = $structure['main_axis'] ?? 'Length';
    $common = [];
    foreach (($structure['common_variants'] ?? []) as $variant) {
        if (! empty($variant['name']) && ! empty($variant['values'])) {
            $common[$variant['name']] = $variant['values'];
        }
    }

    $rows = [];
    foreach (($structure['groups'] ?? []) as $group) {
        $subValues = $group['sub_values'] ?? [];
        if ($subValues === []) {
            $rows[] = [
                'main_axis' => $mainAxis,
                'main_value' => $group['main_value'] ?? null,
                'sub_axis' => $group['sub_axis'] ?? null,
                'sub_value' => null,
                'common_attributes' => $common,
            ];
            continue;
        }

        foreach ($subValues as $subValue) {
            $rows[] = [
                'main_axis' => $mainAxis,
                'main_value' => $group['main_value'] ?? null,
                'sub_axis' => $group['sub_axis'] ?? 'Colour',
                'sub_value' => $subValue,
                'common_attributes' => $common,
            ];
        }
    }

    return $rows;
}

function variant_groups_from_structure(array $structure): array
{
    $groups = $structure['groups'] ?? [];
    $mainAxis = $structure['main_axis'] ?? 'Length';
    $mainValues = [];
    $subAxis = 'Colour';
    $subValues = [];
    $out = [];

    foreach ($groups as $group) {
        if (! empty($group['main_value'])) {
            $mainValues[] = $group['main_value'];
        }
        if (! empty($group['sub_axis'])) {
            $subAxis = $group['sub_axis'];
        }
        foreach (($group['sub_values'] ?? []) as $subValue) {
            if ((string) $subValue !== '') {
                $subValues[] = $subValue;
            }
        }
    }

    if ($mainValues !== []) {
        $out[] = ['name' => $mainAxis, 'values' => array_values(array_unique($mainValues))];
    }
    if ($subValues !== []) {
        $out[] = ['name' => $subAxis, 'values' => array_values(array_unique($subValues))];
    }
    foreach (($structure['common_variants'] ?? []) as $common) {
        if (! empty($common['values'])) {
            $out[] = ['name' => $common['name'] ?? 'Common', 'values' => array_values(array_unique($common['values']))];
        }
    }

    return $out;
}

function normalized_length(string $value): string
{
    if (preg_match('/(\d+(?:\/\d+)*)/i', $value, $match)) {
        return $match[1].' inch';
    }

    return trim($value);
}

$rows = [];

DB::transaction(function () use ($apply, &$rows): void {
    $fixes = [];

    $intake24 = HairExtensionIntake::find(24);
    if ($intake24) {
        $structure = $intake24->variant_structure ?: [];
        foreach (($structure['groups'] ?? []) as &$group) {
            $group['main_value'] = normalized_length((string) ($group['main_value'] ?? ''));
        }
        unset($group);
        $structure['mode'] = 'mapped';
        $structure['source'] = $structure['source'] ?? 'text_note_v2';
        $structure['main_axis'] = 'Length';
        $structure['common_variants'] = [['name' => 'Pack count', 'values' => ['3X']]];
        $structure['sku_matrix'] = rebuild_sku_matrix($structure);
        $structure['summary'] = [
            'main_group_count' => count($structure['groups'] ?? []),
            'common_variant_count' => 1,
            'sellable_combination_count' => count($structure['sku_matrix'] ?? []),
        ];
        $fixes[] = [
            'intake' => $intake24,
            'updates' => [
                'product_type_name' => 'Braid',
                'product_type_status' => 'known',
                'product_type_unknown' => false,
                'classification_path' => ['French Curl', 'Pre-Stretched'],
                'style_name' => 'Spiral French Curl',
                'style_family_status' => 'known',
                'style_unknown' => false,
                'variant_structure' => $structure,
                'variant_groups' => variant_groups_from_structure($structure),
            ],
            'reason' => 'Split long product-type text into type Braid, path French Curl > Pre-Stretched, style Spiral French Curl, and common 3X.',
        ];
    }

    $intake38 = HairExtensionIntake::find(38);
    if ($intake38) {
        $fixes[] = [
            'intake' => $intake38,
            'updates' => [
                'product_type_name' => 'Crochet Braid',
                'product_type_status' => 'known',
                'product_type_unknown' => false,
                'classification_path' => ['Bulk'],
                'style_name' => 'Princess Twist',
                'style_family_status' => 'known',
                'style_unknown' => false,
            ],
            'reason' => 'Princess Twist belongs under Cherish Bulk line and is used as crochet/twist hair, not a bare product type.',
        ];
    }

    $intake41 = HairExtensionIntake::find(41);
    if ($intake41) {
        $structure = $intake41->variant_structure ?: [];
        $structure['common_variants'] = [['name' => 'Pack count', 'values' => ['2X']]];
        $structure['sku_matrix'] = rebuild_sku_matrix($structure);
        $structure['summary'] = [
            'main_group_count' => count($structure['groups'] ?? []),
            'common_variant_count' => 1,
            'sellable_combination_count' => count($structure['sku_matrix'] ?? []),
        ];
        $fixes[] = [
            'intake' => $intake41,
            'updates' => [
                'classification_path' => ['Braids', 'Pre-Stretched'],
                'variant_structure' => $structure,
                'variant_groups' => variant_groups_from_structure($structure),
            ],
            'reason' => 'Added X-Pression Braids > Pre-Stretched grouping and normalized 2x to 2X.',
        ];
    }

    $intake68 = HairExtensionIntake::find(68);
    if ($intake68) {
        $structure = $intake68->variant_structure ?: [];
        $structure['common_variants'] = [];
        $structure['sku_matrix'] = rebuild_sku_matrix($structure);
        $structure['summary'] = [
            'main_group_count' => count($structure['groups'] ?? []),
            'common_variant_count' => 0,
            'sellable_combination_count' => count($structure['sku_matrix'] ?? []),
        ];
        $notes = trim((string) $intake68->visible_text_notes);
        $materialNote = 'Material/feature: 100% Human Hair.';
        if (! str_contains($notes, $materialNote)) {
            $notes = trim($notes."\n".$materialNote);
        }
        $fixes[] = [
            'intake' => $intake68,
            'updates' => [
                'classification_path' => ['100% Human Hair'],
                'variant_structure' => $structure,
                'variant_groups' => variant_groups_from_structure($structure),
                'visible_text_notes' => $notes,
            ],
            'reason' => 'Moved 100% Human Hair out of common variant into material/grouping context.',
        ];
    }

    $intake215 = HairExtensionIntake::find(215);
    if ($intake215) {
        $structure = [
            'mode' => 'single',
            'source' => 'text_note_v2',
            'main_axis' => null,
            'groups' => [],
            'common_variants' => [],
            'sku_matrix' => [[
                'main_axis' => null,
                'main_value' => null,
                'sub_axis' => null,
                'sub_value' => null,
                'common_attributes' => [],
            ]],
            'summary' => [
                'main_group_count' => 0,
                'common_variant_count' => 0,
                'sellable_combination_count' => 1,
            ],
        ];
        $fixes[] = [
            'intake' => $intake215,
            'updates' => [
                'variant_structure' => $structure,
                'variant_groups' => [],
            ],
            'reason' => 'Marked as single/no observed variant. Not every product needs colour or length variant.',
        ];
    }

    $intake158 = HairExtensionIntake::find(158);
    if ($intake158 && $intake158->product_type_name === 'Clip-in Fringe') {
        $fixes[] = [
            'intake' => $intake158,
            'updates' => [
                'product_type_name' => 'Bang / Fringe',
                'product_type_status' => 'known',
                'product_type_unknown' => false,
                'classification_path' => ['Hair Fringe', 'Clip-in Fringe'],
            ],
            'reason' => 'Clip-in fringe is a fringe/bang hairpiece specialist type.',
        ];
    }

    foreach ($fixes as $fix) {
        /** @var HairExtensionIntake $intake */
        $intake = $fix['intake'];
        $updates = $fix['updates'];
        $rows[] = [
            'intake_id' => $intake->id,
            'brand' => $intake->brand_name,
            'old_product_type' => $intake->product_type_name,
            'new_product_type' => $updates['product_type_name'] ?? $intake->product_type_name,
            'old_path' => json_encode($intake->classification_path),
            'new_path' => json_encode($updates['classification_path'] ?? $intake->classification_path),
            'old_style' => $intake->style_name,
            'new_style' => $updates['style_name'] ?? $intake->style_name,
            'reason' => $fix['reason'],
            'applied' => $apply ? 'yes' : 'no',
        ];

        if ($apply) {
            $updates['last_synced_at'] = now();
            $intake->update($updates);
        }
    }
});

$reportDir = public_path('reports');
$timestamp = date('Ymd-His');
$logPath = $reportDir."/hair-extension-remaining-structure-fixes-{$timestamp}.csv";
$latestLogPath = $reportDir.'/hair-extension-remaining-structure-fixes-latest.csv';
$csv = fopen($logPath, 'w');
fputcsv($csv, ['intake_id', 'brand', 'old_product_type', 'new_product_type', 'old_path', 'new_path', 'old_style', 'new_style', 'reason', 'applied']);
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
