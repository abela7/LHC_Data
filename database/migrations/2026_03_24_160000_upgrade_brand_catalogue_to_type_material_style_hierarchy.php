<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_catalogue_product_types')) {
            Schema::create('brand_catalogue_product_types', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('brand_catalogue_brand_id');
                $table->string('name');
                $table->string('slug');
                $table->text('note')->nullable();
                $table->string('url')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['brand_catalogue_brand_id', 'slug'], 'bc_product_types_brand_slug_unique');
                $table->foreign('brand_catalogue_brand_id', 'bc_pt_brand_fk')
                    ->references('id')
                    ->on('brand_catalogue_brands')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('brand_catalogue_materials')) {
            Schema::create('brand_catalogue_materials', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('brand_catalogue_product_type_id');
                $table->string('name');
                $table->string('slug');
                $table->text('note')->nullable();
                $table->string('url')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['brand_catalogue_product_type_id', 'slug'], 'bc_materials_product_type_slug_unique');
                $table->foreign('brand_catalogue_product_type_id', 'bc_mat_pt_fk')
                    ->references('id')
                    ->on('brand_catalogue_product_types')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('brand_catalogue_styles', 'brand_catalogue_material_id')) {
            Schema::table('brand_catalogue_styles', function (Blueprint $table) {
                $table->unsignedBigInteger('brand_catalogue_material_id')
                    ->nullable()
                    ->after('brand_catalogue_brand_id');
            });
        }

        if (! $this->foreignKeyExists('brand_catalogue_styles', 'bc_style_material_fk')) {
            Schema::table('brand_catalogue_styles', function (Blueprint $table) {
                $table->foreign('brand_catalogue_material_id', 'bc_style_material_fk')
                    ->references('id')
                    ->on('brand_catalogue_materials')
                    ->nullOnDelete();
            });
        }

        if (! $this->indexExists('brand_catalogue_styles', 'bc_styles_brand_idx')) {
            Schema::table('brand_catalogue_styles', function (Blueprint $table) {
                $table->index('brand_catalogue_brand_id', 'bc_styles_brand_idx');
            });
        }

        if ($this->indexExists('brand_catalogue_styles', 'brand_catalogue_styles_brand_catalogue_brand_id_slug_unique')) {
            Schema::table('brand_catalogue_styles', function (Blueprint $table) {
                $table->dropUnique('brand_catalogue_styles_brand_catalogue_brand_id_slug_unique');
            });
        }

        if (! $this->indexExists('brand_catalogue_styles', 'bc_styles_material_slug_unique')) {
            Schema::table('brand_catalogue_styles', function (Blueprint $table) {
                $table->unique(['brand_catalogue_material_id', 'slug'], 'bc_styles_material_slug_unique');
            });
        }

        $this->migrateExistingStyles();
    }

    public function down(): void
    {
        Schema::table('brand_catalogue_styles', function (Blueprint $table) {
            if ($this->indexExists('brand_catalogue_styles', 'bc_styles_material_slug_unique')) {
                $table->dropUnique('bc_styles_material_slug_unique');
            }
            if (! $this->indexExists('brand_catalogue_styles', 'brand_catalogue_styles_brand_catalogue_brand_id_slug_unique')) {
                $table->unique(['brand_catalogue_brand_id', 'slug'], 'brand_catalogue_styles_brand_catalogue_brand_id_slug_unique');
            }
            if ($this->foreignKeyExists('brand_catalogue_styles', 'bc_style_material_fk')) {
                $table->dropForeign('bc_style_material_fk');
            }
            if ($this->indexExists('brand_catalogue_styles', 'bc_styles_brand_idx')) {
                $table->dropIndex('bc_styles_brand_idx');
            }
            if (Schema::hasColumn('brand_catalogue_styles', 'brand_catalogue_material_id')) {
                $table->dropColumn('brand_catalogue_material_id');
            }
        });

        Schema::dropIfExists('brand_catalogue_materials');
        Schema::dropIfExists('brand_catalogue_product_types');
    }

    private function migrateExistingStyles(): void
    {
        $brands = DB::table('brand_catalogue_brands')->select('id', 'name')->get();
        $now = now();

        foreach ($brands as $brand) {
            $styles = DB::table('brand_catalogue_styles')
                ->where('brand_catalogue_brand_id', $brand->id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            if ($styles->isEmpty()) {
                continue;
            }

            foreach ($styles as $style) {
                [$productTypeName, $materialName, $productTypeNote, $materialNote] = $this->styleMapping($brand->name, $style->name);

                $productTypeId = $this->findOrCreateProductType(
                    brandId: $brand->id,
                    name: $productTypeName,
                    note: $productTypeNote,
                    sortOrder: 10,
                    now: $now,
                );

                $materialId = $this->findOrCreateMaterial(
                    productTypeId: $productTypeId,
                    name: $materialName,
                    note: $materialNote,
                    sortOrder: 10,
                    now: $now,
                );

                DB::table('brand_catalogue_styles')
                    ->where('id', $style->id)
                    ->update([
                        'brand_catalogue_material_id' => $materialId,
                        'updated_at' => $now,
                    ]);
            }
        }
    }

    /**
     * @return array{0:string,1:string,2:?string,3:?string}
     */
    private function styleMapping(string $brandName, string $styleName): array
    {
        $brand = Str::lower(trim($brandName));
        $style = Str::lower(trim($styleName));

        if ($brand === 'x-pression' && $style === 'ultra braid') {
            return [
                'Braiding Hair',
                'Synthetic Hair',
                'Extension install/type family for braid-ready hair.',
                'Synthetic fibre lines.',
            ];
        }

        return [
            'Unclassified Product Type',
            'Unclassified Material',
            'Temporary holding type for legacy records that still need manual placement.',
            'Temporary holding material for legacy records that still need manual placement.',
        ];
    }

    private function findOrCreateProductType(int $brandId, string $name, ?string $note, int $sortOrder, $now): int
    {
        $existingId = DB::table('brand_catalogue_product_types')
            ->where('brand_catalogue_brand_id', $brandId)
            ->where('name', $name)
            ->value('id');

        if ($existingId) {
            return (int) $existingId;
        }

        return (int) DB::table('brand_catalogue_product_types')->insertGetId([
            'brand_catalogue_brand_id' => $brandId,
            'name' => $name,
            'slug' => $this->uniqueScopedSlug('brand_catalogue_product_types', 'brand_catalogue_brand_id', $brandId, $name),
            'note' => $note,
            'url' => null,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function findOrCreateMaterial(int $productTypeId, string $name, ?string $note, int $sortOrder, $now): int
    {
        $existingId = DB::table('brand_catalogue_materials')
            ->where('brand_catalogue_product_type_id', $productTypeId)
            ->where('name', $name)
            ->value('id');

        if ($existingId) {
            return (int) $existingId;
        }

        return (int) DB::table('brand_catalogue_materials')->insertGetId([
            'brand_catalogue_product_type_id' => $productTypeId,
            'name' => $name,
            'slug' => $this->uniqueScopedSlug('brand_catalogue_materials', 'brand_catalogue_product_type_id', $productTypeId, $name),
            'note' => $note,
            'url' => null,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function uniqueScopedSlug(string $table, string $scopeColumn, int $scopeId, string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $suffix = 2;

        while (DB::table($table)
            ->where($scopeColumn, $scopeId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function indexExists(string $table, string $index): bool
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }

        return count(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
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
