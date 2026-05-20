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

$csvPath = $reportDir."/kali-v2-catalogue-sync-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/kali-v2-catalogue-sync-latest.csv';

function ka_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function ka_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function ka_path_text(array $path): string
{
    return implode(' > ', array_filter(array_map('ka_clean', $path)));
}

function ka_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
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

function ka_variant_axis(mixed $axis): string
{
    $name = ka_clean($axis);
    $key = ka_norm($name);

    if (str_contains($key, 'length')) {
        return 'Length';
    }
    if (str_contains($key, 'colour') || str_contains($key, 'color')) {
        return 'Colour';
    }
    if (str_contains($key, 'pack') || str_contains($key, 'bundle') || str_contains($key, 'count') || str_contains($key, 'piece')) {
        return 'Bundle';
    }

    return $name ?: 'Variant';
}

function ka_variant_type(string $axis): string
{
    $key = ka_norm($axis);

    if (str_contains($key, 'length')) {
        return 'measurement';
    }
    if (str_contains($key, 'colour') || str_contains($key, 'color')) {
        return 'colour_code';
    }
    if (str_contains($key, 'bundle') || str_contains($key, 'count') || str_contains($key, 'piece') || str_contains($key, 'pack')) {
        return 'count';
    }

    return 'text';
}

function ka_variant_value(mixed $value, string $axis = ''): string
{
    $value = str_replace(['â€œ', 'â€', 'â€³'], '"', ka_clean($value));
    $value = preg_replace('/#$/', '', $value) ?? $value;
    $axisKey = ka_norm($axis);

    if (str_contains($axisKey, 'length')) {
        $value = preg_replace('/^(\d+)\s*(?:\"|in|inch|inches)$/i', '$1', $value) ?? $value;
        $value = preg_replace('/^(\d+)\s+INCHES$/i', '$1', $value) ?? $value;
    }

    if (str_contains($axisKey, 'pack') || str_contains($axisKey, 'bundle') || str_contains($axisKey, 'count') || str_contains($axisKey, 'piece')) {
        $value = preg_replace('/^(\d+)\s*x$/i', '$1x', $value) ?? $value;
    }

    if (ka_norm($value) === 'colournotvisibleincrop') {
        return '';
    }

    return ka_clean($value);
}

function ka_is_non_sellable_value(string $value): bool
{
    return in_array(ka_norm($value), ['unspecified', 'unknown', 'notknown', 'na', 'none'], true);
}

function ka_canonical_for(object $intake): array
{
    return match ((int) $intake->id) {
        60, 63, 78 => [
            'path' => ['Kali Quick Braid'],
            'type' => 'Braid',
            'style' => 'Quick Braid',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake reads Kali Quick Braid with length/pack axes; kept separate from Kali Essential Quick Braid because the observed packaging and variants do not safely match the imported Essential style.',
        ],
        84 => [
            'path' => ['Kali Feel Me'],
            'type' => 'Braid',
            'style' => 'French Curl',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake reads Kali Feel Me French Curl; no exact Beauty Elements Kali source style was imported.',
        ],
        94 => [
            'path' => ['Kali Essential', 'Afro Twist Collection'],
            'type' => 'Crochet Braid',
            'style' => 'Baby Mambo',
            'material' => 'Synthetic Hair',
            'source_style_id' => 232,
            'reason' => 'Shop-floor Baby Mambo matches Beauty Elements Kali Essential Baby Mambo; Afro Twist Collection is kept as grouping path.',
        ],
        99 => [
            'path' => ['Kali Essential'],
            'type' => 'Crochet Braid',
            'style' => 'Mega French Curl',
            'material' => 'Synthetic Hair',
            'source_style_id' => 4179,
            'reason' => 'Shop-floor French Curl 28 inch 3X matches the existing Mega French Curl picture-confirmed family; fake Product Variant axis is removed.',
        ],
        110 => [
            'path' => ['Kali Essential', 'African Braid Collection'],
            'type' => 'Braid',
            'style' => 'Faux Locs Dread',
            'material' => 'Synthetic Hair',
            'source_style_id' => 237,
            'reason' => 'Shop-floor Dread Faux Locs matches Beauty Elements Kali Essential Faux Locs Dread; African Braid Collection is kept as grouping path.',
        ],
        111 => [
            'path' => ['Kali Essential'],
            'type' => 'Crochet Braid',
            'style' => 'Micro Locs',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies Micro Locs; no exact imported Kali Essential source style was found.',
        ],
        196, 197 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Uptown Girl',
            'material' => 'Synthetic Hair',
            'source_style_id' => 278,
            'reason' => 'Shop-floor Uptown Girl ponytail matches Beauty Elements Kali Uptown Girl.',
        ],
        199 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Cuban Girl',
            'material' => 'Synthetic Hair',
            'source_style_id' => 270,
            'reason' => 'Shop-floor Cuban Girl ponytail matches Beauty Elements Kali Cuban Girl.',
        ],
        218, 222 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Tiffany Girl',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies Tiffany Girl ponytail; no exact imported Kali source style was found.',
        ],
        221 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Florence Girl',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies Florence Girl ponytail; no exact imported Kali source style was found.',
        ],
        265, 266 => [
            'path' => ['Hair Weaving'],
            'type' => 'Weave',
            'style' => 'Mega Bohemian Remi',
            'material' => 'Human Hair Blend',
            'source_style_id' => 12674,
            'reason' => 'Shop-floor intake identifies Mega Bohemian Remi as Hair Weaving; existing placeholder style is moved from Braiding Hair to operational Weave.',
        ],
        default => [
            'path' => array_values(array_filter(json_decode((string) $intake->classification_path, true) ?: [])) ?: ['Kali'],
            'type' => ka_clean($intake->product_type_name) ?: 'Hair Extension',
            'style' => ka_clean($intake->style_name ?: $intake->observed_product_name) ?: 'Review style',
            'material' => 'Review material',
            'source_style_id' => $intake->brand_catalogue_style_id ?: null,
            'reason' => 'Fallback mapping from submitted Kali V2 intake.',
        ],
    };
}

function ka_family_key(array $canonical): string
{
    return implode('|', [
        ka_norm(ka_path_text($canonical['path'])),
        ka_norm($canonical['type']),
        ka_norm($canonical['style']),
    ]);
}

function ka_sku_rows(object $intake): array
{
    $structure = json_decode((string) $intake->variant_structure, true) ?: [];
    $rows = [];

    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $row = [];
        $mainAxis = ka_variant_axis($sku['main_axis'] ?? $structure['main_axis'] ?? 'Main');
        $main = ka_variant_value($sku['main_value'] ?? '', $mainAxis);
        if ($main !== '' && ! ka_is_non_sellable_value($main)) {
            $row[] = ['axis' => $mainAxis, 'value' => $main];
        }

        $subAxis = ka_variant_axis($sku['sub_axis'] ?? 'Sub');
        $sub = ka_variant_value($sku['sub_value'] ?? '', $subAxis);
        if ($sub !== '' && ! ka_is_non_sellable_value($sub)) {
            $row[] = ['axis' => $subAxis, 'value' => $sub];
        }

        foreach (($sku['common_attributes'] ?? []) as $axis => $values) {
            $variantAxis = ka_variant_axis($axis);
            foreach ((array) $values as $value) {
                $value = ka_variant_value($value, $variantAxis);
                if ($value !== '' && ! ka_is_non_sellable_value($value)) {
                    $row[] = ['axis' => $variantAxis, 'value' => $value];
                }
            }
        }

        if ($row !== []) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function ka_backup(object $brand, string $timestamp): string
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
            ->whereIn('brand_name', ['Kali', 'Kali Essential'])
            ->orWhere('brand_catalogue_brand_id', $brand->id)
            ->get(),
        'product_families' => DB::table('product_families')
            ->where('brand_catalogue_brand_id', $brand->id)
            ->orWhereIn('brand_name', ['Kali', 'Kali Essential'])
            ->get(),
    ];

    $path = "catalogue-backups/kali-v2-sync-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function ka_ensure_line(object $brand, array $path, bool $apply, array &$stats, array &$cache): object
{
    $name = ka_path_text($path) ?: 'Kali';
    $key = ka_norm($name);
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
            'slug' => ka_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $name),
            'note' => 'Created from Kali V2 shop-floor intake structure.',
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

    $line ??= (object) ['id' => 0, 'name' => $name, 'slug' => ka_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $name)];
    return $cache[$key] = $line;
}

function ka_ensure_type(object $brand, object $line, string $name, bool $apply, array &$stats, array &$cache): object
{
    $key = $line->id.'|'.ka_norm($name);
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
            'slug' => ka_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', (int) $line->id, $name),
            'note' => 'Created from Kali V2 shop-floor intake structure.',
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

function ka_ensure_style(object $brand, object $type, array $family, bool $apply, array &$stats): object
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
            'note' => ka_clean(($style->note ?? '').' Normalised to Kali V2 shop-floor family: '.$canonical['reason']),
            'updated_at' => now(),
        ];

        if ((int) $style->brand_catalogue_product_type_id !== (int) $type->id || $style->name !== $canonical['style']) {
            $updates['slug'] = ka_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style'], (int) $style->id);
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
            'slug' => ka_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style']),
            'note' => 'Created from Kali V2 shop-floor evidence. '.$canonical['reason'],
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

function ka_ensure_variant(object $style, string $axis, bool $apply, array &$stats): object
{
    $variant = DB::table('brand_catalogue_variants')
        ->where('brand_catalogue_style_id', $style->id)
        ->where('name', $axis)
        ->first();

    if (! $variant && $apply) {
        $id = DB::table('brand_catalogue_variants')->insertGetId([
            'brand_catalogue_style_id' => $style->id,
            'name' => $axis,
            'variant_type' => ka_variant_type($axis),
            'url' => '',
            'sort_order' => DB::table('brand_catalogue_variants')->where('brand_catalogue_style_id', $style->id)->count() + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $variant = DB::table('brand_catalogue_variants')->where('id', $id)->first();
        $stats['variants_created']++;
    }

    return $variant ?: (object) ['id' => 0, 'name' => $axis, 'variant_type' => ka_variant_type($axis)];
}

function ka_ensure_option(object $variant, string $value, bool $apply, array &$stats): object
{
    $option = DB::table('brand_catalogue_variant_options')
        ->where('variant_id', $variant->id)
        ->where(function ($query) use ($value) {
            $query->where('value', $value)->orWhere('label', $value);
        })
        ->first();

    if (! $option) {
        $wanted = ka_norm($value);
        $option = DB::table('brand_catalogue_variant_options')
            ->where('variant_id', $variant->id)
            ->get()
            ->first(fn (object $row): bool => ka_norm($row->value) === $wanted || ka_norm($row->label) === $wanted);
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

function ka_signature(array $options): string
{
    return collect($options)
        ->map(function (object $option): string {
            $variant = DB::table('brand_catalogue_variants')->where('id', $option->variant_id)->first();

            return ($variant->name ?? 'Variant').':'.($option->value ?? $option->label);
        })
        ->implode('|');
}

function ka_sku_name(array $canonical, array $skuRow): string
{
    $parts = ['Kali', ka_path_text($canonical['path']), $canonical['style']];
    foreach ($skuRow as $part) {
        $parts[] = $part['axis'].' '.$part['value'];
    }

    return ka_clean(implode(' - ', array_filter($parts)));
}

function ka_normalise_official_styles(object $brand, bool $apply, array &$stats, array &$lineCache, array &$typeCache, array &$rows): void
{
    $styles = DB::table('brand_catalogue_styles as s')
        ->join('brand_catalogue_product_types as pt', 'pt.id', '=', 's.brand_catalogue_product_type_id')
        ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
        ->where('s.brand_catalogue_brand_id', $brand->id)
        ->select('s.id', 's.name', 's.material_name', 'pt.name as old_type', 'l.name as old_line')
        ->get();

    foreach ($styles as $style) {
        $canonical = ka_official_cleanup_for($style);
        if ($canonical === null) {
            continue;
        }

        $line = ka_ensure_line($brand, $canonical['path'], $apply, $stats, $lineCache);
        $type = ka_ensure_type($brand, $line, $canonical['type'], $apply, $stats, $typeCache);

        $changed = DB::table('brand_catalogue_styles')
            ->where('id', $style->id)
            ->where(function ($query) use ($type, $canonical) {
                $query->where('brand_catalogue_product_type_id', '!=', $type->id)
                    ->orWhere('name', '!=', $canonical['style'])
                    ->orWhere('material_name', '!=', $canonical['material']);
            })
            ->exists();

        if ($changed) {
            $stats['official_styles_normalised']++;
        }

        if ($apply && $changed) {
            DB::table('brand_catalogue_styles')
                ->where('id', $style->id)
                ->update([
                    'brand_catalogue_product_type_id' => $type->id,
                    'name' => $canonical['style'],
                    'slug' => ka_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style'], (int) $style->id),
                    'material_name' => $canonical['material'],
                    'note' => ka_clean(($style->note ?? '').' '.$canonical['reason']),
                    'updated_at' => now(),
                ]);
        }

        $rows[] = [
            'canonical_key' => 'official-cleanup-'.$style->id,
            'brand' => 'Kali',
            'grouping_path' => ka_path_text($canonical['path']),
            'product_type' => $canonical['type'],
            'style_family' => $canonical['style'],
            'material' => $canonical['material'],
            'source_style_id' => $style->id,
            'intake_ids' => '',
            'style_id' => $style->id,
            'sku_rows_from_v2' => 0,
            'variants_created_for_family' => 0,
            'options_created_for_family' => 0,
            'skus_created_for_family' => 0,
            'reason' => $canonical['reason'],
            'applied' => $apply ? 'yes' : 'no',
        ];
    }
}

function ka_official_cleanup_for(object $style): ?array
{
    if ((int) $style->id === 12674) {
        return ['path' => ['Hair Weaving'], 'type' => 'Weave', 'style' => 'Mega Bohemian Remi', 'material' => 'Human Hair Blend', 'reason' => 'Mega Bohemian Remi is confirmed by V2 as Hair Weaving / Weave.'];
    }
    if ((int) $style->id === 232) {
        return ['path' => ['Kali Essential', 'Afro Twist Collection'], 'type' => 'Crochet Braid', 'style' => 'Baby Mambo', 'material' => 'Synthetic Hair', 'reason' => 'Baby Mambo is confirmed by V2 as Crochet Braid under Afro Twist Collection.'];
    }
    if ((int) $style->id === 237) {
        return ['path' => ['Kali Essential', 'African Braid Collection'], 'type' => 'Braid', 'style' => 'Faux Locs Dread', 'material' => 'Synthetic Hair', 'reason' => 'Faux Locs Dread is matched to shop-floor Dread Faux Locs under African Braid Collection.'];
    }
    if ((int) $style->id === 4179) {
        return ['path' => ['Kali Essential'], 'type' => 'Crochet Braid', 'style' => 'Mega French Curl', 'material' => 'Synthetic Hair', 'reason' => 'Mega French Curl picture-confirmed style is normalised from a weak placeholder to a real crochet braid family.'];
    }

    if ($style->old_line === 'Kali' && $style->old_type === 'Ponytails') {
        return ['path' => ['Hair Ponytail'], 'type' => 'Ponytail', 'style' => $style->name, 'material' => $style->material_name ?: 'Synthetic Hair', 'reason' => 'Kali ponytail source styles normalised under operational product type Ponytail.'];
    }
    if ($style->old_line === 'Kali' && $style->old_type === 'Wigs') {
        return ['path' => ['Wigs'], 'type' => 'Wig', 'style' => $style->name, 'material' => $style->material_name ?: 'Synthetic Hair', 'reason' => 'Kali wig source styles normalised under operational product type Wig.'];
    }
    if ($style->old_line === 'Kali' && $style->old_type === 'Lace Wigs') {
        return ['path' => ['Lace Wig'], 'type' => 'Wig', 'style' => $style->name, 'material' => $style->material_name ?: 'Synthetic Hair', 'reason' => 'Kali lace wig source styles normalised as Wig with Lace Wig grouping path.'];
    }
    if ($style->old_line === 'Kali Essential' && $style->old_type === 'Braiding Hair') {
        return ['path' => ['Kali Essential'], 'type' => 'Braid', 'style' => $style->name, 'material' => $style->material_name ?: 'Synthetic Hair', 'reason' => 'Kali Essential broad Braiding Hair source bucket normalised to operational Braid.'];
    }
    if ($style->old_line === 'Kali Essential' && $style->old_type === 'Weaves') {
        return ['path' => ['Kali Essential'], 'type' => 'Weave', 'style' => $style->name, 'material' => $style->material_name ?: 'Synthetic Hair', 'reason' => 'Kali Essential Weaves source bucket normalised to operational Weave.'];
    }
    if ($style->old_line === 'Kali Essential' && $style->old_type === 'Wigs') {
        return ['path' => ['Kali Essential'], 'type' => 'Wig', 'style' => $style->name, 'material' => $style->material_name ?: 'Synthetic Hair', 'reason' => 'Kali Essential Wigs source bucket normalised to operational Wig.'];
    }
    if ($style->old_line === 'Kali Essential' && $style->old_type === 'Lace Wigs') {
        return ['path' => ['Kali Essential', 'Lace Wig'], 'type' => 'Wig', 'style' => $style->name, 'material' => $style->material_name ?: 'Synthetic Hair', 'reason' => 'Kali Essential Lace Wigs source bucket normalised as Wig with Lace Wig grouping path.'];
    }

    return null;
}

function ka_cleanup_weak_placeholder_skus(bool $apply, array &$stats): void
{
    foreach ([20721 => 7629, 30646 => null] as $skuId => $variantId) {
        $sku = DB::table('brand_catalogue_skus')->where('id', $skuId)->first();
        if (! $sku) {
            continue;
        }

        $hasProduct = DB::table('products')->where('brand_catalogue_sku_id', $sku->id)->exists();
        $hasImage = DB::table('catalogue_images')
            ->where('imageable_type', 'App\\Models\\BrandCatalogueSku')
            ->where('imageable_id', $sku->id)
            ->exists();

        if ($hasProduct || $hasImage) {
            continue;
        }

        $stats['weak_placeholder_skus_removed']++;

        if (! $apply) {
            continue;
        }

        DB::table('brand_catalogue_sku_variant_options')->where('brand_catalogue_sku_id', $sku->id)->delete();
        DB::table('brand_catalogue_skus')->where('id', $sku->id)->delete();

        if ($variantId !== null) {
            $optionIds = DB::table('brand_catalogue_variant_options')->where('variant_id', $variantId)->pluck('id');
            DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_variant_option_id', $optionIds)->delete();
            DB::table('brand_catalogue_variant_options')->whereIn('id', $optionIds)->delete();
            DB::table('brand_catalogue_variants')->where('id', $variantId)->delete();
        }
    }
}

function ka_delete_empty_buckets(object $brand, bool $apply, array &$stats): void
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

function ka_align_product_families_to_current_styles(object $brand, bool $apply, array &$stats): void
{
    $families = DB::table('product_families as pf')
        ->join('brand_catalogue_styles as s', 's.id', '=', 'pf.brand_catalogue_style_id')
        ->join('brand_catalogue_product_types as pt', 'pt.id', '=', 's.brand_catalogue_product_type_id')
        ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
        ->where('s.brand_catalogue_brand_id', $brand->id)
        ->where(function ($query) use ($brand) {
            $query->where('pf.brand_catalogue_brand_id', $brand->id)
                ->orWhereIn('pf.brand_name', ['Kali', 'Kali Essential']);
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

$brand = DB::table('brand_catalogue_brands')
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Kali')
    ->first();

if (! $brand) {
    throw new RuntimeException('Kali brand was not found in brand catalogue 1.');
}

$intakes = DB::table('hair_extension_intakes')
    ->where(function ($query) use ($brand) {
        $query->whereIn('brand_name', ['Kali', 'Kali Essential'])
            ->orWhere('brand_catalogue_brand_id', $brand->id);
    })
    ->where('status', 'submitted')
    ->orderBy('id')
    ->get();

$families = [];
foreach ($intakes as $intake) {
    $canonical = ka_canonical_for($intake);
    $key = ka_family_key($canonical);
    $families[$key] ??= [
        'canonical' => $canonical,
        'intakes' => [],
        'sku_rows' => [],
    ];
    $families[$key]['intakes'][] = $intake;

    foreach (ka_sku_rows($intake) as $row) {
        $rowKey = collect($row)
            ->map(fn (array $part): string => ka_norm($part['axis']).'='.ka_norm($part['value']))
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
    $stats['backup'] = ka_backup($brand, $timestamp);
}

DB::transaction(function () use ($apply, $brand, &$families, &$stats, &$rows): void {
    $lineCache = [];
    $typeCache = [];

    foreach ($families as $family) {
        $canonical = $family['canonical'];
        $line = ka_ensure_line($brand, $canonical['path'], $apply, $stats, $lineCache);
        $type = ka_ensure_type($brand, $line, $canonical['type'], $apply, $stats, $typeCache);
        $style = ka_ensure_style($brand, $type, $family, $apply, $stats);

        $createdSkuCountBefore = $stats['skus_created'];
        $createdOptionCountBefore = $stats['options_created'];
        $createdVariantCountBefore = $stats['variants_created'];

        foreach ($family['sku_rows'] as $skuRow) {
            $options = [];
            foreach ($skuRow as $part) {
                if (ka_clean($part['value']) === '') {
                    continue;
                }
                $variant = ka_ensure_variant($style, $part['axis'], $apply, $stats);
                $options[] = ka_ensure_option($variant, $part['value'], $apply, $stats);
            }

            if ($options === [] || ! $apply) {
                continue;
            }

            $options = collect($options)
                ->unique(fn (object $option): int => (int) $option->variant_id)
                ->values()
                ->all();

            $signature = ka_signature($options);
            $skuName = ka_sku_name($canonical, $skuRow);
            $sku = DB::table('brand_catalogue_skus')
                ->where('brand_catalogue_style_id', $style->id)
                ->where('option_signature', $signature)
                ->first();

            if ($sku) {
                DB::table('brand_catalogue_skus')
                    ->where('id', $sku->id)
                    ->update([
                        'name' => $skuName,
                        'slug' => ka_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $style->id, $skuName, (int) $sku->id),
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
                $stats['skus_updated']++;
            } else {
                $skuId = DB::table('brand_catalogue_skus')->insertGetId([
                    'brand_catalogue_style_id' => $style->id,
                    'name' => $skuName,
                    'slug' => ka_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $style->id, $skuName),
                    'sku_code' => '',
                    'barcode' => '',
                    'option_signature' => $signature,
                    'description' => 'Observed in Kali V2 shop-floor intake.',
                    'note' => 'Observed in Kali V2 shop-floor intake.',
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
            'canonical_key' => ka_family_key($canonical),
            'brand' => 'Kali',
            'grouping_path' => ka_path_text($canonical['path']),
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

    ka_normalise_official_styles($brand, $apply, $stats, $lineCache, $typeCache, $rows);
    ka_cleanup_weak_placeholder_skus($apply, $stats);
    ka_align_product_families_to_current_styles($brand, $apply, $stats);
    ka_delete_empty_buckets($brand, $apply, $stats);
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
