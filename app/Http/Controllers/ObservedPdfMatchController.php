<?php

namespace App\Http\Controllers;

use App\Models\ObservedProduct;
use App\Models\PdfCatalogueProduct;
use App\Models\TrueProductPrice;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ObservedPdfMatchController extends Controller
{
    private const PDF_SOURCE = 'SHERRYS CATALOGUE 2026 JAN .pdf';

    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search')->value());
        $confidence = strtoupper(trim((string) $request->string('confidence')->value()));

        $matches = $this->buildMatches()
            ->sortBy('observed_name')
            ->sortBy('observed_brand')
            ->sortByDesc('score')
            ->take(250)
            ->values();

        $prices = TrueProductPrice::query()
            ->whereIn('match_key', $matches->pluck('match_key'))
            ->get()
            ->keyBy('match_key');

        $matches = $matches->map(function (array $match) use ($prices): array {
            $price = $prices->get($match['match_key']);

            $match['saved_price'] = $price?->price;
            $match['saved_currency'] = $price?->currency ?? 'GBP';
            $match['saved_notes'] = $price?->notes;

            return $match;
        });

        $filtered = $matches
            ->when($confidence !== '' && in_array($confidence, ['A', 'B', 'C'], true), function (Collection $collection) use ($confidence): Collection {
                return $collection->where('match_confidence', $confidence)->values();
            })
            ->when($search !== '', function (Collection $collection) use ($search): Collection {
                $needle = mb_strtoupper($search);

                return $collection->filter(function (array $match) use ($needle): bool {
                    $haystacks = [
                        $match['observed_brand'],
                        $match['observed_name'],
                        $match['pdf_brand'],
                        $match['pdf_name'],
                        $match['pdf_code'],
                        $match['picture_id'],
                    ];

                    foreach ($haystacks as $haystack) {
                        if (mb_stripos((string) $haystack, $needle) !== false) {
                            return true;
                        }
                    }

                    return false;
                })->values();
            });

        return view('products.true-products', [
            'matches' => $this->paginateCollection($filtered, 100, $request),
            'stats' => [
                'top_250' => $matches->count(),
                'exact' => $matches->where('is_exact', true)->count(),
                'a' => $matches->where('match_confidence', 'A')->count(),
                'b' => $matches->where('match_confidence', 'B')->count(),
                'c' => $matches->where('match_confidence', 'C')->count(),
                'priced' => $matches->filter(fn (array $match) => $match['saved_price'] !== null)->count(),
            ],
        ]);
    }

    public function updatePrice(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'match_key' => ['required', 'string', 'max:64'],
            'observed_brand' => ['required', 'string', 'max:255'],
            'observed_name' => ['required', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        TrueProductPrice::query()->updateOrCreate(
            ['match_key' => $validated['match_key']],
            [
                'observed_brand' => trim($validated['observed_brand']),
                'observed_name' => trim($validated['observed_name']),
                'price' => $validated['price'] !== null && $validated['price'] !== '' ? $validated['price'] : null,
                'currency' => trim((string) ($validated['currency'] ?? 'GBP')) ?: 'GBP',
                'notes' => trim((string) ($validated['notes'] ?? '')) ?: null,
            ],
        );

        return redirect()
            ->to(url()->previous())
            ->with('status', 'Price saved.');
    }

    private function buildMatches(): Collection
    {
        $pdfProductsByBrand = PdfCatalogueProduct::query()
            ->where('source_name', self::PDF_SOURCE)
            ->get(['id', 'pdf_catalogue_page_id', 'brand', 'product_code', 'product_name', 'page_number'])
            ->groupBy(fn (PdfCatalogueProduct $product) => $this->normalizeText($product->brand));

        $observedProducts = ObservedProduct::query()
            ->get(['id', 'picture_id', 'canonical_brand', 'brand', 'product_name'])
            ->unique(function (ObservedProduct $product): string {
                return $this->normalizeText($this->displayBrand($product)).'|'.$this->normalizeText($product->product_name);
            });

        return $observedProducts->map(function (ObservedProduct $product) use ($pdfProductsByBrand): ?array {
            $brand = $this->displayBrand($product);
            $normalizedBrand = $this->normalizeText($brand);

            if ($normalizedBrand === '' || ! $pdfProductsByBrand->has($normalizedBrand)) {
                return null;
            }

            $bestMatch = null;
            $bestScore = 0;

            foreach ($pdfProductsByBrand->get($normalizedBrand) as $pdfProduct) {
                $score = $this->scoreProducts($product->product_name, $pdfProduct->product_name);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $pdfProduct;
                }
            }

            if (! $bestMatch || $bestScore < 55) {
                return null;
            }

            return [
                'match_key' => $this->matchKey($brand, $product->product_name),
                'observed_id' => $product->id,
                'picture_id' => $product->picture_id,
                'observed_brand' => $brand,
                'observed_name' => $product->product_name,
                'pdf_brand' => $bestMatch->brand,
                'pdf_name' => $bestMatch->product_name,
                'pdf_code' => $bestMatch->product_code,
                'pdf_page_number' => $bestMatch->page_number,
                'pdf_page_id' => $bestMatch->pdf_catalogue_page_id,
                'score' => $bestScore,
                'match_confidence' => $bestScore >= 85 ? 'A' : ($bestScore >= 70 ? 'B' : 'C'),
                'is_exact' => $this->normalizeText($product->product_name) === $this->normalizeText($bestMatch->product_name),
            ];
        })->filter()->values();
    }

    private function paginateCollection(Collection $items, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max((int) $request->integer('page', 1), 1);
        $total = $items->count();
        $slice = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );
    }

    private function displayBrand(ObservedProduct $product): string
    {
        return trim((string) ($product->canonical_brand ?: $product->brand));
    }

    private function matchKey(string $brand, string $productName): string
    {
        return sha1($this->normalizeText($brand).'|'.$this->normalizeText($productName));
    }

    private function scoreProducts(string $observedName, string $pdfName): int
    {
        $observed = $this->normalizeText($observedName);
        $pdf = $this->normalizeText($pdfName);

        if ($observed === '' || $pdf === '') {
            return 0;
        }

        similar_text($observed, $pdf, $percent);

        $observedTokens = $this->meaningfulTokens($observed);
        $pdfTokens = $this->meaningfulTokens($pdf);
        $intersection = array_intersect($observedTokens, $pdfTokens);
        $union = array_unique(array_merge($observedTokens, $pdfTokens));
        $tokenScore = count($union) > 0 ? (count($intersection) / count($union)) * 100 : 0;

        preg_match_all('/\d+[A-Z.]*|[A-Z]+\d+[A-Z.]*/', $observed, $observedNumbers);
        preg_match_all('/\d+[A-Z.]*|[A-Z]+\d+[A-Z.]*/', $pdf, $pdfNumbers);

        $numberBonus = ! empty($observedNumbers[0]) && ! empty($pdfNumbers[0]) && count(array_intersect($observedNumbers[0], $pdfNumbers[0])) > 0
            ? 12
            : 0;

        $containsBonus = str_contains($observed, $pdf) || str_contains($pdf, $observed)
            ? 12
            : 0;

        return (int) round(max($percent, $tokenScore) + $numberBonus + $containsBonus);
    }

    private function normalizeText(?string $value): string
    {
        $value = mb_strtoupper((string) $value);
        $value = str_replace(['&', '"', "'", '.', ',', '/', '-', '(', ')', '!'], ' ', $value);
        $value = preg_replace('/\b(OZ|ML|G|KG|PCS|PACK|X|INCH|IN)\b/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @return array<int, string>
     */
    private function meaningfulTokens(string $value): array
    {
        $stopWords = [
            'THE',
            'AND',
            'WITH',
            'FOR',
            'OF',
            'IN',
            'TO',
            'HAIR',
            'COLOR',
            'COLOUR',
            'CREAM',
            'OIL',
            'SHAMPOO',
            'CONDITIONER',
            'LOTION',
            'GEL',
            'CARE',
            'BODY',
            'MOISTURIZING',
            'MOISTURE',
            'STYLING',
        ];

        $parts = preg_split('/\s+/u', $value) ?: [];

        return array_values(array_filter(array_unique($parts), function (string $token) use ($stopWords): bool {
            return mb_strlen($token) > 2 && ! in_array($token, $stopWords, true);
        }));
    }
}
