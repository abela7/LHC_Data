<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_catalogue_skus')) {
            Schema::create('brand_catalogue_skus', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('brand_catalogue_style_id');
                $table->string('name');
                $table->string('slug');
                $table->string('sku_code')->nullable();
                $table->string('barcode')->nullable();
                $table->string('option_signature')->default('');
                $table->text('note')->nullable();
                $table->string('url')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['brand_catalogue_style_id', 'slug'], 'bc_skus_style_slug_unique');
                $table->unique(['brand_catalogue_style_id', 'option_signature'], 'bc_skus_style_sig_unique');
                $table->foreign('brand_catalogue_style_id', 'bc_sku_style_fk')
                    ->references('id')
                    ->on('brand_catalogue_styles')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('brand_catalogue_sku_variant_options')) {
            Schema::create('brand_catalogue_sku_variant_options', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('brand_catalogue_sku_id');
                $table->unsignedBigInteger('brand_catalogue_variant_id');
                $table->unsignedBigInteger('brand_catalogue_variant_option_id');
                $table->timestamps();

                $table->unique(['brand_catalogue_sku_id', 'brand_catalogue_variant_id'], 'bc_sku_variant_unique');
                $table->unique(['brand_catalogue_sku_id', 'brand_catalogue_variant_option_id'], 'bc_sku_option_unique');
                $table->foreign('brand_catalogue_sku_id', 'bc_svo_sku_fk')
                    ->references('id')
                    ->on('brand_catalogue_skus')
                    ->cascadeOnDelete();
                $table->foreign('brand_catalogue_variant_id', 'bc_svo_variant_fk')
                    ->references('id')
                    ->on('brand_catalogue_variants')
                    ->cascadeOnDelete();
                $table->foreign('brand_catalogue_variant_option_id', 'bc_svo_option_fk')
                    ->references('id')
                    ->on('brand_catalogue_variant_options')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_catalogue_sku_variant_options');
        Schema::dropIfExists('brand_catalogue_skus');
    }
};
