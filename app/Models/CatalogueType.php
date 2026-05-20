<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class CatalogueType extends Model
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
            'sort_order' => 'integer',
            'approved_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(CatalogueFamily::class, 'catalogue_family_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function mergedIntoType(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_type_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(CatalogueVariant::class, 'catalogue_type_id');
    }

    public function sources(): MorphMany
    {
        return $this->morphMany(CatalogueSource::class, 'sourceable');
    }

    public function images(): MorphMany
    {
        return $this->morphMany(CatalogueImage::class, 'imageable');
    }

    public function shopMatch(): MorphOne
    {
        return $this->morphOne(ShopMatch::class, 'matchable');
    }

    public function reviewActions(): MorphMany
    {
        return $this->morphMany(ReviewAction::class, 'reviewable');
    }
}
