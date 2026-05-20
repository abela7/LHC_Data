<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_category_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_family_id')->nullable()->constrained('product_families')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('category_scaffold_id');
            $table->unsignedBigInteger('category_scaffold_axis_id')->nullable();
            $table->unsignedBigInteger('category_scaffold_node_id')->nullable();
            $table->string('assignment_type')->default('primary');
            $table->string('source_type')->default('publisher');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('category_scaffold_id', 'pca_scaffold_fk')
                ->references('id')
                ->on('category_scaffolds')
                ->cascadeOnDelete();
            $table->foreign('category_scaffold_axis_id', 'pca_axis_fk')
                ->references('id')
                ->on('category_scaffold_axes')
                ->nullOnDelete();
            $table->foreign('category_scaffold_node_id', 'pca_node_fk')
                ->references('id')
                ->on('category_scaffold_nodes')
                ->nullOnDelete();

            $table->index(['product_family_id', 'assignment_type'], 'pca_family_type_index');
            $table->index(['product_id', 'assignment_type'], 'pca_product_type_index');
            $table->index(['category_scaffold_id', 'category_scaffold_node_id'], 'pca_scaffold_node_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_category_assignments');
    }
};
