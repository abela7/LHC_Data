<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Deliveroo official brand registry (slug, label, category, search aliases).
 *
 * @phpstan-type BrandConfig array{label: string, slug: string, category: string, aliases: array<int, string>}
 */
final class DeliverooBrands
{
    /**
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return [
            'Hair Colour',
            'Relaxers & Texturizers',
            'Developers & Extensions',
            'Other',
        ];
    }

    /**
     * @return array<int, BrandConfig>
     */
    public static function all(): array
    {
        return [
            [
                'label' => 'Tru Zone',
                'slug' => 'tru-zone',
                'category' => 'Developers & Extensions',
                'aliases' => ['TRU ZONE', 'TRUZONE'],
            ],
            [
                'label' => 'Manic Panic',
                'slug' => 'manic-panic',
                'category' => 'Hair Colour',
                'aliases' => ['MANIC PANIC'],
            ],
            [
                'label' => 'Crazy Color',
                'slug' => 'crazy-color',
                'category' => 'Hair Colour',
                'aliases' => ['CRAZY COLOR'],
            ],
            [
                'label' => 'Kiss Tintation',
                'slug' => 'kiss-tintation',
                'category' => 'Hair Colour',
                'aliases' => ['KISS TINTATION', 'KISS COLORS', 'KISS COLORS & CARE'],
            ],
            [
                'label' => 'Creme of Nature',
                'slug' => 'creme-of-nature',
                'category' => 'Hair Colour',
                'aliases' => ['CREME OF NATURE'],
            ],
            [
                'label' => 'Dark and Lovely',
                'slug' => 'dark-and-lovely',
                'category' => 'Relaxers & Texturizers',
                'aliases' => ['DARK AND LOVELY', 'DARK & LOVELY'],
            ],
            [
                'label' => 'ORS',
                'slug' => 'ors',
                'category' => 'Relaxers & Texturizers',
                'aliases' => ['ORS', 'ORS OLIVE OIL', 'ORS HAIR CARE'],
            ],
            [
                'label' => 'Vatika',
                'slug' => 'vatika',
                'category' => 'Hair Colour',
                'aliases' => ['VATIKA', 'DABUR VATIKA'],
            ],
            [
                'label' => 'A3 Lemon',
                'slug' => 'a3-lemon',
                'category' => 'Other',
                'aliases' => ['A3', 'A3 LEMON'],
            ],
            [
                'label' => "Africa's Best",
                'slug' => 'africas-best',
                'category' => 'Relaxers & Texturizers',
                'aliases' => ["AFRICA'S BEST", 'AFRICAS BEST'],
            ],
            [
                'label' => 'Adore',
                'slug' => 'adore',
                'category' => 'Hair Colour',
                'aliases' => ['ADORE', 'CREATIVE IMAGE ADORE', 'ADORE COLOURS'],
            ],
            [
                'label' => 'Directions',
                'slug' => 'directions',
                'category' => 'Hair Colour',
                'aliases' => ['DIRECTION', 'DIRECTIONS'],
            ],
            [
                'label' => 'Just For Me',
                'slug' => 'just-for-me',
                'category' => 'Relaxers & Texturizers',
                'aliases' => ['JUST FOR ME'],
            ],
            [
                'label' => "Luster's",
                'slug' => 'lusters',
                'category' => 'Relaxers & Texturizers',
                'aliases' => ["LUSTER'S", 'LUSTERS', "LUSTER'S PINK", 'LUSTERS PINK'],
            ],
            [
                'label' => 'Gentle Treatment',
                'slug' => 'gentle-treatment',
                'category' => 'Relaxers & Texturizers',
                'aliases' => ['GENTLE TREATMENT'],
            ],
            [
                'label' => 'X-pression',
                'slug' => 'x-pression',
                'category' => 'Developers & Extensions',
                'aliases' => [
                    'X-PRESSION', 'X-PRESSIONS', 'X-PRESSION PRE STRETCHED', 'X-PRESSIONS PRE STRETCHED',
                    'X-PRESSION BY SENSATIONNEL', 'X-PRESSION BY OUTRE', 'X-PRESSION ULTRA BRAID',
                    'X-PRESSION PRE-STRETCHED', 'X-PRESSION', 'X-PRESSION BRAIDING HAIR', 'X PRESSIONS',
                    'X-PRESSION SYNTHETIC BRAIDING HAIR',
                ],
            ],
            [
                'label' => 'Queeny Cazara',
                'slug' => 'queeny-cazara',
                'category' => 'Relaxers & Texturizers',
                'aliases' => ['QUEENY CAZARA'],
            ],
            [
                'label' => 'African Pride',
                'slug' => 'african-pride',
                'category' => 'Relaxers & Texturizers',
                'aliases' => [
                    'AFRICAN PRIDE', 'AFRICAN PRIDE DREAM KIDS', 'AFRICAN PRIDE OLIVE MIRACLE',
                    'AFRICAN PRIDE SHEA MIRACLE', 'AFRICAN PRIDE SHEA BUTTER',
                ],
            ],
            [
                'label' => 'Soft & Beautiful Botanicals',
                'slug' => 'soft-beautiful-botanicals',
                'category' => 'Relaxers & Texturizers',
                'aliases' => ['SOFT & BEAUTIFUL BOTANICALS', 'SOFT BEAUTIFUL BOTANICALS', 'BOTANICALS'],
            ],
            [
                'label' => 'S-Curl',
                'slug' => 's-curl',
                'category' => 'Relaxers & Texturizers',
                'aliases' => ['S-CURL', 'SCURL', "LUSTER'S S CURL", "LUSTER'S S-CURL", "LUSTER'S SCURL"],
            ],
            [
                'label' => 'Texture My Way',
                'slug' => 'texture-my-way',
                'category' => 'Relaxers & Texturizers',
                'aliases' => ['TEXTURE MY WAY', "MEN'S TEXTURE MY WAY"],
            ],
            [
                'label' => 'Salon Pro',
                'slug' => 'salon-pro',
                'category' => 'Developers & Extensions',
                'aliases' => ['SALON PRO', 'SALON PRO EXCLUSIVE'],
            ],
            [
                'label' => 'Red One',
                'slug' => 'red-one',
                'category' => 'Developers & Extensions',
                'aliases' => ['RED ONE', 'REDONE'],
            ],
            [
                'label' => 'Gummy',
                'slug' => 'gummy',
                'category' => 'Other',
                'aliases' => ['GUMMY', 'GUMMY PROFESSIONAL', 'FONEX GUMMY'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return array_column(self::all(), 'slug');
    }

    /**
     * @return BrandConfig|null
     */
    public static function findBySlug(string $slug): ?array
    {
        foreach (self::all() as $config) {
            if ($config['slug'] === $slug) {
                return $config;
            }
        }

        return null;
    }
}
