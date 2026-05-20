<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shaba_reference_products', function (Blueprint $table): void {
            $table->id();
            $table->string('source_product_id')->unique();
            $table->char('canonical_url_hash', 64)->unique();
            $table->string('canonical_url', 2048);
            $table->string('retailer')->nullable();
            $table->string('brand');
            $table->string('normalized_brand')->index();
            $table->string('title');
            $table->string('normalized_title')->index();
            $table->longText('description')->nullable();
            $table->string('currency', 3)->default('GBP');
            $table->json('categories')->nullable();
            $table->json('tags')->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('variant_count')->default(0);
            $table->unsignedInteger('media_count')->default(0);
            $table->unsignedInteger('min_price_pence')->nullable();
            $table->unsignedInteger('max_price_pence')->nullable();
            $table->string('stock_status')->nullable();
            $table->string('main_image_url', 2048)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('source_published_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->longText('raw_json')->nullable();
            $table->timestamps();

            $table->index(['brand', 'title']);
            $table->index(['stock_status', 'brand']);
        });

        Schema::create('shaba_reference_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shaba_reference_product_id')->constrained('shaba_reference_products')->cascadeOnDelete();
            $table->string('source_variant_id')->unique();
            $table->string('title');
            $table->string('sku')->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('price_current_pence')->nullable();
            $table->unsignedInteger('price_previous_pence')->nullable();
            $table->string('stock_status')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['shaba_reference_product_id', 'sort_order'], 'shaba_variant_product_sort_idx');
            $table->index(['stock_status', 'price_current_pence'], 'shaba_variant_stock_price_idx');
        });

        Schema::create('shaba_reference_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shaba_reference_product_id')->constrained('shaba_reference_products')->cascadeOnDelete();
            $table->string('source_media_id')->nullable();
            $table->string('type')->default('Image');
            $table->char('url_hash', 64);
            $table->string('url', 2048);
            $table->json('variant_ids')->nullable();
            $table->string('alt')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['shaba_reference_product_id', 'url_hash'], 'shaba_media_product_url_unique');
            $table->index(['shaba_reference_product_id', 'sort_order'], 'shaba_media_product_sort_idx');
            $table->index('source_media_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shaba_reference_media');
        Schema::dropIfExists('shaba_reference_variants');
        Schema::dropIfExists('shaba_reference_products');
    }
};
