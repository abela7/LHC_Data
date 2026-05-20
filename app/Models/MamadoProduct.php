<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MamadoProduct extends Model
{
    protected $guarded = [];

    public function getGrossUnitPriceDisplayAttribute(): string
    {
        return html_entity_decode('&pound;', ENT_QUOTES, 'UTF-8').number_format((float) $this->gross_unit_price, 2);
    }

    public function getSellablePriceDisplayAttribute(): string
    {
        if ($this->sellable_price === null) {
            return 'Not set';
        }

        return html_entity_decode('&pound;', ENT_QUOTES, 'UTF-8').number_format((float) $this->sellable_price, 2);
    }

    protected function casts(): array
    {
        return [
            'gross_unit_price' => 'decimal:2',
            'sellable_price' => 'decimal:2',
            'source_order_date' => 'date',
            'source_delivery_date' => 'date',
            'raw_order_line' => 'array',
            'image_urls' => 'array',
        ];
    }
}
