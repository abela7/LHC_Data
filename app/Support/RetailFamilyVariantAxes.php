<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductVariantGroup;
use App\Models\ProductVariantOption;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Main / common / sub variant axes for a retail family (mirrors SKU list grouping).
 */
final class RetailFamilyVariantAxes
{
    /**
     * @param  Collection<int, int>  $commonGroupIds
     * @param  Collection<int, int>  $subGroupIds
     */
    public function __construct(
        public readonly ?ProductVariantGroup $mainGroup,
        public readonly Collection $commonGroupIds,
        public readonly Collection $subGroupIds,
    ) {}

    public static function forFamily(ProductFamily $family, Collection $products): self
    {
        if (! $family->relationLoaded('variantGroups')) {
            $family->loadMissing('variantGroups');
        }

        $allVariantValues = $products->flatMap(fn (Product $product) => $product->variantValues);

        $commonGroupIds = $family->variantGroups
            ->filter(function (ProductVariantGroup $group) use ($allVariantValues, $products): bool {
                if ($products->count() <= 1) {
                    return self::shouldPinAsCommon($group);
                }

                return self::distinctOptionCount($allVariantValues, (int) $group->id) <= 1
                    || self::shouldPinAsCommon($group);
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip();

        $sharedForGrouping = $family->variantGroups
            ->filter(function (ProductVariantGroup $group) use ($allVariantValues, $products): bool {
                if ($products->count() <= 1) {
                    return false;
                }

                return self::distinctOptionCount($allVariantValues, (int) $group->id) <= 1;
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip();

        $mainGroup = self::resolveMainGroup($family, $products, $allVariantValues, $sharedForGrouping);

        $mainGroupId = $mainGroup?->id;

        $subGroupIds = $family->variantGroups
            ->filter(function (ProductVariantGroup $group) use ($commonGroupIds, $mainGroupId): bool {
                $groupId = (int) $group->id;

                if ($commonGroupIds->has($groupId)) {
                    return false;
                }

                if ($mainGroupId !== null && $groupId === $mainGroupId) {
                    return false;
                }

                return true;
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        return new self($mainGroup, $commonGroupIds, $subGroupIds);
    }

    public function isCommonGroup(int $groupId): bool
    {
        return $this->commonGroupIds->has($groupId);
    }

    public function isSubGroup(int $groupId): bool
    {
        return $this->subGroupIds->contains($groupId);
    }

    public function isMainGroup(int $groupId): bool
    {
        return $this->mainGroup !== null && (int) $this->mainGroup->id === $groupId;
    }

    /**
     * Pack / bundle axes stay fixed when adding a new colour (sub variant).
     *
     * @return array<int, ProductVariantOption> groupId => option
     */
    public function pinnedCommonOptions(
        ProductFamily $family,
        Collection $products,
        ?ProductVariantOption $newOption = null,
    ): array {
        $pinned = [];

        $groups = $family->relationLoaded('variantGroups')
            ? $family->variantGroups
            : $family->variantGroups()->get();

        foreach ($groups as $group) {
            $groupId = (int) $group->id;

            if (! $this->isCommonGroup($groupId)) {
                continue;
            }

            $option = self::resolvePinnedOption($family, $products, $group, $newOption);

            if ($option instanceof ProductVariantOption) {
                $option->setRelation('group', $group);
                $pinned[$groupId] = $option;
            }
        }

        return $pinned;
    }

    /**
     * Products that already use the family's pinned common values (e.g. Bundle 3x + Pack 3X).
     *
     * @param  array<int, ProductVariantOption>  $pinnedCommon
     * @return Collection<int, Product>
     */
    public function referenceProducts(Collection $products, array $pinnedCommon): Collection
    {
        if ($pinnedCommon === []) {
            return $products->values();
        }

        return $products->filter(function (Product $product) use ($pinnedCommon): bool {
            foreach ($pinnedCommon as $groupId => $option) {
                $value = $product->variantValues
                    ->firstWhere('product_variant_group_id', $groupId);

                if ((int) ($value?->product_variant_option_id ?? 0) !== (int) $option->id) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Distinct main-axis assignments on reference products (e.g. Length 20" only → one set).
     *
     * @param  array<int, ProductVariantOption>  $pinnedCommon
     * @return list<array<int, ProductVariantOption>> groupId => option per distinct main row
     */
    public function distinctMainOptionSets(
        ProductFamily $family,
        Collection $referenceProducts,
        array $pinnedCommon,
    ): array {
        if ($this->mainGroup === null) {
            return [];
        }

        $mainGroupId = (int) $this->mainGroup->id;
        $seen = [];
        $sets = [];

        foreach ($referenceProducts as $product) {
            $mainOption = $product->variantValues
                ->firstWhere('product_variant_group_id', $mainGroupId)
                ?->option;

            if (! $mainOption instanceof ProductVariantOption) {
                continue;
            }

            $signature = (int) $mainOption->id;

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $mainOption->setRelation('group', $this->mainGroup);

            $row = $this->buildRowFromReferenceProduct($family, $product, $pinnedCommon, $mainGroupId);
            $row[$mainGroupId] = $mainOption;
            $sets[] = $row;
        }

        return $sets;
    }

    /**
     * @param  array<int, ProductVariantOption>  $pinnedCommon
     * @param  array<int, ProductVariantOption>  $rowOptions
     * @return list<ProductVariantOption>|null
     */
    public function assembleCombo(
        ProductFamily $family,
        ProductVariantOption $newOption,
        int $targetGroupId,
        array $pinnedCommon,
        array $rowOptions,
    ): ?array {
        $combo = [];

        foreach ($family->variantGroups as $group) {
            $groupId = (int) $group->id;

            if ($groupId === $targetGroupId) {
                $newOption->setRelation('group', $group);
                $combo[] = $newOption;

                continue;
            }

            if (isset($pinnedCommon[$groupId])) {
                $combo[] = $pinnedCommon[$groupId];

                continue;
            }

            if (isset($rowOptions[$groupId])) {
                $rowOptions[$groupId]->setRelation('group', $group);
                $combo[] = $rowOptions[$groupId];

                continue;
            }

            return null;
        }

        return count($combo) === $family->variantGroups->count() ? $combo : null;
    }

    /**
     * @param  array<int, ProductVariantOption>  $pinnedCommon
     * @return array<int, ProductVariantOption>
     */
    private function buildRowFromReferenceProduct(
        ProductFamily $family,
        Product $product,
        array $pinnedCommon,
        int $mainGroupId,
    ): array {
        $row = [];

        foreach ($family->variantGroups as $group) {
            $groupId = (int) $group->id;

            if ($groupId === $mainGroupId || isset($pinnedCommon[$groupId])) {
                continue;
            }

            if (! $this->isSubGroup($groupId)) {
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
     * @param  Collection<int, \App\Models\ProductVariantValue>  $allVariantValues
     * @param  Collection<int, int>  $sharedGroupIds
     */
    private static function resolveMainGroup(
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
                'score' => self::scoreMainCandidate($group, $allVariantValues, $products, $sharedGroupIds),
            ])
            ->sortByDesc('score')
            ->values();

        $best = $scored->first(fn (array $row): bool => $row['score'] > 0);

        if ($best !== null) {
            return $best['group'];
        }

        return $sortedGroups->first(fn (ProductVariantGroup $group): bool => self::isLengthAxis($group))
            ?? $sortedGroups->first(fn (ProductVariantGroup $group): bool => ! self::isTypicalSubAxis($group))
            ?? null;
    }

    /**
     * @param  Collection<int, \App\Models\ProductVariantValue>  $allVariantValues
     * @param  Collection<int, int>  $sharedGroupIds
     */
    private static function scoreMainCandidate(
        ProductVariantGroup $group,
        Collection $allVariantValues,
        Collection $products,
        Collection $sharedGroupIds,
    ): int {
        $groupId = (int) $group->id;
        $distinct = self::distinctOptionCount($allVariantValues, $groupId);

        if ($sharedGroupIds->has($groupId) || self::shouldPinAsCommon($group)) {
            return -100;
        }

        if (self::isTypicalSubAxis($group) && ! self::isLengthAxis($group)) {
            return -80;
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

        return $score;
    }

    public static function shouldPinAsCommon(ProductVariantGroup $group): bool
    {
        $name = Str::lower(trim($group->name));
        $type = Str::lower(trim((string) $group->variant_type));

        foreach (['bundle', 'pack', 'package', 'count', 'quantity', 'size'] as $needle) {
            if ($name === $needle || str_contains($name, $needle)) {
                return true;
            }
        }

        foreach (['count', 'pack', 'bundle', 'quantity'] as $needle) {
            if (str_contains($type, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function resolvePinnedOption(
        ProductFamily $family,
        Collection $products,
        ProductVariantGroup $group,
        ?ProductVariantOption $newOption,
    ): ?ProductVariantOption {
        $single = self::familyWideSingleOption($family, $products, (int) $group->id);

        if ($single instanceof ProductVariantOption) {
            return $single;
        }

        $counts = [];

        foreach ($products as $product) {
            $optionId = (int) ($product->variantValues
                ->firstWhere('product_variant_group_id', $group->id)
                ?->product_variant_option_id ?? 0);

            if ($optionId > 0) {
                $counts[$optionId] = ($counts[$optionId] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        $options = $family->relationLoaded('variantGroups')
            ? $family->variantGroups->flatMap(fn (ProductVariantGroup $group) => $group->options)
            : collect();

        $options = $options
            ->whereIn('id', array_keys($counts))
            ->keyBy('id');

        $pickedId = 0;
        $bestScore = PHP_INT_MIN;
        $bestCount = -1;

        foreach ($counts as $optionId => $count) {
            $score = self::scorePinnedCandidate($options->get((int) $optionId), $newOption, $group);

            if ($score > $bestScore || ($score === $bestScore && $count > $bestCount)) {
                $bestScore = $score;
                $bestCount = $count;
                $pickedId = (int) $optionId;
            }
        }

        return $options->get($pickedId);
    }

    private static function scorePinnedCandidate(
        ?ProductVariantOption $option,
        ?ProductVariantOption $newOption,
        ProductVariantGroup $group,
    ): int {
        if (! $option instanceof ProductVariantOption) {
            return -999;
        }

        $score = 0;

        if ($newOption !== null && strcasecmp(trim($option->label), trim($newOption->label)) === 0) {
            $score -= 500;
        }

        if (self::isTypicalSubAxis($group)) {
            $score -= 200;
        }

        $type = Str::lower(trim((string) $group->variant_type));

        if (str_contains($type, 'colour') || str_contains($type, 'color')) {
            $score -= 300;
        }

        return $score;
    }

    private static function familyWideSingleOption(
        ProductFamily $family,
        Collection $products,
        int $groupId,
    ): ?ProductVariantOption {
        $optionIds = $products
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

        $optionId = (int) $optionIds->first();

        if (! $family->relationLoaded('variantGroups')) {
            return ProductVariantOption::query()->find($optionId);
        }

        return $family->variantGroups
            ->flatMap(fn (ProductVariantGroup $group) => $group->options)
            ->firstWhere('id', $optionId);
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
        $type = Str::lower(trim((string) $group->variant_type));

        if (in_array($type, ['colour_name', 'colour_code', 'short_code'], true)) {
            return true;
        }

        foreach (['colour', 'color', 'shade', 'tone'] as $needle) {
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
