<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

class BrandCatalogueSku extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueStyle::class, 'brand_catalogue_style_id');
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(
            BrandCatalogueVariantOption::class,
            'brand_catalogue_sku_variant_options',
            'brand_catalogue_sku_id',
            'brand_catalogue_variant_option_id',
        )->withPivot('brand_catalogue_variant_id')->withTimestamps();
    }

    public function images(): MorphMany
    {
        return $this->morphMany(CatalogueImage::class, 'imageable')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function primaryImage(): ?CatalogueImage
    {
        return $this->images()->where('is_primary', true)->first()
            ?? $this->images()->first()
            ?? $this->selectedOptionPrimaryImage()
            ?? $this->style?->primaryImage();
    }

    public function selectedOptionPrimaryImage(): ?CatalogueImage
    {
        $this->loadMissing('optionValues.variant', 'optionValues.images');

        $variantTypePriority = [
            'colour_name' => 0,
            'colour_code' => 0,
            'short_code' => 1,
            'measurement' => 2,
            'count' => 3,
            'text' => 4,
        ];

        /** @var Collection<int, BrandCatalogueVariantOption> $options */
        $options = $this->optionValues
            ->sortBy(fn (BrandCatalogueVariantOption $option) => sprintf(
                '%02d:%04d:%04d:%s',
                $variantTypePriority[$option->variant->variant_type] ?? 9,
                $option->variant->sort_order,
                $option->sort_order,
                $option->label,
            ))
            ->values();

        foreach ($options as $option) {
            $image = $option->primaryImage();

            if ($image) {
                return $image;
            }
        }

        return null;
    }
}
