<?php

declare(strict_types=1);

namespace App\Support;

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
     * All retail families for this style (including split buckets).
     * Re-attaches orphaned split families that lost brand_catalogue_style_id.
     *
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
            $readable = str_replace('-', ' ', substr($scopeKey, 6));

            return self::formatLengthStyleLabel($readable);
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

    /**
     * Split targets that lost brand_catalogue_style_id are re-linked to the parent style.
     */
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

        $maxPasses = 12;

        for ($pass = 0; $pass < $maxPasses; $pass++) {
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
    }

    private static function attachFamilyToStyle(ProductFamily $family, int $styleId): void
    {
        $scopeKey = self::inferScopeKeyForFamily($family);
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
            $axis = trim($matches[1]);
            $value = trim($matches[2]);
            $base = Str::slug($axis.'-'.$value);

            return Str::limit('split-'.($base !== '' ? $base : 'bucket-'.$family->id), 120, '');
        }

        if (filled($family->catalogue_scope_key)) {
            return (string) $family->catalogue_scope_key;
        }

        $slugTail = Str::afterLast((string) $family->slug, '-');

        return Str::limit('split-'.($slugTail !== '' ? $slugTail : 'family-'.$family->id), 120, '');
    }

    private static function formatLengthStyleLabel(string $readable): string
    {
        if (preg_match('/^length\s+(\d+)$/i', trim($readable), $matches)) {
            return $matches[1].'"';
        }

        return Str::title($readable);
    }
}
