<?php

use App\Models\ObservedProduct;
use App\Support\ObservedProductCategoryResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $resolver = app(ObservedProductCategoryResolver::class);
        $categoryIds = $resolver->ensureMajorCategories()->pluck('id', 'slug');

        ObservedProduct::query()
            ->orderBy('id')
            ->select(['id', 'product_name'])
            ->chunkById(200, function ($rows) use ($resolver, $categoryIds) {
                foreach ($rows as $row) {
                    $categorySlug = $resolver->resolveCategorySlug((string) $row->product_name);
                    $categoryId = $categoryIds->get($categorySlug);

                    DB::table('observed_products')
                        ->where('id', $row->id)
                        ->update(['category_id' => $categoryId]);
                }
            });
    }

    public function down(): void
    {
        // Data correction only. Keep the corrected category assignments in place.
    }
};
