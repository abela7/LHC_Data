<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('merge_events', function (Blueprint $table) {
            $table->id();
            $table->string('mergeable_type')->index();
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('target_id');
            $table->text('notes')->nullable();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at')->useCurrent();
            $table->timestamps();

            $table->index(['mergeable_type', 'source_id']);
            $table->index(['mergeable_type', 'target_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merge_events');
    }
};
