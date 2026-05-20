<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductEcommerceProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_published' => 'boolean',
            'click_and_collect_enabled' => 'boolean',
            'shipping_weight' => 'decimal:3',
        ];
    }
}
