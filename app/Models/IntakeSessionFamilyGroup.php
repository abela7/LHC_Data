<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntakeSessionFamilyGroup extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scope_json' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(IntakeSession::class, 'intake_session_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(IntakeSessionVariant::class)->orderBy('id');
    }
}
