<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogue_images', function (Blueprint $table): void {
            if (! Schema::hasColumn('catalogue_images', 'source_label')) {
                $table->string('source_label')->nullable()->after('source_id');
            }

            if (! Schema::hasColumn('catalogue_images', 'usage_context')) {
                $table->string('usage_context')->default('all')->after('source_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalogue_images', function (Blueprint $table): void {
            $columns = [];

            if (Schema::hasColumn('catalogue_images', 'usage_context')) {
                $columns[] = 'usage_context';
            }

            if (Schema::hasColumn('catalogue_images', 'source_label')) {
                $columns[] = 'source_label';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
