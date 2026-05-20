<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watermark_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('text_size_percent')->default(6)->after('font_family');
        });
    }

    public function down(): void
    {
        Schema::table('watermark_settings', function (Blueprint $table): void {
            $table->dropColumn('text_size_percent');
        });
    }
};
