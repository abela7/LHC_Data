<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfSourceBrandDecision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_excluded' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
