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

$csvPath = $reportDir."/obsession-v2-catalogue-sync-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/obsession-v2-catalogue-sync-latest.csv';

function obs_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function obs_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function obs_path_text(array $path): string
{
    return implode(' > ', array_filter(array_map('obs_clean', $path)));
}

function obs_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
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

function obs_variant_axis(mixed $axis): string
{
    $name = obs_clean($axis);
    $key = obs_norm($name);

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

function obs_variant_type(string $axis): string
{
    $key = obs_norm($axis);

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

function obs_variant_value(mixed $value, string $axis = ''): string
{
    $value = str_replace(['Ã¢â‚¬Å“', 'Ã¢â‚¬Â', 'Ã¢â‚¬Â³'], '"', obs_clean($value));
    $value = preg_replace('/#$/', '', $value) ?? $value;
    $axisKey = obs_norm($axis);

    if (str_contains($axisKey, 'length')) {
        $value = preg_replace('/^(\d+)\s*(?:\"|in|inch|inches)$/i', '$1', $value) ?? $value;
        $value = preg_replace('/^(\d+)\s+INCHES$/i', '$1', $value) ?? $value;
    }

    if (str_contains($axisKey, 'pack') || str_contains($axisKey, 'bundle') || str_contains($axisKey, 'count') || str_contains($axisKey, 'piece')) {
        $value = preg_replace('/^(\d+)\s*x$/i', '$1x', $value) ?? $value;
    }

    if (obs_norm($value) === 'colournotvisibleincrop') {
        return '';
    }

    return obs_clean($value);
}

function obs_is_non_sellable_value(string $value): bool
{
    return in_array(obs_norm($value), ['unspecified', 'unknown', 'notknown', 'na', 'none'], true);
}

function obs_canonical_for(object $intake): array
{
    return match ((int) $intake->id) {
        55, 56 => [
            'path' => ['Crochet, Twist & Loc Hair'],
            'type' => 'Crochet Braid',
            'style' => 'Poppin Twist',
            'material' => 'Synthetic Hair',
            'source_style_id' => 75,
            'reason' => 'Shop-floor intake reads Poppin Twist; Mamado-imported Obsession Bulk : Poppin Twist is the matching family.',
        ],
        89 => [
            'path' => ['Crochet, Twist & Loc Hair'],
            'type' => 'Crochet Braid',
            'style' => 'Pre-Fluffed Poppin Twist',
            'material' => 'Synthetic Hair',
            'source_style_id' => 76,
            'reason' => 'Shop-floor intake explicitly reads Pre-Fluffed Poppin Twist; kept separate from the non-pre-fluffed Poppin Twist family.',
        ],
        90 => [
            'path' => ['Crochet, Twist & Loc Hair', 'Kiddies Bulk'],
            'type' => 'Crochet Braid',
            'style' => 'Kiddies Poppin Twist',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake says Kiddies Bulk with 12 inch Poppin Twist; no exact Mamado family exists, so it is created from V2 evidence.',
        ],
        95 => [
            'path' => ['Crochet, Twist & Loc Hair'],
            'type' => 'Crochet Braid',
            'style' => 'Havana Twist',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies Havana Twist; no exact imported Obsession family was found.',
        ],
        96 => [
            'path' => ['Twist & Loc Hair'],
            'type' => 'Braid',
            'style' => 'Afro Twist Braid',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies Syn Afro Twist Braid; treated as braid rather than crochet because the V2 product type says Braid.',
        ],
        102 => [
            'path' => ['Crochet, Twist & Loc Hair'],
            'type' => 'Crochet Braid',
            'style' => 'Water Poppin Twist',
            'material' => 'Synthetic Hair',
            'source_style_id' => 73,
            'reason' => 'Shop-floor intake identifies Pre-Fluffed Water Pop In Twist; Mamado-imported Water Poppin Twist is the matching family.',
        ],
        104, 105, 116, 117, 129, 130 => [
            'path' => ['Crochet, Twist & Loc Hair', 'Nu Soft'],
            'type' => 'Crochet Braid',
            'style' => 'Nu Soft Locs',
            'material' => 'Synthetic Hair',
            'source_style_id' => 71,
            'reason' => 'Shop-floor intakes identify Nu Soft Locs; Mamado-imported Obsession Bulk - Nu Soft Locs is the matching family.',
        ],
        106, 107 => [
            'path' => ['Crochet, Twist & Loc Hair'],
            'type' => 'Crochet Braid',
            'style' => 'Spring Twist',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies Spring Twist; no exact imported Obsession family was found.',
        ],
        140 => [
            'path' => ['Crochet, Twist & Loc Hair'],
            'type' => 'Crochet Braid',
            'style' => 'Rasta Locs',
            'material' => 'Synthetic Hair',
            'source_style_id' => 72,
            'reason' => 'Shop-floor intake identifies Rasta Locs; Mamado-imported Obsession Bulk - Rasta Locs is the matching family.',
        ],
        198 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Zoey',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies Zoey Ponytail; no exact Mamado-imported family was found.',
        ],
        202 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Straight',
            'material' => 'Synthetic Hair',
            'source_style_id' => 85,
            'reason' => 'Shop-floor intake identifies Straight Ponytail; Mamado-imported Obsession Ponytail - Straight is the matching family.',
        ],
        203 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Iris',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies Iris Ponytail; no exact Mamado-imported family was found.',
        ],
        204 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Sophia',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies Sophia Ponytail; no exact Mamado-imported family was found.',
        ],
        205 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Swag',
            'material' => 'Synthetic Hair',
            'source_style_id' => 86,
            'reason' => 'Shop-floor intake identifies Swag Ponytail; Mamado-imported Obsession Ponytail - Swag is the matching family.',
        ],
        216 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Icon',
            'material' => 'Synthetic Hair',
            'source_style_id' => null,
            'reason' => 'Shop-floor intake identifies Icon Ponytail; no exact Mamado-imported family was found.',
        ],
        default => [
            'path' => array_values(array_filter(json_decode((string) $intake->classification_path, true) ?: [])) ?: ['Obsession'],
            'type' => obs_clean($intake->product_type_name) ?: 'Hair Extension',
            'style' => obs_clean($intake->style_name ?: $intake->observed_product_name) ?: 'Review style',
            'material' => 'Review material',
            'source_style_id' => $intake->brand_catalogue_style_id ?: null,
            'reason' => 'Fallback mapping from submitted Obsession V2 intake.',
        ],
    };
}

function obs_official_cleanup_mappings(): array
{
    return [
        69 => ['path' => ['Lace Wig', '4x4 Lace Wig'], 'type' => 'Wig', 'style' => 'Billie', 'material' => 'Synthetic Hair', 'reason' => 'Mamado family Obsession 4x4 Lace Wig : Billie normalised as Wig; lace format is grouping path.'],
        70 => ['path' => ['Lace Wig', '4x4 Lace Wig'], 'type' => 'Wig', 'style' => 'Teyana', 'material' => 'Synthetic Hair', 'reason' => 'Mamado family Obsession 4x4 Lace Wig : Teyana normalised as Wig; lace format is grouping path.'],
        71 => ['path' => ['Crochet, Twist & Loc Hair', 'Nu Soft'], 'type' => 'Crochet Braid', 'style' => 'Nu Soft Locs', 'material' => 'Synthetic Hair', 'reason' => 'Nu Soft Locs is a crochet/loc family; Bulk is supplier category, not product type.'],
        72 => ['path' => ['Crochet, Twist & Loc Hair'], 'type' => 'Crochet Braid', 'style' => 'Rasta Locs', 'material' => 'Synthetic Hair', 'reason' => 'Rasta Locs normalised under Crochet Braid.'],
        73 => ['path' => ['Crochet, Twist & Loc Hair'], 'type' => 'Crochet Braid', 'style' => 'Water Poppin Twist', 'material' => 'Synthetic Hair', 'reason' => 'Water Poppin Twist normalised under Crochet Braid.'],
        74 => ['path' => ['Crochet, Twist & Loc Hair'], 'type' => 'Crochet Braid', 'style' => 'Butterfly Locs', 'material' => 'Synthetic Hair', 'reason' => 'Butterfly Locs normalised under Crochet Braid.'],
        75 => ['path' => ['Crochet, Twist & Loc Hair'], 'type' => 'Crochet Braid', 'style' => 'Poppin Twist', 'material' => 'Synthetic Hair', 'reason' => 'Poppin Twist normalised under Crochet Braid and kept separate from Pre-Fluffed Poppin Twist.'],
        76 => ['path' => ['Crochet, Twist & Loc Hair'], 'type' => 'Crochet Braid', 'style' => 'Pre-Fluffed Poppin Twist', 'material' => 'Synthetic Hair', 'reason' => 'Pre-Fluffed Poppin Twist normalised under Crochet Braid.'],
        77 => ['path' => ['Lace Wig'], 'type' => 'Wig', 'style' => 'Shanaya', 'material' => 'Synthetic Hair', 'reason' => 'Lace Wig supplier category normalised as product type Wig.'],
        78 => ['path' => ['Lace Wig'], 'type' => 'Wig', 'style' => 'Shay', 'material' => 'Synthetic Hair', 'reason' => 'Lace Wig supplier category normalised as product type Wig.'],
        79 => ['path' => ['Lace Wig', 'F/P'], 'type' => 'Wig', 'style' => 'Catalina', 'material' => 'Synthetic Hair', 'reason' => 'F/P lace wig marker kept as grouping path; product type stays Wig.'],
        80 => ['path' => ['Lace Wig', 'F/P'], 'type' => 'Wig', 'style' => 'Hazel', 'material' => 'Synthetic Hair', 'reason' => 'F/P lace wig marker kept as grouping path; product type stays Wig.'],
        81 => ['path' => ['Lace Wig', 'F/P'], 'type' => 'Wig', 'style' => 'Shakira', 'material' => 'Synthetic Hair', 'reason' => 'F/P lace wig marker kept as grouping path; product type stays Wig.'],
        82 => ['path' => ['Lace Wig', 'H/H FN'], 'type' => 'Wig', 'style' => 'Ayleen', 'material' => 'Synthetic Hair', 'reason' => 'H/H FN lace wig marker kept as grouping path; product type stays Wig.'],
        83 => ['path' => ['Lace Wig', 'H/H FN'], 'type' => 'Wig', 'style' => 'Chelsea', 'material' => 'Synthetic Hair', 'reason' => 'H/H FN lace wig marker kept as grouping path; product type stays Wig.'],
        84 => ['path' => ['Lace Wig', 'ILP'], 'type' => 'Wig', 'style' => 'Selena', 'material' => 'Synthetic Hair', 'reason' => 'ILP lace wig marker kept as grouping path; product type stays Wig.'],
        85 => ['path' => ['Hair Ponytail'], 'type' => 'Ponytail', 'style' => 'Straight', 'material' => 'Synthetic Hair', 'reason' => 'Obsession Ponytail - Straight normalised under operational product type Ponytail.'],
        86 => ['path' => ['Hair Ponytail'], 'type' => 'Ponytail', 'style' => 'Swag', 'material' => 'Synthetic Hair', 'reason' => 'Obsession Ponytail - Swag normalised under operational product type Ponytail.'],
    ];
}

function obs_family_key(array $canonical): string
{
    return implode('|', [
        obs_norm(obs_path_text($canonical['path'])),
        obs_norm($canonical['type']),
        obs_norm($canonical['style']),
    ]);
}

function obs_sku_rows(object $intake): array
{
    $structure = json_decode((string) $intake->variant_structure, true) ?: [];
    $rows = [];

    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $row = [];
        $mainAxis = obs_variant_axis($sku['main_axis'] ?? $structure['main_axis'] ?? 'Main');
        $main = obs_variant_value($sku['main_value'] ?? '', $mainAxis);
        if ($main !== '' && ! obs_is_non_sellable_value($main)) {
            $row[] = ['axis' => $mainAxis, 'value' => $main];
        }

        $subAxis = obs_variant_axis($sku['sub_axis'] ?? 'Sub');
        $sub = obs_variant_value($sku['sub_value'] ?? '', $subAxis);
        if ($sub !== '' && ! obs_is_non_sellable_value($sub)) {
            $row[] = ['axis' => $subAxis, 'value' => $sub];
        }

        foreach (($sku['common_attributes'] ?? []) as $axis => $values) {
            $variantAxis = obs_variant_axis($axis);
            foreach ((array) $values as $value) {
                $value = obs_variant_value($value, $variantAxis);
                if ($value !== '' && ! obs_is_non_sellable_value($value)) {
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

function obs_backup(object $brand, string $timestamp): string
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
            ->where('brand_name', 'Obsession')
            ->orWhere('brand_catalogue_brand_id', $brand->id)
            ->get(),
        'product_families' => DB::table('product_families')
            ->where('brand_catalogue_brand_id', $brand->id)
            ->orWhere('brand_name', 'Obsession')
            ->get(),
    ];

    $path = "catalogue-backups/obsession-v2-sync-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function obs_ensure_line(object $brand, array $path, bool $apply, array &$stats, array &$cache): object
{
    $name = obs_path_text($path) ?: 'Obsession';
    $key = obs_norm($name);
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
            'slug' => obs_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $name),
            'note' => 'Created from Obsession V2 shop-floor intake structure.',
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

    $line ??= (object) ['id' => 0, 'name' => $name, 'slug' => obs_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $name)];
    return $cache[$key] = $line;
}

function obs_ensure_type(object $brand, object $line, string $name, bool $apply, array &$stats, array &$cache): object
{
    $key = $line->id.'|'.obs_norm($name);
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
            'slug' => obs_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', (int) $line->id, $name),
            'note' => 'Created from Obsession V2 shop-floor intake structure.',
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

function obs_ensure_style(object $brand, object $type, array $family, bool $apply, array &$stats): object
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
            'note' => obs_clean(($style->note ?? '').' Normalised to Obsession V2 shop-floor family: '.$canonical['reason']),
            'updated_at' => now(),
        ];

        if ((int) $style->brand_catalogue_product_type_id !== (int) $type->id || $style->name !== $canonical['style']) {
            $updates['slug'] = obs_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style'], (int) $style->id);
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
            'slug' => obs_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style']),
            'note' => 'Created from Obsession V2 shop-floor evidence. '.$canonical['reason'],
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

function obs_ensure_variant(object $style, string $axis, bool $apply, array &$stats): object
{
    $variant = DB::table('brand_catalogue_variants')
        ->where('brand_catalogue_style_id', $style->id)
        ->where('name', $axis)
        ->first();

    if (! $variant && $apply) {
        $id = DB::table('brand_catalogue_variants')->insertGetId([
            'brand_catalogue_style_id' => $style->id,
            'name' => $axis,
            'variant_type' => obs_variant_type($axis),
            'url' => '',
            'sort_order' => DB::table('brand_catalogue_variants')->where('brand_catalogue_style_id', $style->id)->count() + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $variant = DB::table('brand_catalogue_variants')->where('id', $id)->first();
        $stats['variants_created']++;
    }

    return $variant ?: (object) ['id' => 0, 'name' => $axis, 'variant_type' => obs_variant_type($axis)];
}

function obs_ensure_option(object $variant, string $value, bool $apply, array &$stats): object
{
    $option = DB::table('brand_catalogue_variant_options')
        ->where('variant_id', $variant->id)
        ->where(function ($query) use ($value) {
            $query->where('value', $value)->orWhere('label', $value);
        })
        ->first();

    if (! $option) {
        $wanted = obs_norm($value);
        $option = DB::table('brand_catalogue_variant_options')
            ->where('variant_id', $variant->id)
            ->get()
            ->first(fn (object $row): bool => obs_norm($row->value) === $wanted || obs_norm($row->label) === $wanted);
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

function obs_signature(array $options): string
{
    return collect($options)
        ->map(function (object $option): string {
            $variant = DB::table('brand_catalogue_variants')->where('id', $option->variant_id)->first();

            return ($variant->name ?? 'Variant').':'.($option->value ?? $option->label);
        })
        ->implode('|');
}

function obs_sku_name(array $canonical, array $skuRow): string
{
    $parts = ['Obsession', obs_path_text($canonical['path']), $canonical['style']];
    foreach ($skuRow as $part) {
        $parts[] = $part['axis'].' '.$part['value'];
    }

    return obs_clean(implode(' - ', array_filter($parts)));
}

function obs_normalise_official_styles(object $brand, bool $apply, array &$stats, array &$lineCache, array &$typeCache, array &$rows): void
{
    foreach (obs_official_cleanup_mappings() as $styleId => $canonical) {
        $style = DB::table('brand_catalogue_styles')
            ->where('id', $styleId)
            ->where('brand_catalogue_brand_id', $brand->id)
            ->first();

        if (! $style) {
            continue;
        }

        $line = obs_ensure_line($brand, $canonical['path'], $apply, $stats, $lineCache);
        $type = obs_ensure_type($brand, $line, $canonical['type'], $apply, $stats, $typeCache);

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
                    'slug' => obs_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style'], (int) $style->id),
                    'material_name' => $canonical['material'],
                    'note' => obs_clean(($style->note ?? '').' '.$canonical['reason']),
                    'updated_at' => now(),
                ]);
        }

        $rows[] = [
            'canonical_key' => 'official-cleanup-'.$styleId,
            'brand' => 'Obsession',
            'grouping_path' => obs_path_text($canonical['path']),
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

function obs_cleanup_bad_picture_variant(bool $apply, array &$stats): void
{
    $sku = DB::table('brand_catalogue_skus')->where('id', 20702)->first();
    $variant = DB::table('brand_catalogue_variants')->where('id', 7611)->first();

    if (! $sku || ! $variant) {
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

    $stats['bad_picture_skus_removed']++;

    if (! $apply) {
        return;
    }

    $optionIds = DB::table('brand_catalogue_variant_options')->where('variant_id', $variant->id)->pluck('id');
    DB::table('brand_catalogue_sku_variant_options')->where('brand_catalogue_sku_id', $sku->id)->delete();
    DB::table('brand_catalogue_skus')->where('id', $sku->id)->delete();
    DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_variant_option_id', $optionIds)->delete();
    DB::table('brand_catalogue_variant_options')->whereIn('id', $optionIds)->delete();
    DB::table('brand_catalogue_variants')->where('id', $variant->id)->delete();
}

function obs_delete_empty_buckets(object $brand, bool $apply, array &$stats): void
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
    ->where('name', 'Obsession')
    ->first();

if (! $brand) {
    throw new RuntimeException('Obsession brand was not found in brand catalogue 1.');
}

$intakes = DB::table('hair_extension_intakes')
    ->where(function ($query) use ($brand) {
        $query->where('brand_name', 'Obsession')
            ->orWhere('brand_catalogue_brand_id', $brand->id);
    })
    ->where('status', 'submitted')
    ->orderBy('id')
    ->get();

$families = [];
foreach ($intakes as $intake) {
    $canonical = obs_canonical_for($intake);
    $key = obs_family_key($canonical);
    $families[$key] ??= [
        'canonical' => $canonical,
        'intakes' => [],
        'sku_rows' => [],
    ];
    $families[$key]['intakes'][] = $intake;

    foreach (obs_sku_rows($intake) as $row) {
        $rowKey = collect($row)
            ->map(fn (array $part): string => obs_norm($part['axis']).'='.obs_norm($part['value']))
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
    'bad_picture_skus_removed' => 0,
    'empty_product_types_deleted' => 0,
    'empty_lines_deleted' => 0,
    'backup' => null,
];

$rows = [];
if ($apply) {
    $stats['backup'] = obs_backup($brand, $timestamp);
}

DB::transaction(function () use ($apply, $brand, &$families, &$stats, &$rows): void {
    $lineCache = [];
    $typeCache = [];

    foreach ($families as $family) {
        $canonical = $family['canonical'];
        $line = obs_ensure_line($brand, $canonical['path'], $apply, $stats, $lineCache);
        $type = obs_ensure_type($brand, $line, $canonical['type'], $apply, $stats, $typeCache);
        $style = obs_ensure_style($brand, $type, $family, $apply, $stats);

        $createdSkuCountBefore = $stats['skus_created'];
        $createdOptionCountBefore = $stats['options_created'];
        $createdVariantCountBefore = $stats['variants_created'];

        foreach ($family['sku_rows'] as $skuRow) {
            $options = [];
            foreach ($skuRow as $part) {
                if (obs_clean($part['value']) === '') {
                    continue;
                }
                $variant = obs_ensure_variant($style, $part['axis'], $apply, $stats);
                $options[] = obs_ensure_option($variant, $part['value'], $apply, $stats);
            }

            if ($options === [] || ! $apply) {
                continue;
            }

            $options = collect($options)
                ->unique(fn (object $option): int => (int) $option->variant_id)
                ->values()
                ->all();

            $signature = obs_signature($options);
            $skuName = obs_sku_name($canonical, $skuRow);
            $sku = DB::table('brand_catalogue_skus')
                ->where('brand_catalogue_style_id', $style->id)
                ->where('option_signature', $signature)
                ->first();

            if ($sku) {
                DB::table('brand_catalogue_skus')
                    ->where('id', $sku->id)
                    ->update([
                        'name' => $skuName,
                        'slug' => obs_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $style->id, $skuName, (int) $sku->id),
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
                $stats['skus_updated']++;
            } else {
                $skuId = DB::table('brand_catalogue_skus')->insertGetId([
                    'brand_catalogue_style_id' => $style->id,
                    'name' => $skuName,
                    'slug' => obs_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $style->id, $skuName),
                    'sku_code' => '',
                    'barcode' => '',
                    'option_signature' => $signature,
                    'description' => 'Observed in Obsession V2 shop-floor intake.',
                    'note' => 'Observed in Obsession V2 shop-floor intake.',
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
            'canonical_key' => obs_family_key($canonical),
            'brand' => 'Obsession',
            'grouping_path' => obs_path_text($canonical['path']),
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

    obs_normalise_official_styles($brand, $apply, $stats, $lineCache, $typeCache, $rows);
    obs_cleanup_bad_picture_variant($apply, $stats);
    obs_delete_empty_buckets($brand, $apply, $stats);
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

