<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_catalogue_brands', function (Blueprint $table) {
            $table->string('url')->nullable()->after('note');
        });

        Schema::table('brand_catalogue_styles', function (Blueprint $table) {
            $table->string('url')->nullable()->after('note');
        });

        Schema::table('brand_catalogue_variants', function (Blueprint $table) {
            $table->string('url')->nullable()->after('variant_type');
        });
    }

    public function down(): void
    {
        Schema::table('brand_catalogue_brands', fn (Blueprint $t) => $t->dropColumn('url'));
        Schema::table('brand_catalogue_styles', fn (Blueprint $t) => $t->dropColumn('url'));
        Schema::table('brand_catalogue_variants', fn (Blueprint $t) => $t->dropColumn('url'));
    }
};
