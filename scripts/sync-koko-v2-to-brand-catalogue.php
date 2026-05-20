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

$csvPath = $reportDir."/koko-v2-catalogue-sync-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/koko-v2-catalogue-sync-latest.csv';

function ko_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function ko_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function ko_path_text(array $path): string
{
    return implode(' > ', array_filter(array_map('ko_clean', $path)));
}

function ko_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
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

function ko_variant_axis(mixed $axis): string
{
    $name = ko_clean($axis);
    $key = ko_norm($name);

    if (str_contains($key, 'length')) {
        return 'Length';
    }
    if (str_contains($key, 'colour') || str_contains($key, 'color')) {
        return 'Colour';
    }
    if (str_contains($key, 'pack') || str_contains($key, 'piece') || str_contains($key, 'weft')) {
        return 'Piece count';
    }

    return $name ?: 'Variant';
}

function ko_variant_type(string $axis): string
{
    $key = ko_norm($axis);

    if (str_contains($key, 'length')) {
        return 'measurement';
    }
    if (str_contains($key, 'colour') || str_contains($key, 'color')) {
        return 'colour_code';
    }
    if (str_contains($key, 'count') || str_contains($key, 'piece') || str_contains($key, 'weft')) {
        return 'count';
    }

    return 'text';
}

function ko_variant_value(mixed $value): string
{
    $value = str_replace(['“', '”', '″'], '"', ko_clean($value));
    $value = preg_replace('/^(\d+)\s*(?:\"|in|inch|inches)$/i', '$1"', $value) ?? $value;
    $value = preg_replace('/^(\d+)\s+INCHES$/i', '$1"', $value) ?? $value;
    $value = preg_replace('/\s*\(\s*/', ' (', $value) ?? $value;
    $value = preg_replace('/\s*\)\s*/', ')', $value) ?? $value;

    return ko_clean($value);
}

function ko_is_non_sellable_value(string $value): bool
{
    return in_array(ko_norm($value), ['unspecified', 'unknown', 'notknown', 'na', 'none'], true);
}

function ko_canonical_for(object $intake): array
{
    return match ((int) $intake->id) {
        141, 147, 148, 151 => [
            'path' => ['Clip-In Hair Extensions'],
            'type' => 'Clip-in Extensions',
            'style' => 'G1STR 1 Weft Straight',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 885,
            'reason' => 'Shop-floor intake reads 1 Weft Straight at 24"; official Koko G1STR is the matching 24 inch straight one-piece family.',
        ],
        143, 145, 154 => [
            'path' => ['Clip-In Hair Extensions'],
            'type' => 'Clip-in Extensions',
            'style' => 'Dolce 1 Weft Straight',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 883,
            'reason' => 'Shop-floor intake names Dolce 1 Weft Straight; official Koko Dolce is the matching 18 inch straight one-piece family.',
        ],
        142, 146, 152, 153 => [
            'path' => ['Clip-In Hair Extensions'],
            'type' => 'Clip-in Extensions',
            'style' => 'G1C 1 Weft Curly',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 884,
            'reason' => 'Shop-floor intake reads 1 Weft Curly at 20"; official Koko G1C is the matching 20 inch curly one-piece family.',
        ],
        144 => [
            'path' => ['Clip-In Hair Extensions'],
            'type' => 'Clip-in Extensions',
            'style' => 'G0003 1 Weft Curly',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 882,
            'reason' => 'Shop-floor intake reads 1 Weft Curly at 16"; official Koko G0003 is the matching 16 inch curly one-piece family.',
        ],
        149 => [
            'path' => ['Clip-In Hair Extensions'],
            'type' => 'Clip-in Extensions',
            'style' => 'G1007L Curly Dip Dye Clip-In',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 899,
            'reason' => 'Shop-floor intake reads Ombre Curly 20 with 1BTT171; official Koko G1007L Curly 20 Dip Dye Clip-In has that colour.',
        ],
        150, 155 => [
            'path' => ['Glamorous'],
            'type' => 'Clip-in Extensions',
            'style' => 'Glamorous 3 Wefts Straight',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 875,
            'reason' => 'Shop-floor intake reads 3 Wefts Straight 24"; official Koko Glamorous 3 wefts 24 straight is the exact family.',
        ],
        156 => [
            'path' => ['Clip-In Hair Extensions'],
            'type' => 'Clip-in Extensions',
            'style' => 'K001 One Piece Straight',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 886,
            'reason' => 'Existing official Koko K001 straight clip-in style matches the observed straight clip-in family and colour evidence.',
        ],
        157 => [
            'path' => ['Hair Bun'],
            'type' => 'Bun',
            'style' => 'Clamp Bun',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 927,
            'reason' => 'Shop-floor intake reads Clamp Bun; official Koko Messy Claw Clip Clamp Bun is the matching bun family.',
        ],
        158 => [
            'path' => ['Hair Fringe'],
            'type' => 'Bang / Fringe',
            'style' => 'Fringe',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 926,
            'reason' => 'Shop-floor intake reads Fringe; official Koko Clip in Synthetic Hair Fringe is the matching family.',
        ],
        159 => [
            'path' => ['Hair Bun'],
            'type' => 'Scrunchie',
            'style' => 'Large Scrunchies',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 923,
            'reason' => 'Shop-floor intake reads Large Scrunchies; official Koko Large Hair Scrunchies is the matching family.',
        ],
        161 => [
            'skip' => true,
            'reason' => 'This intake contains multiple style names (Poppin Party / Laurel / Scarlett). It should be split into separate product-family entries before linking.',
        ],
        163 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Pearl',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 903,
            'reason' => 'Shop-floor Pearl ponytail matches official Koko Pearl reversible claw clip ponytail.',
        ],
        164 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Laurel',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 918,
            'reason' => 'Shop-floor Laurel ponytail matches official Koko Laurel reversible drawstring ponytail.',
        ],
        165 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Cosmos',
            'material' => 'Synthetic Fibre',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake clearly identifies Koko Cosmos; no exact official imported family was found, so this is created from V2 evidence.',
        ],
        166 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Blossom',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 907,
            'reason' => 'Shop-floor Blossom ponytail matches official Koko Blossom curly drawstring ponytail.',
        ],
        167 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Molly',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 911,
            'reason' => 'Shop-floor Molly ponytail matches official Koko Molly drawstring ponytail.',
        ],
        168 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Scarlett',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 906,
            'reason' => 'Shop-floor Scarlett ponytail matches official Koko Scarlett claw-clip ponytail.',
        ],
        169 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Tulip',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 908,
            'reason' => 'Shop-floor Tulip ponytail matches official Koko Tulip straight drawstring ponytail.',
        ],
        174 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Glam Grab',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 914,
            'reason' => 'Official Koko Glam Grab is a natural wave claw clip ponytail, so it is normalised as Ponytail despite the intake product-type text.',
        ],
        238 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Deluxe Instant Ponytail',
            'material' => 'Synthetic Fibre',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies Deluxe Instant Ponytail; no exact official imported family was found, so this is created from V2 evidence.',
        ],
        241 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Melissa',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 913,
            'reason' => 'Shop-floor Melissa ponytail matches official Koko Melissa 26 inch wraparound curly ponytail.',
        ],
        default => [
            'path' => array_values(array_filter(json_decode((string) $intake->classification_path, true) ?: [])) ?: ['Koko'],
            'type' => ko_clean($intake->product_type_name) ?: 'Hair Extension',
            'style' => ko_clean($intake->style_name ?: $intake->observed_product_name) ?: 'Review style',
            'material' => 'Review material',
            'source_style_id' => $intake->brand_catalogue_style_id ?: null,
            'reason' => 'Fallback mapping from submitted V2 intake.',
        ],
    };
}

function ko_family_key(array $canonical): string
{
    return implode('|', [
        ko_norm(ko_path_text($canonical['path'])),
        ko_norm($canonical['type']),
        ko_norm($canonical['style']),
    ]);
}

function ko_sku_rows(object $intake): array
{
    $structure = json_decode((string) $intake->variant_structure, true) ?: [];
    $rows = [];

    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $row = [];
        $main = ko_variant_value($sku['main_value'] ?? '');
        if ($main !== '' && ! ko_is_non_sellable_value($main)) {
            $row[] = [
                'axis' => ko_variant_axis($sku['main_axis'] ?? $structure['main_axis'] ?? 'Main'),
                'value' => $main,
            ];
        }

        $sub = ko_variant_value($sku['sub_value'] ?? '');
        if ($sub !== '' && ! ko_is_non_sellable_value($sub)) {
            $row[] = [
                'axis' => ko_variant_axis($sku['sub_axis'] ?? 'Sub'),
                'value' => $sub,
            ];
        }

        foreach (($sku['common_attributes'] ?? []) as $axis => $values) {
            foreach ((array) $values as $value) {
                $value = ko_variant_value($value);
                if ($value !== '' && ! ko_is_non_sellable_value($value)) {
                    $row[] = ['axis' => ko_variant_axis($axis), 'value' => $value];
                }
            }
        }

        if ($row !== []) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function ko_backup(object $brand, string $timestamp): string
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
        'intakes' => DB::table('hair_extension_intakes')->where('brand_name', 'Koko')->orWhere('brand_catalogue_brand_id', $brand->id)->get(),
        'product_families' => DB::table('product_families')->where('brand_catalogue_brand_id', $brand->id)->orWhere('brand_name', 'Koko')->get(),
    ];

    $path = "catalogue-backups/koko-v2-sync-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function ko_ensure_line(object $brand, array $path, bool $apply, array &$stats, array &$cache): object
{
    $name = ko_path_text($path) ?: 'Koko';
    $key = ko_norm($name);
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
            'slug' => ko_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $name),
            'note' => 'Created from Koko V2 shop-floor intake structure.',
            'url' => 'https://koko-hair.co.uk/',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $line = DB::table('brand_catalogue_lines')->where('id', $id)->first();
        $stats['lines_created']++;
    }

    $line ??= (object) ['id' => 0, 'name' => $name, 'slug' => ko_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $name)];
    return $cache[$key] = $line;
}

function ko_ensure_type(object $brand, object $line, string $name, bool $apply, array &$stats, array &$cache): object
{
    $key = $line->id.'|'.ko_norm($name);
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
            'slug' => ko_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', (int) $line->id, $name),
            'note' => 'Created from Koko V2 shop-floor intake structure.',
            'url' => $line->url ?? 'https://koko-hair.co.uk/',
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

function ko_ensure_style(object $brand, object $type, array $family, bool $apply, array &$stats): object
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
        if ((int) $style->brand_catalogue_product_type_id !== (int) $type->id || $style->name !== $canonical['style']) {
            DB::table('brand_catalogue_styles')
                ->where('id', $style->id)
                ->update([
                    'brand_catalogue_product_type_id' => $type->id,
                    'name' => $canonical['style'],
                    'slug' => ko_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style'], (int) $style->id),
                    'material_name' => $canonical['material'],
                    'note' => ko_clean(($style->note ?? '').' Normalised to Koko V2 shop-floor family: '.$canonical['reason']),
                    'updated_at' => now(),
                ]);
            $stats['styles_moved_or_renamed']++;
            $style = DB::table('brand_catalogue_styles')->where('id', $style->id)->first();
        }
    }

    if (! $style && $apply) {
        $id = DB::table('brand_catalogue_styles')->insertGetId([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_material_id' => null,
            'brand_catalogue_product_type_id' => $type->id,
            'material_name' => $canonical['material'],
            'name' => $canonical['style'],
            'slug' => ko_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style']),
            'note' => 'Created from Koko V2 shop-floor evidence. '.$canonical['reason'],
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

function ko_ensure_variant(object $style, string $axis, bool $apply, array &$stats): object
{
    $variant = DB::table('brand_catalogue_variants')
        ->where('brand_catalogue_style_id', $style->id)
        ->where('name', $axis)
        ->first();

    if (! $variant && $apply) {
        $id = DB::table('brand_catalogue_variants')->insertGetId([
            'brand_catalogue_style_id' => $style->id,
            'name' => $axis,
            'variant_type' => ko_variant_type($axis),
            'url' => '',
            'sort_order' => DB::table('brand_catalogue_variants')->where('brand_catalogue_style_id', $style->id)->count() + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $variant = DB::table('brand_catalogue_variants')->where('id', $id)->first();
        $stats['variants_created']++;
    }

    return $variant ?: (object) ['id' => 0, 'name' => $axis, 'variant_type' => ko_variant_type($axis)];
}

function ko_ensure_option(object $variant, string $value, bool $apply, array &$stats): object
{
    $option = DB::table('brand_catalogue_variant_options')
        ->where('variant_id', $variant->id)
        ->where(function ($query) use ($value) {
            $query->where('value', $value)->orWhere('label', $value);
        })
        ->first();

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

function ko_signature(array $options): string
{
    return collect($options)
        ->map(fn (object $option): string => $option->variant_id.':'.$option->id)
        ->sort()
        ->implode('|');
}

function ko_sku_name(array $canonical, array $skuRow): string
{
    $parts = ['Koko', ko_path_text($canonical['path']), $canonical['style']];
    foreach ($skuRow as $part) {
        $parts[] = $part['axis'].' '.$part['value'];
    }

    return ko_clean(implode(' - ', array_filter($parts)));
}

function ko_retire_empty_picture_styles(bool $apply, array &$stats): void
{
    foreach ([4175, 4176] as $styleId) {
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
        $stats['picture_placeholder_styles_retired']++;

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

        $typeId = $style->brand_catalogue_product_type_id;
        $typeInUse = DB::table('brand_catalogue_styles')->where('brand_catalogue_product_type_id', $typeId)->exists()
            || DB::table('hair_extension_intakes')->where('brand_catalogue_product_type_id', $typeId)->exists()
            || DB::table('product_families')->where('brand_catalogue_product_type_id', $typeId)->exists()
            || DB::table('brand_catalogue_materials')->where('brand_catalogue_product_type_id', $typeId)->exists();

        if (! $typeInUse) {
            DB::table('brand_catalogue_product_types')->where('id', $typeId)->delete();
            $stats['empty_product_types_deleted']++;
        }
    }
}

$brand = DB::table('brand_catalogue_brands')
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Koko')
    ->first();

if (! $brand) {
    throw new RuntimeException('Koko brand was not found in brand catalogue 1.');
}

$intakes = DB::table('hair_extension_intakes')
    ->where(function ($query) use ($brand) {
        $query->where('brand_name', 'Koko')
            ->orWhere('brand_catalogue_brand_id', $brand->id);
    })
    ->where('status', 'submitted')
    ->orderBy('id')
    ->get();

$families = [];
$skipped = [];
foreach ($intakes as $intake) {
    $canonical = ko_canonical_for($intake);
    if (($canonical['skip'] ?? false) === true) {
        $skipped[] = [
            'intake_id' => $intake->id,
            'reason' => $canonical['reason'],
        ];
        continue;
    }

    $key = ko_family_key($canonical);
    $families[$key] ??= [
        'canonical' => $canonical,
        'intakes' => [],
        'sku_rows' => [],
    ];
    $families[$key]['intakes'][] = $intake;

    foreach (ko_sku_rows($intake) as $row) {
        $rowKey = collect($row)
            ->map(fn (array $part): string => ko_norm($part['axis']).'='.ko_norm($part['value']))
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
    'intakes_skipped' => count($skipped),
    'lines_created' => 0,
    'product_types_created' => 0,
    'styles_created' => 0,
    'styles_moved_or_renamed' => 0,
    'variants_created' => 0,
    'options_created' => 0,
    'skus_created' => 0,
    'skus_updated' => 0,
    'picture_placeholder_styles_retired' => 0,
    'empty_product_types_deleted' => 0,
    'backup' => null,
];

$rows = [];
if ($apply) {
    $stats['backup'] = ko_backup($brand, $timestamp);
}

DB::transaction(function () use ($apply, $brand, &$families, $skipped, &$stats, &$rows): void {
    $lineCache = [];
    $typeCache = [];

    foreach ($families as $family) {
        $canonical = $family['canonical'];
        $line = ko_ensure_line($brand, $canonical['path'], $apply, $stats, $lineCache);
        $type = ko_ensure_type($brand, $line, $canonical['type'], $apply, $stats, $typeCache);
        $style = ko_ensure_style($brand, $type, $family, $apply, $stats);

        $createdSkuCountBefore = $stats['skus_created'];
        $createdOptionCountBefore = $stats['options_created'];
        $createdVariantCountBefore = $stats['variants_created'];

        foreach ($family['sku_rows'] as $skuRow) {
            $options = [];
            foreach ($skuRow as $part) {
                if (ko_clean($part['value']) === '') {
                    continue;
                }
                $variant = ko_ensure_variant($style, $part['axis'], $apply, $stats);
                $options[] = ko_ensure_option($variant, $part['value'], $apply, $stats);
            }

            if ($options === [] || ! $apply) {
                continue;
            }

            $options = collect($options)
                ->unique(fn (object $option): int => (int) $option->variant_id)
                ->values()
                ->all();

            $signature = ko_signature($options);
            $skuName = ko_sku_name($canonical, $skuRow);
            $sku = DB::table('brand_catalogue_skus')
                ->where('brand_catalogue_style_id', $style->id)
                ->where('option_signature', $signature)
                ->first();

            if ($sku) {
                DB::table('brand_catalogue_skus')
                    ->where('id', $sku->id)
                    ->update([
                        'name' => $skuName,
                        'slug' => ko_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $style->id, $skuName, (int) $sku->id),
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
                $stats['skus_updated']++;
            } else {
                $skuId = DB::table('brand_catalogue_skus')->insertGetId([
                    'brand_catalogue_style_id' => $style->id,
                    'name' => $skuName,
                    'slug' => ko_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $style->id, $skuName),
                    'sku_code' => '',
                    'barcode' => '',
                    'option_signature' => $signature,
                    'description' => 'Observed in Koko V2 shop-floor intake.',
                    'note' => 'Observed in Koko V2 shop-floor intake.',
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
            'canonical_key' => ko_family_key($canonical),
            'brand' => 'Koko',
            'grouping_path' => ko_path_text($canonical['path']),
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

    foreach ($skipped as $skip) {
        $rows[] = [
            'canonical_key' => 'skipped',
            'brand' => 'Koko',
            'grouping_path' => '',
            'product_type' => '',
            'style_family' => '',
            'material' => '',
            'source_style_id' => '',
            'intake_ids' => $skip['intake_id'],
            'style_id' => '',
            'sku_rows_from_v2' => 0,
            'variants_created_for_family' => 0,
            'options_created_for_family' => 0,
            'skus_created_for_family' => 0,
            'reason' => $skip['reason'],
            'applied' => $apply ? 'no' : 'no',
        ];
    }

    ko_retire_empty_picture_styles($apply, $stats);
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
