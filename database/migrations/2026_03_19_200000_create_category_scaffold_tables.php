<?php

use App\Support\BeautizoneCategoryScaffold;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_scaffolds', function (Blueprint $table) {
            $table->id();
            $table->string('group_key');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('note')->nullable();
            $table->string('meta_type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('category_scaffold_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_scaffold_id')->constrained('category_scaffolds')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_scaffold_id', 'slug']);
        });

        $now = now();
        $rootOrder = 10;

        foreach (BeautizoneCategoryScaffold::catalogueCategories() as $root) {
            $rootId = DB::table('category_scaffolds')->insertGetId([
                'group_key' => 'catalogue',
                'name' => $root['name'],
                'slug' => Str::slug($root['name']),
                'note' => $root['note'],
                'meta_type' => null,
                'is_active' => true,
                'sort_order' => $rootOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $nodeOrder = 10;
            foreach ($root['children'] as $child) {
                DB::table('category_scaffold_nodes')->insert([
                    'category_scaffold_id' => $rootId,
                    'name' => $child,
                    'slug' => Str::slug($child),
                    'note' => null,
                    'is_active' => true,
                    'sort_order' => $nodeOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $nodeOrder += 10;
            }

            $rootOrder += 10;
        }

        $rootOrder = 10;
        foreach (BeautizoneCategoryScaffold::departmentBuckets() as $root) {
            $rootId = DB::table('category_scaffolds')->insertGetId([
                'group_key' => 'department',
                'name' => $root['name'],
                'slug' => Str::slug($root['name']),
                'note' => $root['note'],
                'meta_type' => null,
                'is_active' => true,
                'sort_order' => $rootOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $nodeOrder = 10;
            foreach ($root['children'] as $child) {
                DB::table('category_scaffold_nodes')->insert([
                    'category_scaffold_id' => $rootId,
                    'name' => $child,
                    'slug' => Str::slug($child),
                    'note' => null,
                    'is_active' => true,
                    'sort_order' => $nodeOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $nodeOrder += 10;
            }

            $rootOrder += 10;
        }

        $rootOrder = 10;
        foreach (BeautizoneCategoryScaffold::nonTaxonomyCollections() as $root) {
            DB::table('category_scaffolds')->insert([
                'group_key' => 'collection',
                'name' => $root['name'],
                'slug' => $this->makeUniqueRootSlug(Str::slug($root['name'])),
                'note' => $root['note'],
                'meta_type' => $root['type'],
                'is_active' => true,
                'sort_order' => $rootOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rootOrder += 10;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_scaffold_nodes');
        Schema::dropIfExists('category_scaffolds');
    }

    private function makeUniqueRootSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $suffix = 2;

        while (DB::table('category_scaffolds')->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
};
