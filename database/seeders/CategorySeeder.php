<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Hair',
                'sort_order' => 10,
                'description' => 'Hair extensions, braids, wigs, weaves, clip-ins, and similar hair pieces.',
            ],
            [
                'name' => 'Body Care',
                'sort_order' => 20,
                'description' => 'Body care, skin care, and all hair care, styling, and treatment products.',
            ],
            [
                'name' => 'Cosmetics',
                'sort_order' => 30,
                'description' => 'Makeup and cosmetic colour products.',
            ],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['slug' => Str::slug($category['name'])],
                [
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'is_active' => true,
                    'sort_order' => $category['sort_order'],
                ],
            );
        }
    }
}
