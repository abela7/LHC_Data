<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ObservedProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservedProductImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_one_row_per_product_from_picture_json(): void
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
      "product_name": "Wonder Lace Bond Lace Melt Spray"
    }
  ]
}
JSON;

        $response = $this->post('/observed-products', [
            'json_payload' => $payload,
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('status');

        $this->assertSame(2, ObservedProduct::query()->count());
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture001',
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'product_name' => 'Ultra Braid Stretched',
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture001',
            'brand' => 'EBIN NEW YORK',
            'canonical_brand' => 'EBIN NEW YORK',
            'brand_line' => null,
            'product_name' => 'Wonder Lace Bond Lace Melt Spray',
            'sort_order' => 2,
        ]);
    }

    public function test_it_assigns_major_categories_on_import(): void
    {
        $payload = <<<'JSON'
{
  "picture_id": "picture900",
  "products": [
    {
      "brand": "X-Pression",
      "product_name": "Ultra Braid Stretched"
    },
    {
      "brand": "Fair & White",
      "product_name": "Lait Aloe Vera Brightening & Moisturizing Body Lotion"
    },
    {
      "brand": "African Essence",
      "product_name": "Control Wig Shampoo"
    },
    {
      "brand": "Beauty Works",
      "product_name": "Foundation Stick"
    }
  ]
}
JSON;

        $this->post('/observed-products', [
            'json_payload' => $payload,
        ])->assertRedirect('/');

        $hairId = Category::query()->where('slug', 'hair')->value('id');
        $bodyCareId = Category::query()->where('slug', 'body-care')->value('id');
        $cosmeticsId = Category::query()->where('slug', 'cosmetics')->value('id');

        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture900',
            'product_name' => 'Ultra Braid Stretched',
            'category_id' => $hairId,
        ]);
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture900',
            'product_name' => 'Lait Aloe Vera Brightening & Moisturizing Body Lotion',
            'category_id' => $bodyCareId,
        ]);
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture900',
            'product_name' => 'Control Wig Shampoo',
            'category_id' => $bodyCareId,
        ]);
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture900',
            'product_name' => 'Foundation Stick',
            'category_id' => $cosmeticsId,
        ]);
    }

    public function test_it_treats_hair_care_and_styling_items_as_body_care(): void
    {
        $payload = <<<'JSON'
{
  "picture_id": "picture901",
  "products": [
    {
      "brand": "African Essence",
      "product_name": "Braid Sheen Spray"
    },
    {
      "brand": "Ebin New York",
      "product_name": "Wonder Lace Bond Lace Melt Spray"
    },
    {
      "brand": "X-Pression",
      "product_name": "Ultra Braid Stretched"
    }
  ]
}
JSON;

        $this->post('/observed-products', [
            'json_payload' => $payload,
        ])->assertRedirect('/');

        $hairId = Category::query()->where('slug', 'hair')->value('id');
        $bodyCareId = Category::query()->where('slug', 'body-care')->value('id');

        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture901',
            'product_name' => 'Braid Sheen Spray',
            'category_id' => $bodyCareId,
        ]);
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture901',
            'product_name' => 'Wonder Lace Bond Lace Melt Spray',
            'category_id' => $bodyCareId,
        ]);
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture901',
            'product_name' => 'Ultra Braid Stretched',
            'category_id' => $hairId,
        ]);
    }

    public function test_it_rejects_more_than_ten_products_in_one_json(): void
    {
        $products = [];

        for ($i = 1; $i <= 11; $i++) {
            $products[] = [
                'brand' => 'Brand '.$i,
                'product_name' => 'Product '.$i,
            ];
        }

        $payload = json_encode([
            'picture_id' => 'picture001',
            'products' => $products,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $response = $this->from('/')->post('/observed-products', [
            'json_payload' => $payload,
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors(['photos.0.products']);
        $this->assertSame(0, ObservedProduct::query()->count());
    }

    public function test_it_imports_multiple_picture_objects_from_one_json_array(): void
    {
        $payload = <<<'JSON'
[
  {
    "picture_id": "picture002",
    "products": [
      {
        "brand": "X-Pression",
        "product_name": "Ultra Braid"
      }
    ]
  },
  {
    "picture_id": "picture003",
    "products": [
      {
        "brand": "X-Pression",
        "product_name": "Ultra Braid Pre-Stretched 6x52\""
      }
    ]
  },
  {
    "picture_id": "picture006",
    "products": [
      {
        "brand": "Sensationnel",
        "product_name": "Soft N' Silky Afro Natural Syn Afro Twist Braid"
      }
    ]
  }
]
JSON;

        $response = $this->post('/observed-products', [
            'json_payload' => $payload,
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('status');

        $this->assertSame(3, ObservedProduct::query()->count());
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture002',
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'product_name' => 'Ultra Braid',
        ]);
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture003',
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'product_name' => 'Ultra Braid Pre-Stretched 6x52"',
        ]);
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture006',
            'brand' => 'Sensationnel',
            'canonical_brand' => 'Sensationnel',
            'product_name' => "Soft N' Silky Afro Natural Syn Afro Twist Braid",
        ]);
    }

    public function test_it_allows_blank_brand_when_product_name_is_present(): void
    {
        $payload = <<<'JSON'
[
  {
    "picture_id": "picture254",
    "products": [
      {
        "brand": "",
        "product_name": "Wax Stick Hair Shine and Smooth"
      }
    ]
  }
]
JSON;

        $response = $this->post('/observed-products', [
            'json_payload' => $payload,
        ]);

        $response->assertRedirect('/');
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture254',
            'brand' => '',
            'canonical_brand' => '',
            'product_name' => 'Wax Stick Hair Shine and Smooth',
        ]);
    }

    public function test_it_applies_brand_verdict_mappings_on_import(): void
    {
        $payload = <<<'JSON'
[
  {
    "picture_id": "picture500",
    "products": [
      {
        "brand": "F&W Paris",
        "product_name": "Mix Ready 2 Glow Brightening Face Cream"
      },
      {
        "brand": "Fantasia IC",
        "product_name": "Hair Polisher Heat Protector Styling Foam"
      },
      {
        "brand": "Fashion Idol Express by Sleek",
        "product_name": "French Curl Braid 28\""
      }
    ]
  }
]
JSON;

        $this->post('/observed-products', [
            'json_payload' => $payload,
        ])->assertRedirect('/');

        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture500',
            'brand' => 'F&W Paris',
            'canonical_brand' => 'Fair & White Paris',
            'brand_line' => null,
            'product_name' => 'Mix Ready 2 Glow Brightening Face Cream',
        ]);
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture500',
            'brand' => 'Fantasia IC',
            'canonical_brand' => 'Fantasia',
            'brand_line' => 'IC',
            'product_name' => 'Hair Polisher Heat Protector Styling Foam',
        ]);
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture500',
            'brand' => 'Fashion Idol Express by Sleek',
            'canonical_brand' => 'Sleek Hair',
            'brand_line' => 'Fashion Idol Express',
            'product_name' => 'French Curl Braid 28"',
        ]);
    }

    public function test_it_skips_rows_that_were_already_imported_without_blocking_new_rows(): void
    {
        $payload = <<<'JSON'
[
  {
    "picture_id": "picture251",
    "products": [
      {
        "brand": "African Essence",
        "product_name": "Control Wig Shampoo"
      },
      {
        "brand": "African Essence",
        "product_name": "Braid Sheen Spray"
      }
    ]
  }
]
JSON;

        $firstResponse = $this->post('/observed-products', [
            'json_payload' => $payload,
        ]);

        $firstResponse->assertRedirect('/');
        $this->assertSame(2, ObservedProduct::query()->count());

        $secondPayload = <<<'JSON'
[
  {
    "picture_id": "picture251",
    "products": [
      {
        "brand": "African Essence",
        "product_name": "Control Wig Shampoo"
      },
      {
        "brand": "African Essence",
        "product_name": "Braid Sheen Spray"
      },
      {
        "brand": "African Essence",
        "product_name": "Weave Spray 6 in 1"
      }
    ]
  }
]
JSON;

        $secondResponse = $this->from('/')->post('/observed-products', [
            'json_payload' => $secondPayload,
        ]);

        $secondResponse->assertRedirect('/');
        $secondResponse->assertSessionHas('status');
        $secondResponse->assertSessionHas('warning');
        $this->assertSame(3, ObservedProduct::query()->count());
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture251',
            'brand' => 'African Essence',
            'product_name' => 'Weave Spray 6 in 1',
        ]);
    }

    public function test_duplicate_skip_requires_exact_brand_and_product_name_match(): void
    {
        $firstPayload = <<<'JSON'
[
  {
    "picture_id": "picture300",
    "products": [
      {
        "brand": "Fair & White",
        "product_name": "Lait Aloe Vera Brightening & Moisturizing Body Lotion"
      }
    ]
  }
]
JSON;

        $secondPayload = <<<'JSON'
[
  {
    "picture_id": "picture300",
    "products": [
      {
        "brand": "FAIR & WHITE",
        "product_name": "Lait Aloe Vera Brightening & Moisturizing Body Lotion"
      }
    ]
  }
]
JSON;

        $this->post('/observed-products', [
            'json_payload' => $firstPayload,
        ])->assertRedirect('/');

        $this->post('/observed-products', [
            'json_payload' => $secondPayload,
        ])->assertRedirect('/');

        $this->assertSame(2, ObservedProduct::query()->count());
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture300',
            'brand' => 'Fair & White',
            'product_name' => 'Lait Aloe Vera Brightening & Moisturizing Body Lotion',
        ]);
        $this->assertDatabaseHas('observed_products', [
            'picture_id' => 'picture300',
            'brand' => 'FAIR & WHITE',
            'product_name' => 'Lait Aloe Vera Brightening & Moisturizing Body Lotion',
        ]);
    }
}
