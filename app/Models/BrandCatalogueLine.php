<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandCatalogueLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueBrand::class, 'brand_catalogue_brand_id');
    }

    public function productTypes(): HasMany
    {
        return $this->hasMany(BrandCatalogueProductType::class)
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
