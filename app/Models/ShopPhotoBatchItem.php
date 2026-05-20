<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopPhotoBatchItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'grouping_path' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ShopPhotoBatch::class, 'shop_photo_batch_id');
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(HairExtensionIntake::class, 'hair_extension_intake_id');
    }
}
