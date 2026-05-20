<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ImportRecord extends Model
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
            'normalized_json' => 'array',
            'parse_warnings' => 'array',
            'import_confidence' => 'decimal:2',
            'staged_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function targetFamily(): BelongsTo
    {
        return $this->belongsTo(CatalogueFamily::class, 'target_family_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function images(): MorphMany
    {
        return $this->morphMany(CatalogueImage::class, 'imageable');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ImportRecordLink::class);
    }

    public function reviewActions(): MorphMany
    {
        return $this->morphMany(ReviewAction::class, 'reviewable');
    }
}
