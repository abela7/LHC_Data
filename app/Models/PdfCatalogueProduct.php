<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PdfCatalogueProduct extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'sort_order' => 'integer',
            'needs_review' => 'boolean',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(PdfCataloguePage::class, 'pdf_catalogue_page_id');
    }

    public function images(): MorphMany
    {
        return $this->morphMany(CatalogueImage::class, 'imageable')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
