<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_families', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('brand_catalogue_id')->nullable()->constrained('brand_catalogues')->nullOnDelete();
            $table->foreignId('brand_catalogue_brand_id')->nullable()->constrained('brand_catalogue_brands')->nullOnDelete();
            $table->foreignId('brand_catalogue_line_id')->nullable()->constrained('brand_catalogue_lines')->nullOnDelete();
            $table->foreignId('brand_catalogue_product_type_id')->nullable()->constrained('brand_catalogue_product_types')->nullOnDelete();
            $table->foreignId('brand_catalogue_style_id')->nullable()->unique()->constrained('brand_catalogue_styles')->nullOnDelete();
            $table->string('root_catalogue_name')->nullable();
            $table->string('brand_name');
            $table->string('line_name')->nullable();
            $table->string('product_type_name')->nullable();
            $table->string('family_name');
            $table->string('slug')->unique();
            $table->longText('description')->nullable();
            $table->string('source_url', 2000)->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['brand_name', 'family_name']);
            $table->index('status');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_family_id')->constrained('product_families')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('brand_catalogue_sku_id')->nullable()->unique()->constrained('brand_catalogue_skus')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('sku')->nullable()->unique();
            $table->string('barcode')->nullable()->index();
            $table->string('receipt_name')->nullable();
            $table->text('search_keywords')->nullable();
            $table->longText('description')->nullable();
            $table->string('status')->default('draft');
            $table->boolean('is_pos_active')->default(false);
            $table->boolean('is_ecommerce_active')->default(false);
            $table->boolean('is_inventory_tracked')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_family_id', 'slug']);
            $table->index(['product_family_id', 'status']);
        });

        Schema::create('product_variant_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_family_id')->constrained('product_families')->cascadeOnDelete();
            $table->foreignId('brand_catalogue_variant_id')->nullable()->unique()->constrained('brand_catalogue_variants')->nullOnDelete();
            $table->string('name');
            $table->string('variant_type')->default('text');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_family_id', 'name']);
        });

        Schema::create('product_variant_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_group_id')->constrained('product_variant_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('brand_catalogue_variant_option_id')->nullable()->unique();
            $table->string('label');
            $table->string('value')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_variant_group_id', 'label']);
            $table->foreign('brand_catalogue_variant_option_id', 'pvo_bc_option_fk')
                ->references('id')
                ->on('brand_catalogue_variant_options')
                ->nullOnDelete();
        });

        Schema::create('product_variant_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_group_id')->constrained('product_variant_groups')->cascadeOnDelete();
            $table->foreignId('product_variant_option_id')->constrained('product_variant_options')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'product_variant_group_id'], 'product_variant_values_product_group_unique');
            $table->unique(['product_id', 'product_variant_option_id'], 'product_variant_values_product_option_unique');
        });

        Schema::create('product_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_family_id')->nullable()->constrained('product_families')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('catalogue_image_id')->nullable()->constrained('catalogue_images')->nullOnDelete();
            $table->string('image_role')->default('main');
            $table->string('external_url', 2000)->nullable();
            $table->string('storage_disk')->nullable();
            $table->string('storage_path', 2000)->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_family_id', 'is_primary']);
            $table->index(['product_id', 'is_primary']);
        });

        Schema::create('product_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->decimal('retail_price', 10, 2)->nullable();
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->string('currency', 3)->default('GBP');
            $table->string('tax_class')->default('standard');
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->string('price_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('location_type')->default('shop');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $table->decimal('stock_quantity', 10, 2)->default(0);
            $table->string('shelf_location')->nullable();
            $table->decimal('low_stock_threshold', 10, 2)->nullable();
            $table->decimal('reorder_quantity', 10, 2)->nullable();
            $table->string('supplier')->nullable();
            $table->string('supplier_product_code')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'inventory_location_id'], 'inventory_levels_product_location_unique');
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->string('movement_type');
            $table->decimal('quantity_delta', 10, 2);
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->string('reason')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['product_id', 'occurred_at']);
        });

        Schema::create('product_pos_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->string('receipt_name')->nullable();
            $table->text('quick_search_keywords')->nullable();
            $table->string('pos_category')->nullable();
            $table->boolean('discount_allowed')->default(true);
            $table->boolean('quick_sale_enabled')->default(true);
            $table->string('tax_class')->default('standard');
            $table->timestamps();
        });

        Schema::create('product_ecommerce_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_family_id')->nullable()->constrained('product_families')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->string('profile_level')->default('family');
            $table->string('online_title')->nullable();
            $table->text('short_description')->nullable();
            $table->longText('long_description')->nullable();
            $table->string('seo_slug')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('click_and_collect_enabled')->default(true);
            $table->decimal('shipping_weight', 10, 3)->nullable();
            $table->string('shipping_dimensions')->nullable();
            $table->timestamps();

            $table->index(['product_family_id', 'profile_level'], 'product_ecommerce_family_level_index');
            $table->index(['product_id', 'profile_level'], 'product_ecommerce_product_level_index');
        });

        Schema::create('product_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_family_id')->nullable()->constrained('product_families')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->string('source_type');
            $table->string('source_table')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_url', 2000)->nullable();
            $table->string('confidence', 1)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_table', 'source_id'], 'product_sources_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sources');
        Schema::dropIfExists('product_ecommerce_profiles');
        Schema::dropIfExists('product_pos_profiles');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_levels');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('product_variant_values');
        Schema::dropIfExists('product_variant_options');
        Schema::dropIfExists('product_variant_groups');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_families');
    }
};
