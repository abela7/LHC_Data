<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MobileCaptureUpload extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'background_removed_at' => 'datetime',
        ];
    }

    public function publicUrl(): string
    {
        return Storage::disk($this->storage_disk ?: 'public')->url($this->storage_path);
    }

    public function originalUrl(): ?string
    {
        if (! $this->original_storage_path) {
            return null;
        }

        return Storage::disk($this->storage_disk ?: 'public')->url($this->original_storage_path);
    }

    public function processedUrl(): ?string
    {
        if (! $this->processed_storage_path) {
            return null;
        }

        return Storage::disk($this->storage_disk ?: 'public')->url($this->processed_storage_path);
    }
}
