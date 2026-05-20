<?php

$sourcePath = __DIR__ . '/../storage/app/mamado/shaba-cherish-products-live.json';
$apply = in_array('--apply', $argv ?? [], true);
$force = in_array('--force', $argv ?? [], true);
$reportPath = __DIR__ . '/../storage/app/mamado/shaba-cherish-image-import-report.json';
$htmlCacheDir = __DIR__ . '/../storage/app/mamado/shaba-product-pages';

function normalize_text_value($value)
{
    return strtoupper(preg_replace('/\s+/', '', trim((string) $value)));
}

function normalize_color_value($value)
{
    $value = normalize_text_value($value);

    $map = [
        'TGRAY' => 'TGREY',
        'TCOPPER' => 'TCOPPER',
        'T-COPPER' => 'TCOPPER',
        'T.COPPER' => 'TCOPPER',
    ];

    return $map[$value] ?? $value;
}

function normalize_length_value($value)
{
    return preg_replace('/[^0-9]/', '', (string) $value);
}

function normalize_bundle_value($value)
{
    $value = normalize_text_value($value);

    if ($value === '') {
        return '';
    }

    $value = str_replace(['VP', 'PACK'], '', $value);

    if (preg_match('/([0-9]+)X/', $value, $matches)) {
        return $matches[1] . 'X';
    }

    if (preg_match('/([0-9]+)/', $value, $matches)) {
        return $matches[1] . 'X';
    }

    return $value;
}

function parse_local_variant($variant)
{
    $parts = ['bundle' => '', 'length' => '', 'color' => ''];

    foreach (explode(';', (string) $variant) as $piece) {
        if (strpos($piece, ':') === false) {
            continue;
        }

        [$key, $value] = array_map('trim', explode(':', $piece, 2));
        $key = strtolower($key);

        if ($key === 'bundle') {
            $parts['bundle'] = normalize_bundle_value($value);
        } elseif ($key === 'length') {
            $parts['length'] = normalize_length_value($value);
        } elseif ($key === 'colour' || $key === 'color') {
            $parts['color'] = normalize_color_value($value);
        }
    }

    return $parts;
}

function variant_key($parts)
{
    return ($parts['bundle'] ?? '') . '|' . ($parts['length'] ?? '') . '|' . ($parts['color'] ?? '');
}

function normalize_image_url($url)
{
    $url = html_entity_decode(trim((string) $url), ENT_QUOTES);

    if ($url === '') {
        return null;
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    if (str_starts_with($url, '/')) {
        return 'https://shabacosmetics.com' . $url;
    }

    return $url;
}

function cached_product_html(string $handle, string $cacheDir): ?string
{
    if (! is_dir($cacheDir)) {
        mkdir($cacheDir, 0777, true);
    }

    $path = $cacheDir . '/' . preg_replace('/[^a-z0-9-]+/i', '-', $handle) . '.html';

    if (! is_file($path) || filesize($path) < 1000) {
        $url = 'https://shabacosmetics.com/products/' . $handle;
        $command = 'curl.exe -L -A "Mozilla/5.0" -o ' . escapeshellarg($path) . ' ' . escapeshellarg($url);
        exec($command, $output, $code);

        if ($code !== 0 || ! is_file($path) || filesize($path) < 1000) {
            return null;
        }
    }

    return file_get_contents($path) ?: null;
}

/**
 * Shaba sometimes shows exact colour images only as rendered swatches, not as
 * Shopify variant featured images. This extracts data-val => swatch image URL.
 *
 * @return array<string, string>
 */
function product_swatch_images(string $handle, string $cacheDir): array
{
    static $cache = [];

    if (array_key_exists($handle, $cache)) {
        return $cache[$handle];
    }

    $html = cached_product_html($handle, $cacheDir);
    if ($html === null) {
        return $cache[$handle] = [];
    }

    $swatches = [];
    preg_match_all('/<button\b(?=[^>]*\bdata-val="([^"]+)")(?=[^>]*\bstyle="([^"]*background-image:\s*url\(([^)]+)\)[^"]*)")[^>]*>/i', $html, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $value = normalize_color_value($match[1]);
        $url = normalize_image_url(trim($match[3], " \t\n\r\0\x0B'\""));

        if ($value !== '' && $url !== null && ! str_contains($url, 'no-image')) {
            $swatches[$value] = $url;
        }
    }

    return $cache[$handle] = $swatches;
}

$familySources = [
    'Cherish Bulk - Pre-Stretched Spiral French Curl' => [
        ['handle' => 'cherish-bulk-3x-ps-spiral-french-curl', 'bundle' => '3X'],
    ],
    'Cherish Bulk - Afro Kinky' => [
        ['handle' => 'cherish-bulk-afro-kinky-24'],
    ],
    'Cherish Bulk - Deep Twist' => [
        ['handle' => 'cherish-bulk-deep-twist-22', 'length' => '22'],
        ['handle' => 'cherish-ultimate-comfort-deep-twist-bulk-3x-pack-22', 'bundle' => '3X', 'length' => '22'],
        ['handle' => 'cherish-bulk-deep-twist-16-3xvp', 'bundle' => '3X', 'length' => '16'],
    ],
    'Cherish Bulk - Bohemian' => [
        ['handle' => 'cherish-bulk-bohemian'],
        ['handle' => 'cherish-bulk-bohemian-20-3xvp', 'bundle' => '3X', 'length' => '20'],
    ],
    'Cherish Bulk - Spanish Curl' => [
        ['handle' => 'cherish-bulk-spanish-curl', 'length' => '22'],
    ],
    'Cherish Bulk - Brazilian' => [
        ['handle' => 'cherish-bulk-brazilian', 'length' => '20'],
        ['handle' => 'cherish-bulk-brazilian-20-3xvp', 'bundle' => '3X', 'length' => '20'],
    ],
    'Cherish Bulk - Water Wave' => [
        ['handle' => 'cherish-bulk-water-wave-22'],
        ['handle' => 'cherish-bulk-3x-water-wave-14', 'bundle' => '3X', 'length' => '14'],
        ['handle' => 'cherish-ultimate-comfort-bulk-3x-pack-18-water-wave', 'bundle' => '3X', 'length' => '18'],
        ['handle' => 'cherish-ultimate-comfort-1x-12-water-wave-bulk', 'length' => '12'],
    ],
    'Cherish Bulk - Passion Twist' => [
        ['handle' => 'cherish-bulk-passion-twist'],
        ['handle' => 'cherish-ultimate-comfort-passion-twist-3x-14pack', 'bundle' => '3X', 'length' => '14'],
        ['handle' => 'cherish-ultimate-comfort-passion-twist-3x-18pack', 'bundle' => '3X', 'length' => '18'],
    ],
    'Cherish Bulk - Butterfly Locs' => [
        ['handle' => 'cherish-bulk-butterfly-locs', 'length' => '12'],
        ['handle' => 'cherish-ultimate-comfort-1x-pack-12-butterfly-locs', 'length' => '18'],
        ['handle' => 'cherish-bulk-2x-butterfly-locs-24', 'bundle' => '2X', 'length' => '24'],
        ['handle' => 'cherish-bulk-3x-butterfly-locs', 'bundle' => '3X', 'length' => '14'],
        ['handle' => 'cherish-ultimate-comfort-3x-pack-18-butterfly-locs', 'bundle' => '3X', 'length' => '18'],
    ],
    'Cherish Junior Bulk - Deep Twist' => [
        ['handle' => 'cherish-junior-bulk-3x-deep-twist', 'bundle' => '3X', 'length' => '8'],
    ],
    'Cherish Junior Bulk - Passion Twist' => [
        ['handle' => 'cherish-junior-bulk-3x-passion-twist', 'bundle' => '3X', 'length' => '8'],
    ],
    'Cherish Junior Bulk - Silky Locs' => [
        ['handle' => 'cherish-junior-bulk-3x-silky-locs', 'bundle' => '3X', 'length' => '8'],
    ],
    'Cherish Junior Bulk - Butterfly Locs' => [
        ['handle' => 'cherish-junior-bulk-3x-butterfly-locs', 'bundle' => '3X', 'length' => '8'],
    ],
    'Cherish Junior Bulk - Box Braids' => [
        ['handle' => 'cherish-junior-kids-3x-box-braid-9', 'bundle' => '3X', 'length' => '9'],
    ],
    'Cherish Junior Bulk - Bohemian Curl' => [
        ['handle' => 'cherish-junior-kids-3x-bohemian-curl-8', 'bundle' => '3X', 'length' => '8'],
    ],
    'Cherish Junior Bulk - Water Bulk' => [
        ['handle' => 'cherish-junior-kids-3x-water-bulk-8', 'bundle' => '3X', 'length' => '8'],
    ],
    'Cherish Junior Bulk - Bubbly Curl' => [
        ['handle' => 'cherish-ultimate-comfort-junior-3x-6bubbly-curl', 'bundle' => '3X', 'length' => '6'],
    ],
    'Cherish Ponytail - Miami Girl' => [
        ['handle' => 'cherish-ultimate-comfort-drawstring-ponytail'],
    ],
    'Cherish Ponytail - Amsterdam Girl' => [
        ['handle' => 'cherish-ultimate-comfort-drawstring-ponytail-amsterdam-girl'],
    ],
    'Cherish Ponytail - Afro Puff' => [
        ['handle' => 'cherish-ultimate-comfort-ponytail-afro-puff'],
    ],
];

$shabaData = json_decode(file_get_contents($sourcePath), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

if (! is_array($shabaData)) {
    fwrite(STDERR, 'Failed to decode Shaba JSON: ' . json_last_error_msg() . PHP_EOL);
    exit(1);
}

$shabaProducts = $shabaData['products'] ?? [];
$productsByHandle = [];

foreach ($shabaProducts as $product) {
    $productsByHandle[$product['handle']] = $product;
}

$sourceVariantsByFamily = [];

foreach ($familySources as $family => $sources) {
    foreach ($sources as $source) {
        if (! isset($productsByHandle[$source['handle']])) {
            continue;
        }

        $product = $productsByHandle[$source['handle']];
        $optionNames = [];

        foreach (($product['options'] ?? []) as $option) {
            $optionNames[(int) $option['position']] = strtolower(trim($option['name'] ?? ''));
        }

        $imageByVariantId = [];

        foreach (($product['images'] ?? []) as $image) {
            foreach (($image['variant_ids'] ?? []) as $variantId) {
                $imageByVariantId[(string) $variantId] = $image['src'] ?? null;
            }
        }

        foreach (($product['variants'] ?? []) as $variant) {
            $parts = [
                'bundle' => normalize_bundle_value($source['bundle'] ?? ''),
                'length' => isset($source['length']) ? normalize_length_value($source['length']) : '',
                'color' => '',
            ];

            for ($position = 1; $position <= 3; $position++) {
                $name = $optionNames[$position] ?? '';
                $value = $variant['option' . $position] ?? '';

                if ($value === '' || $value === null) {
                    continue;
                }

                if (str_contains($name, 'length')) {
                    $parts['length'] = normalize_length_value($value);
                } elseif (str_contains($name, 'color') || str_contains($name, 'colour')) {
                    $parts['color'] = normalize_color_value($value);
                }
            }

            $key = variant_key($parts);
            $image = $variant['featured_image']['src'] ?? ($imageByVariantId[(string) $variant['id']] ?? null);
            $imageSource = $image ? 'variant_featured_image' : null;

            if ($image === null && $parts['color'] !== '') {
                $swatches = product_swatch_images($product['handle'], $htmlCacheDir);
                if (isset($swatches[$parts['color']])) {
                    $image = $swatches[$parts['color']];
                    $imageSource = 'shaba_color_swatch';
                }
            }

            $sourceVariantsByFamily[$family][$key] = [
                'handle' => $product['handle'],
                'title' => $product['title'],
                'variant_title' => $variant['title'],
                'image' => $image,
                'image_source' => $imageSource,
                'product_image_count' => count($product['images'] ?? []),
            ];
        }
    }
}

$pdo = new PDO('mysql:host=127.0.0.1;dbname=lhc_catalogue_staging;charset=utf8mb4', 'root', '');
$rows = $pdo->query("select id, item_code, item_description, family_name, variant_name, image_urls, notes from mamado_products where family_name like 'Cherish%' or family_name like 'Cherish -%' order by family_name, item_code")->fetchAll(PDO::FETCH_ASSOC);

$familyCounts = [];

foreach ($rows as $row) {
    $familyCounts[$row['family_name']] = ($familyCounts[$row['family_name']] ?? 0) + 1;
}

$mappedFamilyRows = 0;
$mappedFamilyCount = 0;

foreach ($familyCounts as $family => $count) {
    if (isset($familySources[$family])) {
        $mappedFamilyCount++;
        $mappedFamilyRows += $count;
    }
}

$exactMatches = 0;
$exactMatchesWithVariantImage = 0;
$genericOrNoVariantImage = 0;
$importableRows = [];
$genericImageSkippedRows = [];
$familyStats = [];
$unmatched = [];

foreach ($rows as $row) {
    $family = $row['family_name'];

    if (! isset($familyStats[$family])) {
        $familyStats[$family] = [
            'total' => 0,
            'source_family' => isset($familySources[$family]),
            'exact' => 0,
            'image' => 0,
            'generic' => 0,
            'missing' => 0,
        ];
    }

    $familyStats[$family]['total']++;
    $key = variant_key(parse_local_variant($row['variant_name']));
    $match = $sourceVariantsByFamily[$family][$key] ?? null;

    if ($match) {
        $exactMatches++;
        $familyStats[$family]['exact']++;

        if (! empty($match['image'])) {
            $exactMatchesWithVariantImage++;
            $familyStats[$family]['image']++;
            $importableRows[] = [
                'id' => (int) $row['id'],
                'item_code' => $row['item_code'],
                'family_name' => $family,
                'variant_name' => $row['variant_name'],
                'image_url' => $match['image'],
                'image_source' => $match['image_source'],
                'source_url' => 'https://shabacosmetics.com/products/' . $match['handle'],
                'shaba_product_title' => $match['title'],
                'shaba_variant_title' => $match['variant_title'],
                'existing_image_urls' => json_decode($row['image_urls'] ?: '[]', true, 512, JSON_INVALID_UTF8_SUBSTITUTE) ?: [],
                'notes' => $row['notes'],
            ];
        } else {
            $genericOrNoVariantImage++;
            $familyStats[$family]['generic']++;
            $genericImageSkippedRows[] = [
                'id' => (int) $row['id'],
                'item_code' => $row['item_code'],
                'family_name' => $family,
                'variant_name' => $row['variant_name'],
                'source_url' => 'https://shabacosmetics.com/products/' . $match['handle'],
                'shaba_product_title' => $match['title'],
                'shaba_variant_title' => $match['variant_title'],
                'reason' => 'Exact Shaba variant match, but no variant-specific image URL or matching colour swatch image was found.',
            ];
        }
    } else {
        $familyStats[$family]['missing']++;
        $row['match_key'] = $key;
        $unmatched[] = $row;
    }
}

$shabaVariantCount = 0;
$shabaImageCount = 0;
$shabaProductsWithImages = 0;

foreach ($shabaProducts as $product) {
    $shabaVariantCount += count($product['variants'] ?? []);
    $imageCount = count($product['images'] ?? []);
    $shabaImageCount += $imageCount;

    if ($imageCount > 0) {
        $shabaProductsWithImages++;
    }
}

$summary = [
    'local_cherish_rows' => count($rows),
    'local_families' => count($familyCounts),
    'shaba_product_pages' => count($shabaProducts),
    'shaba_variants' => $shabaVariantCount,
    'shaba_product_pages_with_images' => $shabaProductsWithImages,
    'shaba_images' => $shabaImageCount,
    'mapped_families' => $mappedFamilyCount,
    'mapped_family_rows' => $mappedFamilyRows,
    'exact_variant_matches' => $exactMatches,
    'exact_variant_matches_with_variant_image' => $exactMatchesWithVariantImage,
    'exact_variant_matches_generic_or_no_variant_image' => $genericOrNoVariantImage,
    'importable_variant_specific_images' => count($importableRows),
    'not_exact_matched' => count($rows) - $exactMatches,
];

$importSummary = [
    'dry_run' => ! $apply,
    'force' => $force,
    'updated_rows' => 0,
    'skipped_existing_images' => 0,
    'skipped_without_variant_image' => $genericOrNoVariantImage,
    'skipped_unmatched' => count($rows) - $exactMatches,
    'updated_item_codes' => [],
];

if ($apply) {
    $update = $pdo->prepare('update mamado_products set image_urls = :image_urls, notes = :notes, updated_at = now() where id = :id');

    foreach ($importableRows as $row) {
        $existing = array_values(array_filter($row['existing_image_urls'], static fn ($value) => is_string($value) && trim($value) !== ''));

        if ($existing !== [] && ! $force) {
            $importSummary['skipped_existing_images']++;
            continue;
        }

        $noteLine = sprintf(
            'Shaba image verified by exact family/variant match: %s (%s). Source: %s',
            $row['shaba_product_title'],
            $row['shaba_variant_title'] . '; image source: ' . $row['image_source'],
            $row['source_url'],
        );
        $notes = trim((string) ($row['notes'] ?? ''));

        if ($notes === '') {
            $notes = $noteLine;
        } elseif (! str_contains($notes, $noteLine)) {
            $notes .= PHP_EOL . $noteLine;
        }

        $update->execute([
            'id' => $row['id'],
            'image_urls' => json_encode([$row['image_url']], JSON_UNESCAPED_SLASHES),
            'notes' => $notes,
        ]);

        $importSummary['updated_rows']++;
        $importSummary['updated_item_codes'][] = $row['item_code'];
    }
}

file_put_contents($reportPath, json_encode([
    'summary' => $summary,
    'import' => $importSummary,
    'importable_rows' => $importableRows,
    'generic_image_skipped_rows' => $genericImageSkippedRows,
    'unmatched_sample' => array_slice($unmatched, 0, 100),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$summary['image_import_report_path'] = $reportPath;
$summary['dry_run'] = ! $apply;
$summary['updated_rows'] = $importSummary['updated_rows'];
$summary['skipped_existing_images'] = $importSummary['skipped_existing_images'];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo PHP_EOL . "family\ttotal\tmapped\texact\tvariant_image\tgeneric_or_no_variant_image\tmissing" . PHP_EOL;

foreach ($familyStats as $family => $stats) {
    echo $family . "\t" .
        $stats['total'] . "\t" .
        ($stats['source_family'] ? 'yes' : 'no') . "\t" .
        $stats['exact'] . "\t" .
        $stats['image'] . "\t" .
        $stats['generic'] . "\t" .
        $stats['missing'] . PHP_EOL;
}

echo PHP_EOL . "unmatched_sample" . PHP_EOL;

foreach (array_slice($unmatched, 0, 50) as $row) {
    echo $row['item_code'] . "\t" . $row['family_name'] . "\t" . $row['variant_name'] . "\t" . $row['match_key'] . PHP_EOL;
}
