<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hair_extension_intakes', function (Blueprint $table): void {
            if (! Schema::hasColumn('hair_extension_intakes', 'store_id')) {
                $table->foreignId('store_id')
                    ->nullable()
                    ->after('shelf_location')
                    ->constrained('inventory_locations')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('hair_extension_intakes', 'section_id')) {
                $table->foreignId('section_id')
                    ->nullable()
                    ->after('store_id')
                    ->constrained('inventory_sections')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('hair_extension_intakes', 'subsection_id')) {
                $table->foreignId('subsection_id')
                    ->nullable()
                    ->after('section_id')
                    ->constrained('inventory_subsections')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hair_extension_intakes', function (Blueprint $table): void {
            foreach (['subsection_id', 'section_id', 'store_id'] as $column) {
                if (Schema::hasColumn('hair_extension_intakes', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
        });
    }
};
