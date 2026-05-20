<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_photo_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('source_folder')->nullable();
            $table->unsignedInteger('photos_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_photo_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_photo_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('original_filename')->nullable();
            $table->string('filename');
            $table->text('source_path');
            $table->string('brand_name')->nullable();
            $table->string('product_type_name')->nullable();
            $table->string('style_name')->nullable();
            $table->string('main_variant')->nullable();
            $table->string('sub_variant')->nullable();
            $table->string('common_variant')->nullable();
            $table->text('ecommerce_note')->nullable();
            $table->string('status')->default('pending_review');
            $table->string('confidence')->nullable();
            $table->text('analysis_notes')->nullable();
            $table->timestamps();

            $table->unique(['shop_photo_batch_id', 'sequence']);
            $table->index(['shop_photo_batch_id', 'status']);
            $table->index(['shop_photo_batch_id', 'brand_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_photo_batch_items');
        Schema::dropIfExists('shop_photo_batches');
    }
};
