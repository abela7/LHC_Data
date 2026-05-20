<?php

declare(strict_types=1);

/**
 * Normalize Length variant labels for Hair Extensions families to inch notation (e.g. 46").
 *
 * Usage:
 *   php scripts/normalize-hair-extension-length-inches.php           # dry run
 *   php scripts/normalize-hair-extension-length-inches.php --apply   # write changes
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ProductVariantOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$apply = in_array('--apply', $argv, true);

/**
 * @return array{changed: bool, label: string, reason: string}
 */
function normalize_length_label(string $label): array
{
    $original = trim(preg_replace('/\s+/u', ' ', $label) ?: '');
    if ($original === '') {
        return ['changed' => false, 'label' => $original, 'reason' => 'empty'];
    }

    if (preg_match('/^\d+(?:\.\d+)?"\s*$/u', $original)) {
        return ['changed' => false, 'label' => $original, 'reason' => 'already_inches'];
    }

    if (preg_match('/^\d+(?:\.\d+)?$/u', $original)) {
        return ['changed' => true, 'label' => $original.'"', 'reason' => 'number_only'];
    }

    if (preg_match('/^\d+(?:\.\d+)?\s*(?:inch|inches|in)\.?$/ui', $original)) {
        $number = trim((string) preg_replace('/\s*(?:inch|inches|in)\.?$/ui', '', $original));

        return ['changed' => true, 'label' => $number.'"', 'reason' => 'inch_word'];
    }

    if (str_contains($original, '/')) {
        $parts = preg_split('#\s*/\s*#u', $original) ?: [];
        $normalized = [];
        $changed = false;
        foreach ($parts as $part) {
            $child = normalize_length_label(trim($part));
            $normalized[] = $child['label'];
            $changed = $changed || $child['changed'] || trim($part) !== $child['label'];
        }
        $joined = implode('/', $normalized);

        return [
            'changed' => $changed || $joined !== $original,
            'label' => $joined,
            'reason' => 'compound_slash',
        ];
    }

    return ['changed' => false, 'label' => $original, 'reason' => 'unchanged'];
}

function is_length_group(string $name): bool
{
    $lower = Str::lower(trim($name));

    return $lower === 'length' || str_contains($lower, 'length');
}

$optionQuery = ProductVariantOption::query()
    ->with(['group.family'])
    ->whereHas('group.family', fn ($q) => $q->where('root_catalogue_name', 'Hair Extensions'))
    ->whereHas('group', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['length'])
        ->orWhereRaw('LOWER(name) LIKE ?', ['%length%']));

$options = $optionQuery->get();

$changed = 0;
$merged = 0;
$skippedConflict = 0;
$unchanged = 0;
$rows = [];

foreach ($options as $option) {
    $result = normalize_length_label($option->label);
    if (! $result['changed']) {
        $unchanged++;

        continue;
    }

    $groupId = (int) $option->product_variant_group_id;
    $newLabel = $result['label'];

    $conflict = ProductVariantOption::query()
        ->where('product_variant_group_id', $groupId)
        ->where('label', $newLabel)
        ->where('id', '!=', $option->id)
        ->exists();

    if ($conflict) {
        $target = ProductVariantOption::query()
            ->where('product_variant_group_id', $groupId)
            ->where('label', $newLabel)
            ->where('id', '!=', $option->id)
            ->first();

        if ($target === null) {
            $skippedConflict++;
            $rows[] = [
                'status' => 'conflict',
                'option_id' => $option->id,
                'group_id' => $groupId,
                'family' => $option->group?->family?->family_name,
                'old_label' => $option->label,
                'new_label' => $newLabel,
                'reason' => $result['reason'],
            ];

            continue;
        }

        if ($apply) {
            DB::transaction(function () use ($option, $target): void {
                $valueIds = DB::table('product_variant_values')
                    ->where('product_variant_option_id', $option->id)
                    ->pluck('id');

                foreach ($valueIds as $valueId) {
                    $row = DB::table('product_variant_values')->where('id', $valueId)->first();
                    if ($row === null) {
                        continue;
                    }

                    $exists = DB::table('product_variant_values')
                        ->where('product_id', $row->product_id)
                        ->where('product_variant_group_id', $row->product_variant_group_id)
                        ->where('id', '!=', $valueId)
                        ->exists();

                    if ($exists) {
                        DB::table('product_variant_values')->where('id', $valueId)->delete();
                    } else {
                        DB::table('product_variant_values')
                            ->where('id', $valueId)
                            ->update(['product_variant_option_id' => $target->id]);
                    }
                }

                $option->delete();
            });
        }

        $rows[] = [
            'status' => $apply ? 'merged' : 'would_merge',
            'option_id' => $option->id,
            'group_id' => $groupId,
            'family' => $option->group?->family?->family_name,
            'old_label' => $option->label,
            'new_label' => $newLabel.' → #'.$target->id,
            'reason' => 'duplicate_merge',
        ];
        $merged++;

        continue;
    }

    $rows[] = [
        'status' => $apply ? 'updated' : 'would_update',
        'option_id' => $option->id,
        'group_id' => $groupId,
        'family' => $option->group?->family?->family_name,
        'old_label' => $option->label,
        'new_label' => $newLabel,
        'reason' => $result['reason'],
    ];

    if ($apply) {
        DB::transaction(function () use ($option, $newLabel): void {
            $option->label = $newLabel;
            $option->value = $newLabel;
            $option->save();

            if ($option->brand_catalogue_variant_option_id) {
                DB::table('brand_catalogue_variant_options')
                    ->where('id', $option->brand_catalogue_variant_option_id)
                    ->update([
                        'label' => $newLabel,
                        'value' => $newLabel,
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    $changed++;
}

// Catalogue-only length options under hair extension styles (not yet published to retail).
$catalogueRows = collect();
if (Schema::hasTable('brand_catalogues')) {
    $catalogueRows = DB::table('brand_catalogue_variant_options as o')
        ->join('brand_catalogue_variants as v', 'v.id', '=', 'o.variant_id')
        ->join('brand_catalogue_styles as s', 's.id', '=', 'v.brand_catalogue_style_id')
        ->join('brand_catalogue_brands as b', 'b.id', '=', 's.brand_catalogue_brand_id')
        ->join('brand_catalogues as c', 'c.id', '=', 'b.brand_catalogue_id')
        ->where('c.name', 'Hair Extensions')
        ->where(function ($query): void {
            $query->whereRaw('LOWER(v.name) = ?', ['length'])
                ->orWhereRaw('LOWER(v.name) LIKE ?', ['%length%']);
        })
        ->whereNotExists(function ($query): void {
            $query->selectRaw('1')
                ->from('product_variant_options as pvo')
                ->whereColumn('pvo.brand_catalogue_variant_option_id', 'o.id');
        })
        ->select('o.id', 'o.variant_id', 'o.label', 'o.value')
        ->get();
}

$catalogueChanged = 0;
$catalogueSkipped = 0;

foreach ($catalogueRows as $row) {
    $result = normalize_length_label((string) $row->label);
    if (! $result['changed']) {
        continue;
    }

    $conflict = DB::table('brand_catalogue_variant_options')
        ->where('variant_id', $row->variant_id)
        ->where('label', $result['label'])
        ->where('id', '!=', $row->id)
        ->exists();

    if ($conflict) {
        $targetId = DB::table('brand_catalogue_variant_options')
            ->where('variant_id', $row->variant_id)
            ->where('label', $result['label'])
            ->where('id', '!=', $row->id)
            ->value('id');

        if ($targetId === null) {
            $catalogueSkipped++;

            continue;
        }

        if ($apply) {
            DB::transaction(function () use ($row, $targetId, $result): void {
                if (Schema::hasTable('brand_catalogue_sku_variant_options')) {
                    DB::table('brand_catalogue_sku_variant_options')
                        ->where('brand_catalogue_variant_option_id', $row->id)
                        ->update(['brand_catalogue_variant_option_id' => $targetId]);
                }

                DB::table('brand_catalogue_variant_options')->where('id', $row->id)->delete();
            });
        }

        $catalogueChanged++;
        $rows[] = [
            'status' => $apply ? 'catalogue_merged' : 'catalogue_would_merge',
            'option_id' => 'bc:'.$row->id,
            'group_id' => 'bcv:'.$row->variant_id,
            'family' => '',
            'old_label' => $row->label,
            'new_label' => $result['label'].' → bc:'.$targetId,
            'reason' => 'duplicate_merge',
        ];

        continue;
    }

    if ($apply) {
        DB::table('brand_catalogue_variant_options')
            ->where('id', $row->id)
            ->update([
                'label' => $result['label'],
                'value' => $result['label'],
                'updated_at' => now(),
            ]);
    }

    $catalogueChanged++;
    $rows[] = [
        'status' => $apply ? 'catalogue_updated' : 'catalogue_would_update',
        'option_id' => 'bc:'.$row->id,
        'group_id' => 'bcv:'.$row->variant_id,
        'family' => '',
        'old_label' => $row->label,
        'new_label' => $result['label'],
        'reason' => $result['reason'],
    ];
}

$reportDir = public_path('reports');
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$timestamp = date('Ymd-His');
$csvPath = $reportDir."/hair-extension-length-inches-{$timestamp}.csv";
$fp = fopen($csvPath, 'w');
fputcsv($fp, ['status', 'option_id', 'group_id', 'family', 'old_label', 'new_label', 'reason']);
foreach ($rows as $row) {
    fputcsv($fp, [$row['status'], $row['option_id'], $row['group_id'], $row['family'], $row['old_label'], $row['new_label'], $row['reason']]);
}
fclose($fp);
copy($csvPath, $reportDir.'/hair-extension-length-inches-latest.csv');

$mode = $apply ? 'APPLIED' : 'DRY RUN';
echo "{$mode}\n";
echo "Retail length options scanned: {$options->count()}\n";
echo "Retail label updates: {$changed}\n";
echo "Retail duplicate merges: {$merged}\n";
echo "Retail conflicts (skipped): {$skippedConflict}\n";
echo "Retail already OK: {$unchanged}\n";
echo "Catalogue-only updates: {$catalogueChanged}\n";
echo "Catalogue-only conflicts: {$catalogueSkipped}\n";
echo "Report: {$csvPath}\n";
