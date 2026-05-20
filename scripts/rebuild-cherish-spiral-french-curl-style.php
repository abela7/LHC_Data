<?php

use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use App\Models\HairExtensionIntakeAiSuggestion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$styleId = 31;
$intakeId = 24;

$style = BrandCatalogueStyle::query()
    ->with(['variants.options', 'skus.optionValues.variant'])
    ->findOrFail($styleId);

$suggestion = HairExtensionIntakeAiSuggestion::query()
    ->where('hair_extension_intake_id', $intakeId)
    ->where('provider', 'manual')
    ->where('model', 'mamado-manufacturer-sheet')
    ->firstOrFail()
    ->suggestion;

$groups = $suggestion['proposed_variant_groups'] ?? [];

$normalisePack = fn (string $value): string => strtoupper(trim($value));
$normaliseLength = fn (string $value): string => trim(str_ireplace([' inches', ' inch', '"'], '', $value));
$normaliseColour = function (string $value): string {
    $value = strtoupper(trim($value));

    return match ($value) {
        'BLKGLD' => 'BLACKGOLD',
        'TCOPPER' => 'TCOPPER',
        'HOT' => 'HOT PINK',
        'ROSE' => 'ROSE WINE',
        'ASH' => 'ASH BLONDE',
        default => $value,
    };
};

$comboKey = fn (string $pack, string $length, string $colour): string => implode('|', [
    $normalisePack($pack),
    $normaliseLength($length),
    $normaliseColour($colour),
]);

$expectedCombos = [];
$packValues = [];
$lengthValues = [];
$colourValues = [];

foreach ($groups as $group) {
    $pack = $normalisePack((string) $group['pack_count']);
    $length = $normaliseLength((string) $group['length']);

    $packValues[$pack] = $pack;
    $lengthValues[$length] = $length;

    foreach ($group['colour_values'] as $rawColour) {
        $colour = $normaliseColour((string) $rawColour);
        $colourValues[$colour] = $colour;
        $expectedCombos[$comboKey($pack, $length, $colour)] = [
            'pack' => $pack,
            'length' => $length,
            'colour' => $colour,
        ];
    }
}

uksort($packValues, 'strnatcasecmp');
uksort($lengthValues, 'strnatcasecmp');
uksort($colourValues, 'strnatcasecmp');

$skuBaseName = 'Cherish Bulk Pre-Stretched Spiral French Curl';

$result = DB::transaction(function () use (
    $style,
    $expectedCombos,
    $packValues,
    $lengthValues,
    $colourValues,
    $comboKey,
    $normalisePack,
    $normaliseLength,
    $normaliseColour,
    $skuBaseName,
) {
    $style->update([
        'name' => $skuBaseName,
        'slug' => 'cherish-bulk-pre-stretched-spiral-french-curl',
        'note' => 'Rebuilt from Mamado manufacturer Spiral French Curl sheets. Review stocked quantities before publishing to retail products.',
        'url' => 'https://www.mamado.co.uk/',
        'material_name' => 'Synthetic Hair',
    ]);

    $style->load(['variants.options', 'skus.optionValues.variant']);

    /** @var BrandCatalogueVariant $lengthVariant */
    $lengthVariant = $style->variants->first(fn ($variant) => strcasecmp($variant->name, 'Length') === 0)
        ?? $style->variants()->create([
            'name' => 'Length',
            'variant_type' => 'measurement',
            'sort_order' => 10,
        ]);

    /** @var BrandCatalogueVariant $colourVariant */
    $colourVariant = $style->variants->first(fn ($variant) => strcasecmp($variant->name, 'Colour') === 0)
        ?? $style->variants()->create([
            'name' => 'Colour',
            'variant_type' => 'colour_code',
            'sort_order' => 20,
        ]);

    /** @var BrandCatalogueVariant $packVariant */
    $packVariant = $style->variants->first(fn ($variant) => in_array(strtolower($variant->name), ['bundle', 'pack count'], true))
        ?? $style->variants()->create([
            'name' => 'Pack count',
            'variant_type' => 'count',
            'sort_order' => 30,
        ]);

    $lengthVariant->update(['name' => 'Length', 'variant_type' => 'measurement', 'sort_order' => 10]);
    $colourVariant->update(['name' => 'Colour', 'variant_type' => 'colour_code', 'sort_order' => 20]);
    $packVariant->update(['name' => 'Pack count', 'variant_type' => 'count', 'sort_order' => 30]);

    $ensureOptions = function (BrandCatalogueVariant $variant, array $values, callable $normaliser): array {
        $variant->load('options');
        $byNormalised = [];

        foreach ($variant->options as $option) {
            $normalised = $normaliser((string) $option->value);
            if (! isset($values[$normalised])) {
                continue;
            }

            if (isset($byNormalised[$normalised])) {
                $option->delete();
                continue;
            }

            $option->update([
                'label' => $values[$normalised],
                'value' => $values[$normalised],
                'sort_order' => array_search($normalised, array_keys($values), true) + 1,
            ]);
            $byNormalised[$normalised] = $option->fresh();
        }

        foreach ($values as $normalised => $value) {
            if (isset($byNormalised[$normalised])) {
                continue;
            }

            $byNormalised[$normalised] = $variant->options()->create([
                'label' => $value,
                'value' => $value,
                'sort_order' => array_search($normalised, array_keys($values), true) + 1,
            ]);
        }

        return $byNormalised;
    };

    $lengthOptions = $ensureOptions($lengthVariant, $lengthValues, $normaliseLength);
    $colourOptions = $ensureOptions($colourVariant, $colourValues, $normaliseColour);
    $packOptions = $ensureOptions($packVariant, $packValues, $normalisePack);

    $style->load(['skus.optionValues.variant']);

    $existingSkuByKey = [];
    $deletedInvalid = 0;
    $updatedExisting = 0;
    $createdMissing = 0;

    foreach ($style->skus as $sku) {
        $attributes = [];
        foreach ($sku->optionValues as $option) {
            $attributes[strtolower((string) $option->variant?->name)] = (string) $option->value;
        }

        $key = $comboKey(
            $attributes['pack count'] ?? $attributes['bundle'] ?? '',
            $attributes['length'] ?? '',
            $attributes['colour'] ?? '',
        );

        if (! isset($expectedCombos[$key])) {
            $sku->optionValues()->detach();
            $sku->delete();
            $deletedInvalid++;
            continue;
        }

        if (isset($existingSkuByKey[$key])) {
            $sku->optionValues()->detach();
            $sku->delete();
            $deletedInvalid++;
            continue;
        }

        $combo = $expectedCombos[$key];
        $name = "{$skuBaseName} {$combo['pack']} {$combo['length']} inch - Colour {$combo['colour']}";
        $signatureParts = [
            $lengthVariant->id.':'.$lengthOptions[$combo['length']]->id,
            $colourVariant->id.':'.$colourOptions[$combo['colour']]->id,
            $packVariant->id.':'.$packOptions[$combo['pack']]->id,
        ];
        sort($signatureParts, SORT_NATURAL);

        $sku->update([
            'name' => $name,
            'slug' => Str::slug($name).'-'.$sku->id,
            'option_signature' => implode('|', $signatureParts),
            'sort_order' => count($existingSkuByKey) + 1,
            'is_active' => true,
        ]);
        $sku->optionValues()->sync([
            $lengthOptions[$combo['length']]->id => ['brand_catalogue_variant_id' => $lengthVariant->id],
            $colourOptions[$combo['colour']]->id => ['brand_catalogue_variant_id' => $colourVariant->id],
            $packOptions[$combo['pack']]->id => ['brand_catalogue_variant_id' => $packVariant->id],
        ]);

        $existingSkuByKey[$key] = $sku;
        $updatedExisting++;
    }

    foreach ($expectedCombos as $key => $combo) {
        if (isset($existingSkuByKey[$key])) {
            continue;
        }

        $name = "{$skuBaseName} {$combo['pack']} {$combo['length']} inch - Colour {$combo['colour']}";
        $signatureParts = [
            $lengthVariant->id.':'.$lengthOptions[$combo['length']]->id,
            $colourVariant->id.':'.$colourOptions[$combo['colour']]->id,
            $packVariant->id.':'.$packOptions[$combo['pack']]->id,
        ];
        sort($signatureParts, SORT_NATURAL);

        $sku = $style->skus()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'option_signature' => implode('|', $signatureParts),
            'sort_order' => count($existingSkuByKey) + 1,
            'is_active' => true,
        ]);
        $sku->optionValues()->sync([
            $lengthOptions[$combo['length']]->id => ['brand_catalogue_variant_id' => $lengthVariant->id],
            $colourOptions[$combo['colour']]->id => ['brand_catalogue_variant_id' => $colourVariant->id],
            $packOptions[$combo['pack']]->id => ['brand_catalogue_variant_id' => $packVariant->id],
        ]);

        $existingSkuByKey[$key] = $sku;
        $createdMissing++;
    }

    foreach ([$lengthVariant, $colourVariant, $packVariant] as $variant) {
        $validOptionIds = match ($variant->id) {
            $lengthVariant->id => collect($lengthOptions)->pluck('id')->all(),
            $colourVariant->id => collect($colourOptions)->pluck('id')->all(),
            default => collect($packOptions)->pluck('id')->all(),
        };

        $variant->options()
            ->whereNotIn('id', $validOptionIds)
            ->doesntHave('images')
            ->delete();
    }

    return [
        'updated_existing_skus' => $updatedExisting,
        'created_missing_skus' => $createdMissing,
        'deleted_invalid_skus' => $deletedInvalid,
        'final_sku_count' => $style->skus()->count(),
        'length_options' => array_values($lengthValues),
        'colour_option_count' => count($colourValues),
        'pack_options' => array_values($packValues),
    ];
});

echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;

