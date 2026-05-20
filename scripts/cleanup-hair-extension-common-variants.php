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

$logPath = $reportDir."/hair-extension-common-variant-cleanup-{$timestamp}.csv";
$latestLogPath = $reportDir.'/hair-extension-common-variant-cleanup-latest.csv';

function clean_cv_text(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function word_number_to_int(string $value): ?int
{
    return match (strtolower($value)) {
        'one' => 1,
        'two' => 2,
        'three' => 3,
        'four' => 4,
        'five' => 5,
        'six' => 6,
        'seven' => 7,
        'eight' => 8,
        'nine' => 9,
        'ten' => 10,
        default => null,
    };
}

function normalize_common_value(string $value): array
{
    $value = clean_cv_text($value);
    if ($value === '') {
        return ['keep' => false, 'value' => '', 'move_note' => ''];
    }

    if (preg_match('/^\s*(\d+)\s*x(?:\s*(?:pack|value pack|eazi-pack))?\s*$/i', $value, $match)) {
        return ['keep' => true, 'value' => strtoupper($match[1].'X'), 'move_note' => $value === strtoupper($match[1].'X') ? '' : "Normalized pack count from {$value}"];
    }

    if (preg_match('/^\s*(\d+)\s*(pack|packs|pc|pcs|piece|pieces|bundle|bundles)\s*$/i', $value, $match)) {
        $unit = strtolower($match[2]);
        $unit = match ($unit) {
            'packs' => 'Pack',
            'pack' => 'Pack',
            'pc', 'pcs', 'piece', 'pieces' => 'Piece',
            'bundle', 'bundles' => 'Bundle',
            default => ucfirst($unit),
        };

        return ['keep' => true, 'value' => $match[1].' '.$unit, 'move_note' => $value === $match[1].' '.$unit ? '' : "Normalized pack/piece count from {$value}"];
    }

    if (preg_match('/^\s*(one|two|three|four|five|six|seven|eight|nine|ten)\s*(pack|packs|pc|pcs|piece|pieces|bundle|bundles)\s*$/i', $value, $match)) {
        $count = word_number_to_int($match[1]);
        $unit = strtolower($match[2]);
        $unit = match ($unit) {
            'packs' => 'Pack',
            'pack' => 'Pack',
            'pc', 'pcs', 'piece', 'pieces' => 'Piece',
            'bundle', 'bundles' => 'Bundle',
            default => ucfirst($unit),
        };

        return ['keep' => true, 'value' => $count.' '.$unit, 'move_note' => "Normalized pack/piece count from {$value}"];
    }

    return ['keep' => false, 'value' => '', 'move_note' => $value];
}

function rebuild_sku(array $structure): array
{
    $common = [];
    foreach (($structure['common_variants'] ?? []) as $variant) {
        if (! empty($variant['name']) && ! empty($variant['values'])) {
            $common[$variant['name']] = array_values($variant['values']);
        }
    }

    $rows = [];
    foreach (($structure['groups'] ?? []) as $group) {
        $subValues = $group['sub_values'] ?? [];
        if ($subValues === []) {
            $rows[] = [
                'main_axis' => $structure['main_axis'] ?? null,
                'main_value' => $group['main_value'] ?? null,
                'sub_axis' => $group['sub_axis'] ?? null,
                'sub_value' => null,
                'common_attributes' => $common,
            ];
            continue;
        }

        foreach ($subValues as $subValue) {
            $rows[] = [
                'main_axis' => $structure['main_axis'] ?? null,
                'main_value' => $group['main_value'] ?? null,
                'sub_axis' => $group['sub_axis'] ?? null,
                'sub_value' => $subValue,
                'common_attributes' => $common,
            ];
        }
    }

    return $rows;
}

function variant_groups_for(array $structure): array
{
    $groups = $structure['groups'] ?? [];
    $mainValues = [];
    $subValues = [];
    $subAxis = 'Colour';
    $variantGroups = [];

    foreach ($groups as $group) {
        if (! empty($group['main_value'])) {
            $mainValues[] = $group['main_value'];
        }
        if (! empty($group['sub_axis'])) {
            $subAxis = $group['sub_axis'];
        }
        foreach (($group['sub_values'] ?? []) as $value) {
            if (clean_cv_text($value) !== '') {
                $subValues[] = $value;
            }
        }
    }

    if ($mainValues !== []) {
        $variantGroups[] = ['name' => $structure['main_axis'] ?? 'Main', 'values' => array_values(array_unique($mainValues))];
    }

    if ($subValues !== []) {
        $variantGroups[] = ['name' => $subAxis, 'values' => array_values(array_unique($subValues))];
    }

    foreach (($structure['common_variants'] ?? []) as $common) {
        if (! empty($common['values'])) {
            $variantGroups[] = ['name' => $common['name'] ?? 'Common', 'values' => array_values(array_unique($common['values']))];
        }
    }

    return $variantGroups;
}

$rows = [];

DB::transaction(function () use ($apply, &$rows): void {
    $intakes = HairExtensionIntake::query()
        ->where('status', 'submitted')
        ->whereNotNull('variant_structure')
        ->orderBy('id')
        ->get();

    foreach ($intakes as $intake) {
        $structure = $intake->variant_structure ?: [];
        $oldCommon = $structure['common_variants'] ?? [];
        if ($oldCommon === []) {
            continue;
        }

        $newCommon = [];
        $movedNotes = [];
        $changed = false;

        foreach ($oldCommon as $variant) {
            $name = clean_cv_text($variant['name'] ?? 'Pack count') ?: 'Pack count';
            $keptValues = [];

            foreach (($variant['values'] ?? []) as $value) {
                $result = normalize_common_value((string) $value);
                if ($result['keep']) {
                    $keptValues[] = $result['value'];
                    if ($result['value'] !== clean_cv_text($value)) {
                        $changed = true;
                    }
                } else {
                    $changed = true;
                }

                if ($result['move_note'] !== '' && ! str_starts_with($result['move_note'], 'Normalized')) {
                    $movedNotes[] = $result['move_note'];
                }
            }

            $keptValues = array_values(array_unique($keptValues));
            if ($keptValues !== []) {
                $newCommon[] = ['name' => $name === 'Material' ? 'Pack count' : $name, 'values' => $keptValues];
            }
        }

        if (! $changed) {
            continue;
        }

        $structure['common_variants'] = $newCommon;
        $structure['sku_matrix'] = rebuild_sku($structure);
        $structure['summary'] = [
            'main_group_count' => count($structure['groups'] ?? []),
            'common_variant_count' => count($newCommon),
            'sellable_combination_count' => count($structure['sku_matrix'] ?? []),
        ];

        $notes = trim((string) $intake->visible_text_notes);
        foreach (array_values(array_unique($movedNotes)) as $movedNote) {
            $line = 'Moved from common variant: '.$movedNote.'.';
            if (! str_contains($notes, $line)) {
                $notes = trim($notes."\n".$line);
            }
        }

        $rows[] = [
            'intake_id' => $intake->id,
            'brand' => $intake->brand_name,
            'style_family' => $intake->style_name,
            'old_common' => json_encode($oldCommon, JSON_UNESCAPED_SLASHES),
            'new_common' => json_encode($newCommon, JSON_UNESCAPED_SLASHES),
            'moved_to_notes' => implode(' | ', array_values(array_unique($movedNotes))),
            'applied' => $apply ? 'yes' : 'no',
        ];

        if ($apply) {
            $intake->update([
                'variant_structure' => $structure,
                'variant_groups' => variant_groups_for($structure),
                'visible_text_notes' => $notes,
                'last_synced_at' => now(),
            ]);
        }
    }
});

$csv = fopen($logPath, 'w');
fputcsv($csv, ['intake_id', 'brand', 'style_family', 'old_common', 'new_common', 'moved_to_notes', 'applied']);
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
