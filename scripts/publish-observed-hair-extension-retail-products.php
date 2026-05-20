<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BrandCatalogueStyle;
use App\Models\ProductFamily;
use App\Services\RetailProductPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$apply = in_array('--apply', $argv, true);
$timestamp = date('Ymd-His');
$reportDir = public_path('reports');

if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$csvPath = $reportDir."/observed-hair-extension-retail-publish-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/observed-hair-extension-retail-publish-latest.csv';

function oh_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function oh_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function oh_axis(mixed $axis): string
{
    $key = oh_norm($axis);

    if (str_contains($key, 'length') || str_contains($key, 'inch')) {
        return 'Length';
    }
    if (str_contains($key, 'colour') || str_contains($key, 'color')) {
        return 'Colour';
    }
    if (str_contains($key, 'pack') || str_contains($key, 'bundle') || str_contains($key, 'piece') || str_contains($key, 'count')) {
        return 'Bundle';
    }
    if (str_contains($key, 'size')) {
        return 'Size';
    }

    return oh_clean($axis) ?: 'Variant';
}

function oh_value(mixed $value, string $axis): string
{
    $value = str_replace(['â€œ', 'â€', 'â€³', '“', '”', '″'], '"', oh_clean($value));

    return match (oh_axis($axis)) {
        'Length' => oh_clean(preg_replace('/^(\d+(?:\.\d+)?)\s*(?:\"|in|inch|inches|inchs|iches|niches|niche)$/i', '$1', $value) ?? $value),
        'Colour' => Str::upper($value),
        'Bundle' => oh_bundle_value($value),
        default => $value,
    };
}

function oh_bundle_value(string $value): string
{
    if (preg_match('/^(\d+)\s*(?:x|pcs?|pieces?)$/i', $value, $match)) {
        return $match[1].'X';
    }

    return Str::upper($value);
}

function oh_part_key(string $axis, mixed $value): string
{
    return oh_axis($axis).':'.oh_norm(oh_value($value, $axis));
}

function oh_is_empty_value(mixed $value): bool
{
    return in_array(oh_norm($value), [
        '',
        'unspecified',
        'unknown',
        'notknown',
        'na',
        'none',
        'notvisible',
        'notvisable',
        'colournotvisible',
        'colournotvisibleincrop',
        'colornotvisible',
        'colornotvisibleincrop',
    ], true);
}

function oh_axis_for_value(mixed $axis, mixed $value, ?string $pairedAxis = null): string
{
    $axis = oh_axis($axis);
    $pairedAxis = $pairedAxis ? oh_axis($pairedAxis) : null;
    $value = oh_clean($value);

    if ($axis === 'Colour' && $pairedAxis === 'Colour' && preg_match('/^\d+(?:\.\d+)?\s*(?:\"|in|inch|inches)?$/i', $value)) {
        return 'Length';
    }

    return $axis;
}

/**
 * @return list<list<array{axis:string,value:string}>>
 */
function oh_rows_from_intake(object $intake): array
{
    $structure = json_decode((string) $intake->variant_structure, true) ?: [];
    $rows = [];

    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $row = [];

        $mainAxis = oh_axis_for_value(
            $sku['main_axis'] ?? $structure['main_axis'] ?? 'Main',
            $sku['main_value'] ?? '',
            $sku['sub_axis'] ?? null,
        );
        $mainValue = oh_value($sku['main_value'] ?? '', $mainAxis);
        if (! oh_is_empty_value($mainValue)) {
            $row[] = ['axis' => $mainAxis, 'value' => $mainValue];
        }

        $subAxis = oh_axis_for_value($sku['sub_axis'] ?? 'Colour', $sku['sub_value'] ?? '');
        $subValue = oh_value($sku['sub_value'] ?? '', $subAxis);
        if (! oh_is_empty_value($subValue)) {
            $row[] = ['axis' => $subAxis, 'value' => $subValue];
        }

        foreach (($sku['common_attributes'] ?? []) as $axis => $values) {
            $axis = oh_axis($axis);
            foreach ((array) $values as $value) {
                $value = oh_value($value, $axis);
                if (! oh_is_empty_value($value)) {
                    $row[] = ['axis' => $axis, 'value' => $value];
                }
            }
        }

        if ($row !== []) {
            $rows[] = oh_unique_row($row);
        }
    }

    if ($rows !== []) {
        return oh_unique_rows($rows);
    }

    foreach (($structure['groups'] ?? []) as $group) {
        $mainAxis = oh_axis_for_value($structure['main_axis'] ?? 'Main', $group['main_value'] ?? '', $group['sub_axis'] ?? null);
        $mainValue = oh_value($group['main_value'] ?? '', $mainAxis);
        $base = [];

        if (! oh_is_empty_value($mainValue)) {
            $base[] = ['axis' => $mainAxis, 'value' => $mainValue];
        }

        $subAxis = oh_axis_for_value($group['sub_axis'] ?? 'Colour', '');
        $subValues = array_values(array_filter((array) ($group['sub_values'] ?? []), fn ($value): bool => ! oh_is_empty_value($value)));

        if ($subValues === []) {
            if ($base !== []) {
                $rows[] = oh_unique_row($base);
            }
            continue;
        }

        foreach ($subValues as $subValue) {
            $row = $base;
            $row[] = ['axis' => $subAxis, 'value' => oh_value($subValue, $subAxis)];
            $rows[] = oh_unique_row($row);
        }
    }

    return oh_unique_rows($rows);
}

/**
 * @param list<array{axis:string,value:string}> $row
 * @return list<array{axis:string,value:string}>
 */
function oh_unique_row(array $row): array
{
    $seen = [];
    $result = [];

    foreach ($row as $part) {
        $key = oh_part_key($part['axis'], $part['value']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $result[] = $part;
    }

    return $result;
}

/**
 * @param list<list<array{axis:string,value:string}>> $rows
 * @return list<list<array{axis:string,value:string}>>
 */
function oh_unique_rows(array $rows): array
{
    $seen = [];
    $result = [];

    foreach ($rows as $row) {
        $key = oh_row_signature($row);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $result[] = $row;
    }

    return $result;
}

/**
 * @param list<array{axis:string,value:string}> $row
 */
function oh_row_signature(array $row): string
{
    return collect($row)
        ->map(fn (array $part): string => oh_part_key($part['axis'], $part['value']))
        ->sort()
        ->values()
        ->implode('|');
}

/**
 * @return array<int, array{sku:object,parts:array<string,true>,entries:list<array{axis:string,value:string}>}>
 */
function oh_sku_index_for_style(int $styleId): array
{
    $skus = DB::table('brand_catalogue_skus')->where('brand_catalogue_style_id', $styleId)->get();
    $index = [];

    foreach ($skus as $sku) {
        $parts = [];
        $entries = [];
        $options = DB::table('brand_catalogue_sku_variant_options as svo')
            ->join('brand_catalogue_variants as v', 'v.id', '=', 'svo.brand_catalogue_variant_id')
            ->join('brand_catalogue_variant_options as vo', 'vo.id', '=', 'svo.brand_catalogue_variant_option_id')
            ->where('svo.brand_catalogue_sku_id', $sku->id)
            ->get(['v.name as axis', 'vo.value', 'vo.label']);

        foreach ($options as $option) {
            $axis = oh_axis($option->axis);
            $value = oh_value($option->value ?: $option->label, $axis);
            $parts[oh_part_key($axis, $value)] = true;
            $entries[] = ['axis' => $axis, 'value' => $value];
        }

        $index[(int) $sku->id] = ['sku' => $sku, 'parts' => $parts, 'entries' => $entries];
    }

    return $index;
}

/**
 * @param list<array{axis:string,value:string}> $row
 * @param array<int, array{sku:object,parts:array<string,true>,entries:list<array{axis:string,value:string}>}> $skuIndex
 * @return array{ids:list<int>,status:string}
 */
function oh_match_row_to_skus(array $row, array $skuIndex): array
{
    $wanted = [];
    $wantedParts = [];
    foreach ($row as $part) {
        $axis = oh_axis($part['axis']);
        $value = oh_value($part['value'], $axis);
        $wanted[] = oh_part_key($axis, $value);
        $wantedParts[] = ['axis' => $axis, 'value' => $value];
    }
    $wanted = array_values(array_unique($wanted));

    if ($wanted === [] && count($skuIndex) === 1) {
        return ['ids' => [array_key_first($skuIndex)], 'status' => 'single_sku_style'];
    }

    if (count($skuIndex) === 1) {
        $only = reset($skuIndex);
        if (($only['parts'] ?? []) === []) {
            return ['ids' => [array_key_first($skuIndex)], 'status' => 'single_sku_style'];
        }
    }

    $exact = [];
    $subset = [];

    foreach ($skuIndex as $skuId => $entry) {
        $parts = array_keys($entry['parts']);
        $containsAll = true;
        foreach ($wantedParts as $wantedPart) {
            if (! oh_sku_satisfies_part($entry, $wantedPart)) {
                $containsAll = false;
                break;
            }
        }
        if (! $containsAll) {
            continue;
        }

        if (count($parts) === count($wanted)) {
            $exact[] = $skuId;
        } else {
            $subset[] = $skuId;
        }
    }

    if ($exact !== []) {
        return ['ids' => array_values($exact), 'status' => count($exact) === 1 ? 'exact' : 'duplicate_exact'];
    }

    if (count($subset) === 1) {
        return ['ids' => array_values($subset), 'status' => 'unique_subset'];
    }

    if (count($subset) > 1) {
        return ['ids' => [], 'status' => 'ambiguous_subset'];
    }

    return ['ids' => [], 'status' => 'not_found'];
}

/**
 * @param array{sku:object,parts:array<string,true>,entries:list<array{axis:string,value:string}>} $entry
 * @param array{axis:string,value:string} $wantedPart
 */
function oh_sku_satisfies_part(array $entry, array $wantedPart): bool
{
    $axis = oh_axis($wantedPart['axis']);
    $value = oh_value($wantedPart['value'], $axis);
    $key = oh_part_key($axis, $value);

    if (isset($entry['parts'][$key])) {
        return true;
    }

    if ($axis === 'Length') {
        $hasLengthAxis = collect($entry['entries'])->contains(fn (array $skuPart): bool => oh_axis($skuPart['axis']) === 'Length');
        if (! $hasLengthAxis) {
            return true;
        }

        foreach ($entry['entries'] as $skuPart) {
            if (oh_axis($skuPart['axis']) !== 'Length') {
                continue;
            }

            $components = preg_split('/[^0-9.]+/', (string) $skuPart['value'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (in_array($value, $components, true)) {
                return true;
            }
        }
    }

    if (in_array($axis, ['Length', 'Bundle'], true)) {
        $skuNameKey = oh_norm($entry['sku']->name ?? '');
        if ($skuNameKey !== '' && str_contains($skuNameKey, oh_norm($value))) {
            return true;
        }
    }

    if ($axis === 'Bundle') {
        $hasBundleAxis = collect($entry['entries'])->contains(fn (array $skuPart): bool => oh_axis($skuPart['axis']) === 'Bundle');
        if (! $hasBundleAxis) {
            return true;
        }
    }

    return false;
}

function oh_backup(array $styleIds, string $timestamp): string
{
    $familyIds = DB::table('product_families')->whereIn('brand_catalogue_style_id', $styleIds)->pluck('id');
    $productIds = DB::table('products')->whereIn('product_family_id', $familyIds)->pluck('id');
    $groupIds = DB::table('product_variant_groups')->whereIn('product_family_id', $familyIds)->pluck('id');
    $optionIds = DB::table('product_variant_options')->whereIn('product_variant_group_id', $groupIds)->pluck('id');

    $backup = [
        'product_families' => DB::table('product_families')->whereIn('id', $familyIds)->get(),
        'products' => DB::table('products')->whereIn('id', $productIds)->get(),
        'product_prices' => DB::table('product_prices')->whereIn('product_id', $productIds)->get(),
        'inventory_levels' => DB::table('inventory_levels')->whereIn('product_id', $productIds)->get(),
        'product_media' => DB::table('product_media')->whereIn('product_family_id', $familyIds)->orWhereIn('product_id', $productIds)->get(),
        'product_sources' => DB::table('product_sources')->whereIn('product_family_id', $familyIds)->orWhereIn('product_id', $productIds)->get(),
        'product_variant_groups' => DB::table('product_variant_groups')->whereIn('id', $groupIds)->get(),
        'product_variant_options' => DB::table('product_variant_options')->whereIn('id', $optionIds)->get(),
        'product_variant_values' => DB::table('product_variant_values')->whereIn('product_id', $productIds)->get(),
        'product_pos_profiles' => DB::table('product_pos_profiles')->whereIn('product_id', $productIds)->get(),
        'product_ecommerce_profiles' => DB::table('product_ecommerce_profiles')->whereIn('product_family_id', $familyIds)->orWhereIn('product_id', $productIds)->get(),
        'product_category_assignments' => DB::table('product_category_assignments')->whereIn('product_family_id', $familyIds)->orWhereIn('product_id', $productIds)->get(),
    ];

    $path = "catalogue-backups/observed-hair-extension-retail-publish-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function oh_product_is_safe_to_prune(object $product): bool
{
    if (oh_clean($product->barcode) !== '' || (bool) $product->is_pos_active || (bool) $product->is_ecommerce_active === true) {
        return false;
    }

    $price = DB::table('product_prices')->where('product_id', $product->id)->first();
    if ($price && ($price->retail_price !== null || $price->compare_at_price !== null || $price->cost_price !== null)) {
        return false;
    }

    $hasStockOrShelf = DB::table('inventory_levels')
        ->where('product_id', $product->id)
        ->where(function ($query) {
            $query->where('stock_quantity', '>', 0)
                ->orWhereNotNull('inventory_section_id')
                ->orWhereNotNull('inventory_subsection_id')
                ->orWhereNotNull('shelf_location')
                ->orWhereNotNull('supplier')
                ->orWhereNotNull('supplier_product_code');
        })
        ->exists();

    if ($hasStockOrShelf) {
        return false;
    }

    $hasManualMedia = DB::table('product_media')
        ->where('product_id', $product->id)
        ->where('source_type', '!=', 'catalogue_source')
        ->exists();

    if ($hasManualMedia) {
        return false;
    }

    $hasNonCatalogueSource = DB::table('product_sources')
        ->where('product_id', $product->id)
        ->where('source_type', '!=', 'brand_catalogue_sku')
        ->exists();

    if ($hasNonCatalogueSource) {
        return false;
    }

    $hasPublishedEcommerce = DB::table('product_ecommerce_profiles')
        ->where('product_id', $product->id)
        ->where('is_published', true)
        ->exists();

    return ! $hasPublishedEcommerce;
}

function oh_delete_products(array $productIds): void
{
    if ($productIds === []) {
        return;
    }

    DB::table('stock_movements')->whereIn('product_id', $productIds)->delete();
    DB::table('product_category_assignments')->whereIn('product_id', $productIds)->delete();
    DB::table('product_sources')->whereIn('product_id', $productIds)->delete();
    DB::table('product_media')->whereIn('product_id', $productIds)->delete();
    DB::table('product_variant_values')->whereIn('product_id', $productIds)->delete();
    DB::table('product_prices')->whereIn('product_id', $productIds)->delete();
    DB::table('inventory_levels')->whereIn('product_id', $productIds)->delete();
    DB::table('product_pos_profiles')->whereIn('product_id', $productIds)->delete();
    DB::table('product_ecommerce_profiles')->whereIn('product_id', $productIds)->delete();
    DB::table('products')->whereIn('id', $productIds)->delete();
}

function oh_prune_family_to_allowed_skus(ProductFamily $family, array $allowedSkuIds, bool $apply, array &$stats): array
{
    $allowed = array_fill_keys($allowedSkuIds, true);
    $extras = DB::table('products')
        ->where('product_family_id', $family->id)
        ->whereNotIn('brand_catalogue_sku_id', $allowedSkuIds ?: [0])
        ->get();

    $safeDeleteIds = [];
    $protected = [];

    foreach ($extras as $product) {
        if (oh_product_is_safe_to_prune($product)) {
            $safeDeleteIds[] = (int) $product->id;
        } else {
            $protected[] = (int) $product->id;
        }
    }

    $stats['extra_products_seen'] += $extras->count();
    $stats['extra_products_safe_pruned'] += count($safeDeleteIds);
    $stats['extra_products_protected'] += count($protected);

    if ($apply) {
        oh_delete_products($safeDeleteIds);
        oh_prune_unused_variant_options($family);
    }

    return ['safe_deleted' => $safeDeleteIds, 'protected' => $protected];
}

function oh_mark_observed_products(ProductFamily $family, array $allowedSkuIds, array $intakeIds, bool $apply, array &$stats): void
{
    $products = DB::table('products')
        ->where('product_family_id', $family->id)
        ->whereIn('brand_catalogue_sku_id', $allowedSkuIds)
        ->get(['id']);

    $notes = 'Shop-floor observed in Hair Extension V2 intake(s): #'.implode(', #', array_values(array_unique($intakeIds))).'.';

    foreach ($products as $product) {
        $stats['observed_product_sources_marked']++;

        if (! $apply) {
            continue;
        }

        $existing = DB::table('product_sources')
            ->where('product_id', $product->id)
            ->where('source_type', 'hair_extension_v2_observed')
            ->first();

        $payload = [
            'product_family_id' => $family->id,
            'product_id' => $product->id,
            'source_type' => 'hair_extension_v2_observed',
            'source_table' => 'hair_extension_intakes',
            'source_id' => null,
            'source_url' => null,
            'confidence' => 'A',
            'notes' => $notes,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('product_sources')->where('id', $existing->id)->update($payload);
        } else {
            $payload['created_at'] = now();
            DB::table('product_sources')->insert($payload);
        }
    }
}

function oh_reconcile_existing_family_variant_options(int $styleId, array $skuIds, bool $apply, array &$stats): void
{
    $family = ProductFamily::query()
        ->where('brand_catalogue_style_id', $styleId)
        ->whereNull('catalogue_scope_key')
        ->first();

    if (! $family) {
        return;
    }

    $optionRows = DB::table('brand_catalogue_sku_variant_options as svo')
        ->join('brand_catalogue_variant_options as vo', 'vo.id', '=', 'svo.brand_catalogue_variant_option_id')
        ->join('brand_catalogue_variants as v', 'v.id', '=', 'svo.brand_catalogue_variant_id')
        ->whereIn('svo.brand_catalogue_sku_id', $skuIds)
        ->get([
            'v.id as catalogue_variant_id',
            'vo.id as catalogue_option_id',
            'vo.label',
            'vo.value',
            'vo.sort_order',
        ]);

    foreach ($optionRows as $row) {
        $group = DB::table('product_variant_groups')
            ->where('product_family_id', $family->id)
            ->where('brand_catalogue_variant_id', $row->catalogue_variant_id)
            ->first();

        if (! $group) {
            continue;
        }

        $byCatalogue = DB::table('product_variant_options')
            ->where('brand_catalogue_variant_option_id', $row->catalogue_option_id)
            ->first();

        $byLabel = DB::table('product_variant_options')
            ->where('product_variant_group_id', $group->id)
            ->where('label', $row->label)
            ->first();

        if (! $byCatalogue && $byLabel) {
            $stats['stale_variant_options_relinked']++;
            if ($apply) {
                DB::table('product_variant_options')->where('id', $byLabel->id)->update([
                    'brand_catalogue_variant_option_id' => $row->catalogue_option_id,
                    'value' => $row->value,
                    'sort_order' => $row->sort_order,
                    'updated_at' => now(),
                ]);
            }
            continue;
        }

        if ($byCatalogue && $byLabel && (int) $byCatalogue->id !== (int) $byLabel->id) {
            $stats['stale_variant_options_merged']++;
            if ($apply) {
                DB::table('product_variant_values')
                    ->where('product_variant_option_id', $byLabel->id)
                    ->update(['product_variant_option_id' => $byCatalogue->id]);
                DB::table('product_variant_options')->where('id', $byLabel->id)->delete();
            }
        }
    }
}

function oh_prune_unused_variant_options(ProductFamily $family): void
{
    $groupIds = DB::table('product_variant_groups')->where('product_family_id', $family->id)->pluck('id');

    foreach ($groupIds as $groupId) {
        $usedOptionIds = DB::table('product_variant_values')
            ->where('product_variant_group_id', $groupId)
            ->pluck('product_variant_option_id')
            ->all();

        DB::table('product_variant_options')
            ->where('product_variant_group_id', $groupId)
            ->when($usedOptionIds !== [], fn ($query) => $query->whereNotIn('id', $usedOptionIds))
            ->when($usedOptionIds === [], fn ($query) => $query)
            ->delete();
    }

    foreach ($groupIds as $groupId) {
        $hasOptions = DB::table('product_variant_options')->where('product_variant_group_id', $groupId)->exists();
        $hasValues = DB::table('product_variant_values')->where('product_variant_group_id', $groupId)->exists();

        if (! $hasOptions && ! $hasValues) {
            DB::table('product_variant_groups')->where('id', $groupId)->delete();
        }
    }
}

$intakes = DB::table('hair_extension_intakes')
    ->where('status', 'submitted')
    ->whereNotNull('brand_catalogue_style_id')
    ->orderBy('brand_name')
    ->orderBy('id')
    ->get();

$styleSkuIds = [];
$styleIntakeIds = [];
$rows = [];
$matchStats = [
    'intakes_seen' => $intakes->count(),
    'row_matches_exact_or_subset' => 0,
    'row_matches_ambiguous' => 0,
    'row_matches_missing' => 0,
];

$skuIndexCache = [];

foreach ($intakes as $intake) {
    $styleId = (int) $intake->brand_catalogue_style_id;
    $styleIntakeIds[$styleId] ??= [];
    $styleIntakeIds[$styleId][] = (int) $intake->id;
    $skuIndexCache[$styleId] ??= oh_sku_index_for_style($styleId);
    $skuIndex = $skuIndexCache[$styleId];

    foreach (oh_rows_from_intake($intake) as $row) {
        $match = oh_match_row_to_skus($row, $skuIndex);
        $rowSignature = oh_row_signature($row);

        if ($match['ids'] !== []) {
            foreach ($match['ids'] as $skuId) {
                $styleSkuIds[$styleId][$skuId] = true;
            }
            $matchStats['row_matches_exact_or_subset']++;
        } elseif ($match['status'] === 'ambiguous_subset') {
            $matchStats['row_matches_ambiguous']++;
        } else {
            $matchStats['row_matches_missing']++;
        }

        $rows[] = [
            'intake_id' => $intake->id,
            'brand' => $intake->brand_name,
            'style_id' => $styleId,
            'style_name' => $intake->style_name,
            'row_signature' => $rowSignature,
            'match_status' => $match['status'],
            'sku_ids' => implode('|', $match['ids']),
            'applied' => $apply ? 'yes' : 'no',
        ];
    }
}

$styleIds = array_keys($styleSkuIds);
$stats = [
    ...$matchStats,
    'styles_with_observed_skus' => count($styleIds),
    'observed_skus' => collect($styleSkuIds)->map(fn (array $ids): int => count($ids))->sum(),
    'backup' => '',
    'families_published_or_updated' => 0,
    'products_after_publish' => 0,
    'extra_products_seen' => 0,
    'extra_products_safe_pruned' => 0,
    'extra_products_protected' => 0,
    'stale_variant_options_relinked' => 0,
    'stale_variant_options_merged' => 0,
    'observed_product_sources_marked' => 0,
];

DB::transaction(function () use ($apply, $styleSkuIds, $styleIntakeIds, &$stats): void {
    $publisher = app(RetailProductPublisher::class);
    $styleIds = array_map('intval', array_keys($styleSkuIds));
    $stats['backup'] = oh_backup($styleIds, date('Ymd-His'));

    foreach ($styleSkuIds as $styleId => $skuIdMap) {
        $skuIds = array_values(array_map('intval', array_keys($skuIdMap)));
        sort($skuIds);

        if ($apply) {
            oh_reconcile_existing_family_variant_options((int) $styleId, $skuIds, $apply, $stats);
            $style = BrandCatalogueStyle::query()->findOrFail($styleId);
            $family = $publisher->publishBrandCatalogueStyle($style, $skuIds);
            oh_mark_observed_products($family, $skuIds, $styleIntakeIds[(int) $styleId] ?? [], $apply, $stats);
            $prune = oh_prune_family_to_allowed_skus($family, $skuIds, $apply, $stats);
            $stats['products_after_publish'] += DB::table('products')->where('product_family_id', $family->id)->count();
        }

        $stats['families_published_or_updated']++;
    }
});

$csv = fopen($csvPath, 'w');
fputcsv($csv, [
    'intake_id',
    'brand',
    'style_id',
    'style_name',
    'row_signature',
    'match_status',
    'sku_ids',
    'applied',
]);
foreach ($rows as $row) {
    fputcsv($csv, $row);
}
fclose($csv);
copy($csvPath, $latestCsvPath);

echo json_encode([
    'mode' => $apply ? 'applied' : 'dry_run',
    'stats' => $stats,
    'csv' => $csvPath,
    'latest_csv' => $latestCsvPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

if (! $apply) {
    echo "Run with --apply to publish observed-only retail products.\n";
}
