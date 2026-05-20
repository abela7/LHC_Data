<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hair_extension_intakes', 'classification_path')) {
            Schema::table('hair_extension_intakes', function (Blueprint $table): void {
                $table->json('classification_path')->nullable()->after('product_type_name');
            });
        }

        if (! Schema::hasColumn('hair_extension_intakes', 'shelf_location')) {
            Schema::table('hair_extension_intakes', function (Blueprint $table): void {
                $table->string('shelf_location')->nullable()->after('classification_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hair_extension_intakes', 'shelf_location')) {
            Schema::table('hair_extension_intakes', function (Blueprint $table): void {
                $table->dropColumn('shelf_location');
            });
        }

        if (Schema::hasColumn('hair_extension_intakes', 'classification_path')) {
            Schema::table('hair_extension_intakes', function (Blueprint $table): void {
                $table->dropColumn('classification_path');
            });
        }
    }
};
