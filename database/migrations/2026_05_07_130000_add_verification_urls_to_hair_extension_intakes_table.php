<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hair_extension_intakes', 'verification_urls')) {
            Schema::table('hair_extension_intakes', function (Blueprint $table): void {
                $table->json('verification_urls')->nullable()->after('source_url');
            });
        }

        DB::statement("
            UPDATE hair_extension_intakes
            SET verification_urls = JSON_ARRAY(source_url)
            WHERE source_url IS NOT NULL
              AND source_url != ''
              AND verification_urls IS NULL
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('hair_extension_intakes', 'verification_urls')) {
            Schema::table('hair_extension_intakes', function (Blueprint $table): void {
                $table->dropColumn('verification_urls');
            });
        }
    }
};
