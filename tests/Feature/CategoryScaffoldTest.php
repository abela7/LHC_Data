<?php

namespace Tests\Feature;

use App\Models\CategoryScaffold;
use App\Models\CategoryScaffoldNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryScaffoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_the_step_one_scaffold_page(): void
    {
        $response = $this->get(route('categories.scaffold'));

        $response->assertOk();
        $response->assertSeeText('Beautizone Scaffold');
        $response->assertSeeText('Step 1: Choose A Section');
        $response->assertSeeText('Catalogue Categories');
        $response->assertSeeText('Department Buckets');
        $response->assertSeeText('Non-category Collections');
    }

    public function test_it_shows_a_scaffold_section_page(): void
    {
        $response = $this->get(route('categories.scaffold.section', ['group' => 'catalogue']));

        $response->assertOk();
        $response->assertSeeText('Step 2');
        $response->assertSeeText('Catalogue Categories');
        $response->assertSeeText('Hair Care');
        $response->assertSeeText('Manage this root');
    }

    public function test_it_shows_a_scaffold_root_page(): void
    {
        $root = CategoryScaffold::query()->where('name', 'Hair Care')->firstOrFail();

        $response = $this->get(route('categories.scaffold.roots.show', ['root' => $root]));

        $response->assertOk();
        $response->assertSeeText('Step 3');
        $response->assertSeeText('Hair Care');
        $response->assertSeeText('Manage nodes under Hair Care');
        $response->assertSeeText('Shampoo');
    }

    public function test_it_can_add_and_update_a_scaffold_root(): void
    {
        $create = $this->post(route('categories.scaffold.roots.store'), [
            'group_key' => 'catalogue',
            'name' => 'Tools',
            'note' => 'Manual tools root',
            'sort_order' => 70,
        ]);

        $create->assertRedirect(route('categories.scaffold.section', ['group' => 'catalogue']));
        $this->assertDatabaseHas('category_scaffolds', [
            'name' => 'Tools',
            'group_key' => 'catalogue',
        ]);

        $root = CategoryScaffold::query()->where('name', 'Tools')->firstOrFail();

        $update = $this->patch(route('categories.scaffold.roots.update', ['root' => $root]), [
            'group_key' => 'department',
            'name' => 'Tools & Kits',
            'note' => 'Updated tools root',
            'meta_type' => null,
            'sort_order' => 80,
        ]);

        $update->assertRedirect(route('categories.scaffold.roots.show', ['root' => $root]));
        $this->assertDatabaseHas('category_scaffolds', [
            'id' => $root->id,
            'name' => 'Tools & Kits',
            'group_key' => 'department',
        ]);
    }

    public function test_it_can_add_and_update_a_scaffold_node(): void
    {
        $root = CategoryScaffold::query()->where('name', 'Hair Care')->firstOrFail();

        $create = $this->post(route('categories.scaffold.nodes.store', ['root' => $root]), [
            'name' => 'Leave-In Conditioner',
            'note' => 'Added manually',
            'sort_order' => 95,
        ]);

        $create->assertRedirect(route('categories.scaffold.roots.show', ['root' => $root]));
        $this->assertDatabaseHas('category_scaffold_nodes', [
            'category_scaffold_id' => $root->id,
            'name' => 'Leave-In Conditioner',
        ]);

        $node = CategoryScaffoldNode::query()
            ->where('category_scaffold_id', $root->id)
            ->where('name', 'Leave-In Conditioner')
            ->firstOrFail();

        $update = $this->patch(route('categories.scaffold.nodes.update', ['node' => $node]), [
            'name' => 'Leave-In Conditioners',
            'note' => 'Pluralized label',
            'sort_order' => 96,
        ]);

        $update->assertRedirect(route('categories.scaffold.roots.show', ['root' => $root]));
        $this->assertDatabaseHas('category_scaffold_nodes', [
            'id' => $node->id,
            'name' => 'Leave-In Conditioners',
        ]);
    }
}
