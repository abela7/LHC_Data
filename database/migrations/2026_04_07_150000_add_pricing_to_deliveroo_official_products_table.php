<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveroo_official_products', function (Blueprint $table): void {
            $table->decimal('price', 10, 2)->nullable()->after('option_values');
            $table->string('currency', 8)->default('GBP')->after('price');
            $table->string('price_notes')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('deliveroo_official_products', function (Blueprint $table): void {
            $table->dropColumn(['price', 'currency', 'price_notes']);
        });
    }
};
