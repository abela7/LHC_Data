<?php

namespace App\Support;

class ObservedBrandVerdict
{
    /**
     * @return array<string, array{canonical_brand: string, brand_line: ?string, official_source_url: ?string, notes: ?string}>
     */
    public static function defaults(): array
    {
        return [
            'Fair & White' => [
                'canonical_brand' => 'Fair & White Paris',
                'brand_line' => null,
                'official_source_url' => 'https://eu.fwparis.com/en/',
                'notes' => 'Official Fair & White Paris site uses F&W ranges under the same brand.',
            ],
            'Fair and White' => [
                'canonical_brand' => 'Fair & White Paris',
                'brand_line' => null,
                'official_source_url' => 'https://eu.fwparis.com/en/',
                'notes' => 'Normalized to official Fair & White Paris brand wording.',
            ],
            'F&W Paris' => [
                'canonical_brand' => 'Fair & White Paris',
                'brand_line' => null,
                'official_source_url' => 'https://eu.fwparis.com/en/',
                'notes' => 'Observed F&W wording points to the official Fair & White Paris brand.',
            ],
            'F&W Fair and White' => [
                'canonical_brand' => 'Fair & White Paris',
                'brand_line' => null,
                'official_source_url' => 'https://eu.fwparis.com/en/',
                'notes' => 'Observed F&W wording points to the official Fair & White Paris brand.',
            ],
            'Fantasia' => [
                'canonical_brand' => 'Fantasia',
                'brand_line' => null,
                'official_source_url' => 'https://fantasiahaircare.com/',
                'notes' => 'Official Fantasia site is the umbrella brand site.',
            ],
            'Fantasia IC' => [
                'canonical_brand' => 'Fantasia',
                'brand_line' => 'IC',
                'official_source_url' => 'https://fantasiahaircare.com/',
                'notes' => 'IC appears as a Fantasia line on the official Fantasia site.',
            ],
            'Fantasia Naturals' => [
                'canonical_brand' => 'Fantasia',
                'brand_line' => 'Naturals',
                'official_source_url' => 'https://fantasiahaircare.com/',
                'notes' => 'Naturals appears as a Fantasia line on the official Fantasia site.',
            ],
            'SoftSheen-Carson' => [
                'canonical_brand' => 'SoftSheen-Carson',
                'brand_line' => null,
                'official_source_url' => 'https://www.softsheen-carson.com/',
                'notes' => 'Official SoftSheen-Carson site is the umbrella brand site.',
            ],
            'SoftSheen-Carson Optimum Care' => [
                'canonical_brand' => 'SoftSheen-Carson',
                'brand_line' => 'Optimum Care',
                'official_source_url' => 'https://www.softsheen-carson.com/',
                'notes' => 'Optimum Care is treated as a SoftSheen-Carson line.',
            ],
            "Africa's Best" => [
                'canonical_brand' => "Africa's Best",
                'brand_line' => null,
                'official_source_url' => 'https://africasbesthair.com/',
                'notes' => 'Keep separate from Originals by Africa\'s Best.',
            ],
            "Originals by Africa's Best" => [
                'canonical_brand' => "Originals by Africa's Best",
                'brand_line' => null,
                'official_source_url' => 'https://originalsbyafricasbest.com/',
                'notes' => 'Official site is separate from Africa\'s Best.',
            ],
            'Sleek' => [
                'canonical_brand' => 'Sleek Hair',
                'brand_line' => null,
                'official_source_url' => 'https://www.sleek.co.uk/',
                'notes' => 'Normalized to the official Sleek Hair brand wording.',
            ],
            'Sleek Hair' => [
                'canonical_brand' => 'Sleek Hair',
                'brand_line' => null,
                'official_source_url' => 'https://www.sleek.co.uk/',
                'notes' => 'Official Sleek Hair brand wording.',
            ],
            'Fashion Idol Express by Sleek' => [
                'canonical_brand' => 'Sleek Hair',
                'brand_line' => 'Fashion Idol Express',
                'official_source_url' => 'https://www.sleek.co.uk/',
                'notes' => 'Fashion Idol Express appears as a Sleek Hair line on the official site.',
            ],
        ];
    }

    /**
     * @return array{canonical_brand: string, brand_line: ?string, official_source_url: ?string, notes: ?string}
     */
    public static function resolve(?string $observedBrand): array
    {
        $observedBrand = trim((string) $observedBrand);

        if ($observedBrand === '') {
            return [
                'canonical_brand' => '',
                'brand_line' => null,
                'official_source_url' => null,
                'notes' => null,
            ];
        }

        return self::defaults()[$observedBrand] ?? [
            'canonical_brand' => $observedBrand,
            'brand_line' => null,
            'official_source_url' => null,
            'notes' => null,
        ];
    }
}
