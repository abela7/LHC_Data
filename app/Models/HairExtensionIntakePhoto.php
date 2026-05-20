<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class HairExtensionIntakePhoto extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(HairExtensionIntake::class, 'hair_extension_intake_id');
    }

    public function displayUrl(): ?string
    {
        if (! $this->storage_disk || ! $this->storage_path) {
            return null;
        }

        $url = Storage::disk($this->storage_disk)->url($this->storage_path);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }

    public function roleLabel(): string
    {
        return match ($this->image_role) {
            'main' => 'Main product photo',
            'packaging_front' => 'Packaging front',
            'packaging_back' => 'Packaging back',
            'label_closeup' => 'Label close-up',
            'variant_evidence' => 'Variant evidence',
            'colour_evidence' => 'Colour evidence',
            'family_reference' => 'Family reference',
            'shelf_reference' => 'Shelf reference',
            'source_reference' => 'Source reference',
            default => ucfirst(str_replace('_', ' ', (string) $this->image_role)),
        };
    }
}
