<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('category_scaffold_brand_assignments');

        Schema::create('category_scaffold_brand_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_scaffold_id');
            $table->unsignedBigInteger('category_scaffold_node_id')->nullable();
            $table->string('canonical_brand_name');
            $table->text('note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_scaffold_id', 'category_scaffold_node_id', 'canonical_brand_name'], 'scaffold_brand_assignment_unique');
            $table->foreign('category_scaffold_id', 'csba_scaffold_fk')
                ->references('id')
                ->on('category_scaffolds')
                ->cascadeOnDelete();
            $table->foreign('category_scaffold_node_id', 'csba_node_fk')
                ->references('id')
                ->on('category_scaffold_nodes')
                ->nullOnDelete();
        });

        $root = DB::table('category_scaffolds')
            ->where('group_key', 'catalogue')
            ->where(function ($query) {
                $query->where('slug', 'hair-extensions')
                    ->orWhere('slug', 'hair-extensions-wigs');
            })
            ->first();

        if (! $root) {
            return;
        }

        DB::table('category_scaffolds')
            ->where('id', $root->id)
            ->update([
                'name' => 'Hair Extensions & Wigs',
                'slug' => 'hair-extensions-wigs',
                'note' => 'Hair pieces, braiding hair, bulk hair, wigs, bundles, closures, frontals, ponytails, and related extension-led products.',
                'updated_at' => now(),
            ]);

        $hairCategoryId = DB::table('categories')->where('slug', 'hair')->value('id');

        if (! $hairCategoryId) {
            return;
        }

        $brands = DB::table('observed_products')
            ->where('category_id', $hairCategoryId)
            ->where('canonical_brand', '!=', '')
            ->selectRaw('canonical_brand as canonical_brand_name, COUNT(*) as row_count, COUNT(DISTINCT product_name) as product_count')
            ->groupBy('canonical_brand')
            ->orderByDesc('product_count')
            ->orderBy('canonical_brand')
            ->get();

        $sortOrder = 10;

        foreach ($brands as $brand) {
            DB::table('category_scaffold_brand_assignments')->insert([
                'category_scaffold_id' => $root->id,
                'category_scaffold_node_id' => null,
                'canonical_brand_name' => $brand->canonical_brand_name,
                'note' => null,
                'sort_order' => $sortOrder,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sortOrder += 10;
        }
    }

    public function down(): void
    {
        $root = DB::table('category_scaffolds')
            ->where('group_key', 'catalogue')
            ->where(function ($query) {
                $query->where('slug', 'hair-extensions-wigs')
                    ->orWhere('name', 'Hair Extensions & Wigs');
            })
            ->first();

        if ($root) {
            DB::table('category_scaffolds')
                ->where('id', $root->id)
                ->update([
                    'name' => 'Hair Extensions',
                    'slug' => 'hair-extensions',
                    'note' => 'Hair-piece and extension structure for synthetic, human hair, braids, crochet, clip-ins, and ponytails.',
                    'updated_at' => now(),
                ]);
        }

        Schema::dropIfExists('category_scaffold_brand_assignments');
    }
};
