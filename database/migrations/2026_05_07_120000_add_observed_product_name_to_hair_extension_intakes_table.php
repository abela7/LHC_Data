<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hair_extension_intakes', 'observed_product_name')) {
            Schema::table('hair_extension_intakes', function (Blueprint $table): void {
                $table->string('observed_product_name')->nullable()->after('brand_name');
            });
        }

        DB::statement('
            UPDATE hair_extension_intakes
            SET observed_product_name = product_type_name
            WHERE observed_product_name IS NULL
              AND product_type_name IS NOT NULL
        ');
    }

    public function down(): void
    {
        if (Schema::hasColumn('hair_extension_intakes', 'observed_product_name')) {
            Schema::table('hair_extension_intakes', function (Blueprint $table): void {
                $table->dropColumn('observed_product_name');
            });
        }
    }
};
