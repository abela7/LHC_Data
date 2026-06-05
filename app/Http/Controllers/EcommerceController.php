<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Support\RetailEcommercePreview;
use Illuminate\View\View;

/**
 * Public, read-only demo storefront.
 *   GET /shop           — grid of product families that have at least one photo
 *   GET /shop/{family}  — ecommerce product page (same as the Shop preview modal)
 */
class EcommerceController extends Controller
{
    public function index(): View
    {
        $families = ProductFamily::query()
            ->where(function ($query): void {
                $query->whereHas('products.media')->orWhereHas('media');
            })
            ->with(['products.media', 'products.price', 'media', 'ecommerceProfile'])
            ->orderByDesc('id')
            ->get();

        $cards = $families->map(function (ProductFamily $family): ?array {
            $image = null;
            foreach ($family->products as $product) {
                $media = $product->media->firstWhere('image_role', 'main')
                    ?? $product->media->firstWhere('image_role', 'variant')
                    ?? $product->media->first(fn ($m) => $m->is_primary)
                    ?? $product->media->first();
                if ($media && $media->displayUrl()) {
                    $image = $media->displayUrl();
                    break;
                }
            }
            if (! $image) {
                $image = optional($family->media->first(fn ($m) => $m->displayUrl()))->displayUrl();
            }
            if (! $image) {
                return null; // no displayable photo — keep it out of the storefront
            }

            $prices = $family->products
                ->map(fn (Product $p) => $p->price?->retail_price)
                ->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => (float) $v);
            $distinct = $prices->unique()->values();

            return [
                'family' => $family,
                'title' => $family->ecommerceProfile?->online_title ?: $family->display_family_name,
                'brand' => $family->brand_name,
                'image' => $image,
                'sharedPrice' => $distinct->count() === 1 ? (float) $distinct->first() : null,
                'priceMin' => $prices->min(),
                'priceMax' => $prices->max(),
                'skuCount' => $family->products->count(),
            ];
        })->filter()->values();

        return view('ecommerce.index', ['cards' => $cards]);
    }

    public function show(ProductFamily $family): View
    {
        return view('ecommerce.show', [
            'family' => $family,
            'ecomPreviewData' => RetailEcommercePreview::forFamily($family),
        ]);
    }
}
