<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntakeSessionVariant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'manually_added' => 'boolean',
            'manual_axes_json' => 'array',
            'price' => 'decimal:2',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(IntakeSession::class, 'intake_session_id');
    }

    public function familyGroup(): BelongsTo
    {
        return $this->belongsTo(IntakeSessionFamilyGroup::class, 'intake_session_family_group_id');
    }

    public function catalogueSku(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueSku::class, 'brand_catalogue_sku_id');
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

    public function photos(): HasMany
    {
        return $this->hasMany(IntakeSessionVariantPhoto::class)->orderBy('sort_order')->orderBy('id');
    }
}
