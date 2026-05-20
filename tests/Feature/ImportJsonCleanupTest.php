<?php

namespace Tests\Feature;

use App\Models\CatalogueFamily;
use App\Models\Category;
use App\Models\CatalogueSource;
use App\Models\CatalogueVariant;
use App\Models\ImportBatch;
use App\Models\ImportRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportJsonCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_messy_but_salvageable_ai_json(): void
    {
        Category::query()->create([
            'name' => 'Hair',
            'slug' => 'hair',
            'description' => 'Seeded for import test.',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $payload = "\xEF\xBB\xBF```json\r\n{\r\n  \"brand\": \"X-Pression\",\r\n  \"category\": \"Hair\",\r\n  \"product_family_name\": \"Ultra\u{200B} Braid Stretched\",\r\n  \"description\": \"Line 1\nLine 2\",\r\n  \"variants\": [\r\n    {\r\n      \"variant_display_name\": \"20 inch / Color 1\",\r\n      \"length\": \"20 inch\",\r\n      \"attributes_json\": {\r\n        \"fiber\": \"synthetic\"\r\n      }\r\n    }\r\n  ]\r\n}\r\n```";

        $response = $this->post('/imports', [
            'source_label' => 'Messy AI import',
            'json_payload' => $payload,
        ]);

        $response->assertRedirect('/review');
        $response->assertSessionHas('status');

        $family = CatalogueFamily::query()->first();

        $this->assertNotNull($family);
        $this->assertSame('Ultra Braid Stretched', $family->product_family_name);
        $this->assertSame("Line 1\nLine 2", $family->full_description);
        $this->assertSame(1, $family->variants()->count());
    }

    public function test_it_returns_a_readable_error_and_cleaned_preview_when_json_is_still_invalid(): void
    {
        $payload = "\xEF\xBB\xBF```json\r\n{\r\n  \"brand\": \"X-Pression\",\r\n  \"product_family_name\": \"Ultra Braid Stretched\",\r\n  \"description\": \"Line 1\nLine 2\"\r\n\r\n```";

        $response = $this->followingRedirects()->post('/imports', [
            'source_label' => 'Broken AI import',
            'json_payload' => $payload,
        ]);

        $response->assertSee('The JSON could not be decoded even after auto-cleaning', false);
        $response->assertSee('Auto-cleaned JSON preview', false);
        $response->assertSee('Removed Markdown code fences around the JSON payload.', false);
    }

    public function test_it_imports_product_finder_nested_json_without_manual_reshaping(): void
    {
        Category::query()->create([
            'name' => 'Hair',
            'slug' => 'hair',
            'description' => 'Seeded for import test.',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $payload = <<<'JSON'
{
  "import_batch_note": "external product finder result",
  "family": {
    "brand_name": "EBIN NEW YORK",
    "category_name": "Hair",
    "subcategory_name": "Hair Styling",
    "product_family_name": "24 Hour Edge Tamer Sleek Hair Wax Stick",
    "product_type": "hair wax stick",
    "short_description": "Extreme-hold hair wax stick for taming edges and flyaways with scented variant options.",
    "full_description": "Portable styling wax stick.",
    "status": "needs_review",
    "finder_confidence": "A",
    "finder_confidence_reason": "Strong match from official and retailer sources.",
    "notes": "Product Finder family note."
  },
  "types": [],
  "variants": [
    {
      "type_name": null,
      "variant_display_name": "Mango",
      "attributes_json": {
        "scent": "Mango"
      },
      "status": "needs_review",
      "finder_confidence": "A",
      "finder_confidence_reason": "Confirmed in photo and retailer research.",
      "notes": "Variant note."
    }
  ],
  "sources": [
    {
      "target_level": "family",
      "target_ref": "family",
      "role": "primary",
      "source_type": "official_brand",
      "trust_status": "verified",
      "url": "https://www.ebinnewyork.com/collections/wax",
      "is_primary": true,
      "is_verified": true,
      "confidence": "A",
      "notes": "Official family source."
    },
    {
      "target_level": "variant",
      "target_ref": "Mango",
      "role": "supporting",
      "source_type": "retailer",
      "trust_status": "verified",
      "url": "https://www.pakcosmetics.com/mango.html",
      "is_primary": false,
      "is_verified": true,
      "confidence": "A",
      "notes": "Retailer variant source."
    }
  ],
  "images": [
    {
      "target_level": "variant",
      "target_ref": "Mango",
      "image_role": "source_image",
      "external_url": "https://www.pakcosmetics.com/mango.jpg",
      "notes": "Variant image."
    }
  ],
  "shop_match": {
    "target_level": "family",
    "target_ref": "family",
    "shop_match_status": "unknown",
    "confirmation_method": null,
    "notes": "To be reviewed against local store stock"
  }
}
JSON;

        $response = $this->post('/imports', [
            'source_label' => 'Product Finder import',
            'json_payload' => $payload,
        ]);

        $response->assertRedirect('/review');

        $family = CatalogueFamily::query()->first();
        $variant = CatalogueVariant::query()->first();

        $this->assertNotNull($family);
        $this->assertSame('24 Hour Edge Tamer Sleek Hair Wax Stick', $family->product_family_name);
        $this->assertSame('needs_review', $family->status);
        $this->assertStringContainsString('Product type: hair wax stick', $family->notes);
        $this->assertNotNull($variant);
        $this->assertSame('Mango', $variant->variant_display_name);
        $this->assertSame('needs_review', $variant->status);
        $this->assertSame('Mango', $variant->attributes_json['scent']);
        $this->assertSame(1, CatalogueSource::query()->whereMorphedTo('sourceable', $family)->count());
        $this->assertSame(1, CatalogueSource::query()->whereMorphedTo('sourceable', $variant)->count());
        $this->assertSame(1, $variant->images()->count());
    }

    public function test_it_imports_simple_vision_picture_json_as_step_one_drafts(): void
    {
        $payload = <<<'JSON'
{
  "picture_id": "picture001",
  "products": [
    {
      "brand": "X-Pression",
      "product_name": "Ultra Braid Stretched"
    },
    {
      "brand": "EBIN NEW YORK",
      "product_name": "Wonder Lace Bond Lace Melt Spray",
      "confidence": "A",
      "confidence_reason": "Front label is clearly readable."
    }
  ]
}
JSON;

        $response = $this->post('/imports', [
            'source_label' => 'Vision scan import',
            'json_payload' => $payload,
        ]);

        $response->assertRedirect('/review');

        $this->assertSame(2, CatalogueFamily::query()->count());
        $this->assertSame(2, ImportRecord::query()->count());

        $xpression = CatalogueFamily::query()->where('product_family_name', 'Ultra Braid Stretched')->first();
        $ebin = CatalogueFamily::query()->where('product_family_name', 'Wonder Lace Bond Lace Melt Spray')->first();

        $this->assertNotNull($xpression);
        $this->assertNotNull($ebin);
        $this->assertSame('X-Pression', $xpression->brand?->name);
        $this->assertSame('imported', $xpression->status);
        $this->assertStringContainsString('picture001', $xpression->notes ?? '');
        $this->assertSame('EBIN NEW YORK', $ebin->brand?->name);
        $this->assertSame('imported', $ebin->status);
        $this->assertSame('0.95', (string) $ebin->import_confidence);

        $records = ImportRecord::query()->orderBy('id')->get();
        $this->assertSame('picture001:1', $records[0]->external_reference);
        $this->assertSame('picture001:2', $records[1]->external_reference);
        $this->assertSame('staged', $records[0]->status);
        $this->assertSame('staged', $records[1]->status);
    }

    public function test_it_auto_uses_picture_id_as_source_label_when_blank(): void
    {
        $payload = <<<'JSON'
{
  "picture_id": "picture001",
  "products": [
    {
      "brand": "X-Pression",
      "product_name": "Ultra Braid Stretched"
    }
  ]
}
JSON;

        $response = $this->post('/imports', [
            'json_payload' => $payload,
        ]);

        $response->assertRedirect('/review');

        $batch = ImportBatch::query()->latest()->first();

        $this->assertNotNull($batch);
        $this->assertSame('picture001', $batch->source_label);
    }
}
