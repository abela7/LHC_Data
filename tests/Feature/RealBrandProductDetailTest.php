<?php

namespace Tests\Feature;

use App\Models\ObservedBrandMapping;
use App\Models\ObservedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealBrandProductDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_a_product_detail_page_with_picture_evidence(): void
    {
        ObservedBrandMapping::query()->create([
            'observed_brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'brand_line' => null,
            'official_source_url' => 'https://asiamnaturally.com/',
            'notes' => 'Direct canonical mapping.',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture001',
            'sort_order' => 1,
            'brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'brand_line' => null,
            'product_name' => 'Classic So Much Moisture Lotion',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture002',
            'sort_order' => 1,
            'brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'brand_line' => null,
            'product_name' => 'Classic So Much Moisture Lotion',
        ]);

        $response = $this->get(route('real-brands.products.show', [
            'brand' => 'As I Am',
            'name' => 'Classic So Much Moisture Lotion',
        ]));

        $response->assertOk();
        $response->assertSee('Classic So Much Moisture Lotion');
        $response->assertSee('picture001');
        $response->assertSee('picture002');
        $response->assertSee(route('shop-photos.show', ['pictureId' => 'picture001']));
        $response->assertSee(route('shop-photos.show', ['pictureId' => 'picture002']));
    }

    public function test_it_serves_a_local_shop_photo_when_present(): void
    {
        $response = $this->get(route('shop-photos.show', ['pictureId' => 'picture001']));

        $response->assertOk();
        $cacheControl = (string) $response->headers->get('cache-control');

        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=3600', $cacheControl);
    }
}
