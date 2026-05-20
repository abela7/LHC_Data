<?php

use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueLine;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use App\Models\HairExtensionIntake;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$sync = in_array('--sync', $argv, true);

$manualStyleMap = [
    // Aftress
    231 => 198, // Togo Girl
    233 => 195, // Peckham Girl
    239 => 195, // Peckham Girl
    333 => 3,   // Auntie Lizzy Braid
    337 => 7,   // 2X Soft Brazilian Braid

    // Angels
    87 => 366,  // Bongo Twist - Looped Crochet
    92 => 361,  // Afro Kinky - Crochet Braids
    98 => 392,  // Bogo Braids - Boho Style
    103 => 372, // Angel's Marley Braid
    334 => 365, // Afro Bulk - Crochet, Yaki
    335 => 375, // Jamaican Bounce - Looped
    339 => 356, // Havana Curl - Braids

    // Echo Collection / Hair Code / Spetra / Urban
    304 => 534,   // Echo European Weave
    306 => 534,   // Echo European Weave
    327 => 4196,  // Hair Code Remy Silky
    57 => 4164,   // Spetra Stretch Braid
    65 => 4164,   // Spetra Stretch Braid
    101 => 12671, // Urban French Curl
];

$brandCorrections = [
    277 => [
        'brand_name' => 'E6',
        'line_name' => 'Brazilian',
        'product_type_name' => 'Human Hair Bulk',
        'style_name' => 'Deep Bulk',
        'note' => 'Corrected from photo: package shows E6 Brazilian Deep Bulk, not Eco Style.',
    ],
];

$reviewOnly = [
    161 => 'Composite intake names multiple Koko ponytail families: Poppin Party / Laurel / Scarlett. Split before linking.',
];

$catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();

$intakes = HairExtensionIntake::query()
    ->with(['brand', 'productType', 'style'])
    ->where('status', 'submitted')
    ->whereNull('brand_catalogue_style_id')
    ->orderBy('brand_name')
    ->orderBy('id')
    ->get();

$summary = [
    'checked' => $intakes->count(),
    'linked_existing_styles' => 0,
    'created_styles' => 0,
    'created_brands' => 0,
    'created_product_types' => 0,
    'created_skus' => 0,
    'updated_skus' => 0,
    'review_only' => 0,
    'errors' => 0,
];

$rows = [];

$result = DB::transaction(function () use ($intakes, $catalogue, $manualStyleMap, $brandCorrections, $reviewOnly, &$summary, &$rows, $sync): array {
    foreach ($intakes as $intake) {
        $row = [
            'intake_id' => $intake->id,
            'brand' => $intake->brand_name,
            'product_type' => $intake->product_type_name,
            'style' => $intake->style_name,
            'action' => 'pending',
            'style_id' => null,
            'style_url' => null,
            'note' => '',
        ];

        try {
            if (isset($brandCorrections[(int) $intake->id])) {
                $correction = $brandCorrections[(int) $intake->id];
                $intake->brand_catalogue_brand_id = null;
                $intake->brand_catalogue_product_type_id = null;
                $intake->brand_name = $correction['brand_name'];
                $intake->product_type_name = $correction['product_type_name'];
                $intake->style_name = $correction['style_name'];
                $intake->classification_path = [$correction['line_name']];
                $row['brand'] = $intake->brand_name;
                $row['product_type'] = $intake->product_type_name;
                $row['style'] = $intake->style_name;
            }

            if (isset($reviewOnly[(int) $intake->id])) {
                $summary['review_only']++;
                $row['action'] = 'review_only';
                $row['note'] = $reviewOnly[(int) $intake->id];
                $rows[] = $row;
                continue;
            }

            $style = null;
            $createdStyle = false;

            if (isset($manualStyleMap[(int) $intake->id])) {
                $style = BrandCatalogueStyle::query()
                    ->with('productType.line')
                    ->find((int) $manualStyleMap[(int) $intake->id]);
            }

            if (! $style) {
                $style = findExistingStyle($catalogue, $intake);
            }

            if (! $style) {
                [$style, $created] = createStyleFromIntake($catalogue, $intake, $summary);
                $createdStyle = $created;
            }

            if (! $style) {
                $summary['review_only']++;
                $row['action'] = 'review_only';
                $row['note'] = 'No safe brand/style could be resolved.';
                $rows[] = $row;
                continue;
            }

            $skuResult = syncIntakeVariantsToStyle($intake, $style, $sync);
            $summary['created_skus'] += $skuResult['created'];
            $summary['updated_skus'] += $skuResult['updated'];

            if ($sync) {
                $intake->update([
                    'brand_catalogue_brand_id' => $style->brand_catalogue_brand_id,
                    'brand_catalogue_product_type_id' => $style->brand_catalogue_product_type_id,
                    'brand_catalogue_style_id' => $style->id,
                    'brand_name' => $intake->brand_name,
                    'product_type_name' => $intake->product_type_name,
                    'style_name' => $intake->style_name,
                    'classification_path' => $intake->classification_path,
                    'catalogue_style_status' => 'known',
                    'product_type_status' => 'known',
                    'style_family_status' => 'known',
                    'last_synced_at' => now(),
                ]);

                appendStyleNote($style, 'Observed in V2 shop-floor intake #'.$intake->id.'.');
            }

            $summary[$createdStyle ? 'created_styles' : 'linked_existing_styles']++;

            $row['action'] = $createdStyle
                ? ($sync ? 'created_style_and_linked' : 'would_create_style_and_link')
                : ($sync ? 'linked_existing_style' : 'would_link_existing_style');
            $row['style_id'] = $style->id;
            $row['style_url'] = styleUrl($catalogue, $style);
            $row['note'] = $skuResult['note'];
        } catch (Throwable $exception) {
            $summary['errors']++;
            $row['action'] = 'error';
            $row['note'] = $exception->getMessage();
        }

        $rows[] = $row;
    }

    if (! $sync) {
        DB::rollBack();
    }

    return [$summary, $rows];
});

[$summary, $rows] = $result;
$reportPath = writeReport($rows, $sync);

echo $sync ? "Unlinked hair intakes synced to Style Workspaces.\n" : "Unlinked hair intakes dry run.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}
echo "report: {$reportPath}\n";
if (! $sync) {
    echo "Run with --sync to write these links.\n";
}

function findExistingStyle(BrandCatalogue $catalogue, HairExtensionIntake $intake): ?BrandCatalogueStyle
{
    $brand = resolveBrand($catalogue, $intake, false);
    if (! $brand) {
        return null;
    }

    $needle = normalizeName((string) $intake->style_name);
    if ($needle === '') {
        return null;
    }

    $styles = BrandCatalogueStyle::query()
        ->with('productType.line')
        ->where('brand_catalogue_brand_id', $brand->id)
        ->get();

    foreach ($styles as $style) {
        if (normalizeName($style->name) === $needle) {
            return $style;
        }
    }

    foreach ($styles as $style) {
        $candidate = normalizeName($style->name);
        if ($candidate !== '' && (str_contains($needle, $candidate) || str_contains($candidate, $needle))) {
            return $style;
        }
    }

    return null;
}

/**
 * @return array{0:BrandCatalogueStyle,1:bool}
 */
function createStyleFromIntake(BrandCatalogue $catalogue, HairExtensionIntake $intake, array &$summary): array
{
    $brand = resolveBrand($catalogue, $intake, true);
    if (! $brand) {
        throw new RuntimeException('Brand could not be resolved.');
    }

    $line = resolveLine($brand, $intake);
    $productType = resolveProductType($brand, $line, $intake, $summary);
    $styleName = cleanLabel((string) $intake->style_name) ?: cleanLabel((string) $intake->observed_product_name);

    if ($styleName === '') {
        throw new RuntimeException('Style name is empty.');
    }

    $existing = BrandCatalogueStyle::query()
        ->where('brand_catalogue_product_type_id', $productType->id)
        ->whereRaw('LOWER(name) = ?', [Str::lower($styleName)])
        ->first();

    if ($existing) {
        return [$existing, false];
    }

    $style = new BrandCatalogueStyle([
        'slug' => uniqueSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $styleName),
    ]);
    $style->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_product_type_id' => $productType->id,
        'brand_catalogue_material_id' => null,
        'material_name' => materialFromIntake($intake),
        'name' => $styleName,
        'note' => 'Created from submitted V2 shop-floor intake #'.$intake->id.'.',
        'url' => null,
        'is_active' => true,
        'sort_order' => ((int) BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id)->max('sort_order')) + 10,
    ]);

    $style->save();

    return [$style, true];
}

function resolveBrand(BrandCatalogue $catalogue, HairExtensionIntake $intake, bool $create): ?BrandCatalogueBrand
{
    if ($intake->brand_catalogue_brand_id) {
        $brand = BrandCatalogueBrand::query()->find((int) $intake->brand_catalogue_brand_id);
        if ($brand) {
            return $brand;
        }
    }

    $brandName = cleanLabel((string) $intake->brand_name);
    if ($brandName === '') {
        return null;
    }

    $brand = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->whereRaw('LOWER(name) = ?', [Str::lower($brandName)])
        ->first();

    if ($brand || ! $create) {
        return $brand;
    }

    return BrandCatalogueBrand::query()->create([
        'brand_catalogue_id' => $catalogue->id,
        'name' => $brandName,
        'slug' => uniqueSlug(BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id), $brandName),
        'note' => 'Created from submitted V2 shop-floor intake.',
        'is_active' => true,
        'sort_order' => ((int) BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id)->max('sort_order')) + 10,
    ]);
}

function resolveLine(BrandCatalogueBrand $brand, HairExtensionIntake $intake): BrandCatalogueLine
{
    $path = collect($intake->classification_path ?? [])->map(fn ($value) => cleanLabel((string) $value))->filter()->values();
    $preferred = $path->first() ?: $brand->name;

    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->whereRaw('LOWER(name) = ?', [Str::lower($preferred)])
        ->first();

    if ($line) {
        return $line;
    }

    $brandLine = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->whereRaw('LOWER(name) = ?', [Str::lower($brand->name)])
        ->first();

    if ($brandLine) {
        return $brandLine;
    }

    $onlyLine = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->orderBy('is_default')
        ->orderBy('sort_order')
        ->first();

    if ($onlyLine) {
        return $onlyLine;
    }

    return BrandCatalogueLine::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'name' => $preferred,
        'slug' => uniqueSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), $preferred),
        'note' => 'Created from submitted V2 shop-floor intake.',
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 10,
    ]);
}

function resolveProductType(BrandCatalogueBrand $brand, BrandCatalogueLine $line, HairExtensionIntake $intake, array &$summary): BrandCatalogueProductType
{
    if ($intake->brand_catalogue_product_type_id) {
        $type = BrandCatalogueProductType::query()->find((int) $intake->brand_catalogue_product_type_id);
        if ($type) {
            return $type;
        }
    }

    $name = canonicalProductType((string) $intake->product_type_name, $intake);

    $type = BrandCatalogueProductType::query()
        ->where('brand_catalogue_line_id', $line->id)
        ->get()
        ->first(fn (BrandCatalogueProductType $candidate): bool => normalizeName($candidate->name) === normalizeName($name));

    if ($type) {
        return $type;
    }

    $type = BrandCatalogueProductType::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->get()
        ->first(fn (BrandCatalogueProductType $candidate): bool => normalizeName($candidate->name) === normalizeName($name));

    if ($type) {
        return $type;
    }

    $summary['created_product_types']++;

    return BrandCatalogueProductType::query()->create([
        'brand_catalogue_brand_id' => $brand->id,
        'brand_catalogue_line_id' => $line->id,
        'name' => $name,
        'slug' => uniqueSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $name),
        'note' => 'Created from submitted V2 shop-floor intake.',
        'url' => null,
        'is_active' => true,
        'sort_order' => ((int) BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id)->max('sort_order')) + 10,
    ]);
}

function canonicalProductType(string $name, HairExtensionIntake $intake): string
{
    $clean = cleanLabel($name);
    $path = Str::lower(implode(' ', $intake->classification_path ?? []));
    $brand = Str::lower((string) $intake->brand_name);

    if ($brand === 'dignity' && Str::contains(Str::lower($clean), 'weave')) {
        return 'Human Hair Weave';
    }

    if (Str::contains($path, 'human hair') && Str::contains(Str::lower($clean), ['weave', 'clip'])) {
        return Str::contains(Str::lower($clean), 'clip') ? 'Clip-in Extensions' : 'Human Hair Weave';
    }

    return match (normalizeName($clean)) {
        'weave', 'weaves' => 'Weave',
        'braid', 'braids' => 'Braiding Hair',
        'crochetbraid', 'crochethair' => 'Crochet Hair',
        'ponytail', 'ponytails' => 'Ponytail',
        default => $clean ?: 'Hair Extensions',
    };
}

function materialFromIntake(HairExtensionIntake $intake): ?string
{
    $text = Str::lower(implode(' ', array_filter([
        $intake->product_type_name,
        $intake->style_name,
        implode(' ', $intake->classification_path ?? []),
        $intake->visible_text_notes,
    ])));

    if (str_contains($text, 'human hair')) {
        return 'Human Hair';
    }

    if (str_contains($text, 'synthetic')) {
        return 'Synthetic Hair';
    }

    return null;
}

/**
 * @return array{created:int,updated:int,note:string}
 */
function syncIntakeVariantsToStyle(HairExtensionIntake $intake, BrandCatalogueStyle $style, bool $sync): array
{
    $combos = extractCombinations($intake);
    $created = 0;
    $updated = 0;

    foreach ($combos as $combo) {
        $signature = optionSignature($combo['axes']);
        $sku = findSkuByNormalizedSignature($style, $signature);
        $name = skuName($style, $combo['axes']);

        if (! $sync) {
            if ($sku) {
                $updated++;
            } else {
                $created++;
            }
            continue;
        }

        if (! $sku) {
            $sku = new BrandCatalogueSku([
                'brand_catalogue_style_id' => $style->id,
                'option_signature' => $signature,
                'slug' => uniqueSlug(BrandCatalogueSku::query()->where('brand_catalogue_style_id', $style->id), $name),
            ]);
            $created++;
        } else {
            $updated++;
        }

        $note = cleanLabel(trim(($sku->note ? $sku->note."\n" : '').'Observed in V2 shop-floor intake #'.$intake->id.'.'));

        $sku->fill([
            'name' => $name,
            'description' => $sku->description,
            'note' => $note,
            'is_active' => true,
            'sort_order' => $sku->exists ? $sku->sort_order : ((int) BrandCatalogueSku::query()->where('brand_catalogue_style_id', $style->id)->max('sort_order')) + 10,
        ])->save();

        DB::table('brand_catalogue_sku_variant_options')
            ->where('brand_catalogue_sku_id', $sku->id)
            ->delete();

        foreach ($combo['axes'] as $axis => $value) {
            $variant = ensureVariant($style, $axis);
            $option = ensureOption($variant, $value);
            DB::table('brand_catalogue_sku_variant_options')->insert([
                'brand_catalogue_sku_id' => $sku->id,
                'brand_catalogue_variant_id' => $variant->id,
                'brand_catalogue_variant_option_id' => $option->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    return [
        'created' => $created,
        'updated' => $updated,
        'note' => count($combos).' observed sellable combination'.(count($combos) === 1 ? '' : 's').' synced.',
    ];
}

/**
 * @return list<array{axes:array<string,string>}>
 */
function extractCombinations(HairExtensionIntake $intake): array
{
    $structure = $intake->variant_structure ?: [];
    $matrix = $structure['sku_matrix'] ?? [];

    if (! is_array($matrix) || $matrix === []) {
        return [['axes' => []]];
    }

    $combos = [];

    foreach ($matrix as $row) {
        if (! is_array($row)) {
            continue;
        }

        $axes = [];
        $mainAxis = cleanAxis((string) ($row['main_axis'] ?? $structure['main_axis'] ?? 'Length'));
        $mainValue = cleanValue((string) ($row['main_value'] ?? ''));
        if (usableVariantValue($mainValue)) {
            if (axisLooksWrong($mainAxis, $mainValue)) {
                $mainAxis = valueLooksLength($mainValue) ? 'Length' : 'Colour';
            }
            $axes[$mainAxis] = $mainValue;
        }

        $subAxis = cleanAxis((string) ($row['sub_axis'] ?? 'Colour'));
        $subValue = cleanValue((string) ($row['sub_value'] ?? ''));
        if (usableVariantValue($subValue)) {
            if (axisLooksWrong($subAxis, $subValue)) {
                $subAxis = valueLooksLength($subValue) ? 'Length' : 'Colour';
            }
            $axes[$subAxis] = $subValue;
        }

        $common = $row['common_attributes'] ?? [];
        if (is_array($common)) {
            foreach ($common as $name => $values) {
                $axis = cleanAxis((string) $name);
                foreach ((array) $values as $value) {
                    $value = cleanValue((string) $value);
                    if (usableVariantValue($value)) {
                        $axes[$axis] = $value;
                    }
                }
            }
        }

        $combos[] = ['axes' => $axes];
    }

    return $combos ?: [['axes' => []]];
}

function ensureVariant(BrandCatalogueStyle $style, string $axis): BrandCatalogueVariant
{
    $variant = BrandCatalogueVariant::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->get()
        ->first(fn (BrandCatalogueVariant $candidate): bool => normalizeName($candidate->name) === normalizeName($axis));

    if ($variant) {
        return $variant;
    }

    return BrandCatalogueVariant::query()->create([
        'brand_catalogue_style_id' => $style->id,
        'name' => $axis,
        'variant_type' => variantType($axis),
        'sort_order' => ((int) BrandCatalogueVariant::query()->where('brand_catalogue_style_id', $style->id)->max('sort_order')) + 10,
    ]);
}

function ensureOption(BrandCatalogueVariant $variant, string $value): BrandCatalogueVariantOption
{
    $option = BrandCatalogueVariantOption::query()
        ->where('variant_id', $variant->id)
        ->get()
        ->first(fn (BrandCatalogueVariantOption $candidate): bool => normalizeName((string) $candidate->value) === normalizeName($value));

    if ($option) {
        return $option;
    }

    return BrandCatalogueVariantOption::query()->create([
        'variant_id' => $variant->id,
        'label' => $value,
        'value' => $value,
        'sort_order' => ((int) BrandCatalogueVariantOption::query()->where('variant_id', $variant->id)->max('sort_order')) + 10,
    ]);
}

function findSkuByNormalizedSignature(BrandCatalogueStyle $style, string $signature): ?BrandCatalogueSku
{
    return BrandCatalogueSku::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->get()
        ->first(fn (BrandCatalogueSku $sku): bool => normalizeSignature($sku->option_signature) === normalizeSignature($signature));
}

/**
 * @param array<string,string> $axes
 */
function optionSignature(array $axes): string
{
    if ($axes === []) {
        return '';
    }

    $parts = [];
    foreach ($axes as $axis => $value) {
        $parts[] = cleanAxis($axis).':'.cleanValue($value);
    }

    return implode('|', $parts);
}

/**
 * @param array<string,string> $axes
 */
function skuName(BrandCatalogueStyle $style, array $axes): string
{
    $brand = $style->brand?->name;
    $base = Str::startsWith(Str::lower($style->name), Str::lower((string) $brand))
        ? $style->name
        : trim(($brand ? $brand.' ' : '').$style->name);

    $values = array_values($axes);

    return trim($base.($values ? ' - '.implode(' - ', $values) : ''));
}

function appendStyleNote(BrandCatalogueStyle $style, string $note): void
{
    $existing = (string) $style->note;
    if (str_contains($existing, $note)) {
        return;
    }

    $style->note = cleanLabel(trim($existing.($existing ? "\n" : '').$note));
    $style->save();
}

function styleUrl(BrandCatalogue $catalogue, BrandCatalogueStyle $style): string
{
    $style->loadMissing('productType.line');

    return url('/brand-catalogue/'.$catalogue->id.'/brands/'.$style->brand_catalogue_brand_id.'/lines/'.$style->productType->line->id.'/product-types/'.$style->brand_catalogue_product_type_id.'/styles/'.$style->id);
}

function writeReport(array $rows, bool $sync): string
{
    $dir = public_path('reports');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $path = $dir.'/unlinked-hair-intake-style-sync-'.($sync ? 'synced' : 'dry-run').'-'.date('Ymd-His').'.csv';
    $handle = fopen($path, 'wb');
    fputcsv($handle, ['intake_id', 'brand', 'product_type', 'style', 'action', 'style_id', 'style_url', 'note']);
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    return $path;
}

function variantType(string $axis): string
{
    $key = normalizeName($axis);

    return match (true) {
        str_contains($key, 'colour'), str_contains($key, 'color') => 'colour_code',
        str_contains($key, 'length') => 'length',
        str_contains($key, 'pack') => 'pack_count',
        default => 'text',
    };
}

function cleanAxis(string $value): string
{
    $value = cleanLabel($value);
    $key = normalizeName($value);

    return match ($key) {
        'color' => 'Colour',
        'colour' => 'Colour',
        'pack', 'packcount', 'bundle', 'bundlecount' => 'Pack count',
        'length', 'size' => 'Length',
        default => $value ?: 'Variant',
    };
}

function cleanValue(string $value): string
{
    $value = trim(str_replace(['\\"', '“', '”'], ['"', '"', '"'], $value));
    $value = preg_replace('/\s+/', ' ', $value) ?: $value;

    return trim($value);
}

function usableVariantValue(string $value): bool
{
    $key = normalizeName($value);

    return $value !== '' && ! in_array($key, ['unspecified', 'unknown', 'na', 'none', 'null'], true);
}

function axisLooksWrong(string $axis, string $value): bool
{
    $axisKey = normalizeName($axis);

    return (str_contains($axisKey, 'colour') && valueLooksLength($value))
        || (str_contains($axisKey, 'length') && valueLooksColour($value));
}

function valueLooksLength(string $value): bool
{
    return (bool) preg_match('/^\d+(\.\d+)?\s*(\"|inch|in|cm)?$/i', $value);
}

function valueLooksColour(string $value): bool
{
    return (bool) preg_match('/^(#?\d+[a-z]?|[tp]?\d+[a-z]?\/\d+[a-z]?|[a-z ]+)$/i', $value)
        && ! valueLooksLength($value);
}

function normalizeName(string $value): string
{
    $value = Str::lower($value);
    $value = str_replace(['&'], ['and'], $value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value) ?: '';

    return $value;
}

function normalizeSignature(string $value): string
{
    return Str::lower(preg_replace('/[^a-z0-9:|]+/', '', $value) ?: '');
}

function cleanLabel(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?: $value);
}

function uniqueSlug($query, string $name, ?int $ignoreId = null): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $i = 2;

    while ((clone $query)
        ->where('slug', $slug)
        ->when($ignoreId, fn ($builder) => $builder->where('id', '!=', $ignoreId))
        ->exists()
    ) {
        $slug = $base.'-'.$i;
        $i++;
    }

    return $slug;
}
