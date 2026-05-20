<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_product_intake_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_type');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['option_type', 'name'], 'shop_product_intake_options_type_name_unique');
            $table->index(['option_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_product_intake_options');
    }
};
