<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\CatalogueAiEnrichment;
use App\Models\CatalogueFamily;
use App\Models\CatalogueType;
use App\Models\ObservedProduct;
use App\Support\CatalogueAiProductIdResolver;
use App\Support\PictureRange;
use App\Support\ShopPhotoLocator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request, CatalogueAiProductIdResolver $catalogueAiProductIdResolver): View
    {
        $query = ObservedProduct::query()->with('category');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'like', '%'.$search.'%')
                  ->orWhere('canonical_brand', 'like', '%'.$search.'%')
                  ->orWhere('brand', 'like', '%'.$search.'%');
            });
        }

        if ($brand = trim((string) $request->input('brand', ''))) {
            $query->where('canonical_brand', $brand);
        }

        if ($categoryId = $request->input('category_id')) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->orderBy('canonical_brand')->orderBy('product_name')->paginate(60)->withQueryString();
        $productIds = $products->getCollection()
            ->map(fn (ObservedProduct $product): ?string => $catalogueAiProductIdResolver->productIdForObservedProduct($product))
            ->filter()
            ->unique()
            ->values();

        $enrichments = CatalogueAiEnrichment::query()
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'confidence', 'needs_review'])
            ->keyBy('product_id');

        $products->setCollection(
            $products->getCollection()->map(function (ObservedProduct $product) use ($catalogueAiProductIdResolver, $enrichments) {
                $productId = $catalogueAiProductIdResolver->productIdForObservedProduct($product);
                $enrichment = $productId !== null ? $enrichments->get($productId) : null;

                $product->setAttribute('ai_product_id', $productId);
                $product->setAttribute('ai_confidence', $enrichment?->confidence);
                $product->setAttribute('ai_needs_review', (bool) ($enrichment?->needs_review ?? false));

                return $product;
            })
        );

        $brandOptions = ObservedProduct::query()
            ->select('canonical_brand')
            ->distinct()
            ->orderBy('canonical_brand')
            ->pluck('canonical_brand')
            ->filter()
            ->values();

        return view('products.index', [
            'products'      => $products,
            'brandOptions'  => $brandOptions,
            'categories'    => Category::query()->orderBy('sort_order')->orderBy('name')->get(),
            'total'         => ObservedProduct::query()->count(),
        ]);
    }

    public function duplicates(): View
    {
        $groups = ObservedProduct::query()
            ->selectRaw('picture_id, brand, MAX(canonical_brand) as canonical_brand, product_name, COUNT(*) as cnt')
            ->groupBy('picture_id', 'brand', 'product_name')
            ->having('cnt', '>', 1)
            ->orderBy('picture_id')
            ->orderBy('product_name')
            ->paginate(30)
            ->withQueryString();

        $keys = $groups->map(fn ($g) => [
            'picture_id'   => $g->picture_id,
            'brand'        => $g->brand,
            'product_name' => $g->product_name,
        ])->all();

        $rows = collect();
        if (count($keys)) {
            $rows = ObservedProduct::query()
                ->with('category')
                ->where(function ($q) use ($keys) {
                    foreach ($keys as $key) {
                        $q->orWhere(function ($sub) use ($key) {
                            $sub->where('picture_id', $key['picture_id'])
                                ->where('brand', $key['brand'])
                                ->where('product_name', $key['product_name']);
                        });
                    }
                })
                ->orderBy('picture_id')
                ->orderBy('product_name')
                ->orderBy('id')
                ->get();
        }

        $groupedRows = $rows->groupBy(fn ($r) => $r->picture_id.'|'.$r->brand.'|'.$r->product_name);

        $dupStats = $this->getDupStats();

        return view('products.duplicates', [
            'groups'      => $groups,
            'groupedRows' => $groupedRows,
            'totalGroups' => $dupStats['groups'],
            'totalRows'   => $dupStats['rows'],
            'toDelete'    => $dupStats['rows'] - $dupStats['groups'],
        ]);
    }

    public function show(
        ObservedProduct $observedProduct,
        CatalogueAiProductIdResolver $catalogueAiProductIdResolver,
        ShopPhotoLocator $shopPhotoLocator,
    ): View {
        $observedProduct->load('category');

        $productId = $catalogueAiProductIdResolver->productIdForObservedProduct($observedProduct);
        $enrichment = $productId !== null
            ? CatalogueAiEnrichment::query()->where('product_id', $productId)->first()
            : null;

        $displayBrand = $catalogueAiProductIdResolver->displayBrand(
            $observedProduct->canonical_brand,
            $observedProduct->brand,
        );

        $relatedRowsQuery = ObservedProduct::query()
            ->with('category')
            ->where('product_name', $observedProduct->product_name);

        if (trim((string) $observedProduct->canonical_brand) !== '') {
            $relatedRowsQuery->where('canonical_brand', $observedProduct->canonical_brand);
        } elseif (trim((string) $observedProduct->brand) !== '') {
            $relatedRowsQuery
                ->where('brand', $observedProduct->brand)
                ->where(function ($query) {
                    $query->whereNull('canonical_brand')->orWhere('canonical_brand', '');
                });
        } else {
            $relatedRowsQuery->where(function ($query) {
                $query->whereNull('brand')->orWhere('brand', '');
            });
        }

        $relatedRows = $relatedRowsQuery
            ->orderBy('picture_id')
            ->orderBy('sort_order')
            ->get();

        $imageUrl = $shopPhotoLocator->findPath($observedProduct->picture_id) !== null
            ? route('shop-photos.show', ['pictureId' => $observedProduct->picture_id])
            : null;

        return view('products.show', [
            'product' => $observedProduct,
            'displayBrand' => $displayBrand !== '' ? $displayBrand : 'Unbranded',
            'productId' => $productId,
            'enrichment' => $enrichment,
            'relatedRows' => $relatedRows,
            'imageUrl' => $imageUrl,
            'categories' => Category::query()->orderBy('sort_order')->orderBy('name')->get(),
            'stats' => [
                'related_rows' => $relatedRows->count(),
                'pictures' => $relatedRows->pluck('picture_id')->filter()->unique()->count(),
            ],
        ]);
    }

    public function update(
        Request $request,
        ObservedProduct $observedProduct,
        CatalogueAiProductIdResolver $catalogueAiProductIdResolver,
    ): RedirectResponse {
        $validated = $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'canonical_brand' => ['nullable', 'string', 'max:255'],
            'brand_line' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            // Enrichment fields
            'subcategory_name' => ['nullable', 'string', 'max:255'],
            'confidence' => ['nullable', 'in:A,B,C,D'],
            'confidence_reason' => ['nullable', 'string', 'max:255'],
            'has_variant' => ['nullable', 'in:Yes,No,Unknown'],
            'variant_types' => ['nullable', 'string', 'max:255'],
            'has_product_type' => ['nullable', 'in:Yes,No,Unknown'],
            'product_type_details' => ['nullable', 'string', 'max:255'],
            'official_site' => ['nullable', 'in:Yes,No,Unknown'],
            'official_site_url' => ['nullable', 'string', 'max:2000'],
            'best_source_url' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $brand = trim((string) ($validated['brand'] ?? ''));
        $canonicalBrand = trim((string) ($validated['canonical_brand'] ?? ''));

        if ($canonicalBrand === '' && $brand !== '') {
            $canonicalBrand = $brand;
        }

        $observedProduct->update([
            'product_name' => trim($validated['product_name']),
            'brand' => $brand,
            'canonical_brand' => $canonicalBrand,
            'brand_line' => trim((string) ($validated['brand_line'] ?? '')) ?: null,
            'category_id' => $validated['category_id'] ?? null,
        ]);

        // Update enrichment record if it exists
        $productId = $catalogueAiProductIdResolver->productIdForObservedProduct($observedProduct);

        if ($productId !== null) {
            $enrichment = CatalogueAiEnrichment::query()->where('product_id', $productId)->first();

            if ($enrichment) {
                $enrichment->update([
                    'subcategory_name' => trim((string) ($validated['subcategory_name'] ?? '')) ?: null,
                    'confidence' => $validated['confidence'] ?? null,
                    'confidence_reason' => trim((string) ($validated['confidence_reason'] ?? '')) ?: null,
                    'has_variant' => $validated['has_variant'] ?? null,
                    'variant_types' => trim((string) ($validated['variant_types'] ?? '')) ?: null,
                    'has_product_type' => $validated['has_product_type'] ?? null,
                    'product_type_details' => trim((string) ($validated['product_type_details'] ?? '')) ?: null,
                    'official_site' => $validated['official_site'] ?? null,
                    'official_site_url' => trim((string) ($validated['official_site_url'] ?? '')) ?: null,
                    'best_source_url' => trim((string) ($validated['best_source_url'] ?? '')) ?: null,
                    'notes' => trim((string) ($validated['notes'] ?? '')) ?: null,
                ]);
            }
        }

        return redirect()
            ->route('products.show', $observedProduct)
            ->with('status', 'Product updated successfully.');
    }

    public function purgeDuplicates(): RedirectResponse
    {
        $keepIds = ObservedProduct::query()
            ->selectRaw('MIN(id) as id')
            ->groupBy('picture_id', 'brand', 'product_name')
            ->pluck('id');

        $deleted = ObservedProduct::query()->whereNotIn('id', $keepIds)->delete();

        return redirect()->route('products.duplicates')
            ->with('status', "Deleted {$deleted} duplicate row(s). One original kept per group.");
    }

    /** @return array{groups: int, rows: int} */
    private function getDupStats(): array
    {
        $result = ObservedProduct::query()
            ->selectRaw('picture_id, brand, product_name, COUNT(*) as cnt')
            ->groupBy('picture_id', 'brand', 'product_name')
            ->having('cnt', '>', 1)
            ->get();

        return [
            'groups' => $result->count(),
            'rows'   => (int) $result->sum('cnt'),
        ];
    }

    public function analysis(Request $request): View
    {
        $minSimilarity = (int) $request->input('min_similarity', 60);
        $pictureRange = PictureRange::fromRequest($request);

        // Get all products with valid brands
        $productsQuery = ObservedProduct::query()
            ->whereNotNull('canonical_brand')
            ->where('canonical_brand', '!=', '');

        $pictureRange->apply($productsQuery);

        $productsByBrand = $productsQuery
            ->with('category')
            ->orderBy('canonical_brand')
            ->orderBy('product_name')
            ->get()
            ->groupBy('canonical_brand');

        $families = collect();

        foreach ($productsByBrand as $brand => $products) {
            if ($products->count() < 2) continue;

            // Cluster similar products
            $clusters = $this->clusterSimilarProducts($products->toArray(), $minSimilarity);

            foreach ($clusters as $cluster) {
                if (count($cluster) > 1) {
                    $families->push([
                        'brand'    => $brand,
                        'products' => $cluster,
                        'count'    => count($cluster),
                    ]);
                }
            }
        }

        // Sort by cluster size (largest first)
        $families = $families->sortByDesc('count')->take(100)->values();

        // Brand options: existing catalogue brands + distinct observed canonical brands
        $catalogueBrands = Brand::query()->orderBy('name')->get(['id', 'name']);
        $observedBrands  = ObservedProduct::query()
            ->select('canonical_brand')
            ->distinct()
            ->orderBy('canonical_brand')
            ->pluck('canonical_brand')
            ->filter()
            ->values();

        return view('products.analysis', [
            'families'        => $families,
            'minSimilarity'   => $minSimilarity,
            'filters'         => $pictureRange->toFilterArray(),
            'totalClusters'   => $families->count(),
            'brandCount'      => $productsByBrand->count(),
            'catalogueBrands' => $catalogueBrands,
            'observedBrands'  => $observedBrands,
        ]);
    }

    public function groupFamily(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_ids'   => ['required', 'array', 'min:1'],
            'product_ids.*' => ['required', 'integer', 'exists:observed_products,id'],
            'family_name'   => ['required', 'string', 'max:255'],
            'brand_name'    => ['required', 'string', 'max:255'],
        ]);

        $brandName = trim($validated['brand_name']);

        // Find or create the brand
        $brand = Brand::query()->firstOrCreate(
            ['name' => $brandName],
            [
                'slug'       => $this->makeUniqueBrandSlug($brandName),
                'is_active'  => true,
                'is_generic' => false,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]
        );

        // Make a unique slug for the family within this brand
        $familyName = trim($validated['family_name']);
        $baseSlug   = Str::slug($familyName) ?: 'family';
        $slug       = $baseSlug;
        $suffix     = 2;
        while (CatalogueFamily::query()->where('brand_id', $brand->id)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        $family = CatalogueFamily::query()->create([
            'brand_id'            => $brand->id,
            'product_family_name' => $familyName,
            'slug'                => $slug,
            'status'              => 'identified',
            'created_by'          => $request->user()?->id,
            'updated_by'          => $request->user()?->id,
        ]);

        // Create a CatalogueType for each selected observed product
        $products = ObservedProduct::query()
            ->whereIn('id', $validated['product_ids'])
            ->get();

        foreach ($products as $product) {
            $typeSlug = Str::slug($product->product_name) ?: 'type';
            CatalogueType::query()->create([
                'catalogue_family_id' => $family->id,
                'name'                => $product->product_name,
                'slug'                => $typeSlug,
                'status'              => 'draft',
                'created_by'          => $request->user()?->id,
                'updated_by'          => $request->user()?->id,
            ]);
        }

        return redirect()->route('families.show', $family)
            ->with('status', "Family \"{$family->product_family_name}\" created with {$products->count()} type(s).");
    }

    public function deleteSelected(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_ids'   => ['required', 'array', 'min:1'],
            'product_ids.*' => ['required', 'integer', 'exists:observed_products,id'],
        ]);

        $deleted = ObservedProduct::query()
            ->whereIn('id', $validated['product_ids'])
            ->delete();

        return redirect()->route('products.analysis', $request->only('min_similarity', 'picture_from', 'picture_to'))
            ->with('status', "Deleted {$deleted} product(s).");
    }

    public function renameSelected(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_ids'    => ['required', 'array', 'min:1'],
            'product_ids.*'  => ['required', 'integer', 'exists:observed_products,id'],
            'new_name'       => ['required', 'string', 'max:255'],
        ]);

        $count = ObservedProduct::query()
            ->whereIn('id', $validated['product_ids'])
            ->update(['product_name' => trim($validated['new_name'])]);

        return redirect()->route('products.analysis', $request->only('min_similarity', 'picture_from', 'picture_to'))
            ->with('status', "Renamed {$count} product(s) to \"{$validated['new_name']}\".");
    }

    private function makeUniqueBrandSlug(string $name): string
    {
        $base   = Str::slug($name) ?: 'brand';
        $slug   = $base;
        $suffix = 2;
        while (Brand::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @param  array<int, mixed>  $products
     * @return array<int, array<int, mixed>>
     */
    private function clusterSimilarProducts(array $products, int $threshold): array
    {
        $clusters = [];
        $used = array_fill(0, count($products), false);

        foreach ($products as $i => $product) {
            if ($used[$i]) {
                continue;
            }

            $cluster = [$product];
            $used[$i] = true;

            foreach ($products as $j => $other) {
                if ($used[$j] || $i === $j) {
                    continue;
                }

                $similarity = $this->stringSimilarity(
                    $product['product_name'],
                    $other['product_name']
                );

                if ($similarity >= $threshold) {
                    $cluster[] = $other;
                    $used[$j] = true;
                }
            }

            $clusters[] = $cluster;
        }

        return $clusters;
    }

    private function stringSimilarity(string $str1, string $str2): int
    {
        $len1 = strlen($str1);
        $len2 = strlen($str2);
        $maxLen = max($len1, $len2);

        if ($maxLen === 0) {
            return 100;
        }

        similar_text($str1, $str2, $percent);

        return (int) round($percent);
    }
}
