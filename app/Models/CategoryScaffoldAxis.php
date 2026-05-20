<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryScaffoldAxis extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scaffold(): BelongsTo
    {
        return $this->belongsTo(CategoryScaffold::class, 'category_scaffold_id');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(CategoryScaffoldNode::class, 'category_scaffold_axis_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
