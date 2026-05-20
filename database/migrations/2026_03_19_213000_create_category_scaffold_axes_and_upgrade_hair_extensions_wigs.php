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
        Schema::create('category_scaffold_axes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_scaffold_id')->constrained('category_scaffolds')->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->text('note')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_scaffold_id', 'key'], 'scaffold_axis_unique');
        });

        Schema::table('category_scaffold_nodes', function (Blueprint $table) {
            $table->foreignId('category_scaffold_axis_id')
                ->nullable()
                ->after('category_scaffold_id')
                ->constrained('category_scaffold_axes')
                ->cascadeOnDelete();
        });

        $now = now();
        $rootIds = DB::table('category_scaffolds')->pluck('id');

        foreach ($rootIds as $rootId) {
            $axisId = DB::table('category_scaffold_axes')->insertGetId([
                'category_scaffold_id' => $rootId,
                'key' => 'taxonomy',
                'name' => 'Primary Taxonomy',
                'note' => 'Operational structure used for catalogue, website navigation, POS, stock handling, and reporting.',
                'is_primary' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('category_scaffold_nodes')
                ->where('category_scaffold_id', $rootId)
                ->update(['category_scaffold_axis_id' => $axisId]);
        }

        $root = DB::table('category_scaffolds')
            ->where('group_key', 'catalogue')
            ->where('slug', 'hair-extensions-wigs')
            ->first();

        if (! $root) {
            return;
        }

        DB::table('category_scaffolds')
            ->where('id', $root->id)
            ->update([
                'note' => 'Extension-led catalogue root with one operational type taxonomy and one material-discovery layer.',
                'updated_at' => $now,
            ]);

        $taxonomyAxisId = DB::table('category_scaffold_axes')
            ->where('category_scaffold_id', $root->id)
            ->where('key', 'taxonomy')
            ->value('id');

        if (! $taxonomyAxisId) {
            return;
        }

        DB::table('category_scaffold_axes')
            ->where('id', $taxonomyAxisId)
            ->update([
                'name' => 'Shop by Type',
                'note' => 'Primary operational taxonomy. Use these nodes for catalogue ownership, stock grouping, POS mapping, and core website navigation.',
                'updated_at' => $now,
            ]);

        $materialAxisId = DB::table('category_scaffold_axes')->insertGetId([
            'category_scaffold_id' => $root->id,
            'key' => 'material',
            'name' => 'Shop by Material',
            'note' => 'Secondary shopper lens. Use alongside the primary type taxonomy when customers care more about fibre than install form.',
            'is_primary' => false,
            'sort_order' => 20,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('category_scaffold_nodes')
            ->where('category_scaffold_id', $root->id)
            ->delete();

        $this->seedAxisNodes($root->id, $taxonomyAxisId, $this->typeNodes(), $now);
        $this->seedAxisNodes($root->id, $materialAxisId, $this->materialNodes(), $now);
    }

    public function down(): void
    {
        $root = DB::table('category_scaffolds')
            ->where('group_key', 'catalogue')
            ->where('slug', 'hair-extensions-wigs')
            ->first();

        if ($root) {
            $now = now();

            DB::table('category_scaffold_nodes')
                ->where('category_scaffold_id', $root->id)
                ->delete();

            $taxonomyAxisId = DB::table('category_scaffold_axes')
                ->where('category_scaffold_id', $root->id)
                ->where('key', 'taxonomy')
                ->value('id');

            if ($taxonomyAxisId) {
                DB::table('category_scaffold_axes')
                    ->where('id', $taxonomyAxisId)
                    ->update([
                        'name' => 'Primary Taxonomy',
                        'note' => 'Operational structure used for catalogue, website navigation, POS, stock handling, and reporting.',
                        'updated_at' => $now,
                    ]);
            }

            DB::table('category_scaffold_axes')
                ->where('category_scaffold_id', $root->id)
                ->where('key', 'material')
                ->delete();

            DB::table('category_scaffolds')
                ->where('id', $root->id)
                ->update([
                    'note' => 'Hair pieces, braiding hair, bulk hair, wigs, bundles, closures, frontals, ponytails, and related extension-led products.',
                    'updated_at' => $now,
                ]);

            if ($taxonomyAxisId) {
                $this->seedAxisNodes($root->id, $taxonomyAxisId, $this->legacyHairExtensionsNodes(), $now);
            }
        }

        Schema::table('category_scaffold_nodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_scaffold_axis_id');
        });

        Schema::dropIfExists('category_scaffold_axes');
    }

    /**
     * @param  array<int, array{name: string, note: string|null, children?: array<int, array{name: string, note: string|null}>}>  $nodes
     */
    private function seedAxisNodes(int $rootId, int $axisId, array $nodes, $now): void
    {
        $rootOrder = 10;

        foreach ($nodes as $node) {
            $parentId = DB::table('category_scaffold_nodes')->insertGetId([
                'category_scaffold_id' => $rootId,
                'category_scaffold_axis_id' => $axisId,
                'parent_id' => null,
                'name' => $node['name'],
                'slug' => Str::slug($node['name']),
                'note' => $node['note'] ?? null,
                'is_active' => true,
                'sort_order' => $rootOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $childOrder = 10;

            foreach ($node['children'] ?? [] as $child) {
                DB::table('category_scaffold_nodes')->insert([
                    'category_scaffold_id' => $rootId,
                    'category_scaffold_axis_id' => $axisId,
                    'parent_id' => $parentId,
                    'name' => $child['name'],
                    'slug' => Str::slug($child['name']),
                    'note' => $child['note'] ?? null,
                    'is_active' => true,
                    'sort_order' => $childOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $childOrder += 10;
            }

            $rootOrder += 10;
        }
    }

    /**
     * @return array<int, array{name: string, note: string|null, children?: array<int, array{name: string, note: string|null}>}>
     */
    private function typeNodes(): array
    {
        return [
            [
                'name' => 'Braiding Hair',
                'note' => 'Primary node for plaiting and feed-in hair used in loose packs, pre-stretched lines, and braid-ready packs.',
            ],
            [
                'name' => 'Bulk Hair',
                'note' => 'Loose bulk fibre sold without a finished track, often used for braiding, twisting, or custom installs.',
            ],
            [
                'name' => 'Crochet, Twist & Loc Hair',
                'note' => 'Pre-formed crochet, faux loc, and twist-led extension products.',
            ],
            [
                'name' => 'Weaves & Bundles',
                'note' => 'Tracked hair, bundle hair, and weft-led extension products for sewn or bonded installs.',
            ],
            [
                'name' => 'Clip-In & Individual Extensions',
                'note' => 'Clip-in sets plus strand-based extension formats such as I-tip, nano, micro-loop, and similar individual installs.',
            ],
            [
                'name' => 'Closures & Frontals',
                'note' => 'Closure, frontal, and parting-piece products sold separately from the main hair bundles.',
            ],
            [
                'name' => 'Wigs',
                'note' => 'Full wig products including lace, braided, synthetic, and human-hair wig ranges.',
                'children' => [
                    [
                        'name' => 'Lace Wigs',
                        'note' => 'Lace-front, full-lace, and HD-lace wig ranges.',
                    ],
                    [
                        'name' => 'Braided Wigs',
                        'note' => 'Ready-made braided wig products.',
                    ],
                ],
            ],
            [
                'name' => 'Half Wigs & Instant Weaves',
                'note' => 'Half wigs, quick-weave units, and instant-weave formats that sit between wigs and extensions.',
            ],
            [
                'name' => 'Ponytails & Hair Pieces',
                'note' => 'Clip-on, claw-clip, drawstring, wrap, bun, topper, and similar finished hair pieces.',
            ],
        ];
    }

    /**
     * @return array<int, array{name: string, note: string|null, children?: array<int, array{name: string, note: string|null}>}>
     */
    private function materialNodes(): array
    {
        return [
            [
                'name' => 'Human Hair',
                'note' => 'Natural human-hair lines across wigs, bundles, closures, clip-ins, and hair pieces.',
            ],
            [
                'name' => 'Synthetic Hair',
                'note' => 'Synthetic fibre lines across braiding hair, wigs, extensions, and finished hair pieces.',
            ],
            [
                'name' => 'Human Hair Blend',
                'note' => 'Blended fibre lines where human hair is mixed with synthetic material.',
            ],
        ];
    }

    /**
     * @return array<int, array{name: string, note: string|null}>
     */
    private function legacyHairExtensionsNodes(): array
    {
        return [
            ['name' => 'Synthetic Hair Weave', 'note' => null],
            ['name' => 'Human Hair Weave', 'note' => null],
            ['name' => 'Braids / Plaiting Hair', 'note' => null],
            ['name' => 'Crochet Hair', 'note' => null],
            ['name' => 'Half Wigs / Instant Weave', 'note' => null],
            ['name' => 'Clip-in Hair Extensions', 'note' => null],
            ['name' => 'Pony Tails', 'note' => null],
        ];
    }
};
