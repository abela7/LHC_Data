<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPosProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'discount_allowed' => 'boolean',
            'quick_sale_enabled' => 'boolean',
        ];
    }
}
