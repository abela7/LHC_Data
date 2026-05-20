<?php

namespace App\Http\Controllers;

use App\Models\ObservedBrandMapping;
use App\Models\ObservedProduct;
use App\Support\PictureRange;
use App\Support\ShopPhotoLocator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RealBrandController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search')->value());
        $view = (string) $request->string('view')->value();
        $view = in_array($view, ['grid', 'list'], true) ? $view : 'grid';
        $pictureRange = PictureRange::fromRequest($request);

        $baseBrandQuery = ObservedProduct::query()
            ->where('canonical_brand', '!=', '');

        $pictureRange->apply($baseBrandQuery);

        $brands = $this->buildRealBrandIndex($request, $search, $pictureRange);

        return view('real-brands.index', [
            'brands' => $brands,
            'viewMode' => $view,
            'filters' => [
                'search' => $search,
                ...$pictureRange->toFilterArray(),
            ],
            'stats' => [
                'real_brands' => $this->allCanonicalBrandNames($pictureRange)->count(),
                'products' => DB::query()
                    ->fromSub(
                        (clone $baseBrandQuery)
                            ->selectRaw('canonical_brand, product_name')
                            ->groupBy('canonical_brand', 'product_name'),
                        'real_brand_products'
                    )
                    ->count(),
                'pictures' => (clone $baseBrandQuery)
                    ->distinct('picture_id')
                    ->count('picture_id'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'canonical_brand' => ['required', 'string', 'max:255'],
            'official_source_url' => ['nullable', 'url', 'max:255'],
        ]);

        $canonicalBrand = trim($validated['canonical_brand']);

        $brandExists = $this->allCanonicalBrandNames()
            ->contains(fn (string $brand) => $brand === $canonicalBrand);

        if ($brandExists) {
            return redirect()
                ->route('real-brands.index')
                ->withErrors([
                    'canonical_brand' => 'That real brand already exists. Open it and edit the official site there.',
                ])
                ->withInput();
        }

        ObservedBrandMapping::query()->create([
            'observed_brand' => $canonicalBrand,
            'canonical_brand' => $canonicalBrand,
            'brand_line' => null,
            'official_source_url' => $validated['official_source_url'] !== null
                ? trim((string) $validated['official_source_url'])
                : null,
            'notes' => 'Manually created from the real brand registry.',
        ]);

        return redirect()
            ->route('real-brands.show', ['brand' => $canonicalBrand])
            ->with('status', "Added {$canonicalBrand} to the real brand registry.");
    }

    public function update(Request $request, string $brand): RedirectResponse
    {
        $canonicalBrand = $brand;

        $validated = $request->validate([
            'official_source_url' => ['nullable', 'url', 'max:255'],
        ]);

        $mappings = ObservedBrandMapping::query()
            ->where('canonical_brand', $canonicalBrand)
            ->get();

        if ($mappings->isEmpty()) {
            ObservedBrandMapping::query()->create([
                'observed_brand' => $canonicalBrand,
                'canonical_brand' => $canonicalBrand,
                'brand_line' => null,
                'official_source_url' => $validated['official_source_url'] !== null
                    ? trim((string) $validated['official_source_url'])
                    : null,
                'notes' => 'Manually linked from the real brand detail page.',
            ]);
        } else {
            ObservedBrandMapping::query()
                ->where('canonical_brand', $canonicalBrand)
                ->update([
                    'official_source_url' => $validated['official_source_url'] !== null
                        ? trim((string) $validated['official_source_url'])
                        : null,
                    'updated_at' => now(),
                ]);
        }

        return redirect()
            ->route('real-brands.show', ['brand' => $canonicalBrand])
            ->with('status', "Updated official site for {$canonicalBrand}.");
    }

    public function show(Request $request, string $brand, ShopPhotoLocator $shopPhotoLocator): View
    {
        $canonicalBrand = $brand;
        $search = trim((string) $request->string('search')->value());
        $view = (string) $request->string('view')->value();
        $view = in_array($view, ['grid', 'list'], true) ? $view : 'grid';
        $pictureRange = PictureRange::fromRequest($request);

        $baseBrandQuery = ObservedProduct::query()
            ->where('canonical_brand', $canonicalBrand);

        $pictureRange->apply($baseBrandQuery);

        $observedBrandsInScope = (clone $baseBrandQuery)
            ->where('brand', '!=', '')
            ->distinct()
            ->pluck('brand');

        $mappingSummary = ObservedBrandMapping::query()
            ->where('canonical_brand', $canonicalBrand)
            ->when($pictureRange->isActive(), function (Builder $query) use ($observedBrandsInScope) {
                $query->whereIn('observed_brand', $observedBrandsInScope);
            })
            ->orderBy('observed_brand')
            ->get();

        $brandExists = (clone $baseBrandQuery)->exists()
            || (! $pictureRange->isActive() && $mappingSummary->isNotEmpty());

        abort_unless($brandExists, 404);

        $products = (clone $baseBrandQuery)
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where('product_name', 'like', '%'.$search.'%');
            })
            ->select('product_name')
            ->distinct()
            ->orderBy('product_name')
            ->paginate(50)
            ->withQueryString();

        $pageProductNames = $products->getCollection()
            ->pluck('product_name')
            ->all();

        $productDetails = (clone $baseBrandQuery)
            ->whereIn('product_name', $pageProductNames)
            ->orderBy('product_name')
            ->orderBy('picture_id')
            ->orderBy('brand')
            ->get(['product_name', 'picture_id', 'brand', 'brand_line']);

        $aggregatedProducts = [];

        foreach ($productDetails as $detail) {
            if (! isset($aggregatedProducts[$detail->product_name])) {
                $aggregatedProducts[$detail->product_name] = [
                    'product_name' => $detail->product_name,
                    'row_count' => 0,
                    'picture_ids' => [],
                    'observed_brands' => [],
                    'lines' => [],
                ];
            }

            $aggregatedProducts[$detail->product_name]['row_count']++;
            $aggregatedProducts[$detail->product_name]['picture_ids'][$detail->picture_id] = true;

            if ($detail->brand !== '') {
                $aggregatedProducts[$detail->product_name]['observed_brands'][$detail->brand] = true;
            }

            if ($detail->brand_line !== null && $detail->brand_line !== '') {
                $aggregatedProducts[$detail->product_name]['lines'][$detail->brand_line] = true;
            }
        }

        $products->setCollection(
            $products->getCollection()->map(function ($product) use ($aggregatedProducts) {
                $details = $aggregatedProducts[$product->product_name] ?? [
                    'product_name' => $product->product_name,
                    'row_count' => 0,
                    'picture_ids' => [],
                    'observed_brands' => [],
                    'lines' => [],
                ];

                $pictureIds = array_keys($details['picture_ids']);
                sort($pictureIds);

                $observedBrands = array_keys($details['observed_brands']);
                sort($observedBrands);

                $lines = array_keys($details['lines']);
                sort($lines);

                return (object) [
                    'product_name' => $product->product_name,
                    'row_count' => $details['row_count'],
                    'picture_count' => count($pictureIds),
                    'picture_ids' => implode(', ', $pictureIds),
                    'observed_brands' => implode(', ', $observedBrands),
                    'lines' => implode(', ', $lines),
                ];
            })
        );

        $brandPictureCards = (clone $baseBrandQuery)
            ->orderBy('picture_id')
            ->get(['picture_id', 'product_name'])
            ->groupBy('picture_id')
            ->map(function ($pictureRows, $pictureId) use ($shopPhotoLocator) {
                $pictureId = (string) $pictureId;

                return (object) [
                    'picture_id' => $pictureId,
                    'image_url' => $shopPhotoLocator->findPath($pictureId) !== null
                        ? route('shop-photos.show', ['pictureId' => $pictureId])
                        : null,
                    'row_count' => $pictureRows->count(),
                    'product_count' => $pictureRows
                        ->pluck('product_name')
                        ->filter(fn (?string $productName) => trim((string) $productName) !== '')
                        ->unique()
                        ->count(),
                ];
            })
            ->values();

        return view('real-brands.show', [
            'canonicalBrand' => $canonicalBrand,
            'products' => $products,
            'brandPictureCards' => $brandPictureCards,
            'viewMode' => $view,
            'filters' => [
                'search' => $search,
                ...$pictureRange->toFilterArray(),
            ],
            'stats' => [
                'rows' => (clone $baseBrandQuery)->count(),
                'products' => (clone $baseBrandQuery)
                    ->distinct('product_name')
                    ->count('product_name'),
                'pictures' => (clone $baseBrandQuery)
                    ->distinct('picture_id')
                    ->count('picture_id'),
                'observed_brands' => (clone $baseBrandQuery)
                    ->where('brand', '!=', '')
                    ->distinct('brand')
                    ->count('brand'),
                'lines' => (clone $baseBrandQuery)
                    ->whereNotNull('brand_line')
                    ->where('brand_line', '!=', '')
                    ->distinct('brand_line')
                    ->count('brand_line'),
            ],
            'mappingSummary' => $mappingSummary,
            'officialSourceUrl' => ObservedBrandMapping::query()
                ->where('canonical_brand', $canonicalBrand)
                ->pluck('official_source_url')
                ->filter(fn (?string $url) => trim((string) $url) !== '')
                ->first(),
        ]);
    }

    private function buildRealBrandIndex(Request $request, string $search, PictureRange $pictureRange): LengthAwarePaginator
    {
        $brandStatsQuery = ObservedProduct::query()
            ->where('canonical_brand', '!=', '');

        $pictureRange->apply($brandStatsQuery);

        $brandStats = $brandStatsQuery
            ->selectRaw('canonical_brand, COUNT(*) as row_count, COUNT(DISTINCT product_name) as product_count, COUNT(DISTINCT picture_id) as picture_count, COUNT(DISTINCT CASE WHEN brand != "" THEN brand END) as observed_brand_count, COUNT(DISTINCT CASE WHEN brand_line IS NOT NULL AND brand_line != "" THEN brand_line END) as line_count')
            ->groupBy('canonical_brand')
            ->get()
            ->keyBy('canonical_brand');

        $mappingSummaries = ObservedBrandMapping::query()
            ->select('canonical_brand', DB::raw('MIN(official_source_url) as official_source_url'))
            ->groupBy('canonical_brand')
            ->get()
            ->keyBy('canonical_brand');

        $brands = $this->allCanonicalBrandNames($pictureRange)
            ->when($search !== '', function (Collection $brands) use ($search) {
                return $brands->filter(fn (string $brand) => str_contains(mb_strtolower($brand), mb_strtolower($search)));
            })
            ->map(function (string $canonicalBrand) use ($brandStats, $mappingSummaries) {
                $stats = $brandStats->get($canonicalBrand);
                $mapping = $mappingSummaries->get($canonicalBrand);

                return (object) [
                    'canonical_brand' => $canonicalBrand,
                    'row_count' => (int) ($stats->row_count ?? 0),
                    'product_count' => (int) ($stats->product_count ?? 0),
                    'picture_count' => (int) ($stats->picture_count ?? 0),
                    'observed_brand_count' => (int) ($stats->observed_brand_count ?? 0),
                    'line_count' => (int) ($stats->line_count ?? 0),
                    'official_source_url' => $mapping->official_source_url ?? null,
                ];
            })
            ->sort(function (object $left, object $right) {
                $productCompare = $right->product_count <=> $left->product_count;

                if ($productCompare !== 0) {
                    return $productCompare;
                }

                return strcmp($left->canonical_brand, $right->canonical_brand);
            })
            ->values();

        $perPage = 24;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $currentPageItems = $brands->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $currentPageItems,
            $brands->count(),
            $perPage,
            $page,
            [
                'path' => route('real-brands.index'),
                'query' => $request->query(),
            ],
        );
    }

    /**
     * @return Collection<int, string>
     */
    private function allCanonicalBrandNames(?PictureRange $pictureRange = null): Collection
    {
        $observedBrandQuery = ObservedProduct::query()
            ->where('canonical_brand', '!=', '');

        if ($pictureRange !== null) {
            $pictureRange->apply($observedBrandQuery);
        }

        $brandNames = $observedBrandQuery->pluck('canonical_brand');

        if ($pictureRange === null || ! $pictureRange->isActive()) {
            $brandNames = $brandNames->merge(
                ObservedBrandMapping::query()
                    ->where('canonical_brand', '!=', '')
                    ->pluck('canonical_brand')
            );
        }

        return $brandNames
            ->filter(fn (?string $brand) => trim((string) $brand) !== '')
            ->unique()
            ->sort()
            ->values();
    }
}
