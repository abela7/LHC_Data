<?php

namespace App\Http\Controllers;

use App\Models\ShabaReferenceProduct;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShabaReferenceController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $brand = trim((string) $request->query('brand', ''));
        $department = $this->validDepartment(trim((string) $request->query('department', '')));
        $departmentLabels = ShabaReferenceProduct::departmentLabels();

        $products = $this->baseSearchQuery($search, $brand, $department)
            ->with(['media' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('brand')
            ->orderBy('title')
            ->paginate(48)
            ->withQueryString();

        return view('reference.shaba.index', [
            'products' => $products,
            'search' => $search,
            'brand' => $brand,
            'department' => $department,
            'departmentLabels' => $departmentLabels,
            'departmentStats' => $this->departmentStats($departmentLabels),
            'stats' => [
                'products' => ShabaReferenceProduct::query()->count(),
                'brands' => ShabaReferenceProduct::query()->distinct('brand')->count('brand'),
                'variants' => \App\Models\ShabaReferenceVariant::query()->count(),
                'media' => \App\Models\ShabaReferenceMedia::query()->count(),
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', $request->query('search', '')));
        $brand = trim((string) $request->query('brand', ''));
        $department = $this->validDepartment(trim((string) $request->query('department', '')));
        $limit = max(1, min(50, (int) $request->query('limit', 12)));

        $products = $this->baseSearchQuery($search, $brand, $department)
            ->with([
                'variants' => fn ($query) => $query->orderBy('sort_order')->limit(60),
                'media' => fn ($query) => $query->orderBy('sort_order')->limit(12),
            ])
            ->orderByRaw(
                'CASE
                    WHEN LOWER(title) = ? THEN 0
                    WHEN LOWER(title) LIKE ? THEN 1
                    WHEN LOWER(brand) = ? THEN 2
                    ELSE 3
                END',
                [Str::lower($search), Str::lower($search).'%', Str::lower($brand)]
            )
            ->orderBy('brand')
            ->orderBy('title')
            ->limit($limit)
            ->get();

        return response()->json([
            'count' => $products->count(),
            'results' => $products->map(fn (ShabaReferenceProduct $product): array => [
                'id' => $product->id,
                'source_product_id' => $product->source_product_id,
                'brand' => $product->brand,
                'title' => $product->title,
                'department' => $product->department,
                'department_label' => ShabaReferenceProduct::departmentLabels()[$product->department] ?? 'Body Care',
                'description' => $product->description,
                'canonical_url' => $product->canonical_url,
                'main_image_url' => $product->main_image_url,
                'currency' => $product->currency,
                'min_price_pence' => $product->min_price_pence,
                'max_price_pence' => $product->max_price_pence,
                'variant_count' => $product->variant_count,
                'media_count' => $product->media_count,
                'categories' => $product->categories ?? [],
                'tags' => $product->tags ?? [],
                'options' => $product->options ?? [],
                'variants' => $product->variants->map(fn ($variant): array => [
                    'id' => $variant->id,
                    'source_variant_id' => $variant->source_variant_id,
                    'title' => $variant->title,
                    'sku' => $variant->sku,
                    'options' => $variant->options ?? [],
                    'price_current_pence' => $variant->price_current_pence,
                    'price_previous_pence' => $variant->price_previous_pence,
                    'stock_status' => $variant->stock_status,
                ])->values(),
                'media' => $product->media->map(fn ($media): array => [
                    'id' => $media->id,
                    'source_media_id' => $media->source_media_id,
                    'type' => $media->type,
                    'url' => $media->url,
                    'variant_ids' => $media->variant_ids ?? [],
                    'alt' => $media->alt,
                ])->values(),
            ])->values(),
        ]);
    }

    private function baseSearchQuery(string $search, string $brand, string $department = ''): Builder
    {
        return ShabaReferenceProduct::query()
            ->when($department !== '', function (Builder $query) use ($department): void {
                $query->where('department', $department);
            })
            ->when($brand !== '', function (Builder $query) use ($brand): void {
                $query->where(function (Builder $inner) use ($brand): void {
                    $inner
                        ->where('brand', 'like', '%'.$brand.'%')
                        ->orWhere('retailer', 'like', '%'.$brand.'%')
                        ->orWhere('normalized_brand', 'like', '%'.$this->normalize($brand).'%');
                });
            })
            ->when($search !== '', function (Builder $query) use ($search): void {
                $tokens = collect(preg_split('/[\s,\/]+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [])
                    ->map(fn (string $token): string => trim($token))
                    ->filter()
                    ->take(8)
                    ->values();

                foreach ($tokens as $token) {
                    $like = '%'.$token.'%';
                    $normalizedToken = '%'.$this->normalize($token).'%';

                    $query->where(function (Builder $inner) use ($like, $normalizedToken): void {
                        $inner
                            ->where('title', 'like', $like)
                            ->orWhere('brand', 'like', $like)
                            ->orWhere('retailer', 'like', $like)
                            ->orWhere('description', 'like', $like)
                            ->orWhere('normalized_title', 'like', $normalizedToken)
                            ->orWhereHas('variants', fn (Builder $variantQuery) => $variantQuery->where('title', 'like', $like));
                    });
                }
            });
    }

    private function validDepartment(string $department): string
    {
        return array_key_exists($department, ShabaReferenceProduct::departmentLabels()) ? $department : '';
    }

    /**
     * @param  array<string, string>  $departmentLabels
     * @return array<string, int>
     */
    private function departmentStats(array $departmentLabels): array
    {
        $counts = ShabaReferenceProduct::query()
            ->selectRaw('department, COUNT(*) as aggregate')
            ->whereIn('department', array_keys($departmentLabels))
            ->groupBy('department')
            ->pluck('aggregate', 'department')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();

        foreach ($departmentLabels as $department => $label) {
            $counts[$department] = $counts[$department] ?? 0;
        }

        return $counts;
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(preg_replace('/[^a-z0-9]+/i', ' ', $value) ?: '')) ?: '');
    }
}
