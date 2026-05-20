<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductMedia extends Model
{
    protected $table = 'product_media';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'is_primary' => 'boolean',
            'is_offline_ready' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function displayUrl(): ?string
    {
        if ($this->storage_disk && $this->storage_path) {
            $url = Storage::disk($this->storage_disk)->url($this->storage_path);
            $path = parse_url($url, PHP_URL_PATH);

            return $path ?: $url;
        }

        return $this->external_url ?: null;
    }

    public function sourceDisplay(): string
    {
        return match ($this->source_type) {
            'catalogue_source' => 'Catalogue source',
            'file_upload' => 'Uploaded file',
            'pasted_upload' => 'Pasted image',
            'phone_camera' => 'Phone camera',
            'mirrored_url' => 'URL copied locally',
            'r2', 's3' => strtoupper($this->source_type),
            default => 'External URL',
        };
    }
}
