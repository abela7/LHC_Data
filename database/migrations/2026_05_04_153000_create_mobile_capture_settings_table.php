<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_capture_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->string('access_token', 80)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip')->nullable();
            $table->text('last_user_agent')->nullable();
            $table->string('camera_status')->default('untested');
            $table->text('camera_error')->nullable();
            $table->timestamp('camera_tested_at')->nullable();
            $table->timestamps();
        });

        DB::table('mobile_capture_settings')->insert([
            'is_enabled' => false,
            'access_token' => Str::random(40),
            'camera_status' => 'untested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_capture_settings');
    }
};
