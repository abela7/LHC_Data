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

$csvPath = $reportDir."/sleek-noble-gold-v2-catalogue-sync-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/sleek-noble-gold-v2-catalogue-sync-latest.csv';

function sng_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function sng_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function sng_variant_type(string $axis): string
{
    $key = sng_norm($axis);

    if (str_contains($key, 'length')) {
        return 'measurement';
    }
    if (str_contains($key, 'colour') || str_contains($key, 'color')) {
        return 'colour_code';
    }
    if (str_contains($key, 'pack') || str_contains($key, 'bundle') || str_contains($key, 'count')) {
        return 'count';
    }

    return 'text';
}

function sng_variant_value(mixed $value): string
{
    $value = sng_clean($value);
    $value = str_ireplace(['NICHES', 'INCHS'], 'INCHES', $value);
    $value = preg_replace('/^(\d+)\s*(?:\"|in|inch|inches)$/i', '$1"', $value) ?? $value;
    $value = preg_replace('/^(\d+)\s+INCHES$/i', '$1"', $value) ?? $value;

    return sng_clean($value);
}

function sng_canonical_for(HairExtensionIntake $intake): array
{
    $styleKey = sng_norm($intake->style_name ?: $intake->observed_product_name);

    return match ($styleKey) {
        'bigbouncecurl' => [
            'path' => ['Noble / Noble Gold'],
            'type' => 'Weave',
            'style' => 'Big Bounce Curl',
            'material' => 'Synthetic Hair',
            'existing_style_id' => 557,
        ],
        'bigwaterweave', 'bigwater' => [
            'path' => ['Noble / Noble Gold'],
            'type' => 'Weave',
            'style' => 'Big Water',
            'material' => 'Synthetic Hair',
            'existing_style_id' => 563,
        ],
        'superbohemian' => [
            'path' => ['Noble / Noble Gold'],
            'type' => 'Weave',
            'style' => 'Super Bohemian',
            'material' => 'Synthetic Hair',
            'existing_style_id' => 4165,
        ],
        'kinkybulk' => [
            'path' => ['Noble / Noble Gold'],
            'type' => 'Bulk Hair',
            'style' => 'Noble Kinky Bulk',
            'material' => 'Synthetic Hair',
            'existing_style_id' => 562,
        ],
        'naturejany' => [
            'path' => ['Noble / Noble Gold'],
            'type' => 'Weave',
            'style' => 'Nature Jany',
            'material' => 'Synthetic Hair',
            'existing_style_id' => null,
        ],
        'naturesasy' => [
            'path' => ['Noble / Noble Gold'],
            'type' => 'Weave',
            'style' => 'Nature Sasy',
            'material' => 'Synthetic Hair',
            'existing_style_id' => null,
        ],
        default => [
            'path' => ['Noble / Noble Gold'],
            'type' => sng_clean($intake->product_type_name) ?: 'Hair Extension',
            'style' => Str::title(Str::lower(sng_clean($intake->style_name ?: $intake->observed_product_name))),
            'material' => 'Synthetic Hair',
            'existing_style_id' => null,
        ],
    };
}

function sng_path_text(array $path): string
{
    return implode(' > ', array_filter(array_map('sng_clean', $path)));
}

function sng_family_key(array $canonical): string
{
    return implode('|', [
        sng_norm(sng_path_text($canonical['path'])),
        sng_norm($canonical['type']),
        sng_norm($canonical['style']),
    ]);
}

function sng_family_display(array $canonical): string
{
    return sng_clean('Sleek Noble / Noble Gold '.$canonical['style']);
}

function sng_sku_rows(HairExtensionIntake $intake): array
{
    $structure = $intake->variant_structure ?: [];
    $rows = [];

    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $row = [];
        if (sng_clean($sku['main_value'] ?? '') !== '') {
            $row[] = [
                'axis' => sng_clean($sku['main_axis'] ?? $structure['main_axis'] ?? 'Main'),
                'value' => sng_variant_value($sku['main_value']),
            ];
        }
        if (sng_clean($sku['sub_value'] ?? '') !== '') {
            $row[] = [
                'axis' => sng_clean($sku['sub_axis'] ?? 'Sub'),
                'value' => sng_variant_value($sku['sub_value']),
            ];
        }
        foreach (($sku['common_attributes'] ?? []) as $axis => $values) {
            foreach ((array) $values as $value) {
                if (sng_clean($value) !== '') {
                    $row[] = ['axis' => sng_clean($axis), 'value' => sng_variant_value($value)];
                }
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }
    }

    return $rows ?: [[['axis' => 'Single', 'value' => 'Single product']]];
}

function sng_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
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

function sng_ensure_line(BrandCatalogueBrand $brand, string $lineName, bool $apply, array &$stats): BrandCatalogueLine
{
    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->whereRaw('LOWER(name) = ?', [Str::lower($lineName)])
        ->first();

    if ($line) {
        return $line;
    }

    $stats['lines_created']++;

    if (! $apply) {
        return new BrandCatalogueLine([
            'id' => -1,
            'brand_catalogue_brand_id' => $brand->id,
            'name' => $lineName,
            'slug' => Str::slug($lineName),
            'is_active' => true,
        ]);
    }

    return BrandCatalogueLine::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'name' => $lineName,
        'slug' => sng_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', $brand->id, $lineName),
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 240,
    ]);
}

function sng_ensure_product_type(BrandCatalogueBrand $brand, BrandCatalogueLine $line, string $typeName, bool $apply, array &$stats): BrandCatalogueProductType
{
    $type = BrandCatalogueProductType::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('brand_catalogue_line_id', $line->id)
        ->whereRaw('LOWER(name) = ?', [Str::lower($typeName)])
        ->first();

    if ($type) {
        return $type;
    }

    $stats['product_types_created']++;

    if (! $apply) {
        return new BrandCatalogueProductType([
            'id' => -1 * $stats['product_types_created'],
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_line_id' => $line->id,
            'name' => $typeName,
            'slug' => Str::slug($typeName),
            'is_active' => true,
        ]);
    }

    return BrandCatalogueProductType::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_line_id' => $line->id,
        'name' => $typeName,
        'slug' => sng_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', $line->id, $typeName),
        'is_active' => true,
        'sort_order' => 240 + $stats['product_types_created'],
    ]);
}

function sng_ensure_style(BrandCatalogueBrand $brand, BrandCatalogueProductType $type, array $canonical, bool $apply, array &$stats): BrandCatalogueStyle
{
    $style = $canonical['existing_style_id'] ? BrandCatalogueStyle::query()->find($canonical['existing_style_id']) : null;
    $styleName = $canonical['style'];

    if (! $style) {
        $style = BrandCatalogueStyle::query()
            ->where('brand_catalogue_brand_id', $brand->id)
            ->where('brand_catalogue_product_type_id', $type->id)
            ->whereRaw('LOWER(name) = ?', [Str::lower($styleName)])
            ->first();
    }

    if ($style) {
        $changed = (int) $style->brand_catalogue_product_type_id !== (int) $type->id
            || sng_clean($style->name) !== $styleName
            || sng_clean($style->material_name) === ''
            || sng_clean($style->material_name) === 'Review material';

        if ($changed) {
            $stats['styles_moved_or_renamed']++;
        }

        if ($apply && $changed) {
            $style->update([
                'brand_catalogue_brand_id' => $brand->id,
                'brand_catalogue_product_type_id' => $type->id,
                'name' => $styleName,
                'slug' => sng_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $type->id, $styleName, $style->id),
                'material_name' => $canonical['material'],
                'is_active' => true,
            ]);

            ProductFamily::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->update([
                    'brand_catalogue_brand_id' => $brand->id,
                    'brand_catalogue_line_id' => $type->brand_catalogue_line_id,
                    'brand_catalogue_product_type_id' => $type->id,
                    'brand_name' => $brand->name,
                    'line_name' => $type->line?->name,
                    'product_type_name' => $type->name,
                    'family_name' => sng_family_display($canonical),
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
            'material_name' => $canonical['material'],
            'is_active' => true,
        ]);
    }

    return BrandCatalogueStyle::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_product_type_id' => $type->id,
        'name' => $styleName,
        'slug' => sng_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $type->id, $styleName),
        'material_name' => $canonical['material'],
        'note' => 'Created from Noble Gold V2 shop-floor intake under Sleek Noble / Noble Gold.',
        'is_active' => true,
        'sort_order' => 360 + $stats['styles_created'],
    ]);
}

function sng_ensure_variant(BrandCatalogueStyle $style, string $axis, bool $apply, array &$stats): BrandCatalogueVariant
{
    $name = sng_clean($axis) ?: 'Variant';
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
            'variant_type' => sng_variant_type($name),
            'sort_order' => $stats['variants_created'] * 10,
        ]);
    }

    return BrandCatalogueVariant::query()->create([
        'brand_catalogue_style_id' => $style->id,
        'name' => $name,
        'variant_type' => sng_variant_type($name),
        'sort_order' => $stats['variants_created'] * 10,
    ]);
}

function sng_ensure_option(BrandCatalogueVariant $variant, string $value, bool $apply, array &$stats): BrandCatalogueVariantOption
{
    $value = sng_variant_value($value);
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

function sng_signature(Collection $options): string
{
    $parts = $options
        ->map(fn (BrandCatalogueVariantOption $option): string => $option->variant_id.':'.$option->id)
        ->values()
        ->all();

    sort($parts, SORT_NATURAL);

    return implode('|', $parts);
}

function sng_sku_name(array $canonical, array $row): string
{
    $parts = [sng_family_display($canonical)];
    foreach ($row as $part) {
        if (sng_norm($part['axis']) === 'single') {
            continue;
        }
        $parts[] = sng_clean($part['axis']).' '.$part['value'];
    }

    return sng_clean(implode(' - ', $parts));
}

function sng_backup(BrandCatalogueBrand $brand, string $timestamp): string
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
        'noble_gold_intakes' => DB::table('hair_extension_intakes')->where('brand_name', 'Noble Gold')->get(),
        'sleek_intakes' => DB::table('hair_extension_intakes')->where('brand_name', 'Sleek')->get(),
        'product_families' => DB::table('product_families')->where('brand_catalogue_brand_id', $brand->id)->orWhereIn('brand_name', ['Sleek', 'Noble Gold'])->get(),
    ];

    $path = "catalogue-backups/sleek-noble-gold-v2-sync-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

$brand = BrandCatalogueBrand::query()
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Sleek')
    ->firstOrFail();

$intakes = HairExtensionIntake::query()
    ->where('status', 'submitted')
    ->where('brand_name', 'Noble Gold')
    ->orderBy('id')
    ->get();

$families = [];
foreach ($intakes as $intake) {
    $canonical = sng_canonical_for($intake);
    $key = sng_family_key($canonical);

    $families[$key] ??= [
        'canonical' => $canonical,
        'intakes' => collect(),
        'sku_rows' => [],
    ];
    $families[$key]['intakes']->push($intake);

    foreach (sng_sku_rows($intake) as $row) {
        $rowKey = collect($row)
            ->map(fn (array $part): string => sng_norm($part['axis']).'='.sng_norm($part['value']))
            ->implode('|');
        $families[$key]['sku_rows'][$rowKey] = $row;
    }
}

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
    'backup' => null,
];

$rows = [];

if ($apply) {
    $stats['backup'] = sng_backup($brand, $timestamp);
}

DB::transaction(function () use ($apply, $brand, &$families, &$stats, &$rows): void {
    $line = sng_ensure_line($brand, 'Noble / Noble Gold', $apply, $stats);
    $typeCache = [];

    foreach ($families as $family) {
        $canonical = $family['canonical'];
        /** @var Collection<int, HairExtensionIntake> $familyIntakes */
        $familyIntakes = $family['intakes'];

        $typeKey = sng_norm($canonical['type']);
        if (! isset($typeCache[$typeKey])) {
            $typeCache[$typeKey] = sng_ensure_product_type($brand, $line, $canonical['type'], $apply, $stats);
            $typeCache[$typeKey]->setRelation('line', $line);
        }
        $type = $typeCache[$typeKey];
        $style = sng_ensure_style($brand, $type, $canonical, $apply, $stats);

        $createdSkuCountBefore = $stats['skus_created'];
        $createdOptionCountBefore = $stats['options_created'];
        $createdVariantCountBefore = $stats['variants_created'];

        foreach ($family['sku_rows'] as $skuRow) {
            $options = collect();
            foreach ($skuRow as $part) {
                if (sng_clean($part['value']) === '') {
                    continue;
                }

                $variant = sng_ensure_variant($style, $part['axis'], $apply, $stats);
                $option = sng_ensure_option($variant, $part['value'], $apply, $stats);
                $options->push($option);
            }

            if ($options->isEmpty() || ! $apply) {
                continue;
            }

            $options = $options
                ->unique(fn (BrandCatalogueVariantOption $option): int => (int) $option->variant_id)
                ->values();

            $signature = sng_signature($options);
            $skuName = sng_sku_name($canonical, $skuRow);
            $sku = BrandCatalogueSku::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->where('option_signature', $signature)
                ->first();

            if ($sku) {
                $sku->update([
                    'name' => $skuName,
                    'slug' => sng_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $skuName, $sku->id),
                    'is_active' => true,
                ]);
                $stats['skus_updated']++;
            } else {
                $sku = BrandCatalogueSku::query()->create([
                    'brand_catalogue_style_id' => $style->id,
                    'name' => $skuName,
                    'slug' => sng_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $skuName),
                    'option_signature' => $signature,
                    'description' => 'Observed in Noble Gold V2 shop-floor intake.',
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
                    'brand_name' => 'Sleek',
                    'classification_path' => $canonical['path'],
                    'product_type_name' => $canonical['type'],
                    'style_name' => $canonical['style'],
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
            'canonical_key' => sng_family_key($canonical),
            'brand' => 'Sleek',
            'previous_intake_brand' => 'Noble Gold',
            'grouping_path' => sng_path_text($canonical['path']),
            'product_type' => $canonical['type'],
            'style_family' => $canonical['style'],
            'display_family' => sng_family_display($canonical),
            'material' => $canonical['material'],
            'intake_ids' => $familyIntakes->pluck('id')->implode(', '),
            'style_id' => $style->id,
            'style_source' => $canonical['existing_style_id'] ? 'existing' : ($apply ? 'created' : 'would_create'),
            'sku_rows_from_v2' => count($family['sku_rows']),
            'variants_created_for_family' => $stats['variants_created'] - $createdVariantCountBefore,
            'options_created_for_family' => $stats['options_created'] - $createdOptionCountBefore,
            'skus_created_for_family' => $stats['skus_created'] - $createdSkuCountBefore,
            'applied' => $apply ? 'yes' : 'no',
        ];
    }
});

$csv = fopen($csvPath, 'w');
fputcsv($csv, [
    'canonical_key',
    'brand',
    'previous_intake_brand',
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
