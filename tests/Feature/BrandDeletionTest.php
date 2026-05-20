<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CatalogueFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_brand_unassigns_linked_families_and_returns_to_review_queue(): void
    {
        $brand = Brand::query()->create([
            'name' => 'Temporary Brand',
            'slug' => 'temporary-brand',
            'is_active' => true,
            'is_generic' => false,
        ]);

        $family = CatalogueFamily::query()->create([
            'brand_id' => $brand->id,
            'product_family_name' => 'Temporary Family',
            'slug' => 'temporary-family',
            'status' => 'needs_review',
            'needs_source_verification' => true,
            'duplicate_flag' => false,
        ]);

        $response = $this->delete(route('brands.destroy', $brand));

        $response->assertRedirect(route('review.index'));
        $response->assertSessionHas('status');
        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
        $this->assertDatabaseHas('catalogue_families', [
            'id' => $family->id,
            'brand_id' => null,
        ]);
    }
}
