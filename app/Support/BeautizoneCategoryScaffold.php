<?php

namespace App\Support;

class BeautizoneCategoryScaffold
{
    /**
     * @return array<int, array{name: string, note: string, children: array<int, string>}>
     */
    public static function catalogueCategories(): array
    {
        return [
            [
                'name' => 'Hair Care',
                'note' => 'Core hair-care taxonomy for shampoos, styling, treatments, relaxers, and dye.',
                'children' => [
                    'Shampoo',
                    'Conditioner',
                    'Moisturisers',
                    'Treatments / Masques',
                    'Relaxer / Texturizers',
                    'Dye / Peroxides',
                    'Serum / Oils',
                    'Styling',
                ],
            ],
            [
                'name' => 'Skin Care',
                'note' => 'Body and face care scaffold used for creams, lotions, cleansing, treatment, and lip-care products.',
                'children' => [
                    'Hand & Body Lotions',
                    'Hand & Body Creams',
                    'Face Moisturisers',
                    'Exfoliators / Toners',
                    'Mouth / Lip Care',
                    'Bath / Soaps',
                    'Body Oils',
                    'Nail Care',
                    'Lip Pencils',
                ],
            ],
            [
                'name' => 'Hair Extensions',
                'note' => 'Hair-piece and extension structure for synthetic, human hair, braids, crochet, clip-ins, and ponytails.',
                'children' => [
                    'Synthetic Hair Weave',
                    'Human Hair Weave',
                    'Braids / Plaiting Hair',
                    'Crochet Hair',
                    'Half Wigs / Instant Weave',
                    'Clip-in Hair Extensions',
                    'Pony Tails',
                ],
            ],
            [
                'name' => 'Accessories',
                'note' => 'Non-consumable product accessories used around hair, wigs, and salon workflows.',
                'children' => [
                    'Hair Accessories',
                    'Hair Brushes & Combs',
                    'Durags & Caps',
                    'Hair Bonnets',
                    'Rubber Bands',
                    'Salon Accessories',
                    'Wig Accessories',
                ],
            ],
            [
                'name' => 'Electrical',
                'note' => 'Electrical tools and supporting accessories.',
                'children' => [
                    'Clippers & Trimmers',
                    'Hair Dryers',
                    'Curling Tongs',
                    'Electrical Accessories',
                ],
            ],
            [
                'name' => 'Fragrances',
                'note' => 'Fragrance pages shown on Beautizone and useful as a separate catalogue branch.',
                'children' => [
                    'Women Perfume',
                    'Men Perfume',
                    'Unisex Perfume',
                    'Air Fresheners',
                ],
            ],
            [
                'name' => 'Makeup',
                'note' => 'Visible as a live Beautizone collection page; currently lip-led and should stay separate from skin care.',
                'children' => [
                    'Lip Makeup',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{name: string, note: string, children: array<int, string>}>
     */
    public static function departmentBuckets(): array
    {
        return [
            [
                'name' => 'Kids',
                'note' => 'Audience bucket. Keep separate from core taxonomy, then place products under the right product category if you later normalize more deeply.',
                'children' => [
                    'Kids Shampoo',
                    'Kids Conditioner',
                    'Kids Relaxer / Texturizer',
                    'Kids Skin Care',
                    'Kids Styling',
                    'Kids Accessories',
                    'Kids Hair Extensions',
                ],
            ],
            [
                'name' => 'Mens',
                'note' => 'Audience bucket used for men-specific grooming and hair-care products.',
                'children' => [
                    'Men Hair Care',
                    'Men Grooming',
                    'Men Hair Dye',
                    'Men Accessories',
                    'Shaving',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{name: string, type: string, note: string}>
     */
    public static function nonTaxonomyCollections(): array
    {
        return [
            [
                'name' => 'Bundles',
                'type' => 'Merchandising',
                'note' => 'Treat as a selling collection, not as a catalogue category.',
            ],
            [
                'name' => 'Sale',
                'type' => 'Merchandising',
                'note' => 'Promotional state only, not category structure.',
            ],
            [
                'name' => 'New Arrivals',
                'type' => 'Merchandising',
                'note' => 'Freshness collection only, not category structure.',
            ],
            [
                'name' => 'A - Z Brands',
                'type' => 'Brand Index',
                'note' => 'Brand navigation, not a product category.',
            ],
        ];
    }

    /**
     * @return array<int, array<title: string, note: string}>
     */
    public static function landingRules(): array
    {
        return [
            [
                'title' => 'Category is the landing tree',
                'note' => 'Each product should land under one scaffold branch such as Hair Care > Styling or Hair Extensions > Crochet Hair.',
            ],
            [
                'title' => 'Brand stays separate',
                'note' => 'Do not turn brands into categories. Keep brand as its own registry and filter layer.',
            ],
            [
                'title' => 'Product type stays separate',
                'note' => 'Type words like oil, shampoo, cream, braid, weave, or clip-in should help classification, but they should not replace the category tree.',
            ],
            [
                'title' => 'Collections stay out of taxonomy',
                'note' => 'Bundles, sale pages, and new-arrival pages should stay as collections or tags, not as core categories.',
            ],
        ];
    }

    /**
     * @return array<int, array<label: string, url: string}>
     */
    public static function sources(): array
    {
        return [
            [
                'label' => 'Beautizone homepage',
                'url' => 'https://beautizone.co.uk/',
            ],
            [
                'label' => 'Beautizone collections',
                'url' => 'https://beautizone.co.uk/collections',
            ],
            [
                'label' => 'Beautizone makeup collection',
                'url' => 'https://beautizone.co.uk/collections/make-up',
            ],
        ];
    }

    /**
     * @return array{roots: int, children: int, departments: int, department_children: int, excluded: int}
     */
    public static function stats(): array
    {
        $catalogueCategories = self::catalogueCategories();
        $departments = self::departmentBuckets();

        return [
            'roots' => count($catalogueCategories),
            'children' => array_sum(array_map(fn (array $node) => count($node['children']), $catalogueCategories)),
            'departments' => count($departments),
            'department_children' => array_sum(array_map(fn (array $node) => count($node['children']), $departments)),
            'excluded' => count(self::nonTaxonomyCollections()),
        ];
    }
}
