<?php

namespace Tests\Feature;

use App\Models\ObservedBrandMapping;
use App\Models\ObservedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealBrandBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_real_brands_in_grid_view_with_counts(): void
    {
        ObservedProduct::query()->create([
            'picture_id' => 'picture601',
            'sort_order' => 1,
            'brand' => 'Fantasia IC',
            'canonical_brand' => 'Fantasia',
            'brand_line' => 'IC',
            'product_name' => 'Hair Polisher Heat Protector Styling Foam',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture602',
            'sort_order' => 1,
            'brand' => 'Fantasia',
            'canonical_brand' => 'Fantasia',
            'brand_line' => null,
            'product_name' => 'Liquid Mousse Super Hold Spritz Hairspray',
        ]);

        $response = $this->get('/real-brands');

        $response->assertOk();
        $response->assertSee('Fantasia');
        $response->assertSee('2 products');
        $response->assertSee('2 pictures');
        $response->assertSee('2 observed labels');
    }

    public function test_it_supports_list_view(): void
    {
        ObservedBrandMapping::query()->create([
            'observed_brand' => 'Fair & White',
            'canonical_brand' => 'Fair & White Paris',
            'brand_line' => null,
            'official_source_url' => 'https://eu.fwparis.com/',
            'notes' => 'Official EU site.',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture603',
            'sort_order' => 1,
            'brand' => 'Fair & White',
            'canonical_brand' => 'Fair & White Paris',
            'brand_line' => null,
            'product_name' => 'Gold Ultimate 1 Radiance Argan Lotion',
        ]);

        $response = $this->get('/real-brands?view=list');

        $response->assertOk();
        $response->assertSee('Fair & White Paris');
        $response->assertSee('Open');
        $response->assertSee('Official site');
        $response->assertSee('https://eu.fwparis.com/');
    }

    public function test_it_shows_products_and_picture_ids_for_a_real_brand(): void
    {
        ObservedBrandMapping::query()->create([
            'observed_brand' => 'Sleek',
            'canonical_brand' => 'Sleek Hair',
            'brand_line' => null,
            'official_source_url' => 'https://www.sleek.co.uk/',
            'notes' => 'Official Sleek Hair wording.',
        ]);

        ObservedBrandMapping::query()->create([
            'observed_brand' => 'Fashion Idol Express by Sleek',
            'canonical_brand' => 'Sleek Hair',
            'brand_line' => 'Fashion Idol Express',
            'official_source_url' => 'https://www.sleek.co.uk/',
            'notes' => 'Line under Sleek Hair.',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture604',
            'sort_order' => 1,
            'brand' => 'Sleek',
            'canonical_brand' => 'Sleek Hair',
            'brand_line' => null,
            'product_name' => 'Style Icon Virgin Remy 100% Remy Hair 18"',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture605',
            'sort_order' => 1,
            'brand' => 'Fashion Idol Express by Sleek',
            'canonical_brand' => 'Sleek Hair',
            'brand_line' => 'Fashion Idol Express',
            'product_name' => 'French Curl Braid 28"',
        ]);

        $response = $this->get(route('real-brands.show', ['brand' => 'Sleek Hair']));

        $response->assertOk();
        $response->assertSee('Sleek Hair');
        $response->assertSee('Style Icon Virgin Remy 100% Remy Hair 18&quot;', false);
        $response->assertSee('French Curl Braid 28&quot;', false);
        $response->assertSee('picture604');
        $response->assertSee('picture605');
        $response->assertSee('Fashion Idol Express');
    }

    public function test_it_renders_a_brand_level_picture_carousel_trigger(): void
    {
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
            'product_name' => 'Classic Leave-In Conditioner',
        ]);

        $response = $this->get(route('real-brands.show', ['brand' => 'As I Am']));

        $response->assertOk();
        $response->assertSee('data-brand-carousel-trigger', false);
        $response->assertSee(route('shop-photos.show', ['pictureId' => 'picture001']));
        $response->assertSee(route('shop-photos.show', ['pictureId' => 'picture002']));
        $response->assertSee('Brand photo evidence');
    }

    public function test_it_supports_list_view_on_a_real_brand_detail_page(): void
    {
        ObservedProduct::query()->create([
            'picture_id' => 'picture710',
            'sort_order' => 1,
            'brand' => 'Ebin New York',
            'canonical_brand' => 'Ebin New York',
            'brand_line' => null,
            'product_name' => '24 Hour Edge Tamer Hair Sleek Stick Mango',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture711',
            'sort_order' => 1,
            'brand' => 'Ebin New York',
            'canonical_brand' => 'Ebin New York',
            'brand_line' => null,
            'product_name' => '24 Hour Braid Sheen',
        ]);

        $response = $this->get(route('real-brands.show', ['brand' => 'Ebin New York', 'view' => 'list']));

        $response->assertOk();
        $response->assertSee('Grid');
        $response->assertSee('List');
        $response->assertSee('24 Hour Edge Tamer Hair Sleek Stick Mango');
        $response->assertSee('24 Hour Braid Sheen');
        $response->assertSee('picture710');
        $response->assertSee('picture711');
        $response->assertSee('data-carousel-index="0"', false);
        $response->assertSee('data-carousel-index="1"', false);
        $response->assertSee('Open');
    }

    public function test_it_can_add_a_new_real_brand_with_an_official_site(): void
    {
        $response = $this->post(route('real-brands.store'), [
            'canonical_brand' => 'New Manual Brand',
            'official_source_url' => 'https://manual-brand.example.com/',
        ]);

        $response->assertRedirect(route('real-brands.show', ['brand' => 'New Manual Brand']));

        $this->assertDatabaseHas('observed_brand_mappings', [
            'observed_brand' => 'New Manual Brand',
            'canonical_brand' => 'New Manual Brand',
            'official_source_url' => 'https://manual-brand.example.com/',
        ]);

        $brandPage = $this->get(route('real-brands.show', ['brand' => 'New Manual Brand']));

        $brandPage->assertOk();
        $brandPage->assertSee('New Manual Brand');
        $brandPage->assertSee('https://manual-brand.example.com/');
    }

    public function test_it_can_update_official_site_for_an_existing_real_brand(): void
    {
        ObservedBrandMapping::query()->create([
            'observed_brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'brand_line' => null,
            'official_source_url' => null,
            'notes' => null,
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture801',
            'sort_order' => 1,
            'brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'brand_line' => null,
            'product_name' => 'Classic So Much Moisture Lotion',
        ]);

        $response = $this->patch(route('real-brands.update', ['brand' => 'As I Am']), [
            'official_source_url' => 'https://asiamnaturally.com/',
        ]);

        $response->assertRedirect(route('real-brands.show', ['brand' => 'As I Am']));

        $this->assertDatabaseHas('observed_brand_mappings', [
            'observed_brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'official_source_url' => 'https://asiamnaturally.com/',
        ]);

        $brandPage = $this->get(route('real-brands.show', ['brand' => 'As I Am']));

        $brandPage->assertOk();
        $brandPage->assertSee('https://asiamnaturally.com/');
        $brandPage->assertSee('Save official site');
    }

    public function test_it_can_filter_real_brands_by_picture_range(): void
    {
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

        ObservedProduct::query()->create([
            'picture_id' => 'picture460',
            'sort_order' => 1,
            'brand' => 'Future Brand',
            'canonical_brand' => 'Future Brand',
            'brand_line' => null,
            'product_name' => 'Future Product',
        ]);

        $response = $this->get(route('real-brands.index', [
            'picture_from' => '381',
            'picture_to' => '459',
        ]));

        $response->assertOk();
        $response->assertSee('Batch Brand');
        $response->assertDontSee('Legacy Brand');
        $response->assertDontSee('Future Brand');
        $response->assertSee('1 real brands');
    }

    public function test_it_can_filter_real_brand_detail_by_picture_range(): void
    {
        ObservedProduct::query()->create([
            'picture_id' => 'picture380',
            'sort_order' => 1,
            'brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'brand_line' => null,
            'product_name' => 'Legacy Product',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture381',
            'sort_order' => 1,
            'brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'brand_line' => null,
            'product_name' => 'Batch Product',
        ]);

        $response = $this->get(route('real-brands.show', [
            'brand' => 'As I Am',
            'picture_from' => '381',
            'picture_to' => '459',
        ]));

        $response->assertOk();
        $response->assertSee('Batch Product');
        $response->assertDontSee('Legacy Product');
        $response->assertSee('picture381');
        $response->assertDontSee('picture380');
    }
}
