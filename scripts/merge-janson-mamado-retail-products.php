<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$args = parseMergeArgs($argv);
$sync = array_key_exists('sync', $args);

$summary = DB::transaction(function () use ($sync): array {
    $summary = [
        'exact_product_duplicate_groups' => 0,
        'product_source_links_repointed' => 0,
        'exact_family_duplicate_groups' => 0,
        'family_source_links_repointed' => 0,
        'products_moved_to_canonical_family' => 0,
        'variant_values_moved' => 0,
        'family_media_moved' => 0,
        'skipped_rows' => 0,
    ];

    $productGroups = exactDuplicateProductGroups(retailMergeSourceRows());
    $summary['exact_product_duplicate_groups'] = $productGroups->count();

    foreach ($productGroups as $group) {
        $target = canonicalProductRow($group);
        if (! $target?->product_id || ! $target?->product_family_id) {
            $summary['skipped_rows'] += $group->count();
            continue;
        }

        foreach ($group as $row) {
            if ((int) $row->product_id === (int) $target->product_id
                && (int) $row->product_family_id === (int) $target->product_family_id) {
                continue;
            }

            DB::table('product_sources')
                ->where('id', $row->source_link_id)
                ->update([
                    'product_family_id' => $target->product_family_id,
                    'product_id' => $target->product_id,
                    'notes' => appendMergeNote(
                        $row->source_notes,
                        "Merged with exact {$target->source_type} product candidate {$target->product_id} and family {$target->product_family_id}."
                    ),
                    'updated_at' => now(),
                ]);

            $summary['product_source_links_repointed']++;
        }
    }

    $familyGroups = exactDuplicateFamilyGroups(retailMergeSourceRows());
    $summary['exact_family_duplicate_groups'] = $familyGroups->count();
    $movedProducts = [];
    $movedFamilies = [];

    foreach ($familyGroups as $group) {
        $targetRow = canonicalFamilyRow($group);
        if (! $targetRow?->product_family_id) {
            $summary['skipped_rows'] += $group->count();
            continue;
        }

        $targetFamily = DB::table('product_families')->where('id', $targetRow->product_family_id)->first();
        if (! $targetFamily) {
            $summary['skipped_rows'] += $group->count();
            continue;
        }

        foreach ($group as $row) {
            if ((int) $row->product_family_id === (int) $targetFamily->id) {
                continue;
            }

            DB::table('product_sources')
                ->where('id', $row->source_link_id)
                ->update([
                    'product_family_id' => $targetFamily->id,
                    'notes' => appendMergeNote(
                        $row->source_notes,
                        "Merged into exact family candidate {$targetFamily->id} ({$targetFamily->family_name})."
                    ),
                    'updated_at' => now(),
                ]);

            $summary['family_source_links_repointed']++;

            if ($row->product_id) {
                $productMoveKey = "{$row->product_id}:{$row->product_family_id}:{$targetFamily->id}";
                if (! isset($movedProducts[$productMoveKey])) {
                    $moveSummary = moveProductToFamily((int) $row->product_id, (int) $row->product_family_id, $targetFamily);
                    $summary['products_moved_to_canonical_family'] += $moveSummary['product_moved'];
                    $summary['variant_values_moved'] += $moveSummary['variant_values_moved'];
                    $movedProducts[$productMoveKey] = true;
                }
            }

            $familyMoveKey = "{$row->product_family_id}:{$targetFamily->id}";
            if (! isset($movedFamilies[$familyMoveKey])) {
                $summary['family_media_moved'] += moveFamilyMedia((int) $row->product_family_id, (int) $targetFamily->id);
                $movedFamilies[$familyMoveKey] = true;
            }
        }
    }

    if (! $sync) {
        DB::rollBack();
    }

    return $summary;
});

echo ($sync ? "Janson + Mamado retail merge applied." : "Janson + Mamado retail merge dry run.").PHP_EOL;
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}".PHP_EOL;
}

if (! $sync) {
    echo "Run with --sync to apply these safe source/family merges.".PHP_EOL;
}

/**
 * @param array<int, string> $argv
 * @return array<string, string|bool>
 */
function parseMergeArgs(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $args[$key] = $value;
        } else {
            $args[$arg] = true;
        }
    }

    return $args;
}

function mergeKey(?string $value): string
{
    return Str::of((string) $value)
        ->ascii()
        ->lower()
        ->replace('&', ' and ')
        ->replace('+', ' and ')
        ->replaceMatches('/\b(?:ltd|limited|inc|llc|co)\b/', ' ')
        ->replaceMatches('/[^a-z0-9]+/', ' ')
        ->squish()
        ->toString();
}

function retailMergeSourceRows(): Collection
{
    return DB::table('product_sources as ps')
        ->join('product_families as pf', 'pf.id', '=', 'ps.product_family_id')
        ->join('products as p', 'p.id', '=', 'ps.product_id')
        ->whereIn('ps.source_type', ['janson_product', 'mamado_product'])
        ->whereNotNull('ps.product_id')
        ->where(function ($query): void {
            $query->whereNull('pf.root_catalogue_name')
                ->orWhere('pf.root_catalogue_name', '<>', 'Hair Extensions');
        })
        ->select([
            'ps.id as source_link_id',
            'ps.source_type',
            'ps.source_id',
            'ps.notes as source_notes',
            'ps.product_family_id',
            'ps.product_id',
            'pf.brand_name',
            'pf.family_name',
            'pf.brand_id as family_brand_id',
            'pf.root_catalogue_name',
            'pf.product_type_name',
            'p.name as product_name',
            'p.slug as product_slug',
            'p.brand_id as product_brand_id',
        ])
        ->get();
}

function exactDuplicateProductGroups(Collection $rows): Collection
{
    return $rows
        ->groupBy(fn (object $row): string => implode('|', [
            mergeKey($row->brand_name),
            mergeKey($row->family_name),
            mergeKey($row->product_name),
        ]))
        ->filter(fn (Collection $group): bool => $group->pluck('source_type')->unique()->count() > 1
            && $group->pluck('product_id')->unique()->count() > 1)
        ->values();
}

function exactDuplicateFamilyGroups(Collection $rows): Collection
{
    return $rows
        ->groupBy(fn (object $row): string => implode('|', [
            mergeKey($row->brand_name),
            mergeKey($row->family_name),
        ]))
        ->filter(fn (Collection $group): bool => $group->pluck('source_type')->unique()->count() > 1
            && $group->pluck('product_family_id')->unique()->count() > 1)
        ->values();
}

function canonicalProductRow(Collection $group): ?object
{
    return $group
        ->sortBy([
            fn (object $row): int => $row->source_type === 'janson_product' ? 0 : 1,
            fn (object $row): int => (int) $row->product_id,
        ])
        ->first();
}

function canonicalFamilyRow(Collection $group): ?object
{
    return $group
        ->sortBy([
            fn (object $row): int => $row->source_type === 'janson_product' ? 0 : 1,
            fn (object $row): int => (int) $row->product_family_id,
        ])
        ->first();
}

function appendMergeNote(?string $notes, string $message): string
{
    $notes = trim((string) $notes);
    if ($notes !== '' && str_contains($notes, $message)) {
        return $notes;
    }

    return trim($notes.' '.$message);
}

/**
 * @return array{product_moved:int, variant_values_moved:int}
 */
function moveProductToFamily(int $productId, int $fromFamilyId, object $targetFamily): array
{
    $product = DB::table('products')->where('id', $productId)->first();
    if (! $product || (int) $product->product_family_id === (int) $targetFamily->id) {
        return ['product_moved' => 0, 'variant_values_moved' => 0];
    }

    $variantValuesMoved = moveVariantValuesToFamily($productId, $fromFamilyId, (int) $targetFamily->id);
    $slug = uniqueProductSlugForFamily((string) $product->slug, (string) $product->name, (int) $targetFamily->id, $productId);

    DB::table('products')
        ->where('id', $productId)
        ->update([
            'product_family_id' => $targetFamily->id,
            'brand_id' => $targetFamily->brand_id,
            'slug' => $slug,
            'updated_at' => now(),
        ]);

    DB::table('product_ecommerce_profiles')
        ->where('product_id', $productId)
        ->update([
            'product_family_id' => $targetFamily->id,
            'updated_at' => now(),
        ]);

    DB::table('product_media')
        ->where('product_id', $productId)
        ->where('product_family_id', $fromFamilyId)
        ->update([
            'product_family_id' => $targetFamily->id,
            'updated_at' => now(),
        ]);

    return ['product_moved' => 1, 'variant_values_moved' => $variantValuesMoved];
}

function moveVariantValuesToFamily(int $productId, int $fromFamilyId, int $targetFamilyId): int
{
    $values = DB::table('product_variant_values as pvv')
        ->join('product_variant_groups as pvg', 'pvg.id', '=', 'pvv.product_variant_group_id')
        ->join('product_variant_options as pvo', 'pvo.id', '=', 'pvv.product_variant_option_id')
        ->where('pvv.product_id', $productId)
        ->where('pvg.product_family_id', $fromFamilyId)
        ->select([
            'pvv.id as value_id',
            'pvg.name as group_name',
            'pvg.variant_type',
            'pvg.sort_order as group_sort_order',
            'pvo.label as option_label',
            'pvo.value as option_value',
            'pvo.sort_order as option_sort_order',
        ])
        ->get();

    $moved = 0;
    foreach ($values as $value) {
        $targetGroupId = firstOrCreateVariantGroup($targetFamilyId, $value);
        $targetOptionId = firstOrCreateVariantOption($targetGroupId, $value);
        $existingForGroup = DB::table('product_variant_values')
            ->where('product_id', $productId)
            ->where('product_variant_group_id', $targetGroupId)
            ->where('id', '<>', $value->value_id)
            ->first();

        if ($existingForGroup) {
            DB::table('product_variant_values')->where('id', $existingForGroup->id)->update([
                'product_variant_option_id' => $targetOptionId,
                'updated_at' => now(),
            ]);
            DB::table('product_variant_values')->where('id', $value->value_id)->delete();
            $moved++;
            continue;
        }

        $existingForOption = DB::table('product_variant_values')
            ->where('product_id', $productId)
            ->where('product_variant_option_id', $targetOptionId)
            ->where('id', '<>', $value->value_id)
            ->first();

        if ($existingForOption) {
            DB::table('product_variant_values')->where('id', $value->value_id)->delete();
            $moved++;
            continue;
        }

        DB::table('product_variant_values')
            ->where('id', $value->value_id)
            ->update([
                'product_variant_group_id' => $targetGroupId,
                'product_variant_option_id' => $targetOptionId,
                'updated_at' => now(),
            ]);
        $moved++;
    }

    return $moved;
}

function firstOrCreateVariantGroup(int $targetFamilyId, object $value): int
{
    $group = DB::table('product_variant_groups')
        ->where('product_family_id', $targetFamilyId)
        ->where('name', $value->group_name)
        ->first();

    if ($group) {
        return (int) $group->id;
    }

    return (int) DB::table('product_variant_groups')->insertGetId([
        'product_family_id' => $targetFamilyId,
        'name' => $value->group_name,
        'variant_type' => $value->variant_type ?: 'text',
        'sort_order' => (int) $value->group_sort_order,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function firstOrCreateVariantOption(int $targetGroupId, object $value): int
{
    $option = DB::table('product_variant_options')
        ->where('product_variant_group_id', $targetGroupId)
        ->where('label', $value->option_label)
        ->first();

    if ($option) {
        return (int) $option->id;
    }

    return (int) DB::table('product_variant_options')->insertGetId([
        'product_variant_group_id' => $targetGroupId,
        'label' => $value->option_label,
        'value' => $value->option_value,
        'sort_order' => (int) $value->option_sort_order,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function uniqueProductSlugForFamily(string $currentSlug, string $productName, int $targetFamilyId, int $productId): string
{
    $base = $currentSlug !== '' ? $currentSlug : Str::slug($productName);
    $base = $base !== '' ? $base : 'product';
    $slug = $base;
    $counter = 2;

    while (DB::table('products')
        ->where('product_family_id', $targetFamilyId)
        ->where('slug', $slug)
        ->where('id', '<>', $productId)
        ->exists()) {
        $slug = "{$base}-{$counter}";
        $counter++;
    }

    return $slug;
}

function moveFamilyMedia(int $fromFamilyId, int $targetFamilyId): int
{
    return DB::table('product_media')
        ->where('product_family_id', $fromFamilyId)
        ->whereNull('product_id')
        ->update([
            'product_family_id' => $targetFamilyId,
            'updated_at' => now(),
        ]);
}

