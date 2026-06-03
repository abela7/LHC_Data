<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProductFamily;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Links retail product families back to a brand catalogue style
 * (including split buckets scoped with catalogue_scope_key).
 */
final class RetailStyleFamilyCatalogue
{
    /**
     * @return Collection<int, ProductFamily>
     */
    public static function familiesForStyle(int $styleId): Collection
    {
        if ($styleId <= 0) {
            return collect();
        }

        return ProductFamily::query()
            ->where('brand_catalogue_style_id', $styleId)
            ->withCount('products')
            ->orderByRaw('catalogue_scope_key IS NULL DESC')
            ->orderBy('catalogue_scope_key')
            ->orderBy('id')
            ->get();
    }

    public static function scopeLabel(?string $scopeKey): string
    {
        if ($scopeKey === null || trim($scopeKey) === '') {
            return 'Main';
        }

        if (str_starts_with($scopeKey, 'split-')) {
            return Str::title(str_replace('-', ' ', substr($scopeKey, 6)));
        }

        return Str::title(str_replace('-', ' ', $scopeKey));
    }

    /**
     * @param  Collection<int, ProductFamily>  $families
     */
    public static function primaryFamily(Collection $families): ?ProductFamily
    {
        if ($families->isEmpty()) {
            return null;
        }

        return $families
            ->sortByDesc(fn (ProductFamily $family): int => (int) $family->products_count)
            ->first(fn (ProductFamily $family): bool => (int) $family->products_count > 0)
            ?? $families->first();
    }

    /**
     * One representative family per style for list cards, with aggregate counts attached.
     *
     * @param  array<int, int>  $styleIds
     * @return Collection<int, ProductFamily>
     */
    public static function primaryFamilyByStyleIds(array $styleIds): Collection
    {
        $styleIds = collect($styleIds)->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();

        if ($styleIds->isEmpty()) {
            return collect();
        }

        return ProductFamily::query()
            ->whereIn('brand_catalogue_style_id', $styleIds->all())
            ->withCount('products')
            ->orderByRaw('catalogue_scope_key IS NULL DESC')
            ->orderBy('catalogue_scope_key')
            ->orderBy('id')
            ->get()
            ->groupBy('brand_catalogue_style_id')
            ->map(function (Collection $families): ProductFamily {
                $primary = self::primaryFamily($families);
                $primary->setAttribute('retail_families_count', $families->count());
                $primary->setAttribute('retail_products_total', $families->sum(
                    fn (ProductFamily $family): int => (int) $family->products_count,
                ));

                return $primary;
            });
    }
}
