<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\RetailProductController;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductVariantGroup;
use App\Models\ProductVariantOption;
use App\Models\ProductVariantValue;
use App\Support\RetailFamilyVariantAxes;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

final class RetailFamilyRoleModelTest extends TestCase
{
    public function test_explicit_roles_classify_main_sub_and_common(): void
    {
        [$family, $products] = $this->buildRoledFamily();

        $axes = RetailFamilyVariantAxes::forFamily($family, $products);

        $this->assertTrue($axes->explicit, 'A fully role-tagged family should use the explicit path.');
        $this->assertSame(1, (int) $axes->mainGroup?->id, 'Length is the main axis.');
        $this->assertTrue($axes->isCommonGroup(3), 'Pack is common.');
        $this->assertTrue($axes->isSubGroup(2), 'Colour is sub-main.');
        $this->assertFalse($axes->isSubGroup(3), 'Pack is not a sub axis.');
    }

    public function test_partial_roles_fall_back_to_heuristic(): void
    {
        [$family, $products] = $this->buildRoledFamily();

        // Wipe the role off one axis -> the family is no longer fully tagged.
        $family->variantGroups->firstWhere('id', 2)->axis_role = null;

        $axes = RetailFamilyVariantAxes::forFamily($family, $products);

        $this->assertFalse($axes->explicit, 'A partially-tagged family must fall back to the heuristic.');
    }

    public function test_role_grid_is_main_by_sub_with_pinned_common(): void
    {
        [$family, $products] = $this->buildRoledFamily();
        $axes = RetailFamilyVariantAxes::forFamily($family, $products);

        $combos = $this->invokePrivate('roleGridCombos', [$family, $axes]);

        // 1 length x 2 colours = 2 combos; Pack pinned to 3x in each.
        $this->assertCount(2, $combos);

        foreach ($combos as $combo) {
            $byGroup = collect($combo)->mapWithKeys(fn (ProductVariantOption $o): array => [
                (int) $o->product_variant_group_id => $o->label,
            ])->all();

            $this->assertSame('20"', $byGroup[1], 'Main (Length) present.');
            $this->assertSame('3x', $byGroup[3], 'Common (Pack) pinned to 3x.');
            $this->assertContains($byGroup[2], ['4', '30'], 'Sub-main (Colour) is one of the values.');
        }

        $colours = collect($combos)
            ->map(fn (array $combo): string => collect($combo)
                ->firstWhere('product_variant_group_id', 2)->label)
            ->all();

        $this->assertEqualsCanonicalizing(['4', '30'], $colours);
    }

    public function test_generated_name_orders_by_role_and_dedupes_count_labels(): void
    {
        $main = new ProductVariantGroup(['id' => 1, 'name' => 'Length', 'variant_type' => 'measurement', 'sort_order' => 1, 'axis_role' => 'main']);
        $sub = new ProductVariantGroup(['id' => 2, 'name' => 'Colour', 'variant_type' => 'colour_name', 'sort_order' => 2, 'axis_role' => 'sub_main']);
        $common = new ProductVariantGroup(['id' => 3, 'name' => 'Pack', 'variant_type' => 'count', 'sort_order' => 3, 'axis_role' => 'common']);
        $commonDup = new ProductVariantGroup(['id' => 4, 'name' => 'Bundle', 'variant_type' => 'count', 'sort_order' => 4, 'axis_role' => 'common']);

        $options = collect([
            $this->option(31, 3, '3x', 1, $common),
            $this->option(21, 2, 'Grey', 1, $sub),
            $this->option(11, 1, '20"', 1, $main),
            $this->option(41, 4, '3X', 1, $commonDup), // case-variant duplicate of "3x"
        ]);

        $name = $this->invokePrivate('generatedRetailProductNameWithBase', ['Poppin Twist', $options]);

        $this->assertSame('Poppin Twist - 20" - Grey - 3x', $name);
        $this->assertStringNotContainsString('3X', $name, 'Case-variant duplicate count label must be deduped.');
    }

    /**
     * @return array{0: ProductFamily, 1: Collection<int, Product>}
     */
    private function buildRoledFamily(): array
    {
        $family = new ProductFamily(['id' => 1, 'family_name' => 'Poppin Twist']);

        $length = new ProductVariantGroup(['id' => 1, 'name' => 'Length', 'variant_type' => 'measurement', 'sort_order' => 1, 'axis_role' => 'main']);
        $colour = new ProductVariantGroup(['id' => 2, 'name' => 'Colour', 'variant_type' => 'colour_name', 'sort_order' => 2, 'axis_role' => 'sub_main']);
        $pack = new ProductVariantGroup(['id' => 3, 'name' => 'Pack', 'variant_type' => 'count', 'sort_order' => 3, 'axis_role' => 'common']);

        $len20 = new ProductVariantOption(['id' => 101, 'product_variant_group_id' => 1, 'label' => '20"', 'sort_order' => 1]);
        $col4 = new ProductVariantOption(['id' => 201, 'product_variant_group_id' => 2, 'label' => '4', 'sort_order' => 1]);
        $col30 = new ProductVariantOption(['id' => 202, 'product_variant_group_id' => 2, 'label' => '30', 'sort_order' => 2]);
        $pack3x = new ProductVariantOption(['id' => 301, 'product_variant_group_id' => 3, 'label' => '3x', 'sort_order' => 1]);

        $length->setRelation('options', collect([$len20]));
        $colour->setRelation('options', collect([$col4, $col30]));
        $pack->setRelation('options', collect([$pack3x]));
        $family->setRelation('variantGroups', collect([$length, $colour, $pack]));

        $products = collect([
            $this->makeProduct(1, [1 => $len20, 2 => $col4, 3 => $pack3x]),
        ]);
        $family->setRelation('products', $products);

        return [$family, $products];
    }

    private function option(int $id, int $groupId, string $label, int $sortOrder, ProductVariantGroup $group): ProductVariantOption
    {
        $option = new ProductVariantOption([
            'id' => $id,
            'product_variant_group_id' => $groupId,
            'label' => $label,
            'sort_order' => $sortOrder,
        ]);
        $option->setRelation('group', $group);

        return $option;
    }

    /**
     * @param  array<int, ProductVariantOption>  $optionsByGroup
     */
    private function makeProduct(int $id, array $optionsByGroup): Product
    {
        $product = new Product(['id' => $id, 'name' => 'SKU '.$id]);
        $values = collect();

        foreach ($optionsByGroup as $groupId => $option) {
            $value = new ProductVariantValue([
                'product_variant_group_id' => $groupId,
                'product_variant_option_id' => $option->id,
            ]);
            $value->setRelation('option', $option);
            $values->push($value);
        }

        $product->setRelation('variantValues', $values);

        return $product;
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function invokePrivate(string $method, array $args): mixed
    {
        $controller = new RetailProductController();
        $reflection = new ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, ...$args);
    }
}
