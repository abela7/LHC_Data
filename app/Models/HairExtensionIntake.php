<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class HairExtensionIntake extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'product_type_unknown' => 'boolean',
            'style_unknown' => 'boolean',
            'classification_path' => 'array',
            'variant_groups' => 'array',
            'variant_structure' => 'array',
            'verification_urls' => 'array',
            'last_synced_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueBrand::class, 'brand_catalogue_brand_id');
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueProductType::class, 'brand_catalogue_product_type_id');
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueStyle::class, 'brand_catalogue_style_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(HairExtensionIntakePhoto::class)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'store_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(InventorySection::class, 'section_id');
    }

    public function subsection(): BelongsTo
    {
        return $this->belongsTo(InventorySubsection::class, 'subsection_id');
    }

    public function aiSuggestions(): HasMany
    {
        return $this->hasMany(HairExtensionIntakeAiSuggestion::class);
    }

    public function photoUrl(): ?string
    {
        $photo = $this->relationLoaded('photos')
            ? $this->photos->first()
            : $this->photos()->first();

        if ($photo) {
            return $photo->displayUrl();
        }

        if (! $this->photo_disk || ! $this->photo_path) {
            return null;
        }

        $url = Storage::disk($this->photo_disk)->url($this->photo_path);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }
}
