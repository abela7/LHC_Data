<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MamadoProduct;
use Illuminate\Support\Facades\DB;

$dryRun = in_array('--dry-run', $argv, true);

function normalize_mamado_brand_text(string $value): string
{
    $value = strtolower(html_entity_decode($value));
    $value = str_replace(['&', '+', '_'], [' and ', ' plus ', ' '], $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

$manualAliases = [
    'ecostyle' => 'Eco Style',
    'ecostyler' => 'Eco Style',
    'eco styler' => 'Eco Style',
    'a3 lemon' => 'A3 Lemon',
    'a3' => 'A3 Lemon',
    'ab org' => "Originals by Africa's Best",
    'afr best org' => "Originals by Africa's Best",
    'africa best org' => "Originals by Africa's Best",
    'ab ko' => "Kids Originals by Africa's Best",
    'afr best ko' => "Kids Originals by Africa's Best",
    'ab uo' => "Africa's Best",
    'ab' => "Africa's Best",
    'afr best' => "Africa's Best",
    'african best' => "Africa's Best",
    'ap' => 'African Pride',
    'afr pride' => 'African Pride',
    'dk' => 'African Pride Dream Kids',
    'aj' => "Aunt Jack's",
    'con' => 'Creme of Nature',
    'cr' => 'Camille Rose',
    'crn' => 'Camille Rose',
    'sme' => 'SheaMoisture',
    'snf' => "Sof'n'free",
    'sofnfree' => "Sof'n'free",
    'ssf' => 'Sta-Sof-Fro',
    'd l' => 'Dark and Lovely',
    'd and l' => 'Dark and Lovely',
    'dark lo' => 'Dark and Lovely',
    'dark and lo' => 'Dark and Lovely',
    'jfm' => 'Just For Me',
    'qp' => 'Elasta QP',
    'q h' => 'Queen Helene',
    'gt' => 'Gentle Treatment',
    'j m' => 'Jamaican Mango & Lime',
    'jml' => 'Jamaican Mango & Lime',
    'moco gorila' => 'Moco de Gorila',
    'lets jam' => "Let's Jam",
    'dabur' => 'Dabur',
    'revlon' => 'Revlon',
    'lottabody' => 'Lottabody',
    'wahl' => 'Wahl Professional',
    'zeenat' => 'Zeenat',
    'mamado' => 'Mamado Aromatherapy',
    'bump stopper' => 'Bump Stopper',
    'koji san' => 'Kojie San',
    'kojic clear' => 'Kojic Clear',
    'koji' => 'Kojie San',
    'kojic' => 'Kojie San',
    'tcb' => 'TCB',
    'hawaiian' => 'Hawaiian Silky',
    'africa finest' => 'Africa Finest',
    'magic' => 'Magic Collection',
    'ultra sheen' => 'Ultra Sheen',
    'wild growth' => 'Wild Growth',
    'wild pouss' => 'Wild Pouss',
    'new light' => 'New Light',
    'snb' => 'Soft & Beautiful',
    'adore' => 'Creative Image Adore',
    'ampro' => 'Ampro Pro Styl',
    'curly chic' => 'CurlyChic',
    'curly kids' => 'Curly Kids',
    'lusters pink' => "Luster's Pink",
    'luster s pink' => "Luster's Pink",
    'lusters pcj' => "Luster's PCJ",
    'luster s pcj' => "Luster's PCJ",
    'lusters short looks' => "Luster's Short Looks",
    'luster s short looks' => "Luster's Short Looks",
    'lusters' => "Luster's",
    'luster s' => "Luster's",
    'naturall' => 'Pure NaturALL',
    'natural l' => 'Pure NaturALL',
    'sulfur 8' => 'Sulfur8',
    'sulfur' => 'Sulfur8',
    'profectiv' => 'Profectiv Mega Growth',
    'gummy' => 'Gummy Professional',
    'got2b' => 'Got2b',
    'salon pro' => 'Salon Pro Exclusives',
    'wave nouveau' => 'Wave Nouveau',
    'soft and precious' => 'Soft & Precious',
    'soft and beautiful botanicals' => 'Soft & Beautiful Botanicals',
    's and b botanicals' => 'Soft & Beautiful Botanicals',
    'astral' => 'Astral',
    'infini clear' => 'Infini Clear',
    'caro white' => 'Caro White',
    'diana' => 'Diana',
    'gabri' => 'Gabri Professional',
    'high beam' => 'High Beam',
    'makali' => 'Makali',
    'razac' => 'Razac',
    'care free curl' => 'Care Free Curl',
    'walker tape' => 'Walker Tape',
    'bragg' => 'Bragg',
    'alcolado' => 'Alcolado Glacial',
    'crusader' => 'Crusader',
    'nubian queen' => 'Nubian Queen',
    'colour of nature' => 'Colour Of Nature',
    'countryside' => 'Countryside',
    'dermis' => 'Dermis 8',
    'eversheen' => 'Eversheen',
    'perfect clear' => 'Perfect Clear',
    'perfect glow' => 'Perfect Glow',
    'lemon clear' => 'Lemon Clear',
    'better braids' => 'Better Braids',
    'tortoise' => 'Tortoise',
    'africare' => 'Africare',
    'dettol' => 'Dettol',
    'edge booster' => 'Ebin New York',
    'nature essence' => 'Nature Essence',
    'rapid clear' => 'Rapid Clear',
    'skala' => 'Skala',
    'valleysoap' => 'Valley Soap',
    'beaute reelle' => 'Beaute Reelle',
    'beautiful beginnings' => 'Soft & Beautiful',
    'beautiful texture' => 'Beautiful Textures',
    'beautiful textures' => 'Beautiful Textures',
    'sporting waves' => 'Sporting Waves',
    'remy ny' => 'Remy NY',
    'la touche' => 'La Touche',
    'p la touche' => 'La Touche',
    'mama africa' => 'Mama Africa',
    'world of curls' => 'Worlds of Curls',
    'world' => 'Worlds of Curls',
    'silicon' => 'Silicon Mix',
    'silicon proteina' => 'Silicon Mix',
    'haz' => 'Haz',
    'maxi light' => 'Maxi Light',
    'rubee' => 'Rubee',
    'shimmer lights' => 'Shimmer Lights',
    'shimmer' => 'Shimmer Lights',
    'tend skin' => 'Tend Skin',
    'thicker fuller' => 'Thicker Fuller Hair',
    'otentika' => 'Otentika',
    'delta' => 'Delta',
    'caro' => 'Caro Light',
    'ct regular' => 'Carotone',
    'ct' => 'Carotone',
    'palmers' => "Palmer's",
    'dg' => 'Doo Gro',
    'keracare' => 'Avlon KeraCare',
    'designer touch' => 'Designer Touch',
    'skintight' => 'Skin Tight',
    'dark and natural' => 'Dark & Natural',
    'proline' => 'Pro-Line',
    'virgin hair fertilizer' => 'Virgin Hair Fertilizer',
    'curalene' => 'Curalene',
    'african gold' => 'African Gold',
];

$aliases = [];

foreach (DB::table('observed_brand_mappings')
    ->select('observed_brand', 'canonical_brand')
    ->whereNotNull('canonical_brand')
    ->get() as $mapping) {
    foreach ([$mapping->observed_brand, $mapping->canonical_brand] as $alias) {
        $normalized = normalize_mamado_brand_text((string) $alias);

        if ($normalized === '') {
            continue;
        }

        if (strlen(str_replace(' ', '', $normalized)) < 3 && ! in_array($normalized, ['aj'], true)) {
            continue;
        }

        $aliases[$normalized] = (string) $mapping->canonical_brand;
    }
}

foreach ($manualAliases as $alias => $brand) {
    $aliases[normalize_mamado_brand_text($alias)] = $brand;
}

uksort($aliases, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

$brandCounts = [];
$unmatched = [];
$updated = 0;
$alreadyCorrect = 0;

foreach (MamadoProduct::query()->orderBy('item_code')->get() as $product) {
    $description = normalize_mamado_brand_text($product->item_description);
    $itemCode = normalize_mamado_brand_text($product->item_code);
    $brand = null;

    foreach ($aliases as $alias => $candidateBrand) {
        if ($description === $alias || str_starts_with($description, $alias.' ')) {
            $brand = $candidateBrand;
            break;
        }
    }

    if ($brand === null) {
        foreach ($aliases as $alias => $candidateBrand) {
            if ($itemCode === $alias || str_starts_with($itemCode, $alias)) {
                $brand = $candidateBrand;
                break;
            }
        }
    }

    if ($brand === null) {
        $unmatched[] = [
            'item_code' => $product->item_code,
            'item_description' => $product->item_description,
        ];
    } else {
        $brandCounts[$brand] = ($brandCounts[$brand] ?? 0) + 1;
    }

    if ($product->brand_label === $brand) {
        $alreadyCorrect++;
        continue;
    }

    $updated++;

    if (! $dryRun) {
        $product->forceFill(['brand_label' => $brand])->save();
    }
}

arsort($brandCounts);

$reportDir = __DIR__ . '/../storage/app/mamado';

if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$summary = [
    'dry_run' => $dryRun,
    'total_products' => MamadoProduct::query()->count(),
    'matched_products' => array_sum($brandCounts),
    'unmatched_products' => count($unmatched),
    'matched_brands' => count($brandCounts),
    'updated_rows' => $updated,
    'already_correct_rows' => $alreadyCorrect,
    'top_brands' => array_slice($brandCounts, 0, 80, true),
];

file_put_contents(
    $reportDir . '/brand-assignment-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);

$csv = fopen($reportDir . '/brand-assignment-unmatched.csv', 'w');
fputcsv($csv, ['item_code', 'item_description']);

foreach ($unmatched as $row) {
    fputcsv($csv, [$row['item_code'], $row['item_description']]);
}

fclose($csv);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
