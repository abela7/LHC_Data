<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watermark_settings', function (Blueprint $table): void {
            $table->string('layout_mode')->default('fit')->after('text_size_percent');
            $table->unsignedTinyInteger('max_width_percent')->default(90)->after('layout_mode');
            $table->unsignedTinyInteger('margin_percent')->default(4)->after('max_width_percent');
            $table->smallInteger('rotation_degrees')->default(0)->after('margin_percent');
            $table->unsignedTinyInteger('shadow_opacity')->default(55)->after('opacity');
            $table->boolean('background_enabled')->default(false)->after('shadow_opacity');
            $table->string('background_color', 7)->default('#000000')->after('background_enabled');
            $table->unsignedTinyInteger('background_opacity')->default(20)->after('background_color');
            $table->unsignedTinyInteger('background_padding_percent')->default(2)->after('background_opacity');
        });
    }

    public function down(): void
    {
        Schema::table('watermark_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'layout_mode',
                'max_width_percent',
                'margin_percent',
                'rotation_degrees',
                'shadow_opacity',
                'background_enabled',
                'background_color',
                'background_opacity',
                'background_padding_percent',
            ]);
        });
    }
};
