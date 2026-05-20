<?php

use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$brand = BrandCatalogueBrand::query()->findOrFail(5);
$productType = BrandCatalogueProductType::query()->findOrFail(20); // Braiding Hair

$styleName = 'Cherish Junior Spiral French Curl';
$colours = [
    '1', '1B', '2', 'T27', 'T30', 'T350', 'T530', '4', '27', '30',
    'P4/27', 'P4/30', 'P4/27/30', 'P27/30/613', '6', '8', '51',
];

$uniqueSlug = function (string $table, string $scopeColumn, int $scopeId, string $name, ?int $ignoreId = null): string {
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
};

$ensureVariant = function (BrandCatalogueStyle $style, string $name, string $type, int $sortOrder): BrandCatalogueVariant {
    $variant = $style->variants()
        ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
        ->first();

    if ($variant) {
        $variant->update([
            'name' => $name,
            'variant_type' => $type,
            'sort_order' => $sortOrder,
        ]);

        return $variant->fresh();
    }

    return $style->variants()->create([
        'name' => $name,
        'variant_type' => $type,
        'sort_order' => $sortOrder,
    ]);
};

$ensureOption = function (BrandCatalogueVariant $variant, string $value, int $sortOrder): BrandCatalogueVariantOption {
    $option = $variant->options()
        ->where(function ($query) use ($value): void {
            $query
                ->whereRaw('LOWER(label) = ?', [Str::lower($value)])
                ->orWhereRaw('LOWER(value) = ?', [Str::lower($value)]);
        })
        ->first();

    if ($option) {
        $option->update([
            'label' => $value,
            'value' => $value,
            'sort_order' => $sortOrder,
        ]);

        return $option->fresh();
    }

    return $variant->options()->create([
        'label' => $value,
        'value' => $value,
        'sort_order' => $sortOrder,
    ]);
};

$signatureFor = function (Collection $options): string {
    $parts = $options
        ->map(fn (BrandCatalogueVariantOption $option): string => $option->variant_id.':'.$option->id)
        ->all();

    sort($parts, SORT_NATURAL);

    return implode('|', $parts);
};

$result = DB::transaction(function () use (
    $brand,
    $productType,
    $styleName,
    $colours,
    $uniqueSlug,
    $ensureVariant,
    $ensureOption,
    $signatureFor,
) {
    $style = BrandCatalogueStyle::query()
        ->where('brand_catalogue_brand_id', $brand->id)
        ->where('brand_catalogue_product_type_id', $productType->id)
        ->whereRaw('LOWER(name) = ?', [Str::lower($styleName)])
        ->first();

    if ($style) {
        $style->update([
            'name' => $styleName,
            'slug' => $uniqueSlug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $productType->id, $styleName, $style->id),
            'material_name' => 'Synthetic Hair',
            'note' => 'Cherish Junior 3X Spiral French Curl 14 inch. Built from Mamado Junior sheet.',
            'url' => 'https://www.mamado.co.uk/',
            'is_active' => true,
        ]);
    } else {
        $style = $productType->styles()->create([
            'brand_catalogue_brand_id' => $brand->id,
            'name' => $styleName,
            'slug' => $uniqueSlug('brand_catalogue_styles', 'brand_catalogue_product_type_id', $productType->id, $styleName),
            'material_name' => 'Synthetic Hair',
            'note' => 'Cherish Junior 3X Spiral French Curl 14 inch. Built from Mamado Junior sheet.',
            'url' => 'https://www.mamado.co.uk/',
            'is_active' => true,
        ]);
    }

    $lengthVariant = $ensureVariant($style, 'Length', 'measurement', 10);
    $colourVariant = $ensureVariant($style, 'Colour', 'colour_code', 20);
    $packVariant = $ensureVariant($style, 'Pack count', 'count', 30);

    $lengthOption = $ensureOption($lengthVariant, '14', 1);
    $packOption = $ensureOption($packVariant, '3X', 1);

    $expectedSignatures = [];
    $created = 0;
    $updated = 0;

    foreach ($colours as $index => $colour) {
        $colourOption = $ensureOption($colourVariant, $colour, $index + 1);
        $options = collect([$lengthOption, $colourOption, $packOption]);
        $signature = $signatureFor($options);
        $expectedSignatures[$signature] = true;

        $skuName = "{$styleName} 3X 14 inch - Colour {$colour}";
        $sku = BrandCatalogueSku::query()
            ->where('brand_catalogue_style_id', $style->id)
            ->where('option_signature', $signature)
            ->first();

        if ($sku) {
            $sku->update([
                'name' => $skuName,
                'slug' => $uniqueSlug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $skuName, $sku->id),
                'sort_order' => $index + 1,
                'is_active' => true,
            ]);
            $updated++;
        } else {
            $sku = $style->skus()->create([
                'name' => $skuName,
                'slug' => $uniqueSlug('brand_catalogue_skus', 'brand_catalogue_style_id', $style->id, $skuName),
                'option_signature' => $signature,
                'sort_order' => $index + 1,
                'is_active' => true,
            ]);
            $created++;
        }

        $sku->optionValues()->sync($options->mapWithKeys(fn (BrandCatalogueVariantOption $option) => [
            $option->id => ['brand_catalogue_variant_id' => $option->variant_id],
        ])->all());
    }

    $removed = 0;
    $style->skus()
        ->whereNotIn('option_signature', array_keys($expectedSignatures))
        ->get()
        ->each(function (BrandCatalogueSku $sku) use (&$removed): void {
            $sku->optionValues()->detach();
            $sku->delete();
            $removed++;
        });

    return [
        'style_id' => $style->id,
        'style_name' => $style->name,
        'created_skus' => $created,
        'updated_skus' => $updated,
        'removed_skus' => $removed,
        'final_sku_count' => $style->skus()->count(),
    ];
});

echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
