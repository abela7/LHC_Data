<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brand_catalogue_styles', 'brand_catalogue_product_type_id')) {
            Schema::table('brand_catalogue_styles', function (Blueprint $table) {
                $table->unsignedBigInteger('brand_catalogue_product_type_id')
                    ->nullable()
                    ->after('brand_catalogue_material_id');
            });
        }

        if (! Schema::hasColumn('brand_catalogue_styles', 'material_name')) {
            Schema::table('brand_catalogue_styles', function (Blueprint $table) {
                $table->string('material_name')->nullable()->after('brand_catalogue_product_type_id');
            });
        }

        if (! $this->foreignKeyExists('brand_catalogue_styles', 'bc_style_product_type_fk')) {
            Schema::table('brand_catalogue_styles', function (Blueprint $table) {
                $table->foreign('brand_catalogue_product_type_id', 'bc_style_product_type_fk')
                    ->references('id')
                    ->on('brand_catalogue_product_types')
                    ->nullOnDelete();
            });
        }

        $this->backfillStyleProductTypesAndMaterials();
    }

    public function down(): void
    {
        if ($this->foreignKeyExists('brand_catalogue_styles', 'bc_style_product_type_fk')) {
            Schema::table('brand_catalogue_styles', function (Blueprint $table) {
                $table->dropForeign('bc_style_product_type_fk');
            });
        }

        if (Schema::hasColumn('brand_catalogue_styles', 'material_name')) {
            Schema::table('brand_catalogue_styles', function (Blueprint $table) {
                $table->dropColumn('material_name');
            });
        }

        if (Schema::hasColumn('brand_catalogue_styles', 'brand_catalogue_product_type_id')) {
            Schema::table('brand_catalogue_styles', function (Blueprint $table) {
                $table->dropColumn('brand_catalogue_product_type_id');
            });
        }
    }

    private function backfillStyleProductTypesAndMaterials(): void
    {
        $rows = DB::table('brand_catalogue_styles as styles')
            ->leftJoin('brand_catalogue_materials as materials', 'materials.id', '=', 'styles.brand_catalogue_material_id')
            ->select([
                'styles.id',
                'materials.brand_catalogue_product_type_id as product_type_id',
                'materials.name as material_name',
            ])
            ->orderBy('styles.id')
            ->get();

        foreach ($rows as $row) {
            DB::table('brand_catalogue_styles')
                ->where('id', $row->id)
                ->update([
                    'brand_catalogue_product_type_id' => $row->product_type_id,
                    'material_name' => $row->material_name,
                ]);
        }
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }

        $database = DB::getDatabaseName();

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $foreignKey)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
