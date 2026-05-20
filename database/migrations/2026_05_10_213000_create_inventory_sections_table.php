<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['inventory_location_id', 'slug'], 'inventory_sections_location_slug_unique');
        });

        Schema::table('inventory_levels', function (Blueprint $table): void {
            $table->foreignId('inventory_section_id')
                ->nullable()
                ->after('inventory_location_id')
                ->constrained('inventory_sections')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_levels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inventory_section_id');
        });

        Schema::dropIfExists('inventory_sections');
    }
};
