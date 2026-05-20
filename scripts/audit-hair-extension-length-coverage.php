<?php

declare(strict_types=1);

/**
 * Deep audit: Hair Extensions Length labels — inch notation (") coverage.
 *
 * Usage: php scripts/audit-hair-extension-length-coverage.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\HairExtensionLengthLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function is_length_group(string $name): bool
{
    return HairExtensionLengthLabel::isLengthGroupName($name);
}

/** @return array{ok: bool, reason: string} */
function classify_length_label(string $label): array
{
    $label = trim($label);
    if ($label === '') {
        return ['ok' => false, 'reason' => 'empty'];
    }

    if (preg_match('/^\d+(?:\.\d+)?"\s*$/u', $label)) {
        return ['ok' => true, 'reason' => 'standard_inches'];
    }

    if (preg_match('/^\d+(?:\.\d+)?$/u', $label)) {
        return ['ok' => false, 'reason' => 'number_only_no_inch'];
    }

    if (preg_match('/^\d+(?:\.\d+)?\s*(?:inch|inches|in)\.?$/ui', $label)) {
        return ['ok' => false, 'reason' => 'inch_word_not_quote'];
    }

    if (preg_match('/"/u', $label)) {
        return ['ok' => true, 'reason' => 'compound_or_special_with_quote'];
    }

    return ['ok' => false, 'reason' => 'other_non_standard'];
}

$reportDir = public_path('reports');
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$issues = [];
$stats = [
    'retail_length_options_total' => 0,
    'retail_length_options_ok' => 0,
    'retail_length_options_bad' => 0,
    'retail_products_with_length_axis' => 0,
    'retail_products_bad_via_option' => 0,
    'retail_draft_products' => 0,
    'retail_draft_bad_length' => 0,
    'retail_active_products' => 0,
    'retail_active_bad_length' => 0,
    'retail_unpublished_ecommerce' => 0,
    'retail_unpublished_ecommerce_bad_length' => 0,
    'catalogue_length_options_total' => 0,
    'catalogue_length_options_bad' => 0,
    'name_embedded_length_suspects' => 0,
];

// ── 1. Retail variant options (Length groups, Hair Extensions) ──
$retailOptions = DB::table('product_variant_options as o')
    ->join('product_variant_groups as g', 'g.id', '=', 'o.product_variant_group_id')
    ->join('product_families as f', 'f.id', '=', 'g.product_family_id')
    ->where('f.root_catalogue_name', 'Hair Extensions')
    ->where(function ($q): void {
        $q->whereRaw('LOWER(g.name) = ?', ['length'])
            ->orWhereRaw('LOWER(g.name) LIKE ?', ['%length%']);
    })
    ->select(
        'o.id as option_id',
        'o.label',
        'o.value',
        'g.id as group_id',
        'g.name as group_name',
        'f.id as family_id',
        'f.family_name',
    )
    ->get();

foreach ($retailOptions as $row) {
    $stats['retail_length_options_total']++;
    $check = classify_length_label((string) $row->label);
    if ($check['ok']) {
        $stats['retail_length_options_ok']++;
    } else {
        $stats['retail_length_options_bad']++;
        $issues[] = [
            'layer' => 'retail_option',
            'reason' => $check['reason'],
            'label' => $row->label,
            'family' => $row->family_name,
            'family_id' => $row->family_id,
            'option_id' => $row->option_id,
            'product_id' => '',
            'status' => '',
        ];
    }
}

// ── 2. Products linked to length options (by status / publish) ──
$productRows = DB::table('products as p')
    ->join('product_families as f', 'f.id', '=', 'p.product_family_id')
    ->join('product_variant_values as pvv', 'pvv.product_id', '=', 'p.id')
    ->join('product_variant_groups as g', 'g.id', '=', 'pvv.product_variant_group_id')
    ->join('product_variant_options as o', 'o.id', '=', 'pvv.product_variant_option_id')
    ->leftJoin('product_ecommerce_profiles as ecp', function ($join): void {
        $join->on('ecp.product_id', '=', 'p.id')->where('ecp.profile_level', '=', 'sku');
    })
    ->where('f.root_catalogue_name', 'Hair Extensions')
    ->where(function ($q): void {
        $q->whereRaw('LOWER(g.name) = ?', ['length'])
            ->orWhereRaw('LOWER(g.name) LIKE ?', ['%length%']);
    })
    ->select(
        'p.id as product_id',
        'p.name as product_name',
        'p.status',
        'p.is_ecommerce_active',
        'p.is_pos_active',
        'o.label as length_label',
        'f.family_name',
        'ecp.is_published as ecommerce_published',
    )
    ->distinct()
    ->get();

$seenProducts = [];
foreach ($productRows as $row) {
    $stats['retail_products_with_length_axis']++;
    $pid = (int) $row->product_id;
    if (! isset($seenProducts[$pid])) {
        $seenProducts[$pid] = true;
        if ($row->status === 'draft') {
            $stats['retail_draft_products']++;
        } else {
            $stats['retail_active_products']++;
        }
        if (! $row->ecommerce_published) {
            $stats['retail_unpublished_ecommerce']++;
        }
    }

    $check = classify_length_label((string) $row->length_label);
    if (! $check['ok']) {
        $stats['retail_products_bad_via_option']++;
        if ($row->status === 'draft') {
            $stats['retail_draft_bad_length']++;
        } else {
            $stats['retail_active_bad_length']++;
        }
        if (! $row->ecommerce_published) {
            $stats['retail_unpublished_ecommerce_bad_length']++;
        }
        $issues[] = [
            'layer' => 'retail_product_sku',
            'reason' => $check['reason'],
            'label' => $row->length_label,
            'family' => $row->family_name,
            'family_id' => '',
            'option_id' => '',
            'product_id' => $row->product_id,
            'status' => $row->status.'|ec:'.$row->is_ecommerce_active.'|pub:'.($row->ecommerce_published ? '1' : '0'),
        ];
    }
}

// ── 3. Brand catalogue length options (Hair Extensions catalogue) ──
if (Schema::hasTable('brand_catalogues')) {
    $catalogueOptions = DB::table('brand_catalogue_variant_options as o')
        ->join('brand_catalogue_variants as v', 'v.id', '=', 'o.variant_id')
        ->join('brand_catalogue_styles as s', 's.id', '=', 'v.brand_catalogue_style_id')
        ->join('brand_catalogue_brands as b', 'b.id', '=', 's.brand_catalogue_brand_id')
        ->join('brand_catalogues as c', 'c.id', '=', 'b.brand_catalogue_id')
        ->where('c.name', 'Hair Extensions')
        ->where(function ($q): void {
            $q->whereRaw('LOWER(v.name) = ?', ['length'])
                ->orWhereRaw('LOWER(v.name) LIKE ?', ['%length%']);
        })
        ->select('o.id', 'o.label', 's.name as style_name')
        ->get();

    foreach ($catalogueOptions as $row) {
        $stats['catalogue_length_options_total']++;
        $check = classify_length_label((string) $row->label);
        if (! $check['ok']) {
            $stats['catalogue_length_options_bad']++;
            $issues[] = [
                'layer' => 'catalogue_option',
                'reason' => $check['reason'],
                'label' => $row->label,
                'family' => $row->style_name,
                'family_id' => '',
                'option_id' => 'bc:'.$row->id,
                'product_id' => '',
                'status' => '',
            ];
        }
    }
}

// ── 4. Product names that look like bare inch numbers without " in variant ──
$nameSuspects = DB::table('products as p')
    ->join('product_families as f', 'f.id', '=', 'p.product_family_id')
    ->where('f.root_catalogue_name', 'Hair Extensions')
    ->where(function ($q): void {
        $q->where('p.name', 'REGEXP', '[0-9]+[[:space:]]*(inch|inches|in)([^a-z]|$)')
            ->orWhereRaw("p.name REGEXP '[0-9]+[[:space:]]*\"' = 0 AND p.name REGEXP '[[:space:]|·][0-9]{1,2}([[:space:]]|$)'");
    })
    ->select('p.id', 'p.name', 'p.status', 'f.family_name')
    ->limit(200)
    ->get();

// Simpler: names containing " inch" or ending with space+digits without quote
$nameSuspects = DB::table('products as p')
    ->join('product_families as f', 'f.id', '=', 'p.product_family_id')
    ->where('f.root_catalogue_name', 'Hair Extensions')
    ->where(function ($q): void {
        $q->where('p.name', 'like', '% inch%')
            ->orWhere('p.name', 'like', '% Inch%')
            ->orWhere('p.name', 'like', '% inches%');
    })
    ->select('p.id', 'p.name', 'p.status', 'f.family_name')
    ->get();

foreach ($nameSuspects as $row) {
    $stats['name_embedded_length_suspects']++;
    $issues[] = [
        'layer' => 'product_name_text',
        'reason' => 'inch_in_product_name',
        'label' => $row->name,
        'family' => $row->family_name,
        'family_id' => '',
        'option_id' => '',
        'product_id' => $row->id,
        'status' => $row->status,
    ];
}

// ── 5. Hair extension families with Length group but NO length axis on some SKUs ──
$missingLengthAxis = DB::table('products as p')
    ->join('product_families as f', 'f.id', '=', 'p.product_family_id')
    ->where('f.root_catalogue_name', 'Hair Extensions')
    ->whereExists(function ($q): void {
        $q->selectRaw('1')
            ->from('product_variant_groups as g')
            ->whereColumn('g.product_family_id', 'f.id')
            ->where(function ($w): void {
                $w->whereRaw('LOWER(g.name) = ?', ['length'])
                    ->orWhereRaw('LOWER(g.name) LIKE ?', ['%length%']);
            });
    })
    ->whereNotExists(function ($q): void {
        $q->selectRaw('1')
            ->from('product_variant_values as pvv')
            ->join('product_variant_groups as g', 'g.id', '=', 'pvv.product_variant_group_id')
            ->whereColumn('pvv.product_id', 'p.id')
            ->where(function ($w): void {
                $w->whereRaw('LOWER(g.name) = ?', ['length'])
                    ->orWhereRaw('LOWER(g.name) LIKE ?', ['%length%']);
            });
    })
    ->select('p.id', 'p.name', 'p.status', 'f.family_name')
    ->get();

$stats['products_missing_length_assignment'] = $missingLengthAxis->count();

foreach ($missingLengthAxis as $row) {
    $issues[] = [
        'layer' => 'missing_length_axis',
        'reason' => 'sku_has_no_length_variant_value',
        'label' => '',
        'family' => $row->family_name,
        'family_id' => '',
        'option_id' => '',
        'product_id' => $row->id,
        'status' => $row->status,
    ];
}

// ── Output ──
$timestamp = date('Ymd-His');
$csvPath = $reportDir."/hair-extension-length-audit-{$timestamp}.csv";
$fp = fopen($csvPath, 'w');
fputcsv($fp, ['layer', 'reason', 'label', 'family', 'family_id', 'option_id', 'product_id', 'status']);
foreach ($issues as $issue) {
    fputcsv($fp, $issue);
}
fclose($fp);
copy($csvPath, $reportDir.'/hair-extension-length-audit-latest.csv');

echo "=== Hair Extensions Length inch (\") deep audit ===\n\n";

echo "RETAIL LENGTH OPTIONS (variant dictionary)\n";
echo "  Total: {$stats['retail_length_options_total']}\n";
echo "  OK (has \" or valid compound): {$stats['retail_length_options_ok']}\n";
echo "  NOT standard: {$stats['retail_length_options_bad']}\n\n";

echo "RETAIL SKUs USING LENGTH AXIS\n";
echo "  Products with a length variant: {$stats['retail_products_with_length_axis']}\n";
echo "  SKUs with non-standard length label: {$stats['retail_products_bad_via_option']}\n";
echo "  Draft SKUs total: {$stats['retail_draft_products']}\n";
echo "  Draft SKUs bad length: {$stats['retail_draft_bad_length']}\n";
echo "  Active SKUs total: {$stats['retail_active_products']}\n";
echo "  Active SKUs bad length: {$stats['retail_active_bad_length']}\n";
echo "  Ecommerce unpublished SKUs: {$stats['retail_unpublished_ecommerce']}\n";
echo "  Unpublished + bad length: {$stats['retail_unpublished_ecommerce_bad_length']}\n\n";

echo "CATALOGUE LENGTH OPTIONS (brand catalogue, incl. unpublished styles)\n";
echo "  Total: {$stats['catalogue_length_options_total']}\n";
echo "  NOT standard: {$stats['catalogue_length_options_bad']}\n\n";

echo "OTHER GAPS\n";
echo "  Product names still saying 'inch' in text: {$stats['name_embedded_length_suspects']}\n";
echo "  SKUs in families with Length group but no length assigned: {$stats['products_missing_length_assignment']}\n\n";

echo "TOTAL ISSUE ROWS: ".count($issues)."\n";
echo "Report: {$csvPath}\n\n";

if (count($issues) === 0) {
    echo "PASS: All audited length variant labels use inch notation.\n";
} else {
    echo "ISSUES REMAIN — see report. Summary by reason:\n";
    $byReason = [];
    foreach ($issues as $issue) {
        $key = $issue['layer'].'/'.$issue['reason'];
        $byReason[$key] = ($byReason[$key] ?? 0) + 1;
    }
    arsort($byReason);
    foreach ($byReason as $key => $count) {
        echo "  {$count}\t{$key}\n";
    }
}
