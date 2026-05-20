<?php

namespace App\Http\Controllers;

use App\Models\ObservedBrandMapping;
use App\Models\ObservedProduct;
use App\Support\ObservedBrandVerdict;
use App\Support\PictureRange;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ObservedBrandMappingController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search')->value());
        $pictureRange = PictureRange::fromRequest($request);

        $observedBrandsInScope = tap(
            ObservedProduct::query()
                ->where('brand', '!=', ''),
            fn (Builder $query) => $pictureRange->apply($query)
        )
            ->distinct()
            ->pluck('brand');

        $statsSubquery = tap(
            ObservedProduct::query(),
            fn (Builder $query) => $pictureRange->apply($query)
        )
            ->selectRaw('brand as observed_brand, count(*) as row_count, count(distinct product_name) as product_count, count(distinct picture_id) as picture_count')
            ->groupBy('brand');

        $mappings = ObservedBrandMapping::query()
            ->leftJoinSub($statsSubquery, 'stats', function ($join) {
                $join->on('observed_brand_mappings.observed_brand', '=', 'stats.observed_brand');
            })
            ->when($pictureRange->isActive(), function (Builder $query) use ($observedBrandsInScope) {
                $query->whereIn('observed_brand_mappings.observed_brand', $observedBrandsInScope);
            })
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $subQuery) use ($search) {
                    $subQuery
                        ->where('observed_brand_mappings.observed_brand', 'like', '%'.$search.'%')
                        ->orWhere('observed_brand_mappings.canonical_brand', 'like', '%'.$search.'%')
                        ->orWhere('observed_brand_mappings.brand_line', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc(DB::raw('COALESCE(stats.row_count, 0)'))
            ->orderBy('observed_brand_mappings.observed_brand')
            ->select([
                'observed_brand_mappings.*',
                DB::raw('COALESCE(stats.row_count, 0) as row_count'),
                DB::raw('COALESCE(stats.product_count, 0) as product_count'),
                DB::raw('COALESCE(stats.picture_count, 0) as picture_count'),
            ])
            ->paginate(50)
            ->withQueryString();

        $brandsOnPage = $mappings->pluck('observed_brand')->all();

        $productsPerBrand = ObservedProduct::query()
            ->whereIn('brand', $brandsOnPage)
            ->when($pictureRange->isActive(), fn (Builder $query) => $pictureRange->apply($query))
            ->select(['id', 'brand', 'product_name', 'picture_id'])
            ->orderBy('picture_id')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('brand');

        return view('brand-review.index', [
            'mappings' => $mappings,
            'productsPerBrand' => $productsPerBrand,
            'filters' => [
                'search' => $search,
                ...$pictureRange->toFilterArray(),
            ],
            'stats' => [
                'observed_brands' => $pictureRange->isActive()
                    ? $observedBrandsInScope->unique()->count()
                    : ObservedBrandMapping::query()->count(),
                'real_brands' => $pictureRange->isActive()
                    ? tap(
                        ObservedProduct::query()
                            ->where('canonical_brand', '!=', ''),
                        fn (Builder $query) => $pictureRange->apply($query)
                    )
                        ->distinct('canonical_brand')
                        ->count('canonical_brand')
                    : ObservedBrandMapping::query()->distinct('canonical_brand')->count('canonical_brand'),
                'products_with_real_brand' => tap(
                    ObservedProduct::query()
                        ->where('canonical_brand', '!=', ''),
                    fn (Builder $query) => $pictureRange->apply($query)
                )
                    ->count(),
            ],
            'candidateGroups' => $this->buildCandidateGroups($pictureRange->isActive() ? $observedBrandsInScope->all() : null),
        ]);
    }

    public function update(Request $request, ObservedBrandMapping $mapping): RedirectResponse
    {
        $validated = $request->validate([
            'canonical_brand' => ['required', 'string', 'max:255'],
            'brand_line' => ['nullable', 'string', 'max:255'],
            'official_source_url' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $mapping->update([
            'canonical_brand' => trim($validated['canonical_brand']),
            'brand_line' => $validated['brand_line'] !== null ? trim((string) $validated['brand_line']) : null,
            'official_source_url' => $validated['official_source_url'] !== null ? trim((string) $validated['official_source_url']) : null,
            'notes' => $validated['notes'] !== null ? trim((string) $validated['notes']) : null,
        ]);

        ObservedProduct::query()
            ->where('brand', $mapping->observed_brand)
            ->update([
                'canonical_brand' => $mapping->canonical_brand,
                'brand_line' => $mapping->brand_line,
                'updated_at' => now(),
            ]);

        return redirect()
            ->route('brand-review.index', $request->only('search', 'page', 'picture_from', 'picture_to'))
            ->with('status', "Updated real brand mapping for {$mapping->observed_brand}.");
    }

    public function seedMissing(): RedirectResponse
    {
        $existing = ObservedBrandMapping::query()
            ->pluck('observed_brand')
            ->all();

        $defaults = ObservedBrandVerdict::defaults();
        $newMappings = 0;

        $brands = ObservedProduct::query()
            ->where('brand', '!=', '')
            ->distinct()
            ->pluck('brand');

        foreach ($brands as $brand) {
            $brand = trim((string) $brand);

            if (in_array($brand, $existing, true)) {
                continue;
            }

            $mapping = $defaults[$brand] ?? [
                'canonical_brand' => $brand,
                'brand_line' => null,
                'official_source_url' => null,
                'notes' => null,
            ];

            ObservedBrandMapping::query()->create([
                'observed_brand' => $brand,
                'canonical_brand' => $mapping['canonical_brand'],
                'brand_line' => $mapping['brand_line'],
                'official_source_url' => $mapping['official_source_url'],
                'notes' => $mapping['notes'],
            ]);

            $newMappings++;
        }

        return redirect()
            ->route('brand-review.index')
            ->with('status', "Added {$newMappings} missing brand mapping(s).");
    }

    /**
     * @param  array<int, string>|null  $brands
     * @return array<int, array{normalized_key: string, brands: array<int, string>}>
     */
    private function buildCandidateGroups(?array $brands = null): array
    {
        if ($brands === null) {
            $brands = ObservedBrandMapping::query()
                ->pluck('observed_brand')
                ->filter(fn (?string $brand) => trim((string) $brand) !== '')
                ->values()
                ->all();
        }

        $groups = [];

        foreach ($brands as $brand) {
            $normalized = $this->normalizeBrand($brand);
            $groups[$normalized][] = $brand;
        }

        $candidateGroups = [];

        foreach ($groups as $normalized => $variants) {
            $variants = array_values(array_unique($variants));

            if (count($variants) < 2) {
                continue;
            }

            sort($variants);
            $candidateGroups[] = [
                'normalized_key' => $normalized,
                'brands' => $variants,
            ];
        }

        usort($candidateGroups, function (array $a, array $b) {
            $countCompare = count($b['brands']) <=> count($a['brands']);

            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strcmp($a['normalized_key'], $b['normalized_key']);
        });

        return array_slice($candidateGroups, 0, 12);
    }

    private function normalizeBrand(string $brand): string
    {
        $brand = mb_strtolower(trim($brand));
        $brand = str_replace('&', ' and ', $brand);
        $brand = preg_replace('/\bby\b/u', ' ', $brand);
        $brand = preg_replace('/[^\pL\pN]+/u', ' ', $brand);
        $brand = preg_replace('/\s+/', ' ', $brand);

        return trim((string) $brand);
    }
}
