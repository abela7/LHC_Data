<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class CatalogueImage extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CatalogueSource::class, 'source_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function displayUrl(): ?string
    {
        if ($this->external_url) {
            return $this->portableExternalUrl((string) $this->external_url);
        }

        if (! $this->storage_disk || ! $this->storage_path) {
            return null;
        }

        $url = Storage::disk($this->storage_disk)->url($this->storage_path);
        $path = parse_url($url, PHP_URL_PATH);

        return $path ?: $url;
    }

    private function portableExternalUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        if (! $host || ! $path) {
            return $url;
        }

        $localHosts = ['localhost', '127.0.0.1', '::1'];
        $publicMarker = '/LHC_Data/public/';

        if (! in_array(strtolower($host), $localHosts, true) || ! str_contains($path, $publicMarker)) {
            return $url;
        }

        $relativePath = ltrim(substr($path, strpos($path, $publicMarker) + strlen($publicMarker)), '/');
        $portableUrl = url($relativePath);
        $query = parse_url($url, PHP_URL_QUERY);

        return $query ? $portableUrl.'?'.$query : $portableUrl;
    }
}
