<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('catalogue_ai_enrichments', function (Blueprint $table) {
            $table->id();
            $table->string('product_id')->unique();
            $table->unsignedInteger('source_row_number')->nullable()->index();
            $table->string('source_file')->nullable();
            $table->string('category_name')->nullable()->index();
            $table->string('product_name');
            $table->string('brand_name')->nullable()->index();
            $table->string('subcategory_name')->nullable()->index();
            $table->string('has_variant')->nullable()->index();
            $table->string('variant_types')->nullable();
            $table->string('has_product_type')->nullable()->index();
            $table->string('product_type_details')->nullable();
            $table->string('has_bundle')->nullable()->index();
            $table->string('bundle_details')->nullable();
            $table->string('official_site')->nullable()->index();
            $table->text('official_site_url')->nullable();
            $table->text('best_source_url')->nullable();
            $table->char('confidence', 1)->nullable()->index();
            $table->string('confidence_reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('processed')->nullable()->index();
            $table->boolean('needs_review')->default(false)->index();
            $table->string('row_hash', 64)->nullable()->index();
            $table->json('raw_row_json')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalogue_ai_enrichments');
    }
};
