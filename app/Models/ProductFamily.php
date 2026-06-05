<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductFamily extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'brand_catalogue_style_id' => 'integer',
            'published_at' => 'datetime',
            'sort_order' => 'integer',
            'sku_family_seq' => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function catalogueStyle(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueStyle::class, 'brand_catalogue_style_id');
    }

    public function catalogueLine(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueLine::class, 'brand_catalogue_line_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('sort_order')->orderBy('name');
    }

    public function variantGroups(): HasMany
    {
        return $this->hasMany(ProductVariantGroup::class)->orderBy('sort_order')->orderBy('name');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)
            ->whereNull('product_id')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function ecommerceProfile(): HasOne
    {
        return $this->hasOne(ProductEcommerceProfile::class)->where('profile_level', 'family');
    }

    public function categoryAssignments(): HasMany
    {
        return $this->hasMany(ProductCategoryAssignment::class)
            ->whereNull('product_id')
            ->orderBy('assignment_type');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
    }

    /**
     * Short family/style title for page headers (not the full grouping path).
     */
    protected function displayFamilyName(): Attribute
    {
        return Attribute::get(function (): string {
            $styleName = $this->catalogueStyle?->name;
            if (is_string($styleName) && trim($styleName) !== '') {
                return trim($styleName);
            }

            $name = trim((string) $this->family_name);
            if ($name === '') {
                return '';
            }

            if (str_contains($name, ' > ')) {
                $segments = array_values(array_filter(array_map('trim', explode('>', $name))));

                return (string) (end($segments) ?: $name);
            }

            return $name;
        });
    }
}
