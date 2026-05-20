<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLevel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'stock_quantity' => 'decimal:2',
            'low_stock_threshold' => 'decimal:2',
            'reorder_quantity' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(InventorySection::class, 'inventory_section_id');
    }

    public function subsection(): BelongsTo
    {
        return $this->belongsTo(InventorySubsection::class, 'inventory_subsection_id');
    }
}
