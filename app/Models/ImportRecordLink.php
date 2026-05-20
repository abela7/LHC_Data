<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ImportRecordLink extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function importRecord(): BelongsTo
    {
        return $this->belongsTo(ImportRecord::class);
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
