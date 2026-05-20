<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ObservedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_ai_research_export_options(): void
    {
        $response = $this->get(route('exports.index'));

        $response->assertOk();
        $response->assertSeeText('AI research handoff');
        $response->assertSeeText('Download XLSX');
        $response->assertSeeText('Download CSV');
    }

    public function test_it_downloads_the_catalogue_ai_input_csv(): void
    {
        $hairId = Category::query()->where('slug', 'hair')->value('id');

        ObservedProduct::query()->create([
            'picture_id' => 'picture701',
            'sort_order' => 1,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'category_id' => $hairId,
            'product_name' => 'Ultra Braid',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture702',
            'sort_order' => 1,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'category_id' => $hairId,
            'product_name' => 'Ultra Braid',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture703',
            'sort_order' => 1,
            'brand' => 'Clere',
            'canonical_brand' => 'Clere',
            'brand_line' => null,
            'category_id' => null,
            'product_name' => 'Pure Glycerine',
        ]);

        $response = $this->get(route('exports.catalogue-ai-input.csv'));

        $response->assertOk();
        $response->assertDownload('catalogue-ai-input.csv');

        $content = file_get_contents($response->baseResponse->getFile()->getPathname());
        $rows = array_map('str_getcsv', array_filter(preg_split("/\r\n|\n|\r/", trim((string) $content))));

        $this->assertStringContainsString('product_id,category,name,brand', $content);
        $this->assertContains(['PRD-C9D9B10A1779', 'Hair', 'Ultra Braid', 'X-Pression'], $rows);
        $this->assertContains(['PRD-6FBBC26E089A', 'Unassigned', 'Pure Glycerine', 'Clere'], $rows);
    }

    public function test_it_downloads_the_catalogue_ai_input_xlsx(): void
    {
        ObservedProduct::query()->create([
            'picture_id' => 'picture704',
            'sort_order' => 1,
            'brand' => 'A3 Lemon',
            'canonical_brand' => 'A3 Lemon',
            'brand_line' => null,
            'category_id' => null,
            'product_name' => 'Lightening Serum',
        ]);

        $response = $this->get(route('exports.catalogue-ai-input.xlsx'));

        $response->assertOk();
        $response->assertDownload('catalogue-ai-input.xlsx');
    }

    public function test_it_can_scope_the_ai_export_to_a_picture_range(): void
    {
        $hairId = Category::query()->where('slug', 'hair')->value('id');

        ObservedProduct::query()->create([
            'picture_id' => 'picture380',
            'sort_order' => 1,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'category_id' => $hairId,
            'product_name' => 'Legacy Product',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture381',
            'sort_order' => 1,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'category_id' => $hairId,
            'product_name' => 'Batch Product One',
        ]);

        ObservedProduct::query()->create([
            'picture_id' => 'picture459',
            'sort_order' => 1,
            'brand' => 'Clere',
            'canonical_brand' => 'Clere',
            'brand_line' => null,
            'category_id' => null,
            'product_name' => 'Batch Product Two',
        ]);

        $indexResponse = $this->get(route('exports.index', [
            'picture_from' => '381',
            'picture_to' => '459',
        ]));

        $indexResponse->assertOk();
        $indexResponse->assertSeeText('2 grouped products ready for external AI review');
        $indexResponse->assertSeeText('2 raw rows from');
        $indexResponse->assertSeeText('2 pictures become');
        $indexResponse->assertSeeText('2 grouped products.');
        $indexResponse->assertSee('value="picture381"', false);
        $indexResponse->assertSee('value="picture459"', false);

        $csvResponse = $this->get(route('exports.catalogue-ai-input.csv', [
            'picture_from' => '381',
            'picture_to' => '459',
        ]));

        $csvResponse->assertOk();
        $csvResponse->assertDownload('catalogue-ai-input-picture381-picture459.csv');

        $content = file_get_contents($csvResponse->baseResponse->getFile()->getPathname());

        $this->assertStringContainsString('Batch Product One', $content);
        $this->assertStringContainsString('Batch Product Two', $content);
        $this->assertStringNotContainsString('Legacy Product', $content);
    }
}
