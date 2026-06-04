<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductVariantGroup;
use App\Models\ProductVariantOption;
use App\Models\ProductVariantValue;
use App\Support\RetailFamilySellableCombinations;
use App\Support\RetailFamilyVariantAxes;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class RetailFamilySellableCombinationsTest extends TestCase
{
    public function test_new_colour_builds_one_combo_with_pinned_pack_not_pack_grey_templates(): void
    {
        $family = new ProductFamily(['id' => 1, 'family_name' => 'Test']);
        $bundle = new ProductVariantGroup(['id' => 10, 'name' => 'Bundle', 'variant_type' => 'count', 'sort_order' => 1]);
        $pack = new ProductVariantGroup(['id' => 20, 'name' => 'Pack', 'variant_type' => 'count', 'sort_order' => 2]);
        $colour = new ProductVariantGroup(['id' => 30, 'name' => 'Colour', 'variant_type' => 'colour_name', 'sort_order' => 3]);

        $bundle3x = new ProductVariantOption(['id' => 101, 'product_variant_group_id' => 10, 'label' => '3x']);
        $pack3x = new ProductVariantOption(['id' => 201, 'product_variant_group_id' => 20, 'label' => '3X']);
        $packGrey = new ProductVariantOption(['id' => 202, 'product_variant_group_id' => 20, 'label' => 'Grey']);
        $colourPlum = new ProductVariantOption(['id' => 301, 'product_variant_group_id' => 30, 'label' => 'PLUM']);
        $colourGrey = new ProductVariantOption(['id' => 302, 'product_variant_group_id' => 30, 'label' => 'Grey']);

        $bundle->setRelation('options', collect([$bundle3x]));
        $pack->setRelation('options', collect([$pack3x, $packGrey]));
        $colour->setRelation('options', collect([$colourPlum, $colourGrey]));
        $family->setRelation('variantGroups', collect([$bundle, $pack, $colour]));

        $good = $this->makeProduct(1, [
            10 => $bundle3x,
            20 => $pack3x,
            30 => $colourPlum,
        ]);
        $wrong = $this->makeProduct(2, [
            10 => $bundle3x,
            20 => $packGrey,
            30 => $colourPlum,
        ]);

        $family->setRelation('products', collect([$good, $wrong]));

        $combos = RetailFamilySellableCombinations::forNewVariantOptions(
            $family,
            collect([302]),
        );

        $this->assertCount(1, $combos);

        $labels = collect($combos[0])
            ->mapWithKeys(fn (ProductVariantOption $option): array => [
                (int) $option->product_variant_group_id => $option->label,
            ])
            ->all();

        $this->assertSame('3x', $labels[10]);
        $this->assertSame('3X', $labels[20]);
        $this->assertSame('Grey', $labels[30]);
    }

    public function test_axes_pin_pack_to_three_x_when_adding_colour_grey(): void
    {
        $family = new ProductFamily(['id' => 1]);
        $pack = new ProductVariantGroup(['id' => 20, 'name' => 'Pack', 'variant_type' => 'count', 'sort_order' => 1]);
        $colour = new ProductVariantGroup(['id' => 30, 'name' => 'Colour', 'variant_type' => 'colour_name', 'sort_order' => 2]);
        $pack3x = new ProductVariantOption(['id' => 201, 'product_variant_group_id' => 20, 'label' => '3X']);
        $packGrey = new ProductVariantOption(['id' => 202, 'product_variant_group_id' => 20, 'label' => 'Grey']);
        $colourGrey = new ProductVariantOption(['id' => 302, 'product_variant_group_id' => 30, 'label' => 'Grey']);
        $pack->setRelation('options', collect([$pack3x, $packGrey]));
        $colour->setRelation('options', collect([$colourGrey]));
        $family->setRelation('variantGroups', collect([$pack, $colour]));

        $products = collect([
            $this->makeProduct(1, [20 => $packGrey, 30 => $colourGrey]),
            $this->makeProduct(2, [20 => $pack3x, 30 => $colourGrey]),
        ]);

        $axes = RetailFamilyVariantAxes::forFamily($family, $products);
        $pinned = $axes->pinnedCommonOptions($family, $products, $colourGrey);

        $this->assertSame('3X', $pinned[20]->label);
    }

    public function test_new_sub_main_colour_can_be_restricted_to_one_main_length(): void
    {
        $family = new ProductFamily(['id' => 1, 'family_name' => 'Poppin Twist']);
        $length = new ProductVariantGroup([
            'id' => 10,
            'name' => 'Length',
            'axis_role' => ProductVariantGroup::AXIS_ROLE_MAIN,
            'variant_type' => 'length',
            'sort_order' => 1,
        ]);
        $pack = new ProductVariantGroup([
            'id' => 20,
            'name' => 'Pack',
            'axis_role' => ProductVariantGroup::AXIS_ROLE_COMMON,
            'variant_type' => 'count',
            'sort_order' => 2,
        ]);
        $colour = new ProductVariantGroup([
            'id' => 30,
            'name' => 'Colour',
            'axis_role' => ProductVariantGroup::AXIS_ROLE_SUB_MAIN,
            'variant_type' => 'colour_name',
            'sort_order' => 3,
        ]);

        $length16 = new ProductVariantOption(['id' => 101, 'product_variant_group_id' => 10, 'label' => '16"']);
        $length20 = new ProductVariantOption(['id' => 102, 'product_variant_group_id' => 10, 'label' => '20"']);
        $pack3x = new ProductVariantOption(['id' => 201, 'product_variant_group_id' => 20, 'label' => '3X']);
        $colourRed = new ProductVariantOption(['id' => 301, 'product_variant_group_id' => 30, 'label' => 'RED']);
        $colourGrey = new ProductVariantOption(['id' => 302, 'product_variant_group_id' => 30, 'label' => 'GREY']);

        $length->setRelation('options', collect([$length16, $length20]));
        $pack->setRelation('options', collect([$pack3x]));
        $colour->setRelation('options', collect([$colourRed, $colourGrey]));
        $family->setRelation('variantGroups', collect([$length, $pack, $colour]));

        $family->setRelation('products', collect([
            $this->makeProduct(1, [10 => $length16, 20 => $pack3x, 30 => $colourRed]),
            $this->makeProduct(2, [10 => $length20, 20 => $pack3x, 30 => $colourRed]),
        ]));

        $combos = RetailFamilySellableCombinations::forNewVariantOptions(
            $family,
            collect([$colourGrey->id]),
            [$length16->id],
        );

        $this->assertCount(1, $combos);

        $labels = collect($combos[0])
            ->mapWithKeys(fn (ProductVariantOption $option): array => [
                (int) $option->product_variant_group_id => $option->label,
            ])
            ->all();

        $this->assertSame('16"', $labels[10]);
        $this->assertSame('3X', $labels[20]);
        $this->assertSame('GREY', $labels[30]);
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
            $group = $option->product_variant_group_id === $groupId
                ? new ProductVariantGroup(['id' => $groupId, 'name' => 'G'.$groupId])
                : null;
            if ($group) {
                $value->setRelation('group', $group);
            }
            $values->push($value);
        }

        $product->setRelation('variantValues', $values);

        return $product;
    }
}
