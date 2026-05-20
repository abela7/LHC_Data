<?php

use App\Support\ObservedBrandVerdict;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observed_brand_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('observed_brand')->unique();
            $table->string('canonical_brand');
            $table->string('brand_line')->nullable();
            $table->string('official_source_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $defaults = ObservedBrandVerdict::defaults();
        $now = now();

        $brands = DB::table('observed_products')
            ->where('brand', '!=', '')
            ->distinct()
            ->pluck('brand');

        foreach ($brands as $brand) {
            $brand = trim((string) $brand);
            $mapping = $defaults[$brand] ?? [
                'canonical_brand' => $brand,
                'brand_line' => null,
                'official_source_url' => null,
                'notes' => null,
            ];

            DB::table('observed_brand_mappings')->insert([
                'observed_brand' => $brand,
                'canonical_brand' => $mapping['canonical_brand'],
                'brand_line' => $mapping['brand_line'],
                'official_source_url' => $mapping['official_source_url'],
                'notes' => $mapping['notes'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('observed_products')
                ->where('brand', $brand)
                ->update([
                    'canonical_brand' => $mapping['canonical_brand'],
                    'brand_line' => $mapping['brand_line'],
                ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('observed_brand_mappings');
    }
};
