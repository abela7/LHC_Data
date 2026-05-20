<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('session_uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('brand_catalogue_brand_id')->nullable()->constrained('brand_catalogue_brands')->nullOnDelete();
            $table->string('style_name_hint')->nullable();
            $table->string('photo_disk')->nullable();
            $table->string('photo_path', 2000)->nullable();
            $table->string('photo_original_filename')->nullable();
            $table->string('photo_mime_type')->nullable();
            $table->unsignedBigInteger('photo_file_size')->nullable();
            $table->json('observations_json')->nullable();
            $table->longText('user_note')->nullable();
            $table->foreignId('matched_style_id')->nullable()->constrained('brand_catalogue_styles')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->foreignId('published_family_id')->nullable()->constrained('product_families')->nullOnDelete();
            $table->timestamps();

            $table->index(['brand_catalogue_brand_id', 'status']);
            $table->index(['matched_style_id', 'status']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('intake_session_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_session_id')->constrained('intake_sessions')->cascadeOnDelete();
            $table->foreignId('brand_catalogue_sku_id')->nullable()->constrained('brand_catalogue_skus')->nullOnDelete();
            $table->boolean('manually_added')->default(false);
            $table->json('manual_axes_json')->nullable();
            $table->string('display_name');
            $table->string('main_value')->nullable();
            $table->string('sub_value')->nullable();
            $table->string('common_value')->nullable();
            $table->string('barcode')->nullable();
            $table->string('barcode_source')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->foreignId('store_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('inventory_sections')->nullOnDelete();
            $table->foreignId('subsection_id')->nullable()->constrained('inventory_subsections')->nullOnDelete();
            $table->string('status')->default('empty');
            $table->timestamps();

            $table->unique(['intake_session_id', 'brand_catalogue_sku_id'], 'intake_session_sku_unique');
            $table->index(['intake_session_id', 'status']);
            $table->index('barcode');
        });

        Schema::create('intake_session_variant_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_session_variant_id')->constrained('intake_session_variants')->cascadeOnDelete();
            $table->string('role');
            $table->string('storage_disk');
            $table->string('storage_path', 2000);
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['intake_session_variant_id', 'role'], 'intake_variant_photo_role_index');
        });

        Schema::create('intake_session_ai_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_session_id')->constrained('intake_sessions')->cascadeOnDelete();
            $table->string('call_type');
            $table->unsignedInteger('call_index');
            $table->longText('request_json');
            $table->longText('response_json')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->unique(['intake_session_id', 'call_type', 'call_index'], 'intake_ai_call_sequence_unique');
            $table->index(['intake_session_id', 'call_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_session_ai_calls');
        Schema::dropIfExists('intake_session_variant_photos');
        Schema::dropIfExists('intake_session_variants');
        Schema::dropIfExists('intake_sessions');
    }
};
