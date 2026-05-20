<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hair_extension_intakes', 'catalogue_style_status')) {
            Schema::table('hair_extension_intakes', function (Blueprint $table): void {
                $table->string('catalogue_style_status', 20)->default('known')->after('brand_catalogue_style_id');
            });
        }

        if (! Schema::hasColumn('hair_extension_intakes', 'product_type_status')) {
            Schema::table('hair_extension_intakes', function (Blueprint $table): void {
                $table->string('product_type_status', 20)->default('known')->after('product_type_name');
            });
        }

        if (! Schema::hasColumn('hair_extension_intakes', 'style_family_status')) {
            Schema::table('hair_extension_intakes', function (Blueprint $table): void {
                $table->string('style_family_status', 20)->default('known')->after('style_name');
            });
        }
    }

    public function down(): void
    {
        foreach (['style_family_status', 'product_type_status', 'catalogue_style_status'] as $column) {
            if (Schema::hasColumn('hair_extension_intakes', $column)) {
                Schema::table('hair_extension_intakes', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
