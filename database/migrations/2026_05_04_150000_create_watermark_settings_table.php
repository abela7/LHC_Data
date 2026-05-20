<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watermark_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->string('text')->default('LHC');
            $table->string('text_color', 7)->default('#ffffff');
            $table->string('font_family')->default('Arial');
            $table->string('position')->default('bottom-right');
            $table->unsignedTinyInteger('opacity')->default(35);
            $table->timestamps();
        });

        DB::table('watermark_settings')->insert([
            'is_enabled' => false,
            'text' => 'LHC',
            'text_color' => '#ffffff',
            'font_family' => 'Arial',
            'position' => 'bottom-right',
            'opacity' => 35,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('watermark_settings');
    }
};
