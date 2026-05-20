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

$csvPath = $reportDir."/cherish-v2-catalogue-sync-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/cherish-v2-catalogue-sync-latest.csv';

function cv2_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function cv2_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function cv2_title(string $value): string
{
    $value = cv2_clean($value);
    if ($value === '') {
        return '';
    }

    $map = [
        'BOHO' => 'BOHO',
        'PASSION TWIST' => 'Passion Twist',
        'MARLEY TWIST' => 'Marley Twist',
        'REBEL BOHO MERMAID LOCS' => 'Rebel Boho Mermaid Locs',
        'BOHEMIAN BRAID' => 'Bohemian Braid',
        'BRAZILIAN BULK' => 'Brazilian Bulk',
        'DEEP TWIST' => 'Deep Twist',
        'LISBON GIRL' => 'Lisbon Girl',
    ];

    return $map[$value] ?? Str::title(Str::lower($value));
}

function cv2_path(mixed $path): array
{
    if (! is_array($path)) {
        return [];
    }

    $parts = [];
    foreach ($path as $value) {
        foreach (explode('>', (string) $value) as $part) {
            $part = cv2_clean($part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
    }

    return array_values(array_filter($parts, fn (string $part): bool => cv2_norm($part) !== 'cherish'));
}

function cv2_path_text(array $path): string
{
    return implode(' > ', array_filter(array_map('cv2_clean', $path)));
}

function cv2_canonical_for(HairExtensionIntake $intake): array
{
    $path = cv2_path($intake->classification_path);
    $pathText = cv2_norm(cv2_path_text($path));
    $styleRaw = cv2_clean($intake->style_name ?: $intake->observed_product_name);
    $styleKey = cv2_norm($styleRaw);
    $type = cv2_clean($intake->product_type_name);

    $style = preg_replace('/^Cherish\s+Junior\s+Bulk\s+/i', '', $styleRaw) ?? $styleRaw;
    $style = preg_replace('/^Cherish\s+Junior\s+/i', '', $style) ?? $style;
    $style = preg_replace('/^Cherish\s+Bulk\s+/i', '', $style) ?? $style;
    $style = preg_replace('/^Cherish\s+Ponytail\s+/i', '', $style) ?? $style;
    $style = cv2_title($style);

    if (in_array($styleKey, ['specialfrenchcurl', 'spiralfrenchcurl'], true)) {
        return ['path' => ['French Curl', 'Pre-Stretched'], 'type' => 'Braid', 'style' => 'Spiral French Curl'];
    }

    if ($styleKey === 'cherishjuniorspiralfrenchcurl') {
        return ['path' => ['Cherish Junior', 'French Curl'], 'type' => 'Braid', 'style' => 'Spiral French Curl'];
    }

    if (str_contains($pathText, 'boho') || in_array($styleKey, ['bohobraid', 'saniyabohobraid', 'monabohobraid', 'bohemianbraid'], true)) {
        return ['path' => ['BOHO'], 'type' => 'Crochet Braid', 'style' => $style];
    }

    if (str_contains($pathText, 'handmade')) {
        $newPath = ['Handmade'];
        if (str_contains($pathText, 'eazipack')) {
            $newPath[] = 'Eazi-Pack';
        }

        return ['path' => $newPath, 'type' => 'Crochet Braid', 'style' => $style];
    }

    if (str_contains($pathText, 'butterflylocs') && $styleKey === 'butterflylocs') {
        return ['path' => ['Butterfly Locs'], 'type' => 'Crochet Braid', 'style' => 'Butterfly Locs'];
    }

    if (str_contains($pathText, 'cherishjunior')) {
        return ['path' => ['Cherish Junior'], 'type' => $type, 'style' => $style];
    }

    if (str_contains($pathText, 'hairponytail')) {
        return ['path' => ['Hair Ponytail'], 'type' => 'Ponytail', 'style' => $style];
    }

    if (in_array($styleKey, ['marleytwist'], true)) {
        return ['path' => ['Twist & Loc Hair'], 'type' => 'Braid', 'style' => 'Marley Twist'];
    }

    if (in_array($styleKey, ['reb elbohomermaidlocs', 'rebelbohomermaidlocs'], true) || str_contains($styleKey, 'mermaidloc')) {
        return ['path' => ['Twist & Loc Hair'], 'type' => 'Crochet Braid', 'style' => 'Rebel Boho Mermaid Locs'];
    }

    if (in_array($styleKey, ['passiontwist', 'princesstwist'], true)) {
        return ['path' => ['Bulk'], 'type' => 'Crochet Braid', 'style' => $style];
    }

    if (in_array($styleKey, ['deeptwist'], true) && $type === 'Crochet Braid') {
        return ['path' => ['Bulk'], 'type' => 'Crochet Braid', 'style' => 'Deep Twist'];
    }

    if (str_contains($pathText, 'bulk') || in_array($styleKey, ['waterwave', 'spanishcurl', 'ravishbulk', 'babyweftbraid', 'brazilianbulk', 'bohemian'], true)) {
        return ['path' => ['Bulk'], 'type' => $type === 'Crochet Braid' ? 'Crochet Braid' : 'Bulk Hair', 'style' => $style];
    }

    if (str_contains($pathText, 'hairbraiding') && $styleKey === 'brazilianbulk') {
        return ['path' => ['Bulk'], 'type' => 'Bulk Hair', 'style' => 'Brazilian Bulk'];
    }

    if (str_contains($pathText, 'hairbraiding') && $styleKey === 'deeptwist') {
        return ['path' => ['Bulk'], 'type' => 'Crochet Braid', 'style' => 'Deep Twist'];
    }

    return ['path' => $path ?: ['Cherish'], 'type' => $type, 'style' => $style];
}

function cv2_family_key(array $canonical): string
{
    return implode('|', [
        cv2_norm(cv2_path_text($canonical['path'])),
        cv2_norm($canonical['type']),
        cv2_norm($canonical['style']),
    ]);
}

function cv2_family_display(array $canonical): string
{
    $path = array_values(array_filter($canonical['path']));
    $style = cv2_clean($canonical['style']);
    $tail = $path ? cv2_clean((string) end($path)) : '';

    if ($path && cv2_norm($path[0]) === 'cherishjunior') {
        return cv2_clean('Cherish Junior '.$style);
    }

    if ($path && cv2_norm($path[0]) === 'frenchcurl') {
        return cv2_clean('Cherish '.($tail === 'Pre-Stretched' ? 'Pre-Stretched ' : '').$style);
    }

    if ($path && cv2_norm($path[0]) === 'bulk' && str_contains(cv2_norm($style), 'bulk')) {
        return cv2_clean('Cherish '.$style);
    }

    if ($path && cv2_norm($tail) !== '' && ! str_contains(cv2_norm($style), cv2_norm($tail))) {
        return cv2_clean('Cherish '.$tail.' '.$style);
    }

    return cv2_clean('Cherish '.$style);
}

function cv2_variant_type(string $axis): string
{
    $key = cv2_norm($axis);

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

function cv2_variant_value(mixed $value): string
{
    $value = cv2_clean($value);
    $value = str_ireplace(['NICHES', 'INCHS'], 'INCHES', $value);
    $value = preg_replace('/\s+INCHES$/i', ' inch', $value) ?? $value;
    $value = preg_replace('/\s+inch$/i', ' inch', $value) ?? $value;

    return cv2_clean($value);
}

function cv2_sku_rows(HairExtensionIntake $intake): array
{
    $structure = $intake->variant_structure ?: [];
    $rows = [];

    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $row = [];
        if (cv2_clean($sku['main_value'] ?? '') !== '') {
            $row[] = [
                'axis' => cv2_clean($sku['main_axis'] ?? $structure['main_axis'] ?? 'Main'),
                'value' => cv2_variant_value($sku['main_value']),
            ];
        }
        if (cv2_clean($sku['sub_value'] ?? '') !== '') {
            $row[] = [
                'axis' => cv2_clean($sku['sub_axis'] ?? 'Sub'),
                'value' => cv2_variant_value($sku['sub_value']),
            ];
        }
        foreach (($sku['common_attributes'] ?? []) as $axis => $values) {
            foreach ((array) $values as $value) {
                if (cv2_clean($value) !== '') {
                    $row[] = ['axis' => cv2_clean($axis), 'value' => cv2_variant_value($value)];
                }
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }
    }

    return $rows ?: [[['axis' => 'Single', 'value' => 'Single product']]];
}

function cv2_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
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

function cv2_ensure_line(BrandCatalogueBrand $brand, array $path, bool $apply, array &$stats, array &$lineCache): BrandCatalogueLine
{
    $lineName = cv2_path_text($path) ?: $brand->name;
    $cacheKey = cv2_norm($lineName);

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
            'sort_order' => 100 + $stats['lines_created'],
        ]);
    }

    return $lineCache[$cacheKey] = BrandCatalogueLine::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'name' => $lineName,
        'slug' => cv2_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', $brand->id, $lineName),
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 100 + $stats['lines_created'],
    ]);
}

function cv2_ensure_product_type(BrandCatalogueBrand $brand, BrandCatalogueLine $line, string $typeName, bool $apply, array &$stats, array &$typeCache): BrandCatalogueProductType
{
    $cacheKey = ((int) $line->id).'|'.cv2_norm($typeName);
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
            'sort_order' => 100 + $stats['product_types_created'],
        ]);
    }

    return $typeCache[$cacheKey] = BrandCatalogueProductType::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_line_id' => $line->id,
        'name' => $typeName,
        'slug' => cv2_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', $line->id, $typeName),
        'is_active' => true,
        'sort_order' => 100 + $stats['product_types_created'],
    ]);
}

function cv2_existing_style_id_for(array $family, Collection $intakes): ?int
{
    $key = cv2_family_key($family['canonical']);
    $manual = [
        'frenchcurlprestretched|braid|spiralfrenchcurl' => 31,
        'cherishjuniorfrenchcurl|braid|spiralfrenchcurl' => 68,
        'twistlochair|braid|marleytwist' => 52,
        'bulk|crochetbraid|deeptwist' => 33,
        'bulk|crochetbraid|passiontwist' => 30,
        'bulk|bulkhair|waterwave' => 36,
        'bulk|bulkhair|spanishcurl' => 37,
        'bulk|bulkhair|bohemian' => 38,
        'bulk|bulkhair|brazilianbulk' => 35,
        'cherishjunior|crochetbraid|boxbraid' => 42,
        'cherishjunior|crochetbraid|butterflylocs' => 40,
        'cherishjunior|crochetbraid|silkylocs' => 46,
        'cherishjunior|bulkhair|bubblycurl' => 43,
        'cherishjunior|bulkhair|deeptwist' => 44,
        'cherishjunior|bulkhair|waterbulk' => 45,
        'twistlochair|crochetbraid|rebelbohomermaidlocs' => 53,
        'hairponytail|ponytail|napoligirl' => 63,
    ];

    if (isset($manual[$key])) {
        return $manual[$key];
    }

    $linked = $intakes
        ->pluck('brand_catalogue_style_id')
        ->filter()
        ->countBy()
        ->sortDesc();

    $linkedId = $linked->keys()->first() ? (int) $linked->keys()->first() : null;
    if (! $linkedId) {
        return null;
    }

    $style = BrandCatalogueStyle::query()->find($linkedId);
    if (! $style) {
        return null;
    }

    $existingName = cv2_clean($style->name);
    $existingName = preg_replace('/^Cherish\s+Junior\s+Bulk\s+/i', '', $existingName) ?? $existingName;
    $existingName = preg_replace('/^Cherish\s+Junior\s+/i', '', $existingName) ?? $existingName;
    $existingName = preg_replace('/^Cherish\s+Bulk\s+/i', '', $existingName) ?? $existingName;
    $existingName = preg_replace('/^Cherish\s+Ponytail\s+/i', '', $existingName) ?? $existingName;

    return cv2_norm($existingName) === cv2_norm($family['canonical']['style']) ? $linkedId : null;
}

function cv2_ensure_style(BrandCatalogueBrand $brand, BrandCatalogueProductType $type, array $family, bool $apply, array &$stats): BrandCatalogueStyle
{
    $styleName = $family['canonical']['style'];
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
            || cv2_clean($style->name) !== $styleName
            || cv2_clean($style->material_name) === ''
            || cv2_clean($style->material_name) === 'Review material';

        if ($changed) {
            $stats['styles_moved_or_renamed']++;
        }

        if ($apply && $changed) {
            $style->update([
                'brand_catalogue_brand_id' => $brand->id,
                'brand_catalogue_product_type_id' => $type->id,
                'name' => $styleName,
                'slug' => cv2_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $type->id, $styleName, $style->id),
                'material_name' => cv2_clean($style->material_name) === '' || cv2_clean($style->material_name) === 'Review material'
                    ? 'Synthetic Hair'
                    : $style->material_name,
                'is_active' => true,
            ]);

            ProductFamily::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->update([
                    'brand_catalogue_line_id' => $type->brand_catalogue_line_id,
                    'brand_catalogue_product_type_id' => $type->id,
                    'line_name' => $type->line?->name,
                    'product_type_name' => $type->name,
                    'family_name' => cv2_family_display($family['canonical']),
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
            'material_name' => 'Synthetic Hair',
            'is_active' => true,
        ]);
    }

    return BrandCatalogueStyle::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_product_type_id' => $type->id,
        'name' => $styleName,
        'slug' => cv2_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $type->id, $styleName),
        'material_name' => 'Synthetic Hair',
        'note' => 'Created from confirmed V2 shop-floor Cherish intake.',
        'is_active' => true,
        'sort_order' => 200 + $stats['styles_created'],
    ]);
}

function cv2_ensure_variant(BrandCatalogueStyle $style, string $axis, bool $apply, array &$stats): BrandCatalogueVariant
{
    $name = cv2_clean($axis) ?: 'Variant';
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
            'variant_type' => cv2_variant_type($name),
            'sort_order' => $stats['variants_created'] * 10,
        ]);
    }

    return BrandCatalogueVariant::query()->create([
        'brand_catalogue_style_id' => $style->id,
        'name' => $name,
        'variant_type' => cv2_variant_type($name),
        'sort_order' => $stats['variants_created'] * 10,
    ]);
}

function cv2_ensure_option(BrandCatalogueVariant $variant, string $value, bool $apply, array &$stats): BrandCatalogueVariantOption
{
    $value = cv2_variant_value($value);
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

function cv2_signature(Collection $options): string
{
    $parts = $options
        ->map(fn (BrandCatalogueVariantOption $option): string => $option->variant_id.':'.$option->id)
        ->values()
        ->all();

    sort($parts, SORT_NATURAL);

    return implode('|', $parts);
}

function cv2_sku_name(array $canonical, array $row): string
{
    $parts = [cv2_family_display($canonical)];
    foreach ($row as $part) {
        if (cv2_norm($part['axis']) === 'single') {
            continue;
        }
        $parts[] = cv2_clean($part['axis']).' '.$part['value'];
    }

    return cv2_clean(implode(' - ', $parts));
}

function cv2_backup_cherish(BrandCatalogueBrand $brand, string $timestamp): string
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
        'intakes' => DB::table('hair_extension_intakes')->where('brand_name', 'Cherish')->get(),
        'product_families' => DB::table('product_families')->where('brand_catalogue_brand_id', $brand->id)->orWhere('brand_name', 'Cherish')->get(),
    ];

    $path = "catalogue-backups/cherish-v2-sync-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

$brand = BrandCatalogueBrand::query()
    ->where('brand_catalogue_id', 1)
    ->where('name', 'Cherish')
    ->firstOrFail();

$intakes = HairExtensionIntake::query()
    ->where('status', 'submitted')
    ->where('brand_name', 'Cherish')
    ->orderBy('id')
    ->get();

$families = [];
foreach ($intakes as $intake) {
    $canonical = cv2_canonical_for($intake);
    $key = cv2_family_key($canonical);

    $families[$key] ??= [
        'canonical' => $canonical,
        'intakes' => collect(),
        'sku_rows' => [],
    ];
    $families[$key]['intakes']->push($intake);

    foreach (cv2_sku_rows($intake) as $row) {
        $rowKey = collect($row)
            ->map(fn (array $part): string => cv2_norm($part['axis']).'='.cv2_norm($part['value']))
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
    $stats['backup'] = cv2_backup_cherish($brand, $timestamp);
}

DB::transaction(function () use ($apply, $brand, &$families, &$stats, &$rows): void {
    $lineCache = [];
    $typeCache = [];

    foreach ($families as &$family) {
        /** @var Collection<int, HairExtensionIntake> $familyIntakes */
        $familyIntakes = $family['intakes'];
        $family['existing_style_id'] = cv2_existing_style_id_for($family, $familyIntakes);

        $line = cv2_ensure_line($brand, $family['canonical']['path'], $apply, $stats, $lineCache);
        $type = cv2_ensure_product_type($brand, $line, $family['canonical']['type'], $apply, $stats, $typeCache);
        $type->setRelation('line', $line);
        $style = cv2_ensure_style($brand, $type, $family, $apply, $stats);

        $createdSkuCountBefore = $stats['skus_created'];
        $createdOptionCountBefore = $stats['options_created'];
        $createdVariantCountBefore = $stats['variants_created'];

        foreach ($family['sku_rows'] as $skuRow) {
            $options = collect();
            foreach ($skuRow as $part) {
                if (cv2_clean($part['value']) === '') {
                    continue;
                }

                $variant = cv2_ensure_variant($style, $part['axis'], $apply, $stats);
                $option = cv2_ensure_option($variant, $part['value'], $apply, $stats);
                $options->push($option);
            }

            if ($options->isEmpty() || ! $apply) {
                continue;
            }

            $options = $options
                ->unique(fn (BrandCatalogueVariantOption $option): int => (int) $option->variant_id)
                ->values();

            $signature = cv2_signature($options);
            $skuName = cv2_sku_name($family['canonical'], $skuRow);
            $sku = BrandCatalogueSku::query()
                ->where('brand_catalogue_style_id', $style->id)
                ->where('option_signature', $signature)
                ->first();

            if ($sku) {
                $sku->update([
                    'name' => $skuName,
                    'slug' => cv2_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $skuName, $sku->id),
                    'is_active' => true,
                ]);
                $stats['skus_updated']++;
            } else {
                $sku = BrandCatalogueSku::query()->create([
                    'brand_catalogue_style_id' => $style->id,
                    'name' => $skuName,
                    'slug' => cv2_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $skuName),
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
            'canonical_key' => cv2_family_key($family['canonical']),
            'brand' => 'Cherish',
            'grouping_path' => cv2_path_text($family['canonical']['path']),
            'product_type' => $family['canonical']['type'],
            'style_family' => $family['canonical']['style'],
            'display_family' => cv2_family_display($family['canonical']),
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
