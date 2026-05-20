<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ObservedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_the_three_major_categories(): void
    {
        $hairId = Category::query()->where('slug', 'hair')->value('id');
        $bodyCareId = Category::query()->where('slug', 'body-care')->value('id');

        ObservedProduct::query()->create([
            'picture_id' => 'picture401',
            'sort_order' => 1,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'category_id' => $hairId,
            'product_name' => 'Ultra Braid',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture402',
            'sort_order' => 1,
            'brand' => 'Clere',
            'canonical_brand' => 'Clere',
            'brand_line' => null,
            'category_id' => $bodyCareId,
            'product_name' => 'Pure Glycerine',
        ]);

        $response = $this->get(route('categories.index'));

        $response->assertOk();
        $response->assertSee('Hair');
        $response->assertSee('Body Care');
        $response->assertSee('Cosmetics');
        $response->assertSee('Top-Level Product Groups');
    }

    public function test_it_shows_products_when_opening_a_category(): void
    {
        $hair = Category::query()->where('slug', 'hair')->firstOrFail();
        $bodyCareId = Category::query()->where('slug', 'body-care')->value('id');

        ObservedProduct::query()->create([
            'picture_id' => 'picture501',
            'sort_order' => 1,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'category_id' => $hair->id,
            'product_name' => 'Ultra Braid',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture502',
            'sort_order' => 1,
            'brand' => 'Sensationnel',
            'canonical_brand' => 'Sensationnel',
            'brand_line' => null,
            'category_id' => $hair->id,
            'product_name' => "Soft N' Silky Afro Natural Syn Afro Twist Braid",
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture503',
            'sort_order' => 1,
            'brand' => 'Clere',
            'canonical_brand' => 'Clere',
            'brand_line' => null,
            'category_id' => $bodyCareId,
            'product_name' => 'Pure Glycerine',
        ]);

        $response = $this->get(route('categories.show', ['category' => $hair->slug]));

        $response->assertOk();
        $response->assertSee('Category Detail');
        $response->assertSeeText('Ultra Braid');
        $response->assertSeeText("Soft N' Silky Afro Natural Syn Afro Twist Braid");
        $response->assertSeeText('X-Pression');
        $response->assertDontSeeText('Pure Glycerine');
    }

    public function test_it_shows_real_brands_when_opening_a_category_brand_page(): void
    {
        $hair = Category::query()->where('slug', 'hair')->firstOrFail();
        $bodyCareId = Category::query()->where('slug', 'body-care')->value('id');

        ObservedProduct::query()->create([
            'picture_id' => 'picture601',
            'sort_order' => 1,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'category_id' => $hair->id,
            'product_name' => 'Ultra Braid',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture602',
            'sort_order' => 1,
            'brand' => 'Sensationnel',
            'canonical_brand' => 'Sensationnel',
            'brand_line' => null,
            'category_id' => $hair->id,
            'product_name' => "Soft N' Silky Afro Natural Syn Afro Twist Braid",
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture603',
            'sort_order' => 1,
            'brand' => 'Clere',
            'canonical_brand' => 'Clere',
            'brand_line' => null,
            'category_id' => $bodyCareId,
            'product_name' => 'Pure Glycerine',
        ]);

        $response = $this->get(route('categories.brands', ['category' => $hair->slug]));

        $response->assertOk();
        $response->assertSeeText('Category Brands');
        $response->assertSeeText('X-Pression');
        $response->assertSeeText('Sensationnel');
        $response->assertDontSeeText('Clere');
    }
}
