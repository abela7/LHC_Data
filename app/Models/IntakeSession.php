<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntakeSession extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'observations_json' => 'array',
            'current_step' => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueBrand::class, 'brand_catalogue_brand_id');
    }

    public function matchedStyle(): BelongsTo
    {
        return $this->belongsTo(BrandCatalogueStyle::class, 'matched_style_id');
    }

    public function publishedFamily(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'published_family_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(IntakeSessionVariant::class)->orderBy('id');
    }

    public function familyGroups(): HasMany
    {
        return $this->hasMany(IntakeSessionFamilyGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    public function aiCalls(): HasMany
    {
        return $this->hasMany(IntakeSessionAiCall::class)->orderBy('created_at');
    }

    public function codexBridgeTasks(): HasMany
    {
        return $this->hasMany(CodexBridgeTask::class)->latest();
    }

    public function latestMatchCall(): HasMany
    {
        return $this->aiCalls()->where('call_type', 'match')->latest('call_index');
    }

    public function latestReviewCall(): HasMany
    {
        return $this->aiCalls()->where('call_type', 'review')->latest('call_index');
    }
}
