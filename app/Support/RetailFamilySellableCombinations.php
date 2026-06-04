<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductVariantOption;
use Illuminate\Support\Collection;

/**
 * Builds sellable SKU variant combinations when new values are added on a family.
 * Uses existing products as templates (main + common axes) and swaps in each new value.
 */
final class RetailFamilySellableCombinations
{
    /**
     * @param  Collection<int, int>  $newOptionIds
     * @return list<list<ProductVariantOption>>
     */
    public static function forNewVariantOptions(ProductFamily $family, Collection $newOptionIds): array
    {
        $family->loadMissing([
            'variantGroups.options',
            'products.variantValues.option',
            'products.variantValues.group',
        ]);

        $newOptionIds = $newOptionIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($newOptionIds->isEmpty() || $family->variantGroups->isEmpty()) {
            return [];
        }

        $newOptions = ProductVariantOption::query()
            ->whereIn('id', $newOptionIds->all())
            ->whereIn('product_variant_group_id', $family->variantGroups->pluck('id'))
            ->get();

        if ($newOptions->isEmpty()) {
            return [];
        }

        if ($family->products->isEmpty()) {
            return self::cartesianIncludingAnyOption($family, $newOptionIds);
        }

        $combos = [];
        $seen = [];

        foreach ($newOptions as $newOption) {
            $targetGroupId = (int) $newOption->product_variant_group_id;
            $targetGroup = $family->variantGroups->firstWhere('id', $targetGroupId);

            if ($targetGroup === null) {
                continue;
            }

            $newOption->setRelation('group', $targetGroup);

            foreach ($family->products as $product) {
                $combo = self::comboFromProductTemplate($family, $product, $targetGroupId, $newOption);

                if ($combo === null) {
                    continue;
                }

                $signature = collect($combo)
                    ->map(fn (ProductVariantOption $option): int => (int) $option->id)
                    ->sort()
                    ->values()
                    ->implode(',');

                if (isset($seen[$signature])) {
                    continue;
                }

                $seen[$signature] = true;
                $combos[] = $combo;
            }
        }

        return $combos;
    }

    /**
     * @return list<ProductVariantOption>|null
     */
    private static function comboFromProductTemplate(
        ProductFamily $family,
        Product $product,
        int $targetGroupId,
        ProductVariantOption $newOption,
    ): ?array {
        $optionByGroup = [];

        foreach ($product->variantValues as $value) {
            if (! $value->option) {
                continue;
            }

            $optionByGroup[(int) $value->product_variant_group_id] = $value->option;
        }

        $combo = [];

        foreach ($family->variantGroups as $group) {
            $groupId = (int) $group->id;

            if ($groupId === $targetGroupId) {
                $combo[] = $newOption;

                continue;
            }

            $picked = $optionByGroup[$groupId] ?? self::familyWideSingleOption($family, $groupId);

            if (! $picked instanceof ProductVariantOption) {
                return null;
            }

            $picked->setRelation('group', $group);
            $combo[] = $picked;
        }

        return count($combo) === $family->variantGroups->count() ? $combo : null;
    }

    private static function familyWideSingleOption(ProductFamily $family, int $groupId): ?ProductVariantOption
    {
        $optionIds = $family->products
            ->flatMap(fn (Product $product) => $product->variantValues)
            ->where('product_variant_group_id', $groupId)
            ->pluck('product_variant_option_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($optionIds->count() !== 1) {
            return null;
        }

        return ProductVariantOption::query()->find($optionIds->first());
    }

    /**
     * @param  Collection<int, int>  $newOptionIds
     * @return list<list<ProductVariantOption>>
     */
    private static function cartesianIncludingAnyOption(ProductFamily $family, Collection $newOptionIds): array
    {
        $combinations = [[]];

        foreach ($family->variantGroups as $group) {
            $next = [];
            foreach ($combinations as $combo) {
                foreach ($group->options as $option) {
                    $option->setRelation('group', $group);
                    $next[] = array_merge($combo, [$option]);
                }
            }
            $combinations = $next;
        }

        return array_values(array_filter(
            $combinations,
            fn (array $combo): bool => collect($combo)->contains(
                fn (ProductVariantOption $option): bool => $newOptionIds->contains((int) $option->id),
            ),
        ));
    }
}
