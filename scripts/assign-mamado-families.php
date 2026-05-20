<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MamadoProduct;

$dryRun = in_array('--dry-run', $argv, true);
$onlyBrand = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--brand=')) {
        $onlyBrand = substr($arg, 8);
    }
}

function mf_clean_spaces(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\xc2\xa0", '–', '—'], [' ', '-', '-'], $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    $value = preg_replace('/\s+([,.)\]])/', '$1', $value) ?? $value;
    $value = preg_replace('/([(\[])\s+/', '$1', $value) ?? $value;
    $value = preg_replace('/\s+-\s+/', ' - ', $value) ?? $value;
    $value = preg_replace('/\(\s*\)/', '', $value) ?? $value;
    $value = preg_replace('/,+/', ',', $value) ?? $value;
    $value = preg_replace('/\s*,\s*$/', '', $value) ?? $value;
    $value = preg_replace('/\s*\/\s*$/', '', $value) ?? $value;

    return trim($value, " \t\n\r\0\x0B-");
}

function mf_title_case(string $value): string
{
    $preserve = [
        '3X' => true, '4X' => true, '2X' => true, '6X' => true,
        'JBCO' => true, 'B&B' => true, 'Bco' => true, 'PCJ' => true,
        'ORS' => true, 'TCB' => true, 'DAX' => true, 'D&L' => true,
        'QP' => true, 'IC' => true, 'UV' => true, 'SPF' => true,
    ];

    return preg_replace_callback('/\b[A-Za-z0-9&\'\/.-]+\b/', function (array $match) use ($preserve): string {
        $word = $match[0];
        $upper = strtoupper($word);

        if (isset($preserve[$upper]) || preg_match('/^[A-Z0-9&.-]{2,}$/', $word)) {
            return $word;
        }

        if (str_contains($word, "'")) {
            return implode("'", array_map(fn (string $part): string => ucfirst(strtolower($part)), explode("'", $word)));
        }

        return ucfirst(strtolower($word));
    }, $value) ?? $value;
}

function mf_brand_aliases(string $brand): array
{
    $aliases = [
        $brand,
        str_replace('&', 'And', $brand),
        str_replace('&', 'and', $brand),
    ];

    $extra = [
        'ORS' => ['Ors'],
        'Cantu' => ['Cantu Shea Butter', 'Cantu Sb', 'Cantu'],
        'African Pride' => ['Ap', 'Afr Pride', 'African Pride'],
        'Creme of Nature' => ['Con', 'Creme Of Nature'],
        'Eco Style' => ['Ecostyle', 'Ecostyler', 'Eco Styler', 'Eco Style'],
        'Mielle' => ['Mielle', 'Mielle Organics'],
        'Dark and Lovely' => ['Dark & Lovely', 'Dark And Lovely', 'D&L', 'D And L', 'Dark & Lo', 'Dark & Lo Mois Plus'],
        'Fantasia' => ['Fantasia Ic', 'Fantasia IC', 'Fantasia'],
        'Jamaican Mango & Lime' => ['J/M', 'Jml', 'JML', 'Jamaican Mango & Lime'],
        'Ampro Pro Styl' => ['Ampro Pro Styl', 'Ampro'],
        'Creative Image Adore' => ['Adore', 'Creative Image Adore'],
        "Sof'n'free" => ['Snf', 'Sofnfree', "Sof'n'free"],
        'DAX' => ['Dax'],
        'Originals by Africa\'s Best' => ['Ab Org', 'Afr. Best Org', 'Africa\'s Best Org'],
        'Africa\'s Best' => ['Ab', 'Afr. Best', 'Africa\'s Best'],
        'African Pride Dream Kids' => ['Dk'],
        'Hawaiian Silky' => ['Hawaiian Silky', 'Hawaiian Slky'],
        'Elasta QP' => ['Qp', 'Elasta QP'],
        'Sta-Sof-Fro' => ['Ssf', 'Sta-Sof-Fro'],
        'Palmer\'s' => ['Palmer\'S', 'Palmers', 'Palmer\'s'],
        'Luster\'s Pink' => ['Lusters Pink', 'Luster\'S Pink', 'Luster\'s Pink'],
        'Sulfur8' => ['Sulfur 8', 'Sulfur8'],
        'Kids Originals by Africa\'s Best' => ['Ab Ko', 'Afr Best Ko'],
        'Profectiv Mega Growth' => ['Profectiv', 'Profectiv Mega Growth'],
        'Mamado Aromatherapy' => ['Mamado'],
        'Pure NaturALL' => ['Naturall', 'Pure NaturALL'],
        'Aunt Jack\'s' => ['Aj', 'Aunt Jack\'s'],
        'Queen Helene' => ['Q/h', 'Queen Helene'],
        'Salon Pro Exclusives' => ['Salon Pro', 'Salon Pro Exclusives'],
        'Wahl Professional' => ['Wahl', 'Wahl Professional'],
        'Worlds of Curls' => ['World Of Curls', 'Worlds of Curls'],
        'CurlyChic' => ['Curly Chic', 'CurlyChic'],
        'Soft\'n White Swiss' => ['Soft \'N\' White Swiss', 'Soft\'n White Swiss'],
        'Soft & Beautiful Botanicals' => ['S & B Botanicals', 'Soft & Beautiful Botanicals'],
        'Valley Soap' => ['Valleysoap', 'Valley Soap'],
        'Skin Tight' => ['Skintight', 'Skin Tight'],
        'Pro-Line' => ['Proline', 'Pro-Line'],
        'SheaMoisture' => ['Sme', 'SheaMoisture'],
        'Stylin\' Dredz' => ['Stylin Dredz', 'Stylin\' Dredz'],
        'Gummy Professional' => ['Gummy Professional', 'Gummy'],
    ];

    $aliases = array_values(array_unique(array_merge($aliases, $extra[$brand] ?? [])));
    usort($aliases, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    return $aliases;
}

function mf_strip_brand_prefix(string $description, string $brand): string
{
    $value = mf_clean_spaces($description);

    foreach (mf_brand_aliases($brand) as $alias) {
        $pattern = '/^' . preg_quote($alias, '/') . '\s*(?::|-)?\s*/i';
        $next = preg_replace($pattern, '', $value, 1) ?? $value;
        if ($next !== $value) {
            return mf_clean_spaces($next);
        }
    }

    return $value;
}

function mf_strip_commercial_noise(string $value): string
{
    $value = preg_replace('/\s*\((?:[^)]*\b(?:pk|pcs?|ea|each|cs|doz|dozen|box|price|bonus|ns|ml|oz|gms?|grams?)\b[^)]*|[0-9 -]{3,})\)\s*/i', ' ', $value) ?? $value;
    $value = preg_replace('/\s*\{[^}]*\}\s*/', ' ', $value) ?? $value;
    $value = preg_replace('/\b(?:\d+\s*)?(?:pk|pcs?|ea|each|cs|doz|dozen|box)\b\.?/i', ' ', $value) ?? $value;
    $value = preg_replace('/\b(?:bonus|doz price|price per dz|price per dozen|price per|with\s*\d+%?\s*extra)\b/i', ' ', $value) ?? $value;
    $value = preg_replace('/\bx\s*\d+\b/i', ' ', $value) ?? $value;
    $value = preg_replace('/\b\d+\s*x\s*$/i', ' ', $value) ?? $value;
    $value = preg_replace('/\b\d{3,}(?:-\d+)?\b/', ' ', $value) ?? $value;
    $value = preg_replace('/\(\s*\)/', ' ', $value) ?? $value;
    $value = preg_replace('/\s*\/\s*(?=$|\/)/', ' ', $value) ?? $value;
    $value = preg_replace('/(?<=\s)\/(?=\s|$)/', ' ', $value) ?? $value;

    return mf_clean_spaces($value);
}

function mf_strip_variant_markers(string $value): string
{
    $value = preg_replace('/\s*\[\s*(?:colou?r|col|shade)?\s*:?\s*[^\]]+\]\s*/i', ' ', $value) ?? $value;
    $value = preg_replace('/\s*\(\s*(?:colou?r|col|shade)\.?\s*:?\s*[^)]+\)\s*/i', ' ', $value) ?? $value;
    $value = preg_replace('/\s*\(\s*(?:regular|normal|super|mild|coarse|medium|large|extra strength|maximum strength|black|jet black|natural black|brown|burgundy|blonde|red|clear|dark brown|light brown|honey blonde|vivid red|deep red)[^)]*\)\s*$/i', ' ', $value) ?? $value;
    $value = preg_replace('/\s*\[\s*(?:regular|normal|super|mild|coarse|medium|large|extra strength|maximum strength|black|jet black|natural black|brown|burgundy|blonde|red|clear|dark brown|light brown|honey blonde|vivid red|deep red)[^\]]*\]\s*$/i', ' ', $value) ?? $value;

    return mf_clean_spaces($value);
}

function mf_strip_size_markers(string $value): string
{
    $value = preg_replace('/\d+(?:\.\d+)?\s*(?:fl\.?\s*)?(?:oz|ounce|ounces|ml|gms|g|gm|kg|lb|lbs|litre|liter|gal|gram|grams)\b\.?/i', ' ', $value) ?? $value;
    $value = preg_replace('/\b\d+(?:\.\d+)?\s*x\s*\d+(?:\.\d+)?\s*(?:ml|oz|g)\b/i', ' ', $value) ?? $value;
    $value = preg_replace('/\b\d+\s*%\s*extra\b/i', ' ', $value) ?? $value;
    $value = preg_replace('/\b\d+(?:\.\d+)?\s*(?:ml|oz|g)\b/i', ' ', $value) ?? $value;

    return mf_clean_spaces($value);
}

function mf_strip_hair_extension_variants(string $value): string
{
    $value = preg_replace('/\b\d+\s*(?:pcs?|x|pack)\b/i', ' ', $value) ?? $value;
    $value = preg_replace('/\b\d+x(?:vp)?\b/i', ' ', $value) ?? $value;
    $value = preg_replace('/\b\d+(?:\/\d+)+\b/', ' ', $value) ?? $value;
    $value = preg_replace('/\b\d{1,2}(?=\s*(?:$|-|\())/', ' ', $value) ?? $value;

    return mf_clean_spaces($value);
}

function mf_normalize_words(string $value): string
{
    $replacements = [
        '/\bCond\.?\b/i' => 'Conditioner',
        '/\bShamp\.?\b/i' => 'Shampoo',
        '/\bMoist\.?\b/i' => 'Moisturizing',
        '/\bMois\.?\b/i' => 'Moisturizing',
        '/\bRlxr\b/i' => 'Relaxer',
        '/\bRlx\b/i' => 'Relaxer',
        '/\bH\/Lotion\b/i' => 'Hair Lotion',
        '/\bH\/Clr\b/i' => 'Hair Color',
        '/\bClr\b/i' => 'Color',
        '/\bBco\b/i' => 'Black Castor Oil',
        '/\bBlk\b/i' => 'Black',
        '/\bJbco\b/i' => 'JBCO',
        '/\bT\/P\b/i' => 'Texturizer',
        '/\bL\/In\b/i' => 'Leave In',
        '/\bLiQ\b/i' => 'Liquid',
        '/\bOrg\b/i' => 'Originals',
        '/\bNat\b/i' => 'Natural',
    ];

    foreach ($replacements as $pattern => $replacement) {
        $value = preg_replace($pattern, $replacement, $value) ?? $value;
    }

    $value = mf_clean_spaces($value);
    $value = str_replace([
        'Leave - In',
        'No - Lye',
        'Anti - ',
        'Sulfate - Free',
        'Extra - Dry',
    ], [
        'Leave-In',
        'No-Lye',
        'Anti-',
        'Sulfate-Free',
        'Extra-Dry',
    ], $value);

    return mf_clean_spaces($value);
}

function mf_brand_specific_family(string $description, string $brand): ?string
{
    $raw = mf_clean_spaces($description);
    $body = mf_strip_brand_prefix($raw, $brand);

    if ($brand === 'Creative Image Adore' && preg_match('/^Adore\b/i', $raw)) {
        return 'Creative Image Adore Semi-Permanent Hair Color';
    }

    if ($brand === 'Manic Panic' && preg_match('/Manic Panic\s+(Cream|Amplified)/i', $raw, $m)) {
        return 'Manic Panic ' . mf_title_case($m[1]) . ' Hair Color';
    }

    if ($brand === 'Manic Panic' && preg_match('/Manic Panic\s+\[[^\]]+\]\s*4\s*oz/i', $raw)) {
        return 'Manic Panic Cream Hair Color';
    }

    if ($brand === 'Bigen' && preg_match('/Bigen\s+(?:Powder\s+)?(?:Hair\s+)?Colou?r/i', $raw)) {
        return 'Bigen Hair Color';
    }

    if ($brand === 'Bigen' && preg_match('/Bigen\s+Dye\s+Mens\s+Speedy/i', $raw)) {
        return 'Bigen Men\'s Speedy Hair Color';
    }

    if ($brand === 'Bigen' && preg_match('/Bigen\s+Dye\s+Mens\s+Beard/i', $raw)) {
        return 'Bigen Men\'s Beard Color';
    }

    if ($brand === 'Bigen' && preg_match('/Bigen\s+(?:Dye\s+)?Mens\s+Ez\s*Colou?r/i', $raw)) {
        return 'Bigen Men\'s EZ Color';
    }

    if ($brand === 'Revlon' && preg_match('/C\/Silk\s+Moist\.?\s+Rich/i', $raw)) {
        return 'Revlon Colorsilk Moisture Rich Hair Color';
    }

    if ($brand === 'Revlon' && preg_match('/C\/Silk/i', $raw)) {
        return 'Revlon Colorsilk Hair Color';
    }

    if ($brand === 'Sta-Sof-Fro' && preg_match('/Hair Dye/i', $raw)) {
        return 'Sta-Sof-Fro Hair Dye';
    }

    if ($brand === 'Dark and Lovely' && preg_match('/(?:H\/Clr|Rev\/Clr|Go Intense|Fade Resist|Hair Colou?r)/i', $raw)) {
        return 'Dark and Lovely Hair Color';
    }

    if ($brand === 'Dark and Lovely' && preg_match('/Relaxer/i', $raw)) {
        return 'Dark and Lovely Relaxer';
    }

    if ($brand === 'African Pride Dream Kids' && preg_match('/Relaxer|Touch-Up/i', $raw)) {
        return 'African Pride Dream Kids Relaxer';
    }

    if ($brand === 'ORS' && preg_match('/Relaxer/i', $raw)) {
        return 'ORS Olive Oil Relaxer';
    }

    if ($brand === 'African Pride' && preg_match('/\b(Bcm|Fif|Mm|Om|Sm)\b/i', $raw, $m)) {
        $lines = [
            'Bcm' => 'Black Castor Miracle',
            'Fif' => 'Feel It Formula',
            'Mm' => 'Moisture Miracle',
            'Om' => 'Olive Miracle',
            'Sm' => 'Shea Miracle',
        ];
        $line = $lines[ucfirst(strtolower($m[1]))] ?? strtoupper($m[1]);
        $body = preg_replace('/\b(?:Ap|African Pride)\b\s*\b(?:Bcm|Fif|Mm|Om|Sm)\b\s*/i', '', $raw, 1) ?? $body;
        $body = mf_strip_size_markers(mf_strip_commercial_noise($body));
        return mf_clean_spaces('African Pride ' . $line . ' ' . mf_title_case($body));
    }

    if ($brand === 'Creme of Nature' && preg_match('/\b(?:Argan Oil|Pure Honey|Aloe(?:\s*&\s*Black Castor)?|Coconut Milk)\b/i', $raw, $m)) {
        $body = preg_replace('/^Con\s+(?:AR|PH|MHC|AO|CM)\s*-?\s*/i', '', $raw) ?? $body;
        $body = preg_replace('/^Con\s*/i', '', $body) ?? $body;
        $body = mf_strip_brand_prefix($body, $brand);
        $body = mf_strip_size_markers(mf_strip_commercial_noise(mf_strip_variant_markers($body)));
        return mf_clean_spaces('Creme of Nature ' . mf_title_case($body));
    }

    if ($brand === 'Creme of Nature' && preg_match('/Women\'?S.*(?:Gel|Liq|Liquid).*H\/?C(?:lr|olou?r)?|Women\'?S.*Hair Colou?r/i', $raw)) {
        return 'Creme of Nature Women\'s Hair Color';
    }

    if ($brand === 'Creme of Nature' && preg_match('/Mens\s+Permanent\s+Gel\s+Hair\s+Colou?r/i', $raw)) {
        return 'Creme of Nature Men\'s Permanent Gel Hair Color';
    }

    if ($brand === 'Impression' && preg_match('/Pre-Stretched\s+Sb\s+4in1/i', $raw)) {
        return 'Impression Pre-Stretched Super Braid 4X';
    }

    if ($brand === 'Bump Stopper') {
        return 'Bump Stopper';
    }

    return null;
}

function mf_finalize_family(string $family): string
{
    $family = preg_replace('/\s*[\[(]\s*[^)\]]+\s*[\])]\s*$/', ' ', $family) ?? $family;
    $family = preg_replace('/\b\d+(?:\.\d+)?\s*0z\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\b\d+0z\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\b\d+(?:\.\d+)?(?:oz|ml|gms?|g|0z)\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\b(?:Ml|Oz|Gms?)\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\b(?:Ml|Oz|Gms?|G)\d+\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\b\d+\s*\/\s*/', ' ', $family) ?? $family;
    $family = preg_replace('/\b(Zeenat Glow Booster Cream Jar)\s+\1\b/i', '$1', $family) ?? $family;
    $family = preg_replace('/\bSoap\s+G\b/i', 'Soap', $family) ?? $family;
    $family = preg_replace('/\bAmpro Pro Styl\s+Pro Styl\b/i', 'Ampro Pro Styl', $family) ?? $family;
    $family = preg_replace('/\s*[\(\{\[]\s*$/', '', $family) ?? $family;
    $family = preg_replace('/\s*[)}\]]\s*$/', '', $family) ?? $family;
    $family = preg_replace('/(?<!\bIn\s)\b\d+(?:\.\d+)?$/i', '', $family) ?? $family;
    $family = preg_replace('/\s*[\(\{\[]\s*$/', '', $family) ?? $family;
    $family = mf_clean_spaces($family);

    $normalizations = [
        'Eco Style Moroccan Argan Oil Styling Gel' => 'Eco Style Moroccan Argan Oil Styling Gel',
        'Eco Style Argan Gel' => 'Eco Style Moroccan Argan Oil Styling Gel',
        'Eco Style Black Castor & Flaxseed Oil Gel' => 'Eco Style Black Castor & Flaxseed Oil Styling Gel',
        'Eco Style Black Castor & Flaxseed Oil Max. Hair Growth' => 'Eco Style Black Castor & Flaxseed Oil Styling Gel',
        'Eco Style Blue Gel' => 'Eco Style Sport Styling Gel',
        'Eco Style Blue Gel (Sports)' => 'Eco Style Sport Styling Gel',
        'Eco Style Sports Styling Gel - Blue' => 'Eco Style Sport Styling Gel',
        'DAX Bees Wax' => 'DAX Bees Wax',
        'DAX Black Bees Wax' => 'DAX Black Bees Wax',
        'DAX Bergamont Pomade' => 'DAX Bergamot Pomade',
        'DAX Bergamot Pomade' => 'DAX Bergamot Pomade',
    ];

    return $normalizations[$family] ?? $family;
}

function mf_family_from_description(string $description, string $brand): string
{
    if ($specific = mf_brand_specific_family($description, $brand)) {
        return mf_finalize_family($specific);
    }

    $body = mf_strip_brand_prefix($description, $brand);
    $body = mf_normalize_words($body);
    $body = mf_strip_variant_markers($body);
    $body = mf_strip_commercial_noise($body);

    $hairExtensionBrands = ['Obsession', 'Pure NaturALL', 'American Dream', 'Impression', 'Remy NY'];
    if (in_array($brand, $hairExtensionBrands, true)) {
        $body = preg_replace('/\bWve\b/i', 'Weave', $body) ?? $body;
        $body = mf_strip_hair_extension_variants($body);
    }

    $body = mf_strip_size_markers($body);
    $body = preg_replace('/\s+-\s*$/', '', $body) ?? $body;
    $body = mf_clean_spaces($body);

    if ($body === '') {
        $body = mf_strip_size_markers(mf_strip_commercial_noise(mf_strip_variant_markers($description)));
    }

    $family = mf_clean_spaces($brand . ' ' . mf_title_case($body));

    $family = preg_replace('/\s*[\[(]\s*[^)\]]+\s*[\])]\s*$/', ' ', $family) ?? $family;
    $family = preg_replace('/\b\d+(?:\.\d+)?\s*0z\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\b\d+0z\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\b\d+(?:\.\d+)?(?:oz|ml|gms?|g|0z)\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\b(?:Ml|Oz|Gms?)\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\b(?:Ml|Oz|Gms?|G)\d+\b/i', ' ', $family) ?? $family;
    $family = preg_replace('/\b\d+\s*\/\s*/', ' ', $family) ?? $family;
    $family = preg_replace('/\b(Zeenat Glow Booster Cream Jar)\s+\1\b/i', '$1', $family) ?? $family;
    $family = preg_replace('/\bSoap\s+G\b/i', 'Soap', $family) ?? $family;
    $family = preg_replace('/\bAmpro Pro Styl\s+Pro Styl\b/i', 'Ampro Pro Styl', $family) ?? $family;
    $family = preg_replace('/\s*[\(\{\[]\s*$/', '', $family) ?? $family;
    $family = preg_replace('/\s*[)}\]]\s*$/', '', $family) ?? $family;
    $family = preg_replace('/(?<!\bIn\s)\b\d+(?:\.\d+)?$/i', '', $family) ?? $family;
    $family = preg_replace('/\s*[\(\{\[]\s*$/', '', $family) ?? $family;
    $family = mf_clean_spaces($family);

    $normalizations = [
        'Eco Style Moroccan Argan Oil Styling Gel' => 'Eco Style Moroccan Argan Oil Styling Gel',
        'Eco Style Argan Gel' => 'Eco Style Moroccan Argan Oil Styling Gel',
        'Eco Style Black Castor & Flaxseed Oil Gel' => 'Eco Style Black Castor & Flaxseed Oil Styling Gel',
        'Eco Style Black Castor & Flaxseed Oil Max. Hair Growth' => 'Eco Style Black Castor & Flaxseed Oil Styling Gel',
        'Eco Style Blue Gel (Sports)' => 'Eco Style Sport Styling Gel',
        'Eco Style Sports Styling Gel - Blue' => 'Eco Style Sport Styling Gel',
        'DAX Bees Wax' => 'DAX Bees Wax',
        'DAX Black Bees Wax' => 'DAX Black Bees Wax',
        'DAX Bergamont Pomade' => 'DAX Bergamot Pomade',
        'DAX Bergamot Pomade' => 'DAX Bergamot Pomade',
    ];

    return $normalizations[$family] ?? $family;
}

$query = MamadoProduct::query()
    ->whereNotNull('brand_label')
    ->where('brand_label', '<>', '')
    ->orderBy('brand_label')
    ->orderBy('item_code');

if ($onlyBrand !== null && $onlyBrand !== '') {
    $query->where('brand_label', $onlyBrand);
}

$groups = [];
$updated = 0;
$alreadyCorrect = 0;
$total = 0;

foreach ($query->get() as $product) {
    $brand = (string) $product->brand_label;
    $family = $brand === 'Cherish'
        ? (string) $product->family_name
        : mf_family_from_description((string) $product->item_description, $brand);

    if ($family === '') {
        continue;
    }

    $total++;
    $groups[$brand][$family] ??= [
        'count' => 0,
        'sample_codes' => [],
        'sample_descriptions' => [],
    ];
    $groups[$brand][$family]['count']++;

    if (count($groups[$brand][$family]['sample_codes']) < 6) {
        $groups[$brand][$family]['sample_codes'][] = $product->item_code;
        $groups[$brand][$family]['sample_descriptions'][] = $product->item_description;
    }

    if ($product->family_name === $family) {
        $alreadyCorrect++;
        continue;
    }

    $updated++;

    if (! $dryRun) {
        $product->forceFill(['family_name' => $family])->save();
    }
}

ksort($groups);
foreach ($groups as &$families) {
    uasort($families, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
}
unset($families);

$reportDir = __DIR__ . '/../storage/app/mamado';
if (! is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$csvPath = $reportDir . '/all-brand-family-candidates.csv';
$csv = fopen($csvPath, 'w');
fputcsv($csv, ['brand', 'family_name', 'product_count', 'sample_item_codes', 'sample_descriptions']);

foreach ($groups as $brand => $families) {
    foreach ($families as $family => $group) {
        fputcsv($csv, [
            $brand,
            $family,
            $group['count'],
            implode(', ', $group['sample_codes']),
            implode(' || ', $group['sample_descriptions']),
        ]);
    }
}
fclose($csv);

$summary = [
    'dry_run' => $dryRun,
    'only_brand' => $onlyBrand,
    'source_products' => $total,
    'brand_count' => count($groups),
    'family_count' => array_sum(array_map('count', $groups)),
    'updated_rows' => $updated,
    'already_correct_rows' => $alreadyCorrect,
    'report_path' => $csvPath,
    'brands' => array_map(function (array $families): array {
        return [
            'family_count' => count($families),
            'product_count' => array_sum(array_column($families, 'count')),
            'top_families' => array_slice(array_map(fn (array $g): int => $g['count'], $families), 0, 10, true),
        ];
    }, $groups),
];

file_put_contents(
    $reportDir . '/all-brand-family-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
