<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BrandCatalogueLine;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Support\RetailEcommercePreview;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public, read-only demo storefront.
 *   GET /shop           — grid of product families that have at least one photo
 *   GET /shop/{family}  — ecommerce product page (same as the Shop preview modal)
 */
class EcommerceController extends Controller
{
    /** @var array<string, string> filter key => label */
    private const FILTERS = [
        'photo' => 'Has photo',
        'barcode' => 'Has barcode',
        'barcode_price' => 'Barcode + price',
        'barcode_photo_no_price' => 'Barcode + photo, no price',
    ];

    public function index(Request $request): View
    {
        $filter = (string) $request->query('filter', 'barcode');
        if (! array_key_exists($filter, self::FILTERS)) {
            $filter = 'barcode';
        }

        // A product has a usable barcode.
        $hasBarcode = fn ($q) => $q->whereNotNull('barcode')->where('barcode', '<>', '');
        // A product has a real retail price.
        $pricedRelation = fn ($p) => $p->whereNotNull('retail_price');

        $lineId = (int) $request->query('line_id', 0);
        $line = trim((string) $request->query('line', ''));

        $query = ProductFamily::query()
            ->with(['products.media', 'products.price', 'media', 'ecommerceProfile', 'catalogueLine'])
            ->orderByDesc('id');

        if ($lineId > 0) {
            $query->where('brand_catalogue_line_id', $lineId);
        } elseif ($line !== '') {
            $query->where(function ($builder) use ($line): void {
                $builder->where('line_name', $line)
                    ->orWhereHas('catalogueLine', fn ($lineQuery) => $lineQuery->where('name', $line));
            });
        }

        switch ($filter) {
            case 'barcode':
                $query->whereHas('products', fn ($q) => $hasBarcode($q));
                break;
            case 'barcode_price':
                $query->whereHas('products', fn ($q) => $hasBarcode($q)->whereHas('price', $pricedRelation));
                break;
            case 'barcode_photo_no_price':
                $query->whereHas('products', fn ($q) => $hasBarcode($q)
                    ->whereHas('media')
                    ->whereDoesntHave('price', $pricedRelation));
                break;
            default: // photo
                $query->where(fn ($q) => $q->whereHas('products.media')->orWhereHas('media'));
        }

        $families = $query->get();
        $requirePhoto = $filter === 'photo';

        $cards = $families->map(function (ProductFamily $family) use ($requirePhoto): ?array {
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
            if (! $image && $requirePhoto) {
                return null; // photo filter: skip families with no displayable photo
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

        $activeLineLabel = null;
        if ($lineId > 0) {
            $activeLineLabel = $families->first()?->catalogueLine?->name
                ?: $families->first()?->line_name
                ?: BrandCatalogueLine::query()->whereKey($lineId)->value('name');
        } elseif ($line !== '') {
            $activeLineLabel = $line;
        }

        return view('ecommerce.index', [
            'cards' => $cards,
            'filters' => self::FILTERS,
            'activeFilter' => $filter,
            'activeLine' => $activeLineLabel,
            'activeLineId' => $lineId > 0 ? $lineId : null,
        ]);
    }

    public function show(ProductFamily $family): View
    {
        return view('ecommerce.show', [
            'family' => $family,
            'ecomPreviewData' => RetailEcommercePreview::forFamily($family),
        ]);
    }
}
