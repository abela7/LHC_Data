<?php

namespace Tests\Feature;

use App\Models\PdfCataloguePage;
use App\Models\PdfCatalogueProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfCatalogueBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_imported_pdf_products_and_filters_by_confidence(): void
    {
        $page = PdfCataloguePage::query()->create([
            'source_name' => 'sherrys.pdf',
            'source_path' => '/tmp/sherrys.pdf',
            'page_number' => 3,
            'brand_context' => 'A3',
            'brand_context_source' => 'code_family',
            'raw_text' => 'A3L01 - LEMON FACE GEL 50G',
            'products_count' => 2,
            'needs_review' => true,
        ]);

        PdfCatalogueProduct::query()->create([
            'pdf_catalogue_page_id' => $page->id,
            'source_name' => 'sherrys.pdf',
            'source_path' => '/tmp/sherrys.pdf',
            'page_number' => 3,
            'sort_order' => 1,
            'brand' => 'A3',
            'brand_source' => 'code_family',
            'product_code' => 'A3L01',
            'product_name' => 'LEMON FACE GEL 50G',
            'confidence' => 'A',
            'confidence_reason' => 'Clear.',
            'needs_review' => false,
        ]);

        PdfCatalogueProduct::query()->create([
            'pdf_catalogue_page_id' => $page->id,
            'source_name' => 'sherrys.pdf',
            'source_path' => '/tmp/sherrys.pdf',
            'page_number' => 3,
            'sort_order' => 2,
            'brand' => 'A3',
            'brand_source' => 'code_family',
            'product_code' => 'A3L02',
            'product_name' => 'LEMON FACE CLEANSER 260ML',
            'confidence' => 'D',
            'confidence_reason' => 'Needs review.',
            'needs_review' => true,
        ]);

        $this->get('/pdf-products')
            ->assertOk()
            ->assertSee('LEMON FACE GEL 50G')
            ->assertSee('LEMON FACE CLEANSER 260ML');

        $this->get('/pdf-products?confidence=D')
            ->assertOk()
            ->assertDontSee('LEMON FACE GEL 50G')
            ->assertSee('LEMON FACE CLEANSER 260ML');

        $this->get('/pdf-products/pages/'.$page->id)
            ->assertOk()
            ->assertSee('Page 3')
            ->assertSee('LEMON FACE GEL 50G')
            ->assertSee('LEMON FACE CLEANSER 260ML');
    }
}
