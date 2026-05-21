<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariantGroupType extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
