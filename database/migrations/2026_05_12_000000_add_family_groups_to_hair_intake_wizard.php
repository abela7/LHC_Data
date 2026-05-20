<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('intake_session_family_groups')) {
            Schema::create('intake_session_family_groups', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('intake_session_id')->constrained('intake_sessions')->cascadeOnDelete();
                $table->string('name');
                $table->json('scope_json')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['intake_session_id', 'sort_order'], 'intake_family_group_session_sort_index');
            });
        }

        if (! Schema::hasColumn('intake_session_variants', 'intake_session_family_group_id')) {
            Schema::table('intake_session_variants', function (Blueprint $table): void {
                $table->foreignId('intake_session_family_group_id')
                    ->nullable()
                    ->after('intake_session_id')
                    ->constrained('intake_session_family_groups')
                    ->nullOnDelete();

                $table->index(['intake_session_family_group_id', 'status'], 'intake_variant_group_status_index');
            });
        }

        $this->dropForeignIfExists('product_families', 'product_families_brand_catalogue_style_id_foreign');

        if ($this->indexExists('product_families', 'product_families_brand_catalogue_style_id_unique')) {
            Schema::table('product_families', function (Blueprint $table): void {
                $table->dropUnique('product_families_brand_catalogue_style_id_unique');
            });
        }

        if (! Schema::hasColumn('product_families', 'catalogue_scope_key')) {
            Schema::table('product_families', function (Blueprint $table): void {
                $table->string('catalogue_scope_key')->nullable()->after('brand_catalogue_style_id');
            });
        }

        if (! $this->indexExists('product_families', 'product_families_style_scope_unique')) {
            Schema::table('product_families', function (Blueprint $table): void {
                $table->unique(['brand_catalogue_style_id', 'catalogue_scope_key'], 'product_families_style_scope_unique');
            });
        }

        if (! $this->foreignExists('product_families', 'product_families_brand_catalogue_style_id_foreign')) {
            Schema::table('product_families', function (Blueprint $table): void {
                $table->foreign('brand_catalogue_style_id', 'product_families_brand_catalogue_style_id_foreign')
                    ->references('id')
                    ->on('brand_catalogue_styles')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $this->dropForeignIfExists('product_families', 'product_families_brand_catalogue_style_id_foreign');

        Schema::table('product_families', function (Blueprint $table): void {
            if ($this->indexExists('product_families', 'product_families_style_scope_unique')) {
                $table->dropUnique('product_families_style_scope_unique');
            }
            if (Schema::hasColumn('product_families', 'catalogue_scope_key')) {
                $table->dropColumn('catalogue_scope_key');
            }
            if (! $this->indexExists('product_families', 'product_families_brand_catalogue_style_id_unique')) {
                $table->unique('brand_catalogue_style_id');
            }
        });

        if (! $this->foreignExists('product_families', 'product_families_brand_catalogue_style_id_foreign')) {
            Schema::table('product_families', function (Blueprint $table): void {
                $table->foreign('brand_catalogue_style_id', 'product_families_brand_catalogue_style_id_foreign')
                    ->references('id')
                    ->on('brand_catalogue_styles')
                    ->nullOnDelete();
            });
        }

        Schema::table('intake_session_variants', function (Blueprint $table): void {
            $table->dropForeign(['intake_session_family_group_id']);
            $table->dropIndex('intake_variant_group_status_index');
            $table->dropColumn('intake_session_family_group_id');
        });

        Schema::dropIfExists('intake_session_family_groups');
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    private function foreignExists(string $table, string $foreign): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $foreign)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }

    private function dropForeignIfExists(string $table, string $foreign): void
    {
        if (! $this->foreignExists($table, $foreign)) {
            return;
        }

        DB::statement("alter table `{$table}` drop foreign key `{$foreign}`");
    }
};
