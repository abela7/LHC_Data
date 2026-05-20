<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hair_extension_intakes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_catalogue_brand_id')->nullable()->constrained('brand_catalogue_brands')->nullOnDelete();
            $table->foreignId('brand_catalogue_product_type_id')->nullable()->constrained('brand_catalogue_product_types')->nullOnDelete();
            $table->foreignId('brand_catalogue_style_id')->nullable()->constrained('brand_catalogue_styles')->nullOnDelete();
            $table->string('brand_name')->nullable();
            $table->string('product_type_name')->nullable();
            $table->boolean('product_type_unknown')->default(false);
            $table->string('style_name')->nullable();
            $table->boolean('style_unknown')->default(false);
            $table->json('variant_groups')->nullable();
            $table->string('source_url', 2000)->nullable();
            $table->longText('visible_text_notes')->nullable();
            $table->string('photo_disk')->nullable();
            $table->string('photo_path', 2000)->nullable();
            $table->string('photo_original_filename')->nullable();
            $table->string('status')->default('draft');
            $table->string('ai_status')->default('not_started');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['brand_name', 'status']);
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hair_extension_intakes');
    }
};
