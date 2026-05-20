<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class BrandCatalogueVariantOption extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueVariant::class, 'variant_id');
    }

    public function skus(): BelongsToMany
    {
        return $this->belongsToMany(
            BrandCatalogueSku::class,
            'brand_catalogue_sku_variant_options',
            'brand_catalogue_variant_option_id',
            'brand_catalogue_sku_id',
        )->withPivot('brand_catalogue_variant_id')->withTimestamps();
    }

    public function images(): MorphMany
    {
        return $this->morphMany(CatalogueImage::class, 'imageable')
            ->orderByRaw("CASE WHEN image_role IN ('main', 'display', 'display_image', 'main_display') THEN 0 ELSE 1 END")
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function primaryImage(): ?CatalogueImage
    {
        $preferred = $this->images()->whereIn('image_role', ['main', 'display', 'display_image', 'main_display', 'variant', 'swatch', 'gallery', 'detail']);

        return $preferred->where('is_primary', true)->first()
            ?? $preferred->first()
            ?? $this->images()->where('is_primary', true)->first()
            ?? $this->images()->first();
    }
}
