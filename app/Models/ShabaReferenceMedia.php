<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShabaReferenceMedia extends Model
{
    protected $table = 'shaba_reference_media';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'variant_ids' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShabaReferenceProduct::class, 'shaba_reference_product_id');
    }
}
