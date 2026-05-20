<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$apply = in_array('--apply', $argv, true);
$timestamp = date('Ymd-His');
$reportDir = public_path('reports');

if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$csvPath = $reportDir."/smart-v2-catalogue-sync-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/smart-v2-catalogue-sync-latest.csv';

function sm_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function sm_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function sm_path_text(array $path): string
{
    return implode(' > ', array_filter(array_map('sm_clean', $path)));
}

function sm_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $i = 2;

    while (
        DB::table($table)
            ->where($scopeColumn, $scopeId)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
    ) {
        $slug = "{$base}-{$i}";
        $i++;
    }

    return $slug;
}

function sm_axis(mixed $axis): string
{
    $key = sm_norm($axis);

    if (str_contains($key, 'length')) {
        return 'Length';
    }
    if (str_contains($key, 'colour') || str_contains($key, 'color')) {
        return 'Colour';
    }
    if (str_contains($key, 'pack') || str_contains($key, 'bundle') || str_contains($key, 'count') || str_contains($key, 'piece')) {
        return 'Bundle';
    }

    return sm_clean($axis) ?: 'Variant';
}

function sm_axis_sort_order(string $axis): int
{
    return match ($axis) {
        'Length' => 10,
        'Colour' => 20,
        'Bundle' => 30,
        default => 90,
    };
}

function sm_variant_type(string $axis): string
{
    return match ($axis) {
        'Length' => 'measurement',
        'Colour' => 'colour_code',
        'Bundle' => 'count',
        default => 'text',
    };
}

function sm_length_value(mixed $value): string
{
    $value = str_replace(['Ã¢â‚¬Å“', 'Ã¢â‚¬Â', 'Ã¢â‚¬Â³'], '"', sm_clean($value));
    $value = preg_replace('/^(\d+(?:\.\d+)?)\s*(?:\"|in|inch|inches)$/i', '$1', $value) ?? $value;

    return sm_clean($value);
}

function sm_colour_value(mixed $value): string
{
    $value = sm_clean($value);

    if (preg_match('/^natural\s+/i', $value)) {
        return Str::title(Str::lower($value));
    }

    return Str::upper($value);
}

function sm_bundle_value(mixed $value): string
{
    $value = sm_clean($value);

    if (preg_match('/^(\d+)\s*(?:x|pcs?|pieces?)$/i', $value, $match)) {
        return $match[1].'X';
    }

    return Str::upper($value);
}

function sm_variant_value(mixed $value, string $axis = ''): string
{
    $axis = sm_axis($axis);

    return match ($axis) {
        'Length' => sm_length_value($value),
        'Colour' => sm_colour_value($value),
        'Bundle' => sm_bundle_value($value),
        default => sm_clean($value),
    };
}

function sm_option_label(string $axis, string $value): string
{
    return $axis === 'Length' ? $value.'"' : $value;
}

function sm_option_key(string $axis, mixed $value): string
{
    return sm_norm(sm_variant_value($value, $axis));
}

function sm_is_non_sellable_value(string $value): bool
{
    return in_array(sm_norm($value), ['unspecified', 'unknown', 'notknown', 'na', 'none'], true);
}

function sm_style_has_fixed_bundle(string $style): bool
{
    return str_contains(sm_norm($style), 'pack') || preg_match('/\b\d+\s*x\b/i', $style);
}

function sm_canonical_for(object $intake): array
{
    return match ((int) $intake->id) {
        58 => [
            'path' => ['Smart Braid'],
            'type' => 'Braid',
            'style' => 'Smart Braid Pre-Stretched 6X Pack 28"',
            'material' => 'Synthetic Fiber',
            'source_style_id' => 648,
            'skip_axes' => ['Bundle'],
            'reason' => 'Shop-floor V2 intake identifies Smart Pre-Stretched 6X 28 inch; existing Smart Hair Intl source style is Smart Braid Pre-Stretched 6X Pack 28".',
        ],
        66 => [
            'path' => ['Smart Braid'],
            'type' => 'Braid',
            'style' => 'Smart Braid Pre-Stretched 3X Pack 28"',
            'material' => 'Synthetic Fiber',
            'source_style_id' => 646,
            'skip_axes' => ['Bundle'],
            'reason' => 'Shop-floor V2 intake identifies Smart Pre-Stretched 3X 28 inch; existing Smart Hair Intl source style is Smart Braid Pre-Stretched 3X Pack 28".',
        ],
        88 => [
            'path' => ['Vivitress'],
            'type' => 'Crochet Braid',
            'style' => 'Vivitress 2X Water Wave Fro Twist 12"',
            'material' => 'Synthetic Fiber',
            'source_style_id' => 652,
            'skip_axes' => ['Bundle'],
            'reason' => 'Shop-floor V2 intake reads Vivitress Braid for Kids Waterwave Fro Twist 12 inch 2X; matched to source style Vivitress 2X Water Wave Fro Twist 12".',
        ],
        100 => [
            'path' => ['Vivitress'],
            'type' => 'Crochet Braid',
            'style' => 'Vivitress Mega Pack 3X French Curl 28"',
            'material' => 'Synthetic Fiber',
            'source_style_id' => 676,
            'skip_axes' => ['Bundle'],
            'reason' => 'Shop-floor photo reads Vivitress 3X French Curl Braid 28 inch; matched to Vivitress Mega Pack 3X French Curl 28".',
        ],
        186 => [
            'path' => ['Glamlace Ponytails'],
            'type' => 'Ponytail',
            'style' => 'Glamlace Ponytail Sandy',
            'material' => 'Synthetic Fiber',
            'source_style_id' => 756,
            'skip_axes' => ['Length'],
            'reason' => 'Shop-floor V2 intake identifies Glamlace Ponytail Sandy; source style has colour variants only, so observed 18 inch is treated as fixed product info rather than a sellable variant.',
        ],
        227, 230 => [
            'path' => ['Remy Chaser'],
            'type' => 'Weave',
            'style' => 'Remy Chaser Deep Wave',
            'material' => 'Human Hair',
            'source_style_id' => 668,
            'skip_axes' => [],
            'reason' => 'Shop-floor V2 intake identifies Remy Chaser Deep Wave; matched to Smart source Remy Chaser Deep Wave weave family.',
        ],
        228 => [
            'path' => ['Remy Chaser'],
            'type' => 'Weave',
            'style' => 'Remy Chaser Yaki Straight',
            'material' => 'Human Hair',
            'source_style_id' => 673,
            'skip_axes' => [],
            'reason' => 'Shop-floor V2 intake identifies Remy Chaser Yaki Straight; matched to Smart source Remy Chaser Yaki Straight weave family.',
        ],
        229 => [
            'path' => ['Remy Chaser'],
            'type' => 'Weave',
            'style' => 'Remy Chaser Water Wave',
            'material' => 'Human Hair',
            'source_style_id' => 672,
            'skip_axes' => [],
            'reason' => 'Shop-floor V2 intake identifies Remy Chaser Water Wave; matched to Smart source Remy Chaser Water Wave weave family.',
        ],
        259 => [
            'path' => ['Natural Bundle Weave'],
            'type' => 'Weave',
            'style' => 'Smart Natural Bundle 2X Olivia Weave 18"',
            'material' => 'Synthetic Fiber',
            'source_style_id' => 695,
            'skip_axes' => ['Bundle'],
            'reason' => 'Shop-floor V2 intake identifies Smart Natural Bundle 2X Olivia Weave 18 inch; matched to existing source style.',
        ],
        300 => [
            'path' => ['Vivitress'],
            'type' => 'Bulk Hair',
            'style' => 'Vivitress Afro Kinky Bulk 24"',
            'material' => 'Synthetic Fiber',
            'source_style_id' => 675,
            'skip_axes' => [],
            'reason' => 'Shop-floor V2 intake identifies Vivitress Afro Kinky Bulk 24 inch; Bulk is treated as the product format even though the imported source originally placed it under Crochet Hair.',
        ],
        default => [
            'path' => array_values(array_filter(json_decode((string) $intake->classification_path, true) ?: [])) ?: ['Smart'],
            'type' => sm_clean($intake->product_type_name) ?: 'Hair Extension',
            'style' => sm_clean($intake->style_name ?: $intake->observed_product_name) ?: 'Review style',
            'material' => 'Review material',
            'source_style_id' => $intake->brand_catalogue_style_id ?: null,
            'skip_axes' => [],
            'reason' => 'Fallback mapping from submitted Smart V2 intake.',
        ],
    };
}

function sm_family_key(array $canonical): string
{
    return implode('|', [
        sm_norm(sm_path_text($canonical['path'])),
        sm_norm($canonical['type']),
        sm_norm($canonical['style']),
    ]);
}

function sm_sku_rows(object $intake, array $canonical): array
{
    $structure = json_decode((string) $intake->variant_structure, true) ?: [];
    $skipAxes = array_map('sm_axis', $canonical['skip_axes'] ?? []);
    if (sm_style_has_fixed_bundle($canonical['style'])) {
        $skipAxes[] = 'Bundle';
    }

    $rows = [];
    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $row = [];
        $mainAxis = sm_axis($sku['main_axis'] ?? $structure['main_axis'] ?? 'Main');
        $main = sm_variant_value($sku['main_value'] ?? '', $mainAxis);
        if (! in_array($mainAxis, $skipAxes, true) && $main !== '' && ! sm_is_non_sellable_value($main)) {
            $row[] = ['axis' => $mainAxis, 'value' => $main];
        }

        $subAxis = sm_axis($sku['sub_axis'] ?? 'Colour');
        $sub = sm_variant_value($sku['sub_value'] ?? '', $subAxis);
        if (! in_array($subAxis, $skipAxes, true) && $sub !== '' && ! sm_is_non_sellable_value($sub)) {
            $row[] = ['axis' => $subAxis, 'value' => $sub];
        }

        foreach (($sku['common_attributes'] ?? []) as $axis => $values) {
            $axis = sm_axis($axis);
            if (in_array($axis, $skipAxes, true)) {
                continue;
            }
            foreach ((array) $values as $value) {
                $value = sm_variant_value($value, $axis);
                if ($value !== '' && ! sm_is_non_sellable_value($value)) {
                    $row[] = ['axis' => $axis, 'value' => $value];
                }
            }
        }

        if ($row !== []) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function sm_sku_name(array $canonical, array $row): string
{
    $name = $canonical['style'];
    $length = null;
    $colour = null;
    $bundle = null;

    foreach ($row as $part) {
        if ($part['axis'] === 'Length' && ! str_contains(sm_norm($name), sm_norm($part['value']))) {
            $length = $part['value'].'"';
        } elseif ($part['axis'] === 'Colour') {
            $colour = $part['value'];
        } elseif ($part['axis'] === 'Bundle' && ! str_contains(sm_norm($name), sm_norm($part['value']))) {
            $bundle = $part['value'];
        }
    }

    if ($bundle !== null) {
        $name .= ' '.$bundle;
    }
    if ($length !== null) {
        $name .= ' '.$length;
    }
    if ($colour !== null) {
        $name .= ' - Colour '.$colour;
    }

    return sm_clean($name);
}

function sm_signature(array $row): string
{
    return collect($row)->map(fn (array $part): string => $part['axis'].':'.$part['value'])->implode('|');
}

function sm_backup(object $brand, ?object $duplicateVivitress, string $timestamp): string
{
    $brandIds = array_values(array_filter([$brand->id, $duplicateVivitress?->id]));
    $styleIds = DB::table('brand_catalogue_styles')->whereIn('brand_catalogue_brand_id', $brandIds)->pluck('id');
    $variantIds = DB::table('brand_catalogue_variants')->whereIn('brand_catalogue_style_id', $styleIds)->pluck('id');
    $skuIds = DB::table('brand_catalogue_skus')->whereIn('brand_catalogue_style_id', $styleIds)->pluck('id');

    $backup = [
        'brands' => DB::table('brand_catalogue_brands')->whereIn('id', $brandIds)->get(),
        'lines' => DB::table('brand_catalogue_lines')->whereIn('brand_catalogue_brand_id', $brandIds)->get(),
        'product_types' => DB::table('brand_catalogue_product_types')->whereIn('brand_catalogue_brand_id', $brandIds)->get(),
        'styles' => DB::table('brand_catalogue_styles')->whereIn('brand_catalogue_brand_id', $brandIds)->get(),
        'variants' => DB::table('brand_catalogue_variants')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'variant_options' => DB::table('brand_catalogue_variant_options')->whereIn('variant_id', $variantIds)->get(),
        'skus' => DB::table('brand_catalogue_skus')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'sku_variant_options' => DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_sku_id', $skuIds)->get(),
        'intakes' => DB::table('hair_extension_intakes')
            ->whereIn('brand_name', ['Smart', 'Vivitress', 'Remy Chaser', 'Smart Braid', 'X-Smart'])
            ->orWhereIn('brand_catalogue_brand_id', $brandIds)
            ->get(),
        'product_families' => DB::table('product_families')
            ->whereIn('brand_catalogue_brand_id', $brandIds)
            ->orWhereIn('brand_name', ['Smart', 'Vivitress', 'Remy Chaser', 'Smart Braid', 'X-Smart'])
            ->get(),
    ];

    $path = "catalogue-backups/smart-v2-sync-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function sm_ensure_line(object $brand, array $path, bool $apply, array &$stats, array &$cache): object
{
    $name = sm_path_text($path) ?: 'Smart';
    $key = sm_norm($name);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $line = DB::table('brand_catalogue_lines')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', $name)
        ->first();

    if (! $line && $apply) {
        $id = DB::table('brand_catalogue_lines')->insertGetId([
            'brand_catalogue_brand_id' => $brand->id,
            'name' => $name,
            'slug' => sm_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $name),
            'note' => 'Created from Smart V2 shop-floor intake structure.',
            'url' => '',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $line = DB::table('brand_catalogue_lines')->where('id', $id)->first();
        $stats['lines_created']++;
    }

    $line ??= (object) ['id' => 0, 'name' => $name];
    return $cache[$key] = $line;
}

function sm_ensure_type(object $brand, object $line, string $name, bool $apply, array &$stats, array &$cache): object
{
    $key = $line->id.'|'.sm_norm($name);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $type = DB::table('brand_catalogue_product_types')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('brand_catalogue_line_id', $line->id)
        ->where('name', $name)
        ->first();

    if (! $type && $apply) {
        $id = DB::table('brand_catalogue_product_types')->insertGetId([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_line_id' => $line->id,
            'name' => $name,
            'slug' => sm_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', (int) $line->id, $name),
            'note' => 'Created from Smart V2 shop-floor intake structure.',
            'url' => $line->url ?? '',
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $type = DB::table('brand_catalogue_product_types')->where('id', $id)->first();
        $stats['product_types_created']++;
    }

    $type ??= (object) ['id' => 0, 'name' => $name, 'brand_catalogue_line_id' => $line->id];
    return $cache[$key] = $type;
}

function sm_ensure_style(object $brand, object $type, array $family, bool $apply, array &$stats): object
{
    $canonical = $family['canonical'];
    $style = null;

    if (! empty($canonical['source_style_id'])) {
        $style = DB::table('brand_catalogue_styles')
            ->where('id', $canonical['source_style_id'])
            ->where('brand_catalogue_brand_id', $brand->id)
            ->first();
    }

    if (! $style) {
        $style = DB::table('brand_catalogue_styles')
            ->where('brand_catalogue_brand_id', $brand->id)
            ->where('brand_catalogue_product_type_id', $type->id)
            ->where('name', $canonical['style'])
            ->first();
    }

    if ($style && $apply) {
        $updates = [
            'brand_catalogue_product_type_id' => $type->id,
            'name' => $canonical['style'],
            'material_name' => $canonical['material'],
            'note' => sm_clean(($style->note ?? '').' Normalised to Smart V2 shop-floor family: '.$canonical['reason']),
            'updated_at' => now(),
        ];

        if ((int) $style->brand_catalogue_product_type_id !== (int) $type->id || $style->name !== $canonical['style']) {
            $updates['slug'] = sm_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style'], (int) $style->id);
            $stats['styles_moved_or_renamed']++;
        }

        DB::table('brand_catalogue_styles')->where('id', $style->id)->update($updates);
        $style = DB::table('brand_catalogue_styles')->where('id', $style->id)->first();
    }

    if (! $style && $apply) {
        $id = DB::table('brand_catalogue_styles')->insertGetId([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_material_id' => null,
            'brand_catalogue_product_type_id' => $type->id,
            'material_name' => $canonical['material'],
            'name' => $canonical['style'],
            'slug' => sm_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style']),
            'note' => 'Created from Smart V2 shop-floor evidence. '.$canonical['reason'],
            'url' => '',
            'is_active' => true,
            'sort_order' => 5000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $style = DB::table('brand_catalogue_styles')->where('id', $id)->first();
        $stats['styles_created']++;
    }

    return $style ?? (object) [
        'id' => $canonical['source_style_id'] ?: 0,
        'name' => $canonical['style'],
        'brand_catalogue_product_type_id' => $type->id,
    ];
}

function sm_find_existing_variant(object $style, string $axis): ?object
{
    $aliases = match ($axis) {
        'Bundle' => ['Bundle', 'Pack', 'Pack count'],
        'Colour' => ['Colour', 'Color'],
        default => [$axis],
    };

    return DB::table('brand_catalogue_variants')
        ->where('brand_catalogue_style_id', $style->id)
        ->whereIn('name', $aliases)
        ->orderByRaw(
            'CASE name '.collect($aliases)
                ->values()
                ->map(fn (string $name, int $index): string => "WHEN '".str_replace("'", "''", $name)."' THEN {$index}")
                ->implode(' ')
                .' ELSE 99 END'
        )
        ->first();
}

function sm_ensure_variant(object $style, string $axis, bool $apply, array &$stats): object
{
    $axis = sm_axis($axis);
    $variant = sm_find_existing_variant($style, $axis);

    if ($variant && $apply) {
        DB::table('brand_catalogue_variants')->where('id', $variant->id)->update([
            'name' => $axis,
            'variant_type' => sm_variant_type($axis),
            'sort_order' => sm_axis_sort_order($axis),
            'updated_at' => now(),
        ]);
        $variant = DB::table('brand_catalogue_variants')->where('id', $variant->id)->first();
    }

    if (! $variant && $apply) {
        $id = DB::table('brand_catalogue_variants')->insertGetId([
            'brand_catalogue_style_id' => $style->id,
            'name' => $axis,
            'variant_type' => sm_variant_type($axis),
            'sort_order' => sm_axis_sort_order($axis),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $variant = DB::table('brand_catalogue_variants')->where('id', $id)->first();
        $stats['variants_created']++;
    }

    return $variant ?? (object) ['id' => 0, 'name' => $axis];
}

function sm_ensure_option(object $variant, string $value, bool $apply, array &$stats): object
{
    $axis = sm_axis($variant->name);
    $value = sm_variant_value($value, $axis);
    $label = sm_option_label($axis, $value);
    $key = sm_option_key($axis, $value);

    $matches = DB::table('brand_catalogue_variant_options')
        ->where('variant_id', $variant->id)
        ->get()
        ->filter(fn (object $option): bool => sm_option_key($axis, $option->value ?: $option->label) === $key)
        ->values();

    $option = $matches->first();

    if ($option && $apply) {
        DB::table('brand_catalogue_variant_options')->where('id', $option->id)->update([
            'label' => $label,
            'value' => $value,
            'updated_at' => now(),
        ]);

        foreach ($matches->slice(1) as $duplicate) {
            DB::table('brand_catalogue_sku_variant_options')
                ->where('brand_catalogue_variant_option_id', $duplicate->id)
                ->update(['brand_catalogue_variant_option_id' => $option->id]);
            DB::table('brand_catalogue_variant_options')->where('id', $duplicate->id)->delete();
        }

        $option = DB::table('brand_catalogue_variant_options')->where('id', $option->id)->first();
    }

    if (! $option && $apply) {
        $id = DB::table('brand_catalogue_variant_options')->insertGetId([
            'variant_id' => $variant->id,
            'label' => $label,
            'value' => $value,
            'sort_order' => DB::table('brand_catalogue_variant_options')->where('variant_id', $variant->id)->count() * 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $option = DB::table('brand_catalogue_variant_options')->where('id', $id)->first();
        $stats['options_created']++;
    }

    return $option ?? (object) ['id' => 0, 'variant_id' => $variant->id, 'label' => $label, 'value' => $value];
}

function sm_existing_sku_by_options(int $styleId, array $options): ?object
{
    $target = collect($options)->pluck('id')->sort()->values()->implode('|');
    foreach (DB::table('brand_catalogue_skus')->where('brand_catalogue_style_id', $styleId)->get() as $sku) {
        $actual = DB::table('brand_catalogue_sku_variant_options')
            ->where('brand_catalogue_sku_id', $sku->id)
            ->pluck('brand_catalogue_variant_option_id')
            ->sort()
            ->values()
            ->implode('|');
        if ($actual === $target) {
            return $sku;
        }
    }

    return null;
}

function sm_delete_style_tree(int $styleId): void
{
    $skuIds = DB::table('brand_catalogue_skus')->where('brand_catalogue_style_id', $styleId)->pluck('id');
    $variantIds = DB::table('brand_catalogue_variants')->where('brand_catalogue_style_id', $styleId)->pluck('id');
    DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_sku_id', $skuIds)->delete();
    DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_variant_id', $variantIds)->delete();
    DB::table('brand_catalogue_skus')->whereIn('id', $skuIds)->delete();
    DB::table('brand_catalogue_variant_options')->whereIn('variant_id', $variantIds)->delete();
    DB::table('brand_catalogue_variants')->whereIn('id', $variantIds)->delete();
    DB::table('brand_catalogue_styles')->where('id', $styleId)->delete();
}

function sm_delete_weak_placeholder_styles(bool $apply, array &$stats): void
{
    foreach ([4163, 4167, 4182] as $styleId) {
        $style = DB::table('brand_catalogue_styles')->where('id', $styleId)->first();
        if (! $style) {
            continue;
        }

        $skuIds = DB::table('brand_catalogue_skus')->where('brand_catalogue_style_id', $styleId)->pluck('id');
        $hasProduct = DB::table('product_families')->where('brand_catalogue_style_id', $styleId)->exists()
            || DB::table('products')->whereIn('brand_catalogue_sku_id', $skuIds)->exists();
        $hasImage = DB::table('catalogue_images')
            ->where(function ($query) use ($styleId, $skuIds) {
                $query->where(function ($styleQuery) use ($styleId) {
                    $styleQuery->where('imageable_type', 'App\\Models\\BrandCatalogueStyle')
                        ->where('imageable_id', $styleId);
                })->orWhere(function ($skuQuery) use ($skuIds) {
                    $skuQuery->where('imageable_type', 'App\\Models\\BrandCatalogueSku')
                        ->whereIn('imageable_id', $skuIds);
                });
            })
            ->exists();

        if ($hasProduct || $hasImage) {
            continue;
        }

        $stats['weak_placeholder_styles_removed']++;

        if ($apply) {
            sm_delete_style_tree($styleId);
        }
    }
}

function sm_canonical_type_for(string $lineName, string $typeName, string $styleName): string
{
    $line = sm_norm($lineName);
    $type = sm_norm($typeName);
    $style = sm_norm($styleName);

    if (in_array($line, ['smartbraid', 'xsmart'], true)) {
        return 'Braid';
    }
    if ($line === 'vivitress') {
        return str_contains($style, 'bulk') ? 'Bulk Hair' : 'Crochet Braid';
    }
    if ($line === 'smartcrochetbulk') {
        return str_contains($style, 'bulk') ? 'Bulk Hair' : 'Crochet Braid';
    }
    if ($line === 'bohocollection') {
        return str_contains($type, 'bulk') || str_contains($style, 'bulk') ? 'Bulk Hair' : 'Crochet Braid';
    }
    if (in_array($line, ['remychaser', 'naturalbundleweave', 'softcrush'], true)) {
        return 'Weave';
    }
    if ($line === 'remychaserclip') {
        return 'Clip-in Extensions';
    }
    if (in_array($line, ['sonatural', 'fashionwigs', 'lacefrontwigs', 'glamlacewigs'], true)) {
        return 'Wig';
    }
    if ($line === 'glamlaceponytails') {
        return 'Ponytail';
    }

    return $typeName;
}

function sm_normalise_all_product_types(object $brand, bool $apply, array &$stats, array &$lineCache, array &$typeCache): void
{
    $styles = DB::table('brand_catalogue_styles as s')
        ->join('brand_catalogue_product_types as pt', 'pt.id', '=', 's.brand_catalogue_product_type_id')
        ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
        ->where('s.brand_catalogue_brand_id', $brand->id)
        ->orderBy('s.id')
        ->get([
            's.id',
            's.name as style_name',
            's.material_name',
            's.brand_catalogue_product_type_id',
            'pt.name as type_name',
            'l.name as line_name',
        ]);

    foreach ($styles as $style) {
        $canonicalTypeName = sm_canonical_type_for($style->line_name, $style->type_name, $style->style_name);
        if ($canonicalTypeName === $style->type_name) {
            continue;
        }

        $line = sm_ensure_line($brand, [$style->line_name], $apply, $stats, $lineCache);
        $type = sm_ensure_type($brand, $line, $canonicalTypeName, $apply, $stats, $typeCache);
        $stats['styles_moved_or_renamed']++;

        if ($apply) {
            DB::table('brand_catalogue_styles')->where('id', $style->id)->update([
                'brand_catalogue_product_type_id' => $type->id,
                'slug' => sm_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $style->style_name, (int) $style->id),
                'updated_at' => now(),
            ]);
        }
    }
}

function sm_align_product_families_to_current_styles(object $brand, bool $apply, array &$stats): void
{
    $families = DB::table('product_families as pf')
        ->join('brand_catalogue_styles as s', 's.id', '=', 'pf.brand_catalogue_style_id')
        ->join('brand_catalogue_product_types as pt', 'pt.id', '=', 's.brand_catalogue_product_type_id')
        ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
        ->where('s.brand_catalogue_brand_id', $brand->id)
        ->where(function ($query) use ($brand) {
            $query->where('pf.brand_catalogue_brand_id', $brand->id)
                ->orWhereIn('pf.brand_name', ['Smart', 'Vivitress', 'Remy Chaser', 'Smart Braid', 'X-Smart']);
        })
        ->get([
            'pf.id',
            'pf.brand_catalogue_line_id as family_line_id',
            'pf.brand_catalogue_product_type_id as family_type_id',
            'pf.line_name as family_line_name',
            'pf.product_type_name as family_type_name',
            'l.id as current_line_id',
            'l.name as current_line_name',
            'pt.id as current_type_id',
            'pt.name as current_type_name',
        ]);

    foreach ($families as $family) {
        $needsUpdate = (int) $family->family_line_id !== (int) $family->current_line_id
            || (int) $family->family_type_id !== (int) $family->current_type_id
            || (string) $family->family_line_name !== (string) $family->current_line_name
            || (string) $family->family_type_name !== (string) $family->current_type_name;

        if (! $needsUpdate) {
            continue;
        }

        $stats['product_families_realigned']++;

        if ($apply) {
            DB::table('product_families')->where('id', $family->id)->update([
                'brand_catalogue_line_id' => $family->current_line_id,
                'brand_catalogue_product_type_id' => $family->current_type_id,
                'line_name' => $family->current_line_name,
                'product_type_name' => $family->current_type_name,
                'updated_at' => now(),
            ]);
        }
    }
}

function sm_delete_empty_buckets(object $brand, bool $apply, array &$stats): void
{
    foreach (DB::table('brand_catalogue_product_types')->where('brand_catalogue_brand_id', $brand->id)->orderByDesc('id')->get() as $type) {
        $inUse = DB::table('brand_catalogue_styles')->where('brand_catalogue_product_type_id', $type->id)->exists()
            || DB::table('brand_catalogue_materials')->where('brand_catalogue_product_type_id', $type->id)->exists()
            || DB::table('hair_extension_intakes')->where('brand_catalogue_product_type_id', $type->id)->exists()
            || DB::table('product_families')->where('brand_catalogue_product_type_id', $type->id)->exists();

        if (! $inUse) {
            $stats['empty_product_types_deleted']++;
            if ($apply) {
                DB::table('brand_catalogue_product_types')->where('id', $type->id)->delete();
            }
        }
    }

    foreach (DB::table('brand_catalogue_lines')->where('brand_catalogue_brand_id', $brand->id)->orderByDesc('id')->get() as $line) {
        $inUse = DB::table('brand_catalogue_product_types')->where('brand_catalogue_line_id', $line->id)->exists()
            || DB::table('product_families')->where('brand_catalogue_line_id', $line->id)->exists();

        if (! $inUse) {
            $stats['empty_lines_deleted']++;
            if ($apply) {
                DB::table('brand_catalogue_lines')->where('id', $line->id)->delete();
            }
        }
    }
}

function sm_remove_duplicate_vivitress_brand(?object $duplicate, bool $apply, array &$stats): void
{
    if (! $duplicate) {
        return;
    }

    $refs = DB::table('brand_catalogue_lines')->where('brand_catalogue_brand_id', $duplicate->id)->count()
        + DB::table('brand_catalogue_product_types')->where('brand_catalogue_brand_id', $duplicate->id)->count()
        + DB::table('brand_catalogue_styles')->where('brand_catalogue_brand_id', $duplicate->id)->count()
        + DB::table('hair_extension_intakes')->where('brand_catalogue_brand_id', $duplicate->id)->count()
        + DB::table('product_families')->where('brand_catalogue_brand_id', $duplicate->id)->count();

    if ($refs > 0) {
        return;
    }

    $stats['duplicate_brands_removed']++;
    if ($apply) {
        DB::table('brand_catalogue_brands')->where('id', $duplicate->id)->delete();
    }
}

$brand = DB::table('brand_catalogue_brands')
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Smart')
    ->first();

if (! $brand) {
    throw new RuntimeException('Smart brand was not found in brand catalogue 1.');
}

$duplicateVivitress = DB::table('brand_catalogue_brands')
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Vivitress')
    ->first();

$intakes = DB::table('hair_extension_intakes')
    ->where('status', 'submitted')
    ->where(function ($query) use ($brand, $duplicateVivitress) {
        $query->whereIn('brand_name', ['Smart', 'Vivitress', 'Remy Chaser', 'Smart Braid', 'X-Smart'])
            ->orWhere('brand_catalogue_brand_id', $brand->id);

        if ($duplicateVivitress) {
            $query->orWhere('brand_catalogue_brand_id', $duplicateVivitress->id);
        }
    })
    ->orderBy('id')
    ->get();

$families = [];
foreach ($intakes as $intake) {
    $canonical = sm_canonical_for($intake);
    $key = sm_family_key($canonical);
    $families[$key] ??= [
        'canonical' => $canonical,
        'intakes' => [],
        'sku_rows' => [],
    ];
    $families[$key]['intakes'][] = $intake;

    foreach (sm_sku_rows($intake, $canonical) as $row) {
        $rowKey = collect($row)
            ->map(fn (array $part): string => sm_norm($part['axis']).'='.sm_norm($part['value']))
            ->implode('|');
        $families[$key]['sku_rows'][$rowKey] = $row;
    }
}

foreach ($families as &$family) {
    if ($family['sku_rows'] === []) {
        $family['sku_rows']['single=singleproduct'] = [['axis' => 'Single', 'value' => 'Single product']];
    }
}
unset($family);

$stats = [
    'families' => count($families),
    'submitted_intakes' => $intakes->count(),
    'intakes_linked' => 0,
    'lines_created' => 0,
    'product_types_created' => 0,
    'styles_created' => 0,
    'styles_moved_or_renamed' => 0,
    'variants_created' => 0,
    'options_created' => 0,
    'skus_created' => 0,
    'skus_updated' => 0,
    'weak_placeholder_styles_removed' => 0,
    'product_families_realigned' => 0,
    'duplicate_brands_removed' => 0,
    'empty_product_types_deleted' => 0,
    'empty_lines_deleted' => 0,
    'backup' => null,
];

$rows = [];
if ($apply) {
    $stats['backup'] = sm_backup($brand, $duplicateVivitress, $timestamp);
}

DB::transaction(function () use ($apply, $brand, $duplicateVivitress, &$families, &$stats, &$rows): void {
    $lineCache = [];
    $typeCache = [];

    sm_delete_weak_placeholder_styles($apply, $stats);
    sm_normalise_all_product_types($brand, $apply, $stats, $lineCache, $typeCache);

    foreach ($families as $family) {
        $canonical = $family['canonical'];
        $line = sm_ensure_line($brand, $canonical['path'], $apply, $stats, $lineCache);
        $type = sm_ensure_type($brand, $line, $canonical['type'], $apply, $stats, $typeCache);
        $style = sm_ensure_style($brand, $type, $family, $apply, $stats);

        $createdSkuCountBefore = $stats['skus_created'];
        $createdOptionCountBefore = $stats['options_created'];
        $createdVariantCountBefore = $stats['variants_created'];

        foreach ($family['sku_rows'] as $skuRow) {
            $options = [];
            foreach ($skuRow as $part) {
                if (sm_clean($part['value']) === '') {
                    continue;
                }
                $variant = sm_ensure_variant($style, $part['axis'], $apply, $stats);
                $options[] = sm_ensure_option($variant, $part['value'], $apply, $stats);
            }

            if ($options === [] || ! $apply) {
                continue;
            }

            $options = collect($options)
                ->unique(fn (object $option): int => (int) $option->variant_id)
                ->values()
                ->all();
            $signature = sm_signature($skuRow);
            $skuName = sm_sku_name($canonical, $skuRow);
            $sku = sm_existing_sku_by_options((int) $style->id, $options)
                ?: DB::table('brand_catalogue_skus')
                    ->where('brand_catalogue_style_id', $style->id)
                    ->where('option_signature', $signature)
                    ->first();

            if ($sku) {
                DB::table('brand_catalogue_skus')->where('id', $sku->id)->update([
                    'name' => $skuName,
                    'slug' => sm_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $style->id, $skuName, (int) $sku->id),
                    'option_signature' => $signature,
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
                $stats['skus_updated']++;
            } else {
                $skuId = DB::table('brand_catalogue_skus')->insertGetId([
                    'brand_catalogue_style_id' => $style->id,
                    'name' => $skuName,
                    'slug' => sm_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $style->id, $skuName),
                    'sku_code' => '',
                    'barcode' => '',
                    'option_signature' => $signature,
                    'description' => 'Observed in Smart V2 shop-floor intake.',
                    'note' => 'Observed in Smart V2 shop-floor intake.',
                    'url' => '',
                    'is_active' => true,
                    'sort_order' => DB::table('brand_catalogue_skus')->where('brand_catalogue_style_id', $style->id)->count() + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $sku = DB::table('brand_catalogue_skus')->where('id', $skuId)->first();
                $stats['skus_created']++;
            }

            DB::table('brand_catalogue_sku_variant_options')->where('brand_catalogue_sku_id', $sku->id)->delete();
            foreach ($options as $option) {
                DB::table('brand_catalogue_sku_variant_options')->insert([
                    'brand_catalogue_sku_id' => $sku->id,
                    'brand_catalogue_variant_id' => $option->variant_id,
                    'brand_catalogue_variant_option_id' => $option->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if ($apply) {
            foreach ($family['intakes'] as $intake) {
                DB::table('hair_extension_intakes')->where('id', $intake->id)->update([
                    'classification_path' => json_encode($canonical['path']),
                    'product_type_name' => $canonical['type'],
                    'style_name' => $canonical['style'],
                    'brand_catalogue_brand_id' => $brand->id,
                    'brand_catalogue_product_type_id' => $type->id,
                    'brand_catalogue_style_id' => $style->id,
                    'catalogue_style_status' => 'known',
                    'product_type_status' => 'known',
                    'style_family_status' => 'known',
                    'last_synced_at' => now(),
                    'updated_at' => now(),
                ]);
                $stats['intakes_linked']++;
            }
        } else {
            $stats['intakes_linked'] += count($family['intakes']);
        }

        $rows[] = [
            'canonical_key' => sm_family_key($canonical),
            'brand' => 'Smart',
            'grouping_path' => sm_path_text($canonical['path']),
            'product_type' => $canonical['type'],
            'style_family' => $canonical['style'],
            'material' => $canonical['material'],
            'source_style_id' => $canonical['source_style_id'] ?: '',
            'intake_ids' => collect($family['intakes'])->pluck('id')->implode(', '),
            'style_id' => $style->id,
            'sku_rows_from_v2' => count($family['sku_rows']),
            'variants_created_for_family' => $stats['variants_created'] - $createdVariantCountBefore,
            'options_created_for_family' => $stats['options_created'] - $createdOptionCountBefore,
            'skus_created_for_family' => $stats['skus_created'] - $createdSkuCountBefore,
            'reason' => $canonical['reason'],
            'applied' => $apply ? 'yes' : 'no',
        ];
    }

    sm_align_product_families_to_current_styles($brand, $apply, $stats);
    sm_remove_duplicate_vivitress_brand($duplicateVivitress, $apply, $stats);
    sm_delete_empty_buckets($brand, $apply, $stats);
});

$csv = fopen($csvPath, 'w');
fputcsv($csv, [
    'canonical_key',
    'brand',
    'grouping_path',
    'product_type',
    'style_family',
    'material',
    'source_style_id',
    'intake_ids',
    'style_id',
    'sku_rows_from_v2',
    'variants_created_for_family',
    'options_created_for_family',
    'skus_created_for_family',
    'reason',
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
    echo "Run with --apply to write these changes.\n";
}
