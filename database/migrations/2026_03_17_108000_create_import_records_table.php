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
        Schema::create('import_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->foreignId('target_family_id')->nullable()->constrained('catalogue_families')->nullOnDelete();
            $table->string('external_reference')->nullable();
            $table->string('status')->default('pending_parse')->index();
            $table->longText('raw_json');
            $table->json('normalized_json')->nullable();
            $table->string('payload_hash', 64)->nullable()->index();
            $table->decimal('import_confidence', 5, 2)->nullable();
            $table->json('parse_warnings')->nullable();
            $table->text('import_notes')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('staged_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_records');
    }
};
