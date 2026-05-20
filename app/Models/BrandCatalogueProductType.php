<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandCatalogueProductType extends Model
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

    public function line(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueLine::class, 'brand_catalogue_line_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(BrandCatalogueMaterial::class)
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function styles(): HasMany
    {
        return $this->hasMany(BrandCatalogueStyle::class, 'brand_catalogue_product_type_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
