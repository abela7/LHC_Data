<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_pos_active' => 'boolean',
            'is_ecommerce_active' => 'boolean',
            'is_inventory_tracked' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function catalogueSku(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueSku::class, 'brand_catalogue_sku_id');
    }

    public function variantValues(): HasMany
    {
        return $this->hasMany(ProductVariantValue::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id');
    }

    public function price(): HasOne
    {
        return $this->hasOne(ProductPrice::class);
    }

    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(InventoryLevel::class);
    }

    public function posProfile(): HasOne
    {
        return $this->hasOne(ProductPosProfile::class);
    }

    public function ecommerceProfile(): HasOne
    {
        return $this->hasOne(ProductEcommerceProfile::class)->where('profile_level', 'sku');
    }

    public function categoryAssignments(): HasMany
    {
        return $this->hasMany(ProductCategoryAssignment::class)->orderBy('assignment_type');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
    }
}
