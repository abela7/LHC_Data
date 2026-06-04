<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductVariantOption;
use Illuminate\Support\Collection;

/**
 * Builds sellable SKU variant combinations when new values are added on a family.
 * Respects main / common / sub axes: new sub values (e.g. Colour Grey) pair with
 * pinned common variants (Bundle 3x, Pack 3X) and each distinct main value (e.g. 20").
 */
final class RetailFamilySellableCombinations
{
    /**
     * Stable signature: one slot per variant group on the family (groupId:optionId).
     */
    public static function variantSignature(ProductFamily $family, Collection $options): string
    {
        $byGroup = $options->keyBy(
            fn (ProductVariantOption $option): int => (int) $option->product_variant_group_id,
        );

        return $family->variantGroups
            ->sortBy('sort_order')
            ->map(function ($group) use ($byGroup): string {
                $groupId = (int) $group->id;
                $optionId = (int) ($byGroup->get($groupId)?->id ?? 0);

                return "{$groupId}:{$optionId}";
            })
            ->implode('|');
    }

    public static function variantSignatureFromProduct(ProductFamily $family, Product $product): string
    {
        $byGroup = [];

        foreach ($product->variantValues as $value) {
            $byGroup[(int) $value->product_variant_group_id] = (int) $value->product_variant_option_id;
        }

        return $family->variantGroups
            ->sortBy('sort_order')
            ->map(function ($group) use ($byGroup): string {
                $groupId = (int) $group->id;

                return "{$groupId}:".($byGroup[$groupId] ?? 0);
            })
            ->implode('|');
    }

    /**
     * @param  list<ProductVariantOption>  $combo
     */
    public static function findProductForCombo(ProductFamily $family, array $combo): ?Product
    {
        $target = self::variantSignature($family, collect($combo));

        return $family->products->first(
            fn (Product $product): bool => self::variantSignatureFromProduct($family, $product) === $target,
        );
    }

    /**
     * @param  Collection<int, int>  $newOptionIds
     * @param  list<int>  $mainOptionIds  Restrict new sub SKUs to these main values
     *                                    (e.g. only Length 20"). Empty = every main value.
     * @return list<list<ProductVariantOption>>
     */
    public static function forNewVariantOptions(ProductFamily $family, Collection $newOptionIds, array $mainOptionIds = []): array
    {
        if (! $family->relationLoaded('variantGroups')) {
            $family->loadMissing(['variantGroups.options']);
        } else {
            foreach ($family->variantGroups as $group) {
                if (! $group->relationLoaded('options')) {
                    $group->loadMissing('options');
                }
            }
        }

        $family->products->each(function (Product $product): void {
            if (! $product->relationLoaded('variantValues')) {
                $product->loadMissing(['variantValues.option', 'variantValues.group']);
            }
        });

        $newOptionIds = $newOptionIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($newOptionIds->isEmpty() || $family->variantGroups->isEmpty()) {
            return [];
        }

        $newOptions = $family->variantGroups
            ->flatMap(fn ($group) => $group->options)
            ->whereIn('id', $newOptionIds->all())
            ->values();

        if ($newOptions->isEmpty()) {
            $newOptions = ProductVariantOption::query()
                ->whereIn('id', $newOptionIds->all())
                ->whereIn('product_variant_group_id', $family->variantGroups->pluck('id'))
                ->get();
        }

        if ($newOptions->isEmpty()) {
            return [];
        }

        if ($family->products->isEmpty()) {
            return self::cartesianIncludingAnyOption($family, $newOptionIds);
        }

        $products = $family->products;
        $axes = RetailFamilyVariantAxes::forFamily($family, $products);
        $combos = [];
        $seen = [];

        foreach ($newOptions as $newOption) {
            $targetGroupId = (int) $newOption->product_variant_group_id;
            $targetGroup = $family->variantGroups->firstWhere('id', $targetGroupId);

            if ($targetGroup === null) {
                continue;
            }

            $newOption->setRelation('group', $targetGroup);

            foreach (self::combosForNewOption($family, $products, $axes, $newOption, $mainOptionIds) as $combo) {
                $signature = self::variantSignature($family, collect($combo));

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
     * @return list<list<ProductVariantOption>>
     */
    private static function combosForNewOption(
        ProductFamily $family,
        Collection $products,
        RetailFamilyVariantAxes $axes,
        ProductVariantOption $newOption,
        array $mainOptionIds = [],
    ): array {
        $targetGroupId = (int) $newOption->product_variant_group_id;

        if ($axes->isMainGroup($targetGroupId)) {
            return self::combosForNewMainOption($family, $products, $axes, $newOption);
        }

        if ($axes->isCommonGroup($targetGroupId)) {
            return self::combosForNewCommonOption($family, $products, $axes, $newOption);
        }

        return self::combosForNewSubOption($family, $products, $axes, $newOption, $mainOptionIds);
    }

    /**
     * New sub variant (e.g. Colour Grey): one sellable per main value, common axes pinned.
     *
     * @return list<list<ProductVariantOption>>
     */
    private static function combosForNewSubOption(
        ProductFamily $family,
        Collection $products,
        RetailFamilyVariantAxes $axes,
        ProductVariantOption $newOption,
        array $mainOptionIds = [],
    ): array {
        $targetGroupId = (int) $newOption->product_variant_group_id;
        $pinnedCommon = $axes->pinnedCommonOptions($family, $products, $newOption);
        $references = $axes->referenceProducts($products, $pinnedCommon);
        $mainRows = $axes->distinctMainOptionSets($family, $references, $pinnedCommon);

        if ($mainRows === []) {
            $mainRows = [self::inferSubRowFromReferences($family, $axes, $references, $targetGroupId)];
        }

        // Restrict to the chosen main values (e.g. only Length 20") when asked.
        $mainOptionIds = array_values(array_filter(array_map('intval', $mainOptionIds)));
        if ($mainOptionIds !== [] && $axes->mainGroup !== null) {
            $mainGroupId = (int) $axes->mainGroup->id;
            $mainRows = array_values(array_filter(
                $mainRows,
                fn (array $row): bool => in_array((int) ($row[$mainGroupId]?->id ?? 0), $mainOptionIds, true),
            ));
        }

        $combos = [];

        foreach ($mainRows as $row) {
            $combo = $axes->assembleCombo($family, $newOption, $targetGroupId, $pinnedCommon, $row);

            if ($combo !== null) {
                $combos[] = $combo;
            }
        }

        return $combos;
    }

    /**
     * @return list<list<ProductVariantOption>>
     */
    private static function combosForNewMainOption(
        ProductFamily $family,
        Collection $products,
        RetailFamilyVariantAxes $axes,
        ProductVariantOption $newOption,
    ): array {
        $targetGroupId = (int) $newOption->product_variant_group_id;
        $pinnedCommon = $axes->pinnedCommonOptions($family, $products, $newOption);
        $references = $axes->referenceProducts($products, $pinnedCommon);
        $seen = [];
        $combos = [];

        foreach ($references as $product) {
            $row = self::inferSubRowFromReferences($family, $axes, collect([$product]), $targetGroupId);
            $combo = $axes->assembleCombo($family, $newOption, $targetGroupId, $pinnedCommon, $row);

            if ($combo === null) {
                continue;
            }

            $signature = self::variantSignature($family, collect($combo));

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $combos[] = $combo;
        }

        if ($combos === [] && $pinnedCommon !== []) {
            $combo = $axes->assembleCombo($family, $newOption, $targetGroupId, $pinnedCommon, []);

            if ($combo !== null) {
                $combos[] = $combo;
            }
        }

        return $combos;
    }

    /**
     * @return list<list<ProductVariantOption>>
     */
    private static function combosForNewCommonOption(
        ProductFamily $family,
        Collection $products,
        RetailFamilyVariantAxes $axes,
        ProductVariantOption $newOption,
    ): array {
        $targetGroupId = (int) $newOption->product_variant_group_id;
        $pinnedCommon = $axes->pinnedCommonOptions($family, $products, $newOption);
        unset($pinnedCommon[$targetGroupId]);
        $pinnedCommon[$targetGroupId] = $newOption;

        $references = $axes->referenceProducts($products, $pinnedCommon);
        $seen = [];
        $combos = [];

        foreach ($references as $product) {
            $row = self::inferSubRowFromReferences($family, $axes, collect([$product]), $targetGroupId);

            if ($axes->mainGroup !== null) {
                $mainOption = $product->variantValues
                    ->firstWhere('product_variant_group_id', $axes->mainGroup->id)
                    ?->option;

                if ($mainOption instanceof ProductVariantOption) {
                    $row[(int) $axes->mainGroup->id] = $mainOption;
                }
            }

            $combo = $axes->assembleCombo($family, $newOption, $targetGroupId, $pinnedCommon, $row);

            if ($combo === null) {
                continue;
            }

            $signature = self::variantSignature($family, collect($combo));

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $combos[] = $combo;
        }

        if ($combos === []) {
            $combo = $axes->assembleCombo($family, $newOption, $targetGroupId, $pinnedCommon, []);

            if ($combo !== null) {
                $combos[] = $combo;
            }
        }

        return $combos;
    }

    /**
     * When the family has no main axis on reference SKUs, keep other sub-axis values
     * from a representative product (excluding the target sub being created).
     *
     * @return array<int, ProductVariantOption>
     */
    private static function inferSubRowFromReferences(
        ProductFamily $family,
        RetailFamilyVariantAxes $axes,
        Collection $references,
        int $targetGroupId,
    ): array {
        $product = $references->first();

        if (! $product instanceof Product) {
            return [];
        }

        $row = [];

        foreach ($family->variantGroups as $group) {
            $groupId = (int) $group->id;

            if ($groupId === $targetGroupId || $axes->isCommonGroup($groupId) || $axes->isMainGroup($groupId)) {
                continue;
            }

            if (! $axes->isSubGroup($groupId)) {
                continue;
            }

            $option = $product->variantValues
                ->firstWhere('product_variant_group_id', $groupId)
                ?->option;

            if ($option instanceof ProductVariantOption) {
                $option->setRelation('group', $group);
                $row[$groupId] = $option;
            }
        }

        return $row;
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
