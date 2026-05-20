<?php

namespace App\Http\Controllers;

use App\Models\ObservedBrandMapping;
use App\Models\ObservedProduct;
use App\Support\PictureRange;
use App\Support\ShopPhotoLocator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class RealBrandProductController extends Controller
{
    public function show(Request $request, string $brand, ShopPhotoLocator $shopPhotoLocator): View
    {
        $canonicalBrand = $brand;
        $productName = trim((string) $request->string('name')->value());
        $pictureRange = PictureRange::fromRequest($request);

        abort_if($productName === '', 404);

        $rows = tap(
            ObservedProduct::query()
            ->where('canonical_brand', $canonicalBrand)
            ->where('product_name', $productName),
            fn ($query) => $pictureRange->apply($query)
        )
            ->orderBy('picture_id')
            ->orderBy('sort_order')
            ->get();

        abort_if($rows->isEmpty(), 404);

        $pictureCards = $rows
            ->groupBy('picture_id')
            ->map(function ($pictureRows, $pictureId) use ($shopPhotoLocator) {
                $pictureId = (string) $pictureId;
                $observedBrands = $pictureRows
                    ->pluck('brand')
                    ->filter(fn (?string $brand) => trim((string) $brand) !== '')
                    ->unique()
                    ->sort()
                    ->values();

                $lines = $pictureRows
                    ->pluck('brand_line')
                    ->filter(fn (?string $line) => trim((string) $line) !== '')
                    ->unique()
                    ->sort()
                    ->values();

                return (object) [
                    'picture_id' => $pictureId,
                    'image_url' => $shopPhotoLocator->findPath($pictureId) !== null
                        ? route('shop-photos.show', ['pictureId' => $pictureId])
                        : null,
                    'observed_brands' => $observedBrands,
                    'lines' => $lines,
                    'row_count' => $pictureRows->count(),
                ];
            })
            ->values();

        $mappingSummary = ObservedBrandMapping::query()
            ->where('canonical_brand', $canonicalBrand)
            ->orderBy('observed_brand')
            ->get();

        return view('real-brands.product', [
            'canonicalBrand' => $canonicalBrand,
            'productName' => $productName,
            'rows' => $rows,
            'pictureCards' => $pictureCards,
            'mappingSummary' => $mappingSummary,
            'filters' => $pictureRange->toFilterArray(),
            'stats' => [
                'rows' => $rows->count(),
                'pictures' => $pictureCards->count(),
                'observed_brands' => $rows->pluck('brand')
                    ->filter(fn (?string $brand) => trim((string) $brand) !== '')
                    ->unique()
                    ->count(),
                'lines' => $rows->pluck('brand_line')
                    ->filter(fn (?string $line) => trim((string) $line) !== '')
                    ->unique()
                    ->count(),
            ],
        ]);
    }
}
