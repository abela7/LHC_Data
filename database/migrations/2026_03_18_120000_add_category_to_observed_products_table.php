<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observed_products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('brand_line')->constrained('categories')->nullOnDelete();
        });

        $timestamp = now();

        $categories = [
            ['name' => 'Hair', 'slug' => 'hair', 'sort_order' => 10],
            ['name' => 'Body Care', 'slug' => 'body-care', 'sort_order' => 20],
            ['name' => 'Cosmetics', 'slug' => 'cosmetics', 'sort_order' => 30],
        ];

        foreach ($categories as $category) {
            DB::table('categories')->updateOrInsert(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'description' => null,
                    'is_active' => true,
                    'sort_order' => $category['sort_order'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
            );
        }

        $categoryIds = DB::table('categories')
            ->whereIn('slug', ['hair', 'body-care', 'cosmetics'])
            ->pluck('id', 'slug');

        DB::table('observed_products')
            ->orderBy('id')
            ->select(['id', 'product_name'])
            ->chunkById(200, function ($rows) use ($categoryIds) {
                foreach ($rows as $row) {
                    $categorySlug = $this->resolveCategorySlug((string) $row->product_name);
                    $categoryId = $categoryIds[$categorySlug] ?? null;

                    DB::table('observed_products')
                        ->where('id', $row->id)
                        ->update(['category_id' => $categoryId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('observed_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }

    private function resolveCategorySlug(string $productName): string
    {
        $normalized = Str::of($productName)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/u', ' ')
            ->trim()
            ->value();

        $cosmeticsKeywords = [
            'lipstick', 'lip gloss', 'lipgloss', 'foundation', 'concealer', 'powder', 'blush',
            'eyeshadow', 'eye shadow', 'eyeliner', 'mascara', 'highlighter', 'contour',
            'bronzer', 'primer', 'palette', 'nail polish', 'makeup',
        ];

        $hairKeywords = [
            'hair', 'braid', 'wig', 'weave', 'remy', 'clip in', 'bulk', 'curl', 'coil',
            'edge', 'relaxer', 'shampoo', 'conditioner', 'detangler', 'mousse', 'gel',
            'spritz', 'styling', 'sleek stick', 'braid sheen', 'leave in', 'hair color',
            'hair colour', 'scalp', 'perm', 'twist', 'extension', 'lace', 'bond',
        ];

        $bodyCareKeywords = [
            'body', 'lotion', 'soap', 'petroleum', 'jelly', 'glycerine', 'facial', 'face',
            'skin', 'micellar', 'astringent', 'brightening', 'moisturizing', 'moisturiser',
            'moisturizer', 'cleanser', 'cleansing water', 'exfoliating', 'cream',
        ];

        if ($this->containsAnyKeyword($normalized, $cosmeticsKeywords)) {
            return 'cosmetics';
        }

        if ($this->containsAnyKeyword($normalized, $hairKeywords)) {
            return 'hair';
        }

        if ($this->containsAnyKeyword($normalized, $bodyCareKeywords)) {
            return 'body-care';
        }

        return 'hair';
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function containsAnyKeyword(string $normalizedValue, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($normalizedValue, $keyword)) {
                return true;
            }
        }

        return false;
    }
};
