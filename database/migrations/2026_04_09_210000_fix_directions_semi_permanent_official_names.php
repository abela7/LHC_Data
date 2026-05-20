<?php

use App\Models\DeliverooOfficialProduct;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DeliverooOfficialProduct::query()
            ->where('brand_slug', 'directions')
            ->where('family_name', 'Semi-Permanent Hair Colours')
            ->whereNotNull('variant_name')
            ->where('variant_name', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($products): void {
                foreach ($products as $product) {
                    /** @var DeliverooOfficialProduct $product */
                    $shade = trim((string) $product->variant_name);
                    $product->update([
                        'official_name' => 'Directions Semi Permanent Hair Dye - '.$shade,
                    ]);
                }
            });
    }

    public function down(): void
    {
        DeliverooOfficialProduct::query()
            ->where('brand_slug', 'directions')
            ->where('family_name', 'Semi-Permanent Hair Colours')
            ->whereNotNull('variant_name')
            ->where('variant_name', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($products): void {
                foreach ($products as $product) {
                    /** @var DeliverooOfficialProduct $product */
                    $shade = trim((string) $product->variant_name);
                    $product->update([
                        'official_name' => $shade.' Hair Colour - Semi Permanent Hair Dye',
                    ]);
                }
            });
    }
};
