<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariant;
use App\Models\BrandCatalogueVariantOption;
use App\Models\ProductFamily;
use App\Models\ProductSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
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
    public static function familiesForStyle(int $styleId, bool $repairOrphans = true): Collection
    {
        if ($styleId <= 0) {
            return collect();
        }

        if ($repairOrphans) {
            self::relinkSplitOrphansForStyle($styleId);
        }

        $families = ProductFamily::query()
            ->where('brand_catalogue_style_id', $styleId)
            ->withCount('products')
            ->orderByRaw('catalogue_scope_key IS NULL DESC')
            ->orderBy('catalogue_scope_key')
            ->orderBy('id')
            ->get();

        if ($families->isNotEmpty()) {
            $families->load('variantGroups.options');
        }

        return $families;
    }

    public static function splitScopeKey(string $axisName, string $optionLabel): string
    {
        $base = Str::slug($axisName.'-'.$optionLabel);

        return Str::limit('split-'.($base !== '' ? $base : 'option'), 120, '');
    }

    public static function scopeLabel(?string $scopeKey): string
    {
        if ($scopeKey === null || trim($scopeKey) === '') {
            return 'Main';
        }

        if (str_starts_with($scopeKey, 'split-')) {
            $slug = substr($scopeKey, 6);

            if (preg_match('/(?:^|-)(\d{1,3})(?:-|$)/', $slug, $matches)) {
                return $matches[1].'"';
            }

            $readable = str_replace('-', ' ', $slug);

            if (preg_match('/^length\s+(\d+)$/i', trim($readable), $matches)) {
                return $matches[1].'"';
            }

            return Str::title($readable);
        }

        return Str::title(str_replace('-', ' ', $scopeKey));
    }

    /**
     * Prefer catalogue option label (e.g. 20") for sidebar pills.
     *
     * @param  Collection<int, BrandCatalogueVariant>  $catalogueVariants
     */
    public static function familyDisplayLabel(ProductFamily $family, Collection $catalogueVariants): string
    {
        if (! filled($family->catalogue_scope_key)) {
            return 'Main';
        }

        foreach ($catalogueVariants as $variant) {
            foreach ($variant->options as $option) {
                if (self::splitScopeKey($variant->name, $option->label) === $family->catalogue_scope_key) {
                    return $option->label;
                }
            }
        }

        return self::scopeLabel($family->catalogue_scope_key);
    }

    /**
     * Map catalogue variant option id → linked retail family (if any).
     *
     * @param  Collection<int, BrandCatalogueVariant>  $catalogueVariants
     * @param  Collection<int, ProductFamily>  $families
     * @return array<int, array{family_id: int, products_count: int, label: string}>
     */
    public static function catalogueOptionRetailMap(int $styleId, Collection $catalogueVariants, Collection $families): array
    {
        if ($styleId <= 0 || $catalogueVariants->isEmpty()) {
            return [];
        }

        if ($families->isEmpty()) {
            $families = self::familiesForStyle($styleId, repairOrphans: false);
        }

        $map = [];

        foreach ($catalogueVariants as $variant) {
            foreach ($variant->options as $option) {
                $family = self::resolveFamilyForCatalogueOption($families, $variant->name, $option);

                if ($family === null) {
                    continue;
                }

                $map[(int) $option->id] = [
                    'family_id' => (int) $family->id,
                    'products_count' => (int) $family->products_count,
                    'label' => $option->label,
                ];
            }
        }

        return $map;
    }

    /**
     * @param  Collection<int, ProductFamily>  $families
     */
    public static function resolveFamilyForCatalogueOption(
        Collection $families,
        string $variantName,
        BrandCatalogueVariantOption $catalogueOption,
    ): ?ProductFamily {
        $scopeKey = self::splitScopeKey($variantName, $catalogueOption->label);

        $byScope = $families->first(
            fn (ProductFamily $family): bool => (string) ($family->catalogue_scope_key ?? '') === $scopeKey,
        );

        if ($byScope instanceof ProductFamily) {
            return $byScope;
        }

        $catalogueOptionId = (int) $catalogueOption->id;

        $byCatalogueOption = $families->first(function (ProductFamily $family) use ($catalogueOptionId): bool {
            foreach ($family->variantGroups as $group) {
                foreach ($group->options as $option) {
                    if ((int) ($option->brand_catalogue_variant_option_id ?? 0) === $catalogueOptionId) {
                        return true;
                    }
                }
            }

            return false;
        });

        if ($byCatalogueOption instanceof ProductFamily) {
            return $byCatalogueOption;
        }

        $needle = Str::lower(trim($catalogueOption->label));

        return $families->first(function (ProductFamily $family) use ($needle): bool {
            if (! filled($family->catalogue_scope_key)) {
                return false;
            }

            $label = Str::lower(self::scopeLabel($family->catalogue_scope_key));

            return $label === $needle || str_contains($label, $needle);
        });
    }

    /**
     * SKU ids on this style that include the given catalogue option.
     *
     * @return list<int>
     */
    public static function catalogueSkuIdsForOption(BrandCatalogueStyle $style, BrandCatalogueVariantOption $option): array
    {
        $style->loadMissing(['skus.optionValues']);

        return $style->skus
            ->filter(fn ($sku) => $sku->optionValues->contains('id', $option->id))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
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
     * @param  array<int, int>  $styleIds
     * @return Collection<int, ProductFamily>
     */
    public static function primaryFamilyByStyleIds(array $styleIds): Collection
    {
        $styleIds = collect($styleIds)->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();

        if ($styleIds->isEmpty()) {
            return collect();
        }

        return collect($styleIds)
            ->mapWithKeys(function (int $styleId): array {
                $families = self::familiesForStyle($styleId, repairOrphans: false);
                $primary = self::primaryFamily($families);

                if (! $primary) {
                    return [];
                }

                $primary->setAttribute('retail_families_count', $families->count());
                $primary->setAttribute('retail_products_total', $families->sum(
                    fn (ProductFamily $family): int => (int) $family->products_count,
                ));

                return [$styleId => $primary];
            });
    }

    private static function relinkSplitOrphansForStyle(int $styleId): void
    {
        if (! Schema::hasColumn('product_families', 'catalogue_scope_key')) {
            return;
        }

        $knownFamilyIds = ProductFamily::query()
            ->where('brand_catalogue_style_id', $styleId)
            ->pluck('id');

        if ($knownFamilyIds->isEmpty()) {
            return;
        }

        for ($pass = 0; $pass < 12; $pass++) {
            $childIds = ProductSource::query()
                ->where('source_type', 'retail_family_bucket_split')
                ->whereIn('source_id', $knownFamilyIds->all())
                ->pluck('product_family_id')
                ->unique()
                ->values();

            if ($childIds->isEmpty()) {
                break;
            }

            $orphans = ProductFamily::query()
                ->whereIn('id', $childIds->all())
                ->where(function ($query) use ($styleId): void {
                    $query->whereNull('brand_catalogue_style_id')
                        ->orWhere('brand_catalogue_style_id', '!=', $styleId);
                })
                ->get();

            if ($orphans->isEmpty()) {
                break;
            }

            foreach ($orphans as $orphan) {
                self::attachFamilyToStyle($orphan, $styleId);
                $knownFamilyIds->push($orphan->id);
            }
        }

        self::relinkFamiliesByCatalogueOptionIds($styleId);
    }

    /**
     * Re-attach split families using brand_catalogue_variant_option_id on retail options.
     */
    private static function relinkFamiliesByCatalogueOptionIds(int $styleId): void
    {
        $orphans = ProductFamily::query()
            ->whereNull('brand_catalogue_style_id')
            ->whereHas('variantGroups.options', fn ($query) => $query
                ->whereNotNull('brand_catalogue_variant_option_id'))
            ->with('variantGroups.options')
            ->get();

        foreach ($orphans as $family) {
            $catalogueOptionId = (int) $family->variantGroups
                ->flatMap(fn ($group) => $group->options)
                ->pluck('brand_catalogue_variant_option_id')
                ->filter()
                ->first();

            if ($catalogueOptionId <= 0) {
                continue;
            }

            $catalogueOption = BrandCatalogueVariantOption::query()
                ->with('variant')
                ->find($catalogueOptionId);

            if (! $catalogueOption?->variant) {
                continue;
            }

            if ((int) ($catalogueOption->variant->brand_catalogue_style_id ?? 0) !== $styleId) {
                continue;
            }

            $scopeKey = self::splitScopeKey($catalogueOption->variant->name, $catalogueOption->label);
            self::attachFamilyToStyle($family, $styleId, $scopeKey);
        }
    }

    private static function attachFamilyToStyle(ProductFamily $family, int $styleId, ?string $preferredScopeKey = null): void
    {
        $scopeKey = $preferredScopeKey ?? self::inferScopeKeyForFamily($family);
        $candidate = $scopeKey;

        while (
            ProductFamily::query()
                ->where('brand_catalogue_style_id', $styleId)
                ->where('catalogue_scope_key', $candidate)
                ->where('id', '!=', $family->id)
                ->exists()
        ) {
            $candidate = Str::limit($scopeKey.'-'.$family->id, 120, '');
        }

        $family->update([
            'brand_catalogue_style_id' => $styleId,
            'catalogue_scope_key' => $candidate,
        ]);
    }

    private static function inferScopeKeyForFamily(ProductFamily $family): string
    {
        $notes = ProductSource::query()
            ->where('product_family_id', $family->id)
            ->where('source_type', 'retail_family_bucket_split')
            ->value('notes');

        if (is_string($notes) && preg_match('/\(([^:]+):\s*([^)]+)\)\s*\.?$/u', $notes, $matches)) {
            return self::splitScopeKey(trim($matches[1]), trim($matches[2]));
        }

        if (filled($family->catalogue_scope_key)) {
            return (string) $family->catalogue_scope_key;
        }

        return Str::limit('split-family-'.$family->id, 120, '');
    }
}
