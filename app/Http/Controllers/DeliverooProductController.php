<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreManualDeliverooProductRequest;
use App\Http\Requests\UpdateManualDeliverooProductRequest;
use App\Models\DeliverooManualBrand;
use App\Models\DeliverooOfficialProduct;
use App\Services\DeliverooPhotoFormatter;
use App\Services\ImageWatermarker;
use App\Support\DeliverooBrands;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DeliverooProductController extends Controller
{
    public function index(Request $request): View
    {
        $officialProducts = DeliverooOfficialProduct::query()
            ->orderBy('sort_order')
            ->orderBy('official_name')
            ->get(['brand_slug', 'family_name', 'official_name', 'image_urls', 'price']);

        $searchTerm = trim((string) $request->query('search', ''));
        $searchResults = collect();
        $matchingBrandSlugs = null;

        if ($searchTerm !== '') {
            $searchResults = $this->searchProducts($searchTerm);
            $matchingBrandSlugs = $searchResults
                ->pluck('brand_slug')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $brands = $this->buildBrandSummaries($officialProducts);
        $categories = $this->buildCategories($brands, $matchingBrandSlugs);

        return view('deliveroo.index', [
            'categories' => $categories,
            'searchTerm' => $searchTerm,
            'searchResults' => $searchResults,
            'searchStats' => [
                'results' => $searchResults->count(),
                'brands' => $searchResults->pluck('brand_slug')->filter()->unique()->count(),
            ],
            'stats' => [
                'brands' => $brands->count(),
                'products' => $brands->sum('product_count'),
                'families' => $brands->sum('family_count'),
                'images' => $brands->sum('image_count'),
                'priced' => $brands->sum('priced_count'),
            ],
        ]);
    }

    public function allProducts(Request $request): View
    {
        $brandParam = trim((string) $request->query('brand', ''));
        $categoryParam = trim((string) $request->query('category', ''));
        $familyParam = trim((string) $request->query('family', ''));
        $priceStatusParam = trim((string) $request->query('price_status', ''));
        $hasImageParam = trim((string) $request->query('has_image', ''));
        $search = trim((string) $request->query('search', ''));
        $perPage = (int) $request->query('per_page', 96);
        $allowedPerPage = [48, 96, 192, 300];

        if (! in_array($perPage, $allowedPerPage, true)) {
            $perPage = 96;
        }

        $brandConfigs = collect($this->brandConfigs())->values();
        $knownSlugs = $brandConfigs->pluck('slug')->all();
        $categoryBySlug = $brandConfigs
            ->mapWithKeys(fn (array $config): array => [$config['slug'] => $config['category'] ?? 'Other'])
            ->all();
        $knownCategories = collect(DeliverooBrands::categories())
            ->merge($brandConfigs->pluck('category')->filter())
            ->unique()
            ->values();

        $query = DeliverooOfficialProduct::query()
            ->orderBy('brand_label')
            ->orderBy('sort_order')
            ->orderBy('official_name');

        if ($brandParam !== '' && in_array($brandParam, $knownSlugs, true)) {
            $query->where('brand_slug', $brandParam);
        }

        if ($categoryParam !== '' && $knownCategories->contains($categoryParam)) {
            $slugsInCategory = $brandConfigs
                ->filter(fn (array $config): bool => ($config['category'] ?? 'Other') === $categoryParam)
                ->pluck('slug')
                ->values()
                ->all();

            if ($slugsInCategory !== []) {
                $query->whereIn('brand_slug', $slugsInCategory);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('official_name', 'like', $like)
                    ->orWhere('family_name', 'like', $like)
                    ->orWhere('variant_name', 'like', $like)
                    ->orWhere('brand_label', 'like', $like);
            });
        }

        if ($familyParam !== '') {
            $familyLike = '%'.$familyParam.'%';
            $query->where('family_name', 'like', $familyLike);
        }

        if ($priceStatusParam === 'priced') {
            $query->whereNotNull('price');
        } elseif ($priceStatusParam === 'unpriced') {
            $query->whereNull('price');
        }

        if ($hasImageParam === 'yes') {
            $query->whereNotNull('image_urls')
                ->whereRaw('JSON_LENGTH(image_urls) > 0');
        } elseif ($hasImageParam === 'no') {
            $query->where(function (Builder $q): void {
                $q->whereNull('image_urls')
                    ->orWhereRaw('JSON_LENGTH(image_urls) = 0');
            });
        }

        $products = $query->paginate($perPage)->withQueryString();

        $brandsForFilter = $brandConfigs
            ->map(fn (array $b): array => ['slug' => $b['slug'], 'label' => $b['label']])
            ->values();
        $categoriesForFilter = $knownCategories->values();
        $familiesForFilter = DeliverooOfficialProduct::query()
            ->when($brandParam !== '' && in_array($brandParam, $knownSlugs, true), fn (Builder $q) => $q->where('brand_slug', $brandParam))
            ->when($categoryParam !== '' && $knownCategories->contains($categoryParam), function (Builder $q) use ($brandConfigs, $categoryParam): void {
                $slugsInCategory = $brandConfigs
                    ->filter(fn (array $config): bool => ($config['category'] ?? 'Other') === $categoryParam)
                    ->pluck('slug')
                    ->values()
                    ->all();
                if ($slugsInCategory === []) {
                    $q->whereRaw('1 = 0');
                    return;
                }
                $q->whereIn('brand_slug', $slugsInCategory);
            })
            ->whereNotNull('family_name')
            ->where('family_name', '!=', '')
            ->select('family_name')
            ->distinct()
            ->orderBy('family_name')
            ->limit(300)
            ->pluck('family_name')
            ->values();

        $totalCatalogue = DeliverooOfficialProduct::query()->count();
        $pricedTotal = DeliverooOfficialProduct::query()->whereNotNull('price')->count();

        return view('deliveroo.all-products', [
            'products' => $products,
            'brandsForFilter' => $brandsForFilter,
            'categoriesForFilter' => $categoriesForFilter,
            'familiesForFilter' => $familiesForFilter,
            'categoryBySlug' => $categoryBySlug,
            'brandFilter' => $brandParam,
            'categoryFilter' => $categoryParam,
            'familyFilter' => $familyParam,
            'priceStatusFilter' => $priceStatusParam,
            'hasImageFilter' => $hasImageParam,
            'search' => $search,
            'perPage' => $perPage,
            'allowedPerPage' => $allowedPerPage,
            'totalCatalogue' => $totalCatalogue,
            'pricedTotal' => $pricedTotal,
        ]);
    }

    /**
     * Generate a PDF catalogue for one or all brands.
     * Images are fetched, resized, and base64-encoded so the
     * output file contains no external URLs.
     */
    public function cataloguePdf(Request $request): HttpResponse
    {
        set_time_limit(600);
        ini_set('memory_limit', '1G');

        $brandSlug = trim((string) $request->query('brand', ''));
        $familyName = trim((string) $request->query('family', ''));
        $configs = collect($this->brandConfigs())->keyBy('slug');

        $query = DeliverooOfficialProduct::query()
            ->orderBy('brand_label')
            ->orderBy('family_name')
            ->orderBy('sort_order')
            ->orderBy('official_name');

        if ($brandSlug !== '' && $configs->has($brandSlug)) {
            $query->where('brand_slug', $brandSlug);
        }

        if ($familyName !== '') {
            $query->where(function (Builder $builder) use ($familyName): void {
                $builder
                    ->where('family_name', $familyName)
                    ->orWhere(function (Builder $fallback) use ($familyName): void {
                        $fallback
                            ->where(function (Builder $emptyFamily): void {
                                $emptyFamily
                                    ->whereNull('family_name')
                                    ->orWhere('family_name', '');
                            })
                            ->where('official_name', $familyName);
                    });
            });
        }

        $products = $query->get();

        $catalogue = $products
            ->groupBy(fn (DeliverooOfficialProduct $p): string => $configs[$p->brand_slug]['category'] ?? 'Other')
            ->sortBy(function ($_, string $category): int {
                $order = ['Hair Colour' => 0, 'Relaxers & Texturizers' => 1, 'Developers & Extensions' => 2, 'Other' => 3];

                return $order[$category] ?? 99;
            })
            ->map(function (Collection $categoryProducts) {
                return $categoryProducts
                    ->groupBy(fn (DeliverooOfficialProduct $p): string => trim((string) ($p->family_name ?: $p->official_name)))
                    ->map(function (Collection $familyProducts) {
                        return $familyProducts->map(function (DeliverooOfficialProduct $product): array {
                            $imageUrl = collect($product->image_urls ?? [])->first();
                            $base64 = null;

                            if ($imageUrl) {
                                $base64 = $this->fetchAndCompressImage($imageUrl);
                            }

                            return [
                                'name' => $product->official_name,
                                'price' => $product->price_display,
                                'image' => $base64,
                            ];
                        })->values()->all();
                    });
            });

        $filename = 'deliveroo-catalogue.pdf';
        if ($brandSlug !== '' && $familyName !== '') {
            $safeFamily = Str::of($familyName)->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->value();
            $filename = "deliveroo-{$brandSlug}-{$safeFamily}-catalogue.pdf";
        } elseif ($brandSlug !== '') {
            $filename = "deliveroo-{$brandSlug}-catalogue.pdf";
        }

        $pdf = Pdf::loadView('deliveroo.catalogue-pdf', [
            'catalogue' => $catalogue,
            'generatedAt' => now()->format('d M Y, H:i'),
            'brandLabel' => $brandSlug !== '' ? ($configs[$brandSlug]['label'] ?? $brandSlug) : 'All Brands',
        ]);

        $pdf->setPaper('A4', 'portrait');

        /** @var \Barryvdh\DomPDF\PDF $pdf */
        return $pdf->download($filename);
    }

    /**
     * Fetch a remote image, resize via GD to fit the PDF grid,
     * and return a base64 data-URI (PNG for transparency support).
     */
    private function fetchAndCompressImage(string $url, int $maxDimension = 400): ?string
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'Mozilla/5.0 LHC-Catalogue/1.0',
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $raw = @file_get_contents($url, false, $context);

            if ($raw === false || strlen($raw) < 100) {
                return null;
            }

            $src = @imagecreatefromstring($raw);
            unset($raw);

            if ($src === false) {
                return null;
            }

            $origW = imagesx($src);
            $origH = imagesy($src);

            $scale = min($maxDimension / max($origW, 1), $maxDimension / max($origH, 1), 1.0);
            $newW = max(1, (int) round($origW * $scale));
            $newH = max(1, (int) round($origH * $scale));

            $thumb = imagecreatetruecolor($newW, $newH);

            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
            imagefill($thumb, 0, 0, $transparent);
            imagealphablending($thumb, true);

            imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            imagedestroy($src);

            ob_start();
            imagepng($thumb, null, 6);
            $png = ob_get_clean();
            imagedestroy($thumb);

            if ($png === false || $png === '') {
                return null;
            }

            return 'data:image/png;base64,'.base64_encode($png);
        } catch (\Throwable) {
            return null;
        }
    }

    public function officialBrand(string $brand): View
    {
        $config = collect($this->brandConfigs())->firstWhere('slug', $brand);

        abort_if($config === null, 404);

        $products = DeliverooOfficialProduct::query()
            ->where('brand_slug', $brand)
            ->orderBy('sort_order')
            ->orderBy('official_name')
            ->get();

        $families = $this->buildFamilySummaries($products);

        return view('deliveroo.official-brand', [
            'brand' => array_merge($config, ['mark' => $this->brandMark($config['label'])]),
            'families' => $families,
            'stats' => [
                'products' => $products->count(),
                'images' => $products->sum(fn (DeliverooOfficialProduct $product) => count($product->image_urls ?? [])),
                'families' => $families->count(),
                'priced' => $products->filter(fn (DeliverooOfficialProduct $product) => $product->price !== null)->count(),
            ],
        ]);
    }

    public function officialFamily(string $brand, string $family): View
    {
        $config = collect($this->brandConfigs())->firstWhere('slug', $brand);

        abort_if($config === null, 404);

        $familyName = $this->decodeFamilyToken($family);

        $products = DeliverooOfficialProduct::query()
            ->where('brand_slug', $brand)
            ->where(function (Builder $query) use ($familyName): void {
                $query
                    ->where('family_name', $familyName)
                    ->orWhere(function (Builder $fallback) use ($familyName): void {
                        $fallback
                            ->where(function (Builder $emptyFamily): void {
                                $emptyFamily
                                    ->whereNull('family_name')
                                    ->orWhere('family_name', '');
                            })
                            ->where('official_name', $familyName);
                    });
            })
            ->orderBy('sort_order')
            ->orderBy('official_name')
            ->get();

        abort_if($products->isEmpty(), 404);

        return view('deliveroo.official-family', [
            'brand' => array_merge($config, ['mark' => $this->brandMark($config['label'])]),
            'familyName' => $familyName,
            'products' => $products,
            'stats' => [
                'products' => $products->count(),
                'images' => $products->sum(fn (DeliverooOfficialProduct $product) => count($product->image_urls ?? [])),
                'priced' => $products->filter(fn (DeliverooOfficialProduct $product) => $product->price !== null)->count(),
            ],
        ]);
    }

    public function officialProduct(string $brand, DeliverooOfficialProduct $product): View
    {
        $config = collect($this->brandConfigs())->firstWhere('slug', $brand);

        abort_if($config === null, 404);
        abort_if($product->brand_slug !== $brand, 404);

        $gallery = collect($product->image_urls ?? [])->filter()->values();
        $relatedProducts = DeliverooOfficialProduct::query()
            ->where('brand_slug', $brand)
            ->whereKeyNot($product->getKey())
            ->orderBy('sort_order')
            ->orderBy('official_name')
            ->limit(4)
            ->get();

        return view('deliveroo.official-product', [
            'brand' => $config,
            'product' => $product,
            'gallery' => $gallery,
            'relatedProducts' => $relatedProducts,
            'stats' => [
                'images' => $gallery->count(),
                'options' => count($product->option_values ?? []),
                'price' => $product->price,
            ],
        ]);
    }

    public function updatePrice(Request $request, string $brand, DeliverooOfficialProduct $product): JsonResponse|RedirectResponse
    {
        $config = collect($this->brandConfigs())->firstWhere('slug', $brand);

        abort_if($config === null, 404);
        abort_if($product->brand_slug !== $brand, 404);

        $validated = $request->validate([
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'price_notes' => ['nullable', 'string', 'max:255'],
        ]);

        $product->update([
            'price' => $validated['price'] !== null && $validated['price'] !== '' ? $validated['price'] : null,
            'currency' => 'GBP',
            'price_notes' => trim((string) ($validated['price_notes'] ?? '')) ?: null,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Deliveroo price saved.',
                'product' => [
                    'id' => $product->id,
                    'price' => $product->price,
                    'currency' => $product->currency,
                    'price_notes' => $product->price_notes,
                    'price_display' => $product->price_display,
                    'has_price' => $product->price !== null,
                ],
            ]);
        }

        return redirect()
            ->route('deliveroo-products.official-product', ['brand' => $brand, 'product' => $product])
            ->with('status', 'Deliveroo price saved.');
    }

    public function updateBrandPrice(Request $request, string $brand): JsonResponse|RedirectResponse
    {
        $config = collect($this->brandConfigs())->firstWhere('slug', $brand);

        abort_if($config === null, 404);

        $validated = $request->validate([
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'price_notes' => ['nullable', 'string', 'max:255'],
        ]);

        $price = (float) $validated['price'];
        $priceNotes = trim((string) ($validated['price_notes'] ?? '')) ?: null;

        DeliverooOfficialProduct::query()
            ->where('brand_slug', $brand)
            ->update([
                'price' => $price,
                'currency' => 'GBP',
                'price_notes' => $priceNotes,
            ]);

        $totalProducts = DeliverooOfficialProduct::query()
            ->where('brand_slug', $brand)
            ->count();

        $pricedProducts = DeliverooOfficialProduct::query()
            ->where('brand_slug', $brand)
            ->whereNotNull('price')
            ->count();

        $priceDisplay = '£'.number_format($price, 2);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Brand price saved.',
                'brand' => [
                    'slug' => $brand,
                    'label' => $config['label'],
                    'price' => $price,
                    'price_display' => html_entity_decode('&pound;', ENT_QUOTES, 'UTF-8').number_format($price, 2),
                    'price_notes' => $priceNotes,
                    'total_products' => $totalProducts,
                    'priced_products' => $pricedProducts,
                    'progress_percent' => $totalProducts > 0
                        ? (int) round(($pricedProducts / $totalProducts) * 100)
                        : 0,
                ],
            ]);
        }

        return redirect()
            ->route('deliveroo-products.official-brand', ['brand' => $brand])
            ->with('status', 'Brand price saved.');
    }

    /**
     * @return array<int, array{label:string, slug:string, category:string, aliases:array<int, string>}>
     */
    private function brandConfigs(): array
    {
        $manual = DeliverooManualBrand::query()
            ->orderBy('label')
            ->get()
            ->map(fn (DeliverooManualBrand $brand): array => [
                'label' => $brand->label,
                'slug' => $brand->slug,
                'category' => $brand->category,
                'aliases' => [$brand->label],
            ])
            ->all();

        $known = collect(DeliverooBrands::all())->keyBy('slug');
        foreach ($manual as $config) {
            $known[$config['slug']] = $config;
        }

        $dynamic = DeliverooOfficialProduct::query()
            ->select('brand_label', 'brand_slug')
            ->distinct()
            ->orderBy('brand_label')
            ->get()
            ->map(fn ($row): ?array => (($row->brand_slug ?? '') !== '' && ($row->brand_label ?? '') !== '' && ! $known->has($row->brand_slug))
                ? [
                    'label' => $row->brand_label,
                    'slug' => $row->brand_slug,
                    'category' => 'Other',
                    'aliases' => [$row->brand_label],
                ]
                : null)
            ->filter()
            ->values()
            ->all();

        foreach ($dynamic as $config) {
            $known[$config['slug']] = $config;
        }

        return $known->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    public function createManualProduct(Request $request): View
    {
        $prefillBrand = (string) $request->query('brand', '');
        $allowed = collect($this->brandConfigs())->pluck('slug')->all();
        if ($prefillBrand === '' || ! in_array($prefillBrand, $allowed, true)) {
            $prefillBrand = null;
        }

        $prefillFamily = trim((string) $request->query('family', ''));
        if ($prefillBrand === null || $prefillFamily === '') {
            $prefillFamily = '';
        } elseif (! DeliverooOfficialProduct::query()
            ->where('brand_slug', $prefillBrand)
            ->where('family_name', $prefillFamily)
            ->exists()) {
            $prefillFamily = '';
        }

        return view('deliveroo.create-product', [
            'brands' => $this->brandConfigs(),
            'brandCategories' => DeliverooBrands::categories(),
            'prefillBrandSlug' => $prefillBrand,
            'prefillFamilyName' => $prefillFamily,
        ]);
    }

    public function familiesForBrandJson(Request $request): JsonResponse
    {
        $slug = (string) $request->query('brand_slug', '');
        if ($slug === '' || collect($this->brandConfigs())->firstWhere('slug', $slug) === null) {
            return response()->json(['families' => []]);
        }

        $families = DeliverooOfficialProduct::query()
            ->where('brand_slug', $slug)
            ->whereNotNull('family_name')
            ->where('family_name', '!=', '')
            ->distinct()
            ->orderBy('family_name')
            ->pluck('family_name')
            ->values()
            ->all();

        return response()->json(['families' => $families]);
    }

    public function imageMapForExtension(Request $request): JsonResponse
    {
        $brand = trim((string) $request->query('brand', ''));
        $family = trim((string) $request->query('family', ''));
        $search = trim((string) $request->query('search', ''));

        $products = DeliverooOfficialProduct::query()
            ->whereNotNull('image_urls')
            ->whereRaw('JSON_LENGTH(image_urls) > 0')
            ->when($brand !== '', fn (Builder $query): Builder => $query->where('brand_slug', $brand))
            ->when($family !== '', fn (Builder $query): Builder => $query->where('family_name', $family))
            ->when($search !== '', function (Builder $query) use ($search): Builder {
                $like = '%'.$search.'%';

                return $query->where(function (Builder $searchQuery) use ($like): void {
                    $searchQuery
                        ->where('brand_label', 'like', $like)
                        ->orWhere('family_name', 'like', $like)
                        ->orWhere('variant_name', 'like', $like)
                        ->orWhere('official_name', 'like', $like);
                });
            })
            ->orderBy('brand_label')
            ->orderBy('family_name')
            ->orderBy('sort_order')
            ->orderBy('official_name')
            ->limit(1200)
            ->get();

        $productsPayload = $products
            ->map(function (DeliverooOfficialProduct $product) use ($request): ?array {
                $imageUrl = $this->firstImageUrl($product);
                if ($imageUrl === null) {
                    return null;
                }

                $nameParts = [
                    $product->brand_label,
                    $product->family_name,
                    $product->variant_name,
                    $product->official_name,
                ];

                return [
                    'id' => $product->getKey(),
                    'brand' => $product->brand_label,
                    'brand_slug' => $product->brand_slug,
                    'family_name' => $product->family_name,
                    'variant_name' => $product->variant_name,
                    'official_name' => $product->official_name,
                    'normalized_name' => $this->normalizeForExtension((string) $product->official_name),
                    'normalized_search' => $this->normalizeForExtension(implode(' ', array_filter($nameParts))),
                    'price' => $product->price,
                    'currency' => $product->currency ?: 'GBP',
                    'image_url' => $imageUrl,
                    'proxy_url' => $request->root().'/deliveroo-products/api/image-proxy/'.$product->getKey(),
                    'local_url' => route('deliveroo-products.official-product', [
                        'brand' => $product->brand_slug,
                        'product' => $product,
                    ]),
                    'filename' => $this->deliverooImageFilenameForProduct($product),
                ];
            })
            ->filter()
            ->values();

        return response()
            ->json([
                'generated_at' => now()->toIso8601String(),
                'count' => $productsPayload->count(),
                'products' => $productsPayload,
            ])
            ->withHeaders($this->extensionCorsHeaders());
    }

    public function imageProxyForExtension(DeliverooOfficialProduct $product): HttpResponse
    {
        $imageUrl = $this->firstImageUrl($product);
        abort_if($imageUrl === null, 404);

        $formattedPath = app(DeliverooPhotoFormatter::class)->formattedPublicPath($product, $imageUrl);
        $disk = Storage::disk('public');

        return response($disk->get($formattedPath), 200)
            ->header('Content-Type', 'image/jpeg')
            ->header('Content-Disposition', 'inline; filename="'.$this->deliverooImageFilenameForProduct($product).'"')
            ->header('Content-Length', (string) $disk->size($formattedPath))
            ->header('X-LHC-Image-Format', 'deliveroo-menu-item-1200x800')
            ->header('Cache-Control', 'no-store, max-age=0')
            ->withHeaders($this->extensionCorsHeaders());
    }

    public function storeManualProduct(StoreManualDeliverooProductRequest $request): RedirectResponse
    {
        $brand = $this->resolveManualBrand($request);
        if ($brand instanceof RedirectResponse) {
            return $brand;
        }

        $familyName = $this->resolveManualFamilyName(
            $brand['slug'],
            (string) $request->input('family_link'),
            $request->input('family_existing'),
            $request->input('family_new'),
        );

        if ($familyName instanceof RedirectResponse) {
            return $familyName;
        }

        [$imageUrls, $invalidUrls] = $this->parseImageUrlsFromRequest($request);
        if ($invalidUrls !== []) {
            return back()
                ->withInput()
                ->withErrors(['image_urls' => __('deliveroo.manual_product.invalid_image_urls', ['urls' => implode(', ', $invalidUrls)])]);
        }

        $officialUrl = trim((string) $request->input('official_url', ''));
        if ($officialUrl === '') {
            $officialUrl = 'manual:lhc:'.(string) Str::uuid();
        }

        if (DeliverooOfficialProduct::query()->where('official_url', $officialUrl)->exists()) {
            return back()
                ->withInput()
                ->withErrors(['official_url' => __('deliveroo.manual_product.duplicate_official_url')]);
        }

        $maxOrder = (int) DeliverooOfficialProduct::query()
            ->where('brand_slug', $brand['slug'])
            ->max('sort_order');

        $product = DeliverooOfficialProduct::query()->create([
            'brand_label' => $brand['label'],
            'brand_slug' => $brand['slug'],
            'family_name' => $familyName,
            'variant_name' => $request->validated('variant_name'),
            'official_name' => $request->validated('official_name'),
            'official_url' => $officialUrl,
            'description' => $request->validated('description'),
            'image_urls' => $imageUrls === [] ? null : $imageUrls,
            'price' => $request->validated('price'),
            'currency' => 'GBP',
            'source_site' => 'manual-entry',
            'sort_order' => $maxOrder + 10,
        ]);

        return redirect()
            ->route('deliveroo-products.official-product', ['brand' => $brand['slug'], 'product' => $product])
            ->with('status', __('deliveroo.manual_product.created'));
    }

    public function editManualProduct(string $brand, DeliverooOfficialProduct $product): View
    {
        $config = collect($this->brandConfigs())->firstWhere('slug', $brand);

        abort_if($config === null, 404);
        abort_if($product->brand_slug !== $brand, 404);

        return view('deliveroo.edit-product', [
            'brands' => $this->brandConfigs(),
            'brandCategories' => DeliverooBrands::categories(),
            'brand' => $config,
            'product' => $product,
            'defaultFamilyLink' => $this->defaultFamilyLinkForEdit($brand, $product),
        ]);
    }

    public function updateManualProduct(UpdateManualDeliverooProductRequest $request, string $brand, DeliverooOfficialProduct $product): RedirectResponse
    {
        abort_if(collect($this->brandConfigs())->firstWhere('slug', $brand) === null, 404);
        abort_if($product->brand_slug !== $brand, 404);

        $targetBrand = $this->resolveManualBrand($request);
        if ($targetBrand instanceof RedirectResponse) {
            return $targetBrand;
        }

        $familyName = $this->resolveManualFamilyName(
            $targetBrand['slug'],
            (string) $request->input('family_link'),
            $request->input('family_existing'),
            $request->input('family_new'),
        );

        if ($familyName instanceof RedirectResponse) {
            return $familyName;
        }

        [$imageUrls, $invalidUrls] = $this->parseImageUrlsFromRequest($request);
        if ($invalidUrls !== []) {
            return back()
                ->withInput()
                ->withErrors(['image_urls' => __('deliveroo.manual_product.invalid_image_urls', ['urls' => implode(', ', $invalidUrls)])]);
        }

        $officialUrl = trim($request->validated('official_url'));

        $product->update([
            'brand_label' => $targetBrand['label'],
            'brand_slug' => $targetBrand['slug'],
            'family_name' => $familyName,
            'variant_name' => $request->validated('variant_name'),
            'official_name' => $request->validated('official_name'),
            'official_url' => $officialUrl,
            'description' => $request->validated('description'),
            'image_urls' => $imageUrls === [] ? null : $imageUrls,
            'price' => $request->validated('price'),
            'currency' => 'GBP',
        ]);

        $product->refresh();

        return redirect()
            ->route('deliveroo-products.official-product', ['brand' => $targetBrand['slug'], 'product' => $product])
            ->with('status', __('deliveroo.manual_product.updated'));
    }

    public function uploadProductImage(Request $request, string $brand, DeliverooOfficialProduct $product): JsonResponse
    {
        abort_if(collect($this->brandConfigs())->firstWhere('slug', $brand) === null, 404);
        abort_if($product->brand_slug !== $brand, 404);

        $validated = $request->validate([
            'uploaded_image' => ['required', 'image', 'max:10240'],
        ]);

        $file = $validated['uploaded_image'];
        $existing = collect($product->image_urls ?? [])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->values()
            ->all();

        if (count($existing) >= 40) {
            return response()->json([
                'message' => __('deliveroo.manual_product.image_upload_maxed'),
            ], 422);
        }

        $extension = $file->guessExtension() ?: $file->extension() ?: 'png';
        $filename = now()->format('YmdHis').'-'.Str::random(8).'.'.$extension;
        $directory = 'deliveroo-products/product-'.$product->getKey();
        $path = $file->storeAs($directory, $filename, 'public');
        app(ImageWatermarker::class)->applyToPublicStoragePath($path);
        $url = Storage::disk('public')->url($path);

        $existing[] = $url;
        $product->update([
            'image_urls' => array_values(array_unique($existing)),
        ]);
        $product->refresh();

        return response()->json([
            'message' => __('deliveroo.manual_product.image_upload_success'),
            'url' => $url,
            'image_urls' => $product->image_urls ?? [],
        ]);
    }

    public function destroyManualProduct(string $brand, DeliverooOfficialProduct $product): RedirectResponse
    {
        abort_if(collect($this->brandConfigs())->firstWhere('slug', $brand) === null, 404);
        abort_if($product->brand_slug !== $brand, 404);

        $product->delete();

        return redirect()
            ->route('deliveroo-products.official-brand', ['brand' => $brand])
            ->with('status', __('deliveroo.manual_product.deleted'));
    }

    public function bulkDestroyBrandProducts(Request $request, string $brand): RedirectResponse
    {
        abort_if(collect($this->brandConfigs())->firstWhere('slug', $brand) === null, 404);

        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:500'],
            'product_ids.*' => ['integer', 'distinct'],
        ]);

        /** @var array<int, int> $ids */
        $ids = array_values(array_unique(array_map('intval', $validated['product_ids'])));

        $deleted = DeliverooOfficialProduct::query()
            ->where('brand_slug', $brand)
            ->whereIn('id', $ids)
            ->delete();

        if ($deleted === 0) {
            return redirect()
                ->route('deliveroo-products.official-brand', ['brand' => $brand])
                ->with('status', __('deliveroo.brand_catalogue.bulk_delete_none'));
        }

        return redirect()
            ->route('deliveroo-products.official-brand', ['brand' => $brand])
            ->with('status', trans_choice('deliveroo.brand_catalogue.bulk_deleted', $deleted, ['count' => $deleted]));
    }

    private function defaultFamilyLinkForEdit(string $brandSlug, DeliverooOfficialProduct $product): string
    {
        $fn = trim((string) ($product->family_name ?? ''));
        if ($fn === '') {
            return 'none';
        }

        $inBrand = DeliverooOfficialProduct::query()
            ->where('brand_slug', $brandSlug)
            ->where('family_name', $fn)
            ->exists();

        return $inBrand ? 'existing' : 'new';
    }

    /**
     * @return array{label:string, slug:string, category:string, aliases:array<int,string>}|RedirectResponse
     */
    private function resolveManualBrand(Request $request): array|RedirectResponse
    {
        $mode = (string) $request->input('brand_mode', 'existing');

        if ($mode === 'new') {
            $label = trim((string) $request->input('brand_new_label', ''));
            $category = trim((string) $request->input('brand_new_category', ''));

            if ($label === '') {
                return back()
                    ->withInput()
                    ->withErrors(['brand_new_label' => __('deliveroo.manual_product.brand_new_required')]);
            }

            if ($category === '' || ! in_array($category, DeliverooBrands::categories(), true)) {
                return back()
                    ->withInput()
                    ->withErrors(['brand_new_category' => __('deliveroo.manual_product.brand_new_category_required')]);
            }

            $existingByLabel = collect($this->brandConfigs())->first(function (array $config) use ($label): bool {
                return Str::lower($config['label']) === Str::lower($label);
            });

            if ($existingByLabel !== null) {
                return $existingByLabel;
            }

            $baseSlug = Str::slug($label);
            $slug = $baseSlug !== '' ? $baseSlug : 'brand';
            $counter = 2;
            while (collect($this->brandConfigs())->contains(fn (array $config): bool => $config['slug'] === $slug)) {
                $slug = $baseSlug.'-'.$counter;
                $counter++;
            }

            $manual = DeliverooManualBrand::query()->create([
                'label' => $label,
                'slug' => $slug,
                'category' => $category,
            ]);

            return [
                'label' => $manual->label,
                'slug' => $manual->slug,
                'category' => $manual->category,
                'aliases' => [$manual->label],
            ];
        }

        $slug = (string) $request->input('brand_slug', '');
        $brand = collect($this->brandConfigs())->firstWhere('slug', $slug);
        if ($brand === null) {
            return back()
                ->withInput()
                ->withErrors(['brand_slug' => __('deliveroo.manual_product.brand_existing_required')]);
        }

        return $brand;
    }

    /**
     * @return string|null|RedirectResponse
     */
    private function resolveManualFamilyName(
        string $brandSlug,
        string $familyLink,
        mixed $familyExisting,
        mixed $familyNew,
    ): string|null|RedirectResponse {
        if ($familyLink === 'none' || $familyLink === '') {
            return null;
        }

        if ($familyLink === 'new') {
            $name = trim((string) $familyNew);
            if ($name === '') {
                return back()
                    ->withInput()
                    ->withErrors(['family_new' => __('deliveroo.manual_product.family_new_required')]);
            }

            return $name;
        }

        if ($familyLink === 'existing') {
            $name = trim((string) $familyExisting);
            if ($name === '') {
                return back()
                    ->withInput()
                    ->withErrors(['family_existing' => __('deliveroo.manual_product.family_existing_required')]);
            }

            $exists = DeliverooOfficialProduct::query()
                ->where('brand_slug', $brandSlug)
                ->where('family_name', $name)
                ->exists();

            if (! $exists) {
                return back()
                    ->withInput()
                    ->withErrors(['family_existing' => __('deliveroo.manual_product.family_not_found_for_brand')]);
            }

            return $name;
        }

        return back()
            ->withInput()
            ->withErrors(['family_link' => __('deliveroo.manual_product.invalid_family_link')]);
    }

    /**
     * @return array<int, string>
     */
    private function parseImageUrlsFromText(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $urls = [];
        foreach ($lines as $line) {
            foreach (array_map('trim', explode(',', $line)) as $part) {
                if ($part === '') {
                    continue;
                }
                if (filter_var($part, FILTER_VALIDATE_URL) !== false) {
                    $urls[] = $part;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Non-empty tokens from input that are not valid URLs (for error message).
     *
     * @return array<int, string>
     */
    private function invalidUrlTokens(string $raw, array $parsedValid): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $bad = [];
        foreach ($lines as $line) {
            foreach (array_map('trim', explode(',', $line)) as $part) {
                if ($part === '') {
                    continue;
                }
                if (filter_var($part, FILTER_VALIDATE_URL) === false) {
                    $bad[] = Str::limit($part, 80);
                }
            }
        }

        return array_values(array_unique($bad));
    }

    /**
     * Reads image URLs from repeatable fields (array) or legacy textarea (string).
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function parseImageUrlsFromRequest(Request $request): array
    {
        $raw = $request->input('image_urls');

        if (is_array($raw)) {
            $urls = [];
            $invalid = [];
            foreach ($raw as $item) {
                if ($item === null || $item === '') {
                    continue;
                }
                if (! is_string($item)) {
                    continue;
                }
                $t = trim($item);
                if ($t === '') {
                    continue;
                }
                if (filter_var($t, FILTER_VALIDATE_URL) !== false) {
                    $urls[] = $t;
                } else {
                    $invalid[] = Str::limit($t, 80);
                }
            }

            return [array_values(array_unique($urls)), array_values(array_unique($invalid))];
        }

        $text = is_string($raw ?? '') ? (string) $raw : '';
        $parsed = $this->parseImageUrlsFromText($text);

        return [$parsed, $this->invalidUrlTokens($text, $parsed)];
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

        return 'DL';
    }

    /**
     * @param  Collection<int, DeliverooOfficialProduct>  $products
     * @return Collection<int, array<string, mixed>>
     */
    private function buildFamilySummaries(Collection $products): Collection
    {
        return $products
            ->groupBy(fn (DeliverooOfficialProduct $product): string => trim((string) ($product->family_name ?: $product->official_name)))
            ->map(function (Collection $familyProducts, string $familyName): array {
                /** @var Collection<int, DeliverooOfficialProduct> $familyProducts */
                $primaryProduct = $familyProducts->first();
                $primaryImage = $familyProducts
                    ->flatMap(fn (DeliverooOfficialProduct $product): array => $product->image_urls ?? [])
                    ->filter()
                    ->first();

                $variants = $familyProducts
                    ->pluck('variant_name')
                    ->filter()
                    ->map(fn (mixed $value): string => trim((string) $value))
                    ->filter()
                    ->unique()
                    ->values();

                return [
                    'name' => $familyName,
                    'token' => $this->encodeFamilyToken($familyName),
                    'product_count' => $familyProducts->count(),
                    'priced_count' => $familyProducts->filter(fn (DeliverooOfficialProduct $product) => $product->price !== null)->count(),
                    'image_count' => $familyProducts->sum(fn (DeliverooOfficialProduct $product) => count($product->image_urls ?? [])),
                    'primary_image' => $primaryImage,
                    'description' => trim((string) ($primaryProduct?->description ?? '')),
                    'variant_preview' => $variants->take(6)->all(),
                    'more_variants' => max(0, $variants->count() - 6),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * @param  Collection<int, DeliverooOfficialProduct>  $officialProducts
     * @return Collection<int, array<string, mixed>>
     */
    private function buildBrandSummaries(Collection $officialProducts): Collection
    {
        $productsByBrand = $officialProducts->groupBy('brand_slug');

        return collect(DeliverooBrands::all())
            ->map(function (array $config) use ($productsByBrand): ?array {
                /** @var Collection<int, DeliverooOfficialProduct> $products */
                $products = $productsByBrand->get($config['slug'], collect());

                if ($products->isEmpty()) {
                    return null;
                }

                $families = $products
                    ->map(function (DeliverooOfficialProduct $product): string {
                        return trim((string) ($product->family_name ?: $product->official_name));
                    })
                    ->filter()
                    ->unique()
                    ->values();

                return [
                    'label' => $config['label'],
                    'slug' => $config['slug'],
                    'category' => $config['category'],
                    'mark' => $this->brandMark($config['label']),
                    'product_count' => $products->count(),
                    'family_count' => $families->count(),
                    'image_count' => $products->sum(fn (DeliverooOfficialProduct $product) => count($product->image_urls ?? [])),
                    'priced_count' => $products->filter(fn (DeliverooOfficialProduct $product) => $product->price !== null)->count(),
                    'family_preview' => $families->take(4)->values(),
                    'more_families' => max(0, $families->count() - 4),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $brands
     * @param  array<int, string>|null  $matchingBrandSlugs
     * @return Collection<int, array<string, mixed>>
     */
    private function buildCategories(Collection $brands, ?array $matchingBrandSlugs = null): Collection
    {
        $categoryOrder = collect([
            'Hair Colour',
            'Relaxers & Texturizers',
            'Developers & Extensions',
            'Other',
        ]);

        return $categoryOrder
            ->map(function (string $category) use ($brands, $matchingBrandSlugs): ?array {
                /** @var Collection<int, array<string, mixed>> $categoryBrands */
                $categoryBrands = $brands
                    ->filter(function (array $brand) use ($category, $matchingBrandSlugs): bool {
                        if ($brand['category'] !== $category) {
                            return false;
                        }

                        if ($matchingBrandSlugs === null) {
                            return true;
                        }

                        return in_array($brand['slug'], $matchingBrandSlugs, true);
                    })
                    ->sortByDesc('product_count')
                    ->values();

                if ($categoryBrands->isEmpty()) {
                    return null;
                }

                return [
                    'label' => $category,
                    'product_count' => $categoryBrands->sum('product_count'),
                    'brands' => $categoryBrands,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, DeliverooOfficialProduct>
     */
    private function searchProducts(string $searchTerm): Collection
    {
        $rawTokens = collect(preg_split('/[\s,\/]+/u', $searchTerm, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $token): string => trim($token))
            ->filter()
            ->values();

        $tokens = $rawTokens->count() > 1
            ? $rawTokens->filter(fn (string $token): bool => Str::length($token) > 1)->values()
            : $rawTokens;

        if ($tokens->isEmpty() && $searchTerm !== '') {
            $tokens = collect([$searchTerm]);
        }

        $lowerSearch = Str::lower($searchTerm);
        $normalizedExpression = $this->normalizedSearchExpression();

        return DeliverooOfficialProduct::query()
            ->where(function (Builder $query) use ($tokens, $normalizedExpression): void {
                foreach ($tokens as $token) {
                    $like = '%'.$token.'%';
                    $normalizedToken = $this->normalizeSearchText($token);

                    $query->where(function (Builder $tokenQuery) use ($like, $normalizedExpression, $normalizedToken): void {
                        $tokenQuery
                            ->where('brand_label', 'like', $like)
                            ->orWhere('brand_slug', 'like', $like)
                            ->orWhere('family_name', 'like', $like)
                            ->orWhere('variant_name', 'like', $like)
                            ->orWhere('official_name', 'like', $like)
                            ->orWhere('description', 'like', $like)
                            ->orWhere('source_site', 'like', $like)
                            ->orWhere('official_url', 'like', $like)
                            ->orWhereRaw('CAST(option_values AS CHAR) LIKE ?', [$like]);

                        if ($normalizedToken !== '') {
                            $tokenQuery->orWhereRaw($normalizedExpression.' LIKE ?', ['%'.$normalizedToken.'%']);
                        }
                    });
                }
            })
            ->orderByRaw(
                'CASE
                    WHEN LOWER(official_name) = ? THEN 0
                    WHEN LOWER(official_name) LIKE ? THEN 1
                    WHEN LOWER(family_name) LIKE ? THEN 2
                    WHEN LOWER(variant_name) LIKE ? THEN 3
                    WHEN LOWER(brand_label) LIKE ? THEN 4
                    ELSE 5
                END',
                [
                    $lowerSearch,
                    $lowerSearch.'%',
                    $lowerSearch.'%',
                    $lowerSearch.'%',
                    $lowerSearch.'%',
                ]
            )
            ->orderBy('brand_label')
            ->orderBy('sort_order')
            ->orderBy('official_name')
            ->get();
    }

    private function normalizedSearchExpression(): string
    {
        return "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CONCAT_WS(' ', COALESCE(brand_label, ''), COALESCE(brand_slug, ''), COALESCE(family_name, ''), COALESCE(variant_name, ''), COALESCE(official_name, ''), COALESCE(description, ''), COALESCE(source_site, ''), COALESCE(official_url, ''), COALESCE(CAST(option_values AS CHAR), '')), '-', ''), ' ', ''), '&', ''), \"'\", ''), '\"', ''))";
    }

    private function normalizeSearchText(string $value): string
    {
        return (string) Str::of(Str::lower($value))
            ->replace(['-', ' ', '&', "'", '"'], '')
            ->trim();
    }

    /**
     * @return array<string, string>
     */
    private function extensionCorsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With',
        ];
    }

    private function firstImageUrl(DeliverooOfficialProduct $product): ?string
    {
        $imageUrl = collect($product->image_urls ?? [])
            ->first(fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        return is_string($imageUrl) ? trim($imageUrl) : null;
    }

    private function deliverooImageFilenameForProduct(DeliverooOfficialProduct $product): string
    {
        $base = Str::slug((string) $product->official_name);
        if ($base === '') {
            $base = 'deliveroo-product-'.$product->getKey();
        }

        return $base.'-deliveroo.jpg';
    }

    private function normalizeForExtension(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = Str::lower($decoded);
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized) ?: '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?: '');
    }

    private function encodeFamilyToken(string $familyName): string
    {
        return rtrim(strtr(base64_encode($familyName), '+/', '-_'), '=');
    }

    private function decodeFamilyToken(string $token): string
    {
        $raw = strtr($token, '-_', '+/');
        $padding = strlen($raw) % 4;
        if ($padding > 0) {
            $raw .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($raw, true);
        abort_if($decoded === false || $decoded === '', 404);

        return $decoded;
    }
}
