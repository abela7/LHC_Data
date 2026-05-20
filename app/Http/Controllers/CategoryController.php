<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ObservedBrandMapping;
use App\Models\ObservedProduct;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index(): View
    {
        $categories = Category::query()
            ->whereIn('slug', ['hair', 'body-care', 'cosmetics'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (Category $category): Category {
                $stats = ObservedProduct::query()
                    ->where('category_id', $category->id)
                    ->selectRaw('COUNT(*) as row_count, COUNT(DISTINCT product_name) as product_count, COUNT(DISTINCT picture_id) as picture_count, COUNT(DISTINCT canonical_brand) as brand_count')
                    ->first();

                $category->row_count = (int) ($stats?->row_count ?? 0);
                $category->product_count = (int) ($stats?->product_count ?? 0);
                $category->picture_count = (int) ($stats?->picture_count ?? 0);
                $category->brand_count = (int) ($stats?->brand_count ?? 0);

                return $category;
            });

        return view('categories.index', [
            'categories' => $categories,
            'stats' => [
                'categories' => $categories->count(),
                'rows' => ObservedProduct::query()->count(),
                'products' => DB::query()
                    ->fromSub(
                        ObservedProduct::query()
                            ->selectRaw('category_id, product_name')
                            ->groupBy('category_id', 'product_name'),
                        'category_products'
                    )
                    ->count(),
            ],
        ]);
    }

    public function show(Request $request, Category $category): View
    {
        abort_unless(in_array($category->slug, ['hair', 'body-care', 'cosmetics'], true), 404);

        $search = trim((string) $request->string('search')->value());
        $view = (string) $request->string('view')->value();
        $view = in_array($view, ['grid', 'list'], true) ? $view : 'grid';

        $products = ObservedProduct::query()
            ->where('category_id', $category->id)
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $subQuery) use ($search) {
                    $subQuery
                        ->where('product_name', 'like', '%'.$search.'%')
                        ->orWhere('canonical_brand', 'like', '%'.$search.'%')
                        ->orWhere('brand', 'like', '%'.$search.'%');
                });
            })
            ->select('canonical_brand', 'product_name')
            ->distinct()
            ->orderBy('canonical_brand')
            ->orderBy('product_name')
            ->paginate(50)
            ->withQueryString();

        $pagePairs = $products->getCollection()
            ->map(fn ($product) => [
                'canonical_brand' => (string) $product->canonical_brand,
                'product_name' => (string) $product->product_name,
            ])
            ->all();

        $productDetails = empty($pagePairs)
            ? new Collection()
            : ObservedProduct::query()
                ->where('category_id', $category->id)
                ->where(function (Builder $query) use ($pagePairs) {
                    foreach ($pagePairs as $pair) {
                        $query->orWhere(function (Builder $subQuery) use ($pair) {
                            $subQuery
                                ->where('canonical_brand', $pair['canonical_brand'])
                                ->where('product_name', $pair['product_name']);
                        });
                    }
                })
                ->orderBy('canonical_brand')
                ->orderBy('product_name')
                ->orderBy('picture_id')
                ->get(['canonical_brand', 'product_name', 'picture_id', 'brand', 'brand_line']);

        $aggregatedProducts = [];

        foreach ($productDetails as $detail) {
            $key = $this->makeProductKey((string) $detail->canonical_brand, (string) $detail->product_name);

            if (! isset($aggregatedProducts[$key])) {
                $aggregatedProducts[$key] = [
                    'canonical_brand' => (string) $detail->canonical_brand,
                    'product_name' => (string) $detail->product_name,
                    'row_count' => 0,
                    'picture_ids' => [],
                    'observed_brands' => [],
                    'lines' => [],
                ];
            }

            $aggregatedProducts[$key]['row_count']++;
            $aggregatedProducts[$key]['picture_ids'][(string) $detail->picture_id] = true;

            if ($detail->brand !== '') {
                $aggregatedProducts[$key]['observed_brands'][(string) $detail->brand] = true;
            }

            if ($detail->brand_line !== null && $detail->brand_line !== '') {
                $aggregatedProducts[$key]['lines'][(string) $detail->brand_line] = true;
            }
        }

        $products->setCollection(
            $products->getCollection()->map(function ($product) use ($aggregatedProducts) {
                $canonicalBrand = (string) $product->canonical_brand;
                $productName = (string) $product->product_name;
                $details = $aggregatedProducts[$this->makeProductKey($canonicalBrand, $productName)] ?? [
                    'canonical_brand' => $canonicalBrand,
                    'product_name' => $productName,
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
                    'canonical_brand' => $canonicalBrand,
                    'product_name' => $productName,
                    'row_count' => $details['row_count'],
                    'picture_count' => count($pictureIds),
                    'picture_ids' => implode(', ', $pictureIds),
                    'observed_brands' => implode(', ', $observedBrands),
                    'lines' => implode(', ', $lines),
                ];
            })
        );

        return view('categories.show', [
            'category' => $category,
            'products' => $products,
            'viewMode' => $view,
            'filters' => [
                'search' => $search,
            ],
            'stats' => [
                'rows' => ObservedProduct::query()
                    ->where('category_id', $category->id)
                    ->count(),
                'products' => ObservedProduct::query()
                    ->where('category_id', $category->id)
                    ->select('canonical_brand', 'product_name')
                    ->distinct()
                    ->count(),
                'pictures' => ObservedProduct::query()
                    ->where('category_id', $category->id)
                    ->distinct('picture_id')
                    ->count('picture_id'),
                'real_brands' => ObservedProduct::query()
                    ->where('category_id', $category->id)
                    ->where('canonical_brand', '!=', '')
                    ->distinct('canonical_brand')
                    ->count('canonical_brand'),
            ],
        ]);
    }

    public function brands(Request $request, Category $category): View
    {
        abort_unless(in_array($category->slug, ['hair', 'body-care', 'cosmetics'], true), 404);

        $search = trim((string) $request->string('search')->value());
        $view = (string) $request->string('view')->value();
        $view = in_array($view, ['grid', 'list'], true) ? $view : 'grid';

        $brands = ObservedProduct::query()
            ->where('category_id', $category->id)
            ->where('canonical_brand', '!=', '')
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where('canonical_brand', 'like', '%'.$search.'%');
            })
            ->selectRaw('canonical_brand, COUNT(*) as row_count, COUNT(DISTINCT product_name) as product_count, COUNT(DISTINCT picture_id) as picture_count, COUNT(DISTINCT CASE WHEN brand != "" THEN brand END) as observed_brand_count, COUNT(DISTINCT CASE WHEN brand_line IS NOT NULL AND brand_line != "" THEN brand_line END) as line_count')
            ->groupBy('canonical_brand')
            ->orderByDesc('product_count')
            ->orderBy('canonical_brand')
            ->paginate(24)
            ->withQueryString();

        $officialSourceUrls = ObservedBrandMapping::query()
            ->whereNotNull('official_source_url')
            ->where('official_source_url', '!=', '')
            ->select('canonical_brand', DB::raw('MIN(official_source_url) as official_source_url'))
            ->groupBy('canonical_brand')
            ->pluck('official_source_url', 'canonical_brand');

        $brands->setCollection(
            $brands->getCollection()->map(function ($brand) use ($officialSourceUrls) {
                $brand->official_source_url = $officialSourceUrls[$brand->canonical_brand] ?? null;

                return $brand;
            })
        );

        return view('categories.brands', [
            'category' => $category,
            'brands' => $brands,
            'viewMode' => $view,
            'filters' => [
                'search' => $search,
            ],
            'stats' => [
                'real_brands' => ObservedProduct::query()
                    ->where('category_id', $category->id)
                    ->where('canonical_brand', '!=', '')
                    ->distinct('canonical_brand')
                    ->count('canonical_brand'),
                'products' => ObservedProduct::query()
                    ->where('category_id', $category->id)
                    ->select('canonical_brand', 'product_name')
                    ->distinct()
                    ->count(),
                'pictures' => ObservedProduct::query()
                    ->where('category_id', $category->id)
                    ->distinct('picture_id')
                    ->count('picture_id'),
                'rows' => ObservedProduct::query()
                    ->where('category_id', $category->id)
                    ->count(),
            ],
        ]);
    }

    private function makeProductKey(string $canonicalBrand, string $productName): string
    {
        return $canonicalBrand.'|'.$productName;
    }
}
