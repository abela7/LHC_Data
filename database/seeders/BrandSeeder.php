<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BrandSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $brands = [
            ['name' => 'Generic', 'notes' => 'Default generic brand record for non-branded catalogue entries.'],
            ['name' => 'Unbranded', 'notes' => 'Used where no meaningful brand exists on the product.'],
            ['name' => 'Store Generic', 'notes' => 'Used for internal generic groupings confirmed by the business.'],
        ];

        foreach ($brands as $brand) {
            Brand::updateOrCreate(
                ['slug' => Str::slug($brand['name'])],
                [
                    'name' => $brand['name'],
                    'notes' => $brand['notes'],
                    'is_active' => true,
                    'is_generic' => true,
                ],
            );
        }
    }
}
