<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueLine;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use App\Models\HairExtensionIntake;
use App\Models\ProductFamily;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

$apply = in_array('--apply', $argv, true);
$timestamp = date('Ymd-His');
$reportDir = public_path('reports');

if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$csvPath = $reportDir."/kuknus-v2-catalogue-sync-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/kuknus-v2-catalogue-sync-latest.csv';

function kv2_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function kv2_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function kv2_path_text(array $path): string
{
    return implode(' > ', array_filter(array_map('kv2_clean', $path)));
}

function kv2_variant_axis(mixed $axis): string
{
    $name = kv2_clean($axis);
    $key = kv2_norm($name);

    if (str_contains($key, 'length')) {
        return 'Length';
    }
    if (str_contains($key, 'colour') || str_contains($key, 'color')) {
        return 'Colour';
    }
    if (str_contains($key, 'pack') || str_contains($key, 'bundle') || str_contains($key, 'count') || str_contains($key, 'piece')) {
        return 'Pack count';
    }

    return $name ?: 'Variant';
}

function kv2_variant_type(string $axis): string
{
    $key = kv2_norm($axis);

    if (str_contains($key, 'length')) {
        return 'measurement';
    }
    if (str_contains($key, 'colour') || str_contains($key, 'color')) {
        return 'colour_code';
    }
    if (str_contains($key, 'pack') || str_contains($key, 'bundle') || str_contains($key, 'count') || str_contains($key, 'piece')) {
        return 'count';
    }

    return 'text';
}

function kv2_variant_value(mixed $value): string
{
    $value = str_replace(['“', '”', '″'], '"', kv2_clean($value));
    $value = str_ireplace(['NICHES', 'INCHS'], 'INCHES', $value);
    $value = preg_replace('/^(\d+)\s*(?:\"|in|inch|inches)$/i', '$1"', $value) ?? $value;
    $value = preg_replace('/^(\d+)\s+INCHES$/i', '$1"', $value) ?? $value;

    return kv2_clean($value);
}

function kv2_canonical_for(HairExtensionIntake $intake): array
{
    return match ((int) $intake->id) {
        59, 64 => [
            'path' => ['Spectrial'],
            'type' => 'Braid',
            'style' => 'Pre-Stretched Braid',
            'material' => 'Synthetic Hair',
            'reason' => 'Shop-floor package identifies Spectrial pre-stretched braid; length and pack count are variants.',
        ],
        62 => [
            'path' => ['Fusion'],
            'type' => 'Bulk Hair',
            'style' => 'Afro Kinky Bulk',
            'material' => 'Human Hair Blend',
            'reason' => 'Shop-floor intake places Afro Kinky Bulk under Kuknus Fusion with 20 inch colour variants.',
        ],
        75 => [
            'path' => ['Fusion'],
            'type' => 'Bulk Hair',
            'style' => 'Peruvian Remi Deep',
            'material' => 'Human Hair Blend',
            'reason' => 'Shop-floor intake identifies Fusion Peruvian Remi Deep as a bulk hair family.',
        ],
        76 => [
            'path' => ['Fusion'],
            'type' => 'Bulk Hair',
            'style' => 'Peruvian Remi Water',
            'material' => 'Human Hair Blend',
            'reason' => 'Shop-floor intake identifies Fusion Peruvian Remi Water as a bulk hair family.',
        ],
        85 => [
            'path' => ['Kuknus Collection'],
            'type' => 'Braid',
            'style' => 'French Curl',
            'material' => 'Synthetic Hair',
            'reason' => 'Shop-floor intake identifies Kuknus Collection French Curl 3X braid.',
        ],
        97 => [
            'path' => ['Kuknus Collection', 'Pre-Stretch'],
            'type' => 'Crochet Braid',
            'style' => 'Pre-Stretch Popp-In-Twist',
            'material' => 'Synthetic Hair',
            'reason' => 'Shop photo/intake reads Pre-Stretch Popp-In-Twist with crochet/interlocking evidence.',
        ],
        109 => [
            'path' => ['Bulk'],
            'type' => 'Bulk Hair',
            'style' => 'Dread Locks Wavy Bulk',
            'material' => 'Synthetic Hair',
            'reason' => 'Shop-floor intake identifies Dread Locks Wavy Bulk under Kuknus bulk grouping.',
        ],
        296 => [
            'path' => ['Hair Braiding'],
            'type' => 'Crochet Braid',
            'style' => 'Afro Kinky Bulk',
            'material' => 'Synthetic Hair',
            'reason' => 'Official Kuknus source has Afro Kinky Bulk 24 under Synthetic Crochet Braids; shop variant confirms 24 inch 2X.',
        ],
        338 => [
            'path' => ['Hair Braiding'],
            'type' => 'Bulk Hair',
            'style' => 'Brazilian Bulk',
            'material' => 'Synthetic Hair',
            'reason' => 'Shop-floor intake identifies Brazilian Bulk 20 inch; official import does not provide an exact Brazilian Bulk family.',
        ],
        176, 185 => [
            'path' => ['Hair Ponytail', 'Butterfly Drawstring Series'],
            'type' => 'Ponytail',
            'style' => 'HH Toronto',
            'material' => 'Human Hair Blend',
            'reason' => 'Shop-floor intakes identify HH Toronto / Butterfly Drawstring Series; official has Toronto Girl but not exact HH Toronto.',
        ],
        177 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'HH Cuban Girl',
            'material' => 'Human Hair',
            'reason' => 'Shop-floor Cuban Girl length 30 matches the official HH Cuban Girl 30 family strongly.',
        ],
        175, 178 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Miami Girl',
            'material' => 'Human Hair Blend',
            'reason' => 'Shop-floor Miami Girl intakes match official Kuknus Miami Girl.',
        ],
        180, 182 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Danish Girl',
            'material' => 'Hair',
            'reason' => 'Shop-floor Danish Girl intakes match official Kuknus Danish Girl.',
        ],
        181 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'German Girl',
            'material' => 'Hair',
            'reason' => 'Shop-floor German Girl intake matches official Kuknus German Girl.',
        ],
        179 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Spanish Girl',
            'material' => 'Hair',
            'reason' => 'Shop-floor Spanish Girl intake matches official Kuknus Spanish Girl.',
        ],
        195 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Indian Wrap',
            'material' => 'Hair',
            'reason' => 'Shop-floor intake identifies Indian Wrap; no exact official imported style was found, so this is created from V2 evidence.',
        ],
        215, 235 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Jamaican Girl',
            'material' => 'Hair',
            'reason' => 'Shop-floor Jamaican Girl intakes match official Kuknus Jamaican Girl.',
        ],
        74, 232, 234 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'London Girl',
            'material' => 'Hair',
            'reason' => 'Shop-floor London Girl intakes match official Kuknus London Girl; Butterfly wording kept as source evidence, not a separate family.',
        ],
        184 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Togo Girl',
            'material' => 'Hair',
            'reason' => 'Shop-floor Togo Girl intake matches official Kuknus Togo Girl.',
        ],
        237 => [
            'path' => ['Hair Ponytail'],
            'type' => 'Ponytail',
            'style' => 'Big Afro Puff',
            'material' => 'Hair',
            'reason' => 'Shop-floor Big Afro Puff intake matches official Kuknus Big Afro Puff.',
        ],
        default => [
            'path' => ['Kuknus'],
            'type' => kv2_clean($intake->product_type_name) ?: 'Hair Extension',
            'style' => kv2_clean($intake->style_name ?: $intake->observed_product_name) ?: 'Review style',
            'material' => 'Review material',
            'reason' => 'Fallback mapping; review required.',
        ],
    };
}

function kv2_family_key(array $canonical): string
{
    return implode('|', [
        kv2_norm(kv2_path_text($canonical['path'])),
        kv2_norm($canonical['type']),
        kv2_norm($canonical['style']),
    ]);
}

function kv2_family_display(array $canonical): string
{
    $path = array_values(array_filter($canonical['path']));
    $line = $path ? kv2_clean((string) end($path)) : '';
    $style = kv2_clean($canonical['style']);

    if ($line !== '' && ! str_contains(kv2_norm($style), kv2_norm($line))) {
        return kv2_clean('Kuknus '.$line.' '.$style);
    }

    return kv2_clean('Kuknus '.$style);
}

function kv2_sku_rows(HairExtensionIntake $intake): array
{
    $structure = $intake->variant_structure ?: [];
    $rows = [];

    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $row = [];
        if (kv2_clean($sku['main_value'] ?? '') !== '') {
            $row[] = [
                'axis' => kv2_variant_axis($sku['main_axis'] ?? $structure['main_axis'] ?? 'Main'),
                'value' => kv2_variant_value($sku['main_value']),
            ];
        }
        if (kv2_clean($sku['sub_value'] ?? '') !== '') {
            $row[] = [
                'axis' => kv2_variant_axis($sku['sub_axis'] ?? 'Sub'),
                'value' => kv2_variant_value($sku['sub_value']),
            ];
        }
        foreach (($sku['common_attributes'] ?? []) as $axis => $values) {
            foreach ((array) $values as $value) {
                if (kv2_clean($value) !== '') {
                    $row[] = ['axis' => kv2_variant_axis($axis), 'value' => kv2_variant_value($value)];
                }
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function kv2_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
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

function kv2_ensure_line(BrandCatalogueBrand $brand, array $path, bool $apply, array &$stats, array &$lineCache): BrandCatalogueLine
{
    $lineName = kv2_path_text($path) ?: $brand->name;
    $cacheKey = kv2_norm($lineName);

    if (isset($lineCache[$cacheKey])) {
        return $lineCache[$cacheKey];
    }

    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->whereRaw('LOWER(name) = ?', [Str::lower($lineName)])
        ->first();

    if ($line) {
        return $lineCache[$cacheKey] = $line;
    }

    $stats['lines_created']++;

    if (! $apply) {
        return $lineCache[$cacheKey] = new BrandCatalogueLine([
            'id' => -1 * $stats['lines_created'],
            'brand_catalogue_brand_id' => $brand->id,
            'name' => $lineName,
            'slug' => Str::slug($lineName),
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 500 + $stats['lines_created'],
        ]);
    }

    return $lineCache[$cacheKey] = BrandCatalogueLine::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'name' => $lineName,
        'slug' => kv2_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', $brand->id, $lineName),
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 500 + $stats['lines_created'],
    ]);
}

function kv2_ensure_product_type(BrandCatalogueBrand $brand, BrandCatalogueLine $line, string $typeName, bool $apply, array &$stats, array &$typeCache): BrandCatalogueProductType
{
    $cacheKey = ((int) $line->id).'|'.kv2_norm($typeName);
    if (isset($typeCache[$cacheKey])) {
        return $typeCache[$cacheKey];
    }

    $type = BrandCatalogueProductType::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('brand_catalogue_line_id', $line->id)
        ->whereRaw('LOWER(name) = ?', [Str::lower($typeName)])
        ->first();

    if ($type) {
        return $typeCache[$cacheKey] = $type;
    }

    $stats['product_types_created']++;

    if (! $apply) {
        return $typeCache[$cacheKey] = new BrandCatalogueProductType([
            'id' => -1 * $stats['product_types_created'],
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_line_id' => $line->id,
            'name' => $typeName,
            'slug' => Str::slug($typeName),
            'is_active' => true,
            'sort_order' => 500 + $stats['product_types_created'],
        ]);
    }

    return $typeCache[$cacheKey] = BrandCatalogueProductType::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_line_id' => $line->id,
        'name' => $typeName,
        'slug' => kv2_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', $line->id, $typeName),
        'is_active' => true,
        'sort_order' => 500 + $stats['product_types_created'],
    ]);
}

function kv2_existing_style_id_for(array $family): ?int
{
    $manual = [
        'spectrial|braid|prestretchedbraid' => 4162,
        'hairbraiding|crochetbraid|afrokinkybulk' => 438,
        'hairponytail|ponytail|bigafropuff' => 449,
        'hairponytail|ponytail|danishgirl' => 455,
        'hairponytail|ponytail|germangirl' => 458,
        'hairponytail|ponytail|hhcubangirl' => 467,
        'hairponytail|ponytail|jamaicangirl' => 475,
        'hairponytail|ponytail|londongirl' => 512,
        'hairponytail|ponytail|miamigirl' => 514,
        'hairponytail|ponytail|spanishgirl' => 517,
        'hairponytail|ponytail|togogirl' => 520,
    ];

    return $manual[kv2_family_key($family['canonical'])] ?? null;
}

function kv2_ensure_style(BrandCatalogueBrand $brand, BrandCatalogueProductType $type, array $family, bool $apply, array &$stats): BrandCatalogueStyle
{
    $styleName = $family['canonical']['style'];
    $material = $family['canonical']['material'] ?? 'Review material';
    $existingId = $family['existing_style_id'];
    $style = $existingId ? BrandCatalogueStyle::query()->find($existingId) : null;

    if (! $style) {
        $style = BrandCatalogueStyle::query()
            ->where('brand_catalogue_brand_id', $brand->id)
            ->where('brand_catalogue_product_type_id', $type->id)
            ->whereRaw('LOWER(name) = ?', [Str::lower($styleName)])
            ->first();
    }

    if ($style) {
        $shouldUpdateMaterial = kv2_clean($style->material_name) === '' || kv2_clean($style->material_name) === 'Review material';
        $changed = (int) $style->brand_catalogue_product_type_id !== (int) $type->id
            || kv2_clean($style->name) !== $styleName
            || $shouldUpdateMaterial;

        if ($changed) {
            $stats['styles_moved_or_renamed']++;
        }

        if ($apply && $changed) {
            $style->update([
                'brand_catalogue_brand_id' => $brand->id,
                'brand_catalogue_product_type_id' => $type->id,
                'name' => $styleName,
                'slug' => kv2_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $type->id, $styleName, $style->id),
                'material_name' => $shouldUpdateMaterial ? $material : $style->material_name,
                'is_active' => true,
            ]);

            ProductFamily::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->update([
                    'brand_catalogue_line_id' => $type->brand_catalogue_line_id,
                    'brand_catalogue_product_type_id' => $type->id,
                    'line_name' => $type->line?->name,
                    'product_type_name' => $type->name,
                    'family_name' => kv2_family_display($family['canonical']),
                    'updated_at' => now(),
                ]);
        }

        return $style->fresh();
    }

    $stats['styles_created']++;

    if (! $apply) {
        return new BrandCatalogueStyle([
            'id' => -1 * $stats['styles_created'],
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $type->id,
            'name' => $styleName,
            'material_name' => $material,
            'is_active' => true,
        ]);
    }

    return BrandCatalogueStyle::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_product_type_id' => $type->id,
        'name' => $styleName,
        'slug' => kv2_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $type->id, $styleName),
        'material_name' => $material,
        'note' => 'Created from confirmed V2 shop-floor Kuknus intake.',
        'is_active' => true,
        'sort_order' => 600 + $stats['styles_created'],
    ]);
}

function kv2_ensure_variant(BrandCatalogueStyle $style, string $axis, bool $apply, array &$stats): BrandCatalogueVariant
{
    $name = kv2_variant_axis($axis);
    $variant = BrandCatalogueVariant::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
        ->first();

    if ($variant) {
        return $variant;
    }

    $stats['variants_created']++;

    if (! $apply) {
        return new BrandCatalogueVariant([
            'id' => -1 * $stats['variants_created'],
            'brand_catalogue_style_id' => $style->id,
            'name' => $name,
            'variant_type' => kv2_variant_type($name),
            'sort_order' => $stats['variants_created'] * 10,
        ]);
    }

    return BrandCatalogueVariant::query()->create([
        'brand_catalogue_style_id' => $style->id,
        'name' => $name,
        'variant_type' => kv2_variant_type($name),
        'sort_order' => $stats['variants_created'] * 10,
    ]);
}

function kv2_ensure_option(BrandCatalogueVariant $variant, string $value, bool $apply, array &$stats): BrandCatalogueVariantOption
{
    $value = kv2_variant_value($value);
    $option = BrandCatalogueVariantOption::query()
        ->where('variant_id', $variant->id)
        ->where(function ($query) use ($value): void {
            $query
                ->whereRaw('LOWER(label) = ?', [Str::lower($value)])
                ->orWhereRaw('LOWER(value) = ?', [Str::lower($value)]);
        })
        ->first();

    if ($option) {
        return $option;
    }

    $stats['options_created']++;

    if (! $apply) {
        return new BrandCatalogueVariantOption([
            'id' => -1 * $stats['options_created'],
            'variant_id' => $variant->id,
            'label' => $value,
            'value' => $value,
            'sort_order' => $stats['options_created'],
        ]);
    }

    return BrandCatalogueVariantOption::query()->create([
        'variant_id' => $variant->id,
        'label' => $value,
        'value' => $value,
        'sort_order' => $stats['options_created'],
    ]);
}

function kv2_signature(Collection $options): string
{
    $parts = $options
        ->map(fn (BrandCatalogueVariantOption $option): string => $option->variant_id.':'.$option->id)
        ->values()
        ->all();

    sort($parts, SORT_NATURAL);

    return implode('|', $parts);
}

function kv2_sku_name(array $canonical, array $row): string
{
    $parts = [kv2_family_display($canonical)];
    foreach ($row as $part) {
        if (kv2_norm($part['axis']) === 'single') {
            continue;
        }
        $parts[] = kv2_variant_axis($part['axis']).' '.$part['value'];
    }

    return kv2_clean(implode(' - ', $parts));
}

function kv2_backup_kuknus(BrandCatalogueBrand $brand, string $timestamp): string
{
    $styleIds = BrandCatalogueStyle::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->pluck('id');
    $variantIds = BrandCatalogueVariant::query()
        ->whereIn('brand_catalogue_style_id', $styleIds)
        ->pluck('id');
    $skuIds = BrandCatalogueSku::query()
        ->whereIn('brand_catalogue_style_id', $styleIds)
        ->pluck('id');

    $backup = [
        'brand' => $brand->toArray(),
        'lines' => DB::table('brand_catalogue_lines')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'product_types' => DB::table('brand_catalogue_product_types')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'styles' => DB::table('brand_catalogue_styles')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'variants' => DB::table('brand_catalogue_variants')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'variant_options' => DB::table('brand_catalogue_variant_options')->whereIn('variant_id', $variantIds)->get(),
        'skus' => DB::table('brand_catalogue_skus')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'sku_variant_options' => DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_sku_id', $skuIds)->get(),
        'intakes' => DB::table('hair_extension_intakes')->where('brand_name', 'Kuknus')->orWhere('brand_catalogue_brand_id', $brand->id)->get(),
        'product_families' => DB::table('product_families')->where('brand_catalogue_brand_id', $brand->id)->orWhere('brand_name', 'Kuknus')->get(),
    ];

    $path = "catalogue-backups/kuknus-v2-sync-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function kv2_move_official_ponytails(BrandCatalogueBrand $brand, bool $apply, array &$stats, array &$lineCache, array &$typeCache): void
{
    $sourceTypes = BrandCatalogueProductType::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', 'Ponytails / Drawstrings')
        ->get();

    if ($sourceTypes->isEmpty()) {
        return;
    }

    $line = kv2_ensure_line($brand, ['Hair Ponytail'], $apply, $stats, $lineCache);
    $target = kv2_ensure_product_type($brand, $line, 'Ponytail', $apply, $stats, $typeCache);
    $target->setRelation('line', $line);

    foreach ($sourceTypes as $sourceType) {
        if ((int) $sourceType->id === (int) $target->id) {
            continue;
        }

        $styles = BrandCatalogueStyle::query()
            ->where('brand_catalogue_product_type_id', $sourceType->id)
            ->orderBy('id')
            ->get();

        $stats['official_ponytail_styles_moved'] += $styles->count();

        if ($apply) {
            foreach ($styles as $style) {
                $style->update([
                    'brand_catalogue_product_type_id' => $target->id,
                    'slug' => kv2_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $target->id, $style->name, $style->id),
                    'is_active' => true,
                ]);
            }

            ProductFamily::query()
                ->where('brand_catalogue_product_type_id', $sourceType->id)
                ->update([
                    'brand_catalogue_line_id' => $line->id,
                    'brand_catalogue_product_type_id' => $target->id,
                    'line_name' => $line->name,
                    'product_type_name' => $target->name,
                    'updated_at' => now(),
                ]);

            DB::table('hair_extension_intakes')
                ->where('brand_catalogue_product_type_id', $sourceType->id)
                ->update([
                    'brand_catalogue_product_type_id' => $target->id,
                    'product_type_name' => $target->name,
                    'updated_at' => now(),
                ]);

            if (
                ! DB::table('brand_catalogue_styles')->where('brand_catalogue_product_type_id', $sourceType->id)->exists()
                && ! DB::table('product_families')->where('brand_catalogue_product_type_id', $sourceType->id)->exists()
                && ! DB::table('hair_extension_intakes')->where('brand_catalogue_product_type_id', $sourceType->id)->exists()
            ) {
                $sourceType->delete();
                $stats['empty_product_types_deleted']++;
            }
        }
    }
}

$brand = BrandCatalogueBrand::query()
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Kuknus')
    ->firstOrFail();

$intakes = HairExtensionIntake::query()
    ->where('status', 'submitted')
    ->where('brand_name', 'Kuknus')
    ->orderBy('id')
    ->get();

$families = [];
foreach ($intakes as $intake) {
    $canonical = kv2_canonical_for($intake);
    $key = kv2_family_key($canonical);

    $families[$key] ??= [
        'canonical' => $canonical,
        'intakes' => collect(),
        'sku_rows' => [],
    ];
    $families[$key]['intakes']->push($intake);

    foreach (kv2_sku_rows($intake) as $row) {
        $rowKey = collect($row)
            ->map(fn (array $part): string => kv2_norm($part['axis']).'='.kv2_norm($part['value']))
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
    'intakes_linked' => 0,
    'lines_created' => 0,
    'product_types_created' => 0,
    'styles_created' => 0,
    'styles_moved_or_renamed' => 0,
    'variants_created' => 0,
    'options_created' => 0,
    'skus_created' => 0,
    'skus_updated' => 0,
    'official_ponytail_styles_moved' => 0,
    'empty_product_types_deleted' => 0,
    'backup' => null,
];

$rows = [];

if ($apply) {
    $stats['backup'] = kv2_backup_kuknus($brand, $timestamp);
}

DB::transaction(function () use ($apply, $brand, &$families, &$stats, &$rows): void {
    $lineCache = [];
    $typeCache = [];

    kv2_move_official_ponytails($brand, $apply, $stats, $lineCache, $typeCache);

    foreach ($families as &$family) {
        /** @var Collection<int, HairExtensionIntake> $familyIntakes */
        $familyIntakes = $family['intakes'];
        $family['existing_style_id'] = kv2_existing_style_id_for($family);

        $line = kv2_ensure_line($brand, $family['canonical']['path'], $apply, $stats, $lineCache);
        $type = kv2_ensure_product_type($brand, $line, $family['canonical']['type'], $apply, $stats, $typeCache);
        $type->setRelation('line', $line);
        $style = kv2_ensure_style($brand, $type, $family, $apply, $stats);

        $createdSkuCountBefore = $stats['skus_created'];
        $createdOptionCountBefore = $stats['options_created'];
        $createdVariantCountBefore = $stats['variants_created'];

        foreach ($family['sku_rows'] as $skuRow) {
            $options = collect();
            foreach ($skuRow as $part) {
                if (kv2_clean($part['value']) === '') {
                    continue;
                }

                $variant = kv2_ensure_variant($style, $part['axis'], $apply, $stats);
                $option = kv2_ensure_option($variant, $part['value'], $apply, $stats);
                $options->push($option);
            }

            if ($options->isEmpty() || ! $apply) {
                continue;
            }

            $options = $options
                ->unique(fn (BrandCatalogueVariantOption $option): int => (int) $option->variant_id)
                ->values();

            $signature = kv2_signature($options);
            $skuName = kv2_sku_name($family['canonical'], $skuRow);
            $sku = BrandCatalogueSku::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->where('option_signature', $signature)
                ->first();

            if ($sku) {
                $sku->update([
                    'name' => $skuName,
                    'slug' => kv2_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $skuName, $sku->id),
                    'is_active' => true,
                ]);
                $stats['skus_updated']++;
            } else {
                $sku = BrandCatalogueSku::query()->create([
                    'brand_catalogue_style_id' => $style->id,
                    'name' => $skuName,
                    'slug' => kv2_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $skuName),
                    'option_signature' => $signature,
                    'description' => 'Observed in V2 shop-floor intake.',
                    'is_active' => true,
                    'sort_order' => $style->skus()->count() + 1,
                ]);
                $stats['skus_created']++;
            }

            $sku->optionValues()->sync($options->mapWithKeys(fn (BrandCatalogueVariantOption $option) => [
                $option->id => ['brand_catalogue_variant_id' => $option->variant_id],
            ])->all());
        }

        if ($apply) {
            foreach ($familyIntakes as $intake) {
                $intake->update([
                    'classification_path' => $family['canonical']['path'],
                    'product_type_name' => $family['canonical']['type'],
                    'style_name' => $family['canonical']['style'],
                    'brand_catalogue_brand_id' => $brand->id,
                    'brand_catalogue_product_type_id' => $type->id,
                    'brand_catalogue_style_id' => $style->id,
                    'catalogue_style_status' => 'known',
                    'product_type_status' => 'known',
                    'style_family_status' => 'known',
                    'last_synced_at' => now(),
                ]);
                $stats['intakes_linked']++;
            }
        } else {
            $stats['intakes_linked'] += $familyIntakes->count();
        }

        $rows[] = [
            'canonical_key' => kv2_family_key($family['canonical']),
            'brand' => 'Kuknus',
            'grouping_path' => kv2_path_text($family['canonical']['path']),
            'product_type' => $family['canonical']['type'],
            'style_family' => $family['canonical']['style'],
            'display_family' => kv2_family_display($family['canonical']),
            'material' => $family['canonical']['material'],
            'intake_ids' => $familyIntakes->pluck('id')->implode(', '),
            'style_id' => $style->id,
            'style_source' => $family['existing_style_id'] ? 'existing' : ($apply ? 'created' : 'would_create'),
            'sku_rows_from_v2' => count($family['sku_rows']),
            'variants_created_for_family' => $stats['variants_created'] - $createdVariantCountBefore,
            'options_created_for_family' => $stats['options_created'] - $createdOptionCountBefore,
            'skus_created_for_family' => $stats['skus_created'] - $createdSkuCountBefore,
            'reason' => $family['canonical']['reason'],
            'applied' => $apply ? 'yes' : 'no',
        ];
    }
});

$csv = fopen($csvPath, 'w');
fputcsv($csv, [
    'canonical_key',
    'brand',
    'grouping_path',
    'product_type',
    'style_family',
    'display_family',
    'material',
    'intake_ids',
    'style_id',
    'style_source',
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
