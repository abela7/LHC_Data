<?php

namespace Tests\Feature;

use App\Models\ObservedProduct;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDuplicateGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_duplicate_rows_cannot_exist_in_observed_products(): void
    {
        ObservedProduct::query()->create([
            'picture_id' => 'picture900',
            'sort_order' => 1,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'product_name' => 'Ultra Braid',
        ]);

        $this->expectException(QueryException::class);

        ObservedProduct::query()->create([
            'picture_id' => 'picture900',
            'sort_order' => 2,
            'brand' => 'X-Pression',
            'canonical_brand' => 'X-Pression',
            'brand_line' => null,
            'product_name' => 'Ultra Braid',
        ]);
    }
}
