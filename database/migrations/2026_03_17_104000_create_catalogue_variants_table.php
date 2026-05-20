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
        Schema::create('catalogue_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogue_family_id')->constrained('catalogue_families')->cascadeOnDelete();
            $table->foreignId('catalogue_type_id')->nullable()->constrained('catalogue_types')->nullOnDelete();
            $table->string('variant_display_name');
            $table->string('color_code')->nullable();
            $table->string('color_name')->nullable();
            $table->string('size')->nullable();
            $table->string('length')->nullable();
            $table->unsignedInteger('bundle_count')->nullable();
            $table->string('pack_size')->nullable();
            $table->string('texture')->nullable();
            $table->string('shade')->nullable();
            $table->string('finish')->nullable();
            $table->string('style')->nullable();
            $table->string('weight')->nullable();
            $table->string('volume')->nullable();
            $table->json('attributes_json')->nullable();
            $table->decimal('source_confidence', 5, 2)->nullable();
            $table->decimal('import_confidence', 5, 2)->nullable();
            $table->string('status')->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->index();
            $table->unsignedBigInteger('merged_into_variant_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['catalogue_family_id', 'variant_display_name'], 'cat_variants_family_display_idx');
            $table->foreign('merged_into_variant_id')
                ->references('id')
                ->on('catalogue_variants')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalogue_variants');
    }
};
