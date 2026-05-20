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

$csvPath = $reportDir."/sleek-v2-catalogue-sync-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/sleek-v2-catalogue-sync-latest.csv';

function sv2_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function sv2_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function sv2_path(mixed $path): array
{
    if (! is_array($path)) {
        return [];
    }

    $parts = [];
    foreach ($path as $value) {
        foreach (explode('>', (string) $value) as $part) {
            $part = sv2_clean($part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
    }

    return array_values(array_filter($parts, fn (string $part): bool => sv2_norm($part) !== 'sleek'));
}

function sv2_path_text(array $path): string
{
    return implode(' > ', array_filter(array_map('sv2_clean', $path)));
}

function sv2_style_title(string $value): string
{
    $value = sv2_clean($value);
    $map = [
        'BOOTYLICIOUS' => 'Bootylicious',
        'BOUNCE' => 'Bounce',
        'BELLE' => 'Belle',
        'DIZZY' => 'Dizzy',
        'GLAMOR' => 'Glamor',
        'JUMBO AFRO' => 'Jumbo Afro',
        'NEW AFRO' => 'New Afro',
        'POSH' => 'Posh',
        'REBEL' => 'Rebel',
        'RELISH' => 'Relish',
        'TWIRL' => 'Twirl',
        'COSMOS' => 'Cosmos',
        'JASMINE' => 'Jasmine',
        'EW INDIAN' => 'EW Indian',
        'HOT YAKI' => 'Hot Yaki Weave',
        'HOT YAKI WEAVE' => 'Hot Yaki Weave',
        'RC SILKY WEAVE' => 'Remy Couture Silky Weave',
        'RC STICK TIP' => 'Remy Couture Pre-Bonded Stick Tip',
        'STYLE ICON VIRGIN REMY SILKY' => 'Style Icon Remy Silky Weave',
    ];

    return $map[Str::upper($value)] ?? Str::title(Str::lower($value));
}

function sv2_canonical_for(HairExtensionIntake $intake): array
{
    $path = sv2_path($intake->classification_path);
    $pathText = sv2_norm(sv2_path_text($path));
    $styleRaw = sv2_clean($intake->style_name ?: $intake->observed_product_name);
    $styleKey = sv2_norm($styleRaw);

    if (in_array($styleKey, ['hotyaki', 'hotyakiweave'], true) || str_contains($pathText, 'fashionidol101')) {
        return [
            'path' => ['Fashion Idol 101'],
            'type' => 'Weave',
            'style' => 'Hot Yaki Weave',
            'material' => 'Synthetic Hair',
        ];
    }

    if (str_contains($styleKey, 'frenchcurl') || str_contains($pathText, 'fashionidolexpress')) {
        return [
            'path' => ['Fashion Idol Express'],
            'type' => 'Braid',
            'style' => 'French Curl Braid',
            'material' => 'Synthetic Hair',
        ];
    }

    if (str_contains($pathText, 'haircouture') && str_contains($pathText, 'ponytail')) {
        $style = str_contains($styleKey, 'jasmin') ? 'Jasmine' : (str_contains($styleKey, 'cosmos') ? 'Cosmos' : sv2_style_title($styleRaw));

        return [
            'path' => ['Hair Couture'],
            'type' => 'Ponytails',
            'style' => $style,
            'material' => 'Synthetic Hair',
        ];
    }

    if (str_contains($pathText, 'haircouture') && (str_contains($pathText, 'hairextensions') || str_contains($styleKey, 'silkystraight'))) {
        return [
            'path' => ['Hair Couture'],
            'type' => 'Synthetic Clip-Ins',
            'style' => 'Silky Straight',
            'material' => 'Synthetic Hair',
        ];
    }

    if (str_contains($pathText, 'ezponytail') || str_contains($pathText, 'hairponytail')) {
        if ($styleKey === 'permyaki') {
            return [
                'path' => ['Remy Gorgeous'],
                'type' => 'Weave',
                'style' => 'Perm Yaki',
                'material' => 'Synthetic Hair',
            ];
        }

        $style = preg_replace('/^101\s+/i', '', $styleRaw) ?? $styleRaw;
        $style = preg_replace('/\s+EZ\s+Pony$/i', '', $style) ?? $style;
        $style = preg_replace('/\s+EZ\s+PONY$/i', '', $style) ?? $style;
        $style = preg_replace('/^NEW\s+AFRO\s+EZ\s+PONY$/i', 'New Afro', $style) ?? $style;
        $style = preg_replace('/^JUMBO\s+AFRO\s+EZ\s+PONY$/i', 'Jumbo Afro', $style) ?? $style;

        return [
            'path' => ['eZ Synthetic Hair Accessories', 'eZ Ponytail'],
            'type' => 'Ponytail',
            'style' => sv2_style_title($style),
            'material' => 'Synthetic Hair',
        ];
    }

    if (str_contains($pathText, 'europeanweave') || in_array($styleKey, ['ewindian'], true)) {
        return [
            'path' => ['European Weave'],
            'type' => 'Weave',
            'style' => 'EW Indian',
            'material' => 'Human Hair',
        ];
    }

    if ($styleKey === 'rcsticktip') {
        return [
            'path' => ['Remy Couture'],
            'type' => 'Stick Tip Extensions',
            'style' => 'Remy Couture Pre-Bonded Stick Tip',
            'material' => 'Human Hair',
        ];
    }

    if (str_contains($styleKey, 'styleicon') || str_contains($pathText, 'styleicon')) {
        return [
            'path' => ['Style Icon'],
            'type' => 'Weave',
            'style' => 'Style Icon Remy Silky Weave',
            'material' => 'Human Hair',
        ];
    }

    if (str_contains($styleKey, 'rcsilky') || str_contains($pathText, 'virginremyhair')) {
        return [
            'path' => ['Remy Couture'],
            'type' => 'Weave',
            'style' => 'Remy Couture Silky Weave',
            'material' => 'Human Hair',
        ];
    }

    return [
        'path' => $path ?: ['Sleek'],
        'type' => sv2_clean($intake->product_type_name) ?: 'Hair Extension',
        'style' => sv2_style_title($styleRaw),
        'material' => 'Review material',
    ];
}

function sv2_family_key(array $canonical): string
{
    return implode('|', [
        sv2_norm(sv2_path_text($canonical['path'])),
        sv2_norm($canonical['type']),
        sv2_norm($canonical['style']),
    ]);
}

function sv2_family_display(array $canonical): string
{
    $path = array_values(array_filter($canonical['path']));
    $style = sv2_clean($canonical['style']);
    $line = $path ? sv2_clean((string) end($path)) : '';

    if (str_contains(sv2_norm(sv2_path_text($path)), 'ezponytail')) {
        return sv2_clean('Sleek eZ Ponytail '.$style);
    }

    if ($path && sv2_norm($path[0]) === 'haircouture') {
        return sv2_clean('Sleek Hair Couture '.$style);
    }

    if ($path && ! str_contains(sv2_norm($style), sv2_norm($line))) {
        return sv2_clean('Sleek '.$line.' '.$style);
    }

    return sv2_clean('Sleek '.$style);
}

function sv2_variant_type(string $axis): string
{
    $key = sv2_norm($axis);

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

function sv2_variant_value(mixed $value): string
{
    $value = sv2_clean($value);
    $value = str_ireplace(['NICHES', 'INCHS'], 'INCHES', $value);
    $value = preg_replace('/^(\d+)\s*(?:\"|in|inch|inches)$/i', '$1"', $value) ?? $value;
    $value = preg_replace('/^(\d+)\s+INCHES$/i', '$1"', $value) ?? $value;

    return sv2_clean($value);
}

function sv2_sku_rows(HairExtensionIntake $intake): array
{
    $structure = $intake->variant_structure ?: [];
    $rows = [];

    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $row = [];
        if (sv2_clean($sku['main_value'] ?? '') !== '') {
            $row[] = [
                'axis' => sv2_clean($sku['main_axis'] ?? $structure['main_axis'] ?? 'Main'),
                'value' => sv2_variant_value($sku['main_value']),
            ];
        }
        if (sv2_clean($sku['sub_value'] ?? '') !== '') {
            $row[] = [
                'axis' => sv2_clean($sku['sub_axis'] ?? 'Sub'),
                'value' => sv2_variant_value($sku['sub_value']),
            ];
        }
        foreach (($sku['common_attributes'] ?? []) as $axis => $values) {
            foreach ((array) $values as $value) {
                if (sv2_clean($value) !== '') {
                    $row[] = ['axis' => sv2_clean($axis), 'value' => sv2_variant_value($value)];
                }
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }
    }

    return $rows ?: [[['axis' => 'Single', 'value' => 'Single product']]];
}

function sv2_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
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

function sv2_ensure_line(BrandCatalogueBrand $brand, array $path, bool $apply, array &$stats, array &$lineCache): BrandCatalogueLine
{
    $lineName = sv2_path_text($path) ?: $brand->name;
    $cacheKey = sv2_norm($lineName);

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
            'sort_order' => 200 + $stats['lines_created'],
        ]);
    }

    return $lineCache[$cacheKey] = BrandCatalogueLine::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'name' => $lineName,
        'slug' => sv2_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', $brand->id, $lineName),
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 200 + $stats['lines_created'],
    ]);
}

function sv2_ensure_product_type(BrandCatalogueBrand $brand, BrandCatalogueLine $line, string $typeName, bool $apply, array &$stats, array &$typeCache): BrandCatalogueProductType
{
    $cacheKey = ((int) $line->id).'|'.sv2_norm($typeName);
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
            'sort_order' => 200 + $stats['product_types_created'],
        ]);
    }

    return $typeCache[$cacheKey] = BrandCatalogueProductType::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_line_id' => $line->id,
        'name' => $typeName,
        'slug' => sv2_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', $line->id, $typeName),
        'is_active' => true,
        'sort_order' => 200 + $stats['product_types_created'],
    ]);
}

function sv2_existing_style_id_for(array $family): ?int
{
    $manual = [
        'fashionidol101|weave|hotyakiweave' => 540,
        'fashionidolexpress|braid|frenchcurlbraid' => 19,
        'europeanweave|weave|ewindian' => 595,
        'remycouture|sticktipextensions|remycoutureprebondedsticktip' => 537,
        'styleicon|weave|styleiconremysilkyweave' => 535,
        'remycouture|weave|remycouturesilkyweave' => 536,
        'remygorgeous|weave|permyaki' => 623,
    ];

    return $manual[sv2_family_key($family['canonical'])] ?? null;
}

function sv2_ensure_style(BrandCatalogueBrand $brand, BrandCatalogueProductType $type, array $family, bool $apply, array &$stats): BrandCatalogueStyle
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
        $changed = (int) $style->brand_catalogue_product_type_id !== (int) $type->id
            || sv2_clean($style->name) !== $styleName
            || sv2_clean($style->material_name) === ''
            || sv2_clean($style->material_name) === 'Review material';

        if ($changed) {
            $stats['styles_moved_or_renamed']++;
        }

        if ($apply && $changed) {
            $style->update([
                'brand_catalogue_brand_id' => $brand->id,
                'brand_catalogue_product_type_id' => $type->id,
                'name' => $styleName,
                'slug' => sv2_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $type->id, $styleName, $style->id),
                'material_name' => $material,
                'is_active' => true,
            ]);

            ProductFamily::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->update([
                    'brand_catalogue_line_id' => $type->brand_catalogue_line_id,
                    'brand_catalogue_product_type_id' => $type->id,
                    'line_name' => $type->line?->name,
                    'product_type_name' => $type->name,
                    'family_name' => sv2_family_display($family['canonical']),
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
        'slug' => sv2_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $type->id, $styleName),
        'material_name' => $material,
        'note' => 'Created from confirmed V2 shop-floor Sleek intake.',
        'is_active' => true,
        'sort_order' => 300 + $stats['styles_created'],
    ]);
}

function sv2_ensure_variant(BrandCatalogueStyle $style, string $axis, bool $apply, array &$stats): BrandCatalogueVariant
{
    $name = sv2_clean($axis) ?: 'Variant';
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
            'variant_type' => sv2_variant_type($name),
            'sort_order' => $stats['variants_created'] * 10,
        ]);
    }

    return BrandCatalogueVariant::query()->create([
        'brand_catalogue_style_id' => $style->id,
        'name' => $name,
        'variant_type' => sv2_variant_type($name),
        'sort_order' => $stats['variants_created'] * 10,
    ]);
}

function sv2_ensure_option(BrandCatalogueVariant $variant, string $value, bool $apply, array &$stats): BrandCatalogueVariantOption
{
    $value = sv2_variant_value($value);
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

function sv2_signature(Collection $options): string
{
    $parts = $options
        ->map(fn (BrandCatalogueVariantOption $option): string => $option->variant_id.':'.$option->id)
        ->values()
        ->all();

    sort($parts, SORT_NATURAL);

    return implode('|', $parts);
}

function sv2_sku_name(array $canonical, array $row): string
{
    $parts = [sv2_family_display($canonical)];
    foreach ($row as $part) {
        if (sv2_norm($part['axis']) === 'single') {
            continue;
        }
        $parts[] = sv2_clean($part['axis']).' '.$part['value'];
    }

    return sv2_clean(implode(' - ', $parts));
}

function sv2_backup_sleek(BrandCatalogueBrand $brand, string $timestamp): string
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
        'intakes' => DB::table('hair_extension_intakes')->where('brand_name', 'Sleek')->get(),
        'product_families' => DB::table('product_families')->where('brand_catalogue_brand_id', $brand->id)->orWhere('brand_name', 'Sleek')->get(),
    ];

    $path = "catalogue-backups/sleek-v2-sync-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

$brand = BrandCatalogueBrand::query()
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Sleek')
    ->firstOrFail();

$intakes = HairExtensionIntake::query()
    ->where('status', 'submitted')
    ->where('brand_name', 'Sleek')
    ->orderBy('id')
    ->get();

$families = [];
foreach ($intakes as $intake) {
    $canonical = sv2_canonical_for($intake);
    $key = sv2_family_key($canonical);

    $families[$key] ??= [
        'canonical' => $canonical,
        'intakes' => collect(),
        'sku_rows' => [],
    ];
    $families[$key]['intakes']->push($intake);

    foreach (sv2_sku_rows($intake) as $row) {
        $rowKey = collect($row)
            ->map(fn (array $part): string => sv2_norm($part['axis']).'='.sv2_norm($part['value']))
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
    $stats['backup'] = sv2_backup_sleek($brand, $timestamp);
}

DB::transaction(function () use ($apply, $brand, &$families, &$stats, &$rows): void {
    $lineCache = [];
    $typeCache = [];

    foreach ($families as &$family) {
        /** @var Collection<int, HairExtensionIntake> $familyIntakes */
        $familyIntakes = $family['intakes'];
        $family['existing_style_id'] = sv2_existing_style_id_for($family);

        $line = sv2_ensure_line($brand, $family['canonical']['path'], $apply, $stats, $lineCache);
        $type = sv2_ensure_product_type($brand, $line, $family['canonical']['type'], $apply, $stats, $typeCache);
        $type->setRelation('line', $line);
        $style = sv2_ensure_style($brand, $type, $family, $apply, $stats);

        $createdSkuCountBefore = $stats['skus_created'];
        $createdOptionCountBefore = $stats['options_created'];
        $createdVariantCountBefore = $stats['variants_created'];

        foreach ($family['sku_rows'] as $skuRow) {
            $options = collect();
            foreach ($skuRow as $part) {
                if (sv2_clean($part['value']) === '') {
                    continue;
                }

                $variant = sv2_ensure_variant($style, $part['axis'], $apply, $stats);
                $option = sv2_ensure_option($variant, $part['value'], $apply, $stats);
                $options->push($option);
            }

            if ($options->isEmpty() || ! $apply) {
                continue;
            }

            $options = $options
                ->unique(fn (BrandCatalogueVariantOption $option): int => (int) $option->variant_id)
                ->values();

            $signature = sv2_signature($options);
            $skuName = sv2_sku_name($family['canonical'], $skuRow);
            $sku = BrandCatalogueSku::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->where('option_signature', $signature)
                ->first();

            if ($sku) {
                $sku->update([
                    'name' => $skuName,
                    'slug' => sv2_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $skuName, $sku->id),
                    'is_active' => true,
                ]);
                $stats['skus_updated']++;
            } else {
                $sku = BrandCatalogueSku::query()->create([
                    'brand_catalogue_style_id' => $style->id,
                    'name' => $skuName,
                    'slug' => sv2_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $skuName),
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
            'canonical_key' => sv2_family_key($family['canonical']),
            'brand' => 'Sleek',
            'grouping_path' => sv2_path_text($family['canonical']['path']),
            'product_type' => $family['canonical']['type'],
            'style_family' => $family['canonical']['style'],
            'display_family' => sv2_family_display($family['canonical']),
            'material' => $family['canonical']['material'],
            'intake_ids' => $familyIntakes->pluck('id')->implode(', '),
            'style_id' => $style->id,
            'style_source' => $family['existing_style_id'] ? 'existing' : ($apply ? 'created' : 'would_create'),
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
