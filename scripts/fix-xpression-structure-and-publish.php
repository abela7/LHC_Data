<?php

use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueLine;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\CatalogueImage;
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
    $brand = BrandCatalogueBrand::query()
        ->where('id', 1)
        ->where('name', 'X-Pression')
        ->firstOrFail();

    $braidsLine = line($brand, 'X-Pression Braids', 10);
    line($brand, 'X-Pression Crochet Braids', 20);
    line($brand, 'X-Pression Weave On', 30);
    line($brand, 'Outre', 40);

    $preStretchedType = productType($brand, $braidsLine, 'Pre-Stretched Braiding Hair');
    $bulkType = productType($brand, $braidsLine, 'Bulk Braiding Hair');

    $ultraBraid = BrandCatalogueStyle::query()->findOrFail(1);
    $preStretched = BrandCatalogueStyle::query()->findOrFail(94);

    deleteUnlinkedDuplicateStyle(12682, $ultraBraid, 'duplicate Ultra Braid source row');
    deleteUnlinkedDuplicateStyle(12681, $preStretched, 'duplicate Pre-Stretched source row');

    removeCatalogueVariant($ultraBraid, 'Shop Product');
    removeCatalogueVariant($preStretched, 'Product Variant');

    moveStyleToType($ultraBraid, $bulkType);
    moveStyleToType($preStretched, $preStretchedType);

    HairExtensionIntake::query()
        ->whereIn('id', [42, 43, 45])
        ->update([
            'brand_catalogue_product_type_id' => $bulkType->id,
            'product_type_name' => $bulkType->name,
            'updated_at' => now(),
        ]);

    HairExtensionIntake::query()
        ->where('id', 41)
        ->update([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $preStretchedType->id,
            'brand_catalogue_style_id' => $preStretched->id,
            'product_type_name' => $preStretchedType->name,
            'updated_at' => now(),
        ]);

    HairExtensionIntake::query()
        ->where('id', 44)
        ->update([
            'brand_catalogue_brand_id' => $brand->id,
            'brand_catalogue_product_type_id' => $bulkType->id,
            'brand_catalogue_style_id' => 100,
            'product_type_name' => $bulkType->name,
            'updated_at' => now(),
        ]);

    deleteEmptyProductType(3344);
    deleteEmptyLine(1169);

    return [
        'lines' => BrandCatalogueLine::query()
            ->where('brand_catalogue_brand_id', $brand->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'sort_order'])
            ->map(fn (BrandCatalogueLine $line): string => "{$line->id}:{$line->name}")
            ->all(),
        'shop_confirmed_style_ids' => shopConfirmedXpressionStyleIds(),
    ];
});

echo "X-Pression structure cleaned.\n";
echo 'Lines: '.implode(' | ', $summary['lines'])."\n";
echo 'Shop-confirmed style IDs: '.implode(', ', $summary['shop_confirmed_style_ids'])."\n";

if ($publish) {
    $publisher = app(RetailProductPublisher::class);
    $published = [];

    foreach ($summary['shop_confirmed_style_ids'] as $styleId) {
        $style = BrandCatalogueStyle::query()->find($styleId);

        if (! $style) {
            continue;
        }

        $family = $publisher->publishBrandCatalogueStyle($style);
        $published[] = [
            'style_id' => $style->id,
            'family_id' => $family->id,
            'family_name' => $family->family_name,
            'products' => $family->products()->count(),
        ];
    }

    echo "Published draft retail families:\n";
    foreach ($published as $row) {
        echo "- style {$row['style_id']} => family {$row['family_id']} {$row['family_name']} ({$row['products']} products)\n";
    }
}

function line(BrandCatalogueBrand $brand, string $name, int $sortOrder): BrandCatalogueLine
{
    $line = BrandCatalogueLine::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('name', $name)
        ->firstOrFail();

    $line->fill([
        'sort_order' => $sortOrder,
        'is_default' => false,
        'is_active' => true,
    ])->save();

    return $line;
}

function productType(BrandCatalogueBrand $brand, BrandCatalogueLine $line, string $name): BrandCatalogueProductType
{
    return BrandCatalogueProductType::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('brand_catalogue_line_id', $line->id)
        ->where('name', $name)
        ->firstOrFail();
}

function deleteUnlinkedDuplicateStyle(int $duplicateStyleId, BrandCatalogueStyle $keepStyle, string $reason): void
{
    $duplicate = BrandCatalogueStyle::query()->find($duplicateStyleId);

    if (! $duplicate) {
        return;
    }

    $linkedIntakes = HairExtensionIntake::query()
        ->where('brand_catalogue_style_id', $duplicate->id)
        ->count();

    $publishedFamilies = ProductFamily::query()
        ->where('brand_catalogue_style_id', $duplicate->id)
        ->count();

    if ($linkedIntakes > 0 || $publishedFamilies > 0) {
        throw new RuntimeException("Refusing to delete style {$duplicate->id}; it has {$linkedIntakes} intake link(s) and {$publishedFamilies} published family link(s).");
    }

    moveUniqueStyleImages($duplicate, $keepStyle);
    deleteStyleImagesAndChildren($duplicate);
    $duplicate->delete();

    echo "Deleted {$reason}: style {$duplicateStyleId}\n";
}

function moveUniqueStyleImages(BrandCatalogueStyle $from, BrandCatalogueStyle $to): void
{
    $existingKeys = CatalogueImage::query()
        ->where('imageable_type', BrandCatalogueStyle::class)
        ->where('imageable_id', $to->id)
        ->get(['external_url', 'storage_disk', 'storage_path'])
        ->map(fn (CatalogueImage $image): string => imageKey($image))
        ->all();

    $existing = array_fill_keys($existingKeys, true);

    CatalogueImage::query()
        ->where('imageable_type', BrandCatalogueStyle::class)
        ->where('imageable_id', $from->id)
        ->orderBy('id')
        ->get()
        ->each(function (CatalogueImage $image) use ($to, &$existing): void {
            $key = imageKey($image);

            if (isset($existing[$key])) {
                $image->delete();

                return;
            }

            $image->imageable_id = $to->id;
            $image->save();
            $existing[$key] = true;
        });
}

function imageKey(CatalogueImage $image): string
{
    return implode('|', [
        (string) $image->external_url,
        (string) $image->storage_disk,
        (string) $image->storage_path,
    ]);
}

function deleteStyleImagesAndChildren(BrandCatalogueStyle $style): void
{
    $skuIds = BrandCatalogueSku::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->pluck('id');

    if ($skuIds->isNotEmpty()) {
        CatalogueImage::query()
            ->where('imageable_type', BrandCatalogueSku::class)
            ->whereIn('imageable_id', $skuIds)
            ->delete();
    }

    $optionIds = DB::table('brand_catalogue_variant_options')
        ->whereIn('variant_id', BrandCatalogueVariant::query()
            ->where('brand_catalogue_style_id', $style->id)
            ->select('id'))
        ->pluck('id');

    if ($optionIds->isNotEmpty()) {
        CatalogueImage::query()
            ->where('imageable_type', App\Models\BrandCatalogueVariantOption::class)
            ->whereIn('imageable_id', $optionIds)
            ->delete();
    }

    CatalogueImage::query()
        ->where('imageable_type', BrandCatalogueStyle::class)
        ->where('imageable_id', $style->id)
        ->delete();
}

function removeCatalogueVariant(BrandCatalogueStyle $style, string $variantName): void
{
    $variant = BrandCatalogueVariant::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->where('name', $variantName)
        ->first();

    if (! $variant) {
        return;
    }

    $skuIds = BrandCatalogueSku::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->pluck('id');

    foreach ($skuIds as $skuId) {
        $sku = BrandCatalogueSku::query()->find($skuId);

        if (! $sku) {
            continue;
        }

        $newSignature = removeSignaturePart((string) $sku->option_signature, $variantName);
        $conflict = BrandCatalogueSku::query()
            ->where('brand_catalogue_style_id', $style->id)
            ->where('option_signature', $newSignature)
            ->whereKeyNot($sku->id)
            ->first();

        DB::table('brand_catalogue_sku_variant_options')
            ->where('brand_catalogue_sku_id', $sku->id)
            ->where('brand_catalogue_variant_id', $variant->id)
            ->delete();

        if ($conflict) {
            DB::table('brand_catalogue_sku_variant_options')
                ->where('brand_catalogue_sku_id', $sku->id)
                ->delete();

            CatalogueImage::query()
                ->where('imageable_type', BrandCatalogueSku::class)
                ->where('imageable_id', $sku->id)
                ->delete();

            $sku->delete();

            continue;
        }

        $sku->option_signature = $newSignature;
        $sku->save();
    }

    $optionIds = $variant->options()->pluck('id');

    if ($optionIds->isNotEmpty()) {
        CatalogueImage::query()
            ->where('imageable_type', App\Models\BrandCatalogueVariantOption::class)
            ->whereIn('imageable_id', $optionIds)
            ->delete();
    }

    $variant->options()->delete();
    $variant->delete();

    echo "Removed non-sellable variant '{$variantName}' from style {$style->id}\n";
}

function removeSignaturePart(string $signature, string $variantName): string
{
    return collect(explode('|', $signature))
        ->map(fn (string $part): string => trim($part))
        ->filter(fn (string $part): bool => $part !== '' && ! Str::startsWith($part, $variantName.':'))
        ->implode('|');
}

function moveStyleToType(BrandCatalogueStyle $style, BrandCatalogueProductType $type): void
{
    $style->brand_catalogue_product_type_id = $type->id;
    $style->brand_catalogue_brand_id = $type->brand_catalogue_brand_id;
    $style->save();

    ProductFamily::query()
        ->where('brand_catalogue_style_id', $style->id)
        ->update([
            'brand_catalogue_product_type_id' => $type->id,
            'brand_catalogue_line_id' => $type->brand_catalogue_line_id,
            'line_name' => $type->line?->name,
            'product_type_name' => $type->name,
            'updated_at' => now(),
        ]);
}

function deleteEmptyProductType(int $productTypeId): void
{
    $type = BrandCatalogueProductType::query()->find($productTypeId);

    if (! $type) {
        return;
    }

    if ($type->styles()->exists()) {
        throw new RuntimeException("Refusing to delete product type {$productTypeId}; it still has styles.");
    }

    $type->delete();
    echo "Deleted empty product type {$productTypeId}\n";
}

function deleteEmptyLine(int $lineId): void
{
    $line = BrandCatalogueLine::query()->find($lineId);

    if (! $line) {
        return;
    }

    if ($line->productTypes()->exists()) {
        throw new RuntimeException("Refusing to delete line {$lineId}; it still has product types.");
    }

    $line->delete();
    echo "Deleted empty line {$lineId}\n";
}

/**
 * @return list<int>
 */
function shopConfirmedXpressionStyleIds(): array
{
    return HairExtensionIntake::query()
        ->where('brand_catalogue_brand_id', 1)
        ->where('status', 'submitted')
        ->whereNotNull('brand_catalogue_style_id')
        ->distinct()
        ->orderBy('brand_catalogue_style_id')
        ->pluck('brand_catalogue_style_id')
        ->map(fn ($id): int => (int) $id)
        ->values()
        ->all();
}
