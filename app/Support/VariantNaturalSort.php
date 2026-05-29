<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariantGroup;
use App\Models\ProductVariantOption;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class VariantNaturalSort
{
    public static function valueKey(?string $label): string
    {
        $value = self::sortValue($label);

        if (preg_match('/^(\d+(?:\.\d+)?)/', $value, $matches)) {
            $number = str_pad((string) ((int) round((float) $matches[1] * 100)), 10, '0', STR_PAD_LEFT);

            return '0:'.$number.':'.$value;
        }

        return '1:'.$value;
    }

    public static function groupKey(ProductVariantGroup $group): string
    {
        return sprintf('%04d:%s', (int) $group->sort_order, self::sortValue($group->name));
    }

    /**
     * @param  Collection<int, ProductVariantGroup>  $groups
     */
    public static function productKey(Product $product, Collection $groups): string
    {
        $parts = [];

        foreach ($groups as $group) {
            $value = $product->variantValues
                ->first(fn ($variantValue): bool => (int) $variantValue->product_variant_group_id === (int) $group->id);

            $parts[] = self::valueKey($value?->option?->label);
        }

        return implode('|', $parts).'|'.self::sortValue($product->name).'|'.str_pad((string) $product->id, 10, '0', STR_PAD_LEFT);
    }

    /**
     * @param  Collection<int, ProductVariantOption>  $options
     * @return Collection<int, ProductVariantOption>
     */
    public static function sortOptions(Collection $options): Collection
    {
        return $options
            ->sortBy(fn (ProductVariantOption $option): string => self::valueKey($option->label))
            ->values();
    }

    /**
     * @param  Collection<int, ProductVariantGroup>  $groups
     * @return Collection<int, ProductVariantGroup>
     */
    public static function sortGroups(Collection $groups): Collection
    {
        return $groups
            ->sortBy(fn (ProductVariantGroup $group): string => self::groupKey($group))
            ->values();
    }

    private static function sortValue(?string $label): string
    {
        $value = Str::of((string) $label)
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->lower()
            ->toString();

        if (str_contains($value, ':')) {
            $parts = explode(':', $value);
            $value = trim((string) end($parts));
        }

        $value = preg_replace('/^(colour|color|length|len|size|pack|bundle)\s+/i', '', $value) ?: $value;
        $value = preg_replace('/^(no|number|shade)\.?\s+/i', '', $value) ?: $value;
        $value = trim($value, " \t\n\r\0\x0B\"'");

        return $value;
    }
}
