<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryScaffoldBrandAssignment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function scaffold(): BelongsTo
    {
        return $this->belongsTo(CategoryScaffold::class, 'category_scaffold_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(CategoryScaffoldNode::class, 'category_scaffold_node_id');
    }
}
