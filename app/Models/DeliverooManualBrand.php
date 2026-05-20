<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverooManualBrand extends Model
{
    protected $fillable = [
        'label',
        'slug',
        'category',
    ];
}
