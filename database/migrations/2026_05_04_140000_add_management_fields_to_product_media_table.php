<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_media', function (Blueprint $table): void {
            $table->string('source_type')->default('external_url')->after('image_role');
            $table->string('source_label')->nullable()->after('source_type');
            $table->string('usage_context')->default('all')->after('source_label');
            $table->string('alt_text')->nullable()->after('storage_path');
            $table->boolean('is_offline_ready')->default(false)->after('is_primary');

            $table->index(['image_role', 'usage_context']);
            $table->index('source_type');
        });

        DB::table('product_media')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $sourceType = 'external_url';

                if ($row->catalogue_image_id !== null) {
                    $sourceType = 'catalogue_source';
                } elseif ($row->storage_path !== null) {
                    $sourceType = 'file_upload';
                }

                DB::table('product_media')
                    ->where('id', $row->id)
                    ->update([
                        'source_type' => $sourceType,
                        'usage_context' => 'all',
                        'is_offline_ready' => $row->storage_path !== null,
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_media', function (Blueprint $table): void {
            $table->dropIndex(['image_role', 'usage_context']);
            $table->dropIndex(['source_type']);
            $table->dropColumn([
                'source_type',
                'source_label',
                'usage_context',
                'alt_text',
                'is_offline_ready',
            ]);
        });
    }
};
