<?php

namespace App\Http\Controllers;

use App\Models\MamadoProduct;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MamadoProductController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $brand = trim((string) $request->query('brand', ''));
        $family = trim((string) $request->query('family', ''));
        $status = trim((string) $request->query('status', ''));
        $sourceOrder = trim((string) $request->query('source_order', ''));
        $viewMode = trim((string) $request->query('view', ''));
        $hasImages = $request->boolean('has_images');
        $perPage = (int) $request->query('per_page', 100);
        $allowedPerPage = [50, 100, 200, 400];

        if (! in_array($viewMode, ['', 'families'], true)) {
            $viewMode = '';
        }

        if (! in_array($perPage, $allowedPerPage, true)) {
            $perPage = 100;
        }

        $query = MamadoProduct::query();

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('item_code', 'like', $like)
                    ->orWhere('item_description', 'like', $like)
                    ->orWhere('brand_label', 'like', $like)
                    ->orWhere('family_name', 'like', $like)
                    ->orWhere('sellable_name', 'like', $like);
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($brand !== '') {
            $query->where('brand_label', $brand);
        }

        if ($family !== '') {
            $query->where('family_name', $family);
        }

        if ($sourceOrder !== '') {
            $query->where('source_order_number', $sourceOrder);
        }

        if ($hasImages) {
            $query->whereNotNull('image_urls')
                ->where('image_urls', '<>', '[]');
        }

        $brandFamilyGroups = collect();
        $familyStats = null;

        if ($brand !== '' && $family === '') {
            $brandFamilyGroups = $this->buildFamilySummaries((clone $query)->get());
        }

        if ($viewMode === 'families' && $brand === '' && $family === '') {
            $brandFamilyGroups = $this->buildFamilySummaries(
                (clone $query)
                    ->whereNotNull('family_name')
                    ->where('family_name', '<>', '')
                    ->get()
            );
        }

        if ($brand !== '' && $family !== '') {
            /** @var EloquentCollection<int, MamadoProduct> $familyProductsForStats */
            $familyProductsForStats = (clone $query)->get();
            $familyStats = [
                'products' => $familyProductsForStats->count(),
                'images' => $familyProductsForStats->sum(fn (MamadoProduct $product): int => count($product->image_urls ?? [])),
                'variant_review' => $familyProductsForStats->filter(fn (MamadoProduct $product): bool => $product->status === 'variant_review_pending')->count(),
                'orders' => $familyProductsForStats->pluck('source_order_number')->filter()->unique()->count(),
            ];
        }

        if ($brand !== '' || $family !== '') {
            $query->orderByRaw('CASE WHEN family_name IS NULL OR family_name = "" THEN 1 ELSE 0 END')
                ->orderBy('family_name')
                ->orderBy('item_code');
        } else {
            $query->orderBy('item_code');
        }

        $products = $query->paginate($perPage)->withQueryString();
        $statusOptions = MamadoProduct::query()
            ->select('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->filter()
            ->values();
        $sourceOrders = MamadoProduct::query()
            ->select('source_order_number')
            ->distinct()
            ->orderByDesc('source_order_number')
            ->pluck('source_order_number')
            ->filter()
            ->values();
        $brandOptions = MamadoProduct::query()
            ->select('brand_label')
            ->distinct()
            ->orderBy('brand_label')
            ->pluck('brand_label')
            ->filter()
            ->values();
        $familyOptionsQuery = MamadoProduct::query()
            ->select('family_name')
            ->whereNotNull('family_name')
            ->where('family_name', '<>', '')
            ->distinct()
            ->orderBy('family_name');

        if ($brand !== '') {
            $familyOptionsQuery->where('brand_label', $brand);
        }

        $familyOptions = $familyOptionsQuery
            ->pluck('family_name')
            ->filter()
            ->values();

        return view('mamado-products.index', [
            'products' => $products,
            'search' => $search,
            'brand' => $brand,
            'family' => $family,
            'status' => $status,
            'sourceOrder' => $sourceOrder,
            'viewMode' => $viewMode,
            'hasImages' => $hasImages,
            'statusOptions' => $statusOptions,
            'sourceOrders' => $sourceOrders,
            'brandOptions' => $brandOptions,
            'familyOptions' => $familyOptions,
            'brandFamilyGroups' => $brandFamilyGroups,
            'familyStats' => $familyStats,
            'perPage' => $perPage,
            'allowedPerPage' => $allowedPerPage,
            'stats' => [
                'products' => MamadoProduct::query()->count(),
                'brands' => MamadoProduct::query()->whereNotNull('brand_label')->distinct('brand_label')->count('brand_label'),
                'families' => MamadoProduct::query()->whereNotNull('family_name')->distinct('family_name')->count('family_name'),
                'images' => MamadoProduct::query()
                    ->whereNotNull('image_urls')
                    ->where('image_urls', '<>', '[]')
                    ->count(),
                'sourceOnly' => MamadoProduct::query()->where('status', 'source_only')->count(),
                'variantReviewPending' => MamadoProduct::query()->where('status', 'variant_review_pending')->count(),
                'enriched' => MamadoProduct::query()->whereNotNull('sellable_name')->count(),
                'priced' => MamadoProduct::query()->whereNotNull('sellable_price')->count(),
                'orders' => MamadoProduct::query()->whereNotNull('source_order_number')->distinct('source_order_number')->count('source_order_number'),
            ],
        ]);
    }

    public function brands(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $query = MamadoProduct::query()
            ->select([
                'brand_label',
                DB::raw('COUNT(*) as product_count'),
                DB::raw('COUNT(DISTINCT NULLIF(family_name, "")) as family_count'),
                DB::raw('SUM(CASE WHEN image_urls IS NOT NULL AND image_urls <> "[]" THEN 1 ELSE 0 END) as image_count'),
                DB::raw('SUM(CASE WHEN status = "variant_review_pending" THEN 1 ELSE 0 END) as variant_review_count'),
                DB::raw('MIN(gross_unit_price) as min_price'),
                DB::raw('MAX(gross_unit_price) as max_price'),
                DB::raw('COUNT(DISTINCT source_order_number) as order_count'),
            ])
            ->whereNotNull('brand_label')
            ->where('brand_label', '<>', '')
            ->groupBy('brand_label')
            ->orderByDesc('product_count')
            ->orderBy('brand_label');

        if ($search !== '') {
            $query->where('brand_label', 'like', '%'.$search.'%');
        }

        $brands = $query->paginate(80)->withQueryString();
        $brands->getCollection()->transform(function ($brand) {
            $brand->mark = $this->brandMark((string) $brand->brand_label);

            return $brand;
        });

        return view('mamado-products.brands', [
            'brands' => $brands,
            'search' => $search,
            'stats' => [
                'brands' => MamadoProduct::query()->whereNotNull('brand_label')->distinct('brand_label')->count('brand_label'),
                'products' => MamadoProduct::query()->count(),
                'unassigned' => MamadoProduct::query()
                    ->whereNull('brand_label')
                    ->orWhere('brand_label', '')
                    ->count(),
            ],
        ]);
    }

    public function show(MamadoProduct $mamadoProduct): View
    {
        return view('mamado-products.show', [
            'product' => $mamadoProduct,
            'gallery' => collect($mamadoProduct->image_urls ?? [])->filter()->values(),
        ]);
    }

    private function brandMark(string $label): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', strtoupper($label), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) >= 2) {
            return substr($parts[0], 0, 1).substr($parts[1], 0, 1);
        }

        if ($parts !== []) {
            return substr($parts[0], 0, 2);
        }

        return 'MM';
    }

    /**
     * @param  EloquentCollection<int, MamadoProduct>  $products
     */
    private function buildFamilySummaries(EloquentCollection $products): \Illuminate\Support\Collection
    {
        return $products
            ->groupBy(fn (MamadoProduct $product): string => Str::lower(Str::ascii(trim((string) ($product->family_name ?: 'Unstaged family')))))
            ->map(function (\Illuminate\Support\Collection $familyProducts): array {
                $familyName = trim((string) ($familyProducts->first()?->family_name ?: 'Unstaged family'));
                $primaryImage = $familyProducts
                    ->flatMap(fn (MamadoProduct $product): array => $product->image_urls ?? [])
                    ->filter()
                    ->first();

                $variants = $familyProducts
                    ->pluck('variant_name')
                    ->filter()
                    ->map(fn (mixed $value): string => trim((string) $value))
                    ->filter()
                    ->unique()
                    ->values();

                $reviewPendingCount = $familyProducts
                    ->filter(fn (MamadoProduct $product): bool => $product->status === 'variant_review_pending')
                    ->count();

                return [
                    'name' => $familyName,
                    'product_count' => $familyProducts->count(),
                    'review_pending_count' => $reviewPendingCount,
                    'image_count' => $familyProducts->sum(fn (MamadoProduct $product): int => count($product->image_urls ?? [])),
                    'order_count' => $familyProducts->pluck('source_order_number')->filter()->unique()->count(),
                    'primary_image' => $primaryImage,
                    'variant_preview' => $variants->take(6)->all(),
                    'more_variants' => max(0, $variants->count() - 6),
                    'min_price' => $familyProducts->min('gross_unit_price'),
                    'max_price' => $familyProducts->max('gross_unit_price'),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}
