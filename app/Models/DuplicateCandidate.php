<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateCandidate extends Model
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
            'similarity_score' => 'decimal:2',
            'match_basis' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function leftFamily(): BelongsTo
    {
        return $this->belongsTo(CatalogueFamily::class, 'left_family_id');
    }

    public function rightFamily(): BelongsTo
    {
        return $this->belongsTo(CatalogueFamily::class, 'right_family_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
