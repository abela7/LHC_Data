<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandCatalogueVariant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public const TYPES = [
        'measurement',
        'colour_name',
        'colour_code',
        'short_code',
        'count',
        'text',
    ];

    public function style(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueStyle::class, 'brand_catalogue_style_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(BrandCatalogueVariantOption::class, 'variant_id')->orderBy('sort_order')->orderBy('label');
    }
}
