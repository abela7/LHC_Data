<?php

namespace Tests\Feature;

use App\Models\DeliverooOfficialProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverooManualProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_renders(): void
    {
        $response = $this->get('/deliveroo-products/create');

        $response->assertOk();
        $response->assertSee('Add product manually', false);
    }

    public function test_families_json_returns_distinct_family_names_for_brand(): void
    {
        DeliverooOfficialProduct::query()->create([
            'brand_label' => 'Adore',
            'brand_slug' => 'adore',
            'family_name' => 'Semi Permanent Hair Dye Colour',
            'variant_name' => null,
            'official_name' => 'Test A',
            'official_url' => 'https://example.com/a',
            'description' => null,
            'image_urls' => null,
            'source_site' => 'test',
            'sort_order' => 10,
        ]);

        DeliverooOfficialProduct::query()->create([
            'brand_label' => 'Adore',
            'brand_slug' => 'adore',
            'family_name' => 'Semi Permanent Hair Dye Colour',
            'variant_name' => null,
            'official_name' => 'Test B',
            'official_url' => 'https://example.com/b',
            'description' => null,
            'image_urls' => null,
            'source_site' => 'test',
            'sort_order' => 20,
        ]);

        $response = $this->getJson('/deliveroo-products/api/families?brand_slug=adore');

        $response->assertOk();
        $response->assertJson([
            'families' => ['Semi Permanent Hair Dye Colour'],
        ]);
    }

    public function test_store_creates_manual_product_with_generated_url(): void
    {
        $response = $this->post('/deliveroo-products', [
            'brand_slug' => 'adore',
            'official_name' => 'Manual Test Product',
            'description' => 'A short description.',
            'variant_name' => '30 TEST',
            'official_url' => '',
            'image_urls' => ['https://example.com/one.jpg', 'https://example.com/two.jpg'],
            'family_link' => 'none',
            'family_existing' => '',
            'family_new' => '',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('deliveroo_official_products', [
            'brand_slug' => 'adore',
            'official_name' => 'Manual Test Product',
            'variant_name' => '30 TEST',
            'source_site' => 'manual-entry',
        ]);

        $row = DeliverooOfficialProduct::query()->where('official_name', 'Manual Test Product')->first();
        $this->assertNotNull($row);
        $this->assertStringStartsWith('manual:lhc:', (string) $row->official_url);
        $this->assertSame(['https://example.com/one.jpg', 'https://example.com/two.jpg'], $row->image_urls);
    }

    public function test_store_links_existing_family(): void
    {
        DeliverooOfficialProduct::query()->create([
            'brand_label' => 'Adore',
            'brand_slug' => 'adore',
            'family_name' => 'Family Line',
            'variant_name' => null,
            'official_name' => 'Existing',
            'official_url' => 'https://example.com/existing',
            'description' => null,
            'image_urls' => null,
            'source_site' => 'test',
            'sort_order' => 10,
        ]);

        $response = $this->post('/deliveroo-products', [
            'brand_slug' => 'adore',
            'official_name' => 'New In Family',
            'description' => null,
            'variant_name' => null,
            'official_url' => '',
            'image_urls' => '',
            'family_link' => 'existing',
            'family_existing' => 'Family Line',
            'family_new' => '',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('deliveroo_official_products', [
            'official_name' => 'New In Family',
            'family_name' => 'Family Line',
        ]);
    }

    public function test_store_rejects_invalid_brand_slug(): void
    {
        $response = $this->post('/deliveroo-products', [
            'brand_slug' => 'not-a-real-brand',
            'official_name' => 'X',
            'family_link' => 'none',
        ]);

        $response->assertSessionHasErrors('brand_slug');
    }

    public function test_store_sets_new_family_name(): void
    {
        $response = $this->post('/deliveroo-products', [
            'brand_slug' => 'directions',
            'official_name' => 'New Family Product',
            'description' => null,
            'variant_name' => null,
            'official_url' => '',
            'image_urls' => '',
            'family_link' => 'new',
            'family_existing' => '',
            'family_new' => 'Custom Family Line',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('deliveroo_official_products', [
            'brand_slug' => 'directions',
            'official_name' => 'New Family Product',
            'family_name' => 'Custom Family Line',
        ]);
    }

    public function test_edit_form_renders(): void
    {
        $product = DeliverooOfficialProduct::query()->create([
            'brand_label' => 'Adore',
            'brand_slug' => 'adore',
            'family_name' => 'Line A',
            'variant_name' => null,
            'official_name' => 'Editable',
            'official_url' => 'manual:lhc:test-edit-uuid',
            'description' => 'Desc',
            'image_urls' => null,
            'source_site' => 'test',
            'sort_order' => 10,
        ]);

        $response = $this->get('/deliveroo-products/official/adore/products/'.$product->id.'/edit');

        $response->assertOk();
        $response->assertSee('Editable', false);
        $response->assertSee('Edit product', false);
    }

    public function test_update_changes_product_and_may_change_brand(): void
    {
        $product = DeliverooOfficialProduct::query()->create([
            'brand_label' => 'Adore',
            'brand_slug' => 'adore',
            'family_name' => 'Line A',
            'variant_name' => 'V1',
            'official_name' => 'Before',
            'official_url' => 'https://example.com/p/before',
            'description' => 'Old',
            'image_urls' => null,
            'source_site' => 'test',
            'sort_order' => 10,
            'price' => 9.99,
        ]);

        DeliverooOfficialProduct::query()->create([
            'brand_label' => 'Directions',
            'brand_slug' => 'directions',
            'family_name' => 'Shared Fam',
            'variant_name' => null,
            'official_name' => 'Other',
            'official_url' => 'https://example.com/p/other',
            'description' => null,
            'image_urls' => null,
            'source_site' => 'test',
            'sort_order' => 10,
        ]);

        $response = $this->patch('/deliveroo-products/official/adore/products/'.$product->id, [
            'brand_slug' => 'directions',
            'official_name' => 'After',
            'description' => 'New text',
            'variant_name' => 'V2',
            'official_url' => 'https://example.com/p/before',
            'image_urls' => ['https://example.com/i.jpg'],
            'family_link' => 'existing',
            'family_existing' => 'Shared Fam',
            'family_new' => '',
        ]);

        $response->assertRedirect(route('deliveroo-products.official-product', ['brand' => 'directions', 'product' => $product->id]));

        $product->refresh();
        $this->assertSame('directions', $product->brand_slug);
        $this->assertSame('After', $product->official_name);
        $this->assertSame('Shared Fam', $product->family_name);
        $this->assertSame(['https://example.com/i.jpg'], $product->image_urls);
        $this->assertEquals(9.99, (float) $product->price);
    }

    public function test_destroy_removes_product(): void
    {
        $product = DeliverooOfficialProduct::query()->create([
            'brand_label' => 'Adore',
            'brand_slug' => 'adore',
            'family_name' => null,
            'variant_name' => null,
            'official_name' => 'To Delete',
            'official_url' => 'https://example.com/p/del',
            'description' => null,
            'image_urls' => null,
            'source_site' => 'test',
            'sort_order' => 10,
        ]);

        $response = $this->delete('/deliveroo-products/official/adore/products/'.$product->id);

        $response->assertRedirect(route('deliveroo-products.official-brand', ['brand' => 'adore']));
        $this->assertDatabaseMissing('deliveroo_official_products', ['id' => $product->id]);
    }

    public function test_store_preserves_image_url_order_from_array(): void
    {
        $response = $this->post('/deliveroo-products', [
            'brand_slug' => 'adore',
            'official_name' => 'Ordered Images',
            'description' => null,
            'variant_name' => null,
            'official_url' => '',
            'image_urls' => [
                'https://example.com/b.jpg',
                'https://example.com/a.jpg',
                'https://example.com/c.jpg',
            ],
            'family_link' => 'none',
            'family_existing' => '',
            'family_new' => '',
        ]);

        $response->assertRedirect();
        $row = DeliverooOfficialProduct::query()->where('official_name', 'Ordered Images')->first();
        $this->assertNotNull($row);
        $this->assertSame(
            ['https://example.com/b.jpg', 'https://example.com/a.jpg', 'https://example.com/c.jpg'],
            $row->image_urls
        );
    }

    public function test_store_rejects_invalid_image_url_in_array(): void
    {
        $response = $this->post('/deliveroo-products', [
            'brand_slug' => 'adore',
            'official_name' => 'Bad Image Row',
            'description' => null,
            'variant_name' => null,
            'official_url' => '',
            'image_urls' => ['https://example.com/ok.jpg', 'not-a-url'],
            'family_link' => 'none',
            'family_existing' => '',
            'family_new' => '',
        ]);

        $response->assertSessionHasErrors('image_urls');
        $this->assertDatabaseMissing('deliveroo_official_products', [
            'official_name' => 'Bad Image Row',
        ]);
    }

    public function test_bulk_delete_removes_products_for_brand(): void
    {
        $one = DeliverooOfficialProduct::query()->create([
            'brand_label' => 'Adore',
            'brand_slug' => 'adore',
            'family_name' => null,
            'variant_name' => null,
            'official_name' => 'Bulk One',
            'official_url' => 'https://example.com/bulk-one',
            'description' => null,
            'image_urls' => null,
            'source_site' => 'test',
            'sort_order' => 10,
        ]);
        $two = DeliverooOfficialProduct::query()->create([
            'brand_label' => 'Adore',
            'brand_slug' => 'adore',
            'family_name' => null,
            'variant_name' => null,
            'official_name' => 'Bulk Two',
            'official_url' => 'https://example.com/bulk-two',
            'description' => null,
            'image_urls' => null,
            'source_site' => 'test',
            'sort_order' => 20,
        ]);

        $response = $this->delete('/deliveroo-products/official/adore/products', [
            'product_ids' => [$one->id, $two->id],
        ]);

        $response->assertRedirect(route('deliveroo-products.official-brand', ['brand' => 'adore']));
        $this->assertDatabaseMissing('deliveroo_official_products', ['id' => $one->id]);
        $this->assertDatabaseMissing('deliveroo_official_products', ['id' => $two->id]);
    }

    public function test_bulk_delete_skips_ids_from_other_brands(): void
    {
        $adore = DeliverooOfficialProduct::query()->create([
            'brand_label' => 'Adore',
            'brand_slug' => 'adore',
            'family_name' => null,
            'variant_name' => null,
            'official_name' => 'Adore Only',
            'official_url' => 'https://example.com/adore-only-bulk',
            'description' => null,
            'image_urls' => null,
            'source_site' => 'test',
            'sort_order' => 10,
        ]);
        $directions = DeliverooOfficialProduct::query()->create([
            'brand_label' => 'Directions',
            'brand_slug' => 'directions',
            'family_name' => null,
            'variant_name' => null,
            'official_name' => 'Directions Keep',
            'official_url' => 'https://example.com/directions-keep-bulk',
            'description' => null,
            'image_urls' => null,
            'source_site' => 'test',
            'sort_order' => 10,
        ]);

        $response = $this->delete('/deliveroo-products/official/adore/products', [
            'product_ids' => [$adore->id, $directions->id],
        ]);

        $response->assertRedirect(route('deliveroo-products.official-brand', ['brand' => 'adore']));
        $this->assertDatabaseMissing('deliveroo_official_products', ['id' => $adore->id]);
        $this->assertDatabaseHas('deliveroo_official_products', ['id' => $directions->id]);
    }

    public function test_official_family_short_url_renders(): void
    {
        DeliverooOfficialProduct::query()->create([
            'brand_label' => 'X-pression',
            'brand_slug' => 'x-pression',
            'family_name' => 'Family Short Url',
            'variant_name' => null,
            'official_name' => 'Sku One',
            'official_url' => 'https://example.com/xp-short-1',
            'description' => null,
            'image_urls' => null,
            'source_site' => 'test',
            'sort_order' => 10,
        ]);

        $token = rtrim(strtr(base64_encode('Family Short Url'), '+/', '-_'), '=');
        $response = $this->get('/x-pression/families/'.$token);

        $response->assertOk();
        $response->assertSee('Sku One', false);
        $response->assertSee('data-deliveroo-brand-catalogue', false);
    }

    public function test_bulk_delete_validates_product_ids(): void
    {
        $response = $this->from('/deliveroo-products/official/adore')->delete('/deliveroo-products/official/adore/products', [
            'product_ids' => [],
        ]);

        $response->assertSessionHasErrors('product_ids');
    }

    public function test_edit_returns_404_when_product_brand_mismatch(): void
    {
        $product = DeliverooOfficialProduct::query()->create([
            'brand_label' => 'Adore',
            'brand_slug' => 'adore',
            'family_name' => null,
            'variant_name' => null,
            'official_name' => 'Wrong',
            'official_url' => 'https://example.com/p/w',
            'description' => null,
            'image_urls' => null,
            'source_site' => 'test',
            'sort_order' => 10,
        ]);

        $response = $this->get('/deliveroo-products/official/directions/products/'.$product->id.'/edit');

        $response->assertNotFound();
    }
}
