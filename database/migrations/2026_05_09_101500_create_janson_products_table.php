<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('janson_products', function (Blueprint $table): void {
            $table->id();
            $table->string('source_row_id')->unique();
            $table->unsignedInteger('row_index');
            $table->unsignedInteger('page')->nullable();
            $table->unsignedInteger('page_row')->nullable();
            $table->string('code')->nullable()->index();
            $table->string('source_code')->nullable();
            $table->string('category')->nullable()->index();
            $table->string('source_category')->nullable();
            $table->string('name');
            $table->string('source_name')->nullable();
            $table->decimal('price_gbp', 10, 2)->nullable();
            $table->string('currency', 3)->default('GBP');
            $table->json('flags')->nullable();
            $table->boolean('is_new')->default(false);
            $table->string('special_note')->nullable();
            $table->json('review_flags')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['category', 'name']);
            $table->index(['page', 'page_row']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('janson_products');
    }
};
