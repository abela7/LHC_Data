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
        Schema::create('catalogue_sources', function (Blueprint $table) {
            $table->id();
            $table->morphs('sourceable');
            $table->string('role')->default('secondary')->index();
            $table->string('source_type')->index();
            $table->string('trust_status')->default('unverified')->index();
            $table->text('url')->nullable();
            $table->string('title')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->boolean('is_primary')->default(false)->index();
            $table->boolean('is_verified')->default(false)->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalogue_sources');
    }
};
