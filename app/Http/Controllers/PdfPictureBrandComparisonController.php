<?php

namespace App\Http\Controllers;

use App\Models\ObservedBrandMapping;
use App\Models\PdfCatalogueProduct;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PdfPictureBrandComparisonController extends Controller
{
    private const PDF_SOURCE = 'SHERRYS CATALOGUE 2026 JAN .pdf';

    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search')->value());

        $pictureBrands = ObservedBrandMapping::query()
            ->whereNotNull('canonical_brand')
            ->where('canonical_brand', '!=', '')
            ->distinct()
            ->orderBy('canonical_brand')
            ->pluck('canonical_brand');

        $pdfBrands = PdfCatalogueProduct::query()
            ->where('source_name', self::PDF_SOURCE)
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->where('brand', '!=', 'Unknown')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand');

        $pictureBrandMap = $pictureBrands
            ->mapWithKeys(fn (string $brand) => [mb_strtoupper($brand) => $brand]);

        [$bothBrands, $pdfOnlyBrands] = $pdfBrands->partition(
            fn (string $brand) => $pictureBrandMap->has(mb_strtoupper($brand))
        );

        $filter = function (Collection $brands) use ($search): Collection {
            if ($search === '') {
                return $brands->values();
            }

            return $brands
                ->filter(fn (string $brand) => str_contains(mb_strtoupper($brand), mb_strtoupper($search)))
                ->values();
        };

        return view('real-brands.pdf-picture-compare', [
            'pdfSource' => self::PDF_SOURCE,
            'search' => $search,
            'bothBrands' => $filter($bothBrands),
            'pdfOnlyBrands' => $filter($pdfOnlyBrands),
            'stats' => [
                'picture_brands' => $pictureBrands->count(),
                'pdf_brands' => $pdfBrands->count(),
                'both' => $bothBrands->count(),
                'pdf_only' => $pdfOnlyBrands->count(),
            ],
        ]);
    }
}
