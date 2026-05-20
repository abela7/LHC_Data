<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\ProductFamily;
use App\Models\ShopProductIntake;
use App\Models\ShopProductIntakeOption;
use App\Services\ShopSkuNamingSuggestionService;
use App\Services\ShopStructureSuggestionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShopProductIntakeController extends Controller
{
    public function index(): View
    {
        return $this->renderForm(null);
    }

    public function edit(ShopProductIntake $intake): View
    {
        return $this->renderForm($intake);
    }

    private function renderForm(?ShopProductIntake $intake): View
    {
        return view('shop-product-intake.index', [
            'brands' => $this->brandOptions(),
            'departmentOptions' => $this->departmentOptions(),
            'productTypeOptions' => $this->productTypeOptions(),
            'sourceData' => $this->sourceSuggestionData(),
            'submittedUrl' => route('shop-product-intake.submitted'),
            'normalizationUrl' => route('shop-product-intake.normalization.index'),
            'submitUrl' => $intake
                ? route('shop-product-intake.update', $intake)
                : route('shop-product-intake.store'),
            'quickBrandUrl' => route('shop-product-intake.quick-brand'),
            'quickOptionUrl' => route('shop-product-intake.quick-option'),
            'structureSuggestUrl' => route('shop-product-intake.suggest-structure'),
            'skuNameSuggestUrl' => route('shop-product-intake.suggest-sku-names'),
            'intake' => $intake,
            'editPayload' => $intake ? $this->intakeFormPayload($intake) : null,
            'destroyUrl' => $intake ? route('shop-product-intake.destroy', $intake) : null,
            'newIntakeUrl' => route('shop-product-intake.index'),
        ]);
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = $this->cleanText($data['name']);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter the brand name.']);
        }

        $brand = Brand::query()
            ->whereRaw('lower(name) = ?', [Str::lower($name)])
            ->first();

        if (! $brand) {
            $brand = Brand::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug('brands', $name),
                'is_active' => true,
                'is_generic' => false,
                'notes' => 'Added from shop product intake.',
            ]);
        } elseif (! $brand->is_active) {
            $brand->update(['is_active' => true]);
        }

        return response()->json([
            'ok' => true,
            'option' => [
                'name' => $brand->name,
                'label' => $brand->name,
            ],
        ]);
    }

    public function storeOption(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:department,product_type'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = $this->cleanText($data['name']);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter the option name.']);
        }

        $option = ShopProductIntakeOption::query()
            ->where('option_type', $data['type'])
            ->whereRaw('lower(name) = ?', [Str::lower($name)])
            ->first();

        if (! $option) {
            $option = ShopProductIntakeOption::query()->create([
                'option_type' => $data['type'],
                'name' => $name,
                'is_active' => true,
            ]);
        } elseif (! $option->is_active) {
            $option->update(['is_active' => true]);
        }

        return response()->json([
            'ok' => true,
            'option' => [
                'type' => $option->option_type,
                'name' => $option->name,
            ],
        ]);
    }

    public function suggestStructure(Request $request, ShopStructureSuggestionService $suggestions): JsonResponse
    {
        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:255'],
            'family_name' => ['required', 'string', 'max:255'],
            'current_department_name' => ['nullable', 'string', 'max:255'],
            'current_product_type_name' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'ok' => true,
            ...$suggestions->suggest($data),
        ]);
    }

    public function suggestSkuNames(Request $request, ShopSkuNamingSuggestionService $suggestions): JsonResponse
    {
        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:255'],
            'department_name' => ['nullable', 'string', 'max:255'],
            'product_type_name' => ['nullable', 'string', 'max:255'],
            'family_name' => ['required', 'string', 'max:255'],
            'variant_main_axis' => ['nullable', 'string', 'max:80'],
            'variant_sub_axis' => ['nullable', 'string', 'max:80'],
            'variant_rows' => ['nullable', 'array'],
            'common_variants' => ['nullable', 'array'],
            'sku_rows' => ['required', 'array', 'min:1'],
        ]);

        return response()->json([
            'ok' => true,
            ...$suggestions->suggest($data),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $intake = $this->persistIntake($request, null);

        return redirect()
            ->route('shop-product-intake.index')
            ->with('status', $this->successMessage('Saved', $intake))
            ->with('saved_intake_id', $intake->id);
    }

    public function update(Request $request, ShopProductIntake $intake): RedirectResponse
    {
        $this->persistIntake($request, $intake);

        return redirect()
            ->route('shop-product-intake.edit', $intake)
            ->with('status', $this->successMessage('Updated', $intake->fresh()));
    }

    public function destroy(ShopProductIntake $intake): RedirectResponse
    {
        $intake->delete();

        return redirect()
            ->route('shop-product-intake.submitted')
            ->with('status', 'Deleted intake for '.$intake->brand_name.' / '.$intake->family_name.'.');
    }

    private function persistIntake(Request $request, ?ShopProductIntake $intake): ShopProductIntake
    {
        $data = $request->validate([
            'source_product_family_id' => ['nullable', 'integer', 'exists:product_families,id'],
            'brand_name' => ['required', 'string', 'max:255'],
            'department_name' => ['nullable', 'string', 'max:255'],
            'product_type_name' => ['nullable', 'string', 'max:255'],
            'family_name' => ['required', 'string', 'max:255'],
            'variant_main_axis' => ['nullable', 'string', 'max:80'],
            'variant_sub_axis' => ['nullable', 'string', 'max:80'],
            'variant_rows' => ['required', 'string'],
            'common_variants' => ['nullable', 'string'],
            'sku_rows' => ['nullable', 'string'],
            'shelf_ticket_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'shelf_location' => ['nullable', 'string', 'max:255'],
            'visible_text_notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $brandName = $this->cleanText($data['brand_name'] ?? '');
        $familyName = $this->cleanText($data['family_name'] ?? '');
        $mainAxis = $this->cleanText($data['variant_main_axis'] ?? '') ?: 'Size';
        $variantRows = $this->decodeVariantRows((string) ($data['variant_rows'] ?? ''));
        $commonVariants = $this->decodeCommonVariants((string) ($data['common_variants'] ?? ''));
        $skuRows = $this->decodeSkuRows((string) ($data['sku_rows'] ?? ''));

        if ($brandName === '') {
            throw ValidationException::withMessages(['brand_name' => 'Choose the brand.']);
        }

        if ($familyName === '') {
            throw ValidationException::withMessages(['family_name' => 'Enter the product family/name seen on the pack.']);
        }

        if ($variantRows === []) {
            throw ValidationException::withMessages(['variant_rows' => 'Add at least one sellable variant row.']);
        }

        $skuMatrix = $this->buildSkuMatrix($mainAxis, $variantRows, $commonVariants, $skuRows);
        $variantStructure = [
            'mode' => 'shop_intake',
            'source' => 'manual_shop_floor',
            'main_axis' => $mainAxis,
            'groups' => $variantRows,
            'common_variants' => $commonVariants,
            'sku_matrix' => $skuMatrix,
            'summary' => [
                'main_group_count' => count($variantRows),
                'common_variant_count' => count($commonVariants),
                'sellable_combination_count' => count($skuMatrix),
                'barcode_count' => collect($skuMatrix)->filter(fn (array $row): bool => filled($row['barcode'] ?? null))->count(),
            ],
        ];

        $payload = [
            'source_product_family_id' => $data['source_product_family_id'] ?? null,
            'brand_name' => $brandName,
            'department_name' => $this->nullTrim($data['department_name'] ?? null),
            'product_type_name' => $this->nullTrim($data['product_type_name'] ?? null),
            'family_name' => $familyName,
            'observed_product_name' => trim($brandName.' '.$familyName),
            'variant_groups' => $this->variantGroups($mainAxis, $variantRows, $commonVariants),
            'variant_structure' => $variantStructure,
            'sku_rows' => $skuMatrix,
            'shelf_ticket_price' => $data['shelf_ticket_price'] ?? null,
            'shelf_location' => $this->nullTrim($data['shelf_location'] ?? null),
            'visible_text_notes' => $this->nullTrim($data['visible_text_notes'] ?? null),
            'source_match_status' => ! empty($data['source_product_family_id']) ? 'suggestion_selected' : 'unmatched',
            'status' => 'submitted',
        ];

        if ($intake) {
            $intake->update($payload);

            return $intake;
        }

        $payload['submitted_at'] = now();

        return ShopProductIntake::query()->create($payload);
    }

    private function successMessage(string $verb, ShopProductIntake $intake): string
    {
        $skuRows = collect($intake->sku_rows ?? []);
        $sellable = $skuRows->count();
        $barcodes = $skuRows->filter(fn ($row) => filled($row['barcode'] ?? null))->count();

        return $verb.' '.$sellable.' sellable SKU'.($sellable === 1 ? '' : 's')
            .' with '.$barcodes.' barcode'.($barcodes === 1 ? '' : 's').'.';
    }

    /**
     * Convert a saved intake into the same shape the form's JS uses on first
     * load (matches the data-old-rows / data-old-common-variants / data-old-skus
     * attributes), so editing is just "render the form pre-filled".
     *
     * @return array<string, mixed>
     */
    private function intakeFormPayload(ShopProductIntake $intake): array
    {
        $structure = $intake->variant_structure ?? [];
        $variantRows = collect($structure['groups'] ?? [])
            ->map(fn ($row): array => [
                'main_value' => (string) ($row['main_value'] ?? ''),
                'sub_axis' => (string) ($row['sub_axis'] ?? 'Variant'),
                'sub_values' => array_values(array_filter(array_map('strval', (array) ($row['sub_values'] ?? [])))),
                'notes' => $row['notes'] ?? null,
            ])
            ->values()
            ->all();

        $commonVariants = collect($structure['common_variants'] ?? [])
            ->map(fn ($group): array => [
                'name' => (string) ($group['name'] ?? 'Common'),
                'values' => array_values(array_filter(array_map('strval', (array) ($group['values'] ?? [])))),
            ])
            ->filter(fn (array $group): bool => $group['values'] !== [])
            ->values()
            ->all();

        $skuRows = collect($intake->sku_rows ?? [])
            ->map(fn ($row): array => [
                'key' => (string) ($row['key'] ?? ''),
                'barcode' => (string) ($row['barcode'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'name_label' => (string) ($row['name_label'] ?? ''),
                'suggested_name' => (string) ($row['suggested_name'] ?? ''),
            ])
            ->filter(fn (array $row): bool => $row['key'] !== '')
            ->values()
            ->all();

        $subAxis = collect($variantRows)->pluck('sub_axis')->filter()->first() ?: 'Colour';

        return [
            'id' => $intake->id,
            'source_product_family_id' => $intake->source_product_family_id,
            'brand_name' => (string) $intake->brand_name,
            'department_name' => (string) $intake->department_name,
            'product_type_name' => (string) $intake->product_type_name,
            'family_name' => (string) $intake->family_name,
            'variant_main_axis' => (string) ($structure['main_axis'] ?? 'Size'),
            'variant_sub_axis' => $subAxis,
            'variant_rows' => $variantRows,
            'common_variants' => $commonVariants,
            'sku_rows' => $skuRows,
            'shelf_ticket_price' => $intake->shelf_ticket_price !== null
                ? (string) $intake->shelf_ticket_price
                : '',
            'shelf_location' => (string) $intake->shelf_location,
            'visible_text_notes' => (string) $intake->visible_text_notes,
            'submitted_at' => $intake->submitted_at?->format('d M Y H:i'),
        ];
    }

    public function submitted(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $brand = trim((string) $request->query('brand', ''));

        $intakes = ShopProductIntake::query()
            ->with('sourceFamily')
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $query->where(function ($query) use ($like): void {
                    $query->where('brand_name', 'like', $like)
                        ->orWhere('family_name', 'like', $like)
                        ->orWhere('product_type_name', 'like', $like)
                        ->orWhere('visible_text_notes', 'like', $like);
                });
            })
            ->when($brand !== '', fn ($query) => $query->where('brand_name', $brand))
            ->latest('submitted_at')
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('shop-product-intake.submitted', [
            'intakes' => $intakes,
            'search' => $search,
            'brand' => $brand,
            'brands' => ShopProductIntake::query()
                ->select('brand_name')
                ->selectRaw('count(*) as intake_count')
                ->groupBy('brand_name')
                ->orderBy('brand_name')
                ->get(),
            'intakeUrl' => route('shop-product-intake.index'),
        ]);
    }

    private function brandOptions(): Collection
    {
        /*
         * The shop floor needs to find every brand that has ever appeared in
         * our catalogue or in any source feed (Janson, Mamado, PDF catalogues,
         * Deliveroo, observed products). Otherwise legitimate shelf brands
         * like "Directions" — which today only exists in
         * deliveroo_official_products — show up as "missing" and force the
         * operator to manually re-add them.
         *
         * Each contributing table is folded into a single deduped, lowercased
         * list. The family_count badge keeps its existing meaning (number of
         * non-hair-extension product_families for that brand), which means
         * source-only brands appear with a "0" badge — a useful signal that
         * the brand has never been catalogued yet.
         */
        $brands = collect();

        // 1) Catalogued product families (excluding hair extensions — that
        //    workflow has its own intake page).
        $brands = $brands->merge(
            ProductFamily::query()
                ->where('root_catalogue_name', '!=', 'Hair Extensions')
                ->whereNotNull('brand_name')->where('brand_name', '!=', '')
                ->select('brand_name')
                ->selectRaw('count(*) as family_count')
                ->groupBy('brand_name')
                ->get()
                ->map(fn ($row): array => [
                    'name' => $row->brand_name,
                    'family_count' => (int) $row->family_count,
                ])
        );

        // 2) The real brands master table.
        $brands = $brands->merge(
            DB::table('brands')
                ->where('is_active', true)
                ->select('name')
                ->get()
                ->map(fn ($row): array => [
                    'name' => $row->name,
                    'family_count' => 0,
                ])
        );

        // 3) Brands seen in curated source feeds but not yet catalogued.
        // We deliberately skip observed_products and pdf_catalogue_pages — those
        // hold raw OCR/page-extracted text and pollute the list with garbage like
        // "AB", "AE", "(1X4) PER". Janson brands flow in via product_families
        // above (Janson rows are linked through product_sources -> product_families).
        $sourceTables = [
            ['mamado_products', 'brand_label'],
            ['pdf_catalogue_products', 'brand'],
            ['deliveroo_official_products', 'brand_label'],
        ];

        foreach ($sourceTables as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $brands = $brands->merge(
                DB::table($table)
                    ->whereNotNull($column)
                    ->where($column, '!=', '')
                    ->distinct()
                    ->pluck($column)
                    ->map(fn (?string $name): array => [
                        'name' => (string) $name,
                        'family_count' => 0,
                    ])
            );
        }

        // 4) Deliveroo manual brands carry their own list of brand-line names.
        if (Schema::hasTable('deliveroo_manual_brands')) {
            $cols = Schema::getColumnListing('deliveroo_manual_brands');
            $candidate = collect(['name', 'brand_name', 'label', 'brand_label'])
                ->first(fn (string $c): bool => in_array($c, $cols, true));

            if ($candidate !== null) {
                $brands = $brands->merge(
                    DB::table('deliveroo_manual_brands')
                        ->whereNotNull($candidate)
                        ->where($candidate, '!=', '')
                        ->distinct()
                        ->pluck($candidate)
                        ->map(fn (?string $name): array => [
                            'name' => (string) $name,
                            'family_count' => 0,
                        ])
                );
            }
        }

        return $brands
            ->filter(function (array $row): bool {
                $name = trim((string) $row['name']);
                if ($name === '') {
                    return false;
                }
                // Reject obviously broken entries: pure numbers, pure punctuation,
                // or strings starting with a parenthesis followed by digits — these
                // come from PDF text noise (e.g. "(1X4) PER").
                if (preg_match('/^\(?\d/', $name) === 1) {
                    return false;
                }
                // Require at least one alphabetic char.
                return preg_match('/[A-Za-z]/', $name) === 1;
            })
            ->groupBy(fn (array $row): string => Str::lower(trim($row['name'])))
            ->map(function (Collection $rows): array {
                // Prefer the longest-cased version we found so brand spellings
                // like "African Pride" win over "african pride".
                $bestName = $rows->pluck('name')
                    ->sortByDesc(fn (string $name): int => strlen(trim($name)))
                    ->first();

                return [
                    'name' => trim((string) $bestName),
                    'family_count' => (int) $rows->sum('family_count'),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function departmentOptions(): Collection
    {
        return collect(['Skin Care', 'Hair Products', 'General Products', 'Body Care', 'Electrical', 'Makeup', 'Fragrance', 'Accessories', 'Other'])
            ->merge(ShopProductIntakeOption::query()
                ->where('option_type', 'department')
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name'))
            ->merge(ProductFamily::query()
                ->whereNotNull('root_catalogue_name')
                ->where('root_catalogue_name', '!=', '')
                ->distinct()
                ->orderBy('root_catalogue_name')
                ->pluck('root_catalogue_name'))
            ->unique(fn (string $value): string => Str::lower($value))
            ->values();
    }

    private function productTypeOptions(): Collection
    {
        return collect([
            'Body Lotion',
            'Body Cream',
            'Body Butter',
            'Body Oil',
            'Body Wash',
            'Soap',
            'Face Cream',
            'Face Wash',
            'Skin Treatment',
            'Shampoo',
            'Conditioner',
            'Hair Treatment',
            'Styling Gel',
            'Relaxer',
            'Hair Colour',
            'Perfume',
            'Deodorant',
            'Makeup',
        ])
            ->merge(ShopProductIntakeOption::query()
                ->where('option_type', 'product_type')
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name'))
            ->merge(ProductFamily::query()
                ->whereNotNull('product_type_name')
                ->where('product_type_name', '!=', '')
                ->distinct()
                ->orderBy('product_type_name')
                ->pluck('product_type_name'))
            ->unique(fn (string $value): string => Str::lower($value))
            ->values();
    }

    private function sourceSuggestionData(): array
    {
        $sourceTypes = DB::table('product_sources')
            ->select('product_family_id')
            ->selectRaw("group_concat(distinct source_type order by source_type separator ', ') as source_types")
            ->whereNotNull('product_family_id')
            ->groupBy('product_family_id');

        $families = ProductFamily::query()
            ->with('variantGroups.options')
            ->leftJoinSub($sourceTypes, 'source_types', fn ($join) => $join->on('source_types.product_family_id', '=', 'product_families.id'))
            ->where('root_catalogue_name', '!=', 'Hair Extensions')
            ->select('product_families.*', 'source_types.source_types')
            ->orderBy('brand_name')
            ->orderBy('family_name')
            ->get();

        return $families
            ->groupBy(fn (ProductFamily $family): string => Str::lower($family->brand_name))
            ->map(function (Collection $brandFamilies): array {
                return $brandFamilies
                    ->map(fn (ProductFamily $family): array => $this->familySuggestionPayload($family))
                    ->values()
                    ->all();
            })
            ->all();
    }

    private function familySuggestionPayload(ProductFamily $family): array
    {
        $groups = $family->variantGroups
            ->map(fn ($group): array => [
                'name' => $group->name,
                'values' => $group->options
                    ->pluck('label')
                    ->filter()
                    ->unique(fn (string $value): string => Str::lower($value))
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $group): bool => $group['values'] !== [])
            ->values()
            ->all();

        $mainAxis = $groups[0]['name'] ?? 'Size';
        $subAxis = $groups[1]['name'] ?? 'Variant';
        $mainValues = $groups[0]['values'] ?? [];
        $subValues = $groups[1]['values'] ?? [];
        $commonVariants = collect($groups)
            ->slice(2)
            ->filter(fn (array $group): bool => count($group['values'] ?? []) === 1)
            ->values()
            ->all();

        $variantRows = collect($mainValues)
            ->map(fn (string $value): array => [
                'main_value' => $value,
                'sub_axis' => $subAxis,
                'sub_values' => $subValues,
                'notes' => null,
            ])
            ->values()
            ->all();

        return [
            'id' => $family->id,
            'brand_name' => $family->brand_name,
            'family_name' => $family->family_name,
            'department_name' => $family->root_catalogue_name,
            'product_type_name' => $family->product_type_name,
            'source_types' => $family->source_types,
            'main_axis' => $mainAxis,
            'variant_rows' => $variantRows,
            'common_variants' => $commonVariants,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeVariantRows(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(function ($row): ?array {
                if (! is_array($row)) {
                    return null;
                }

                $mainValue = $this->cleanText($row['main_value'] ?? null);
                $subAxis = $this->cleanText($row['sub_axis'] ?? null) ?: 'Variant';
                $subValues = $this->cleanValues($row['sub_values'] ?? []);

                if ($mainValue === '' && $subValues === []) {
                    return null;
                }

                return [
                    'main_value' => $mainValue ?: 'Standard',
                    'sub_axis' => $subAxis,
                    'sub_values' => $subValues,
                    'notes' => null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function decodeSkuRows(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn ($row): bool => is_array($row))
            ->mapWithKeys(function (array $row): array {
                $key = $this->cleanText($row['key'] ?? '');

                if ($key === '') {
                    return [];
                }

                return [$key => [
                    'barcode' => $this->cleanText($row['barcode'] ?? ''),
                    'label' => $this->cleanText($row['label'] ?? ''),
                    'name_label' => $this->cleanText($row['name_label'] ?? ''),
                    'suggested_name' => $this->cleanText($row['suggested_name'] ?? ''),
                ]];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeCommonVariants(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(function ($group): ?array {
                if (! is_array($group)) {
                    return null;
                }

                $name = $this->cleanText($group['name'] ?? null);
                $values = $this->cleanValues($group['values'] ?? []);

                if ($values === []) {
                    return null;
                }

                return [
                    'name' => $name ?: 'Common',
                    'values' => $values,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $variantRows
     * @param array<int, array<string, mixed>> $commonVariants
     * @param array<string, array<string, mixed>> $skuRows
     * @return array<int, array<string, mixed>>
     */
    private function buildSkuMatrix(string $mainAxis, array $variantRows, array $commonVariants, array $skuRows): array
    {
        $commonLabel = $this->commonVariantLabel($commonVariants);

        return collect($variantRows)
            ->flatMap(function (array $row) use ($mainAxis, $commonVariants, $commonLabel, $skuRows): array {
                $subValues = $row['sub_values'] ?? [];

                if ($subValues === []) {
                    $label = $this->skuLabel([$row['main_value'], $commonLabel]);
                    $key = $this->skuKey($row['main_value'], null, $commonLabel);

                    return [[
                        'key' => $key,
                        'label' => $label,
                        'main_axis' => $mainAxis,
                        'main_value' => $row['main_value'],
                        'sub_axis' => null,
                        'sub_value' => null,
                        'common_attributes' => $commonVariants,
                        'name_label' => $skuRows[$key]['name_label'] ?? $this->skuLabel([$row['main_value']]),
                        'suggested_name' => $skuRows[$key]['suggested_name'] ?? null,
                        'barcode' => $skuRows[$key]['barcode'] ?? null,
                    ]];
                }

                return collect($subValues)->map(function (string $subValue) use ($mainAxis, $row, $commonVariants, $commonLabel, $skuRows): array {
                    $label = $this->skuLabel([$row['main_value'], $subValue, $commonLabel]);
                    $key = $this->skuKey($row['main_value'], $subValue, $commonLabel);

                    return [
                        'key' => $key,
                        'label' => $label,
                        'main_axis' => $mainAxis,
                        'main_value' => $row['main_value'],
                        'sub_axis' => $row['sub_axis'],
                        'sub_value' => $subValue,
                        'common_attributes' => $commonVariants,
                        'name_label' => $skuRows[$key]['name_label'] ?? $this->skuLabel([$row['main_value'], $subValue]),
                        'suggested_name' => $skuRows[$key]['suggested_name'] ?? null,
                        'barcode' => $skuRows[$key]['barcode'] ?? null,
                    ];
                })->all();
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $variantRows
     * @param array<int, array<string, mixed>> $commonVariants
     * @return array<int, array<string, mixed>>
     */
    private function variantGroups(string $mainAxis, array $variantRows, array $commonVariants): array
    {
        $groups = [[
            'name' => $mainAxis,
            'values' => collect($variantRows)
                ->pluck('main_value')
                ->filter()
                ->unique(fn (string $value): string => Str::lower($value))
                ->values()
                ->all(),
        ]];

        $subValues = collect($variantRows)
            ->flatMap(fn (array $row): array => $row['sub_values'] ?? [])
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->values()
            ->all();
        $subAxis = collect($variantRows)->pluck('sub_axis')->filter()->first();

        if ($subValues !== []) {
            $groups[] = [
                'name' => $subAxis ?: 'Variant',
                'values' => $subValues,
            ];
        }

        foreach ($commonVariants as $commonVariant) {
            $values = $commonVariant['values'] ?? [];
            if ($values === []) {
                continue;
            }

            $groups[] = [
                'name' => $commonVariant['name'] ?? 'Common',
                'values' => $values,
            ];
        }

        return $groups;
    }

    private function skuKey(?string $mainValue, ?string $subValue, ?string $commonLabel = null): string
    {
        $raw = Str::lower($this->cleanText(($mainValue ?: 'standard').'|'.($subValue ?: '').'|'.($commonLabel ?: '')));
        $raw = str_replace('.', '', $raw);
        $key = preg_replace('/[^a-z0-9]+/', '-', $raw) ?: '';

        return trim($key, '-');
    }

    /**
     * @param array<int, string|null> $parts
     */
    private function skuLabel(array $parts): string
    {
        return collect($parts)
            ->map(fn ($part): string => $this->cleanText($part))
            ->filter()
            ->implode(' / ');
    }

    /**
     * @param array<int, array<string, mixed>> $commonVariants
     */
    private function commonVariantLabel(array $commonVariants): ?string
    {
        $parts = collect($commonVariants)
            ->flatMap(function (array $variant): array {
                $values = $variant['values'] ?? [];
                $name = $this->cleanText($variant['name'] ?? '');

                return collect($values)
                    ->map(fn (string $value): string => count($values) === 1 ? $value : trim($name.': '.$value))
                    ->all();
            })
            ->filter()
            ->values();

        return $parts->isEmpty() ? null : $parts->implode(' / ');
    }

    private function uniqueSlug(string $table, string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $counter = 2;

        while (DB::table($table)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private function cleanText(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_replace('/\s+/', ' ', $value) ?: $value;
    }

    /**
     * @return array<int, string>
     */
    private function cleanValues(mixed $values): array
    {
        if (! is_array($values)) {
            $values = preg_split('/[\n,;]+/', (string) $values) ?: [];
        }

        return collect($values)
            ->map(fn (mixed $value): string => $this->cleanText($value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->values()
            ->all();
    }

    private function nullTrim(?string $value): ?string
    {
        $value = $this->cleanText($value);

        return $value === '' ? null : $value;
    }
}
