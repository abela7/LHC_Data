<?php

use App\Models\BrandCatalogueLine;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueStyle;
use App\Models\HairExtensionIntake;
use App\Models\ProductFamily;
use App\Services\RetailProductPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$publish = ! in_array('--no-publish', $argv, true);

$summary = DB::transaction(function (): array {
    $braid = renameType(35, 'Braid', 'Broad reusable hair-extension product type. Source pages may describe these as braiding hair, bulk braiding hair, or curly braid.', 10);
    $twist = renameType(33, 'Twist', 'Broad reusable hair-extension product type for twist styles.', 20);
    $locs = renameType(34, 'Locs', 'Broad reusable hair-extension product type for faux loc styles.', 30);
    $crochet = renameType(36, 'Crochet', 'Broad reusable hair-extension product type for crochet braid styles.', 10);
    $weave = renameType(37, 'Weave', 'Broad reusable hair-extension product type for weave-on styles.', 10);
    $outreCrochet = renameType(6306, 'Crochet', 'Broad reusable hair-extension product type. These styles belong to the Outre X-Pression Twisted Up line.', 10);

    moveStyles([118, 94, 95, 121, 100, 1, 119], $braid);
    moveStyles([96, 97, 122], $twist);
    moveStyles([98, 99], $locs);
    moveStyles([123, 101, 124, 120, 103, 104, 102, 105], $crochet);
    moveStyles([106, 107, 108, 109, 110, 111, 112, 113, 114, 115, 116, 117], $weave);
    moveStyles([12678, 12679, 12676, 12677, 12680, 12675], $outreCrochet);

    renameOutreTwistedUpStyles();
    updateXpressionIntakeTypeLinks($braid, $weave, $outreCrochet);

    deleteEmptyType(32);
    deleteEmptyType(38);

    return [
        'lines' => lineSummary(),
        'shop_confirmed_style_ids' => HairExtensionIntake::query()
            ->where('brand_catalogue_brand_id', 1)
            ->where('status', 'submitted')
            ->whereNotNull('brand_catalogue_style_id')
            ->distinct()
            ->orderBy('brand_catalogue_style_id')
            ->pluck('brand_catalogue_style_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all(),
    ];
});

echo "X-Pression product types normalized.\n";
foreach ($summary['lines'] as $line) {
    echo "- {$line}\n";
}

if ($publish) {
    $publisher = app(RetailProductPublisher::class);

    echo "Republished shop-confirmed draft families:\n";
    foreach ($summary['shop_confirmed_style_ids'] as $styleId) {
        $style = BrandCatalogueStyle::query()->find($styleId);

        if (! $style) {
            continue;
        }

        $family = $publisher->publishBrandCatalogueStyle($style);
        echo "- style {$style->id} => family {$family->id} {$family->family_name} ({$family->product_type_name}, {$family->products()->count()} products)\n";
    }
}

function renameType(int $id, string $name, string $note, int $sortOrder): BrandCatalogueProductType
{
    $type = BrandCatalogueProductType::query()->findOrFail($id);

    $type->fill([
        'name' => $name,
        'slug' => scopedTypeSlug($type, $name),
        'note' => $note,
        'is_active' => true,
        'sort_order' => $sortOrder,
    ])->save();

    return $type->fresh('line');
}

function scopedTypeSlug(BrandCatalogueProductType $type, string $name): string
{
    $base = Str::slug($name) ?: 'type';
    $slug = $base;
    $i = 2;

    while (BrandCatalogueProductType::query()
        ->where('brand_catalogue_line_id', $type->brand_catalogue_line_id)
        ->where('slug', $slug)
        ->whereKeyNot($type->id)
        ->exists()) {
        $slug = "{$base}-{$i}";
        $i++;
    }

    return $slug;
}

/**
 * @param  list<int>  $styleIds
 */
function moveStyles(array $styleIds, BrandCatalogueProductType $type): void
{
    BrandCatalogueStyle::query()
        ->whereIn('id', $styleIds)
        ->update([
            'brand_catalogue_product_type_id' => $type->id,
            'updated_at' => now(),
        ]);

    ProductFamily::query()
        ->whereIn('brand_catalogue_style_id', $styleIds)
        ->update([
            'brand_catalogue_product_type_id' => $type->id,
            'brand_catalogue_line_id' => $type->brand_catalogue_line_id,
            'line_name' => $type->line?->name,
            'product_type_name' => $type->name,
            'updated_at' => now(),
        ]);
}

function renameOutreTwistedUpStyles(): void
{
    $names = [
        12675 => 'X-Pression Twisted Up Swicy Afro Twist',
        12676 => 'X-Pression Twisted Up LaLa Wandcurl',
        12677 => 'X-Pression Twisted Up LuLu Wandcurl',
        12678 => 'X-Pression Twisted Up Boho Giana Locs',
        12679 => 'X-Pression Twisted Up Borabora Locs',
        12680 => 'X-Pression Twisted Up Springy Afro Twist',
    ];

    foreach ($names as $styleId => $name) {
        $style = BrandCatalogueStyle::query()->find($styleId);

        if (! $style) {
            continue;
        }

        $style->name = $name;
        $style->slug = scopedStyleSlug($style, $name);
        $style->save();
    }
}

function scopedStyleSlug(BrandCatalogueStyle $style, string $name): string
{
    $base = Str::slug($name) ?: 'style';
    $slug = $base;
    $i = 2;

    while (BrandCatalogueStyle::query()
        ->where('brand_catalogue_product_type_id', $style->brand_catalogue_product_type_id)
        ->where('slug', $slug)
        ->whereKeyNot($style->id)
        ->exists()) {
        $slug = "{$base}-{$i}";
        $i++;
    }

    return $slug;
}

function updateXpressionIntakeTypeLinks(BrandCatalogueProductType $braid, BrandCatalogueProductType $weave, BrandCatalogueProductType $outreCrochet): void
{
    HairExtensionIntake::query()
        ->whereIn('id', [41, 42, 43, 44, 45])
        ->update([
            'brand_catalogue_product_type_id' => $braid->id,
            'product_type_name' => $braid->name,
            'updated_at' => now(),
        ]);

    HairExtensionIntake::query()
        ->whereIn('id', [46, 47])
        ->update([
            'brand_catalogue_product_type_id' => $outreCrochet->id,
            'product_type_name' => $outreCrochet->name,
            'updated_at' => now(),
        ]);

    HairExtensionIntake::query()
        ->where('id', 48)
        ->update([
            'brand_catalogue_product_type_id' => $weave->id,
            'product_type_name' => $weave->name,
            'updated_at' => now(),
        ]);
}

function deleteEmptyType(int $id): void
{
    $type = BrandCatalogueProductType::query()->find($id);

    if (! $type) {
        return;
    }

    if ($type->styles()->exists()) {
        throw new RuntimeException("Refusing to delete product type {$id}; it still has styles.");
    }

    $type->delete();
}

/**
 * @return list<string>
 */
function lineSummary(): array
{
    return BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', 1)
        ->with(['productTypes' => fn ($query) => $query->withCount('styles')->orderBy('sort_order')->orderBy('name')])
        ->orderBy('sort_order')
        ->get()
        ->map(fn (BrandCatalogueLine $line): string => $line->name.' => '.$line->productTypes
            ->map(fn (BrandCatalogueProductType $type): string => $type->name.' ('.$type->styles_count.')')
            ->implode(', '))
        ->all();
}
