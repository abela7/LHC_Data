<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ObservedProduct;
use App\Models\ObservedBrandMapping;
use App\Support\PictureRange;
use App\Support\PictureProductMapRepository;
use App\Support\ShopPhotoLocator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PictureManagementController extends Controller
{
    public function index(
        Request $request,
        ShopPhotoLocator $shopPhotoLocator,
        PictureProductMapRepository $pictureProductMapRepository,
    ): View {
        if (! ObservedProduct::query()->exists() && $pictureProductMapRepository->hasData()) {
            return $this->mappedIndex($request, $shopPhotoLocator, $pictureProductMapRepository);
        }

        return $this->observedIndex($request, $shopPhotoLocator);
    }

    public function show(
        string $pictureId,
        ShopPhotoLocator $shopPhotoLocator,
        PictureProductMapRepository $pictureProductMapRepository,
    ): View {
        if (! ObservedProduct::query()->exists() && $pictureProductMapRepository->hasData()) {
            return $this->mappedShow($pictureId, $shopPhotoLocator, $pictureProductMapRepository);
        }

        return $this->observedShow($pictureId, $shopPhotoLocator);
    }

    public function unmapped(
        ShopPhotoLocator $shopPhotoLocator,
        PictureProductMapRepository $pictureProductMapRepository,
    ): View {
        $allPictureIds = $shopPhotoLocator->allPictureIds();
        $mappedPictureIds = ObservedProduct::query()->exists()
            ? ObservedProduct::query()->distinct()->pluck('picture_id')->filter()->values()->all()
            : $pictureProductMapRepository->pictureIds();

        $mappedLookup = array_fill_keys($mappedPictureIds, true);
        $unmappedPictureIds = array_values(array_filter(
            $allPictureIds,
            fn (string $pictureId): bool => ! isset($mappedLookup[$pictureId]),
        ));

        $pictures = collect($unmappedPictureIds)->map(function (string $pictureId) {
            return (object) [
                'picture_id' => $pictureId,
                'image_url' => route('shop-photos.show', ['pictureId' => $pictureId]),
            ];
        });

        return view('pictures.unmapped', [
            'pictures' => $pictures,
            'stats' => [
                'pictures' => count($unmappedPictureIds),
                'mapped' => count($mappedPictureIds),
                'total_files' => count($allPictureIds),
            ],
        ]);
    }

    public function productHits(Request $request): View
    {
        $search = trim((string) $request->string('search')->value());
        $brandFilter = trim((string) $request->string('brand')->value());
        $categoryFilter = trim((string) $request->string('category')->value());
        $hiddenCategorySlugs = [
            'hair-extension-moved',
            'retail-productized-confidence-a',
            'retail-productized-picture-draft',
        ];

        $applyFilters = function ($query) use ($search, $brandFilter, $categoryFilter): void {
            if ($search !== '') {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery
                        ->where('op.picture_id', 'like', '%'.$search.'%')
                        ->orWhere('op.product_name', 'like', '%'.$search.'%')
                        ->orWhere('op.brand', 'like', '%'.$search.'%')
                        ->orWhere('op.canonical_brand', 'like', '%'.$search.'%')
                        ->orWhere('op.brand_line', 'like', '%'.$search.'%')
                        ->orWhere('c.name', 'like', '%'.$search.'%');
                });
            }

            if ($brandFilter !== '') {
                if ($brandFilter === '__blank__') {
                    $query->whereRaw("COALESCE(NULLIF(op.canonical_brand, ''), op.brand) = ''");
                } else {
                    $query->whereRaw("COALESCE(NULLIF(op.canonical_brand, ''), op.brand) = ?", [$brandFilter]);
                }
            }

            if ($categoryFilter !== '') {
                $query->where('c.slug', $categoryFilter);
            }
        };

        $baseQuery = DB::table('observed_products as op')
            ->leftJoin('categories as c', 'c.id', '=', 'op.category_id');

        $baseQuery->where(function ($query) use ($hiddenCategorySlugs): void {
            $query
                ->whereNull('c.slug')
                ->orWhereNotIn('c.slug', $hiddenCategorySlugs);
        });

        $applyFilters($baseQuery);

        $hits = $baseQuery
            ->select([
                DB::raw('MIN(op.id) as first_id'),
                'op.picture_id',
                'op.product_name',
                DB::raw('MIN(op.sort_order) as first_sort_order'),
                DB::raw('COUNT(*) as raw_row_count'),
                DB::raw("GROUP_CONCAT(DISTINCT op.id ORDER BY op.id SEPARATOR ', ') as row_ids"),
                DB::raw("GROUP_CONCAT(DISTINCT NULLIF(op.brand, '') ORDER BY op.brand SEPARATOR ', ') as observed_brands"),
                DB::raw("GROUP_CONCAT(DISTINCT COALESCE(NULLIF(op.canonical_brand, ''), op.brand) ORDER BY COALESCE(NULLIF(op.canonical_brand, ''), op.brand) SEPARATOR ', ') as real_brands"),
                DB::raw("GROUP_CONCAT(DISTINCT NULLIF(op.brand_line, '') ORDER BY op.brand_line SEPARATOR ', ') as brand_lines"),
                DB::raw("GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') as categories"),
            ])
            ->groupBy('op.picture_id', 'op.product_name')
            ->orderBy('op.picture_id')
            ->orderBy('first_sort_order')
            ->orderBy('op.product_name')
            ->get();

        $brandOptions = DB::table('observed_products as op')
            ->leftJoin('categories as c', 'c.id', '=', 'op.category_id')
            ->where(function ($query) use ($hiddenCategorySlugs): void {
                $query
                    ->whereNull('c.slug')
                    ->orWhereNotIn('c.slug', $hiddenCategorySlugs);
            })
            ->selectRaw("DISTINCT COALESCE(NULLIF(op.canonical_brand, ''), op.brand) as brand_name")
            ->orderBy('brand_name')
            ->pluck('brand_name')
            ->all();

        $hits = $hits->map(function (object $hit): object {
            $hit->real_brand_entries = collect(explode(',', (string) $hit->real_brands))
                ->map(fn (string $brand): string => trim($brand))
                ->filter()
                ->unique()
                ->map(fn (string $brand): object => (object) [
                    'name' => $brand,
                    'key' => $this->brandKey($brand),
                ])
                ->values();

            return $hit;
        });

        $duplicateHits = $hits->filter(fn (object $hit): bool => (int) $hit->raw_row_count > 1)->count();

        return view('pictures.product-hits', [
            'hits' => $hits,
            'filters' => [
                'search' => $search,
                'brand' => $brandFilter,
                'category' => $categoryFilter,
            ],
            'brandOptions' => $brandOptions,
            'categoryOptions' => Category::query()
                ->whereIn('slug', ['hair', 'body-care', 'cosmetics'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'stats' => [
                'product_hits' => $hits->count(),
                'raw_rows' => $hits->sum(fn (object $hit): int => (int) $hit->raw_row_count),
                'pictures' => $hits->pluck('picture_id')->unique()->count(),
                'brands' => $hits
                    ->flatMap(fn (object $hit): array => array_filter(array_map('trim', explode(',', (string) $hit->real_brands))))
                    ->unique()
                    ->count(),
                'duplicate_hits' => $duplicateHits,
            ],
        ]);
    }

    private function observedIndex(Request $request, ShopPhotoLocator $shopPhotoLocator): View
    {
        $search = trim((string) $request->string('search')->value());
        $brandFilter = (string) $request->string('brand')->value();
        $categoryFilter = (string) $request->string('category')->value();
        $pictureRange = PictureRange::fromRequest($request);
        $rangeQuery = array_filter($pictureRange->toFilterArray());

        $basePictureQuery = ObservedProduct::query()
            ->tap(fn (Builder $query) => $pictureRange->apply($query))
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $innerQuery) use ($search) {
                    $innerQuery
                        ->where('picture_id', 'like', '%'.$search.'%')
                        ->orWhere('product_name', 'like', '%'.$search.'%')
                        ->orWhere('brand', 'like', '%'.$search.'%')
                        ->orWhere('canonical_brand', 'like', '%'.$search.'%');
                });
            })
            ->when($brandFilter !== '', function (Builder $query) use ($brandFilter) {
                if ($brandFilter === '__blank__') {
                    $query->where('canonical_brand', '');

                    return;
                }

                $query->where('canonical_brand', $brandFilter);
            })
            ->when($categoryFilter !== '', function (Builder $query) use ($categoryFilter) {
                $query->whereHas('category', function (Builder $categoryQuery) use ($categoryFilter) {
                    $categoryQuery->where('slug', $categoryFilter);
                });
            });

        $pictureIds = DB::query()
            ->fromSub(
                (clone $basePictureQuery)
                    ->select('picture_id')
                    ->groupBy('picture_id'),
                'picture_buckets'
            )
            ->select('picture_id')
            ->orderBy('picture_id')
            ->paginate(24)
            ->withQueryString();

        $pagePictureIds = $pictureIds->getCollection()
            ->pluck('picture_id')
            ->all();

        $pictureRows = empty($pagePictureIds)
            ? collect()
            : (clone $basePictureQuery)
                ->whereIn('picture_id', $pagePictureIds)
                ->orderBy('picture_id')
                ->orderBy('sort_order')
                ->get(['picture_id', 'brand', 'canonical_brand', 'product_name']);

        $aggregatedPictures = [];

        foreach ($pictureRows as $row) {
            if (! isset($aggregatedPictures[$row->picture_id])) {
                $aggregatedPictures[$row->picture_id] = [
                    'picture_id' => $row->picture_id,
                    'row_count' => 0,
                    'products' => [],
                    'product_entries' => [],
                    'brands' => [],
                ];
            }

            $aggregatedPictures[$row->picture_id]['row_count']++;

            $brandLabel = trim((string) ($row->canonical_brand ?: $row->brand));

            if ($row->product_name !== '') {
                $aggregatedPictures[$row->picture_id]['products'][$row->product_name] = true;

                $productEntryKey = $brandLabel.'||'.$row->product_name;

                $aggregatedPictures[$row->picture_id]['product_entries'][$productEntryKey] = [
                    'brand_name' => $brandLabel,
                    'product_name' => $row->product_name,
                ];
            }

            if ($brandLabel !== '') {
                $aggregatedPictures[$row->picture_id]['brands'][$brandLabel] = true;
            }
        }

        $pictureIds->setCollection(
            $pictureIds->getCollection()->map(function ($picture) use ($aggregatedPictures, $shopPhotoLocator, $rangeQuery) {
                $details = $aggregatedPictures[$picture->picture_id] ?? [
                    'picture_id' => $picture->picture_id,
                    'row_count' => 0,
                    'products' => [],
                    'product_entries' => [],
                    'brands' => [],
                ];

                $productNames = array_keys($details['products']);
                sort($productNames);

                $productEntries = array_values($details['product_entries']);

                usort($productEntries, function (array $left, array $right): int {
                    $productNameComparison = strcmp($left['product_name'], $right['product_name']);

                    if ($productNameComparison !== 0) {
                        return $productNameComparison;
                    }

                    return strcmp($left['brand_name'], $right['brand_name']);
                });

                $brandNames = array_keys($details['brands']);
                sort($brandNames);

                $pictureId = (string) $picture->picture_id;

                return (object) [
                    'picture_id' => $pictureId,
                    'image_url' => $shopPhotoLocator->findPath($pictureId) !== null
                        ? route('shop-photos.show', ['pictureId' => $pictureId])
                        : null,
                    'row_count' => $details['row_count'],
                    'product_count' => count($productNames),
                    'brand_count' => count($brandNames),
                    'products' => $productNames,
                    'brands' => $brandNames,
                    'product_entries' => array_map(function (array $entry) use ($rangeQuery): object {
                        $brandName = $entry['brand_name'];
                        $productName = $entry['product_name'];

                        return (object) [
                            'brand_name' => $brandName,
                            'product_name' => $productName,
                            'brand_url' => $brandName !== ''
                                ? route('real-brands.show', array_merge(['brand' => $brandName], $rangeQuery))
                                : null,
                            'product_url' => $brandName !== '' && $productName !== ''
                                ? route('real-brands.products.show', array_merge(['brand' => $brandName, 'name' => $productName], $rangeQuery))
                                : null,
                        ];
                    }, $productEntries),
                    'brand_entries' => array_map(function (string $brandName) use ($rangeQuery): object {
                        return (object) [
                            'name' => $brandName,
                            'url' => route('real-brands.show', array_merge(['brand' => $brandName], $rangeQuery)),
                        ];
                    }, $brandNames),
                ];
            })
        );

        return view('pictures.index', [
            'pictures' => $pictureIds,
            'filters' => [
                'search' => $search,
                'brand' => $brandFilter,
                'category' => $categoryFilter,
                ...$pictureRange->toFilterArray(),
            ],
            'brandOptions' => tap(ObservedProduct::query(), fn (Builder $query) => $pictureRange->apply($query))
                ->select('canonical_brand')
                ->distinct()
                ->orderBy('canonical_brand')
                ->pluck('canonical_brand')
                ->all(),
            'categoryOptions' => Category::query()
                ->whereIn('slug', ['hair', 'body-care', 'cosmetics'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'stats' => [
                'pictures' => (clone $basePictureQuery)
                    ->distinct('picture_id')
                    ->count('picture_id'),
                'rows' => (clone $basePictureQuery)->count(),
                'products' => DB::query()
                    ->fromSub(
                        (clone $basePictureQuery)
                            ->selectRaw('picture_id, product_name')
                            ->groupBy('picture_id', 'product_name'),
                        'picture_products'
                    )
                    ->count(),
            ],
        ]);
    }

    private function observedShow(string $pictureId, ShopPhotoLocator $shopPhotoLocator): View
    {
        $rows = ObservedProduct::query()
            ->where('picture_id', $pictureId)
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        abort_if($rows->isEmpty(), 404);

        $brandOptions = ObservedProduct::query()
            ->select('canonical_brand')
            ->where('canonical_brand', '!=', '')
            ->distinct()
            ->orderBy('canonical_brand')
            ->pluck('canonical_brand')
            ->all();

        $observedBrandOptions = ObservedBrandMapping::query()
            ->select('observed_brand')
            ->orderBy('observed_brand')
            ->pluck('observed_brand')
            ->all();

        return view('pictures.show', [
            'pictureId' => $pictureId,
            'imageUrl' => $shopPhotoLocator->findPath($pictureId) !== null
                ? route('shop-photos.show', ['pictureId' => $pictureId])
                : null,
            'rows' => $rows,
            'categoryOptions' => Category::query()
                ->whereIn('slug', ['hair', 'body-care', 'cosmetics'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'brandOptions' => $brandOptions,
            'observedBrandOptions' => $observedBrandOptions,
            'stats' => [
                'rows' => $rows->count(),
                'products' => $rows->pluck('product_name')->filter()->unique()->count(),
                'brands' => $rows->map(fn (ObservedProduct $row) => trim((string) ($row->canonical_brand ?: $row->brand)))->filter()->unique()->count(),
                'categories' => $rows->pluck('category_id')->filter()->unique()->count(),
            ],
        ]);
    }

    private function mappedIndex(
        Request $request,
        ShopPhotoLocator $shopPhotoLocator,
        PictureProductMapRepository $pictureProductMapRepository,
    ): View {
        $search = trim((string) $request->string('search')->value());
        $brandFilter = (string) $request->string('brand')->value();
        $categoryFilter = (string) $request->string('category')->value();
        $pictureRange = PictureRange::fromRequest($request);
        $pictures = $pictureProductMapRepository->paginate($search, $brandFilter, $categoryFilter, $pictureRange);
        $stats = $pictureProductMapRepository->stats($search, $brandFilter, $categoryFilter, $pictureRange);

        $pictures->setCollection(
            $pictures->getCollection()->map(function (object $picture) use ($shopPhotoLocator) {
                $pictureId = (string) $picture->picture_id;
                $picture->image_url = $shopPhotoLocator->findPath($pictureId) !== null
                    ? route('shop-photos.show', ['pictureId' => $pictureId])
                    : null;

                return $picture;
            }),
        );

        return view('pictures.index', [
            'pictures' => $pictures,
            'filters' => [
                'search' => $search,
                'brand' => $brandFilter,
                'category' => $categoryFilter,
                ...$pictureRange->toFilterArray(),
            ],
            'brandOptions' => $pictureProductMapRepository->brandOptions($pictureRange),
            'categoryOptions' => Category::query()
                ->whereIn('slug', ['hair', 'body-care', 'cosmetics'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'stats' => $stats,
            'dataSource' => 'mapped',
        ]);
    }

    private function mappedShow(
        string $pictureId,
        ShopPhotoLocator $shopPhotoLocator,
        PictureProductMapRepository $pictureProductMapRepository,
    ): View {
        $picture = $pictureProductMapRepository->find($pictureId);

        abort_if($picture === null, 404);

        return view('pictures.show', [
            'pictureId' => $pictureId,
            'imageUrl' => $shopPhotoLocator->findPath($pictureId) !== null
                ? route('shop-photos.show', ['pictureId' => $pictureId])
                : null,
            'rows' => collect(),
            'mappedPicture' => (object) $picture,
            'categoryOptions' => Category::query()
                ->whereIn('slug', ['hair', 'body-care', 'cosmetics'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'brandOptions' => [],
            'observedBrandOptions' => [],
            'stats' => [
                'rows' => $picture['row_count'],
                'products' => $picture['product_count'],
                'brands' => $picture['brand_count'],
                'categories' => $picture['category_count'],
            ],
            'dataSource' => 'mapped',
        ]);
    }

    private function brandKey(string $brand): string
    {
        return Str::slug($this->normalizeText($brand)) ?: 'unknown';
    }

    private function normalizeText(string $value): string
    {
        $value = Str::ascii(Str::lower(trim($value)));
        $value = str_replace(['&', '+'], ' and ', $value);
        $value = preg_replace('/\b(?:ltd|limited|inc|llc|co)\b/', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
