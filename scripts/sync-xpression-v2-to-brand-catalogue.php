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

$csvPath = $reportDir."/xpression-v2-catalogue-sync-{$timestamp}.csv";
$latestCsvPath = $reportDir.'/xpression-v2-catalogue-sync-latest.csv';

function xp_clean(mixed $value): string
{
    return trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
}

function xp_norm(mixed $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
}

function xp_path_text(array $path): string
{
    return implode(' > ', array_filter(array_map('xp_clean', $path)));
}

function xp_unique_slug(string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string
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

function xp_axis(mixed $axis): string
{
    $key = xp_norm($axis);

    if (str_contains($key, 'length')) {
        return 'Length';
    }
    if (str_contains($key, 'colour') || str_contains($key, 'color')) {
        return 'Colour';
    }
    if (str_contains($key, 'pack') || str_contains($key, 'bundle') || str_contains($key, 'count') || str_contains($key, 'piece')) {
        return 'Bundle';
    }

    return xp_clean($axis) ?: 'Variant';
}

function xp_axis_sort_order(string $axis): int
{
    return match ($axis) {
        'Length' => 10,
        'Colour' => 20,
        'Bundle' => 30,
        default => 90,
    };
}

function xp_variant_type(string $axis): string
{
    return match ($axis) {
        'Length' => 'measurement',
        'Colour' => 'colour_code',
        'Bundle' => 'count',
        default => 'text',
    };
}

function xp_length_value(mixed $value): string
{
    $value = str_replace(['â€œ', 'â€', 'â€³', '“', '”', '″'], '"', xp_clean($value));
    $value = preg_replace('/^(\d+(?:\.\d+)?)\s*(?:\"|in|inch|inches)$/i', '$1', $value) ?? $value;
    $value = preg_replace('/"\s+(?=\d+\s*x)/i', '" + ', $value) ?? $value;

    return xp_clean($value);
}

function xp_colour_value(mixed $value): string
{
    $value = xp_clean($value);

    if (preg_match('/^ash\s+/i', $value)) {
        return Str::upper($value);
    }

    return Str::upper($value);
}

function xp_bundle_value(mixed $value): string
{
    $value = xp_clean($value);

    if (preg_match('/^(\d+)\s*(?:x|pcs?|pieces?)$/i', $value, $match)) {
        return $match[1].'X';
    }

    return Str::upper($value);
}

function xp_variant_value(mixed $value, string $axis = ''): string
{
    $axis = xp_axis($axis);

    return match ($axis) {
        'Length' => xp_length_value($value),
        'Colour' => xp_colour_value($value),
        'Bundle' => xp_bundle_value($value),
        default => xp_clean($value),
    };
}

function xp_option_label(string $axis, string $value): string
{
    return $axis === 'Length' && preg_match('/^\d+(?:\.\d+)?$/', $value) ? $value.'"' : $value;
}

function xp_option_key(string $axis, mixed $value): string
{
    return xp_norm(xp_variant_value($value, $axis));
}

function xp_is_non_sellable_value(string $value): bool
{
    return in_array(xp_norm($value), ['unspecified', 'unknown', 'notknown', 'na', 'none'], true);
}

function xp_canonical_for(object $intake): ?array
{
    return match ((int) $intake->id) {
        41 => [
            'path' => ['X-Pression Braids'],
            'type' => 'Braid',
            'style' => 'Pre-Stretched',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 94,
            'url' => '',
            'reason' => 'Shop-floor V2 intake identifies X-Pression Ultra Braid Pre-Stretched; existing catalogue style 94 holds the Pre-Stretched length/colour/bundle structure.',
        ],
        42, 43, 45 => [
            'path' => ['X-Pression Braids'],
            'type' => 'Braid',
            'style' => 'Ultra Braid',
            'material' => '100% Kanekalon Fibre',
            'source_style_id' => 1,
            'url' => '',
            'reason' => 'Shop-floor V2 intake identifies X-Pression Ultra Braid. Length and bundle differences stay as sellable variant axes, not separate catalogue styles.',
        ],
        44 => [
            'path' => ['X-Pression Braids'],
            'type' => 'Braid',
            'style' => 'Lagos Braid',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 100,
            'url' => '',
            'reason' => 'Shop-floor V2 intake identifies X-Pression Lagos Braid with the mixed 42/46 inch pack length.',
        ],
        46 => [
            'path' => ['Outre'],
            'type' => 'Crochet Braid',
            'style' => 'X-Pression Twisted Up Swicy Afro Twist',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 12675,
            'url' => 'https://feme.com/outre-x-pression-twisted-up-swicy-afro-twist/',
            'reason' => 'Shop-floor V2 intake matches the imported Outre X-Pression Twisted Up Swicy Afro Twist style; colour variants are shop-floor additions.',
        ],
        47 => [
            'path' => ['Outre'],
            'type' => 'Crochet Braid',
            'style' => 'X-Pression Twisted Up LuLu Wandcurl',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 12677,
            'url' => '',
            'reason' => 'Shop-floor V2 intake matches the imported Outre X-Pression Twisted Up LuLu Wandcurl style; colour variants are shop-floor additions.',
        ],
        48 => [
            'path' => ['X-Pression Weave On'],
            'type' => 'Weave',
            'style' => 'Active',
            'material' => 'Synthetic Fibre',
            'source_style_id' => 106,
            'url' => '',
            'reason' => 'Shop-floor V2 intake identifies X-Pression Weave On Active.',
        ],
        91 => [
            'path' => ['X-Pression Braids'],
            'type' => 'Braid',
            'style' => 'Kingky Braid',
            'material' => '100% Kanekalon Fibre',
            'source_style_id' => 121,
            'url' => 'https://feme.com/x-pression-kingky-braid/',
            'reason' => 'The V2 spelling "kingky" is confirmed by Feme as X-Pression Kingky Braid. Feme lists it as a bulk-type braid, 55 inch, with colour 1B visible, so it is not kept under Crochet Braid.',
        ],
        default => null,
    };
}

function xp_family_key(array $canonical): string
{
    return implode('|', [
        xp_norm(xp_path_text($canonical['path'])),
        xp_norm($canonical['type']),
        xp_norm($canonical['style']),
    ]);
}

function xp_sku_rows(object $intake, array $canonical): array
{
    if ((int) $intake->id === 91) {
        return [
            [
                ['axis' => 'Length', 'value' => '55'],
                ['axis' => 'Colour', 'value' => '1B'],
            ],
        ];
    }

    $structure = json_decode((string) $intake->variant_structure, true) ?: [];
    $rows = [];

    foreach (($structure['sku_matrix'] ?? []) as $sku) {
        $row = [];
        $mainAxis = xp_axis($sku['main_axis'] ?? $structure['main_axis'] ?? 'Main');
        $main = xp_variant_value($sku['main_value'] ?? '', $mainAxis);
        if ($main !== '' && ! xp_is_non_sellable_value($main)) {
            $row[] = ['axis' => $mainAxis, 'value' => $main];
        }

        $subAxis = xp_axis($sku['sub_axis'] ?? 'Colour');
        $sub = xp_variant_value($sku['sub_value'] ?? '', $subAxis);
        if ($sub !== '' && ! xp_is_non_sellable_value($sub)) {
            $row[] = ['axis' => $subAxis, 'value' => $sub];
        }

        $commonAttributes = $sku['common_attributes'] ?? [];
        if ((int) $intake->id === 45 && empty($commonAttributes['Pack count'] ?? [])) {
            $commonAttributes['Pack count'] = ['6X'];
        }

        foreach ($commonAttributes as $axis => $values) {
            $axis = xp_axis($axis);
            foreach ((array) $values as $value) {
                $value = xp_variant_value($value, $axis);
                if ($value !== '' && ! xp_is_non_sellable_value($value)) {
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

function xp_sku_name(array $canonical, array $row): string
{
    $name = $canonical['style'];
    $length = null;
    $colour = null;
    $bundle = null;

    foreach ($row as $part) {
        if ($part['axis'] === 'Length' && ! str_contains(xp_norm($name), xp_norm($part['value']))) {
            $length = xp_option_label('Length', $part['value']);
        } elseif ($part['axis'] === 'Colour') {
            $colour = $part['value'];
        } elseif ($part['axis'] === 'Bundle' && ! str_contains(xp_norm($name), xp_norm($part['value']))) {
            $bundle = $part['value'];
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

    return xp_clean($name);
}

function xp_signature(array $row): string
{
    return collect($row)->map(fn (array $part): string => $part['axis'].':'.$part['value'])->implode('|');
}

function xp_backup(object $brand, string $timestamp): string
{
    $styleIds = DB::table('brand_catalogue_styles')->where('brand_catalogue_brand_id', $brand->id)->pluck('id');
    $variantIds = DB::table('brand_catalogue_variants')->whereIn('brand_catalogue_style_id', $styleIds)->pluck('id');
    $skuIds = DB::table('brand_catalogue_skus')->whereIn('brand_catalogue_style_id', $styleIds)->pluck('id');

    $backup = [
        'brand' => DB::table('brand_catalogue_brands')->where('id', $brand->id)->get(),
        'lines' => DB::table('brand_catalogue_lines')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'product_types' => DB::table('brand_catalogue_product_types')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'styles' => DB::table('brand_catalogue_styles')->where('brand_catalogue_brand_id', $brand->id)->get(),
        'variants' => DB::table('brand_catalogue_variants')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'variant_options' => DB::table('brand_catalogue_variant_options')->whereIn('variant_id', $variantIds)->get(),
        'skus' => DB::table('brand_catalogue_skus')->whereIn('brand_catalogue_style_id', $styleIds)->get(),
        'sku_variant_options' => DB::table('brand_catalogue_sku_variant_options')->whereIn('brand_catalogue_sku_id', $skuIds)->get(),
        'intakes' => DB::table('hair_extension_intakes')
            ->where('brand_name', 'X-Pression')
            ->orWhere('brand_catalogue_brand_id', $brand->id)
            ->get(),
        'product_families' => DB::table('product_families')->where('brand_catalogue_brand_id', $brand->id)->get(),
    ];

    $path = "catalogue-backups/xpression-v2-sync-{$timestamp}.json";
    Storage::disk('local')->put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return Storage::disk('local')->path($path);
}

function xp_ensure_line(object $brand, array $path, bool $apply, array &$stats, array &$cache): object
{
    $name = xp_path_text($path) ?: 'X-Pression';
    $key = xp_norm($name);

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
            'slug' => xp_unique_slug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $name),
            'note' => 'Created from X-Pression V2 shop-floor intake structure.',
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

function xp_ensure_type(object $brand, object $line, string $name, bool $apply, array &$stats, array &$cache): object
{
    $key = $line->id.'|'.xp_norm($name);

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $aliases = match ($name) {
        'Crochet Braid' => ['Crochet Braid', 'Crochet'],
        default => [$name],
    };

    $type = DB::table('brand_catalogue_product_types')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('brand_catalogue_line_id', $line->id)
        ->whereIn('name', $aliases)
        ->orderByRaw(
            'CASE name '.collect($aliases)
                ->values()
                ->map(fn (string $alias, int $index): string => "WHEN '".str_replace("'", "''", $alias)."' THEN {$index}")
                ->implode(' ')
                .' ELSE 99 END'
        )
        ->first();

    if ($type && $apply) {
        $updates = [
            'name' => $name,
            'slug' => xp_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', (int) $line->id, $name, (int) $type->id),
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ($name === 'Crochet Braid') {
            $note = (string) ($type->note ?? '');
            if ($type->name !== $name && ! str_contains($note, 'Normalised from Crochet to Crochet Braid')) {
                $note = xp_clean($note.' Normalised from Crochet to Crochet Braid for shop-floor product taxonomy.');
            }
            $updates['note'] = $note;
        }

        DB::table('brand_catalogue_product_types')->where('id', $type->id)->update($updates);
        $type = DB::table('brand_catalogue_product_types')->where('id', $type->id)->first();
    }

    if (! $type && $apply) {
        $id = DB::table('brand_catalogue_product_types')->insertGetId([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_line_id' => $line->id,
            'name' => $name,
            'slug' => xp_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', (int) $line->id, $name),
            'note' => 'Created from X-Pression V2 shop-floor intake structure.',
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

function xp_ensure_style(object $brand, object $type, array $family, bool $apply, array &$stats): object
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
            'note' => xp_clean(($style->note ?? '').' Normalised to X-Pression V2 shop-floor family: '.$canonical['reason']),
            'url' => $canonical['url'] ?: ($style->url ?? ''),
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ((int) $style->brand_catalogue_product_type_id !== (int) $type->id || $style->name !== $canonical['style']) {
            $updates['slug'] = xp_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style'], (int) $style->id);
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
            'slug' => xp_unique_slug('brand_catalogue_styles', 'brand_catalogue_product_type_id', (int) $type->id, $canonical['style']),
            'note' => 'Created from X-Pression V2 shop-floor evidence. '.$canonical['reason'],
            'url' => $canonical['url'] ?? '',
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

function xp_find_existing_variant(object $style, string $axis): ?object
{
    $aliases = match ($axis) {
        'Bundle' => ['Bundle', 'Pack count', 'Pack', 'Piece count'],
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

function xp_ensure_variant(object $style, string $axis, bool $apply, array &$stats): object
{
    $axis = xp_axis($axis);
    $variant = xp_find_existing_variant($style, $axis);

    if ($variant && $apply) {
        DB::table('brand_catalogue_variants')->where('id', $variant->id)->update([
            'name' => $axis,
            'variant_type' => xp_variant_type($axis),
            'sort_order' => xp_axis_sort_order($axis),
            'updated_at' => now(),
        ]);
        $variant = DB::table('brand_catalogue_variants')->where('id', $variant->id)->first();
    }

    if (! $variant && $apply) {
        $id = DB::table('brand_catalogue_variants')->insertGetId([
            'brand_catalogue_style_id' => $style->id,
            'name' => $axis,
            'variant_type' => xp_variant_type($axis),
            'sort_order' => xp_axis_sort_order($axis),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $variant = DB::table('brand_catalogue_variants')->where('id', $id)->first();
        $stats['variants_created']++;
    }

    return $variant ?? (object) ['id' => 0, 'name' => $axis];
}

function xp_ensure_option(object $variant, string $value, bool $apply, array &$stats): object
{
    $axis = xp_axis($variant->name);
    $value = xp_variant_value($value, $axis);
    $label = xp_option_label($axis, $value);
    $key = xp_option_key($axis, $value);

    $matches = DB::table('brand_catalogue_variant_options')
        ->where('variant_id', $variant->id)
        ->get()
        ->filter(fn (object $option): bool => xp_option_key($axis, $option->value ?: $option->label) === $key)
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
            $stats['duplicate_options_merged']++;
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

function xp_existing_sku_by_options(int $styleId, array $options): ?object
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

function xp_rewrite_sku_options(object $sku, array $options, string $skuName, string $signature, bool $apply, array &$stats): void
{
    if (! $apply) {
        return;
    }

    DB::table('brand_catalogue_skus')->where('id', $sku->id)->update([
        'name' => $skuName,
        'slug' => xp_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $sku->brand_catalogue_style_id, $skuName, (int) $sku->id),
        'option_signature' => $signature,
        'is_active' => true,
        'updated_at' => now(),
    ]);

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

    $stats['skus_updated']++;
}

function xp_ensure_sku(object $style, array $canonical, array $row, bool $apply, array &$stats): void
{
    $options = [];

    foreach ($row as $part) {
        $variant = xp_ensure_variant($style, $part['axis'], $apply, $stats);
        $options[] = xp_ensure_option($variant, $part['value'], $apply, $stats);
    }

    if (! $apply) {
        $existing = null;
    } else {
        $existing = xp_existing_sku_by_options((int) $style->id, $options);
    }

    $skuName = xp_sku_name($canonical, $row);
    $signature = xp_signature($row);

    if ($existing) {
        xp_rewrite_sku_options($existing, $options, $skuName, $signature, $apply, $stats);

        return;
    }

    $sku = DB::table('brand_catalogue_skus')
        ->where('brand_catalogue_style_id', $style->id)
        ->where('option_signature', $signature)
        ->first();

    if ($sku) {
        xp_rewrite_sku_options($sku, $options, $skuName, $signature, $apply, $stats);

        return;
    }

    if ($apply) {
        $skuId = DB::table('brand_catalogue_skus')->insertGetId([
            'brand_catalogue_style_id' => $style->id,
            'name' => $skuName,
            'slug' => xp_unique_slug('brand_catalogue_skus', 'brand_catalogue_style_id', (int) $style->id, $skuName),
            'sku_code' => '',
            'barcode' => '',
            'option_signature' => $signature,
            'description' => 'Observed in X-Pression V2 shop-floor intake.',
            'note' => 'Observed in X-Pression V2 shop-floor intake.',
            'url' => '',
            'is_active' => true,
            'sort_order' => DB::table('brand_catalogue_skus')->where('brand_catalogue_style_id', $style->id)->count() + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sku = DB::table('brand_catalogue_skus')->where('id', $skuId)->first();

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

    $stats['skus_created']++;
}

function xp_add_fixed_length_to_existing_skus(object $style, string $length, bool $apply, array &$stats): void
{
    $lengthVariant = xp_ensure_variant($style, 'Length', $apply, $stats);
    $lengthOption = xp_ensure_option($lengthVariant, $length, $apply, $stats);

    $skus = DB::table('brand_catalogue_skus')->where('brand_catalogue_style_id', $style->id)->get();

    foreach ($skus as $sku) {
        $hasLength = DB::table('brand_catalogue_sku_variant_options')
            ->where('brand_catalogue_sku_id', $sku->id)
            ->where('brand_catalogue_variant_id', $lengthVariant->id)
            ->exists();

        if ($hasLength) {
            continue;
        }

        $options = DB::table('brand_catalogue_sku_variant_options as svo')
            ->join('brand_catalogue_variants as v', 'v.id', '=', 'svo.brand_catalogue_variant_id')
            ->join('brand_catalogue_variant_options as vo', 'vo.id', '=', 'svo.brand_catalogue_variant_option_id')
            ->where('svo.brand_catalogue_sku_id', $sku->id)
            ->orderBy('v.sort_order')
            ->get(['v.name as axis', 'vo.value', 'vo.label']);

        $row = [['axis' => 'Length', 'value' => $length]];
        foreach ($options as $option) {
            $row[] = [
                'axis' => xp_axis($option->axis),
                'value' => xp_variant_value($option->value ?: $option->label, $option->axis),
            ];
        }

        $rewrittenOptions = [$lengthOption];
        foreach ($options as $option) {
            $variant = xp_ensure_variant($style, $option->axis, $apply, $stats);
            $rewrittenOptions[] = xp_ensure_option($variant, $option->value ?: $option->label, $apply, $stats);
        }

        xp_rewrite_sku_options($sku, $rewrittenOptions, xp_sku_name(['style' => $style->name], $row), xp_signature($row), $apply, $stats);
        $stats['fixed_length_skus_realigned']++;
    }
}

function xp_align_product_families_to_current_styles(object $brand, bool $apply, array &$stats): void
{
    $families = DB::table('product_families as pf')
        ->join('brand_catalogue_styles as s', 's.id', '=', 'pf.brand_catalogue_style_id')
        ->join('brand_catalogue_product_types as pt', 'pt.id', '=', 's.brand_catalogue_product_type_id')
        ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
        ->where('s.brand_catalogue_brand_id', $brand->id)
        ->where('pf.brand_catalogue_brand_id', $brand->id)
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

        if ($apply) {
            DB::table('product_families')->where('id', $family->id)->update([
                'brand_catalogue_line_id' => $family->current_line_id,
                'brand_catalogue_product_type_id' => $family->current_type_id,
                'line_name' => $family->current_line_name,
                'product_type_name' => $family->current_type_name,
                'updated_at' => now(),
            ]);
        }
    }
}

function xp_normalize_existing_product_types(object $brand, bool $apply, array &$stats): void
{
    $lineNames = ['X-Pression Crochet Braids', 'Outre'];

    foreach ($lineNames as $lineName) {
        $line = DB::table('brand_catalogue_lines')
            ->where('brand_catalogue_brand_id', $brand->id)
            ->where('name', $lineName)
            ->first();

        if (! $line) {
            continue;
        }

        $type = DB::table('brand_catalogue_product_types')
            ->where('brand_catalogue_brand_id', $brand->id)
            ->where('brand_catalogue_line_id', $line->id)
            ->where('name', 'Crochet')
            ->first();

        if (! $type) {
            continue;
        }

        $stats['product_types_normalized']++;

        if ($apply) {
            DB::table('brand_catalogue_product_types')->where('id', $type->id)->update([
                'name' => 'Crochet Braid',
                'slug' => xp_unique_slug('brand_catalogue_product_types', 'brand_catalogue_line_id', (int) $line->id, 'Crochet Braid', (int) $type->id),
                'note' => xp_clean(((string) $type->note).' Normalised from Crochet to Crochet Braid for shop-floor product taxonomy.'),
                'updated_at' => now(),
            ]);

            DB::table('product_families')->where('brand_catalogue_product_type_id', $type->id)->update([
                'product_type_name' => 'Crochet Braid',
                'updated_at' => now(),
            ]);
        }
    }
}

function xp_delete_empty_buckets(object $brand, bool $apply, array &$stats): void
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
    ->where('id', 1)
    ->where('name', 'X-Pression')
    ->firstOrFail();

$intakes = DB::table('hair_extension_intakes')
    ->where('status', 'submitted')
    ->where(function ($query) use ($brand) {
        $query->where('brand_name', 'X-Pression')
            ->orWhere('brand_catalogue_brand_id', $brand->id);
    })
    ->orderBy('id')
    ->get();

$families = [];
foreach ($intakes as $intake) {
    $canonical = xp_canonical_for($intake);

    if (! $canonical) {
        continue;
    }

    $key = xp_family_key($canonical);
    $families[$key] ??= [
        'canonical' => $canonical,
        'intakes' => [],
        'sku_rows' => [],
    ];
    $families[$key]['intakes'][] = $intake;

    foreach (xp_sku_rows($intake, $canonical) as $row) {
        $families[$key]['sku_rows'][xp_signature($row)] = $row;
    }
}

$stats = [
    'submitted_intakes' => $intakes->count(),
    'families_seen' => count($families),
    'backup' => '',
    'lines_created' => 0,
    'product_types_created' => 0,
    'product_types_normalized' => 0,
    'styles_created' => 0,
    'styles_moved_or_renamed' => 0,
    'variants_created' => 0,
    'options_created' => 0,
    'duplicate_options_merged' => 0,
    'skus_created' => 0,
    'skus_updated' => 0,
    'intakes_linked' => 0,
    'fixed_length_skus_realigned' => 0,
    'product_families_realigned' => 0,
    'empty_product_types_deleted' => 0,
    'empty_lines_deleted' => 0,
];

$rows = [];

DB::transaction(function () use ($brand, $families, $apply, $timestamp, &$stats, &$rows): void {
    $lineCache = [];
    $typeCache = [];

    $stats['backup'] = xp_backup($brand, $timestamp);
    xp_normalize_existing_product_types($brand, $apply, $stats);

    foreach ($families as $family) {
        $canonical = $family['canonical'];
        $line = xp_ensure_line($brand, $canonical['path'], $apply, $stats, $lineCache);
        $type = xp_ensure_type($brand, $line, $canonical['type'], $apply, $stats, $typeCache);
        $style = xp_ensure_style($brand, $type, $family, $apply, $stats);

        if ($apply && (int) $style->id === 121) {
            xp_add_fixed_length_to_existing_skus($style, '55', $apply, $stats);
        }

        $createdVariantCountBefore = $stats['variants_created'];
        $createdOptionCountBefore = $stats['options_created'];
        $createdSkuCountBefore = $stats['skus_created'];

        foreach ($family['sku_rows'] as $row) {
            xp_ensure_sku($style, $canonical, $row, $apply, $stats);
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
            'canonical_key' => xp_family_key($canonical),
            'brand' => 'X-Pression',
            'grouping_path' => xp_path_text($canonical['path']),
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

    xp_align_product_families_to_current_styles($brand, $apply, $stats);
    xp_delete_empty_buckets($brand, $apply, $stats);
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
