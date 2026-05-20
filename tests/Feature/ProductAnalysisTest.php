<?php

namespace Tests\Feature;

use App\Models\ObservedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_filter_product_analysis_by_picture_range(): void
    {
        ObservedProduct::query()->create([
            'picture_id' => 'picture380',
            'sort_order' => 1,
            'brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'brand_line' => null,
            'product_name' => 'Classic Coconut CoWash',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture381',
            'sort_order' => 1,
            'brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'brand_line' => null,
            'product_name' => 'Classic Coconut Cowash',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture382',
            'sort_order' => 1,
            'brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'brand_line' => null,
            'product_name' => 'Classic Coconut Co Wash',
        ]);

        $response = $this->get(route('products.analysis', [
            'min_similarity' => 60,
            'picture_from' => '381',
            'picture_to' => '459',
        ]));

        $response->assertOk();
        $response->assertSee('Range picture381 to picture459');
        $response->assertSee('Classic Coconut Cowash');
        $response->assertSee('Classic Coconut Co Wash');
        $response->assertDontSee('Classic Coconut CoWash');
    }

    public function test_delete_selected_redirect_keeps_picture_range(): void
    {
        $row = ObservedProduct::query()->create([
            'picture_id' => 'picture381',
            'sort_order' => 1,
            'brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'brand_line' => null,
            'product_name' => 'Classic Coconut Cowash',
        ]);

        $response = $this->post(route('products.delete-selected'), [
            'product_ids' => [$row->id],
            'min_similarity' => 60,
            'picture_from' => '381',
            'picture_to' => '459',
        ]);

        $response->assertRedirect(route('products.analysis', [
            'min_similarity' => 60,
            'picture_from' => '381',
            'picture_to' => '459',
        ]));
    }
}
