<?php

use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueLine;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dryRun = in_array('--dry-run', $argv, true);
$families = collect(pdfFamilies());
$skuCount = $families->sum(fn (array $family): int => count($family['records']));

if ($dryRun) {
    echo "Smart Hair Intl order-sheet dry run.\n";
    echo 'Families/styles: '.$families->count()."\n";
    echo "SKU variants: {$skuCount}\n\n";

    $families
        ->groupBy('line_name')
        ->each(function (Collection $lineFamilies, string $lineName): void {
            echo "{$lineName}: {$lineFamilies->count()} families / ".$lineFamilies->sum(fn (array $family): int => count($family['records']))." SKUs\n";
        });

    exit(0);
}

$summary = DB::transaction(function () use ($families): array {
    $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->firstOrFail();
    $brand = findOrCreateSmartBrand($catalogue);

    $brand->fill([
        'name' => 'Smart',
        'slug' => uniqueBrandSlug($catalogue, 'smart', $brand->id),
        'url' => 'https://smartbraid.co.uk/',
        'note' => mergeNote($brand->note, 'Master brand for Smart Hair Intl ranges. Smart Braid, X-Smart, Vivitress, Remy Chaser, Boho Collection, Soft Crush, So Natural, Fashion Wigs, Lace Front Wigs and Glamlace are treated as lines/sub-brands under Smart. Variant matrices are overlaid from the Smart Hair Intl order sheet dated 25-07-25 and should be shop-checked before retail publishing.'),
        'is_active' => true,
    ])->save();

    $lineModels = [];
    foreach (lineConfigs() as $lineName => $lineConfig) {
        $lineModels[$lineName] = findOrCreateLine($brand, $lineName, $lineConfig['url'], $lineConfig['sort_order']);
    }

    $productTypes = [];
    foreach ($families->groupBy(fn (array $family): string => $family['line_name'].'|'.$family['product_type']) as $key => $typeFamilies) {
        $first = $typeFamilies->first();
        $line = $lineModels[$first['line_name']];
        $productTypes[$key] = findOrCreateProductType(
            $brand,
            $line,
            $first['product_type'],
            productTypeSortOrder($families, $first['line_name'], $first['product_type']),
            $first['line_name'],
        );
    }

    $createdStyles = 0;
    $updatedStyles = 0;
    $createdSkus = 0;
    $updatedSkus = 0;
    $styleIds = [];

    foreach ($families as $index => $family) {
        $line = $lineModels[$family['line_name']];
        $productType = $productTypes[$family['line_name'].'|'.$family['product_type']];
        $style = findExistingLineStyle($line, $family['name'], $family['aliases']);

        if (! $style) {
            $style = new BrandCatalogueStyle([
                'slug' => scopedSlug(BrandCatalogueStyle::query()->where('brand_catalogue_product_type_id', $productType->id), $family['name']),
            ]);
            $createdStyles++;
        } else {
            $updatedStyles++;
        }

        $style->fill([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $productType->id,
            'brand_catalogue_material_id' => null,
            'material_name' => $family['material_name'],
            'name' => $family['name'],
            'note' => mergeNote($style->note, styleNote($family)),
            'url' => $family['url'],
            'is_active' => true,
            'sort_order' => $style->exists ? $style->sort_order : $index * 10,
        ])->save();

        [$created, $updated] = syncVariantsAndSkus($style, collect($family['records']), $family);

        $createdSkus += $created;
        $updatedSkus += $updated;
        $styleIds[] = $style->id;
    }

    return [
        'brand_id' => $brand->id,
        'brand_name' => $brand->name,
        'lines_touched' => count($lineModels),
        'product_types_touched' => count($productTypes),
        'styles_created' => $createdStyles,
        'styles_updated' => $updatedStyles,
        'styles_total_touched' => count(array_unique($styleIds)),
        'skus_created' => $createdSkus,
        'skus_updated' => $updatedSkus,
        'source_skus' => $families->sum(fn (array $family): int => count($family['records'])),
        'brand_url' => url("/brand-catalogue/{$catalogue->id}/brands/{$brand->id}"),
    ];
});

echo "Smart Hair Intl order-sheet structure imported.\n";
foreach ($summary as $key => $value) {
    echo "{$key}: {$value}\n";
}

/**
 * @return array<string, array{url:string,sort_order:int}>
 */
function lineConfigs(): array
{
    return [
        'Smart Braid' => ['url' => 'https://smartbraid.co.uk/product-category/braids/smart-braid/', 'sort_order' => 10],
        'X-Smart' => ['url' => 'https://smartbraid.co.uk/product-category/braids/x-smart/', 'sort_order' => 20],
        'Vivitress' => ['url' => 'https://smartbraid.co.uk/product-category/crotchet/vivitress/', 'sort_order' => 30],
        'Smart Crochet / Bulk' => ['url' => 'https://smartbraid.co.uk/product-category/crotchet/', 'sort_order' => 40],
        'Boho Collection' => ['url' => 'https://smartbraid.co.uk/product-category/boho-style/', 'sort_order' => 50],
        'Remy Chaser' => ['url' => 'https://smartbraid.co.uk/product-category/remy-chaser/', 'sort_order' => 60],
        'Remy Chaser Clip' => ['url' => 'https://smartbraid.co.uk/product-category/remy-chaser/', 'sort_order' => 70],
        'Natural Bundle Weave' => ['url' => 'https://smartbraid.co.uk/product-category/smart-natural-bundle/', 'sort_order' => 80],
        'Soft Crush' => ['url' => 'https://smartbraid.co.uk/', 'sort_order' => 90],
        'So Natural' => ['url' => 'https://smartbraid.co.uk/', 'sort_order' => 100],
        'Fashion Wigs' => ['url' => 'https://smartbraid.co.uk/product-category/smart-fashion-wig/', 'sort_order' => 110],
        'Lace Front Wigs' => ['url' => 'https://smartbraid.co.uk/product-category/smart-fashion-wig/', 'sort_order' => 120],
        'Glamlace Wigs' => ['url' => 'https://smartbraid.co.uk/product-category/glamlace/', 'sort_order' => 130],
        'Glamlace Ponytails' => ['url' => 'https://smartbraid.co.uk/product-category/smart-ponytail/', 'sort_order' => 140],
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function pdfFamilies(): array
{
    $smartBase = ['1', '1B', '2', '4'];
    $smartShort = ['1', '1B', '2', '4', 'T1B/27', 'T1B/30', 'T1B/900'];
    $basicSix = ['1', '1B', '2', '4', '27', '30'];
    $basicEight = ['1', '1B', '2', '4', '27', '30', 'T1B/27', 'T1B/30'];
    $basicTen = ['1', '1B', '2', '4', '27', '30', '613', 'T1B/27', 'T1B/30', 'T1B/900'];
    $bohoColours = ['1', '1B', '2', '27', '30', 'BUG', 'P27', 'P30', 'P350', 'T1B/27', 'T1B/30', 'T1B/350'];
    $naturalBundleColours = ['1', '1B', '2', '4', '27', '30', 'T1B/27', 'T1B/30'];
    $bohemianBundleColours = ['1', '1B', '2', '4', '27', '30', '613', 'MTB/27', 'MTB/30', 'MTB/BUG', 'SP2/4/30', 'SP4/27/613'];
    $softCrushColours = ['1', '1B', '2', '4', '27', '30'];
    $fashionCore = ['1', '1B', '2', '4', 'SP1B/27', 'SP1B/30'];
    $glamlace13x5 = ['1', '1B', '2', '99J', 'F1B/30', 'F1B/27', 'F4/30', '4327', 'TT27', 'TT30', 'TT613', 'BUTLERSCOTCH', 'STRAWBERRY BLONDE'];

    return [
        f('Smart Braid', 'Synthetic Braiding Hair', 'Synthetic Fiber', 'Smart Braid Pre-Stretched 3X Pack 16"', lc('16"', ['1', '1B', '2', '4', '27', '30', 'T1B/27', 'T1B/30', 'T1B/900', '613', 'T1B/350', 'T1B/RED', 'T1B/SILVER', 'T1B/BLUE', 'T1B/PURPLE'])),
        f('Smart Braid', 'Synthetic Braiding Hair', 'Synthetic Fiber', 'Smart Braid Pre-Stretched 6X Pack 16"', lc('16"', $smartShort)),
        f('Smart Braid', 'Synthetic Braiding Hair', 'Synthetic Fiber', 'Smart Braid Pre-Stretched 3X Pack 20"', lc('20"', $smartShort)),
        f('Smart Braid', 'Synthetic Braiding Hair', 'Synthetic Fiber', 'Smart Braid Pre-Stretched 2X Pack 26"', lc('26"', ['1', '1B', '2', '4', '27', '30', '900', '613', 'T1B/27', 'T1B/30', 'T1B/350', 'T1B/900', 'T1B/RED', 'T1B/PINK', 'T1B/SILVER', 'T1B/GREEN', 'T1B/BLUE', 'T1B/PURPLE'])),
        f('Smart Braid', 'Synthetic Braiding Hair', 'Synthetic Fiber', 'Smart Braid Pre-Stretched 3X Pack 28"', lc('28"', ['1', '1B', '2', '4', '27', '30', '33', '900', '99J', '350', '613', 'T1B/27', 'T1B/30', 'T1B/33', 'T1B/900', 'T1B/350', 'T1B/PINK', 'T1B/RED', 'T1B/GREEN', 'T1B/SILVER', 'T1B/PURPLE', 'T1B/BLUE'])),
        f('Smart Braid', 'Synthetic Braiding Hair', 'Synthetic Fiber', 'Smart Braid Pre-Stretched 6X Pack 28"', lc('28"', ['1', '1B', '2', '4', '27', '30', '613', 'T1B/27', 'T1B/30', 'T1B/900'])),
        f('Smart Braid', 'Synthetic Braiding Hair', 'Synthetic Fiber', 'Smart Braid Pre-Stretched 8X Pack 28"', lc('28"', $smartShort)),
        f('Smart Braid', 'Synthetic Braiding Hair', 'Synthetic Fiber', 'Smart Braid Pre-Stretched 10X Pack 28"', lc('28"', $smartShort)),
        f('Smart Braid', 'Synthetic Braiding Hair', 'Synthetic Fiber', 'Smart Braid Pre-Stretched 2X Pack 36"', lc('36"', ['1', '1B', '2', '4', '27', '30'])),

        f('X-Smart', 'Synthetic Braiding Hair', 'Synthetic Fiber', 'X-Smart Pre-Stretched 2X Pack 26" (Fire Resistance)', lc('26"', ['1', '1B', '2', '4', '27', '30', '33', '99J', '613', '900', 'L/RED', 'SILVER', 'BLUE', 'PURPLE', 'GREEN', 'PERIWINKLE', 'LILAC', 'T1B/27', 'T1B/30', 'T1B/BLUE', 'T1B/PURPLE', 'T1B/SILVER', 'T1B/ORANGE', 'T1B/PERIWINKLE', 'T1B/VPINK', 'T1B/LILAC', 'T1B/RED', 'T1B/900', 'T1B/PINK', 'T1B/ORANGE', 'T1B/144', 'T27/613', 'RED/YELLOW', 'PURPLE/60', 'T1B/L-BLUE', 'T1B/LRED', 'T1B/GREEN', 'S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10', 'S11', 'S12', 'S13', 'S14', 'S15', 'S16', 'S17', 'S18', 'S19', 'S20'])),
        f('X-Smart', 'Synthetic Braiding Hair', 'Synthetic Fiber', 'X-Smart Pre-Stretched 3X Pack 26"', lc('26"', ['1', '1B', '2', '4', '27', '30', '33', '99J', '613', '900', 'VINTAGE ROSE', 'PURPLE', 'PERIWINKLE', 'LILAC', 'T1B/27', 'T1B/30', 'T1B/BLUE', 'T1B/SILVER', 'T1B/PERIWINKLE', 'T1B/LILAC', 'T1B/900', 'T1B/PINK', 'T1B/VINTAGE ROSE', 'T1B/GREEN', 'T1B/60'])),

        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Water Wave Bulk 22"', lc('22"', ['1', '1B', '2', '4', '27', '30', 'BUG', 'T1B/27', 'T1B/30', 'T1B/BUG'])),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Deep Twist Bulk 22"', lc('22"', ['1', '1B', '2', '4', '27', '30', 'BUG', 'T1B/27', 'T1B/30', 'T1B/BUG'])),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress 2X Water Wave Fro Twist 12"', lc('12"', $basicTen), ['Vivitress 2x Water Wave Afro-Twist 12"']),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Mega Pack 3X Water Wave Fro Twist 16"', lc('16"', ['1', '1B', '2', '4', '27', '30', '613', 'T1B/27', 'T1B/30', 'T1B/BLOND']), ['Vivitress Mega Pack 3X Water Wave Afro-Twist 16"']),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Mega Pack 3X Water Wave Fro Twist 24"', lc('24"', ['1', '1B', '2', '4', '27', '30', '613', 'T1B/27', 'T1B/30', 'T1B/BLOND']), ['Vivitress Mega Pack 3X Water Wave Afro-Twist 24"']),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Mega Pack 3X Water Wave Fro Twist 30"', lc('30"', $basicTen), ['Vivitress Mega Pack 3x Water Wave Afro-Twist 30"']),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Afro Kinky Bulk 24"', lc('24"', ['1', '1B', '2', '4', '27', '30', 'T1B/27', 'T1B/30', 'TBG', 'GREY (280)'])),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Mega Pack 3X Springy Bohemian Twist 24"', lc('24"', $basicTen), ['Vivitress Mega Pack 3x Springy Bohemian Twist 24"']),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Mega Pack 3X Deep Twist 22"', lc('22"', $basicSix)),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Mega Pack 3X Water Wave 22"', lc('22"', ['1', '1B', '2', '4', '27', '30', 'T1B/27', 'T1B/30', 'T1B/BUG'])),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Mega Pack 3X Passion Twist 18"', lc('18"', $basicSix)),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Mega Pack 3X Butterfly Locks 18"', lc('18"', ['1', '1B', '2', '4', '27', '30', 'T1B/27', 'T1B/30']), ['Vivitress Mega Pack 3x Butterfly Locs 18"']),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Mega Pack 3X French Curl 22"', lc('22"', ['1', '1B', '2', '4', '27', '30', 'T1B/27', 'T1B/30', '613', '900', '350', 'T1B/900', 'T1B/350', '27/613', 'T1B/BG', 'T27/613']), ['Vivitress Mega Pack 3x French Curl 22"']),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Mega Pack 3X French Curl 28"', lc('28"', ['1', '1B', '4', '27', '30', 'T1B/27', 'T1B/30'])),
        f('Smart Crochet / Bulk', 'Crochet Hair', 'Synthetic Fiber', 'Smart Box Butterfly 18"', lc('18"', ['1B', '2', '4', '27', '30'])),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Jamaican Locks 22"', lc('22"', $basicSix)),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Curly Faux Locks', lcm(['18"' => ['1', '1B', '2', '4', '27', '30', 'BUG', 'T1B/27', 'T1B/30', 'T1B/BUG'], '24"' => $basicSix, '36"' => $smartBase]), ['Vivitress Curly Faux Locks (All Lengths)']),
        f('Vivitress', 'Crochet Hair', 'Synthetic Fiber', 'Vivitress Mega Pack 3X Curly Faux Locks 18"', lc('18"', ['1', '1B', '2', '4', '27', '30', 'T1B/27', 'T1B/30', 'T1B/BUG']), ['Vivitress Mega Pack 3x Curly Faux Locks 18"']),
        f('Smart Crochet / Bulk', 'Crochet Hair', 'Synthetic Fiber', 'Smart Twist Braid 20"', lc('20"', ['1', '1B', '2', '27'])),
        f('Smart Crochet / Bulk', 'Crochet Hair', 'Synthetic Fiber', 'Smart Box Braid 20"', lc('20"', ['1', '1B', '2', '27'])),
        f('Smart Crochet / Bulk', 'Crochet Hair', 'Synthetic Fiber', 'Smart Deep Twist Bulk 22"', lc('22"', ['1', '1B', '2', '4', 'T1B/27', 'T1B/30', 'T1B/PURPLE', 'T1B/144', 'T1B/60'])),
        f('Smart Crochet / Bulk', 'Crochet Hair', 'Synthetic Fiber', 'Smart Pre-Loop Water Bulk 22"', lc('22"', ['1', '1B', '2', '4', 'T1B/27', 'T1B/30', 'T1B/BUG'])),

        f('Boho Collection', 'Boho Crochet Hair', 'Synthetic Fiber', 'Vivitress Bohemian Box 26"', lc('26"', $basicEight)),
        f('Boho Collection', 'Boho Crochet Hair', 'Synthetic Fiber', 'Vivitress Marvel Locks 26"', lc('26"', $basicEight)),
        f('Boho Collection', 'Boho Crochet Hair', 'Synthetic Fiber', 'Vivitress Water Locks 20"', lc('20"', $basicEight)),
        f('Boho Collection', 'Boho Crochet Hair', 'Synthetic Fiber', 'Vivitress Body Wave 24"', lc('24"', $basicEight)),
        f('Boho Collection', 'Boho Crochet Hair', 'Synthetic Fiber', 'Vivitress Deep Box 26"', lc('26"', $basicEight)),
        f('Boho Collection', 'Boho Crochet Hair', 'Synthetic Fiber', 'Vivitress Goddess Locs 22"', lc('22"', $basicEight)),
        f('Boho Collection', 'Boho Crochet Hair', 'Synthetic Fiber', 'Vivitress Deep Wave 24"', lc('24"', $basicEight)),
        f('Boho Collection', 'Boho Bulk Hair', 'Synthetic Fiber', 'Boho Deep Bulk', lcm(['18"' => $bohoColours, '24"' => $bohoColours])),
        f('Boho Collection', 'Boho Bulk Hair', 'Synthetic Fiber', 'Boho Water Bulk', lcm(['18"' => $bohoColours, '24"' => $bohoColours])),

        f('Remy Chaser', 'Human Hair Weave', 'Human Hair', 'Remy Chaser Straight Wave', remyChaserWeaveRecords()),
        f('Remy Chaser', 'Human Hair Weave', 'Human Hair', 'Remy Chaser Body Wave', remyChaserWeaveRecords()),
        f('Remy Chaser', 'Human Hair Weave', 'Human Hair', 'Remy Chaser Deep Wave', remyChaserWeaveRecords()),
        f('Remy Chaser', 'Human Hair Weave', 'Human Hair', 'Remy Chaser Water Wave', remyChaserWeaveRecords()),
        f('Remy Chaser', 'Human Hair Weave', 'Human Hair', 'Remy Chaser Natural Wave', lcm(['16"' => ['Natural Color', 'Natural Brown'], '20"' => ['Natural Color', 'Natural Brown'], '30"' => ['Natural Color', 'Natural Brown']])),
        f('Remy Chaser', 'Human Hair Weave', 'Human Hair', 'Remy Chaser Super Wave', lcm(['20"' => ['Natural Color', 'Natural Brown'], '30"' => ['Natural Color', 'Natural Brown']])),
        f('Remy Chaser', 'Human Hair Weave', 'Human Hair', 'Remy Chaser Yaki Straight', lcm(['20"' => ['Natural Color', 'Natural Brown'], '30"' => ['Natural Color', 'Natural Brown']])),

        f('Remy Chaser Clip', 'Human Hair Clip-Ins', 'Human Hair', 'Remy Chaser Kinky Straight Clip (9Pc) 18"', lc('18"', ['Natural Black', 'Natural Brown'])),
        f('Remy Chaser Clip', 'Human Hair Clip-Ins', 'Human Hair', 'Remy Chaser Corlscrew Clip (9Pc) 18"', lc('18"', ['Natural Black', 'Natural Brown'])),
        f('Remy Chaser Clip', 'Human Hair Clip-Ins', 'Human Hair', 'Remy Chaser Coily Fro Clip (9Pc) 18"', lc('18"', ['Natural Black', 'Natural Brown'])),
        f('Remy Chaser Clip', 'Human Hair Clip-Ins', 'Human Hair', 'Remy Chaser Kinky Curly Clip (9Pc) 18"', lc('18"', ['Natural Black', 'Natural Brown'])),

        f('Natural Bundle Weave', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Natural Bundle 2X Olivia Weave 18"', lc('18"', $naturalBundleColours)),
        f('Natural Bundle Weave', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Natural Bundle 2X Stella Weave 20"', lc('20"', $naturalBundleColours)),
        f('Natural Bundle Weave', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Natural Bundle 3X Mia Weave 18"x20"x22"', lc('18"x20"x22"', $naturalBundleColours)),
        f('Natural Bundle Weave', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Natural Bundle 3X Coco 12"x14"x16"', lc('12"x14"x16"', $naturalBundleColours)),
        f('Natural Bundle Weave', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Natural Bundle 3X Bohemian Curl', c($bohemianBundleColours)),
        f('Natural Bundle Weave', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Natural Bundle 3X Bohemian Deep Curl', c($bohemianBundleColours)),
        f('Natural Bundle Weave', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Natural Bundle 3X Bohemian Wave', c($bohemianBundleColours)),

        f('Soft Crush', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Soft Crush Deep Curl', lcm(['14"x16"x18"' => $softCrushColours, '18"x20"x22"' => $softCrushColours, '22"x24"x26"' => $softCrushColours])),
        f('Soft Crush', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Soft Crush Bohemian Wave', lcm(['14"x16"x18"' => $softCrushColours, '18"x20"x22"' => $softCrushColours, '22"x24"x26"' => $softCrushColours])),
        f('Soft Crush', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Soft Crush Bohemian Curl', lcm(['14"x16"x18"' => $softCrushColours, '18"x20"x22"' => $softCrushColours, '22"x24"x26"' => $softCrushColours])),
        f('Soft Crush', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Soft Crush Water', lcm(['14"x16"x18"' => $softCrushColours, '18"x20"x22"' => $softCrushColours, '22"x24"x26"' => $softCrushColours])),
        f('Soft Crush', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Soft Crush Loose Deep', lcm(['14"x16"x18"' => $softCrushColours, '18"x20"x22"' => $softCrushColours, '22"x24"x26"' => $softCrushColours])),
        f('Soft Crush', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Soft Crush Cici (9X) 20"', lc('20"', ['1B'])),
        f('Soft Crush', 'Synthetic Bundle Weave', 'Synthetic Fiber', 'Smart Soft Crush Nora (9X) 20"', lc('20"', ['1B'])),

        f('So Natural', 'Human Hair Wigs', 'Human Hair', 'So Natural Wendy 8"', lc('8"', ['Natural', 'Natural Black', 'P1B/30', 'P4/30'])),
        f('So Natural', 'Human Hair Wigs', 'Human Hair', 'So Natural Jennie 10"', lc('10"', ['Natural', 'Natural Black', '34', 'P27/30', 'P4/27'])),
        f('So Natural', 'Human Hair Wigs', 'Human Hair', 'So Natural Lisa 10"', lc('10"', ['Natural', 'Natural Black', '34', 'P27/30', 'P4/27'])),
        f('So Natural', 'Human Hair Wigs', 'Human Hair', 'So Natural Irene 8"', lc('8"', ['Natural', 'Natural Black', '34', 'P27/30', 'P4/27', 'MISTY GREY'])),
        f('So Natural', 'Human Hair Wigs', 'Human Hair', 'So Natural Tiffany 8"', lc('8"', ['Natural', 'Natural Black', '34', 'P27/30', 'P4/27', 'GREY'])),
        f('So Natural', 'Human Hair Wigs', 'Human Hair', 'So Natural Jessica 8"', lc('8"', ['Natural', 'Natural Black', '34', 'P27/30', 'P4/27', 'GREY'])),
        f('So Natural', 'Human Hair Wigs', 'Human Hair', 'So Natural Krystal 13"', lc('13"', ['Natural', 'Natural Black', 'P1B/30', 'P4/30', 'P1B/BUG'])),
        f('So Natural', 'Human Hair Wigs', 'Human Hair', 'So Natural Victoria 13"', lc('13"', ['Natural', 'Natural Black', 'P1B/30', 'P4/30', 'P1B/BUG'])),
        f('So Natural', 'Human Hair Wigs', 'Human Hair', 'So Natural Narsha 10"', lc('10"', ['Natural', 'Natural Black', 'P1B/30', 'P4/27', 'P27/30'])),
        f('So Natural', 'Human Hair Wigs', 'Human Hair', 'So Natural Gain 7.5"', lc('7.5"', ['Natural', 'Natural Black'])),

        f('Fashion Wigs', 'Synthetic Wigs', 'Synthetic Fiber', 'Smart Fashion Wig Bella', c(['HL1B/27', 'HL1B/613', 'HL1B/30', 'HL1B/130', 'HL1B/350'])),
        f('Fashion Wigs', 'Synthetic Wigs', 'Synthetic Fiber', 'Smart Fashion Wig Anna', c(['1', '1B', '2', 'GPB 8/613', 'GPB 10B/26', 'GPB 1B/30'])),
        f('Fashion Wigs', 'Synthetic Wigs', 'Synthetic Fiber', 'Smart Fashion Wig Lucy', c(['1', '1B', '2', 'TTPB 1B/4/350'])),
        f('Fashion Wigs', 'Synthetic Wigs', 'Synthetic Fiber', 'Smart Fashion Wig Aria', c($fashionCore)),
        f('Fashion Wigs', 'Synthetic Wigs', 'Synthetic Fiber', 'Smart Fashion Wig Avery', c($fashionCore)),
        f('Fashion Wigs', 'Synthetic Wigs', 'Synthetic Fiber', 'Smart Fashion Wig Chloe', c($fashionCore)),
        f('Fashion Wigs', 'Synthetic Wigs', 'Synthetic Fiber', 'Smart Fashion Wig Luna', c($fashionCore)),
        f('Fashion Wigs', 'Synthetic Wigs', 'Synthetic Fiber', 'Smart Fashion Wig Ruby', c($fashionCore)),
        f('Fashion Wigs', 'Synthetic Wigs', 'Synthetic Fiber', 'Smart Fashion Wig Elena', c($fashionCore)),
        f('Fashion Wigs', 'Synthetic Wigs', 'Synthetic Fiber', 'Smart Fashion Wig Zoey', c($fashionCore)),

        f('Lace Front Wigs', 'Synthetic Lace Front Wigs', 'Synthetic Fiber', 'Smart Lace Front Wig Alice', c(['1', '1B', '2', '613', 'TT1B/27', 'TT1B/30'])),
        f('Lace Front Wigs', 'Synthetic Lace Front Wigs', 'Synthetic Fiber', 'Smart Lace Front Wig Grace', c(['1', '1B', '2', 'TTPB 1B/4/30', 'TTPB 1B/99J/530'])),
        f('Lace Front Wigs', 'Synthetic Lace Front Wigs', 'Synthetic Fiber', 'Smart Lace Front Wig Amber', c(['1', '1B', '2', 'T BR/27B', 'T 27/613', 'T 1B/30'])),
        f('Lace Front Wigs', 'Synthetic Lace Front Wigs', 'Synthetic Fiber', 'Smart Lace Front Wig Ella', c(['1', '1B', '2', 'GPB 8/613', 'GPB 10B/26', 'GPB 1B/30'])),
        f('Lace Front Wigs', 'Synthetic Lace Front Wigs', 'Synthetic Fiber', 'Smart Lace Front Wig Lillian', c(['TCTL 1B/24/16'])),
        f('Lace Front Wigs', 'Synthetic Lace Front Wigs', 'Synthetic Fiber', 'Smart Lace Front Wig Emily', c(['TTFL 1B/24/16'])),

        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace Nancy Y-Part', c(['1', '1B', '2', 'OET1B/30', 'M.BLYG/30', 'M.BLYG/CARAMEL', 'M.BLYG/STORM BLONDE'])),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace Amelia Y-Part', c(['1', '1B', '2', 'OET1B/30', 'M.BLYG/30', 'M.BLYG/CARAMEL', 'M.BLYG/STORM BLONDE'])),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace Daisy Y-Part', c(['1', '1B', '2', 'OET1B/30', 'M.BLYG/30', 'M.BLYG/CARAMEL', 'M.BLYG/STORM BLONDE'])),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace LF-Maya 4X4', c(['1', '1B', '2', 'CARAMEL', 'CHOCOLATE', 'DIAMOND', 'OET4/613'])),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace F4-Eden 13X4', c($glamlace13x5)),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace F4-Stella 13X4', c(['1', '1B', '2', 'FS1B30', 'OET1B30', 'OET1B/BURG', 'OET1B/SILVER'])),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace F4-Jessica 13X4', c(['1', '1B', '2', 'FS1B30', 'OET1B30', 'OET1B/BURG', 'OET1B/SILVER'])),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace F4-Lorna 13X4', c(['1', '1B', '2', 'CARAMEL', 'CHOCOLATE', 'DIAMOND', 'OET4/613'])),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace F4-Jade 13X4', c(['1', '1B', '2', 'CARAMEL', 'CHOCOLATE', 'DIAMOND', 'OET4/613'])),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace F5-Audrey 13X5', c($glamlace13x5)),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace F5-Julie 13X5', c($glamlace13x5)),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace F5-Poppy 13X5', c($glamlace13x5)),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace F5-Kelsey 13X5', c($glamlace13x5)),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace F6-Kelly 13X6', c(['1', '1B', '2', 'M.BLYG/CARAMEL', 'OET1B/30', 'BUTLERSCOTCH', 'STRAWBERRY BLONDE'])),
        f('Glamlace Wigs', 'Synthetic Lace Wigs', 'Synthetic Fiber', 'Glamlace F6-Apple 13X6', c(['1', '1B', '2', 'OET1B/30', 'M.BLYG/30', 'M.BLYG/CARAMEL', 'OET1B/VELVET PURPLE', 'STRAWBERRY BLONDE', 'BUTLERSCOTCH'])),

        f('Glamlace Ponytails', 'Synthetic Ponytails', 'Synthetic Fiber', 'Glamlace Ponytail Hope', c(['1', '1B', '2', '27', '613', 'FS1B/30', 'FS1B/BURG', 'OET1B/27', 'OET1B/30', 'OET4/613'])),
        f('Glamlace Ponytails', 'Synthetic Ponytails', 'Synthetic Fiber', 'Glamlace Ponytail Riley', c(['1', '1B', '2', '27', '613', 'FS1B/30', 'OET1B/27', 'OET1B/30', 'OET4/613'])),
        f('Glamlace Ponytails', 'Synthetic Ponytails', 'Synthetic Fiber', 'Glamlace Ponytail Cassie', c(['1B', '2', '27', '30', '613', 'MT1B/27', 'MT1B/30', 'MT1B/BUG', 'MT4/27', 'MT1B/350', 'MT27/613'])),
        f('Glamlace Ponytails', 'Synthetic Ponytails', 'Synthetic Fiber', 'Glamlace Ponytail Lynn', c(['1B', '2', '27', '30', '613', 'ORANGE', 'MT1B/27', 'MT1B/30', 'MT1B/BUG', 'MT27/613', 'T4/613', 'T4/30', 'SP18/613', 'SP27/613', 'SP2/4/30'])),
        f('Glamlace Ponytails', 'Synthetic Ponytails', 'Synthetic Fiber', 'Glamlace Ponytail Evelyn', c(['1B', '2', '27', '30', '613', 'MT1B/27', 'MT1B/30', 'MT1B/BUG', 'MT4/27', 'MT27/613', 'T4/30', 'T2/350', 'MT1B/350', 'T1B/BUG', 'T4/613', 'P18/18', 'P27/30', 'P34/56', 'P44/51', 'P99J/350'])),
        f('Glamlace Ponytails', 'Synthetic Ponytails', 'Synthetic Fiber', 'Glamlace Ponytail Laura', c(['1B', '2', '27', '30', '613', 'ORANGE', 'MT1B/27', 'MT1B/30', 'MT1B/BUG', 'MT4/27', 'T4/613', 'T4/30', 'MT27/613', 'SP18/613', 'SP27/613', 'SP2/4/30'])),
        f('Glamlace Ponytails', 'Synthetic Ponytails', 'Synthetic Fiber', 'Glamlace Ponytail Sandy', c(['1B', '2', '27', '30', '613', 'ORANGE', 'MT1B/27', 'MT1B/30', 'MT1B/BUG', 'MT4/27', 'T4/613', 'T4/30', 'MT27/613', 'T2/350'])),
        f('Glamlace Ponytails', 'Synthetic Ponytails', 'Synthetic Fiber', 'Glamlace Ponytail Olivia', c(['1', '1B', '2', '613', 'TT1B/27', 'TT1B/30'])),
        f('Glamlace Ponytails', 'Synthetic Ponytails', 'Synthetic Fiber', 'Glamlace Ponytail Emma', c(['1', '1B', '2', '613', 'TT1B/27', 'TT1B/30'])),
        f('Glamlace Ponytails', 'Synthetic Ponytails', 'Synthetic Fiber', 'Glamlace Ponytail Ava', c(['1', '1B', '2', '613', 'TT1B/27', 'TT1B/30'])),
        f('Glamlace Ponytails', 'Synthetic Ponytails', 'Synthetic Fiber', 'Glamlace Ponytail Sophia', c(['1', '1B', '2', '613', 'TT1B/27', 'TT1B/30'])),
        f('Glamlace Ponytails', 'Synthetic Ponytails', 'Synthetic Fiber', 'Glamlace Ponytail Joy', c(['1', '1B', '2', '4', 'TT1B/27', 'TT1B/30', 'TT1B/613'])),
    ];
}

/**
 * @param array<int, string> $colours
 * @return array<int, array{options:array<string, string>}>
 */
function lc(string $length, array $colours): array
{
    return array_map(fn (string $colour): array => ['options' => ['Length' => $length, 'Colour' => colourLabel($colour)]], $colours);
}

/**
 * @param array<string, array<int, string>> $matrix
 * @return array<int, array{options:array<string, string>}>
 */
function lcm(array $matrix): array
{
    $records = [];

    foreach ($matrix as $length => $colours) {
        foreach ($colours as $colour) {
            $records[] = ['options' => ['Length' => (string) $length, 'Colour' => colourLabel($colour)]];
        }
    }

    return $records;
}

/**
 * @param array<int, string> $colours
 * @return array<int, array{options:array<string, string>}>
 */
function c(array $colours): array
{
    return array_map(fn (string $colour): array => ['options' => ['Colour' => colourLabel($colour)]], $colours);
}

/**
 * @return array<int, array{options:array<string, string>}>
 */
function remyChaserWeaveRecords(): array
{
    return lcm([
        '16"' => ['Natural Color', 'Natural Brown'],
        '20"' => ['Natural Color', 'Natural Brown', '27', '30', 'T/27', 'T/30'],
        '30"' => ['Natural Color', 'Natural Brown'],
    ]);
}

/**
 * @param array<int, array{options:array<string, string>}> $records
 * @param array<int, string> $aliases
 * @return array<string, mixed>
 */
function f(string $lineName, string $productType, string $materialName, string $name, array $records, array $aliases = []): array
{
    return [
        'line_name' => $lineName,
        'product_type' => $productType,
        'material_name' => $materialName,
        'name' => cleanSpaces($name),
        'aliases' => array_values(array_unique(array_map('cleanSpaces', $aliases))),
        'url' => lineConfigs()[$lineName]['url'],
        'records' => collect($records)
            ->map(fn (array $record): array => ['options' => sortOptions($record['options'])])
            ->unique(fn (array $record): string => optionSignature($record['options']))
            ->values()
            ->all(),
    ];
}

function colourLabel(string $value): string
{
    $value = cleanSpaces(str_replace("\xc2\xa0", ' ', $value));

    return match (Str::lower($value)) {
        '1b', '1 b' => '1B',
        'orange' => 'ORANGE',
        default => $value,
    };
}

function findOrCreateSmartBrand(BrandCatalogue $catalogue): BrandCatalogueBrand
{
    $brand = BrandCatalogueBrand::query()
        ->where('brand_catalogue_id', $catalogue->id)
        ->where(function ($query): void {
            $query
                ->whereIn('slug', ['smart', 'smart-braid', 'smartbraid'])
                ->orWhereIn('name', ['Smart', 'Smart Braid']);
        })
        ->orderByRaw("case when name = 'Smart Braid' then 0 when slug = 'smart-braid' then 1 else 2 end")
        ->first();

    if ($brand) {
        return $brand;
    }

    return BrandCatalogueBrand::query()->create([
        'brand_catalogue_id' => $catalogue->id,
        'name' => 'Smart',
        'slug' => uniqueBrandSlug($catalogue, 'smart'),
        'url' => 'https://smartbraid.co.uk/',
        'is_active' => true,
        'sort_order' => 160,
    ]);
}

function findOrCreateLine(BrandCatalogueBrand $brand, string $name, string $url, int $sortOrder): BrandCatalogueLine
{
    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', $name)
        ->first();

    if (! $line) {
        $line = new BrandCatalogueLine([
            'brand_catalogue_brand_id' => $brand->id,
            'name' => $name,
            'slug' => scopedSlug(BrandCatalogueLine::query()->where('brand_catalogue_brand_id', $brand->id), $name),
        ]);
    }

    $line->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'name' => $name,
        'note' => mergeNote($line->note, "{$name} is treated as a line/sub-brand under the Smart master brand."),
        'url' => $url,
        'is_default' => $name === 'Smart Braid',
        'is_active' => true,
        'sort_order' => $line->exists ? $line->sort_order : $sortOrder,
    ])->save();

    return $line;
}

function findOrCreateProductType(BrandCatalogueBrand $brand, BrandCatalogueLine $line, string $name, int $sortOrder, string $lineName): BrandCatalogueProductType
{
    $productType = BrandCatalogueProductType::query()->firstOrCreate(
        [
            'brand_catalogue_line_id' => $line->id,
            'name' => $name,
        ],
        [
            'brand_catalogue_brand_id' => $brand->id,
            'slug' => scopedSlug(BrandCatalogueProductType::query()->where('brand_catalogue_line_id', $line->id), $name),
            'is_active' => true,
            'sort_order' => $sortOrder,
        ],
    );

    $productType->fill([
        'brand_catalogue_brand_id' => $brand->id,
        'note' => mergeNote($productType->note, "Structured from the Smart Hair Intl order-sheet PDF for {$lineName}."),
        'url' => $line->url,
        'is_active' => true,
        'sort_order' => $sortOrder,
    ])->save();

    return $productType;
}

function productTypeSortOrder(Collection $families, string $lineName, string $productType): int
{
    $index = $families
        ->where('line_name', $lineName)
        ->pluck('product_type')
        ->unique()
        ->values()
        ->search($productType);

    return (($index === false ? 0 : (int) $index) + 1) * 10;
}

/**
 * @param array<int, string> $aliases
 */
function findExistingLineStyle(BrandCatalogueLine $line, string $name, array $aliases): ?BrandCatalogueStyle
{
    $productTypeIds = BrandCatalogueProductType::query()
        ->where('brand_catalogue_line_id', $line->id)
        ->pluck('id');

    if ($productTypeIds->isEmpty()) {
        return null;
    }

    $names = collect([$name, ...$aliases])->filter()->unique()->values();

    $style = BrandCatalogueStyle::query()
        ->whereIn('brand_catalogue_product_type_id', $productTypeIds)
        ->whereIn('name', $names)
        ->first();

    if ($style) {
        return $style;
    }

    return BrandCatalogueStyle::query()
        ->whereIn('brand_catalogue_product_type_id', $productTypeIds)
        ->get()
        ->first(fn (BrandCatalogueStyle $candidate): bool => $names->contains(fn (string $candidateName): bool => normaliseName($candidate->name) === normaliseName($candidateName)));
}

/**
 * @param array<string, mixed> $family
 */
function styleNote(array $family): string
{
    return "Family/style and variant matrix imported from Smart Hair Intl order sheet PDF dated 25-07-25.";
}

/**
 * @param Collection<int, array{options:array<string, string>}> $records
 * @param array<string, mixed> $family
 * @return array{0:int,1:int}
 */
function syncVariantsAndSkus(BrandCatalogueStyle $style, Collection $records, array $family): array
{
    $variantNames = $records
        ->flatMap(fn (array $record): array => array_keys($record['options']))
        ->unique()
        ->values();

    $variants = [];
    foreach ($variantNames as $index => $variantName) {
        $variants[$variantName] = BrandCatalogueVariant::query()->updateOrCreate(
            [
                'brand_catalogue_style_id' => $style->id,
                'name' => $variantName,
            ],
            [
                'variant_type' => $variantType = $variantName === 'Length' ? 'measurement' : 'colour_code',
                'url' => $style->url,
                'sort_order' => ($index + 1) * 10,
            ],
        );
    }

    $optionMaps = [];
    foreach ($variants as $variantName => $variant) {
        $values = $records
            ->map(fn (array $record): ?string => $record['options'][$variantName] ?? null)
            ->filter()
            ->unique()
            ->sortBy(fn (string $value): string => $variantName === 'Length' ? lengthSortKey($value) : colourSortKey($value))
            ->values()
            ->all();

        $optionMaps[$variantName] = syncOptions($variant, $values);
    }

    $created = 0;
    $updated = 0;

    foreach ($records as $index => $record) {
        $selected = $record['options'];
        $signature = optionSignature($selected);
        $sku = findEquivalentSku($style, $selected, $signature);
        $name = skuName($style->name, $selected);

        if (! $sku) {
            $sku = new BrandCatalogueSku([
                'brand_catalogue_style_id' => $style->id,
                'option_signature' => $signature,
                'slug' => scopedSlug(BrandCatalogueSku::query()->where('brand_catalogue_style_id', $style->id), $name),
            ]);
            $created++;
        } else {
            $updated++;
        }

        $sku->fill([
            'name' => $name,
            'option_signature' => $signature,
            'note' => mergeNote($sku->note, skuNote($family)),
            'url' => $family['url'],
            'is_active' => true,
            'sort_order' => $sku->exists ? $sku->sort_order : $index * 10,
        ])->save();

        DB::table('brand_catalogue_sku_variant_options')
            ->where('brand_catalogue_sku_id', $sku->id)
            ->delete();

        $rows = [];
        foreach ($selected as $variantName => $value) {
            $rows[] = [
                'brand_catalogue_sku_id' => $sku->id,
                'brand_catalogue_variant_id' => $variants[$variantName]->id,
                'brand_catalogue_variant_option_id' => $optionMaps[$variantName][$value],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('brand_catalogue_sku_variant_options')->insert($rows);
        }
    }

    pruneUnusedVariantOptions($style);

    return [$created, $updated];
}

function findEquivalentSku(BrandCatalogueStyle $style, array $selected, string $signature): ?BrandCatalogueSku
{
    $exact = BrandCatalogueSku::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->where('option_signature', $signature)
        ->first();

    if ($exact) {
        return $exact;
    }

    $targetNormalised = normalisedOptionSignature($selected);

    return BrandCatalogueSku::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->get()
        ->first(fn (BrandCatalogueSku $sku): bool => normalisedSignatureString($sku->option_signature) === $targetNormalised);
}

/**
 * @param array<int, string> $values
 * @return array<string, int>
 */
function syncOptions(BrandCatalogueVariant $variant, array $values): array
{
    $map = [];

    foreach ($values as $index => $value) {
        $option = BrandCatalogueVariantOption::query()->updateOrCreate(
            [
                'variant_id' => $variant->id,
                'label' => $value,
            ],
            [
                'value' => $value,
                'sort_order' => $index * 10,
            ],
        );

        $map[$value] = $option->id;
    }

    return $map;
}

function pruneUnusedVariantOptions(BrandCatalogueStyle $style): void
{
    $variantIds = BrandCatalogueVariant::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->pluck('id');

    if ($variantIds->isEmpty()) {
        return;
    }

    BrandCatalogueVariantOption::query()
        ->whereIn('variant_id', $variantIds)
        ->whereNotIn('id', DB::table('brand_catalogue_sku_variant_options')->pluck('brand_catalogue_variant_option_id'))
        ->delete();
}

/**
 * @param array<string, string> $selected
 */
function optionSignature(array $selected): string
{
    return collect(sortOptions($selected))
        ->map(fn (string $value, string $name): string => $name.':'.$value)
        ->implode('|');
}

/**
 * @param array<string, string> $selected
 */
function normalisedOptionSignature(array $selected): string
{
    return collect(sortOptions($selected))
        ->map(fn (string $value, string $name): string => Str::lower($name).':'.normaliseOptionValue($value))
        ->implode('|');
}

function normalisedSignatureString(string $signature): string
{
    $parts = [];

    foreach (explode('|', $signature) as $pair) {
        [$name, $value] = array_pad(explode(':', $pair, 2), 2, '');
        if ($name === '' || $value === '') {
            continue;
        }

        $parts[Str::lower($name)] = normaliseOptionValue($value);
    }

    return normalisedOptionSignature($parts);
}

function normaliseOptionValue(string $value): string
{
    $value = Str::upper(cleanSpaces($value));
    $value = str_replace(['-', ' / ', '/ ', ' /'], '/', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return trim($value);
}

/**
 * @param array<string, string> $options
 * @return array<string, string>
 */
function sortOptions(array $options): array
{
    $ordered = [];
    foreach (['Length', 'Colour'] as $variantName) {
        if (isset($options[$variantName])) {
            $ordered[$variantName] = $options[$variantName];
        }
    }

    foreach ($options as $variantName => $value) {
        if (! isset($ordered[$variantName])) {
            $ordered[$variantName] = $value;
        }
    }

    return $ordered;
}

/**
 * @param array<string, string> $selected
 */
function skuName(string $styleName, array $selected): string
{
    $parts = [$styleName];

    if (isset($selected['Length']) && ! str_contains($styleName, $selected['Length'])) {
        $parts[] = $selected['Length'];
    }

    if (isset($selected['Colour'])) {
        $parts[] = 'Colour '.$selected['Colour'];
    }

    return implode(' - ', $parts);
}

/**
 * @param array<string, mixed> $family
 */
function skuNote(array $family): string
{
    return "Variant listed in Smart Hair Intl order sheet PDF dated 25-07-25.";
}

function lengthSortKey(string $length): string
{
    if (preg_match('/\d+(?:\.\d+)?/', $length, $match) === 1) {
        return sprintf('%08.2f:%s', (float) $match[0], $length);
    }

    return '99999999:'.$length;
}

function colourSortKey(string $colour): string
{
    if (preg_match('/^\d+/', $colour, $match) === 1) {
        return sprintf('0%05d:%s', (int) $match[0], $colour);
    }

    return '1'.$colour;
}

function uniqueBrandSlug(BrandCatalogue $catalogue, string $slug, ?int $exceptId = null): string
{
    $base = Str::slug($slug) ?: 'item';
    $candidate = $base;
    $suffix = 2;

    while (
        BrandCatalogueBrand::query()
            ->where('brand_catalogue_id', $catalogue->id)
            ->where('slug', $candidate)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists()
    ) {
        $candidate = $base.'-'.$suffix;
        $suffix++;
    }

    return $candidate;
}

function scopedSlug($query, string $name): string
{
    $base = Str::slug($name) ?: 'item';
    $slug = $base;
    $suffix = 2;

    while ((clone $query)->where('slug', $slug)->exists()) {
        $slug = $base.'-'.$suffix;
        $suffix++;
    }

    return $slug;
}

function mergeNote(?string $existing, string $addition): string
{
    $existing = cleanSpaces((string) $existing);
    $addition = cleanSpaces($addition);

    if ($addition === '') {
        return $existing;
    }

    if ($existing === '') {
        return $addition;
    }

    if (str_contains($existing, $addition)) {
        return $existing;
    }

    return $existing.' '.$addition;
}

function cleanSpaces(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function normaliseName(string $value): string
{
    $value = Str::lower(cleanSpaces($value));
    $value = str_replace(['"', "'", '(', ')', '-', '/', 'x'], '', $value);
    $value = preg_replace('/\s+/', '', $value) ?? $value;

    return $value;
}
