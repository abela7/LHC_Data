<?php

use App\Models\BrandCatalogueStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$style = BrandCatalogueStyle::query()
    ->with(['variants.options', 'skus.optionValues.variant'])
    ->findOrFail(31);

$result = DB::transaction(function () use ($style): array {
    $lengthVariant = $style->variants->firstWhere('name', 'Length');
    $packVariant = $style->variants->firstWhere('name', 'Pack count');
    $colourVariant = $style->variants->firstWhere('name', 'Colour');

    $length28 = $lengthVariant->options()
        ->where(function ($query): void {
            $query->where('value', '28')->orWhere('label', '28');
        })
        ->first();

    if (! $length28) {
        $length28 = $lengthVariant->options()->create([
            'label' => '28',
            'value' => '28',
            'sort_order' => 4,
        ]);
    }

    $repaired = 0;

    $style->load(['skus.optionValues.variant']);

    foreach ($style->skus as $sku) {
        if (! str_contains($sku->name, ' 28 inch ')) {
            continue;
        }

        $options = $sku->optionValues;
        $colour = $options->first(fn ($option) => (int) $option->variant_id === (int) $colourVariant->id);
        $pack = $options->first(fn ($option) => (int) $option->variant_id === (int) $packVariant->id);

        if (! $colour || ! $pack) {
            continue;
        }

        $selectedOptions = collect([$length28, $colour, $pack]);
        $signatureParts = $selectedOptions
            ->map(fn ($option): string => $option->variant_id.':'.$option->id)
            ->all();
        sort($signatureParts, SORT_NATURAL);

        $sku->update([
            'option_signature' => implode('|', $signatureParts),
        ]);

        $sku->optionValues()->sync($selectedOptions->mapWithKeys(fn ($option) => [
            $option->id => ['brand_catalogue_variant_id' => $option->variant_id],
        ])->all());

        $repaired++;
    }

    return [
        'length_option_id' => $length28->id,
        'repaired_28_inch_skus' => $repaired,
    ];
});

echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;

