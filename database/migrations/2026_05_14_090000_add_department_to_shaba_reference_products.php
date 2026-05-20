<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shaba_reference_products', function (Blueprint $table): void {
            $table->string('department', 40)
                ->default('body_care')
                ->after('normalized_title')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('shaba_reference_products', function (Blueprint $table): void {
            $table->dropColumn('department');
        });
    }
};
