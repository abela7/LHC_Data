<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_catalogues', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('brand_catalogue_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_catalogue_id')->constrained('brand_catalogues')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['brand_catalogue_id', 'slug']);
        });

        Schema::create('brand_catalogue_styles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_catalogue_brand_id')->constrained('brand_catalogue_brands')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['brand_catalogue_brand_id', 'slug']);
        });

        Schema::create('brand_catalogue_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_catalogue_style_id')->constrained('brand_catalogue_styles')->cascadeOnDelete();
            $table->string('name');
            $table->string('variant_type')->default('text');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('brand_catalogue_variant_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('variant_id');
            $table->foreign('variant_id', 'bc_var_opts_variant_fk')->references('id')->on('brand_catalogue_variants')->cascadeOnDelete();
            $table->string('label');
            $table->string('value')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed the initial catalogue
        DB::table('brand_catalogues')->insert([
            'name' => 'Hair Extensions',
            'slug' => 'hair-extensions',
            'note' => 'Complete product catalogue for hair extensions and wigs — organised by brand, style, and variant.',
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_catalogue_variant_options');
        Schema::dropIfExists('brand_catalogue_variants');
        Schema::dropIfExists('brand_catalogue_styles');
        Schema::dropIfExists('brand_catalogue_brands');
        Schema::dropIfExists('brand_catalogues');
    }
};
