<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrueProductPrice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }
}
