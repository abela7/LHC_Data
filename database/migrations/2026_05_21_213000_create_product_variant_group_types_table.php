<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_group_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('product_variant_group_types')->insert([
            ['name' => 'Length / size', 'slug' => 'measurement', 'is_system' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Colour name', 'slug' => 'colour_name', 'is_system' => true, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Colour code', 'slug' => 'colour_code', 'is_system' => true, 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Short code', 'slug' => 'short_code', 'is_system' => true, 'sort_order' => 40, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Pack / count', 'slug' => 'count', 'is_system' => true, 'sort_order' => 50, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Text', 'slug' => 'text', 'is_system' => true, 'sort_order' => 60, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_group_types');
    }
};
