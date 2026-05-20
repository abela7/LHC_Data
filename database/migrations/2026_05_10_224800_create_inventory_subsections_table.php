<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_subsections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_section_id')->constrained('inventory_sections')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['inventory_section_id', 'slug'], 'inventory_subsections_section_slug_unique');
        });

        Schema::table('inventory_levels', function (Blueprint $table): void {
            $table->foreignId('inventory_subsection_id')
                ->nullable()
                ->after('inventory_section_id')
                ->constrained('inventory_subsections')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_levels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inventory_subsection_id');
        });

        Schema::dropIfExists('inventory_subsections');
    }
};
