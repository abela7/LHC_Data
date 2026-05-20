<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PictureOnlyProductController extends Controller
{
    public function brands(Request $request): View
    {
        $search = trim((string) $request->string('search')->value());
        $brandLists = $this->threeSourceBrandLists();

        if ($search !== '') {
            $needle = Str::lower($search);
            foreach ($brandLists as $source => $brands) {
                $brandLists[$source] = $brands
                    ->filter(function (array $brand) use ($needle): bool {
                        $searchText = implode(' ', array_filter([
                            $brand['display_name'] ?? '',
                            implode(' ', $brand['names'] ?? []),
                            implode(' ', $brand['source_brand_labels'] ?? []),
                            implode(' ', $brand['sample_products'] ?? []),
                        ]));

                        return Str::contains(Str::lower($searchText), $needle);
                    })
                    ->values();
            }
        }

        $maxRows = max($brandLists['pictures']->count(), $brandLists['janson']->count(), $brandLists['mamado']->count());
        $brandRows = collect($maxRows > 0 ? range(0, $maxRows - 1) : [])
            ->map(fn (int $index): array => [
                'picture' => $brandLists['pictures']->get($index),
                'janson' => $brandLists['janson']->get($index),
                'mamado' => $brandLists['mamado']->get($index),
            ]);

        return view('source-products.picture-brands', [
            'brandRows' => $brandRows,
            'search' => $search,
            'stats' => [
                'rows' => $brandRows->count(),
                'picture_brands' => $brandLists['pictures']->count(),
                'janson_brands' => $brandLists['janson']->count(),
                'mamado_brands' => $brandLists['mamado']->count(),
            ],
        ]);
    }

    public function showBrand(Request $request, string $brandKey): View
    {
        $search = trim((string) $request->string('search')->value());
        $brand = $this->pictureBrandGroups()->firstWhere('key', $brandKey);

        abort_if(! $brand, 404);

        $products = $this->pictureProductsForBrand($brandKey);

        if ($search !== '') {
            $needle = Str::lower($search);
            $products = $products
                ->filter(function (array $product) use ($needle): bool {
                    return Str::contains(Str::lower($product['product_name']), $needle)
                        || collect($product['observed_brand_labels'])->contains(fn (string $label): bool => Str::contains(Str::lower($label), $needle))
                        || collect($product['brand_lines'])->contains(fn (string $line): bool => Str::contains(Str::lower($line), $needle))
                        || collect($product['picture_ids'])->contains(fn (string $pictureId): bool => Str::contains(Str::lower($pictureId), $needle));
                })
                ->values();
        }

        return view('source-products.picture-brand', [
            'brand' => $brand,
            'products' => $products,
            'search' => $search,
            'stats' => [
                'products' => $products->count(),
                'picture_hits' => $products->sum('picture_hits'),
                'pictures' => $products->flatMap(fn (array $product): array => $product['picture_ids'])->unique()->count(),
                'brand_labels' => $products->flatMap(fn (array $product): array => $product['observed_brand_labels'])->unique()->count(),
            ],
        ]);
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search')->value());
        $catalogue = trim((string) $request->string('catalogue')->value());
        $brand = trim((string) $request->string('brand')->value());
        $productType = trim((string) $request->string('product_type')->value());
        $allowedPerPage = [50, 100, 200, 500];
        $perPage = (int) $request->integer('per_page', 100);

        if (! in_array($perPage, $allowedPerPage, true)) {
            $perPage = 100;
        }

        $query = $this->filteredQuery($search, $catalogue, $brand, $productType);

        $products = (clone $query)
            ->orderBy('catalogue_sort')
            ->orderBy('brand_name')
            ->orderBy('product_type_name')
            ->orderBy('family_name')
            ->orderBy('sku_name')
            ->paginate($perPage)
            ->withQueryString();

        $products->setCollection(
            $products->getCollection()->map(function (object $product): object {
                $product->first_picture_id = $this->firstPictureId((string) $product->note);
                $product->picture_ids = $this->pictureIds((string) $product->note);
                $product->observed_as = $this->observedAs((string) $product->note);
                $product->variant_summary = $product->variant_summary ?: 'Review pending';
                $product->sku_url = route('brand-catalogue.skus.show', [
                    $product->catalogue_id,
                    $product->brand_id,
                    $product->line_id,
                    $product->product_type_id,
                    $product->style_id,
                    $product->sku_id,
                ]);
                $product->picture_url = $product->first_picture_id
                    ? route('pictures.show', $product->first_picture_id)
                    : null;

                return $product;
            })
        );

        return view('source-products.picture-only', [
            'products' => $products,
            'search' => $search,
            'catalogue' => $catalogue,
            'brand' => $brand,
            'productType' => $productType,
            'allowedPerPage' => $allowedPerPage,
            'perPage' => $perPage,
            'catalogueOptions' => $this->optionValues('catalogue_name'),
            'brandOptions' => $this->optionValues('brand_name'),
            'productTypeOptions' => $this->optionValues('product_type_name'),
            'stats' => $this->stats(),
            'breakdown' => $this->breakdown(),
        ]);
    }

    private function filteredQuery(string $search, string $catalogue, string $brand, string $productType): Builder
    {
        return $this->baseQuery()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner
                        ->where('b.name', 'like', '%'.$search.'%')
                        ->orWhere('s.name', 'like', '%'.$search.'%')
                        ->orWhere('sku.name', 'like', '%'.$search.'%')
                        ->orWhere('pt.name', 'like', '%'.$search.'%')
                        ->orWhere('sku.note', 'like', '%'.$search.'%');
                });
            })
            ->when($catalogue !== '', fn (Builder $query) => $query->where('c.name', $catalogue))
            ->when($brand !== '', fn (Builder $query) => $query->where('b.name', $brand))
            ->when($productType !== '', fn (Builder $query) => $query->where('pt.name', $productType));
    }

    private function baseQuery(): Builder
    {
        $variantSummary = DB::table('brand_catalogue_sku_variant_options as svo')
            ->join('brand_catalogue_variants as v', 'v.id', '=', 'svo.brand_catalogue_variant_id')
            ->join('brand_catalogue_variant_options as vo', 'vo.id', '=', 'svo.brand_catalogue_variant_option_id')
            ->selectRaw("
                svo.brand_catalogue_sku_id,
                GROUP_CONCAT(CONCAT(v.name, ': ', vo.label) ORDER BY v.sort_order, vo.sort_order SEPARATOR ' | ') as variant_summary
            ")
            ->groupBy('svo.brand_catalogue_sku_id');

        return DB::table('brand_catalogue_skus as sku')
            ->join('brand_catalogue_styles as s', 's.id', '=', 'sku.brand_catalogue_style_id')
            ->join('brand_catalogue_product_types as pt', 'pt.id', '=', 's.brand_catalogue_product_type_id')
            ->join('brand_catalogue_lines as l', 'l.id', '=', 'pt.brand_catalogue_line_id')
            ->join('brand_catalogue_brands as b', 'b.id', '=', 's.brand_catalogue_brand_id')
            ->join('brand_catalogues as c', 'c.id', '=', 'b.brand_catalogue_id')
            ->leftJoinSub($variantSummary, 'variants', 'variants.brand_catalogue_sku_id', '=', 'sku.id')
            ->where('sku.note', 'like', '%Shop picture evidence:%')
            ->where('sku.note', 'not like', '%PDF staging match:%')
            ->where('sku.note', 'not like', '%Mamado match:%')
            ->where('sku.note', 'not like', '%Janson match:%')
            ->select([
                'sku.id as sku_id',
                'sku.name as sku_name',
                'sku.note',
                'sku.option_signature',
                's.id as style_id',
                's.name as family_name',
                'pt.id as product_type_id',
                'pt.name as product_type_name',
                'l.id as line_id',
                'l.name as line_name',
                'b.id as brand_id',
                'b.name as brand_name',
                'c.id as catalogue_id',
                'c.name as catalogue_name',
                'c.sort_order as catalogue_sort',
                DB::raw('variants.variant_summary as variant_summary'),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function optionValues(string $field): array
    {
        $column = match ($field) {
            'catalogue_name' => 'c.name',
            'brand_name' => 'b.name',
            'product_type_name' => 'pt.name',
            default => throw new \InvalidArgumentException("Unsupported option field: {$field}"),
        };

        return $this->baseQuery()
            ->distinct()
            ->selectRaw($column.' as option_value')
            ->orderBy($column)
            ->pluck('option_value')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        $rows = $this->baseQuery()->get();

        return [
            'products' => $rows->count(),
            'brands' => $rows->pluck('brand_id')->unique()->count(),
            'families' => $rows->pluck('style_id')->unique()->count(),
            'catalogues' => $rows->pluck('catalogue_id')->unique()->count(),
            'review_pending_variants' => $rows->filter(fn (object $row): bool => blank($row->variant_summary))->count(),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function breakdown(): \Illuminate\Support\Collection
    {
        return $this->baseQuery()
            ->select([
                'c.name as catalogue_name',
                DB::raw('COUNT(DISTINCT sku.id) as product_count'),
                DB::raw('COUNT(DISTINCT b.id) as brand_count'),
            ])
            ->groupBy('c.name', 'c.sort_order')
            ->orderBy('c.sort_order')
            ->get();
    }

    private function firstPictureId(string $note): ?string
    {
        preg_match('/picture\d+/i', $note, $matches);

        return $matches[0] ?? null;
    }

    /**
     * @return array<int, string>
     */
    private function pictureIds(string $note): array
    {
        preg_match_all('/picture\d+/i', $note, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $id): string => Str::lower($id))
            ->unique()
            ->values()
            ->all();
    }

    private function observedAs(string $note): string
    {
        if (preg_match('/observed as (.*?)(?:\.| PDF staging| Mamado| Review)/i', $note, $matches)) {
            return trim((string) $matches[1]);
        }

        return '';
    }

    private function pictureBrandGroups(): \Illuminate\Support\Collection
    {
        $sourceMatches = $this->supplierBrandMatches();

        return $this->pictureRows()
            ->groupBy(fn (object $row): string => $this->brandKey((string) $row->display_brand))
            ->map(function (\Illuminate\Support\Collection $rows, string $brandKey): array {
                $displayName = $this->mostCommon($rows->pluck('display_brand')) ?: 'Unknown';
                $products = $rows
                    ->groupBy(fn (object $row): string => $this->productKey((string) $row->product_name))
                    ->map(fn (\Illuminate\Support\Collection $productRows): string => $this->mostCommon($productRows->pluck('product_name')) ?: 'Unknown product')
                    ->sortBy(fn (string $value): string => $value, SORT_NATURAL | SORT_FLAG_CASE)
                    ->values();
                $sourceMatch = $sourceMatches[$brandKey] ?? [
                    'has_janson' => false,
                    'has_mamado' => false,
                    'source_names' => [],
                    'janson_products' => 0,
                    'mamado_products' => 0,
                ];

                return [
                    'key' => $brandKey,
                    'display_name' => $displayName,
                    'products' => $products->count(),
                    'picture_hits' => $rows->count(),
                    'pictures' => $rows->pluck('picture_id')->unique()->count(),
                    'picture_ids' => $rows->pluck('picture_id')->unique()->sortBy(fn (string $value): string => $value, SORT_NATURAL)->values()->all(),
                    'source_brand_labels' => $rows->pluck('brand')->filter()->unique()->sortBy(fn (string $value): string => $value, SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
                    'brand_lines' => $rows->pluck('brand_line')->filter()->unique()->sortBy(fn (string $value): string => $value, SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
                    'sample_products' => $products->take(8)->all(),
                    'has_janson' => $sourceMatch['has_janson'],
                    'has_mamado' => $sourceMatch['has_mamado'],
                    'source_names' => $sourceMatch['source_names'],
                    'janson_products' => $sourceMatch['janson_products'],
                    'mamado_products' => $sourceMatch['mamado_products'],
                    'source_match_label' => $this->sourceMatchLabel((bool) $sourceMatch['has_janson'], (bool) $sourceMatch['has_mamado']),
                ];
            })
            ->sortBy('display_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function brandComparisonRows(): \Illuminate\Support\Collection
    {
        $pictureBrands = $this->pictureBrandGroups()->keyBy('key');
        $supplierMatches = collect($this->supplierBrandMatches());
        $keys = $pictureBrands->keys()
            ->merge($supplierMatches->keys())
            ->unique()
            ->values();

        return $keys
            ->map(function (string $key) use ($pictureBrands, $supplierMatches): array {
                $picture = $pictureBrands->get($key);
                $supplier = $supplierMatches->get($key, [
                    'has_janson' => false,
                    'has_mamado' => false,
                    'source_names' => [],
                    'janson_names' => [],
                    'mamado_names' => [],
                    'janson_products' => 0,
                    'mamado_products' => 0,
                ]);

                return [
                    'key' => $key,
                    'picture' => $picture,
                    'janson' => [
                        'names' => $supplier['janson_names'],
                        'display_name' => $supplier['janson_names'][0] ?? '',
                        'products' => $supplier['janson_products'],
                    ],
                    'mamado' => [
                        'names' => $supplier['mamado_names'],
                        'display_name' => $supplier['mamado_names'][0] ?? '',
                        'products' => $supplier['mamado_products'],
                    ],
                    'has_picture' => $picture !== null,
                    'has_janson' => (bool) $supplier['has_janson'],
                    'has_mamado' => (bool) $supplier['has_mamado'],
                    'sort_name' => $picture['display_name']
                        ?? $supplier['janson_names'][0]
                        ?? $supplier['mamado_names'][0]
                        ?? $key,
                ];
            })
            ->sortBy('sort_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * @return array{pictures:\Illuminate\Support\Collection<int,array<string,mixed>>,janson:\Illuminate\Support\Collection<int,array<string,mixed>>,mamado:\Illuminate\Support\Collection<int,array<string,mixed>>}
     */
    private function threeSourceBrandLists(): array
    {
        $supplierMatches = collect($this->supplierBrandMatches());
        $excludedHairExtensionKeys = $this->excludedHairExtensionBrandKeys();

        return [
            'pictures' => $this->pictureBrandGroups()
                ->map(fn (array $brand): array => [
                    'key' => $brand['key'],
                    'display_name' => $brand['display_name'],
                    'products' => $brand['products'],
                    'hits' => $brand['picture_hits'],
                    'pictures' => $brand['pictures'],
                    'source_brand_labels' => $brand['source_brand_labels'],
                    'sample_products' => $brand['sample_products'],
                    'url' => route('source-products.picture-brands.show', $brand['key']),
                ])
                ->reject(fn (array $brand): bool => in_array($brand['key'], $excludedHairExtensionKeys, true))
                ->sortBy('display_name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values(),
            'janson' => $supplierMatches
                ->filter(fn (array $brand): bool => (bool) $brand['has_janson'])
                ->map(fn (array $brand, string $key): array => [
                    'key' => $key,
                    'display_name' => $brand['janson_names'][0] ?? 'Unknown',
                    'names' => $brand['janson_names'],
                    'products' => $brand['janson_products'],
                    'url' => route('retail-products.index', ['source' => 'janson', 'brand' => $brand['janson_names'][0] ?? 'Unknown']),
                ])
                ->reject(fn (array $brand): bool => in_array($brand['key'], $excludedHairExtensionKeys, true))
                ->sortBy('display_name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values(),
            'mamado' => $supplierMatches
                ->filter(fn (array $brand): bool => (bool) $brand['has_mamado'])
                ->map(fn (array $brand, string $key): array => [
                    'key' => $key,
                    'display_name' => $brand['mamado_names'][0] ?? 'Unknown',
                    'names' => $brand['mamado_names'],
                    'products' => $brand['mamado_products'],
                    'url' => route('retail-products.index', ['source' => 'mamado', 'brand' => $brand['mamado_names'][0] ?? 'Unknown']),
                ])
                ->reject(fn (array $brand): bool => in_array($brand['key'], $excludedHairExtensionKeys, true))
                ->sortBy('display_name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values(),
        ];
    }

    /**
     * Keep this curated instead of excluding every brand in catalogue 1, because some
     * hair-care/body-care brands also exist in that catalogue from earlier imports.
     *
     * @return array<int, string>
     */
    private function excludedHairExtensionBrandKeys(): array
    {
        return collect([
            '1st Lady Platinum Collection',
            'Aftress',
            'Angels',
            'Cherish',
            'Dignity',
            'Echo Collection',
            'EI Hair Extensions',
            'European Weave',
            'Fashion Idol',
            'Fashion Idol Classic Brazilian',
            'Fashion Idol Express',
            'Impression',
            "It's Braid",
            'Kali',
            'Kali Essential',
            'Kara',
            'Koko',
            'Kuknus',
            'Kuknus Braid',
            'Kuknus Collection',
            'Noble',
            'Obsession',
            'Pure NaturALL',
            'Remy Chaser',
            'Remy Couture',
            'Remy Gorgeous',
            'Sensationnel',
            'Sleek',
            'Sleek Brazilian',
            'Sleek Hair',
            'Smart',
            'Smart Braid',
            'Spetra',
            'Style Icon',
            'Stylejiang',
            'Vivitress',
            'X Smart',
            'X.Smart',
            'X-Pression',
            'Xpression',
        ])
            ->map(fn (string $brand): string => $this->brandKey($brand))
            ->unique()
            ->values()
            ->all();
    }

    private function pictureProductsForBrand(string $brandKey): \Illuminate\Support\Collection
    {
        return $this->pictureRows()
            ->filter(fn (object $row): bool => $this->brandKey((string) $row->display_brand) === $brandKey)
            ->groupBy(fn (object $row): string => $this->productKey((string) $row->product_name))
            ->map(function (\Illuminate\Support\Collection $rows): array {
                $productName = $this->mostCommon($rows->pluck('product_name')) ?: 'Unknown product';
                $firstRow = $rows->sortBy('id')->first();
                $pictureIds = $rows->pluck('picture_id')->filter()->unique()->sortBy(fn (string $value): string => $value, SORT_NATURAL)->values();

                return [
                    'product_name' => $productName,
                    'picture_hits' => $rows->count(),
                    'pictures' => $pictureIds->count(),
                    'picture_ids' => $pictureIds->all(),
                    'first_picture_id' => $pictureIds->first(),
                    'first_observed_product_id' => $firstRow?->id,
                    'observed_brand_labels' => $rows->pluck('brand')->filter()->unique()->sortBy(fn (string $value): string => $value, SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
                    'brand_lines' => $rows->pluck('brand_line')->filter()->unique()->sortBy(fn (string $value): string => $value, SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
                ];
            })
            ->sortBy('product_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function pictureRows(): \Illuminate\Support\Collection
    {
        return DB::table('observed_products')
            ->select([
                'id',
                'picture_id',
                'brand',
                'canonical_brand',
                'brand_line',
                'product_name',
                DB::raw("COALESCE(NULLIF(canonical_brand, ''), brand) as display_brand"),
            ])
            ->orderBy('picture_id')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @return array<string, array{has_janson:bool,has_mamado:bool,source_names:array<int,string>,janson_names:array<int,string>,mamado_names:array<int,string>,janson_products:int,mamado_products:int}>
     */
    private function supplierBrandMatches(): array
    {
        return DB::table('product_sources as ps')
            ->join('product_families as pf', 'pf.id', '=', 'ps.product_family_id')
            ->whereIn('ps.source_type', ['janson_product', 'mamado_product'])
            ->whereNotNull('ps.product_id')
            ->where(function ($query): void {
                $query->whereNull('pf.root_catalogue_name')
                    ->orWhere('pf.root_catalogue_name', '<>', 'Hair Extensions');
            })
            ->select([
                'pf.brand_name',
                'ps.source_type',
                DB::raw('COUNT(DISTINCT ps.product_id) as product_count'),
            ])
            ->groupBy('pf.brand_name', 'ps.source_type')
            ->get()
            ->groupBy(fn (object $row): string => $this->brandKey((string) $row->brand_name))
            ->map(function (\Illuminate\Support\Collection $rows): array {
                $jansonNames = $rows
                    ->where('source_type', 'janson_product')
                    ->pluck('brand_name')
                    ->filter()
                    ->unique()
                    ->sortBy(fn (string $name): string => $name, SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all();
                $mamadoNames = $rows
                    ->where('source_type', 'mamado_product')
                    ->pluck('brand_name')
                    ->filter()
                    ->unique()
                    ->sortBy(fn (string $name): string => $name, SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all();

                return [
                    'has_janson' => $rows->contains('source_type', 'janson_product'),
                    'has_mamado' => $rows->contains('source_type', 'mamado_product'),
                    'source_names' => $rows->pluck('brand_name')->filter()->unique()->sortBy(fn (string $name): string => $name, SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
                    'janson_names' => $jansonNames,
                    'mamado_names' => $mamadoNames,
                    'janson_products' => (int) $rows->where('source_type', 'janson_product')->sum('product_count'),
                    'mamado_products' => (int) $rows->where('source_type', 'mamado_product')->sum('product_count'),
                ];
            })
            ->all();
    }

    private function sourceMatchLabel(bool $hasJanson, bool $hasMamado): string
    {
        if ($hasJanson && $hasMamado) {
            return 'Janson + Mamado';
        }
        if ($hasJanson) {
            return 'Janson only';
        }
        if ($hasMamado) {
            return 'Mamado only';
        }

        return 'No supplier match';
    }

    private function mostCommon(\Illuminate\Support\Collection $values): ?string
    {
        return $values
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();
    }

    private function brandKey(string $brand): string
    {
        return Str::slug($this->normalizeText($brand)) ?: 'unknown';
    }

    private function productKey(string $product): string
    {
        return Str::slug($this->normalizeText($product)) ?: 'unknown';
    }

    private function normalizeText(string $value): string
    {
        $value = Str::ascii(Str::lower(trim($value)));
        $value = str_replace(['&', '+'], ' and ', $value);
        $value = preg_replace('/\b(?:ltd|limited|inc|llc|co)\b/', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
