<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = DB::table('observed_products as op')
    ->leftJoin('categories as c', 'c.id', '=', 'op.category_id')
    ->select([
        'op.picture_id',
        'op.brand',
        'op.canonical_brand',
        'op.product_name',
    ])
    ->where(function ($query): void {
        $query->whereNull('c.slug')->orWhere('c.slug', '!=', 'hair-extension-moved');
    })
    ->where('op.product_name', '!=', '')
    ->orderByRaw("COALESCE(NULLIF(op.canonical_brand, ''), op.brand)")
    ->orderBy('op.product_name')
    ->get();

$groups = [];
$duplicates = [];

foreach ($rows as $row) {
    $brand = cleanName((string) ($row->canonical_brand ?: $row->brand));
    $product = cleanName((string) $row->product_name);
    $pictureId = (string) $row->picture_id;

    $duplicateKey = normalizeKey($brand).'|'.normalizeKey($product);
    $duplicates[$duplicateKey]['brand'] = $brand;
    $duplicates[$duplicateKey]['product'] = $product;
    $duplicates[$duplicateKey]['pictures'][] = $pictureId;

    $candidate = classifyFamilyCandidate($brand, $product);
    if ($candidate === null) {
        continue;
    }

    $key = normalizeKey($brand).'|'.normalizeKey($candidate['family_name']);
    $groups[$key]['brand'] = $brand;
    $groups[$key]['family_name'] = $candidate['family_name'];
    $groups[$key]['variant_axis'] = $candidate['variant_axis'];
    $groups[$key]['confidence'] = $candidate['confidence'];
    $groups[$key]['notes'] = $candidate['notes'];
    $groups[$key]['items'][$product]['product_name'] = $product;
    $groups[$key]['items'][$product]['variant_name'] = $candidate['variant_name'];
    $groups[$key]['items'][$product]['pictures'][] = $pictureId;
}

$groups = array_values(array_filter($groups, fn (array $group): bool => count($group['items']) >= 2));
usort($groups, fn (array $a, array $b): int => [$a['brand'], $a['family_name']] <=> [$b['brand'], $b['family_name']]);

$duplicateRows = array_values(array_filter($duplicates, fn (array $duplicate): bool => count(array_unique($duplicate['pictures'])) > 1));
usort($duplicateRows, fn (array $a, array $b): int => [$a['brand'], $a['product']] <=> [$b['brand'], $b['product']]);

$reportDir = storage_path('app/catalogue-reports');
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$stamp = date('Ymd-His');
$csvPath = "{$reportDir}/picture-family-candidates-{$stamp}.csv";
$markdownPath = "{$reportDir}/picture-family-candidates-{$stamp}.md";
$duplicatesPath = "{$reportDir}/picture-duplicate-product-evidence-{$stamp}.csv";

writeCandidatesCsv($csvPath, $groups);
writeCandidatesMarkdown($markdownPath, $groups);
writeDuplicatesCsv($duplicatesPath, $duplicateRows);

echo "visible_rows: ".$rows->count()."\n";
echo "family_candidate_groups: ".count($groups)."\n";
echo "candidate_sellable_rows: ".array_sum(array_map(fn (array $group): int => count($group['items']), $groups))."\n";
echo "duplicate_evidence_groups: ".count($duplicateRows)."\n";
echo "csv: {$csvPath}\n";
echo "markdown: {$markdownPath}\n";
echo "duplicates_csv: {$duplicatesPath}\n";

function classifyFamilyCandidate(string $brand, string $product): ?array
{
    $brandKey = normalizeKey($brand);

    $rules = [
        ['brand' => 'baby love', 'regex' => '/^Baby Protecting Jelly (.+)$/i', 'family' => 'Baby Protecting Jelly', 'axis' => 'Fragrance', 'confidence' => 'A'],
        ['brand' => 'bigen', 'regex' => '/^Permanent Powder Hair Colour (.+)$/i', 'family' => 'Permanent Powder Hair Colour', 'axis' => 'Shade', 'confidence' => 'A'],
        ['brand' => 'bigen', 'regex' => '/^Men.?s Speedy Colour (.+)$/i', 'family' => "Men's Speedy Colour", 'axis' => 'Shade', 'confidence' => 'A'],
        ['brand' => 'clere', 'regex' => '/^Perfumed Petroleum Jelly (.+)$/i', 'family' => 'Perfumed Petroleum Jelly', 'axis' => 'Fragrance', 'confidence' => 'A'],
        ['brand' => 'creme of nature', 'regex' => '/^Exotic Shine Color (.+)$/i', 'family' => 'Exotic Shine Color', 'axis' => 'Shade', 'confidence' => 'A'],
        ['brand' => 'dark lovely', 'regex' => '/^Fade Resist Permanent Haircolor (.+)$/i', 'family' => 'Fade Resist Permanent Haircolor', 'axis' => 'Shade', 'confidence' => 'A'],
        ['brand' => 'directions', 'regex' => '/^Semi-Permanent Conditioning Hair Colour (.+)$/i', 'family' => 'Semi-Permanent Conditioning Hair Colour', 'axis' => 'Colour', 'confidence' => 'A'],
        ['brand' => 'ebin new york', 'regex' => '/^24 Hour Edge Tamer Hair Sleek Stick (.+)$/i', 'family' => '24 Hour Edge Tamer Hair Sleek Stick', 'axis' => 'Fragrance', 'confidence' => 'A'],
        ['brand' => 'ebin new york', 'regex' => '/^5 Second Detangler (.+)$/i', 'family' => '5 Second Detangler', 'axis' => 'Hair type', 'confidence' => 'A'],
        ['brand' => 'ebin new york', 'regex' => '/^Tinted Color Temporary Hair Color Spray (.+)$/i', 'family' => 'Tinted Color Temporary Hair Color Spray', 'axis' => 'Shade', 'confidence' => 'A'],
        ['brand' => 'ebin new york', 'regex' => '/^Tinted Lace Aerosol Spray 10X Quick Dry (.+)$/i', 'family' => 'Tinted Lace Aerosol Spray 10X Quick Dry', 'axis' => 'Shade', 'confidence' => 'A'],
        ['brand' => 'ebin new york', 'regex' => '/^Tinted Lace Mousse (.+)$/i', 'family' => 'Tinted Lace Mousse', 'axis' => 'Shade', 'confidence' => 'A'],
        ['brand' => 'ebin new york', 'regex' => '/^Wonder Lace Bond Tinted Lace Melt Aerosol Spray (.+)$/i', 'family' => 'Wonder Lace Bond Tinted Lace Melt Aerosol Spray', 'axis' => 'Shade', 'confidence' => 'A'],
        ['brand' => 'fair white paris', 'regex' => '/^Intense Power Silky Brightening Lotion Perfect Tone with (.+)$/i', 'family' => 'Intense Power Silky Brightening Lotion Perfect Tone', 'axis' => 'Ingredient', 'confidence' => 'A'],
        ['brand' => 'gummy professional', 'regex' => '/^Styling Wax (.+)$/i', 'family' => 'Styling Wax', 'axis' => 'Finish/hold', 'confidence' => 'A'],
        ['brand' => 'ican london', 'regex' => '/^100% Pure Petroleum Jelly (.+)$/i', 'family' => '100% Pure Petroleum Jelly', 'axis' => 'Fragrance/type', 'confidence' => 'B'],
        ['brand' => 'ican london', 'regex' => '/^Face & Body Scrub (.+)$/i', 'family' => 'Face & Body Scrub', 'axis' => 'Variant', 'confidence' => 'A'],
        ['brand' => 'ican london', 'regex' => '/^Jamm Conditioning Gel (.+)$/i', 'family' => 'Jamm Conditioning Gel', 'axis' => 'Hold/ingredient', 'confidence' => 'A'],
        ['brand' => 'ican london', 'regex' => '/^Rich Conditioning Petroleum Jelly (.+)$/i', 'family' => 'Rich Conditioning Petroleum Jelly', 'axis' => 'Fragrance/ingredient', 'confidence' => 'A'],
        ['brand' => 'jamaican mango lime', 'regex' => '/^Jamaican Black Castor Oil (Amla|Lavender|Lemon Grass|Tea Tree|Xtra Dark)$/i', 'family' => 'Jamaican Black Castor Oil', 'axis' => 'Oil variant', 'confidence' => 'A'],
        ['brand' => 'jergens', 'regex' => '/^Oil-Infused (.+?)\s*(?:24HR )?Moisturizer$/i', 'family' => 'Oil-Infused 24HR Moisturizer', 'axis' => 'Formula', 'confidence' => 'B'],
        ['brand' => 'luster s', 'regex' => '/^Texturizer Wave & Curl Creme (.+)$/i', 'family' => 'Texturizer Wave & Curl Creme', 'axis' => 'Strength', 'confidence' => 'A'],
        ['brand' => 'mamado aromatherapy', 'regex' => '/^West Indian Bay Rum(?: (.+))?$/i', 'family' => 'West Indian Bay Rum', 'axis' => 'Strength/type', 'confidence' => 'A', 'variant' => 'optional_original'],
        ['brand' => 'manic panic', 'regex' => '/^Classic High Voltage Semi-Permanent Hair Color Cream (.+)$/i', 'family' => 'Classic High Voltage Semi-Permanent Hair Color Cream', 'axis' => 'Colour', 'confidence' => 'A'],
        ['brand' => 'manic panic', 'regex' => '/^Creamtone Perfect Pastel Semi-Permanent Hair Color Cream (.+)$/i', 'family' => 'Creamtone Perfect Pastel Semi-Permanent Hair Color Cream', 'axis' => 'Colour', 'confidence' => 'A'],
        ['brand' => 'misba 21', 'regex' => '/^Exfoliant Shower Gel (.+)$/i', 'family' => 'Exfoliant Shower Gel', 'axis' => 'Variant', 'confidence' => 'A'],
        ['brand' => 'misba 21', 'regex' => '/^Savon Gommant (.+)$/i', 'family' => 'Savon Gommant Exfoliating Scrub Soap', 'axis' => 'Variant', 'confidence' => 'B'],
        ['brand' => 'moco de gorila', 'regex' => '/^(.+) Hair Gel$/i', 'family' => 'Hair Gel', 'axis' => 'Style/hold', 'confidence' => 'A'],
        ['brand' => 'motions', 'regex' => '/^Classic Formula Hair Relaxer (.+)$/i', 'family' => 'Classic Formula Hair Relaxer', 'axis' => 'Strength', 'confidence' => 'A'],
        ['brand' => 'murray s', 'regex' => '/^(Black|Cream) Beeswax$/i', 'family' => 'Beeswax', 'axis' => 'Variant', 'confidence' => 'A'],
        ['brand' => 'naturelle bio', 'regex' => '/^Natural Moroccan Soap (.+)$/i', 'family' => 'Natural Moroccan Soap', 'axis' => 'Variant', 'confidence' => 'A'],
        ['brand' => 'queen elisabeth', 'regex' => '/^(Beurre de Karit.|Cocoa Butter) Hand and Body Cream$/i', 'family' => 'Hand and Body Cream', 'axis' => 'Ingredient', 'confidence' => 'B'],
        ['brand' => 'queen elisabeth', 'regex' => '/^(Cocoa Butter|Shea Butter) Hand and Body Lotion$/i', 'family' => 'Hand and Body Lotion', 'axis' => 'Ingredient', 'confidence' => 'A'],
        ['brand' => 'raw', 'regex' => '/^(.+) Oil Extra Virgin$/i', 'family' => 'Extra Virgin Oil', 'axis' => 'Oil type', 'confidence' => 'A'],
        ['brand' => 'red by kiss', 'regex' => '/^Tintation Semi-Permanent Hair Color (.+)$/i', 'family' => 'Tintation Semi-Permanent Hair Color', 'axis' => 'Colour', 'confidence' => 'A'],
        ['brand' => 'red one', 'regex' => '/^(Black|Olive|Orange|Red|Violetta) Aqua Hair (?:Gel )?Wax Full Force$/i', 'family' => 'Aqua Hair Gel Wax Full Force', 'axis' => 'Colour', 'confidence' => 'A'],
        ['brand' => 'skala', 'regex' => '/^(.+?) (?:2in1 )?Co-Wash$/i', 'family' => 'Co-Wash', 'axis' => 'Formula', 'confidence' => 'B'],
        ['brand' => 'softsheen carson', 'regex' => '/^(Fragrant|Regular Strength|Skin Conditioning) Shaving Powder$/i', 'family' => 'Shaving Powder', 'axis' => 'Variant', 'confidence' => 'A'],
        ['brand' => 'staso fro', 'regex' => '/^Permanent Powder Hair Colour (.+)$/i', 'family' => 'Permanent Powder Hair Colour', 'axis' => 'Shade', 'confidence' => 'A'],
        ['brand' => 'tropic isle living', 'regex' => '/^Herbal Collection (Aloe Vera|Sage) Jamaican Black Castor Oil$/i', 'family' => 'Herbal Collection Jamaican Black Castor Oil', 'axis' => 'Herbal variant', 'confidence' => 'A'],
        ['brand' => 'tropic isle living', 'regex' => '/^Jamaican Black Castor Oil Smooth Natural Oils (.+)$/i', 'family' => 'Jamaican Black Castor Oil Smooth Natural Oils', 'axis' => 'Fragrance', 'confidence' => 'A'],
        ['brand' => 'victoria super colorful', 'regex' => '/^(.+) Super Whitening Oil 7 Days$/i', 'family' => 'Super Whitening Oil 7 Days', 'axis' => 'Ingredient', 'confidence' => 'A'],
        ['brand' => 'wonder gro', 'regex' => '/^(.+) Hair & Scalp Conditioner$/i', 'family' => 'Hair & Scalp Conditioner', 'axis' => 'Ingredient', 'confidence' => 'B'],
        ['brand' => 'yari', 'regex' => '/^100% Natural Raw Shea Butter & (.+)$/i', 'family' => '100% Natural Raw Shea Butter', 'axis' => 'Ingredient', 'confidence' => 'A'],
        ['brand' => 'yari', 'regex' => '/^100% Natural (.+ Oil)$/i', 'family' => '100% Natural Oil', 'axis' => 'Oil type', 'confidence' => 'B'],
        ['brand' => 'yari', 'regex' => '/^Fast Locks Gel-Wax (.+)$/i', 'family' => 'Fast Locks Gel-Wax', 'axis' => 'Hold', 'confidence' => 'A'],
    ];

    foreach ($rules as $rule) {
        if ($rule['brand'] !== $brandKey || ! preg_match($rule['regex'], $product, $matches)) {
            continue;
        }

        return result($rule['family'], variantFromMatch($rule, $matches), $rule['axis'], $rule['confidence']);
    }

    return classifySpecialCases($brandKey, $product);
}

function classifySpecialCases(string $brandKey, string $product): ?array
{
    if ($brandKey === 'allored professional') {
        if (preg_match('/^Styling Gel (.+)$/i', $product, $matches)) {
            return result('Styling Gel', cleanName($matches[1]), 'Ingredient', 'A');
        }
        if (preg_match('/^7-in-1 Heat Protectant (.+?) Leave-In Conditioner$/i', $product, $matches)) {
            return result('7-in-1 Heat Protectant Leave-In Conditioner', cleanName($matches[1]), 'Formula/ingredient', 'B');
        }
    }

    if ($brandKey === 'creme of nature' && preg_match('/^Argan Oil from Morocco Perfect Edges(?: (.+))?$/i', $product, $matches)) {
        return result('Argan Oil from Morocco Perfect Edges', cleanName($matches[1] ?? 'Original'), 'Hold', 'A');
    }

    if ($brandKey === 'dark lovely' && preg_match('/^(.+) Hair Treat$/i', $product, $matches)) {
        return result('Hair Treat', cleanName($matches[1]), 'Formula', 'A');
    }

    if ($brandKey === 'eco style') {
        foreach (['/^(.+?) Professional Styling Gel$/i', '/^(.+?) Styling Gel$/i', '/^(Professional) Styling Gel$/i'] as $pattern) {
            if (preg_match($pattern, $product, $matches)) {
                return result('Styling Gel', cleanName($matches[1]), 'Formula/hold', 'B');
            }
        }
    }

    if ($brandKey === 'jergens' && preg_match('/^Ultra Healing (Fragrance Free )?Extra Dry Skin Moisturizer$/i', $product, $matches)) {
        return result('Ultra Healing Extra Dry Skin Moisturizer', trim((string) ($matches[1] ?? '')) !== '' ? 'Fragrance Free' : 'Original', 'Fragrance', 'A');
    }

    if ($brandKey === 'shine n jam') {
        $map = [
            'Conditioning Gel Regular Hold' => 'Regular Hold',
            'Extra Hold Conditioning Gel' => 'Extra Hold',
            'Supreme Hold Conditioning Gel' => 'Supreme Hold',
        ];
        if (isset($map[$product])) {
            return result('Conditioning Gel', $map[$product], 'Hold', 'A');
        }
    }

    return null;
}

function variantFromMatch(array $rule, array $matches): string
{
    if (($rule['variant'] ?? '') === 'optional_original') {
        return cleanName($matches[1] ?? '') !== '' ? cleanName($matches[1]) : 'Original';
    }

    return cleanName((string) end($matches));
}

function result(string $family, string $variant, string $axis, string $confidence): array
{
    return [
        'family_name' => $family,
        'variant_name' => $variant,
        'variant_axis' => $axis,
        'confidence' => $confidence,
        'notes' => $confidence === 'A'
            ? 'Strong same-family candidate from picture source; variant is visible in product text.'
            : 'Likely same-family candidate, but review before automatic SKU publishing.',
    ];
}

function writeCandidatesCsv(string $path, array $groups): void
{
    $handle = fopen($path, 'wb');
    fputcsv($handle, ['brand', 'family_name', 'variant_axis', 'confidence', 'product_count', 'variants', 'products', 'picture_ids', 'notes']);

    foreach ($groups as $group) {
        $items = array_values($group['items']);
        fputcsv($handle, [
            $group['brand'],
            $group['family_name'],
            $group['variant_axis'],
            $group['confidence'],
            count($items),
            implode(' | ', array_map(fn (array $item): string => $item['variant_name'], $items)),
            implode(' | ', array_map(fn (array $item): string => $item['product_name'], $items)),
            implode(' | ', array_map(fn (array $item): string => implode(',', array_unique($item['pictures'])), $items)),
            $group['notes'],
        ]);
    }

    fclose($handle);
}

function writeCandidatesMarkdown(string $path, array $groups): void
{
    $lines = [
        '# Picture Family Candidates',
        '',
        'Only rows where the core product is the same and the visible difference looks like a variant are included.',
        '',
    ];

    foreach ($groups as $group) {
        $lines[] = sprintf('## %s - %s (%s)', $group['brand'], $group['family_name'], $group['confidence']);
        $lines[] = sprintf('- Variant axis: %s', $group['variant_axis']);
        $lines[] = sprintf('- Notes: %s', $group['notes']);
        foreach ($group['items'] as $item) {
            $lines[] = sprintf('  - %s: %s [%s]', $item['variant_name'], $item['product_name'], implode(', ', array_unique($item['pictures'])));
        }
        $lines[] = '';
    }

    file_put_contents($path, implode("\n", $lines));
}

function writeDuplicatesCsv(string $path, array $duplicates): void
{
    $handle = fopen($path, 'wb');
    fputcsv($handle, ['brand', 'product_name', 'picture_ids']);

    foreach ($duplicates as $duplicate) {
        fputcsv($handle, [
            $duplicate['brand'],
            $duplicate['product'],
            implode(', ', array_unique($duplicate['pictures'])),
        ]);
    }

    fclose($handle);
}

function cleanName(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function normalizeKey(string $value): string
{
    $value = Str::ascii($value);
    $value = strtolower($value);
    $value = str_replace('&', 'and', $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}
