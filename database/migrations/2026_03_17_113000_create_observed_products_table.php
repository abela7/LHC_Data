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
        Schema::create('observed_products', function (Blueprint $table) {
            $table->id();
            $table->string('picture_id')->index();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->string('brand');
            $table->string('product_name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('observed_products');
    }
};
