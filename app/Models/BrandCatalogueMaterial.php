<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandCatalogueMaterial extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueProductType::class, 'brand_catalogue_product_type_id');
    }

    public function styles(): HasMany
    {
        return $this->hasMany(BrandCatalogueStyle::class)
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
