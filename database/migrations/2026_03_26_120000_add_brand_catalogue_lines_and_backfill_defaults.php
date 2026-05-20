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
        if (! Schema::hasTable('brand_catalogue_lines')) {
            Schema::create('brand_catalogue_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('brand_catalogue_brand_id');
                $table->string('name');
                $table->string('slug');
                $table->text('note')->nullable();
                $table->string('url')->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['brand_catalogue_brand_id', 'slug'], 'bc_lines_brand_slug_unique');
                $table->foreign('brand_catalogue_brand_id', 'bc_line_brand_fk')
                    ->references('id')
                    ->on('brand_catalogue_brands')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('brand_catalogue_product_types', 'brand_catalogue_line_id')) {
            Schema::table('brand_catalogue_product_types', function (Blueprint $table) {
                $table->unsignedBigInteger('brand_catalogue_line_id')
                    ->nullable()
                    ->after('brand_catalogue_brand_id');
            });
        }

        if (! $this->foreignKeyExists('brand_catalogue_product_types', 'bc_pt_line_fk')) {
            Schema::table('brand_catalogue_product_types', function (Blueprint $table) {
                $table->foreign('brand_catalogue_line_id', 'bc_pt_line_fk')
                    ->references('id')
                    ->on('brand_catalogue_lines')
                    ->nullOnDelete();
            });
        }

        $this->backfillDefaultLines();

        if ($this->indexExists('brand_catalogue_product_types', 'bc_product_types_brand_slug_unique')) {
            if (! $this->indexExists('brand_catalogue_product_types', 'bc_product_types_brand_idx')) {
                Schema::table('brand_catalogue_product_types', function (Blueprint $table) {
                    $table->index('brand_catalogue_brand_id', 'bc_product_types_brand_idx');
                });
            }

            Schema::table('brand_catalogue_product_types', function (Blueprint $table) {
                $table->dropUnique('bc_product_types_brand_slug_unique');
            });
        }

        if (! $this->indexExists('brand_catalogue_product_types', 'bc_product_types_line_slug_unique')) {
            Schema::table('brand_catalogue_product_types', function (Blueprint $table) {
                $table->unique(['brand_catalogue_line_id', 'slug'], 'bc_product_types_line_slug_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('brand_catalogue_product_types', 'bc_product_types_line_slug_unique')) {
            Schema::table('brand_catalogue_product_types', function (Blueprint $table) {
                $table->dropUnique('bc_product_types_line_slug_unique');
            });
        }

        if (! $this->indexExists('brand_catalogue_product_types', 'bc_product_types_brand_slug_unique')) {
            Schema::table('brand_catalogue_product_types', function (Blueprint $table) {
                $table->unique(['brand_catalogue_brand_id', 'slug'], 'bc_product_types_brand_slug_unique');
            });
        }

        if ($this->indexExists('brand_catalogue_product_types', 'bc_product_types_brand_idx')) {
            Schema::table('brand_catalogue_product_types', function (Blueprint $table) {
                $table->dropIndex('bc_product_types_brand_idx');
            });
        }

        if ($this->foreignKeyExists('brand_catalogue_product_types', 'bc_pt_line_fk')) {
            Schema::table('brand_catalogue_product_types', function (Blueprint $table) {
                $table->dropForeign('bc_pt_line_fk');
            });
        }

        if (Schema::hasColumn('brand_catalogue_product_types', 'brand_catalogue_line_id')) {
            Schema::table('brand_catalogue_product_types', function (Blueprint $table) {
                $table->dropColumn('brand_catalogue_line_id');
            });
        }

        Schema::dropIfExists('brand_catalogue_lines');
    }

    private function backfillDefaultLines(): void
    {
        $now = now();
        $brands = DB::table('brand_catalogue_brands')
            ->select('id', 'name', 'note', 'url')
            ->orderBy('id')
            ->get();

        foreach ($brands as $brand) {
            $defaultLineId = DB::table('brand_catalogue_lines')
                ->where('brand_catalogue_brand_id', $brand->id)
                ->where('is_default', true)
                ->value('id');

            if (! $defaultLineId) {
                $defaultLineId = DB::table('brand_catalogue_lines')->insertGetId([
                    'brand_catalogue_brand_id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $this->uniqueScopedSlug('brand_catalogue_lines', 'brand_catalogue_brand_id', (int) $brand->id, $brand->name),
                    'note' => $brand->note,
                    'url' => $brand->url,
                    'is_default' => true,
                    'is_active' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('brand_catalogue_product_types')
                ->where('brand_catalogue_brand_id', $brand->id)
                ->whereNull('brand_catalogue_line_id')
                ->update([
                    'brand_catalogue_line_id' => $defaultLineId,
                    'updated_at' => $now,
                ]);
        }
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
