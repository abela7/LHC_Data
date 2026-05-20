<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ObservedProductCategoryResolver
{
    /**
     * @var array<int, array{name: string, slug: string, sort_order: int, description: string}>
     */
    private const MAJOR_CATEGORIES = [
        [
            'name' => 'Hair',
            'slug' => 'hair',
            'sort_order' => 10,
            'description' => 'Hair extensions, braids, wigs, weaves, clip-ins, and similar hair pieces.',
        ],
        [
            'name' => 'Body Care',
            'slug' => 'body-care',
            'sort_order' => 20,
            'description' => 'Body care, skin care, and all hair care, styling, and treatment products.',
        ],
        [
            'name' => 'Cosmetics',
            'slug' => 'cosmetics',
            'sort_order' => 30,
            'description' => 'Makeup and cosmetic colour products.',
        ],
    ];

    /**
     * @var array<int, string>
     */
    private const COSMETICS_KEYWORDS = [
        'lipstick',
        'lip gloss',
        'lipgloss',
        'foundation',
        'concealer',
        'powder',
        'blush',
        'eyeshadow',
        'eye shadow',
        'eyeliner',
        'mascara',
        'highlighter',
        'contour',
        'bronzer',
        'primer',
        'palette',
        'nail polish',
        'makeup',
    ];

    /**
     * @var array<int, string>
     */
    private const BODY_CARE_KEYWORDS = [
        'shampoo',
        'conditioner',
        'detangler',
        'leave in',
        'leave-in',
        'spray',
        'sheen',
        'gel',
        'mousse',
        'foam',
        'paste',
        'pomade',
        'styling',
        'relaxer',
        'reconstructor',
        'cream',
        'creme',
        'lotion',
        'oil',
        'butter',
        'soap',
        'facial',
        'face',
        'body',
        'skin',
        'petroleum',
        'jelly',
        'glycerine',
        'micellar',
        'astringent',
        'brightening',
        'moisturizing',
        'moisturiser',
        'moisturizer',
        'cleanser',
        'cleansing',
        'exfoliating',
        'edge tamer',
        'edge control',
        'sleek stick',
        'wax stick',
        'bond',
        'glue',
        'remover',
        'hair color',
        'hair colour',
        'hair dye',
        'powder hair colour',
        'powder hair color',
        'treatment',
        'mask',
        'curl activator',
        'activator',
        'hair mayonnaise',
        'hairdress',
        'polisher',
        'scalp',
    ];

    /**
     * @var array<int, string>
     */
    private const HAIR_EXTENSION_KEYWORDS = [
        'braid',
        'braiding',
        'bulk',
        'weave',
        'wig',
        'clip in',
        'clip-in',
        'extension',
        'extensions',
        'remy',
        'virgin hair',
        'human hair',
        'synthetic hair',
        'pre stretched',
        'pre-stretched',
        'crochet braid',
        'crochet',
        'french curl',
        'jerry braid',
        'afro twist',
        'twist braid',
        'ponytail',
        'closure',
        'frontal',
        'lace wig',
        'lace front',
        'lace closure',
        'lace frontal',
        'body wave',
        'water wave',
        'deep wave',
        'loose wave',
        'bundle hair',
        'bundles',
        'locs',
        'loc',
    ];

    /**
     * @return Collection<string, Category>
     */
    public function ensureMajorCategories(): Collection
    {
        $categories = collect(self::MAJOR_CATEGORIES)->mapWithKeys(function (array $category): array {
            $model = Category::query()->updateOrCreate(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'is_active' => true,
                    'sort_order' => $category['sort_order'],
                ],
            );

            return [$category['slug'] => $model];
        });

        return $categories;
    }

    public function resolveCategoryId(string $productName): ?int
    {
        $categories = $this->ensureMajorCategories();
        $categorySlug = $this->resolveCategorySlug($productName);

        return $categories->get($categorySlug)?->id;
    }

    public function resolveCategorySlug(string $productName): string
    {
        $normalized = Str::of($productName)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/u', ' ')
            ->trim()
            ->value();

        if ($this->containsAnyKeyword($normalized, self::COSMETICS_KEYWORDS)) {
            return 'cosmetics';
        }

        if ($this->containsAnyKeyword($normalized, self::BODY_CARE_KEYWORDS)) {
            return 'body-care';
        }

        if ($this->containsAnyKeyword($normalized, self::HAIR_EXTENSION_KEYWORDS)) {
            return 'hair';
        }

        return 'body-care';
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
}
