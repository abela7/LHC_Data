<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class BrandCatalogueStyle extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueBrand::class, 'brand_catalogue_brand_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueMaterial::class, 'brand_catalogue_material_id');
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueProductType::class, 'brand_catalogue_product_type_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(BrandCatalogueVariant::class)->orderBy('sort_order')->orderBy('name');
    }

    public function skus(): HasMany
    {
        return $this->hasMany(BrandCatalogueSku::class)
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function images(): MorphMany
    {
        return $this->morphMany(CatalogueImage::class, 'imageable')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function primaryImage(): ?CatalogueImage
    {
        return $this->images()->where('is_primary', true)->first()
            ?? $this->images()->first();
    }
}
