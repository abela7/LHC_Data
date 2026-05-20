<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopProductIntake extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'variant_groups' => 'array',
            'variant_structure' => 'array',
            'sku_rows' => 'array',
            'shelf_ticket_price' => 'decimal:2',
            'submitted_at' => 'datetime',
        ];
    }

    public function sourceFamily(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'source_product_family_id');
    }
}
