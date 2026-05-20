<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_capture_uploads', function (Blueprint $table): void {
            $table->string('original_storage_path')->nullable()->after('storage_path');
            $table->string('processed_storage_path')->nullable()->after('original_storage_path');
            $table->string('processing_status')->default('disabled')->after('processed_storage_path');
            $table->text('processing_error')->nullable()->after('processing_status');
            $table->timestamp('background_removed_at')->nullable()->after('processing_error');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_capture_uploads', function (Blueprint $table): void {
            $table->dropColumn([
                'original_storage_path',
                'processed_storage_path',
                'processing_status',
                'processing_error',
                'background_removed_at',
            ]);
        });
    }
};
