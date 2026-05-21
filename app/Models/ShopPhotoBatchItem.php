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

    public function resolvedSourcePath(): ?string
    {
        $sourcePath = trim((string) $this->source_path);

        if ($sourcePath === '') {
            return null;
        }

        $candidates = [$sourcePath];
        $normalized = str_replace('\\', '/', $sourcePath);
        $marker = 'Shop Photos/';
        $markerPosition = stripos($normalized, $marker);

        if ($markerPosition !== false) {
            $candidates[] = base_path(substr($normalized, $markerPosition));
        } elseif (str_starts_with($normalized, $marker)) {
            $candidates[] = base_path($normalized);
        }

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
