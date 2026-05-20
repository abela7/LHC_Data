<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandCatalogue extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function brands(): HasMany
    {
        return $this->hasMany(BrandCatalogueBrand::class)->orderBy('sort_order')->orderBy('name');
    }
}
