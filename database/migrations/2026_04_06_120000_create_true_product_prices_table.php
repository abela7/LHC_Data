<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('true_product_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('match_key')->unique();
            $table->string('observed_brand');
            $table->string('observed_name');
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 8)->default('GBP');
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['observed_brand', 'observed_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('true_product_prices');
    }
};
