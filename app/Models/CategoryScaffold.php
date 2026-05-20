<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryScaffold extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(CategoryScaffoldNode::class)->orderBy('sort_order')->orderBy('name');
    }

    public function axes(): HasMany
    {
        return $this->hasMany(CategoryScaffoldAxis::class)->orderBy('sort_order')->orderBy('name');
    }

    public function brandAssignments(): HasMany
    {
        return $this->hasMany(CategoryScaffoldBrandAssignment::class)->orderBy('sort_order')->orderBy('canonical_brand_name');
    }
}
