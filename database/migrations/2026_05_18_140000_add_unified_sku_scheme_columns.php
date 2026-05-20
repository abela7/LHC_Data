<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the columns needed by the unified SKU scheme:
 *   {DEPT}-{BRAND}-{FFFFF}{V}  e.g. HE-XPR-00012A
 *
 * - brands.sku_code: 3-4 char uppercase brand code (e.g. XPR, AFP, ORS).
 * - product_families.sku_family_seq: per (department, brand) family number,
 *   allocated once per family and stable thereafter.
 * - products.legacy_sku: the original SKU before the migration, kept for audit
 *   so we can revert or look up by historical code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->string('sku_code', 4)->nullable()->after('slug');
            $table->unique('sku_code');
        });

        Schema::table('product_families', function (Blueprint $table): void {
            $table->unsignedInteger('sku_family_seq')->nullable()->after('slug');
            $table->index(['root_catalogue_name', 'brand_id', 'sku_family_seq'], 'product_families_sku_family_seq_idx');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('legacy_sku')->nullable()->after('sku');
            $table->index('legacy_sku');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['legacy_sku']);
            $table->dropColumn('legacy_sku');
        });

        Schema::table('product_families', function (Blueprint $table): void {
            $table->dropIndex('product_families_sku_family_seq_idx');
            $table->dropColumn('sku_family_seq');
        });

        Schema::table('brands', function (Blueprint $table): void {
            $table->dropUnique(['sku_code']);
            $table->dropColumn('sku_code');
        });
    }
};
