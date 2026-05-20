<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class IntakeSessionVariantPhoto extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(IntakeSessionVariant::class, 'intake_session_variant_id');
    }

    public function displayUrl(): ?string
    {
        if (! $this->storage_disk || ! $this->storage_path) {
            return null;
        }

        return Storage::disk($this->storage_disk)->url($this->storage_path);
    }
}
