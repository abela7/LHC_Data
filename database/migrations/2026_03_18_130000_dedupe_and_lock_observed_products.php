<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateIds = DB::table('observed_products')
            ->selectRaw('id, ROW_NUMBER() OVER (PARTITION BY picture_id, brand, product_name ORDER BY id) as duplicate_rank')
            ->get()
            ->filter(fn ($row) => (int) $row->duplicate_rank > 1)
            ->pluck('id')
            ->all();

        if ($duplicateIds !== []) {
            DB::table('observed_products')->whereIn('id', $duplicateIds)->delete();
        }

        Schema::table('observed_products', function (Blueprint $table) {
            $table->unique(['picture_id', 'brand', 'product_name'], 'observed_products_picture_brand_product_unique');
        });
    }

    public function down(): void
    {
        Schema::table('observed_products', function (Blueprint $table) {
            $table->dropUnique('observed_products_picture_brand_product_unique');
        });
    }
};
