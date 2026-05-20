<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShabaReferenceProduct extends Model
{
    public const DEPARTMENT_HAIR_EXTENSIONS = 'hair_extensions';

    public const DEPARTMENT_BODY_CARE = 'body_care';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    public static function departmentLabels(): array
    {
        return [
            self::DEPARTMENT_HAIR_EXTENSIONS => 'Hair Extensions',
            self::DEPARTMENT_BODY_CARE => 'Body Care',
        ];
    }

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'tags' => 'array',
            'options' => 'array',
            'variant_count' => 'integer',
            'media_count' => 'integer',
            'min_price_pence' => 'integer',
            'max_price_pence' => 'integer',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'source_published_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ShabaReferenceVariant::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ShabaReferenceMedia::class);
    }
}
