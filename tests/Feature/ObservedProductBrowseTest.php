<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ObservedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservedProductBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_search_imported_rows(): void
    {
        ObservedProduct::query()->create([
            'picture_id' => 'picture101',
            'sort_order' => 1,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'product_name' => 'Ultra Braid Stretched',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture102',
            'sort_order' => 1,
            'brand' => 'Yari',
            'canonical_brand' => 'Yari',
            'brand_line' => null,
            'product_name' => '100% Natural Argan Oil',
        ]);

        $response = $this->get('/?search=Ultra+Braid');

        $response->assertOk();
        $response->assertSee('Ultra Braid Stretched');
        $response->assertDontSee('100% Natural Argan Oil');
    }

    public function test_it_can_filter_imported_rows_by_brand(): void
    {
        ObservedProduct::query()->create([
            'picture_id' => 'picture201',
            'sort_order' => 1,
            'brand' => 'F&W Paris',
            'canonical_brand' => 'Fair & White Paris',
            'brand_line' => null,
            'product_name' => 'Gold Ultimate 1 Radiance Argan Lotion',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture202',
            'sort_order' => 1,
            'brand' => 'Clere',
            'canonical_brand' => 'Clere',
            'brand_line' => null,
            'product_name' => 'Pure Glycerine',
        ]);

        $response = $this->get('/?brand=Fair%20%26%20White%20Paris');

        $response->assertOk();
        $response->assertSee('Gold Ultimate 1 Radiance Argan Lotion');
        $response->assertDontSee('Pure Glycerine');
    }

    public function test_it_can_filter_imported_rows_by_category(): void
    {
        $hairId = Category::query()->where('slug', 'hair')->value('id');
        $bodyCareId = Category::query()->where('slug', 'body-care')->value('id');

        ObservedProduct::query()->create([
            'picture_id' => 'picture301',
            'sort_order' => 1,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'category_id' => $hairId,
            'product_name' => 'Ultra Braid Stretched',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture302',
            'sort_order' => 1,
            'brand' => 'Clere',
            'canonical_brand' => 'Clere',
            'brand_line' => null,
            'category_id' => $bodyCareId,
            'product_name' => 'Pure Glycerine',
        ]);

        $response = $this->get('/?category=hair');

        $response->assertOk();
        $response->assertSee('Ultra Braid Stretched');
        $response->assertDontSee('Pure Glycerine');
    }
}
