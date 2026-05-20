<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class CatalogueFamily extends Model
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
            'source_confidence' => 'decimal:2',
            'import_confidence' => 'decimal:2',
            'needs_source_verification' => 'boolean',
            'duplicate_flag' => 'boolean',
            'imported_json_snapshot' => 'array',
            'approved_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
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

    public function mergedIntoFamily(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_family_id');
    }

    public function types(): HasMany
    {
        return $this->hasMany(CatalogueType::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(CatalogueVariant::class);
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

    public function importRecords(): HasMany
    {
        return $this->hasMany(ImportRecord::class, 'target_family_id');
    }
}
