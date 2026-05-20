<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hair_extension_intake_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hair_extension_intake_id')->constrained('hair_extension_intakes')->cascadeOnDelete();
            $table->string('image_role')->default('evidence');
            $table->string('source_label')->nullable();
            $table->text('notes')->nullable();
            $table->string('storage_disk')->default('public');
            $table->string('storage_path', 2000);
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('source_type')->default('phone_camera');
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['hair_extension_intake_id', 'image_role'], 'hei_photos_intake_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hair_extension_intake_photos');
    }
};
