<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mamado_products', function (Blueprint $table): void {
            $table->id();
            $table->string('item_code')->unique();
            $table->text('item_description');
            $table->decimal('gross_unit_price', 10, 2)->nullable();
            $table->string('units')->nullable();
            $table->string('source_order_number')->nullable()->index();
            $table->date('source_order_date')->nullable()->index();
            $table->date('source_delivery_date')->nullable();
            $table->json('raw_order_line')->nullable();

            $table->string('brand_label')->nullable()->index();
            $table->string('family_name')->nullable()->index();
            $table->string('variant_name')->nullable();
            $table->string('sellable_name')->nullable();
            $table->longText('description')->nullable();
            $table->json('image_urls')->nullable();
            $table->decimal('sellable_price', 10, 2)->nullable();
            $table->string('status')->default('source_only')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('item_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mamado_products');
    }
};
