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
        Schema::create('import_record_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_record_id')->constrained('import_records')->cascadeOnDelete();
            $table->morphs('linkable');
            $table->string('relation_role')->default('staged')->index();
            $table->timestamps();

            $table->unique(['import_record_id', 'linkable_type', 'linkable_id'], 'import_record_link_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_record_links');
    }
};
