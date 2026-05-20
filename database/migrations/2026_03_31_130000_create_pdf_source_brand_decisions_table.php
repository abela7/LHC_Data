<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_source_brand_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('source_name');
            $table->string('brand_name');
            $table->boolean('is_excluded')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['source_name', 'brand_name'], 'pdf_source_brand_unique');
            $table->index(['source_name', 'is_excluded'], 'pdf_source_brand_excluded_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_source_brand_decisions');
    }
};
