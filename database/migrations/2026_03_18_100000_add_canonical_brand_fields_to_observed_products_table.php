<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observed_products', function (Blueprint $table) {
            $table->string('canonical_brand')->default('')->after('brand');
            $table->string('brand_line')->nullable()->after('canonical_brand');
        });

        $mappings = [
            'Fair & White' => ['canonical_brand' => 'Fair & White Paris', 'brand_line' => null],
            'Fair and White' => ['canonical_brand' => 'Fair & White Paris', 'brand_line' => null],
            'F&W Paris' => ['canonical_brand' => 'Fair & White Paris', 'brand_line' => null],
            'F&W Fair and White' => ['canonical_brand' => 'Fair & White Paris', 'brand_line' => null],
            'Fantasia' => ['canonical_brand' => 'Fantasia', 'brand_line' => null],
            'Fantasia IC' => ['canonical_brand' => 'Fantasia', 'brand_line' => 'IC'],
            'Fantasia Naturals' => ['canonical_brand' => 'Fantasia', 'brand_line' => 'Naturals'],
            'SoftSheen-Carson' => ['canonical_brand' => 'SoftSheen-Carson', 'brand_line' => null],
            'SoftSheen-Carson Optimum Care' => ['canonical_brand' => 'SoftSheen-Carson', 'brand_line' => 'Optimum Care'],
            "Africa's Best" => ['canonical_brand' => "Africa's Best", 'brand_line' => null],
            "Originals by Africa's Best" => ['canonical_brand' => "Originals by Africa's Best", 'brand_line' => null],
            'Sleek' => ['canonical_brand' => 'Sleek Hair', 'brand_line' => null],
            'Sleek Hair' => ['canonical_brand' => 'Sleek Hair', 'brand_line' => null],
            'Fashion Idol Express by Sleek' => ['canonical_brand' => 'Sleek Hair', 'brand_line' => 'Fashion Idol Express'],
        ];

        DB::table('observed_products')
            ->orderBy('id')
            ->chunkById(250, function ($rows) use ($mappings) {
                foreach ($rows as $row) {
                    $brand = trim((string) $row->brand);
                    $mapping = $mappings[$brand] ?? [
                        'canonical_brand' => $brand,
                        'brand_line' => null,
                    ];

                    DB::table('observed_products')
                        ->where('id', $row->id)
                        ->update([
                            'canonical_brand' => $mapping['canonical_brand'],
                            'brand_line' => $mapping['brand_line'],
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('observed_products', function (Blueprint $table) {
            $table->dropColumn(['canonical_brand', 'brand_line']);
        });
    }
};
