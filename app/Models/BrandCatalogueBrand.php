<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BrandCatalogueBrand extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function catalogue(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogue::class, 'brand_catalogue_id');
    }

    public function styles(): HasMany
    {
        return $this->hasMany(BrandCatalogueStyle::class)->orderBy('sort_order')->orderBy('name');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BrandCatalogueLine::class)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function defaultLine(): HasOne
    {
        return $this->hasOne(BrandCatalogueLine::class)->where('is_default', true);
    }

    public function productTypes(): HasMany
    {
        return $this->hasMany(BrandCatalogueProductType::class)
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
