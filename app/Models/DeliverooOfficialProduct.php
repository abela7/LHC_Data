<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverooOfficialProduct extends Model
{
    protected $guarded = [];

    public function getPriceDisplayAttribute(): string
    {
        if ($this->price === null) {
            return 'Not set';
        }

        return html_entity_decode('&pound;', ENT_QUOTES, 'UTF-8').number_format((float) $this->price, 2);
    }

    protected function casts(): array
    {
        return [
            'image_urls' => 'array',
            'option_values' => 'array',
            'price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }
}
