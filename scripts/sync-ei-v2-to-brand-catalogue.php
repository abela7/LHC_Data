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

$csvPath = $reportDir."/ei-v2-catalogue-sync-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/ei-v2-catalogue-sync-latest.csv';

function ei_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function ei_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function ei_path_text(array $path): string
{
    return implode(' > ', array_filter(array_map('ei_clean', $path)));
}

function ei_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
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

function ei_variant_axis(mixed $axis): string
{
    $name = ei_clean($axis);
    $key = ei_norm($name);

    if (str_contains($key, 'length')) {
        return 'Length';
    }
    if (str_contains($key, 'colour') || str_contains($key, 'color')) {
        return 'Colour';
    }
    if (str_contains($key, 'pack') || str_contains($key, 'bundle') || str_contains($key, 'count') || str_contains($key, 'piece')) {
        return 'Piece count';
    }

    return $name ?: 'Variant';
}

function ei_variant_type(string $axis): string
{
    $key = ei_norm($axis);

    if (str_contains($key, 'length')) {
        return 'measurement';
    }
    if (str_contains($key, 'colour') || str_contains($key, 'color')) {
        return 'colour_code';
    }
    if (str_contains($key, 'count') || str_contains($key, 'piece') || str_contains($key, 'pack')) {
        return 'count';
    }

    return 'text';
}

function ei_variant_value(mixed $value): string
{
    $value = str_replace(['â€œ', 'â€', 'â€³'], '"', ei_clean($value));
    $value = preg_replace('/#$/', '', $value) ?? $value;
    $value = preg_replace('/^(\d+)\s*(?:\"|in|inch|inches)$/i', '$1 Inch', $value) ?? $value;
    $value = preg_replace('/^(\d+)\s+INCHES$/i', '$1 Inch', $value) ?? $value;

    return ei_clean($value);
}

function ei_is_non_sellable_value(string $value): bool
{
    return in_array(ei_norm($value), ['unspecified', 'unknown', 'notknown', 'na', 'none'], true);
}

function ei_canonical_for(object $intake): array
{
    return match ((int) $intake->id) {
        278 => [
            'path' => ['100% Human Hair', 'Brazilian Hair'],
            'type' => 'Bulk Hair',
            'style' => 'Deep Bulk',
            'material' => 'Human Hair Blend',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies EI Brazilian Deep Bulk as bulk hair; no exact official EI family was imported for this bulk line.',
        ],
        279, 330 => [
            'path' => ['100% Human Hair', 'Brazilian Hair'],
            'type' => 'Weave',
            'style' => 'Deep Wave',
            'material' => '100% Unprocessed Virgin Brazilian Hair',
            'source_style_id' => 633,
            'reason' => 'Shop-floor intake identifies Brazilian Deep Wave; official EI Unprocessed Virgin Brazilian Deep Wave is the matching weave family.',
        ],
        280, 329 => [
            'path' => ['100% Human Hair', 'Brazilian Hair'],
            'type' => 'Weave',
            'style' => 'Straight',
            'material' => '100% Unprocessed Virgin Brazilian Hair',
            'source_style_id' => 631,
            'reason' => 'Shop-floor intake identifies Brazilian Straight; official EI Unprocessed Virgin Brazilian Straight is the matching weave family.',
        ],
        281 => [
            'path' => ['100% Human Hair', 'Brazilian Hair'],
            'type' => 'Weave',
            'style' => 'Body Wave',
            'material' => '100% Unprocessed Virgin Brazilian Hair',
            'source_style_id' => 632,
            'reason' => 'Shop-floor intake identifies Brazilian Body Wave; official EI Unprocessed Virgin Brazilian Body Wave is the matching weave family.',
        ],
        282 => [
            'path' => ['100% Human Hair', 'Brazilian Hair'],
            'type' => 'Weave',
            'style' => 'Jerry Curl',
            'material' => '100% Unprocessed Virgin Brazilian Hair',
            'source_style_id' => 634,
            'reason' => 'Shop-floor intake identifies Brazilian Jerry Curl; official EI Unprocessed Virgin Brazilian Jerry Curl is the matching weave family.',
        ],
        283, 284, 285, 287, 312 => [
            'path' => ['100% Human Hair'],
            'type' => 'Clip-in Extensions',
            'style' => 'Clip-In Human Hair Extensions',
            'material' => '100% Human Hair',
            'source_style_id' => 628,
            'reason' => 'Shop-floor clip-in/clipin entries match the official EI full-head Clip-In Human Hair Extensions family; 7 Piece is kept as a sellable pack-count variant.',
        ],
        289 => [
            'path' => ['100% Human Hair', 'Brazilian Hair'],
            'type' => 'Weave',
            'style' => 'Kinky Straight',
            'material' => '100% Human Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake clearly identifies Brazilian Kinky Straight weave; no exact official EI family was imported, so this is created from V2 evidence.',
        ],
        291 => [
            'path' => ['100% Human Hair'],
            'type' => 'Closure / Frontal',
            'style' => 'Deep Wave 4x4 Middle-Part Closure',
            'material' => '100% Human Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies a Deep Wave closure/frontal and notes 4x4 Middle-Part; no exact official EI closure family was imported.',
        ],
        302, 315 => [
            'path' => ['100% Human Hair', 'European Weave'],
            'type' => 'Weave',
            'style' => 'EW',
            'material' => '100% Human Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies EI EW / European Weave; no exact official EI family was imported, so this is created from V2 evidence.',
        ],
        313 => [
            'path' => ['100% Human Hair'],
            'type' => 'Clip-in Extensions',
            'style' => 'Dulux Seamless Clip-In Extensions 8pcs 200g',
            'material' => '100% Remy Human Hair',
            'source_style_id' => 629,
            'reason' => 'Shop-floor Seamless Clipin Hair matches official EI Dulux Seamless Clip-In Extensions 8pcs 200g.',
        ],
        314 => [
            'path' => ['100% Human Hair'],
            'type' => 'Micro Loop Extensions',
            'style' => 'Micro Loop Remy Hair',
            'material' => '100% Remy Human Hair',
            'source_style_id' => 638,
            'reason' => 'Shop-floor Micro Loop Hair matches official EI Micro Loop Remy Hair.',
        ],
        316, 317 => [
            'path' => ['100% Human Hair'],
            'type' => 'Tape-in Extensions',
            'style' => 'Tape-In Remy Extensions',
            'material' => 'Remy Human Hair',
            'source_style_id' => 639,
            'reason' => 'Shop-floor Tape Hair / Tape In Hair entries match official EI Tape-In Remy Extensions.',
        ],
        318 => [
            'path' => ['100% Human Hair'],
            'type' => 'Nano Ring Extensions',
            'style' => 'Remy Nano Ring Extensions',
            'material' => '100% Remy Human Hair',
            'source_style_id' => 637,
            'reason' => 'Shop-floor Nano Hair matches official EI Remy Nano Ring Extensions.',
        ],
        331 => [
            'path' => ['100% Human Hair', 'Remy Hair'],
            'type' => 'Weave',
            'style' => 'Triple Weft Remy 150g',
            'material' => '100% Remy Human Hair',
            'source_style_id' => 636,
            'reason' => 'Shop-floor Triple Weft Remy with 150 grams evidence matches official EI Triple Weft Remy 150g.',
        ],
        default => [
            'path' => array_values(array_filter(json_decode((string) $intake->classification_path, true) ?: [])) ?: ['EI Hair Extensions'],
            'type' => ei_clean($intake->product_type_name) ?: 'Hair Extension',
            'style' => ei_clean($intake->style_name ?: $intake->observed_product_name) ?: 'Review style',
            'material' => 'Review material',
            'source_style_id' => $intake->brand_catalogue_style_id ?: null,
            'reason' => 'Fallback mapping from submitted EI V2 intake.',
        ],
    };
}

function ei_official_cleanup_mappings(): array
{
    return [
        624 => ['path' => ['100% Human Hair'], 'type' => 'Weave', 'style' => 'Weft Human Hair Extensions', 'material' => '100% Human Hair', 'reason' => 'Official EI weft family normalised so product type is operational Weave, not material/category wording.'],
        625 => ['path' => ['100% Human Hair'], 'type' => 'Weave', 'style' => 'Yaki Human Hair Weave', 'material' => '100% Human Hair', 'reason' => 'Official EI Yaki weave normalised under Weave.'],
        627 => ['path' => ['100% Human Hair'], 'type' => 'Clip-in Extensions', 'style' => 'DIY Hair Extension Set with Clips', 'material' => '100% Human Hair', 'reason' => 'Official EI DIY clip-in set normalised under Clip-in Extensions.'],
        628 => ['path' => ['100% Human Hair'], 'type' => 'Clip-in Extensions', 'style' => 'Clip-In Human Hair Extensions', 'material' => '100% Human Hair', 'reason' => 'Official EI clip-in family normalised under Clip-in Extensions.'],
        629 => ['path' => ['100% Human Hair'], 'type' => 'Clip-in Extensions', 'style' => 'Dulux Seamless Clip-In Extensions 8pcs 200g', 'material' => '100% Remy Human Hair', 'reason' => 'Official EI seamless clip-in family normalised under Clip-in Extensions.'],
        630 => ['path' => ['100% Human Hair'], 'type' => 'Clip-in Extensions', 'style' => 'Chic Extra Volume 10pcs Clip-In Remy Human Hair', 'material' => '100% Remy Human Hair', 'reason' => 'Official E&I Chic clip-in family normalised under Clip-in Extensions.'],
        631 => ['path' => ['100% Human Hair', 'Brazilian Hair'], 'type' => 'Weave', 'style' => 'Straight', 'material' => '100% Unprocessed Virgin Brazilian Hair', 'reason' => 'Brazilian Straight is a weave style; Brazilian Hair is kept as grouping path.'],
        632 => ['path' => ['100% Human Hair', 'Brazilian Hair'], 'type' => 'Weave', 'style' => 'Body Wave', 'material' => '100% Unprocessed Virgin Brazilian Hair', 'reason' => 'Brazilian Body Wave is a weave style; Brazilian Hair is kept as grouping path.'],
        633 => ['path' => ['100% Human Hair', 'Brazilian Hair'], 'type' => 'Weave', 'style' => 'Deep Wave', 'material' => '100% Unprocessed Virgin Brazilian Hair', 'reason' => 'Brazilian Deep Wave is a weave style; Brazilian Hair is kept as grouping path.'],
        634 => ['path' => ['100% Human Hair', 'Brazilian Hair'], 'type' => 'Weave', 'style' => 'Jerry Curl', 'material' => '100% Unprocessed Virgin Brazilian Hair', 'reason' => 'Brazilian Jerry Curl is a weave style; Brazilian Hair is kept as grouping path.'],
        635 => ['path' => ['100% Human Hair', 'Remy Hair'], 'type' => 'Weave', 'style' => 'Double Drawn Lumiere Remy Hair', 'material' => '100% Remy Human Hair', 'reason' => 'Double Drawn Remy is a remy weave line; quality/material moves to grouping path.'],
        636 => ['path' => ['100% Human Hair', 'Remy Hair'], 'type' => 'Weave', 'style' => 'Triple Weft Remy 150g', 'material' => '100% Remy Human Hair', 'reason' => 'Triple Weft Remy is a remy weave line; quality/material moves to grouping path.'],
        637 => ['path' => ['100% Human Hair'], 'type' => 'Nano Ring Extensions', 'style' => 'Remy Nano Ring Extensions', 'material' => '100% Remy Human Hair', 'reason' => 'Nano Ring is the operational product type.'],
        638 => ['path' => ['100% Human Hair'], 'type' => 'Micro Loop Extensions', 'style' => 'Micro Loop Remy Hair', 'material' => '100% Remy Human Hair', 'reason' => 'Micro Loop is the operational product type.'],
        639 => ['path' => ['100% Human Hair'], 'type' => 'Tape-in Extensions', 'style' => 'Tape-In Remy Extensions', 'material' => 'Remy Human Hair', 'reason' => 'Tape-in is the operational product type.'],
        640 => ['path' => ['100% Human Hair'], 'type' => 'Pre-Bonded Extensions', 'style' => 'Stick Tip Remy Fusion Pre-Bonded', 'material' => '100% Remy Human Hair', 'reason' => 'Stick Tip belongs under operational Pre-Bonded Extensions.'],
        641 => ['path' => ['100% Human Hair'], 'type' => 'Pre-Bonded Extensions', 'style' => 'Nail Tip Remy Pre-Bonded', 'material' => '100% Remy Human Hair', 'reason' => 'Nail Tip belongs under operational Pre-Bonded Extensions.'],
    ];
}

function ei_family_key(array $canonical): string
{
    return implode('|', [
        ei_norm(ei_path_text($canonical['path'])),
        ei_norm($canonical['type']),
        ei_norm($canonical['style']),
    ]);
}

function ei_sku_rows(object $intake): array
{
    $structure = json_decode((string) $intake->variant_structure, true) ?: [];
    $rows = [];

    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $row = [];
        $main = ei_variant_value($sku['main_value'] ?? '');
        if ($main !== '' && ! ei_is_non_sellable_value($main)) {
            $row[] = [
                'axis' => ei_variant_axis($sku['main_axis'] ?? $structure['main_axis'] ?? 'Main'),
                'value' => $main,
            ];
        }

        $sub = ei_variant_value($sku['sub_value'] ?? '');
        if ($sub !== '' && ! ei_is_non_sellable_value($sub)) {
            $row[] = [
                'axis' => ei_variant_axis($sku['sub_axis'] ?? 'Sub'),
                'value' => $sub,
            ];
        }

        foreach (($sku['common_attributes'] ?? []) as $axis => $values) {
            foreach ((array) $values as $value) {
                $value = ei_variant_value($value);
                if ($value !== '' && ! ei_is_non_sellable_value($value)) {
                    $row[] = ['axis' => ei_variant_axis($axis), 'value' => $value];
                }
            }
        }

        if ($row !== []) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function ei_backup(object $brand, string $timestamp): string
{
    $styleIds = DB::table('brand_catalogue_styles')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->pluck('id');
    $variantIds = DB::table('brand_catalogue_variants')
        ->whereIn('brand_catalogue_style_id', $styleIds)
        ->pluck('id');
    $skuIds = DB::table('brand_catalogue_skus')
        ->whereIn('brand_catalogue_style_id', $styleIds)
        ->pluck('id');
    $productTypeIds = DB::table('brand_catalogue_product_types')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->pluck('id');

    $backup = [
        'brand' => $brand,
        'lines' => DB::table('brand_catalogue_lines')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'product_types' => DB::table('brand_catalogue_product_types')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'materials' => DB::table('brand_catalogue_materials')->whereIn('brand_catalogue_product_type_id', $productTypeIds)->get(),
        'styles' => DB::table('brand_catalogue_styles')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'variants' => DB::table('brand_catalogue_variants')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'variant_options' => DB::table('brand_catalogue_variant_options')->whereIn('variant_id', $variantIds)->get(),
        'skus' => DB::table('brand_catalogue_skus')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'sku_variant_options' => DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_sku_id', $skuIds)->get(),
        'images' => DB::table('catalogue_images')
            ->where(function ($query) use ($styleIds, $skuIds) {
                $query->where(function ($styleQuery) use ($styleIds) {
                    $styleQuery->where('imageable_type', 'App\\Models\\BrandCatalogueStyle')
                        ->whereIn('imageable_id', $styleIds);
                })->orWhere(function ($skuQuery) use ($skuIds) {
                    $skuQuery->where('imageable_type', 'App\\Models\\BrandCatalogueSku')
                        ->whereIn('imageable_id', $skuIds);
                });
            })
            ->get(),
        'intakes' => DB::table('hair_extension_intakes')
            ->where('brand_name', 'EI Hair Extensions')
            ->orWhere('brand_catalogue_brand_id', $brand->id)
            ->get(),
        'product_families' => DB::table('product_families')
            ->where('brand_catalogue_brand_id', $brand->id)
            ->orWhere('brand_name', 'EI Hair Extensions')
            ->get(),
    ];

    $path = "catalogue-backups/ei-v2-sync-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function ei_ensure_line(object $brand, array $path, bool $apply, array &$stats, array &$cache): object
{
    $name = ei_path_text($path) ?: 'EI Hair Extensions';
    $key = ei_norm($name);
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
            'slug' => ei_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $name),
            'note' => 'Created from EI V2 shop-floor intake structure.',
            'url' => 'https://eihairextensions.co.uk/',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $line = DB::table('brand_catalogue_lines')->where('id', $id)->first();
        $stats['lines_created']++;
    }

    $line ??= (object) ['id' => 0, 'name' => $name, 'slug' => ei_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $name)];
    return $cache[$key] = $line;
}

function ei_ensure_type(object $brand, object $line, string $name, bool $apply, array &$stats, array &$cache): object
{
    $key = $line->id.'|'.ei_norm($name);
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
            'slug' => ei_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', (int) $line->id, $name),
            'note' => 'Created from EI V2 shop-floor intake structure.',
            'url' => $line->url ?? 'https://eihairextensions.co.uk/',
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

function ei_ensure_style(object $brand, object $type, array $family, bool $apply, array &$stats): object
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
            'note' => ei_clean(($style->note ?? '').' Normalised to EI V2 shop-floor family: '.$canonical['reason']),
            'updated_at' => now(),
        ];

        if ((int) $style->brand_catalogue_product_type_id !== (int) $type->id || $style->name !== $canonical['style']) {
            $updates['slug'] = ei_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style'], (int) $style->id);
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
            'slug' => ei_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style']),
            'note' => 'Created from EI V2 shop-floor evidence. '.$canonical['reason'],
            'url' => '',
            'is_active' => true,
            'sort_order' => 5000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $style = DB::table('brand_catalogue_styles')->where('id', $id)->first();
        $stats['styles_created']++;
    }

    $style ??= (object) [
        'id' => $canonical['source_style_id'] ?: 0,
        'name' => $canonical['style'],
        'brand_catalogue_product_type_id' => $type->id,
    ];

    return $style;
}

function ei_ensure_variant(object $style, string $axis, bool $apply, array &$stats): object
{
    $variant = DB::table('brand_catalogue_variants')
        ->where('brand_catalogue_style_id', $style->id)
        ->where('name', $axis)
        ->first();

    if (! $variant && $apply) {
        $id = DB::table('brand_catalogue_variants')->insertGetId([
            'brand_catalogue_style_id' => $style->id,
            'name' => $axis,
            'variant_type' => ei_variant_type($axis),
            'url' => '',
            'sort_order' => DB::table('brand_catalogue_variants')->where('brand_catalogue_style_id', $style->id)->count() + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $variant = DB::table('brand_catalogue_variants')->where('id', $id)->first();
        $stats['variants_created']++;
    }

    return $variant ?: (object) ['id' => 0, 'name' => $axis, 'variant_type' => ei_variant_type($axis)];
}

function ei_ensure_option(object $variant, string $value, bool $apply, array &$stats): object
{
    $option = DB::table('brand_catalogue_variant_options')
        ->where('variant_id', $variant->id)
        ->where(function ($query) use ($value) {
            $query->where('value', $value)->orWhere('label', $value);
        })
        ->first();

    if (! $option && ei_norm($variant->name) === 'length') {
        $wanted = ei_norm($value);
        $option = DB::table('brand_catalogue_variant_options')
            ->where('variant_id', $variant->id)
            ->get()
            ->first(fn (object $row): bool => ei_norm($row->value) === $wanted || ei_norm($row->label) === $wanted);
    }

    if (! $option && $apply) {
        $id = DB::table('brand_catalogue_variant_options')->insertGetId([
            'variant_id' => $variant->id,
            'label' => $value,
            'value' => $value,
            'sort_order' => DB::table('brand_catalogue_variant_options')->where('variant_id', $variant->id)->count() + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $option = DB::table('brand_catalogue_variant_options')->where('id', $id)->first();
        $stats['options_created']++;
    }

    return $option ?: (object) ['id' => 0, 'variant_id' => $variant->id, 'value' => $value, 'label' => $value];
}

function ei_signature(array $options): string
{
    return collect($options)
        ->map(function (object $option): string {
            $variant = DB::table('brand_catalogue_variants')->where('id', $option->variant_id)->first();

            return ($variant->name ?? 'Variant').':'.($option->value ?? $option->label);
        })
        ->implode('|');
}

function ei_sku_name(array $canonical, array $skuRow): string
{
    $parts = ['EI Hair Extensions', ei_path_text($canonical['path']), $canonical['style']];
    foreach ($skuRow as $part) {
        $parts[] = $part['axis'].' '.$part['value'];
    }

    return ei_clean(implode(' - ', array_filter($parts)));
}

function ei_normalise_official_styles(object $brand, bool $apply, array &$stats, array &$lineCache, array &$typeCache, array &$rows): void
{
    foreach (ei_official_cleanup_mappings() as $styleId => $canonical) {
        $style = DB::table('brand_catalogue_styles')
            ->where('id', $styleId)
            ->where('brand_catalogue_brand_id', $brand->id)
            ->first();

        if (! $style) {
            continue;
        }

        $line = ei_ensure_line($brand, $canonical['path'], $apply, $stats, $lineCache);
        $type = ei_ensure_type($brand, $line, $canonical['type'], $apply, $stats, $typeCache);

        $changed = (int) $style->brand_catalogue_product_type_id !== (int) $type->id
            || $style->name !== $canonical['style']
            || $style->material_name !== $canonical['material'];

        if ($changed) {
            $stats['official_styles_normalised']++;
        }

        if ($apply && $changed) {
            DB::table('brand_catalogue_styles')
                ->where('id', $style->id)
                ->update([
                    'brand_catalogue_product_type_id' => $type->id,
                    'name' => $canonical['style'],
                    'slug' => ei_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style'], (int) $style->id),
                    'material_name' => $canonical['material'],
                    'note' => ei_clean(($style->note ?? '').' '.$canonical['reason']),
                    'updated_at' => now(),
                ]);
        }

        $rows[] = [
            'canonical_key' => 'official-cleanup-'.$styleId,
            'brand' => 'EI Hair Extensions',
            'grouping_path' => ei_path_text($canonical['path']),
            'product_type' => $canonical['type'],
            'style_family' => $canonical['style'],
            'material' => $canonical['material'],
            'source_style_id' => $styleId,
            'intake_ids' => '',
            'style_id' => $styleId,
            'sku_rows_from_v2' => 0,
            'variants_created_for_family' => 0,
            'options_created_for_family' => 0,
            'skus_created_for_family' => 0,
            'reason' => $canonical['reason'],
            'applied' => $apply ? 'yes' : 'no',
        ];
    }
}

function ei_retire_weak_placeholder_styles(bool $apply, array &$stats): void
{
    foreach ([4199] as $styleId) {
        $style = DB::table('brand_catalogue_styles')->where('id', $styleId)->first();
        if (! $style) {
            continue;
        }

        $hasIntakes = DB::table('hair_extension_intakes')->where('brand_catalogue_style_id', $styleId)->exists();
        $hasFamilies = DB::table('product_families')->where('brand_catalogue_style_id', $styleId)->exists();
        $hasImages = DB::table('catalogue_images')->where('imageable_type', 'App\\Models\\BrandCatalogueStyle')->where('imageable_id', $styleId)->exists();

        if ($hasIntakes || $hasFamilies || $hasImages) {
            continue;
        }

        $skuIds = DB::table('brand_catalogue_skus')->where('brand_catalogue_style_id', $styleId)->pluck('id');
        $variantIds = DB::table('brand_catalogue_variants')->where('brand_catalogue_style_id', $styleId)->pluck('id');
        $stats['weak_placeholder_styles_retired']++;

        if (! $apply) {
            continue;
        }

        DB::table('catalogue_images')
            ->where('imageable_type', 'App\\Models\\BrandCatalogueSku')
            ->whereIn('imageable_id', $skuIds)
            ->delete();
        DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_sku_id', $skuIds)->delete();
        DB::table('brand_catalogue_skus')->whereIn('id', $skuIds)->delete();
        DB::table('brand_catalogue_variant_options')->whereIn('variant_id', $variantIds)->delete();
        DB::table('brand_catalogue_variants')->whereIn('id', $variantIds)->delete();
        DB::table('brand_catalogue_styles')->where('id', $styleId)->delete();
    }
}

function ei_delete_empty_buckets(object $brand, bool $apply, array &$stats): void
{
    $productTypes = DB::table('brand_catalogue_product_types')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->orderByDesc('id')
        ->get();

    foreach ($productTypes as $type) {
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

    $lines = DB::table('brand_catalogue_lines')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->orderByDesc('id')
        ->get();

    foreach ($lines as $line) {
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
    ->where('name', 'EI Hair Extensions')
    ->first();

if (! $brand) {
    throw new RuntimeException('EI Hair Extensions brand was not found in brand catalogue 1.');
}

$intakes = DB::table('hair_extension_intakes')
    ->where(function ($query) use ($brand) {
        $query->where('brand_name', 'EI Hair Extensions')
            ->orWhere('brand_catalogue_brand_id', $brand->id);
    })
    ->where('status', 'submitted')
    ->orderBy('id')
    ->get();

$families = [];
foreach ($intakes as $intake) {
    $canonical = ei_canonical_for($intake);
    $key = ei_family_key($canonical);
    $families[$key] ??= [
        'canonical' => $canonical,
        'intakes' => [],
        'sku_rows' => [],
    ];
    $families[$key]['intakes'][] = $intake;

    foreach (ei_sku_rows($intake) as $row) {
        $rowKey = collect($row)
            ->map(fn (array $part): string => ei_norm($part['axis']).'='.ei_norm($part['value']))
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
    'weak_placeholder_styles_retired' => 0,
    'empty_product_types_deleted' => 0,
    'empty_lines_deleted' => 0,
    'backup' => null,
];

$rows = [];
if ($apply) {
    $stats['backup'] = ei_backup($brand, $timestamp);
}

DB::transaction(function () use ($apply, $brand, &$families, &$stats, &$rows): void {
    $lineCache = [];
    $typeCache = [];

    foreach ($families as $family) {
        $canonical = $family['canonical'];
        $line = ei_ensure_line($brand, $canonical['path'], $apply, $stats, $lineCache);
        $type = ei_ensure_type($brand, $line, $canonical['type'], $apply, $stats, $typeCache);
        $style = ei_ensure_style($brand, $type, $family, $apply, $stats);

        $createdSkuCountBefore = $stats['skus_created'];
        $createdOptionCountBefore = $stats['options_created'];
        $createdVariantCountBefore = $stats['variants_created'];

        foreach ($family['sku_rows'] as $skuRow) {
            $options = [];
            foreach ($skuRow as $part) {
                if (ei_clean($part['value']) === '') {
                    continue;
                }
                $variant = ei_ensure_variant($style, $part['axis'], $apply, $stats);
                $options[] = ei_ensure_option($variant, $part['value'], $apply, $stats);
            }

            if ($options === [] || ! $apply) {
                continue;
            }

            $options = collect($options)
                ->unique(fn (object $option): int => (int) $option->variant_id)
                ->values()
                ->all();

            $signature = ei_signature($options);
            $skuName = ei_sku_name($canonical, $skuRow);
            $sku = DB::table('brand_catalogue_skus')
                ->where('brand_catalogue_style_id', $style->id)
                ->where('option_signature', $signature)
                ->first();

            if ($sku) {
                DB::table('brand_catalogue_skus')
                    ->where('id', $sku->id)
                    ->update([
                        'name' => $skuName,
                        'slug' => ei_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $style->id, $skuName, (int) $sku->id),
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
                $stats['skus_updated']++;
            } else {
                $skuId = DB::table('brand_catalogue_skus')->insertGetId([
                    'brand_catalogue_style_id' => $style->id,
                    'name' => $skuName,
                    'slug' => ei_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $style->id, $skuName),
                    'sku_code' => '',
                    'barcode' => '',
                    'option_signature' => $signature,
                    'description' => 'Observed in EI V2 shop-floor intake.',
                    'note' => 'Observed in EI V2 shop-floor intake.',
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
                DB::table('hair_extension_intakes')
                    ->where('id', $intake->id)
                    ->update([
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
            'canonical_key' => ei_family_key($canonical),
            'brand' => 'EI Hair Extensions',
            'grouping_path' => ei_path_text($canonical['path']),
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

    ei_normalise_official_styles($brand, $apply, $stats, $lineCache, $typeCache, $rows);
    ei_retire_weak_placeholder_styles($apply, $stats);
    ei_delete_empty_buckets($brand, $apply, $stats);
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
