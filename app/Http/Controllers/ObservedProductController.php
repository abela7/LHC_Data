<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueStyle;
use App\Models\HairExtensionIntake;
use App\Models\IntakeSession;
use App\Models\ObservedBrandMapping;
use App\Models\ObservedProduct;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Support\JsonPayloadCleaner;
use App\Support\ObservedBrandVerdict;
use App\Support\ObservedProductCategoryResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class ObservedProductController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search')->value());
        $brandFilter = (string) $request->string('brand')->value();
        $categoryFilter = (string) $request->string('category')->value();

        $observedProductsQuery = ObservedProduct::query()
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $subQuery) use ($search) {
                    $subQuery
                        ->where('picture_id', 'like', '%'.$search.'%')
                        ->orWhere('brand', 'like', '%'.$search.'%')
                        ->orWhere('canonical_brand', 'like', '%'.$search.'%')
                        ->orWhere('brand_line', 'like', '%'.$search.'%')
                        ->orWhere('product_name', 'like', '%'.$search.'%');
                });
            })
            ->when($brandFilter !== '', function (Builder $query) use ($brandFilter) {
                if ($brandFilter === '__blank__') {
                    $query->where('canonical_brand', '');

                    return;
                }

                $query->where('canonical_brand', $brandFilter);
            })
            ->when($categoryFilter !== '', function (Builder $query) use ($categoryFilter) {
                $query->whereHas('category', function (Builder $categoryQuery) use ($categoryFilter) {
                    $categoryQuery->where('slug', $categoryFilter);
                });
            });

        return view('dashboard', [
            'observedProducts' => $observedProductsQuery
                ->with('category')
                ->latest()
                ->paginate(50)
                ->withQueryString(),
            'brandOptions' => ObservedProduct::query()
                ->select('canonical_brand')
                ->distinct()
                ->orderBy('canonical_brand')
                ->pluck('canonical_brand')
                ->all(),
            'categoryOptions' => Category::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'filters' => [
                'search' => $search,
                'brand' => $brandFilter,
                'category' => $categoryFilter,
            ],
            'stats' => [
                'product_rows' => ObservedProduct::query()->count(),
                'pictures' => ObservedProduct::query()->distinct('picture_id')->count('picture_id'),
                'real_brands' => ObservedProduct::query()
                    ->where('canonical_brand', '!=', '')
                    ->distinct('canonical_brand')
                    ->count('canonical_brand'),
                'categories' => Category::query()
                    ->whereIn('slug', ['hair', 'body-care', 'cosmetics'])
                    ->count(),
            ],
            'hairDashboard' => $this->hairExtensionDashboard(),
        ]);
    }

    private function hairExtensionDashboard(): array
    {
        $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->first();

        $catalogueBrandCount = $catalogue
            ? BrandCatalogueBrand::query()->where('brand_catalogue_id', $catalogue->id)->count()
            : 0;

        $catalogueStyleCount = $catalogue
            ? BrandCatalogueStyle::query()
                ->whereHas('brand', fn ($query) => $query->where('brand_catalogue_id', $catalogue->id))
                ->count()
            : 0;

        return [
            'catalogue_id' => $catalogue?->id ?? 1,
            'stats' => [
                'submitted_intakes' => HairExtensionIntake::query()->where('status', 'submitted')->count(),
                'catalogue_brands' => $catalogueBrandCount,
                'catalogue_styles' => $catalogueStyleCount,
                'retail_families' => ProductFamily::query()->where('root_catalogue_name', 'Hair Extensions')->count(),
                'sellable_skus' => Product::query()
                    ->whereHas('family', fn ($query) => $query->where('root_catalogue_name', 'Hair Extensions'))
                    ->count(),
                'active_sessions' => IntakeSession::query()
                    ->whereNotIn('status', ['published', 'abandoned'])
                    ->count(),
            ],
        ];
    }

    public function store(Request $request, JsonPayloadCleaner $jsonPayloadCleaner, ObservedProductCategoryResolver $categoryResolver): RedirectResponse
    {
        $validated = $request->validate([
            'json_payload' => ['required', 'string'],
        ]);

        $payloadPreview = $jsonPayloadCleaner->clean($validated['json_payload']);
        $decoded = json_decode($payloadPreview['cleaned_payload'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->redirectWithPayloadError(
                message: 'The JSON could not be decoded even after auto-cleaning: '.json_last_error_msg(),
                payloadPreview: $payloadPreview,
            );
        }

        $picturePayloads = $this->normalizePicturePayloads($decoded);

        if ($picturePayloads === null) {
            return $this->redirectWithPayloadError(
                message: 'Use one picture JSON object, an array of picture objects, or a photos wrapper.',
                payloadPreview: $payloadPreview,
            );
        }

        $validator = Validator::make(['photos' => $picturePayloads], [
            'photos' => ['required', 'array', 'min:1'],
            'photos.*.picture_id' => ['required', 'string', 'max:255'],
            'photos.*.products' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*.products.*.brand' => ['nullable', 'string', 'max:255'],
            'photos.*.products.*.product_name' => ['required', 'string', 'max:255'],
        ], [
            'photos.*.products.max' => 'Each picture JSON can contain at most 10 products.',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('dashboard')
                ->withErrors($validator)
                ->withInput([
                    'json_payload' => $payloadPreview['cleaned_payload'],
                ]);
        }

        $rows = [];

        $importedRows = 0;
        $pictureIds = [];

        foreach ($picturePayloads as $picturePayload) {
            $pictureId = trim((string) $picturePayload['picture_id']);
            $pictureIds[] = $pictureId;

            foreach ($picturePayload['products'] as $index => $product) {
                $brand = trim((string) ($product['brand'] ?? ''));
                $brandVerdict = $this->resolveBrandVerdict($brand);

                $rows[] = [
                    'picture_id' => $pictureId,
                    'sort_order' => $index + 1,
                    'brand' => $brand,
                    'canonical_brand' => $brandVerdict['canonical_brand'],
                    'brand_line' => $brandVerdict['brand_line'],
                    'category_id' => $categoryResolver->resolveCategoryId(trim((string) $product['product_name'])),
                    'product_name' => trim((string) $product['product_name']),
                ];
            }
        }

        [$rowsToImport, $duplicateRows] = $this->partitionDuplicateRows($rows);

        foreach ($rowsToImport as $row) {
            ObservedProduct::query()->create([
                'picture_id' => $row['picture_id'],
                'sort_order' => $row['sort_order'],
                'brand' => $row['brand'],
                'canonical_brand' => $row['canonical_brand'],
                'brand_line' => $row['brand_line'],
                'category_id' => $row['category_id'],
                'product_name' => $row['product_name'],
            ]);

            $importedRows++;
        }

        $pictureCount = count(array_unique($pictureIds));
        $status = "Imported {$importedRows} product row(s)";

        if ($importedRows > 0 && $pictureCount === 1) {
            $status .= " from {$pictureIds[0]}.";
        } elseif ($importedRows > 0) {
            $status .= " from {$pictureCount} pictures.";
        }

        $response = redirect()->route('dashboard');

        if ($importedRows > 0) {
            $response->with('status', $status);
        }

        if ($duplicateRows !== []) {
            $response->with('warning', $this->buildDuplicateWarning($duplicateRows, $importedRows));
        }

        if ($importedRows === 0 && $duplicateRows === []) {
            $response->with('warning', 'No rows were imported.');
        }

        return $response;
    }

    public function update(Request $request, ObservedProduct $observedProduct): RedirectResponse
    {
        $validated = $request->validate([
            'brand' => ['nullable', 'string', 'max:255'],
            'canonical_brand' => ['nullable', 'string', 'max:255'],
            'brand_line' => ['nullable', 'string', 'max:255'],
            'product_name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'return_to' => ['nullable', 'string', 'max:2000'],
        ]);

        $brand = trim((string) ($validated['brand'] ?? ''));
        $canonicalBrand = trim((string) ($validated['canonical_brand'] ?? ''));
        $brandLine = trim((string) ($validated['brand_line'] ?? ''));
        $productName = trim((string) $validated['product_name']);

        if ($canonicalBrand === '' && $brand !== '') {
            $canonicalBrand = $brand;
        }

        $duplicateExists = ObservedProduct::query()
            ->where('id', '!=', $observedProduct->id)
            ->where('picture_id', $observedProduct->picture_id)
            ->where('brand', $brand)
            ->where('product_name', $productName)
            ->exists();

        if ($duplicateExists) {
            return redirect()
                ->to($validated['return_to'] ?: route('pictures.show', ['pictureId' => $observedProduct->picture_id]))
                ->withErrors([
                    'product_name' => 'Another row in this picture already has the same brand and product name.',
                ]);
        }

        $observedProduct->update([
            'brand' => $brand,
            'canonical_brand' => $canonicalBrand,
            'brand_line' => $brandLine !== '' ? $brandLine : null,
            'product_name' => $productName,
            'category_id' => $validated['category_id'] ?? null,
        ]);

        return redirect()
            ->to($validated['return_to'] ?: route('pictures.show', ['pictureId' => $observedProduct->picture_id]))
            ->with('status', "Updated row {$observedProduct->id}.");
    }

    /**
     * @param  array<int, array{picture_id: string, sort_order: int, brand: string, canonical_brand: string, brand_line: ?string, product_name: string}>  $rows
     * @return array{0: array<int, array{picture_id: string, sort_order: int, brand: string, canonical_brand: string, brand_line: ?string, product_name: string}>, 1: array<int, string>}
     */
    private function partitionDuplicateRows(array $rows): array
    {
        $rowsToImport = [];
        $duplicates = [];
        $seenInPayload = [];

        foreach ($rows as $row) {
            $duplicateKey = $this->makeDuplicateKey($row['picture_id'], $row['brand'], $row['product_name']);

            if (isset($seenInPayload[$duplicateKey])) {
                $duplicates[] = $this->formatDuplicateRow($row['picture_id'], $row['brand'], $row['product_name']).' (repeated in this JSON)';
                continue;
            }

            $seenInPayload[$duplicateKey] = true;

            $exists = ObservedProduct::query()
                ->where('picture_id', $row['picture_id'])
                ->get(['picture_id', 'brand', 'product_name'])
                ->contains(fn (ObservedProduct $product) => $product->picture_id === $row['picture_id']
                    && $product->brand === $row['brand']
                    && $product->product_name === $row['product_name']);

            if ($exists) {
                $duplicates[] = $this->formatDuplicateRow($row['picture_id'], $row['brand'], $row['product_name']);
                continue;
            }

            $rowsToImport[] = $row;
        }

        return [$rowsToImport, array_values(array_unique($duplicates))];
    }

    private function makeDuplicateKey(string $pictureId, string $brand, string $productName): string
    {
        return $pictureId.'|'.$brand.'|'.$productName;
    }

    /**
     * @return array{canonical_brand: string, brand_line: ?string, official_source_url: ?string, notes: ?string}
     */
    private function resolveBrandVerdict(string $brand): array
    {
        if ($brand === '') {
            return ObservedBrandVerdict::resolve($brand);
        }

        $mapping = ObservedBrandMapping::query()->firstOrCreate(
            ['observed_brand' => $brand],
            ObservedBrandVerdict::resolve($brand),
        );

        return [
            'canonical_brand' => $mapping->canonical_brand,
            'brand_line' => $mapping->brand_line,
            'official_source_url' => $mapping->official_source_url,
            'notes' => $mapping->notes,
        ];
    }

    private function formatDuplicateRow(string $pictureId, string $brand, string $productName): string
    {
        $brandLabel = $brand !== '' ? $brand : '[blank brand]';

        return "{$pictureId} / {$brandLabel} / {$productName}";
    }

    /**
     * @param  array<int, string>  $duplicateRows
     */
    private function buildDuplicateWarning(array $duplicateRows, int $importedRows): string
    {
        $count = count($duplicateRows);
        $examples = array_slice($duplicateRows, 0, 3);
        $prefix = $importedRows > 0
            ? "Skipped {$count} duplicate row(s) already entered."
            : "No new rows imported. {$count} duplicate row(s) were already entered.";

        if ($examples === []) {
            return $prefix;
        }

        $suffix = ' Example: '.implode(' | ', $examples);

        if ($count > count($examples)) {
            $suffix .= ' | and '.($count - count($examples)).' more';
        }

        return $prefix.$suffix;
    }

    /**
     * @param  array{cleaned_payload: string, changed: bool, cleanup_notes: array<int, string>}  $payloadPreview
     */
    private function redirectWithPayloadError(string $message, array $payloadPreview): RedirectResponse
    {
        $response = redirect()
            ->route('dashboard')
            ->withErrors(['json_payload' => $message])
            ->withInput([
                'json_payload' => $payloadPreview['cleaned_payload'],
            ]);

        if ($payloadPreview['changed']) {
            $response->with('cleaned_json_preview', $payloadPreview['cleaned_payload']);
            $response->with('payload_cleanup_notes', $payloadPreview['cleanup_notes']);
        }

        return $response;
    }

    /**
     * @param  mixed  $decoded
     * @return array<int, array<string, mixed>>|null
     */
    private function normalizePicturePayloads(mixed $decoded): ?array
    {
        if (! is_array($decoded)) {
            return null;
        }

        if (array_is_list($decoded)) {
            return array_values(array_filter($decoded, fn ($item) => is_array($item)));
        }

        if (isset($decoded['photos']) && is_array($decoded['photos'])) {
            return array_values(array_filter($decoded['photos'], fn ($item) => is_array($item)));
        }

        return [$decoded];
    }
}
