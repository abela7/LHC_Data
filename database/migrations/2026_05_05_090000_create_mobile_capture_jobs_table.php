<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_capture_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 80)->unique();
            $table->string('status')->default('pending');
            $table->string('destination_type');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id');
            $table->string('target_label')->nullable();
            $table->string('image_role')->nullable();
            $table->string('usage_context')->nullable();
            $table->string('source_label')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('result_type')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->text('error_message')->nullable();
            $table->string('last_ip')->nullable();
            $table->text('last_user_agent')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_capture_jobs');
    }
};
