<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_photo_batch_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('shop_photo_batch_items', 'grouping_path')) {
                $table->json('grouping_path')->nullable()->after('brand_name');
            }

            if (! Schema::hasColumn('shop_photo_batch_items', 'hair_extension_intake_id')) {
                $table->foreignId('hair_extension_intake_id')
                    ->nullable()
                    ->after('analysis_notes')
                    ->constrained('hair_extension_intakes')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_photo_batch_items', function (Blueprint $table): void {
            if (Schema::hasColumn('shop_photo_batch_items', 'hair_extension_intake_id')) {
                $table->dropConstrainedForeignId('hair_extension_intake_id');
            }

            if (Schema::hasColumn('shop_photo_batch_items', 'grouping_path')) {
                $table->dropColumn('grouping_path');
            }
        });
    }
};
