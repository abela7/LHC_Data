<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('catalogue_families', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('subcategories')->nullOnDelete();
            $table->string('product_family_name');
            $table->string('slug')->nullable();
            $table->text('short_description')->nullable();
            $table->longText('full_description')->nullable();
            $table->decimal('source_confidence', 5, 2)->nullable();
            $table->decimal('import_confidence', 5, 2)->nullable();
            $table->string('status')->default('imported')->index();
            $table->boolean('needs_source_verification')->default(false)->index();
            $table->boolean('duplicate_flag')->default(false)->index();
            $table->json('imported_json_snapshot')->nullable();
            $table->longText('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->index();
            $table->unsignedBigInteger('merged_into_family_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'product_family_name']);
            $table->unique(['brand_id', 'slug']);
            $table->foreign('merged_into_family_id')
                ->references('id')
                ->on('catalogue_families')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalogue_families');
    }
};
