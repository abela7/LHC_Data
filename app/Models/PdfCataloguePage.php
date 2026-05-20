<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdfCataloguePage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'products_count' => 'integer',
            'needs_review' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(PdfCatalogueProduct::class)->orderBy('sort_order')->orderBy('product_code');
    }
}
