<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('category_scaffold_nodes', function (Blueprint $table) {
            if (! Schema::hasColumn('category_scaffold_nodes', 'parent_id')) {
                $table->foreignId('parent_id')
                    ->nullable()
                    ->after('category_scaffold_id')
                    ->constrained('category_scaffold_nodes')
                    ->cascadeOnDelete();
            }
        });

        $root = DB::table('category_scaffolds')
            ->where('group_key', 'catalogue')
            ->where(function ($query) {
                $query->where('slug', 'hair-care')
                    ->orWhere('slug', 'hair-products');
            })
            ->first();

        if (! $root) {
            return;
        }

        $now = now();

        DB::table('category_scaffolds')
            ->where('id', $root->id)
            ->update([
                'name' => 'Hair Products',
                'slug' => 'hair-products',
                'note' => 'Reference scaffold aligned to TJ Beauty\'s Hair Products menu: Hair Care, Hair Colour, and Hair Concerns.',
                'updated_at' => $now,
            ]);

        DB::table('category_scaffold_nodes')
            ->where('category_scaffold_id', $root->id)
            ->delete();

        $groupOrder = 10;

        foreach ($this->hairProductsTree() as $group) {
            $parentId = DB::table('category_scaffold_nodes')->insertGetId([
                'category_scaffold_id' => $root->id,
                'parent_id' => null,
                'name' => $group['name'],
                'slug' => Str::slug($group['name']),
                'note' => $group['note'],
                'is_active' => true,
                'sort_order' => $groupOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $childOrder = 10;

            foreach ($group['children'] as $child) {
                DB::table('category_scaffold_nodes')->insert([
                    'category_scaffold_id' => $root->id,
                    'parent_id' => $parentId,
                    'name' => $child,
                    'slug' => Str::slug($child),
                    'note' => null,
                    'is_active' => true,
                    'sort_order' => $childOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $childOrder += 10;
            }

            $groupOrder += 10;
        }
    }

    public function down(): void
    {
        $root = DB::table('category_scaffolds')
            ->where('group_key', 'catalogue')
            ->where(function ($query) {
                $query->where('slug', 'hair-products')
                    ->orWhere('name', 'Hair Products');
            })
            ->first();

        if ($root) {
            $now = now();

            DB::table('category_scaffold_nodes')
                ->where('category_scaffold_id', $root->id)
                ->delete();

            DB::table('category_scaffolds')
                ->where('id', $root->id)
                ->update([
                    'name' => 'Hair Care',
                    'slug' => 'hair-care',
                    'note' => 'Core hair-care taxonomy for shampoos, styling, treatments, relaxers, and dye.',
                    'updated_at' => $now,
                ]);

            $nodeOrder = 10;

            foreach ($this->legacyHairCareNodes() as $node) {
                DB::table('category_scaffold_nodes')->insert([
                    'category_scaffold_id' => $root->id,
                    'name' => $node,
                    'slug' => Str::slug($node),
                    'note' => null,
                    'is_active' => true,
                    'sort_order' => $nodeOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $nodeOrder += 10;
            }
        }

        Schema::table('category_scaffold_nodes', function (Blueprint $table) {
            if (Schema::hasColumn('category_scaffold_nodes', 'parent_id')) {
                $table->dropConstrainedForeignId('parent_id');
            }
        });
    }

    /**
     * @return array<int, array{name: string, note: string, children: array<int, string>}>
     */
    private function hairProductsTree(): array
    {
        return [
            [
                'name' => 'Hair Care',
                'note' => 'TJ Beauty care branch for cleansing, conditioning, styling, chemical relaxing, and treatment products.',
                'children' => [
                    'Shampoos',
                    'Conditioners',
                    'Hair Masks & Treatments',
                    'Hair Moisturisers',
                    'Hair Styling',
                    'Hair Relaxers & Texturizers',
                    'Hair Kits',
                ],
            ],
            [
                'name' => 'Hair Colour',
                'note' => 'TJ Beauty colour branch for developer systems, kits, permanent colour, and temporary colour.',
                'children' => [
                    'Bleach, Peroxide & Developers',
                    'Hair Colour Kits',
                    'Permanent Hair Colour',
                    'Semi/Demi Permanent Hair Colour',
                    'Temporary Hair Colour',
                    'Hair Colour Accessories',
                ],
            ],
            [
                'name' => 'Hair Concerns',
                'note' => 'TJ Beauty concern-led navigation for hair states, textures, and treatment intents.',
                'children' => [
                    'Braids',
                    'Breakage',
                    'Colour Care',
                    'Dandruff',
                    'Frizz Control',
                    'Hair Growth',
                    'Heat Protection',
                    'Locs',
                    'Natural Curls',
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function legacyHairCareNodes(): array
    {
        return [
            'Shampoo',
            'Conditioner',
            'Moisturisers',
            'Treatments / Masques',
            'Relaxer / Texturizers',
            'Dye / Peroxides',
            'Serum / Oils',
            'Styling',
        ];
    }
};
