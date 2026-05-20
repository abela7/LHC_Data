<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brand_catalogue_skus', 'description')) {
            Schema::table('brand_catalogue_skus', function (Blueprint $table) {
                $table->text('description')->nullable()->after('option_signature');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('brand_catalogue_skus', 'description')) {
            Schema::table('brand_catalogue_skus', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
