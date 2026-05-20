<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveroo_official_products', function (Blueprint $table): void {
            $table->json('option_values')->nullable()->after('image_urls');
        });
    }

    public function down(): void
    {
        Schema::table('deliveroo_official_products', function (Blueprint $table): void {
            $table->dropColumn('option_values');
        });
    }
};
