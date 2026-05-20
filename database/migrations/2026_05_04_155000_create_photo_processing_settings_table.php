<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_processing_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('remove_background_enabled')->default(false);
            $table->boolean('apply_to_mobile_capture')->default(true);
            $table->boolean('keep_original')->default(true);
            $table->string('background_color', 7)->default('#ffffff');
            $table->string('python_command')->default('py');
            $table->unsignedInteger('timeout_seconds')->default(120);
            $table->timestamps();
        });

        DB::table('photo_processing_settings')->insert([
            'remove_background_enabled' => false,
            'apply_to_mobile_capture' => true,
            'keep_original' => true,
            'background_color' => '#ffffff',
            'python_command' => 'py',
            'timeout_seconds' => 120,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_processing_settings');
    }
};
