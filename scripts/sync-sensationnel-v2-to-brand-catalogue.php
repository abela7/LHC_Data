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

$csvPath = $reportDir."/sensationnel-v2-catalogue-sync-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/sensationnel-v2-catalogue-sync-latest.csv';

function sn2_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function sn2_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function sn2_path_text(array $path): string
{
    return implode(' > ', array_filter(array_map('sn2_clean', $path)));
}

function sn2_variant_type(string $axis): string
{
    $key = sn2_norm($axis);

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

function sn2_variant_value(mixed $value): string
{
    $value = sn2_clean($value);
    $value = str_ireplace(['NICHES', 'INCHS'], 'INCHES', $value);
    $value = preg_replace('/^(\d+)\s*(?:\"|in|inch|inches)$/i', '$1"', $value) ?? $value;
    $value = preg_replace('/^(\d+)\s+INCHES$/i', '$1"', $value) ?? $value;

    return sn2_clean($value);
}

function sn2_canonical_for(HairExtensionIntake $intake): array
{
    return match ((int) $intake->id) {
        71, 288, 290, 292, 293, 294, 298, 299, 305, 307 => [
            'path' => ['Premium Now'],
            'type' => 'Weave',
            'style' => 'Euro Straight',
            'material' => 'Human Hair',
            'reason' => 'Shop photos/readings show Premium Now HH European/Euro Straight weave.',
        ],
        217 => [
            'path' => ['Premium Too Blend Hair'],
            'type' => 'Bulk Hair',
            'style' => '2X Ocean Wave Bulk',
            'material' => 'Human & Premium Blend Hair',
            'reason' => 'Observed name matches imported Premium Too 2X Ocean Wave Bulk; this is bulk hair, not ponytail.',
        ],
        219 => [
            'path' => ['Instant Pony'],
            'type' => 'Ponytail',
            'style' => 'French Wave',
            'material' => 'Synthetic Hair',
            'reason' => 'Shop-floor path says Instant Pony and style reads French Wave.',
        ],
        248 => [
            'path' => ['Premium Plus'],
            'type' => 'Weave',
            'style' => 'Tara (27 Pcs)',
            'material' => 'Human Hair',
            'reason' => 'Observed Premium Plus Tara 27-piece product.',
        ],
        264 => [
            'path' => ['Premium Too'],
            'type' => 'Weave',
            'style' => 'Multi Loose Twist',
            'material' => 'Human & Premium Blend Hair',
            'reason' => 'Observed style matches imported Premium Too Multi Loose Twist.',
        ],
        270, 272 => [
            'path' => ['Premium Too Human Hair'],
            'type' => 'Bulk Hair',
            'style' => 'Deep Wave Bulk',
            'material' => 'Human Hair',
            'reason' => 'Observed Deep Wave Bulk matches existing Premium Too Human Hair bulk family.',
        ],
        271, 274 => [
            'path' => ['Premium Too Human Hair'],
            'type' => 'Weave',
            'style' => 'Jerry Curl',
            'material' => 'Human Hair',
            'reason' => 'Observed Jerry Curl matches existing Premium Too Human Hair weave family.',
        ],
        273, 295, 297, 308 => [
            'path' => ['Premium Too Human Hair'],
            'type' => 'Weave',
            'style' => 'Yaki Natural Wvg',
            'material' => 'Human Hair',
            'reason' => 'Observed Yaki Natural/Yaki Natural Wvg matches existing Premium Too Human Hair weave family.',
        ],
        309 => [
            'path' => ['Goddess Remi'],
            'type' => 'Fusion Extensions',
            'style' => 'Remi Fusion Silky',
            'material' => 'Human Hair',
            'reason' => 'Shop photo shows Goddess Remi Fusion packaging and Remi Fusion Silky label.',
        ],
        324, 325, 326 => [
            'path' => ['Goddess Remi'],
            'type' => 'Weave',
            'style' => 'Goddess Remi Silky',
            'material' => 'Human Hair',
            'reason' => 'Shop photos/readings show Goddess Remi Silky Wvg variants.',
        ],
        default => [
            'path' => ['Sensationnel'],
            'type' => sn2_clean($intake->product_type_name) ?: 'Hair Extension',
            'style' => sn2_clean($intake->style_name ?: $intake->observed_product_name) ?: 'Review style',
            'material' => 'Review material',
            'reason' => 'Fallback mapping; review required.',
        ],
    };
}

function sn2_family_key(array $canonical): string
{
    return implode('|', [
        sn2_norm(sn2_path_text($canonical['path'])),
        sn2_norm($canonical['type']),
        sn2_norm($canonical['style']),
    ]);
}

function sn2_family_display(array $canonical): string
{
    $path = array_values(array_filter($canonical['path']));
    $line = $path ? sn2_clean((string) end($path)) : '';
    $style = sn2_clean($canonical['style']);

    if ($line !== '' && ! str_contains(sn2_norm($style), sn2_norm($line))) {
        return sn2_clean('Sensationnel '.$line.' '.$style);
    }

    return sn2_clean('Sensationnel '.$style);
}

function sn2_sku_rows(HairExtensionIntake $intake): array
{
    $structure = $intake->variant_structure ?: [];
    $rows = [];

    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $row = [];
        if (sn2_clean($sku['main_value'] ?? '') !== '') {
            $row[] = [
                'axis' => sn2_clean($sku['main_axis'] ?? $structure['main_axis'] ?? 'Main'),
                'value' => sn2_variant_value($sku['main_value']),
            ];
        }
        if (sn2_clean($sku['sub_value'] ?? '') !== '') {
            $row[] = [
                'axis' => sn2_clean($sku['sub_axis'] ?? 'Sub'),
                'value' => sn2_variant_value($sku['sub_value']),
            ];
        }
        foreach (($sku['common_attributes'] ?? []) as $axis => $values) {
            foreach ((array) $values as $value) {
                if (sn2_clean($value) !== '') {
                    $row[] = ['axis' => sn2_clean($axis), 'value' => sn2_variant_value($value)];
                }
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }
    }

    return $rows ?: [[['axis' => 'Single', 'value' => 'Single product']]];
}

function sn2_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
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

function sn2_ensure_line(BrandCatalogueBrand $brand, array $path, bool $apply, array &$stats, array &$lineCache): BrandCatalogueLine
{
    $lineName = sn2_path_text($path) ?: $brand->name;
    $cacheKey = sn2_norm($lineName);

    if (isset($lineCache[$cacheKey])) {
        return $lineCache[$cacheKey];
    }

    $candidateNames = [$lineName, 'Sensationnel '.$lineName];
    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->whereIn(DB::raw('LOWER(name)'), array_map(fn (string $name): string => Str::lower($name), $candidateNames))
        ->orderByRaw('CASE WHEN LOWER(name) = ? THEN 0 ELSE 1 END', [Str::lower($lineName)])
        ->first();

    if ($line) {
        if (sn2_clean($line->name) !== $lineName) {
            $stats['lines_renamed']++;
            if ($apply) {
                $line->update([
                    'name' => $lineName,
                    'slug' => sn2_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', $brand->id, $lineName, $line->id),
                ]);

                ProductFamily::query()
                    ->where('brand_catalogue_line_id', $line->id)
                    ->update([
                        'line_name' => $lineName,
                        'updated_at' => now(),
                    ]);
            }
        }

        return $lineCache[$cacheKey] = $line->fresh();
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
            'sort_order' => 400 + $stats['lines_created'],
        ]);
    }

    return $lineCache[$cacheKey] = BrandCatalogueLine::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'name' => $lineName,
        'slug' => sn2_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', $brand->id, $lineName),
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 400 + $stats['lines_created'],
    ]);
}

function sn2_ensure_product_type(BrandCatalogueBrand $brand, BrandCatalogueLine $line, string $typeName, bool $apply, array &$stats, array &$typeCache): BrandCatalogueProductType
{
    $cacheKey = ((int) $line->id).'|'.sn2_norm($typeName);
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
            'sort_order' => 400 + $stats['product_types_created'],
        ]);
    }

    return $typeCache[$cacheKey] = BrandCatalogueProductType::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_line_id' => $line->id,
        'name' => $typeName,
        'slug' => sn2_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', $line->id, $typeName),
        'is_active' => true,
        'sort_order' => 400 + $stats['product_types_created'],
    ]);
}

function sn2_existing_style_id_for(array $family): ?int
{
    $manual = [
        'premiumnow|weave|eurostraight' => 149,
        'premiumtooblendhair|bulkhair|2xoceanwavebulk' => 133,
        'premiumplus|weave|tara27pcs' => 145,
        'premiumtoo|weave|multiloosetwist' => 162,
        'premiumtoohumanhair|bulkhair|deepwavebulk' => 137,
        'premiumtoohumanhair|weave|jerrycurl' => 141,
        'premiumtoohumanhair|weave|yakinaturalwvg' => 143,
    ];

    return $manual[sn2_family_key($family['canonical'])] ?? null;
}

function sn2_ensure_style(BrandCatalogueBrand $brand, BrandCatalogueProductType $type, array $family, bool $apply, array &$stats): BrandCatalogueStyle
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
        $shouldUpdateMaterial = sn2_clean($style->material_name) === '' || sn2_clean($style->material_name) === 'Review material';
        $changed = (int) $style->brand_catalogue_product_type_id !== (int) $type->id
            || sn2_clean($style->name) !== $styleName
            || $shouldUpdateMaterial;

        if ($changed) {
            $stats['styles_moved_or_renamed']++;
        }

        if ($apply && $changed) {
            $style->update([
                'brand_catalogue_brand_id' => $brand->id,
                'brand_catalogue_product_type_id' => $type->id,
                'name' => $styleName,
                'slug' => sn2_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $type->id, $styleName, $style->id),
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
                    'family_name' => sn2_family_display($family['canonical']),
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
        'slug' => sn2_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $type->id, $styleName),
        'material_name' => $material,
        'note' => 'Created from confirmed V2 shop-floor Sensationnel intake.',
        'is_active' => true,
        'sort_order' => 500 + $stats['styles_created'],
    ]);
}

function sn2_ensure_variant(BrandCatalogueStyle $style, string $axis, bool $apply, array &$stats): BrandCatalogueVariant
{
    $name = sn2_clean($axis) ?: 'Variant';
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
            'variant_type' => sn2_variant_type($name),
            'sort_order' => $stats['variants_created'] * 10,
        ]);
    }

    return BrandCatalogueVariant::query()->create([
        'brand_catalogue_style_id' => $style->id,
        'name' => $name,
        'variant_type' => sn2_variant_type($name),
        'sort_order' => $stats['variants_created'] * 10,
    ]);
}

function sn2_ensure_option(BrandCatalogueVariant $variant, string $value, bool $apply, array &$stats): BrandCatalogueVariantOption
{
    $value = sn2_variant_value($value);
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

function sn2_signature(Collection $options): string
{
    $parts = $options
        ->map(fn (BrandCatalogueVariantOption $option): string => $option->variant_id.':'.$option->id)
        ->values()
        ->all();

    sort($parts, SORT_NATURAL);

    return implode('|', $parts);
}

function sn2_sku_name(array $canonical, array $row): string
{
    $parts = [sn2_family_display($canonical)];
    foreach ($row as $part) {
        if (sn2_norm($part['axis']) === 'single') {
            continue;
        }
        $parts[] = sn2_clean($part['axis']).' '.$part['value'];
    }

    return sn2_clean(implode(' - ', $parts));
}

function sn2_backup_sensationnel(BrandCatalogueBrand $brand, string $timestamp): string
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
        'intakes' => DB::table('hair_extension_intakes')->where('brand_name', 'Sensationnel')->get(),
        'product_families' => DB::table('product_families')->where('brand_catalogue_brand_id', $brand->id)->orWhere('brand_name', 'Sensationnel')->get(),
    ];

    $path = "catalogue-backups/sensationnel-v2-sync-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function sn2_normalize_prefixed_lines(BrandCatalogueBrand $brand, bool $apply, array &$stats): void
{
    BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', 'like', 'Sensationnel %')
        ->orderBy('id')
        ->get()
        ->each(function (BrandCatalogueLine $line) use ($brand, $apply, &$stats): void {
            $lineName = sn2_clean(preg_replace('/^Sensationnel\s+/i', '', $line->name) ?? $line->name);
            if ($lineName === '' || $lineName === sn2_clean($line->name)) {
                return;
            }

            $duplicate = BrandCatalogueLine::query()
                ->where('brand_catalogue_brand_id', $brand->id)
                ->where('id', '!=', $line->id)
                ->whereRaw('LOWER(name) = ?', [Str::lower($lineName)])
                ->exists();

            if ($duplicate) {
                $stats['line_renames_skipped']++;

                return;
            }

            $stats['lines_renamed']++;

            if (! $apply) {
                return;
            }

            $line->update([
                'name' => $lineName,
                'slug' => sn2_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', $brand->id, $lineName, $line->id),
            ]);

            ProductFamily::query()
                ->where('brand_catalogue_line_id', $line->id)
                ->update([
                    'line_name' => $lineName,
                    'updated_at' => now(),
                ]);
        });
}

$brand = BrandCatalogueBrand::query()
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Sensationnel')
    ->firstOrFail();

$intakes = HairExtensionIntake::query()
    ->where('status', 'submitted')
    ->where('brand_name', 'Sensationnel')
    ->orderBy('id')
    ->get();

$families = [];
foreach ($intakes as $intake) {
    $canonical = sn2_canonical_for($intake);
    $key = sn2_family_key($canonical);

    $families[$key] ??= [
        'canonical' => $canonical,
        'intakes' => collect(),
        'sku_rows' => [],
    ];
    $families[$key]['intakes']->push($intake);

    foreach (sn2_sku_rows($intake) as $row) {
        $rowKey = collect($row)
            ->map(fn (array $part): string => sn2_norm($part['axis']).'='.sn2_norm($part['value']))
            ->implode('|');
        $families[$key]['sku_rows'][$rowKey] = $row;
    }
}

$stats = [
    'families' => count($families),
    'intakes_linked' => 0,
    'lines_created' => 0,
    'lines_renamed' => 0,
    'line_renames_skipped' => 0,
    'product_types_created' => 0,
    'styles_created' => 0,
    'styles_moved_or_renamed' => 0,
    'variants_created' => 0,
    'options_created' => 0,
    'skus_created' => 0,
    'skus_updated' => 0,
    'backup' => null,
];

$rows = [];

if ($apply) {
    $stats['backup'] = sn2_backup_sensationnel($brand, $timestamp);
}

DB::transaction(function () use ($apply, $brand, &$families, &$stats, &$rows): void {
    $lineCache = [];
    $typeCache = [];

    sn2_normalize_prefixed_lines($brand, $apply, $stats);

    foreach ($families as &$family) {
        /** @var Collection<int, HairExtensionIntake> $familyIntakes */
        $familyIntakes = $family['intakes'];
        $family['existing_style_id'] = sn2_existing_style_id_for($family);

        $line = sn2_ensure_line($brand, $family['canonical']['path'], $apply, $stats, $lineCache);
        $type = sn2_ensure_product_type($brand, $line, $family['canonical']['type'], $apply, $stats, $typeCache);
        $type->setRelation('line', $line);
        $style = sn2_ensure_style($brand, $type, $family, $apply, $stats);

        $createdSkuCountBefore = $stats['skus_created'];
        $createdOptionCountBefore = $stats['options_created'];
        $createdVariantCountBefore = $stats['variants_created'];

        foreach ($family['sku_rows'] as $skuRow) {
            $options = collect();
            foreach ($skuRow as $part) {
                if (sn2_clean($part['value']) === '') {
                    continue;
                }

                $variant = sn2_ensure_variant($style, $part['axis'], $apply, $stats);
                $option = sn2_ensure_option($variant, $part['value'], $apply, $stats);
                $options->push($option);
            }

            if ($options->isEmpty() || ! $apply) {
                continue;
            }

            $options = $options
                ->unique(fn (BrandCatalogueVariantOption $option): int => (int) $option->variant_id)
                ->values();

            $signature = sn2_signature($options);
            $skuName = sn2_sku_name($family['canonical'], $skuRow);
            $sku = BrandCatalogueSku::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->where('option_signature', $signature)
                ->first();

            if ($sku) {
                $sku->update([
                    'name' => $skuName,
                    'slug' => sn2_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $skuName, $sku->id),
                    'is_active' => true,
                ]);
                $stats['skus_updated']++;
            } else {
                $sku = BrandCatalogueSku::query()->create([
                    'brand_catalogue_style_id' => $style->id,
                    'name' => $skuName,
                    'slug' => sn2_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $skuName),
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
            'canonical_key' => sn2_family_key($family['canonical']),
            'brand' => 'Sensationnel',
            'grouping_path' => sn2_path_text($family['canonical']['path']),
            'product_type' => $family['canonical']['type'],
            'style_family' => $family['canonical']['style'],
            'display_family' => sn2_family_display($family['canonical']),
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
