<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_product_intakes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_product_family_id')->nullable()->constrained('product_families')->nullOnDelete();
            $table->string('brand_name');
            $table->string('department_name')->nullable();
            $table->string('product_type_name')->nullable();
            $table->string('family_name');
            $table->string('observed_product_name')->nullable();
            $table->json('variant_groups')->nullable();
            $table->json('variant_structure')->nullable();
            $table->json('sku_rows')->nullable();
            $table->decimal('shelf_ticket_price', 10, 2)->nullable();
            $table->string('shelf_location')->nullable();
            $table->longText('visible_text_notes')->nullable();
            $table->string('source_match_status')->default('unmatched');
            $table->string('status')->default('submitted');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['brand_name', 'status']);
            $table->index(['department_name', 'product_type_name']);
            $table->index(['source_match_status', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_product_intakes');
    }
};
