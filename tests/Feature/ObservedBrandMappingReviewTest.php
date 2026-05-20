<?php

namespace Tests\Feature;

use App\Models\ObservedBrandMapping;
use App\Models\ObservedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservedBrandMappingReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_observed_brand_mappings(): void
    {
        ObservedBrandMapping::query()->create([
            'observed_brand' => 'Fantasia IC',
            'canonical_brand' => 'Fantasia',
            'brand_line' => 'IC',
            'official_source_url' => 'https://fantasiahaircare.com/',
            'notes' => 'Official Fantasia site shows IC as a line.',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture401',
            'sort_order' => 1,
            'brand' => 'Fantasia IC',
            'canonical_brand' => 'Fantasia',
            'brand_line' => 'IC',
            'product_name' => 'Hair Polisher Heat Protector Styling Foam',
        ]);

        $response = $this->get('/brand-review');

        $response->assertOk();
        $response->assertSee('Fantasia IC');
        $response->assertSee('Fantasia');
        $response->assertSee('1 rows');
        $response->assertSee('1 products');
    }

    public function test_it_updates_mapping_and_syncs_observed_rows(): void
    {
        $mapping = ObservedBrandMapping::query()->create([
            'observed_brand' => 'Sleek',
            'canonical_brand' => 'Sleek Hair',
            'brand_line' => null,
            'official_source_url' => 'https://www.sleek.co.uk/',
            'notes' => 'Initial mapping.',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture402',
            'sort_order' => 1,
            'brand' => 'Sleek',
            'canonical_brand' => 'Sleek Hair',
            'brand_line' => null,
            'product_name' => 'Style Icon Virgin Remy 100% Remy Hair 18"',
        ]);

        $response = $this->patch("/brand-mappings/{$mapping->id}", [
            'canonical_brand' => 'Sleek London',
            'brand_line' => 'Style Icon',
            'official_source_url' => 'https://www.sleek.co.uk/',
            'notes' => 'Updated after review.',
        ]);

        $response->assertRedirect('/brand-review');

        $this->assertDatabaseHas('observed_brand_mappings', [
            'id' => $mapping->id,
            'canonical_brand' => 'Sleek London',
            'brand_line' => 'Style Icon',
        ]);

        $this->assertDatabaseHas('observed_products', [
            'brand' => 'Sleek',
            'canonical_brand' => 'Sleek London',
            'brand_line' => 'Style Icon',
            'product_name' => 'Style Icon Virgin Remy 100% Remy Hair 18"',
        ]);
    }

    public function test_it_can_filter_brand_review_by_picture_range(): void
    {
        ObservedBrandMapping::query()->create([
            'observed_brand' => 'Legacy Brand',
            'canonical_brand' => 'Legacy Brand',
            'brand_line' => null,
            'official_source_url' => null,
            'notes' => null,
        ]);

        ObservedBrandMapping::query()->create([
            'observed_brand' => 'Batch Brand',
            'canonical_brand' => 'Batch Brand',
            'brand_line' => null,
            'official_source_url' => null,
            'notes' => null,
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture380',
            'sort_order' => 1,
            'brand' => 'Legacy Brand',
            'canonical_brand' => 'Legacy Brand',
            'brand_line' => null,
            'product_name' => 'Legacy Product',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture381',
            'sort_order' => 1,
            'brand' => 'Batch Brand',
            'canonical_brand' => 'Batch Brand',
            'brand_line' => null,
            'product_name' => 'Batch Product',
        ]);

        $response = $this->get(route('brand-review.index', [
            'picture_from' => '381',
            'picture_to' => '459',
        ]));

        $response->assertOk();
        $response->assertSee('Batch Brand');
        $response->assertDontSee('Legacy Brand');
        $response->assertSee('Range picture381 to picture459');
    }
}
