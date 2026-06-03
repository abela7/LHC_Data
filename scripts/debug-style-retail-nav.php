<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$styleId = (int) ($argv[1] ?? 75);

$style = App\Models\BrandCatalogueStyle::query()
    ->with(['variants.options'])
    ->find($styleId);

if (! $style) {
    fwrite(STDERR, "Style {$styleId} not found\n");
    exit(1);
}

echo "Style: {$style->id} {$style->name}\n\n";

$families = App\Support\RetailStyleFamilyCatalogue::familiesForStyle($styleId);

echo "Families:\n";
foreach ($families as $family) {
    $label = App\Support\RetailStyleFamilyCatalogue::familyDisplayLabel($family, $style->variants);
    echo "  #{$family->id} scope=".($family->catalogue_scope_key ?? 'NULL')." count={$family->products_count} label={$label}\n";
}

echo "\nCatalogue options:\n";
foreach ($style->variants as $variant) {
    foreach ($variant->options as $option) {
        $resolved = App\Support\RetailStyleFamilyCatalogue::resolveFamilyForCatalogueOption(
            $families,
            $variant->name,
            $option,
        );
        $fid = $resolved?->id ?? '—';
        echo "  {$variant->name} / {$option->label} (opt {$option->id}) → family {$fid}\n";
    }
}
