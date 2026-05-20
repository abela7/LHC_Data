<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HairExtensionIntakeAiSuggestion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'suggestion' => 'array',
            'source_urls' => 'array',
        ];
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(HairExtensionIntake::class, 'hair_extension_intake_id');
    }
}
