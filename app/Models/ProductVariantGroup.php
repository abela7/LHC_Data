<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariantGroup extends Model
{
    public const AXIS_ROLE_MAIN = 'main';

    public const AXIS_ROLE_SUB_MAIN = 'sub_main';

    public const AXIS_ROLE_COMMON = 'common';

    /** @var array<string, string> role => human label */
    public const ROLE_LABELS = [
        self::AXIS_ROLE_MAIN => 'Main',
        self::AXIS_ROLE_SUB_MAIN => 'Sub-main',
        self::AXIS_ROLE_COMMON => 'Common',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function hasExplicitRole(): bool
    {
        return in_array($this->axis_role, array_keys(self::ROLE_LABELS), true);
    }

    public function isMainRole(): bool
    {
        return $this->axis_role === self::AXIS_ROLE_MAIN;
    }

    public function isSubMainRole(): bool
    {
        return $this->axis_role === self::AXIS_ROLE_SUB_MAIN;
    }

    public function isCommonRole(): bool
    {
        return $this->axis_role === self::AXIS_ROLE_COMMON;
    }

    public function roleLabel(): ?string
    {
        return self::ROLE_LABELS[$this->axis_role] ?? null;
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductVariantOption::class)->orderBy('sort_order')->orderBy('label');
    }
}
