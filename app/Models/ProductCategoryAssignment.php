<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCategoryAssignment extends Model
{
    protected $guarded = [];

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scaffold(): BelongsTo
    {
        return $this->belongsTo(CategoryScaffold::class, 'category_scaffold_id');
    }

    public function axis(): BelongsTo
    {
        return $this->belongsTo(CategoryScaffoldAxis::class, 'category_scaffold_axis_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(CategoryScaffoldNode::class, 'category_scaffold_node_id');
    }
}
