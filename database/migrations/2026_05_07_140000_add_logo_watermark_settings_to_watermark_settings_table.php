<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watermark_settings', function (Blueprint $table): void {
            $table->boolean('text_enabled')->default(true)->after('is_enabled');
            $table->boolean('logo_enabled')->default(false)->after('background_padding_percent');
            $table->string('logo_path')->nullable()->after('logo_enabled');
            $table->unsignedTinyInteger('logo_size_percent')->default(18)->after('logo_path');
            $table->unsignedTinyInteger('logo_opacity')->default(45)->after('logo_size_percent');
            $table->string('logo_position')->default('bottom-left')->after('logo_opacity');
            $table->unsignedTinyInteger('logo_margin_percent')->default(4)->after('logo_position');
            $table->smallInteger('logo_rotation_degrees')->default(0)->after('logo_margin_percent');
        });
    }

    public function down(): void
    {
        Schema::table('watermark_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'text_enabled',
                'logo_enabled',
                'logo_path',
                'logo_size_percent',
                'logo_opacity',
                'logo_position',
                'logo_margin_percent',
                'logo_rotation_degrees',
            ]);
        });
    }
};
