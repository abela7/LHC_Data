<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ObservedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PictureManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_pictures_with_product_counts_and_product_names(): void
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
            'picture_id' => 'picture001',
            'sort_order' => 2,
            'brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'brand_line' => null,
            'product_name' => 'Classic Leave-In Conditioner',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture002',
            'sort_order' => 1,
            'brand' => 'Yari',
            'canonical_brand' => 'Yari',
            'brand_line' => null,
            'product_name' => '100% Natural Argan Oil',
        ]);

        $response = $this->get(route('pictures.index'));

        $response->assertOk();
        $response->assertSee('Picture Browser');
        $response->assertSee('picture001');
        $response->assertSee('picture002');
        $response->assertSee('Classic So Much Moisture Lotion');
        $response->assertSee('Classic Leave-In Conditioner');
        $response->assertSee('100% Natural Argan Oil');
        $response->assertSee('As I Am');
        $response->assertSee('Yari');
        $response->assertSee(route('shop-photos.show', ['pictureId' => 'picture001']));
        $response->assertSee(route('real-brands.show', ['brand' => 'As I Am']));
        $response->assertSee(route('real-brands.products.show', ['brand' => 'As I Am', 'name' => 'Classic So Much Moisture Lotion']));
        $response->assertSee(route('pictures.show', ['pictureId' => 'picture001']));
        $response->assertSee('data-picture-preview-trigger', false);
        $response->assertSee('data-picture-preview-modal', false);
        $response->assertSee('2 products');
    }

    public function test_it_shows_a_picture_review_page_with_editable_rows(): void
    {
        $hairId = Category::query()->where('slug', 'hair')->value('id');

        ObservedProduct::query()->create([
            'picture_id' => 'picture301',
            'sort_order' => 1,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'category_id' => $hairId,
            'product_name' => 'Ultra Braid',
        ]);

        $response = $this->get(route('pictures.show', ['pictureId' => 'picture301']));

        $response->assertOk();
        $response->assertSee('Picture Review');
        $response->assertSee('Ultra Braid');
        $response->assertSee('Observed brand');
        $response->assertSee('Real brand');
        $response->assertSee('Category');
        $response->assertSee(route('shop-photos.show', ['pictureId' => 'picture301']));
    }

    public function test_it_updates_an_observed_row_from_picture_review(): void
    {
        $hairId = Category::query()->where('slug', 'hair')->value('id');
        $bodyCareId = Category::query()->where('slug', 'body-care')->value('id');

        $row = ObservedProduct::query()->create([
            'picture_id' => 'picture302',
            'sort_order' => 1,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'category_id' => $hairId,
            'product_name' => 'Ultra Braid',
        ]);

        $response = $this->patch(route('observed-products.update', ['observedProduct' => $row]), [
            'brand' => 'X-Pression',
            'canonical_brand' => 'New Brand Bucket',
            'brand_line' => 'Special Line',
            'product_name' => 'Ultra Braid Corrected',
            'category_id' => $bodyCareId,
            'return_to' => route('pictures.show', ['pictureId' => 'picture302']),
            'editing_row_id' => $row->id,
        ]);

        $response->assertRedirect(route('pictures.show', ['pictureId' => 'picture302']));
        $this->assertDatabaseHas('observed_products', [
            'id' => $row->id,
            'brand' => 'X-Pression',
            'canonical_brand' => 'New Brand Bucket',
            'brand_line' => 'Special Line',
            'product_name' => 'Ultra Braid Corrected',
            'category_id' => $bodyCareId,
        ]);
    }

    public function test_it_can_filter_pictures_by_real_brand(): void
    {
        ObservedProduct::query()->create([
            'picture_id' => 'picture101',
            'sort_order' => 1,
            'brand' => 'As I Am',
            'canonical_brand' => 'As I Am',
            'brand_line' => null,
            'product_name' => 'Classic So Much Moisture Lotion',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture102',
            'sort_order' => 1,
            'brand' => 'Yari',
            'canonical_brand' => 'Yari',
            'brand_line' => null,
            'product_name' => '100% Natural Argan Oil',
        ]);

        $response = $this->get(route('pictures.index', ['brand' => 'As I Am']));

        $response->assertOk();
        $response->assertSee('picture101');
        $response->assertDontSee('picture102');
        $response->assertSee('Classic So Much Moisture Lotion');
        $response->assertDontSee('100% Natural Argan Oil');
        $response->assertSee('As I Am');
        $response->assertSee('1 pictures on this result set');
    }

    public function test_it_can_filter_pictures_by_category(): void
    {
        $hairId = Category::query()->where('slug', 'hair')->value('id');
        $bodyCareId = Category::query()->where('slug', 'body-care')->value('id');

        ObservedProduct::query()->create([
            'picture_id' => 'picture201',
            'sort_order' => 1,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'category_id' => $hairId,
            'product_name' => 'Ultra Braid',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture202',
            'sort_order' => 1,
            'brand' => 'Clere',
            'canonical_brand' => 'Clere',
            'brand_line' => null,
            'category_id' => $bodyCareId,
            'product_name' => 'Pure Glycerine',
        ]);

        $response = $this->get(route('pictures.index', ['category' => 'hair']));

        $response->assertOk();
        $response->assertSee('picture201');
        $response->assertDontSee('picture202');
        $response->assertSee('Ultra Braid');
        $response->assertDontSee('Pure Glycerine');
        $response->assertSee('X-Pression');
        $response->assertSee('1 pictures on this result set');
    }

    public function test_it_paginates_by_distinct_picture_ids_not_raw_rows(): void
    {
        foreach (range(1, 25) as $index) {
            ObservedProduct::query()->create([
                'picture_id' => 'picture010',
                'sort_order' => $index,
                'brand' => 'As I Am',
                'canonical_brand' => 'As I Am',
                'brand_line' => null,
                'product_name' => 'Product '.$index,
            ]);

            ObservedProduct::query()->create([
                'picture_id' => 'picture011',
                'sort_order' => $index,
                'brand' => 'Yari',
                'canonical_brand' => 'Yari',
                'brand_line' => null,
                'product_name' => 'Oil '.$index,
            ]);
        }

        $response = $this->get(route('pictures.index'));

        $response->assertOk();
        $response->assertSee('2 pictures on this result set');
        $response->assertDontSee('50 pictures on this result set');
    }

    public function test_it_can_filter_pictures_by_picture_range(): void
    {
        ObservedProduct::query()->create([
            'picture_id' => 'picture380',
            'sort_order' => 1,
            'brand' => 'Old Brand',
            'canonical_brand' => 'Old Brand',
            'brand_line' => null,
            'product_name' => 'Old Product',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture381',
            'sort_order' => 1,
            'brand' => 'New Brand',
            'canonical_brand' => 'New Brand',
            'brand_line' => null,
            'product_name' => 'New Product One',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture459',
            'sort_order' => 1,
            'brand' => 'Newest Brand',
            'canonical_brand' => 'Newest Brand',
            'brand_line' => null,
            'product_name' => 'New Product Two',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture460',
            'sort_order' => 1,
            'brand' => 'Future Brand',
            'canonical_brand' => 'Future Brand',
            'brand_line' => null,
            'product_name' => 'Future Product',
        ]);

        $response = $this->get(route('pictures.index', [
            'picture_from' => '381',
            'picture_to' => '459',
        ]));

        $response->assertOk();
        $response->assertSee('picture381');
        $response->assertSee('picture459');
        $response->assertDontSee('picture380');
        $response->assertDontSee('picture460');
        $response->assertSee('New Product One');
        $response->assertSee('New Product Two');
        $response->assertDontSee('Old Product');
        $response->assertDontSee('Future Product');
    }
}
