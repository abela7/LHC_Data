<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hair_extension_intake_ai_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hair_extension_intake_id')->nullable();
            $table->string('brand_name');
            $table->string('observed_product_name');
            $table->string('source_url', 2000)->nullable();
            $table->string('provider')->default('gemini');
            $table->string('model')->nullable();
            $table->string('status')->default('pending');
            $table->string('confidence', 1)->nullable();
            $table->json('suggestion')->nullable();
            $table->json('source_urls')->nullable();
            $table->longText('raw_response')->nullable();
            $table->text('error_message')->nullable();
            $table->string('prompt_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['brand_name', 'observed_product_name'], 'hei_ai_brand_product_idx');
            $table->index(['status', 'confidence'], 'hei_ai_status_confidence_idx');
            $table->foreign('hair_extension_intake_id', 'hei_ai_intake_fk')
                ->references('id')
                ->on('hair_extension_intakes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hair_extension_intake_ai_suggestions');
    }
};
