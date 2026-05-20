<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codex_bridge_tasks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('task_uuid')->unique();
            $table->string('task_type');
            $table->foreignId('intake_session_id')->nullable()->constrained('intake_sessions')->cascadeOnDelete();
            $table->string('codex_thread_id')->nullable();
            $table->string('status')->default('queued');
            $table->string('prompt_disk')->nullable();
            $table->string('prompt_path')->nullable();
            $table->string('script_path')->nullable();
            $table->string('output_path')->nullable();
            $table->unsignedInteger('process_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['task_type', 'status']);
            $table->index(['intake_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codex_bridge_tasks');
    }
};
