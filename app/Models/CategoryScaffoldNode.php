<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryScaffoldNode extends Model
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

    public function scaffold(): BelongsTo
    {
        return $this->belongsTo(CategoryScaffold::class, 'category_scaffold_id');
    }

    public function axis(): BelongsTo
    {
        return $this->belongsTo(CategoryScaffoldAxis::class, 'category_scaffold_axis_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function brandAssignments(): HasMany
    {
        return $this->hasMany(CategoryScaffoldBrandAssignment::class, 'category_scaffold_node_id')
            ->orderBy('sort_order')
            ->orderBy('canonical_brand_name');
    }
}
