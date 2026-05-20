<?php

use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariantOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$style = BrandCatalogueStyle::query()->with('variants.options', 'skus.optionValues.variant')->findOrFail(95);

$mapping = [
    'UV60' => 'UV-60',
    'UV613' => 'UV-613',
    'UVOR' => 'Ice Orange',
    'UVPK' => 'UV-PINK',
    'UVSB' => 'UV-SB',
    'UVYL' => 'UV-Yellow',
];

DB::transaction(function () use ($style, $mapping): void {
    $colourVariant = $style->variants->firstWhere('name', 'Colour');

    if (! $colourVariant) {
        throw new RuntimeException('Colour variant not found for Pre-Stretched Ultraviolet.');
    }

    foreach ($colourVariant->options as $option) {
        $newLabel = $mapping[$option->label] ?? null;

        if (! $newLabel) {
            continue;
        }

        $option->label = $newLabel;
        $option->value = $newLabel;
        $option->save();
    }

    $style->note = trim((string) $style->note."\nColour labels aligned to shop intake: UV-60, UV-613, Ice Orange, UV-PINK, UV-SB, UV-Yellow.");
    $style->save();

    $style->load('skus.optionValues.variant');

    foreach ($style->skus as $sku) {
        $parts = selectedVariantParts($sku);
        $signature = collect($parts)
            ->map(fn (array $part): string => $part['variant'].':'.$part['label'])
            ->implode('|');
        $colour = collect($parts)->firstWhere('variant', 'Colour')['label'] ?? null;
        $length = collect($parts)->firstWhere('variant', 'Length')['label'] ?? null;

        $sku->option_signature = $signature;
        $sku->name = trim('X-Pression Pre-Stretched Ultraviolet'.($length ? ' - '.$length : '').($colour ? ' - Colour '.$colour : ''));
        $sku->slug = scopedSkuSlug($style->id, $sku->name, $sku->id);
        $sku->save();
    }
});

$style->load('variants.options', 'skus');

echo "Pre-Stretched Ultraviolet colour variants fixed.\n";
foreach ($style->variants as $variant) {
    echo '- '.$variant->name.': '.$variant->options->pluck('label')->implode(', ').PHP_EOL;
}

echo "SKUs:\n";
foreach ($style->skus as $sku) {
    echo '- '.$sku->name.' | '.$sku->option_signature.PHP_EOL;
}

/**
 * @return list<array{variant:string,label:string}>
 */
function selectedVariantParts(BrandCatalogueSku $sku): array
{
    return $sku->optionValues
        ->sortBy(fn (BrandCatalogueVariantOption $option): string => str_pad((string) $option->variant->sort_order, 4, '0', STR_PAD_LEFT).':'.str_pad((string) $option->sort_order, 4, '0', STR_PAD_LEFT))
        ->map(fn (BrandCatalogueVariantOption $option): array => [
            'variant' => $option->variant->name,
            'label' => $option->label,
        ])
        ->values()
        ->all();
}

function scopedSkuSlug(int $styleId, string $name, int $ignoreId): string
{
    $base = Str::slug($name) ?: 'sku';
    $slug = $base;
    $i = 2;

    while (BrandCatalogueSku::query()
        ->where('brand_catalogue_style_id', $styleId)
        ->where('slug', $slug)
        ->whereKeyNot($ignoreId)
        ->exists()) {
        $slug = "{$base}-{$i}";
        $i++;
    }

    return $slug;
}
