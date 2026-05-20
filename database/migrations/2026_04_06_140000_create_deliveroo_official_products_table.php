<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveroo_official_products', function (Blueprint $table): void {
            $table->id();
            $table->string('brand_label');
            $table->string('brand_slug');
            $table->string('family_name')->nullable();
            $table->string('variant_name')->nullable();
            $table->string('official_name');
            $table->string('official_url')->unique();
            $table->longText('description')->nullable();
            $table->json('image_urls')->nullable();
            $table->string('source_site')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['brand_slug', 'family_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveroo_official_products');
    }
};
