<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$apply = in_array('--apply', $argv, true);
$timestamp = date('Ymd-His');

$brand = DB::table('brand_catalogue_brands')
    ->where('id', 16)
    ->where('name', 'Sleek')
    ->first();

if (! $brand) {
    throw new RuntimeException('Sleek brand id 16 was not found.');
}

$mainLine = DB::table('brand_catalogue_lines')
    ->where('brand_catalogue_brand_id', $brand->id)
    ->where('name', 'Hair Couture')
    ->first();

if (! $mainLine) {
    throw new RuntimeException('Main Hair Couture line was not found.');
}

$moves = [
    [
        'old_line_name' => 'Hair Couture > Ponytails',
        'old_type_name' => 'Ponytail',
        'new_type_name' => 'Ponytails',
    ],
    [
        'old_line_name' => 'Hair Couture > Synthetic Clip-Ins',
        'old_type_name' => 'Clip-in Extensions',
        'new_type_name' => 'Synthetic Clip-Ins',
    ],
];

function hcn_slug(string $value): string
{
    return Str::slug($value) ?: 'item';
}

function hcn_unique_product_type_slug(int $lineId, string $name, ?int $ignoreId = null): string
{
    $base = hcn_slug($name);
    $slug = $base;
    $suffix = 2;

    while (true) {
        $query = DB::table('brand_catalogue_product_types')
            ->where('brand_catalogue_line_id', $lineId)
            ->where('slug', $slug);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if (! $query->exists()) {
            return $slug;
        }

        $slug = "{$base}-{$suffix}";
        $suffix++;
    }
}

$lineNames = array_merge(['Hair Couture'], array_column($moves, 'old_line_name'));
$backup = [
    'brand' => $brand,
    'lines' => DB::table('brand_catalogue_lines')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->whereIn('name', $lineNames)
        ->get(),
    'product_types' => DB::table('brand_catalogue_product_types')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->whereIn('brand_catalogue_line_id', function ($query) use ($brand, $lineNames) {
            $query->select('id')
                ->from('brand_catalogue_lines')
                ->where('brand_catalogue_brand_id', $brand->id)
                ->whereIn('name', $lineNames);
        })
        ->get(),
    'styles' => DB::table('brand_catalogue_styles')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->whereIn('brand_catalogue_product_type_id', function ($query) use ($brand, $lineNames) {
            $query->select('pt.id')
                ->from('brand_catalogue_product_types as pt')
                ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
                ->where('l.brand_catalogue_brand_id', $brand->id)
                ->whereIn('l.name', $lineNames);
        })
        ->get(),
    'intakes' => DB::table('hair_extension_intakes')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->whereIn('brand_catalogue_product_type_id', function ($query) use ($brand, $lineNames) {
            $query->select('pt.id')
                ->from('brand_catalogue_product_types as pt')
                ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
                ->where('l.brand_catalogue_brand_id', $brand->id)
                ->whereIn('l.name', $lineNames);
        })
        ->get(),
    'product_families' => DB::table('product_families')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->whereIn('brand_catalogue_line_id', function ($query) use ($brand, $lineNames) {
            $query->select('id')
                ->from('brand_catalogue_lines')
                ->where('brand_catalogue_brand_id', $brand->id)
                ->whereIn('name', $lineNames);
        })
        ->get(),
];

$stats = [
    'mode' => $apply ? 'applied' : 'dry_run',
    'main_line_id' => $mainLine->id,
    'product_types_moved' => 0,
    'product_types_renamed' => 0,
    'intakes_updated' => 0,
    'product_families_updated' => 0,
    'old_lines_deleted' => 0,
    'backup' => null,
];

$actions = [];

$run = function () use ($moves, $brand, $mainLine, &$stats, &$actions): void {
    foreach ($moves as $move) {
        $oldLine = DB::table('brand_catalogue_lines')
            ->where('brand_catalogue_brand_id', $brand->id)
            ->where('name', $move['old_line_name'])
            ->first();

        if (! $oldLine) {
            $actions[] = [
                'old_line' => $move['old_line_name'],
                'result' => 'old_line_not_found',
            ];
            continue;
        }

        $sourceType = DB::table('brand_catalogue_product_types')
            ->where('brand_catalogue_line_id', $oldLine->id)
            ->where('name', $move['old_type_name'])
            ->first();

        if (! $sourceType) {
            $actions[] = [
                'old_line' => $move['old_line_name'],
                'result' => 'source_product_type_not_found',
            ];
            continue;
        }

        $targetType = DB::table('brand_catalogue_product_types')
            ->where('brand_catalogue_line_id', $mainLine->id)
            ->where('name', $move['new_type_name'])
            ->first();

        if ($targetType && (int) $targetType->id !== (int) $sourceType->id) {
            DB::table('brand_catalogue_styles')
                ->where('brand_catalogue_product_type_id', $sourceType->id)
                ->update([
                    'brand_catalogue_product_type_id' => $targetType->id,
                    'updated_at' => now(),
                ]);

            $stats['intakes_updated'] += DB::table('hair_extension_intakes')
                ->where('brand_catalogue_product_type_id', $sourceType->id)
                ->update([
                    'classification_path' => json_encode(['Hair Couture']),
                    'product_type_name' => $move['new_type_name'],
                    'brand_catalogue_product_type_id' => $targetType->id,
                    'updated_at' => now(),
                ]);

            $stats['product_families_updated'] += DB::table('product_families')
                ->where('brand_catalogue_product_type_id', $sourceType->id)
                ->update([
                    'brand_catalogue_line_id' => $mainLine->id,
                    'brand_catalogue_product_type_id' => $targetType->id,
                    'line_name' => 'Hair Couture',
                    'product_type_name' => $move['new_type_name'],
                    'updated_at' => now(),
                ]);

            DB::table('brand_catalogue_product_types')
                ->where('id', $sourceType->id)
                ->delete();

            $actions[] = [
                'old_line' => $move['old_line_name'],
                'source_product_type_id' => $sourceType->id,
                'target_product_type_id' => $targetType->id,
                'new_type' => $move['new_type_name'],
                'result' => 'merged_into_existing_type',
            ];
        } else {
            $slug = hcn_unique_product_type_slug((int) $mainLine->id, $move['new_type_name'], (int) $sourceType->id);

            DB::table('brand_catalogue_product_types')
                ->where('id', $sourceType->id)
                ->update([
                    'brand_catalogue_line_id' => $mainLine->id,
                    'name' => $move['new_type_name'],
                    'slug' => $slug,
                    'updated_at' => now(),
                ]);

            $stats['product_types_moved']++;
            $stats['product_types_renamed']++;

            $stats['intakes_updated'] += DB::table('hair_extension_intakes')
                ->where('brand_catalogue_product_type_id', $sourceType->id)
                ->update([
                    'classification_path' => json_encode(['Hair Couture']),
                    'product_type_name' => $move['new_type_name'],
                    'updated_at' => now(),
                ]);

            $stats['product_families_updated'] += DB::table('product_families')
                ->where('brand_catalogue_product_type_id', $sourceType->id)
                ->update([
                    'brand_catalogue_line_id' => $mainLine->id,
                    'line_name' => 'Hair Couture',
                    'product_type_name' => $move['new_type_name'],
                    'updated_at' => now(),
                ]);

            $actions[] = [
                'old_line' => $move['old_line_name'],
                'source_product_type_id' => $sourceType->id,
                'target_product_type_id' => $sourceType->id,
                'new_type' => $move['new_type_name'],
                'result' => 'moved_and_renamed',
            ];
        }

        $remainingTypes = DB::table('brand_catalogue_product_types')
            ->where('brand_catalogue_line_id', $oldLine->id)
            ->count();

        if ($remainingTypes === 0) {
            DB::table('brand_catalogue_lines')
                ->where('id', $oldLine->id)
                ->delete();

            $stats['old_lines_deleted']++;
        }
    }
};

if ($apply) {
    $backupPath = "catalogue-backups/sleek-hair-couture-line-normalization-{$timestamp}.json";
    Storage::disk('local')->put($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $stats['backup'] = storage_path("app/{$backupPath}");

    DB::transaction($run);
} else {
    foreach ($moves as $move) {
        $oldLine = DB::table('brand_catalogue_lines')
            ->where('brand_catalogue_brand_id', $brand->id)
            ->where('name', $move['old_line_name'])
            ->first();

        $sourceType = $oldLine
            ? DB::table('brand_catalogue_product_types')
                ->where('brand_catalogue_line_id', $oldLine->id)
                ->where('name', $move['old_type_name'])
                ->first()
            : null;

        $actions[] = [
            'old_line' => $move['old_line_name'],
            'old_line_id' => $oldLine?->id,
            'source_product_type_id' => $sourceType?->id,
            'target_line_id' => $mainLine->id,
            'new_type' => $move['new_type_name'],
            'would_delete_old_line' => (bool) $oldLine,
        ];

        if ($sourceType) {
            $stats['product_types_moved']++;
            $stats['product_types_renamed']++;
            $stats['intakes_updated'] += DB::table('hair_extension_intakes')
                ->where('brand_catalogue_product_type_id', $sourceType->id)
                ->count();
            $stats['product_families_updated'] += DB::table('product_families')
                ->where('brand_catalogue_product_type_id', $sourceType->id)
                ->count();
        }
    }
}

echo json_encode([
    'stats' => $stats,
    'actions' => $actions,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

if (! $apply) {
    echo "Run with --apply to write these changes.", PHP_EOL;
}
