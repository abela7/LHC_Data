<?php

namespace Tests\Feature;

use App\Models\PdfCataloguePage;
use App\Models\PdfCatalogueProduct;
use App\Services\SherrysPdfCatalogueImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfCatalogueImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_extracted_pdf_pages_into_staging_tables(): void
    {
        /** @var SherrysPdfCatalogueImporter $importer */
        $importer = app(SherrysPdfCatalogueImporter::class);

        $summary = $importer->importExtractedPages(
            pages: [
                [
                    'page_number' => 3,
                    'header_text' => null,
                    'brand_context' => 'A3',
                    'brand_context_source' => 'code_family',
                    'raw_text' => 'A3L01 - LEMON FACE GEL 50G',
                    'products' => [
                        [
                            'sort_order' => 1,
                            'brand' => 'A3',
                            'brand_source' => 'code_family',
                            'product_code' => 'A3L01',
                            'product_name' => 'LEMON FACE GEL 50G',
                            'confidence' => 'A',
                            'confidence_reason' => 'Code-to-name pairing is clear on the page.',
                            'raw_name_text' => 'LEMON FACE GEL 50G',
                        ],
                    ],
                ],
                [
                    'page_number' => 5,
                    'header_text' => null,
                    'brand_context' => "Africa's Best",
                    'brand_context_source' => 'prefix_context',
                    'raw_text' => 'AB01 Braid Sheen Spray 12oz',
                    'products' => [
                        [
                            'sort_order' => 1,
                            'brand' => "Africa's Best",
                            'brand_source' => 'prefix_context',
                            'product_code' => 'AB01',
                            'product_name' => 'Braid Sheen Spray 12oz',
                            'confidence' => 'A',
                            'confidence_reason' => 'Code-to-name pairing is clear on the page.',
                            'raw_name_text' => 'Braid Sheen Spray 12oz',
                        ],
                    ],
                ],
            ],
            sourcePath: '/tmp/sherrys.pdf',
            sourceName: 'sherrys.pdf',
        );

        $this->assertSame(2, $summary['pages_imported']);
        $this->assertSame(2, $summary['products_imported']);
        $this->assertSame(0, $summary['needs_review']);
        $this->assertSame(2, PdfCataloguePage::query()->count());
        $this->assertSame(2, PdfCatalogueProduct::query()->count());

        $this->assertDatabaseHas('pdf_catalogue_products', [
            'source_path' => '/tmp/sherrys.pdf',
            'page_number' => 3,
            'brand' => 'A3',
            'product_code' => 'A3L01',
            'product_name' => 'LEMON FACE GEL 50G',
            'confidence' => 'A',
        ]);

        $this->assertDatabaseHas('pdf_catalogue_products', [
            'source_path' => '/tmp/sherrys.pdf',
            'page_number' => 5,
            'brand' => "Africa's Best",
            'product_code' => 'AB01',
            'product_name' => 'Braid Sheen Spray 12oz',
            'confidence' => 'A',
        ]);
    }

    public function test_reimport_replaces_existing_rows_for_the_same_page(): void
    {
        /** @var SherrysPdfCatalogueImporter $importer */
        $importer = app(SherrysPdfCatalogueImporter::class);

        $importer->importExtractedPages(
            pages: [[
                'page_number' => 3,
                'brand_context' => 'A3',
                'brand_context_source' => 'code_family',
                'raw_text' => 'A3L01 - LEMON FACE GEL 50G',
                'products' => [[
                    'sort_order' => 1,
                    'brand' => 'A3',
                    'brand_source' => 'code_family',
                    'product_code' => 'A3L01',
                    'product_name' => 'LEMON FACE GEL 50G',
                    'confidence' => 'A',
                ]],
            ]],
            sourcePath: '/tmp/sherrys.pdf',
            sourceName: 'sherrys.pdf',
        );

        $importer->importExtractedPages(
            pages: [[
                'page_number' => 3,
                'brand_context' => 'A3',
                'brand_context_source' => 'code_family',
                'raw_text' => 'A3L01 - LEMON FACE GEL 50G',
                'products' => [[
                    'sort_order' => 1,
                    'brand' => 'A3',
                    'brand_source' => 'code_family',
                    'product_code' => 'A3L01',
                    'product_name' => 'LEMON FACE GEL 50GR',
                    'confidence' => 'B',
                ]],
            ]],
            sourcePath: '/tmp/sherrys.pdf',
            sourceName: 'sherrys.pdf',
        );

        $this->assertSame(1, PdfCataloguePage::query()->count());
        $this->assertSame(1, PdfCatalogueProduct::query()->count());
        $this->assertDatabaseMissing('pdf_catalogue_products', [
            'source_path' => '/tmp/sherrys.pdf',
            'page_number' => 3,
            'product_name' => 'LEMON FACE GEL 50G',
        ]);
        $this->assertDatabaseHas('pdf_catalogue_products', [
            'source_path' => '/tmp/sherrys.pdf',
            'page_number' => 3,
            'product_name' => 'LEMON FACE GEL 50GR',
            'confidence' => 'B',
        ]);
    }
}
