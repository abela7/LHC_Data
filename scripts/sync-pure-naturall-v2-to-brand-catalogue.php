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

$csvPath = $reportDir."/pure-naturall-v2-catalogue-sync-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/pure-naturall-v2-catalogue-sync-latest.csv';

function pn_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function pn_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function pn_path_text(array $path): string
{
    return implode(' > ', array_filter(array_map('pn_clean', $path)));
}

function pn_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
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

function pn_axis(mixed $axis): string
{
    $key = pn_norm($axis);

    if (str_contains($key, 'lengthset')) {
        return 'Length Set';
    }
    if (str_contains($key, 'length')) {
        return 'Length';
    }
    if (str_contains($key, 'colour') || str_contains($key, 'color')) {
        return 'Colour';
    }
    if (str_contains($key, 'pack') || str_contains($key, 'bundle') || str_contains($key, 'count') || str_contains($key, 'piece')) {
        return 'Bundle';
    }

    return pn_clean($axis) ?: 'Variant';
}

function pn_variant_type(string $axis): string
{
    return match ($axis) {
        'Length', 'Length Set' => 'measurement',
        'Colour' => 'colour_code',
        'Bundle' => 'count',
        default => 'text',
    };
}

function pn_axis_sort_order(string $axis): int
{
    return match ($axis) {
        'Length Set' => 10,
        'Length' => 15,
        'Colour' => 20,
        'Bundle' => 30,
        default => 90,
    };
}

function pn_length_value(mixed $value): string
{
    $value = str_replace(['Ã¢â‚¬Å“', 'Ã¢â‚¬Â', 'Ã¢â‚¬Â³'], '"', pn_clean($value));
    $value = preg_replace('/^(\d+)\s*(?:\"|in|inch|inches)$/i', '$1', $value) ?? $value;
    $value = preg_replace('/^(\d+)\s+INCHES$/i', '$1', $value) ?? $value;

    return pn_clean($value);
}

function pn_bundle_value(mixed $value): string
{
    $value = pn_clean($value);

    if (preg_match('/^(\d+)\s*(?:x|pcs?|pieces?)$/i', $value, $match)) {
        return $match[1].'X';
    }

    return Str::upper($value);
}

function pn_variant_value(mixed $value, string $axis = ''): string
{
    $axis = pn_axis($axis);

    return match ($axis) {
        'Length', 'Length Set' => pn_length_value($value),
        'Bundle' => pn_bundle_value($value),
        'Colour' => Str::upper(pn_clean($value)),
        default => pn_clean($value),
    };
}

function pn_option_label(string $axis, string $value): string
{
    return match ($axis) {
        'Length' => $value.'"',
        'Length Set' => $value.'"',
        default => $value,
    };
}

function pn_option_key(string $axis, mixed $value): string
{
    return pn_norm(pn_variant_value($value, $axis));
}

function pn_is_non_sellable_value(string $value): bool
{
    return in_array(pn_norm($value), ['unspecified', 'unknown', 'notknown', 'na', 'none'], true);
}

function pn_canonical_for(object $intake): array
{
    return match ((int) $intake->id) {
        79 => [
            'path' => ['Bulk'],
            'type' => 'Bulk Hair',
            'style' => 'Water Wave',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor V2 intake identifies Pure NaturALL Bulk Water Wave 24 inch with colour variants; kept separate from weave Water Wave.',
        ],
        80 => [
            'path' => ['Bulk'],
            'type' => 'Bulk Hair',
            'style' => 'Loose Deep Wave',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor V2 intake identifies Bulk Loose Deep Wave 24 inch; no exact imported source style exists.',
        ],
        81 => [
            'path' => ['Bulk'],
            'type' => 'Bulk Hair',
            'style' => 'Deep Wave',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor V2 intake identifies Bulk Deep Wave 24 inch; no exact imported source style exists.',
        ],
        240, 253 => [
            'path' => ['Hair Weaving'],
            'type' => 'Weave',
            'style' => 'Bohemian Curl',
            'material' => 'Synthetic Hair',
            'source_style_id' => 88,
            'reason' => 'Shop-floor V2 intake and QH Beauty/Mamado source both identify NaturALL Bohemian Curl weave; product type is operationally Weave.',
        ],
        254, 255 => [
            'path' => ['Hair Weaving'],
            'type' => 'Weave',
            'style' => 'Yaky Straight (Silky)',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor V2 intake identifies Yaky Straight (Silky) weave; no exact imported source style exists.',
        ],
        256 => [
            'path' => ['Hair Weaving'],
            'type' => 'Weave',
            'style' => 'Deep Curl',
            'material' => 'Synthetic Hair',
            'source_style_id' => 89,
            'reason' => 'Shop-floor V2 intake matches imported NaturALL Deep Curl weave family.',
        ],
        257 => [
            'path' => ['Hair Weaving'],
            'type' => 'Weave',
            'style' => 'Beach Curl',
            'material' => 'Synthetic Hair',
            'source_style_id' => 87,
            'reason' => 'Shop-floor V2 intake matches imported NaturALL Beach Curl weave family.',
        ],
        258 => [
            'path' => ['Hair Weaving'],
            'type' => 'Weave',
            'style' => 'Malibu Curl',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor V2 intake identifies Malibu Curl weave; no exact imported source style exists.',
        ],
        263 => [
            'path' => ['Hair Weaving'],
            'type' => 'Weave',
            'style' => 'Water Wave',
            'material' => 'Synthetic Hair',
            'source_style_id' => 91,
            'reason' => 'Shop-floor V2 intake matches imported NaturALL Water Wave weave family.',
        ],
        default => [
            'path' => array_values(array_filter(json_decode((string) $intake->classification_path, true) ?: [])) ?: ['Pure NaturALL'],
            'type' => pn_clean($intake->product_type_name) ?: 'Hair Extension',
            'style' => pn_clean($intake->style_name ?: $intake->observed_product_name) ?: 'Review style',
            'material' => 'Review material',
            'source_style_id' => $intake->brand_catalogue_style_id ?: null,
            'reason' => 'Fallback mapping from submitted Pure NaturALL V2 intake.',
        ],
    };
}

function pn_family_key(array $canonical): string
{
    return implode('|', [
        pn_norm(pn_path_text($canonical['path'])),
        pn_norm($canonical['type']),
        pn_norm($canonical['style']),
    ]);
}

function pn_common_values(array $structure): array
{
    $values = [];
    foreach (($structure['common_variants'] ?? []) as $variant) {
        $axis = pn_axis($variant['name'] ?? '');
        foreach (($variant['values'] ?? []) as $value) {
            $value = pn_variant_value($value, $axis);
            if ($value !== '' && ! pn_is_non_sellable_value($value)) {
                $values[$axis][$value] = $value;
            }
        }
    }

    return $values;
}

function pn_sku_rows(object $intake, array $canonical): array
{
    $structure = json_decode((string) $intake->variant_structure, true) ?: [];

    if ($canonical['type'] === 'Weave') {
        $groups = $structure['groups'] ?? [];
        $lengths = [];
        $colours = [];
        foreach ($groups as $group) {
            $length = pn_variant_value($group['main_value'] ?? '', 'Length');
            if ($length !== '') {
                $lengths[$length] = $length;
            }
            foreach (($group['sub_values'] ?? []) as $colour) {
                $colour = pn_variant_value($colour, $group['sub_axis'] ?? 'Colour');
                if ($colour !== '' && ! pn_is_non_sellable_value($colour)) {
                    $colours[$colour] = $colour;
                }
            }
        }

        $lengthValues = array_values($lengths);
        $lengthAxis = count($lengthValues) > 1 ? 'Length Set' : 'Length';
        $lengthValue = implode('/', $lengthValues);
        $bundles = array_values(pn_common_values($structure)['Bundle'] ?? []);
        $bundles = $bundles === [] ? [null] : $bundles;
        $colours = array_values($colours);
        $colours = $colours === [] ? [null] : $colours;
        $rows = [];

        foreach ($bundles as $bundle) {
            foreach ($colours as $colour) {
                $row = [];
                if ($lengthValue !== '') {
                    $row[] = ['axis' => $lengthAxis, 'value' => $lengthValue];
                }
                if ($colour !== null) {
                    $row[] = ['axis' => 'Colour', 'value' => $colour];
                }
                if ($bundle !== null) {
                    $row[] = ['axis' => 'Bundle', 'value' => $bundle];
                }
                if ($row !== []) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    $rows = [];
    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $row = [];
        $mainAxis = pn_axis($sku['main_axis'] ?? $structure['main_axis'] ?? 'Main');
        $main = pn_variant_value($sku['main_value'] ?? '', $mainAxis);
        if ($main !== '' && ! pn_is_non_sellable_value($main)) {
            $row[] = ['axis' => $mainAxis, 'value' => $main];
        }

        $subAxis = pn_axis($sku['sub_axis'] ?? 'Colour');
        $sub = pn_variant_value($sku['sub_value'] ?? '', $subAxis);
        if ($sub !== '' && ! pn_is_non_sellable_value($sub)) {
            $row[] = ['axis' => $subAxis, 'value' => $sub];
        }

        foreach (($sku['common_attributes'] ?? []) as $axis => $values) {
            $axis = pn_axis($axis);
            foreach ((array) $values as $value) {
                $value = pn_variant_value($value, $axis);
                if ($value !== '' && ! pn_is_non_sellable_value($value)) {
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

function pn_sku_name(array $canonical, array $row): string
{
    $prefix = $canonical['type'] === 'Bulk Hair' ? 'Pure NaturALL Bulk' : 'Pure NaturALL Weave';
    $name = $prefix.' '.$canonical['style'];
    $colour = null;
    $bundle = null;
    $length = null;

    foreach ($row as $part) {
        if ($part['axis'] === 'Bundle') {
            $bundle = $part['value'];
        } elseif ($part['axis'] === 'Length' || $part['axis'] === 'Length Set') {
            $length = $part['value'].'"';
        } elseif ($part['axis'] === 'Colour') {
            $colour = $part['value'];
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

    return pn_clean($name);
}

function pn_signature(array $row): string
{
    return collect($row)
        ->map(fn (array $part): string => $part['axis'].':'.$part['value'])
        ->implode('|');
}

function pn_backup(object $brand, string $timestamp): string
{
    $styleIds = DB::table('brand_catalogue_styles')->where('brand_catalogue_brand_id', $brand->id)->pluck('id');
    $variantIds = DB::table('brand_catalogue_variants')->whereIn('brand_catalogue_style_id', $styleIds)->pluck('id');
    $skuIds = DB::table('brand_catalogue_skus')->whereIn('brand_catalogue_style_id', $styleIds)->pluck('id');

    $backup = [
        'brand' => $brand,
        'lines' => DB::table('brand_catalogue_lines')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'product_types' => DB::table('brand_catalogue_product_types')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'styles' => DB::table('brand_catalogue_styles')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'variants' => DB::table('brand_catalogue_variants')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'variant_options' => DB::table('brand_catalogue_variant_options')->whereIn('variant_id', $variantIds)->get(),
        'skus' => DB::table('brand_catalogue_skus')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'sku_variant_options' => DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_sku_id', $skuIds)->get(),
        'intakes' => DB::table('hair_extension_intakes')
            ->where('brand_name', 'Pure NaturALL')
            ->orWhere('brand_catalogue_brand_id', $brand->id)
            ->get(),
        'product_families' => DB::table('product_families')
            ->where('brand_catalogue_brand_id', $brand->id)
            ->orWhere('brand_name', 'Pure NaturALL')
            ->get(),
    ];

    $path = "catalogue-backups/pure-naturall-v2-sync-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function pn_ensure_line(object $brand, array $path, bool $apply, array &$stats, array &$cache): object
{
    $name = pn_path_text($path) ?: 'Pure NaturALL';
    $key = pn_norm($name);
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
            'slug' => pn_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $name),
            'note' => 'Created from Pure NaturALL V2 shop-floor intake structure.',
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

function pn_ensure_type(object $brand, object $line, string $name, bool $apply, array &$stats, array &$cache): object
{
    $key = $line->id.'|'.pn_norm($name);
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
            'slug' => pn_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', (int) $line->id, $name),
            'note' => 'Created from Pure NaturALL V2 shop-floor intake structure.',
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

function pn_ensure_style(object $brand, object $type, array $family, bool $apply, array &$stats): object
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
            'note' => pn_clean(($style->note ?? '').' Normalised to Pure NaturALL V2 shop-floor family: '.$canonical['reason']),
            'updated_at' => now(),
        ];

        if ((int) $style->brand_catalogue_product_type_id !== (int) $type->id || $style->name !== $canonical['style']) {
            $updates['slug'] = pn_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style'], (int) $style->id);
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
            'slug' => pn_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style']),
            'note' => 'Created from Pure NaturALL V2 shop-floor evidence. '.$canonical['reason'],
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

function pn_find_existing_variant(object $style, string $axis): ?object
{
    $aliases = match ($axis) {
        'Bundle' => ['Bundle', 'Pack'],
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

function pn_ensure_variant(object $style, string $axis, bool $apply, array &$stats): object
{
    $axis = pn_axis($axis);
    $variant = pn_find_existing_variant($style, $axis);

    if ($variant && $apply) {
        DB::table('brand_catalogue_variants')->where('id', $variant->id)->update([
            'name' => $axis,
            'variant_type' => pn_variant_type($axis),
            'sort_order' => pn_axis_sort_order($axis),
            'updated_at' => now(),
        ]);
        $variant = DB::table('brand_catalogue_variants')->where('id', $variant->id)->first();
    }

    if (! $variant && $apply) {
        $id = DB::table('brand_catalogue_variants')->insertGetId([
            'brand_catalogue_style_id' => $style->id,
            'name' => $axis,
            'variant_type' => pn_variant_type($axis),
            'sort_order' => pn_axis_sort_order($axis),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $variant = DB::table('brand_catalogue_variants')->where('id', $id)->first();
        $stats['variants_created']++;
    }

    return $variant ?? (object) ['id' => 0, 'name' => $axis];
}

function pn_ensure_option(object $variant, string $value, bool $apply, array &$stats): object
{
    $axis = pn_axis($variant->name);
    $value = pn_variant_value($value, $axis);
    $label = pn_option_label($axis, $value);
    $key = pn_option_key($axis, $value);

    $matches = DB::table('brand_catalogue_variant_options')
        ->where('variant_id', $variant->id)
        ->get()
        ->filter(fn (object $option): bool => pn_option_key($axis, $option->value ?: $option->label) === $key)
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

function pn_existing_sku_by_options(int $styleId, array $options): ?object
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

function pn_rebuild_sku_signature_from_options(object $sku): string
{
    $parts = DB::table('brand_catalogue_sku_variant_options as svo')
        ->join('brand_catalogue_variants as v', 'v.id', '=', 'svo.brand_catalogue_variant_id')
        ->join('brand_catalogue_variant_options as vo', 'vo.id', '=', 'svo.brand_catalogue_variant_option_id')
        ->where('svo.brand_catalogue_sku_id', $sku->id)
        ->orderBy('v.sort_order')
        ->orderBy('v.id')
        ->get(['v.name as axis', 'vo.value', 'vo.label'])
        ->map(fn (object $row): string => pn_axis($row->axis).':'.pn_variant_value($row->value ?: $row->label, $row->axis))
        ->all();

    return implode('|', $parts);
}

function pn_delete_fake_bohemian_closure_sku(bool $apply, array &$stats): void
{
    $sku = DB::table('brand_catalogue_skus')->where('id', 20705)->first();
    if (! $sku) {
        return;
    }

    $hasProduct = DB::table('products')->where('brand_catalogue_sku_id', $sku->id)->exists();
    $hasImage = DB::table('catalogue_images')
        ->where('imageable_type', 'App\\Models\\BrandCatalogueSku')
        ->where('imageable_id', $sku->id)
        ->exists();

    if ($hasProduct || $hasImage) {
        return;
    }

    $stats['weak_placeholder_skus_removed']++;

    if (! $apply) {
        return;
    }

    DB::table('brand_catalogue_sku_variant_options')->where('brand_catalogue_sku_id', $sku->id)->delete();
    DB::table('brand_catalogue_skus')->where('id', $sku->id)->delete();

    $variant = DB::table('brand_catalogue_variants')->where('id', 7615)->first();
    if ($variant && ! DB::table('brand_catalogue_sku_variant_options')->where('brand_catalogue_variant_id', $variant->id)->exists()) {
        $optionIds = DB::table('brand_catalogue_variant_options')->where('variant_id', $variant->id)->pluck('id');
        DB::table('brand_catalogue_variant_options')->whereIn('id', $optionIds)->delete();
        DB::table('brand_catalogue_variants')->where('id', $variant->id)->delete();
    }
}

function pn_normalise_imported_weave_styles(object $brand, object $type, bool $apply, array &$stats): void
{
    foreach ([87, 88, 89, 90, 91] as $styleId) {
        $style = DB::table('brand_catalogue_styles')->where('id', $styleId)->where('brand_catalogue_brand_id', $brand->id)->first();
        if (! $style) {
            continue;
        }

        if ($apply && (int) $style->brand_catalogue_product_type_id !== (int) $type->id) {
            DB::table('brand_catalogue_styles')->where('id', $style->id)->update([
                'brand_catalogue_product_type_id' => $type->id,
                'material_name' => 'Synthetic Hair',
                'slug' => pn_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $style->name, (int) $style->id),
                'note' => pn_clean(($style->note ?? '').' Normalised under Hair Weaving > Weave for Pure NaturALL.'),
                'updated_at' => now(),
            ]);
            $stats['styles_moved_or_renamed']++;
            $style = DB::table('brand_catalogue_styles')->where('id', $style->id)->first();
        }

        if (! $apply) {
            continue;
        }

        foreach (DB::table('brand_catalogue_variants')->where('brand_catalogue_style_id', $style->id)->get() as $variant) {
            $axis = pn_axis($variant->name);
            DB::table('brand_catalogue_variants')->where('id', $variant->id)->update([
                'name' => $axis,
                'variant_type' => pn_variant_type($axis),
                'sort_order' => pn_axis_sort_order($axis),
                'updated_at' => now(),
            ]);

            $variant = DB::table('brand_catalogue_variants')->where('id', $variant->id)->first();
            foreach (DB::table('brand_catalogue_variant_options')->where('variant_id', $variant->id)->get() as $option) {
                $value = pn_variant_value($option->value ?: $option->label, $axis);
                DB::table('brand_catalogue_variant_options')->where('id', $option->id)->update([
                    'label' => pn_option_label($axis, $value),
                    'value' => $value,
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (DB::table('brand_catalogue_skus')->where('brand_catalogue_style_id', $style->id)->get() as $sku) {
            $signature = pn_rebuild_sku_signature_from_options($sku);
            $row = collect(explode('|', $signature))
                ->filter()
                ->map(function (string $part): array {
                    [$axis, $value] = explode(':', $part, 2);
                    return ['axis' => $axis, 'value' => $value];
                })
                ->values()
                ->all();
            $canonical = ['type' => 'Weave', 'style' => $style->name];

            DB::table('brand_catalogue_skus')->where('id', $sku->id)->update([
                'name' => pn_sku_name($canonical, $row),
                'option_signature' => $signature,
                'updated_at' => now(),
            ]);
        }

        $stats['official_styles_normalised']++;
    }
}

function pn_align_product_families_to_current_styles(object $brand, bool $apply, array &$stats): void
{
    $families = DB::table('product_families as pf')
        ->join('brand_catalogue_styles as s', 's.id', '=', 'pf.brand_catalogue_style_id')
        ->join('brand_catalogue_product_types as pt', 'pt.id', '=', 's.brand_catalogue_product_type_id')
        ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
        ->where('s.brand_catalogue_brand_id', $brand->id)
        ->where(function ($query) use ($brand) {
            $query->where('pf.brand_catalogue_brand_id', $brand->id)
                ->orWhere('pf.brand_name', 'Pure NaturALL');
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

        if (! $apply) {
            continue;
        }

        DB::table('product_families')->where('id', $family->id)->update([
            'brand_catalogue_line_id' => $family->current_line_id,
            'brand_catalogue_product_type_id' => $family->current_type_id,
            'line_name' => $family->current_line_name,
            'product_type_name' => $family->current_type_name,
            'updated_at' => now(),
        ]);
    }
}

function pn_delete_empty_buckets(object $brand, bool $apply, array &$stats): void
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

$brand = DB::table('brand_catalogue_brands')
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Pure NaturALL')
    ->first();

if (! $brand) {
    throw new RuntimeException('Pure NaturALL brand was not found in brand catalogue 1.');
}

$intakes = DB::table('hair_extension_intakes')
    ->where('status', 'submitted')
    ->where(function ($query) use ($brand) {
        $query->where('brand_name', 'Pure NaturALL')
            ->orWhere('brand_catalogue_brand_id', $brand->id);
    })
    ->orderBy('id')
    ->get();

$families = [];
foreach ($intakes as $intake) {
    $canonical = pn_canonical_for($intake);
    $key = pn_family_key($canonical);
    $families[$key] ??= [
        'canonical' => $canonical,
        'intakes' => [],
        'sku_rows' => [],
    ];
    $families[$key]['intakes'][] = $intake;

    foreach (pn_sku_rows($intake, $canonical) as $row) {
        $rowKey = collect($row)
            ->map(fn (array $part): string => pn_norm($part['axis']).'='.pn_norm($part['value']))
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
    'official_styles_normalised' => 0,
    'variants_created' => 0,
    'options_created' => 0,
    'skus_created' => 0,
    'skus_updated' => 0,
    'weak_placeholder_skus_removed' => 0,
    'product_families_realigned' => 0,
    'empty_product_types_deleted' => 0,
    'empty_lines_deleted' => 0,
    'backup' => null,
];

$rows = [];
if ($apply) {
    $stats['backup'] = pn_backup($brand, $timestamp);
}

DB::transaction(function () use ($apply, $brand, &$families, &$stats, &$rows): void {
    $lineCache = [];
    $typeCache = [];
    $hairWeavingLine = pn_ensure_line($brand, ['Hair Weaving'], $apply, $stats, $lineCache);
    $weaveType = pn_ensure_type($brand, $hairWeavingLine, 'Weave', $apply, $stats, $typeCache);

    foreach ($families as $family) {
        $canonical = $family['canonical'];
        $line = pn_ensure_line($brand, $canonical['path'], $apply, $stats, $lineCache);
        $type = pn_ensure_type($brand, $line, $canonical['type'], $apply, $stats, $typeCache);
        $style = pn_ensure_style($brand, $type, $family, $apply, $stats);

        $createdSkuCountBefore = $stats['skus_created'];
        $createdOptionCountBefore = $stats['options_created'];
        $createdVariantCountBefore = $stats['variants_created'];

        foreach ($family['sku_rows'] as $skuRow) {
            $options = [];
            foreach ($skuRow as $part) {
                if (pn_clean($part['value']) === '') {
                    continue;
                }
                $variant = pn_ensure_variant($style, $part['axis'], $apply, $stats);
                $options[] = pn_ensure_option($variant, $part['value'], $apply, $stats);
            }

            if ($options === [] || ! $apply) {
                continue;
            }

            $options = collect($options)
                ->unique(fn (object $option): int => (int) $option->variant_id)
                ->values()
                ->all();
            $signature = pn_signature($skuRow);
            $skuName = pn_sku_name($canonical, $skuRow);
            $sku = pn_existing_sku_by_options((int) $style->id, $options)
                ?: DB::table('brand_catalogue_skus')
                    ->where('brand_catalogue_style_id', $style->id)
                    ->where('option_signature', $signature)
                    ->first();

            if ($sku) {
                DB::table('brand_catalogue_skus')->where('id', $sku->id)->update([
                    'name' => $skuName,
                    'slug' => pn_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $style->id, $skuName, (int) $sku->id),
                    'option_signature' => $signature,
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
                $stats['skus_updated']++;
            } else {
                $skuId = DB::table('brand_catalogue_skus')->insertGetId([
                    'brand_catalogue_style_id' => $style->id,
                    'name' => $skuName,
                    'slug' => pn_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $style->id, $skuName),
                    'sku_code' => '',
                    'barcode' => '',
                    'option_signature' => $signature,
                    'description' => 'Observed in Pure NaturALL V2 shop-floor intake.',
                    'note' => 'Observed in Pure NaturALL V2 shop-floor intake.',
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
            'canonical_key' => pn_family_key($canonical),
            'brand' => 'Pure NaturALL',
            'grouping_path' => pn_path_text($canonical['path']),
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

    pn_normalise_imported_weave_styles($brand, $weaveType, $apply, $stats);
    pn_delete_fake_bohemian_closure_sku($apply, $stats);
    pn_align_product_families_to_current_styles($brand, $apply, $stats);
    pn_delete_empty_buckets($brand, $apply, $stats);
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
