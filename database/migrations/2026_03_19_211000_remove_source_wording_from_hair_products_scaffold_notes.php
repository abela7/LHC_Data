<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rootId = DB::table('category_scaffolds')
            ->where('slug', 'hair-products')
            ->value('id');

        if (! $rootId) {
            return;
        }

        DB::table('category_scaffolds')
            ->where('id', $rootId)
            ->update([
                'note' => 'Main hair-products scaffold split into care, colour, and concern-led navigation.',
                'updated_at' => now(),
            ]);

        foreach ($this->nodeNotes() as $name => $note) {
            DB::table('category_scaffold_nodes')
                ->where('category_scaffold_id', $rootId)
                ->where('name', $name)
                ->update([
                    'note' => $note,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $rootId = DB::table('category_scaffolds')
            ->where('slug', 'hair-products')
            ->value('id');

        if (! $rootId) {
            return;
        }

        DB::table('category_scaffolds')
            ->where('id', $rootId)
            ->update([
                'note' => 'Reference scaffold aligned to TJ Beauty\'s Hair Products menu: Hair Care, Hair Colour, and Hair Concerns.',
                'updated_at' => now(),
            ]);

        foreach ($this->legacyNodeNotes() as $name => $note) {
            DB::table('category_scaffold_nodes')
                ->where('category_scaffold_id', $rootId)
                ->where('name', $name)
                ->update([
                    'note' => $note,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function nodeNotes(): array
    {
        return [
            'Hair Care' => 'Cleansing, conditioning, styling, relaxing, and treatment products.',
            'Hair Colour' => 'Colour systems, kits, permanent colour, and temporary colour products.',
            'Hair Concerns' => 'Concern-led navigation for texture, damage, scalp, and care needs.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function legacyNodeNotes(): array
    {
        return [
            'Hair Care' => 'TJ Beauty care branch for cleansing, conditioning, styling, chemical relaxing, and treatment products.',
            'Hair Colour' => 'TJ Beauty colour branch for developer systems, kits, permanent colour, and temporary colour.',
            'Hair Concerns' => 'TJ Beauty concern-led navigation for hair states, textures, and treatment intents.',
        ];
    }
};
