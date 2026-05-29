<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductVariantGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Groups sellable SKUs on the family page by the best "main" variant axis
 * (Length for hair extensions), with common/shared axes shown in headers only.
 */
final class RetailFamilySkuGrouper
{
    /**
     * @param  Collection<int, Product>  $products
     * @return array{
     *     grouping_group: ?ProductVariantGroup,
     *     shared_group_ids: Collection<int, int>,
     *     family_common_labels: Collection<int, string>,
     *     sku_groups: Collection<int, array{label: string, sort_order: int, products: Collection<int, Product>}>,
     *     use_accordions: bool,
     * }
     */
    public static function forFamily(ProductFamily $family, Collection $products): array
    {
        $allVariantValues = $products->flatMap(fn (Product $product) => $product->variantValues);

        $sharedGroupIds = $family->variantGroups
            ->filter(function (ProductVariantGroup $group) use ($allVariantValues, $products): bool {
                if ($products->count() <= 1) {
                    return false;
                }

                return self::distinctOptionCount($allVariantValues, $group->id) <= 1;
            })
            ->pluck('id')
            ->flip();

        $groupingGroup = self::resolveGroupingGroup($family, $products, $allVariantValues, $sharedGroupIds);

        $familyCommonLabels = $family->variantGroups
            ->sortBy(fn (ProductVariantGroup $group): string => VariantNaturalSort::groupKey($group))
            ->filter(fn (ProductVariantGroup $group): bool => $sharedGroupIds->has($group->id))
            ->map(function (ProductVariantGroup $group) use ($allVariantValues): ?string {
                $match = $allVariantValues->where('product_variant_group_id', $group->id)->first();

                return $match ? $group->name.': '.$match->option->label : null;
            })
            ->filter()
            ->values();

        $skuGroups = collect();
        if ($groupingGroup !== null && $products->isNotEmpty()) {
            foreach ($products as $product) {
                $variantMatch = $product->variantValues
                    ->firstWhere('product_variant_group_id', $groupingGroup->id);
                $option = $variantMatch?->option;
                $groupKey = $option ? (string) $option->id : '__unassigned__';
                $groupLabel = $option?->label ?? ('No '.$groupingGroup->name);

                if (! $skuGroups->has($groupKey)) {
                    $skuGroups->put($groupKey, [
                        'label' => $groupLabel,
                        'sort_order' => (int) ($option?->sort_order ?? 9999),
                        'sort_key' => VariantNaturalSort::valueKey($groupLabel),
                        'products' => collect(),
                    ]);
                }

                $skuGroups[$groupKey]['products']->push($product);
            }

            $skuGroups = $skuGroups
                ->map(function (array $group) use ($family): array {
                    $group['products'] = $group['products']
                        ->sortBy(fn (Product $product): string => VariantNaturalSort::productKey($product, $family->variantGroups))
                        ->values();

                    return $group;
                })
                ->sortBy(fn (array $group): array => [$group['sort_key'], $group['sort_order']])
                ->values();
        } else {
            $skuGroups = collect([[
                'label' => $family->family_name,
                'sort_order' => 0,
                'sort_key' => '0',
                'products' => $products
                    ->sortBy(fn (Product $product): string => VariantNaturalSort::productKey($product, $family->variantGroups))
                    ->values(),
            ]]);
        }

        return [
            'grouping_group' => $groupingGroup,
            'shared_group_ids' => $sharedGroupIds,
            'family_common_labels' => $familyCommonLabels,
            'sku_groups' => $skuGroups,
            'use_accordions' => $groupingGroup !== null && $skuGroups->count() > 1,
        ];
    }

    /**
     * @param  Collection<int, \App\Models\ProductVariantValue>  $allVariantValues
     * @param  Collection<int, int>  $sharedGroupIds
     */
    private static function resolveGroupingGroup(
        ProductFamily $family,
        Collection $products,
        Collection $allVariantValues,
        Collection $sharedGroupIds,
    ): ?ProductVariantGroup {
        $sortedGroups = $family->variantGroups->sortBy('sort_order')->values();

        if ($sortedGroups->isEmpty()) {
            return null;
        }

        $scored = $sortedGroups
            ->map(fn (ProductVariantGroup $group): array => [
                'group' => $group,
                'score' => self::scoreGroupingCandidate($group, $allVariantValues, $products, $sharedGroupIds),
            ])
            ->sortByDesc('score')
            ->values();

        $best = $scored->first(fn (array $row): bool => $row['score'] > 0);

        return $best['group'] ?? $sortedGroups->first();
    }

    /**
     * @param  Collection<int, \App\Models\ProductVariantValue>  $allVariantValues
     * @param  Collection<int, int>  $sharedGroupIds
     */
    private static function scoreGroupingCandidate(
        ProductVariantGroup $group,
        Collection $allVariantValues,
        Collection $products,
        Collection $sharedGroupIds,
    ): int {
        $distinct = self::distinctOptionCount($allVariantValues, $group->id);
        $isFamilyCommon = $sharedGroupIds->has($group->id);

        if ($isFamilyCommon && $distinct <= 1) {
            return -100;
        }

        $score = 0;

        if (self::isLengthAxis($group)) {
            $score += 1000;
        }

        if ($distinct >= 2) {
            $score += 200 + min($distinct, 40);
        } elseif ($products->count() <= 1) {
            $score += 20;
        } else {
            $score += 10;
        }

        $score += max(0, 90 - (int) $group->sort_order);

        if (self::isTypicalSubAxis($group)) {
            $score -= 80;
        }

        return $score;
    }

    private static function isLengthAxis(ProductVariantGroup $group): bool
    {
        $name = Str::lower(trim($group->name));
        $type = Str::lower(trim((string) $group->variant_type));

        if ($name === 'length' || $type === 'length') {
            return true;
        }

        return (bool) preg_match('/\b(length|len)\b/i', $group->name);
    }

    private static function isTypicalSubAxis(ProductVariantGroup $group): bool
    {
        $name = Str::lower(trim($group->name));

        foreach (['colour', 'color', 'width', 'pack', 'package', 'texture', 'style', 'material'] as $needle) {
            if ($name === $needle || str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, \App\Models\ProductVariantValue>  $allVariantValues
     */
    private static function distinctOptionCount(Collection $allVariantValues, int $groupId): int
    {
        return $allVariantValues
            ->where('product_variant_group_id', $groupId)
            ->pluck('product_variant_option_id')
            ->unique()
            ->count();
    }

}
