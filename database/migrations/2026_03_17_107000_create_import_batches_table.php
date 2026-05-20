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
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_uuid')->unique();
            $table->string('import_channel')->index();
            $table->string('original_filename')->nullable();
            $table->string('source_label')->nullable();
            $table->string('status')->default('received')->index();
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('accepted_records')->default(0);
            $table->unsignedInteger('warning_records')->default(0);
            $table->unsignedInteger('rejected_records')->default(0);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
