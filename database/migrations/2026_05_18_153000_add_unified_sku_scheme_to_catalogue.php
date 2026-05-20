<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the unified SKU scheme to the brand-catalogue side so the Style
 * Workspace can issue codes following {DEPT}-{BRAND}-{FFFFF}{V}.
 *
 * Mirrors the columns added on the retail side:
 *   - brand_catalogue_brands.sku_code      (3-4 char uppercase brand code)
 *   - brand_catalogue_styles.sku_family_seq (per catalogue+brand family number)
 *   - brand_catalogue_skus.legacy_sku_code  (original code, for audit/rollback)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_catalogue_brands', function (Blueprint $table): void {
            $table->string('sku_code', 4)->nullable()->after('slug');
            $table->unique('sku_code', 'bc_brands_sku_code_unique');
        });

        Schema::table('brand_catalogue_styles', function (Blueprint $table): void {
            $table->unsignedInteger('sku_family_seq')->nullable()->after('slug');
            $table->index(
                ['brand_catalogue_brand_id', 'sku_family_seq'],
                'bc_styles_sku_family_seq_idx'
            );
        });

        Schema::table('brand_catalogue_skus', function (Blueprint $table): void {
            $table->string('legacy_sku_code')->nullable()->after('sku_code');
            $table->index('legacy_sku_code', 'bc_skus_legacy_sku_code_idx');
        });
    }

    public function down(): void
    {
        Schema::table('brand_catalogue_skus', function (Blueprint $table): void {
            $table->dropIndex('bc_skus_legacy_sku_code_idx');
            $table->dropColumn('legacy_sku_code');
        });

        Schema::table('brand_catalogue_styles', function (Blueprint $table): void {
            $table->dropIndex('bc_styles_sku_family_seq_idx');
            $table->dropColumn('sku_family_seq');
        });

        Schema::table('brand_catalogue_brands', function (Blueprint $table): void {
            $table->dropUnique('bc_brands_sku_code_unique');
            $table->dropColumn('sku_code');
        });
    }
};
