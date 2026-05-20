<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_catalogue_pages', function (Blueprint $table) {
            $table->id();
            $table->string('source_name');
            $table->string('source_path');
            $table->unsignedInteger('page_number');
            $table->string('header_text')->nullable();
            $table->string('brand_context')->nullable();
            $table->string('brand_context_source')->nullable();
            $table->longText('raw_text')->nullable();
            $table->unsignedInteger('products_count')->default(0);
            $table->boolean('needs_review')->default(false);
            $table->timestamps();

            $table->unique(['source_path', 'page_number'], 'pdf_pages_src_page_unique');
            $table->index(['source_name', 'page_number'], 'pdf_pages_source_page_idx');
        });

        Schema::create('pdf_catalogue_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdf_catalogue_page_id')->constrained('pdf_catalogue_pages')->cascadeOnDelete();
            $table->string('source_name');
            $table->string('source_path');
            $table->unsignedInteger('page_number');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('brand');
            $table->string('brand_source')->nullable();
            $table->string('product_code');
            $table->text('product_name');
            $table->char('confidence', 1);
            $table->string('confidence_reason')->nullable();
            $table->text('raw_name_text')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->timestamps();

            $table->unique(['source_path', 'page_number', 'product_code'], 'pdf_products_src_page_code_unique');
            $table->index(['confidence', 'page_number'], 'pdf_products_conf_page_idx');
            $table->index('product_code', 'pdf_products_code_idx');
            $table->index('brand', 'pdf_products_brand_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_catalogue_products');
        Schema::dropIfExists('pdf_catalogue_pages');
    }
};
