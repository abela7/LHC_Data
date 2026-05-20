<?php

namespace App\Http\Controllers;

use App\Models\PdfCataloguePage;
use App\Models\PdfCatalogueProduct;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PdfCatalogueProductController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search')->value());
        $confidence = strtoupper(trim((string) $request->string('confidence')->value()));
        $pageNumber = trim((string) $request->string('page_number')->value());
        $source = trim((string) $request->string('source')->value());
        $imageStatus = trim((string) $request->string('image_status')->value());

        $query = PdfCatalogueProduct::query()
            ->with([
                'page',
                'images' => fn ($images) => $images->where('usage_context', 'pdf_catalogue'),
            ])
            ->withCount([
                'images as pdf_catalogue_images_count' => fn ($images) => $images->where('usage_context', 'pdf_catalogue'),
            ])
            ->when($source !== '', function (Builder $builder) use ($source): void {
                $builder->where('source_name', $source);
            })
            ->when($search !== '', function (Builder $builder) use ($search): void {
                $builder->where(function (Builder $inner) use ($search): void {
                    $inner
                        ->where('brand', 'like', '%'.$search.'%')
                        ->orWhere('product_code', 'like', '%'.$search.'%')
                        ->orWhere('product_name', 'like', '%'.$search.'%');
                });
            })
            ->when(in_array($confidence, ['A', 'B', 'C', 'D'], true), function (Builder $builder) use ($confidence): void {
                $builder->where('confidence', $confidence);
            })
            ->when($pageNumber !== '' && ctype_digit($pageNumber), function (Builder $builder) use ($pageNumber): void {
                $builder->where('page_number', (int) $pageNumber);
            })
            ->when($imageStatus === 'with_image', function (Builder $builder): void {
                $builder->whereHas('images', fn ($images) => $images->where('usage_context', 'pdf_catalogue'));
            })
            ->when($imageStatus === 'missing_image', function (Builder $builder): void {
                $builder->whereDoesntHave('images', fn ($images) => $images->where('usage_context', 'pdf_catalogue'));
            });

        $products = (clone $query)
            ->orderBy('page_number')
            ->orderBy('sort_order')
            ->orderBy('product_code')
            ->paginate(100)
            ->withQueryString();

        return view('pdf-products.index', [
            'products' => $products,
            'sources' => PdfCatalogueProduct::query()
                ->select('source_name')
                ->distinct()
                ->orderBy('source_name')
                ->pluck('source_name'),
            'stats' => [
                'products' => PdfCatalogueProduct::query()->count(),
                'pages' => PdfCataloguePage::query()->count(),
                'needs_review' => PdfCatalogueProduct::query()->where('needs_review', true)->count(),
                'a' => PdfCatalogueProduct::query()->where('confidence', 'A')->count(),
                'b' => PdfCatalogueProduct::query()->where('confidence', 'B')->count(),
                'c' => PdfCatalogueProduct::query()->where('confidence', 'C')->count(),
                'd' => PdfCatalogueProduct::query()->where('confidence', 'D')->count(),
                'sherrys_products' => PdfCatalogueProduct::query()
                    ->where('source_name', 'SHERRYS CATALOGUE 2026 JAN .pdf')
                    ->count(),
                'sherrys_with_images' => PdfCatalogueProduct::query()
                    ->where('source_name', 'SHERRYS CATALOGUE 2026 JAN .pdf')
                    ->whereHas('images', fn ($images) => $images->where('usage_context', 'pdf_catalogue'))
                    ->count(),
            ],
        ]);
    }

    public function showPage(PdfCataloguePage $page): View
    {
        $page->load(['products' => fn ($query) => $query
            ->with(['images' => fn ($images) => $images->where('usage_context', 'pdf_catalogue')])
            ->withCount(['images as pdf_catalogue_images_count' => fn ($images) => $images->where('usage_context', 'pdf_catalogue')])
            ->orderBy('sort_order')
            ->orderBy('product_code')]);

        return view('pdf-products.show-page', [
            'page' => $page,
            'stats' => [
                'products' => $page->products->count(),
                'needs_review' => $page->products->where('needs_review', true)->count(),
                'with_images' => $page->products->filter(fn (PdfCatalogueProduct $product): bool => $product->images->isNotEmpty())->count(),
                'a' => $page->products->where('confidence', 'A')->count(),
                'b' => $page->products->where('confidence', 'B')->count(),
                'c' => $page->products->where('confidence', 'C')->count(),
                'd' => $page->products->where('confidence', 'D')->count(),
            ],
        ]);
    }
}
