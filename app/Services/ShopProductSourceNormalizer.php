<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\ProductFamily;
use App\Models\ProductMedia;
use App\Models\ProductSource;
use App\Models\ProductVariantGroup;
use App\Models\ProductVariantOption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShopProductSourceNormalizer
{
    private const DEFAULT_PER_PAGE = 250;
    private const MAX_PER_PAGE = 500;

    /**
     * @return array{
     *     filters: array<string, string>,
     *     candidates: list<array<string, mixed>>,
     *     candidate_count: int,
     *     row_count: int,
     *     shown_count: int,
     *     source_counts: array<string, int>,
     *     confidence_counts: array<string, int>,
     *     brand_fallback_active: bool,
     *     requested_brand: string,
     *     current_page: int,
     *     per_page: int,
     *     total_pages: int,
     *     page_from: int,
     *     page_to: int,
     *     brands: list<string>,
     *     source_labels: array<string, string>
     * }
     */
    public function review(array $filters = []): array
    {
        $filters = $this->normaliseFilters($filters);
        $requestedBrand = $filters['brand'];
        $rows = $this->sourceRows($filters);
        $candidates = $this->filteredReviewCandidates($rows, $filters);
        $brandFallbackActive = false;

        if ($candidates->isEmpty() && $filters['brand'] !== '' && $filters['search'] !== '') {
            $fallbackFilters = $filters;
            $fallbackFilters['brand'] = '';
            $fallbackRows = $this->sourceRows($fallbackFilters);
            $fallbackCandidates = $this->filteredReviewCandidates($fallbackRows, $fallbackFilters);

            if ($fallbackCandidates->isNotEmpty()) {
                $rows = $fallbackRows;
                $candidates = $fallbackCandidates;
                $brandFallbackActive = true;
            }
        }

        $confidenceCounts = $candidates
            ->groupBy('confidence')
            ->map(fn (Collection $items): int => $items->count())
            ->all();

        $sorted = $candidates
            ->sortByDesc(fn (array $candidate): string => sprintf(
                '%d:%03d:%s',
                $candidate['existing_family_id'] ? 1 : 0,
                (int) round(((float) $candidate['score']) * 100),
                Str::lower($candidate['brand'].' '.$candidate['family_name']),
            ))
            ->values();

        $total = $sorted->count();
        $perPage = (int) $filters['per_page'];
        $totalPages = max(1, (int) ceil($total / $perPage));
        $currentPage = min((int) $filters['page'], $totalPages);
        $offset = ($currentPage - 1) * $perPage;
        $pageItems = $sorted->slice($offset, $perPage)->values();

        return [
            'filters' => $filters,
            'candidates' => $pageItems->all(),
            'candidate_count' => $total,
            'row_count' => $rows->count(),
            'shown_count' => $pageItems->count(),
            'current_page' => $currentPage,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'page_from' => $total === 0 ? 0 : $offset + 1,
            'page_to' => min($offset + $pageItems->count(), $total),
            'source_counts' => $rows
                ->groupBy('source')
                ->map(fn (Collection $items): int => $items->count())
                ->sortKeys()
                ->all(),
            'confidence_counts' => [
                'A' => (int) ($confidenceCounts['A'] ?? 0),
                'B' => (int) ($confidenceCounts['B'] ?? 0),
                'C' => (int) ($confidenceCounts['C'] ?? 0),
            ],
            'brand_fallback_active' => $brandFallbackActive,
            'requested_brand' => $requestedBrand,
            'brands' => $sorted
                ->pluck('brand')
                ->push($requestedBrand)
                ->filter()
                ->unique(fn (string $brand): string => Str::lower($brand))
                ->sort()
                ->values()
                ->all(),
            'source_labels' => $this->sourceLabels(),
        ];
    }

    private function filteredReviewCandidates(Collection $rows, array $filters): Collection
    {
        $candidates = $this->addDuplicateSignals($this->buildCandidates($rows));

        if ($filters['department'] !== '') {
            $candidates = $candidates
                ->filter(fn (array $candidate): bool => Str::lower($candidate['department']) === Str::lower($filters['department']))
                ->values();
        }

        if ($filters['confidence'] !== '') {
            $candidates = $candidates
                ->filter(fn (array $candidate): bool => $candidate['confidence'] === $filters['confidence'])
                ->values();
        }

        if ($filters['variant_state'] === 'with') {
            $candidates = $candidates
                ->filter(fn (array $candidate): bool => $this->candidateVariantCount($candidate) > 0)
                ->values();
        }

        if ($filters['variant_state'] === 'without') {
            $candidates = $candidates
                ->filter(fn (array $candidate): bool => $this->candidateVariantCount($candidate) === 0)
                ->values();
        }

        if ($filters['issue'] !== '') {
            $candidates = $candidates
                ->filter(fn (array $candidate): bool => $this->candidateMatchesIssueFilter($candidate, $filters['issue']))
                ->values();
        }

        return $candidates;
    }

    public function createDraftFamily(string $candidateKey): ProductFamily
    {
        $candidate = $this->candidateByKey($candidateKey);

        if (! $candidate) {
            throw ValidationException::withMessages([
                'candidate_key' => 'This normalized candidate could not be found. Refresh the page and try again.',
            ]);
        }

        return $this->createDraftFamilyFromCandidate($candidate);
    }

    public function createDraftFamilyFromCandidate(array $candidate): ProductFamily
    {
        if ($candidate['existing_family_id']) {
            $existing = ProductFamily::query()->find($candidate['existing_family_id']);
            if ($existing instanceof ProductFamily) {
                return DB::transaction(function () use ($existing, $candidate): ProductFamily {
                    $this->enrichExistingFamily($existing, $candidate);

                    return $existing->fresh(['variantGroups.options', 'sources']) ?? $existing;
                });
            }
        }

        $existing = ProductFamily::query()
            ->whereRaw('lower(root_catalogue_name) = ?', [Str::lower((string) $candidate['department'])])
            ->whereRaw('lower(brand_name) = ?', [Str::lower((string) $candidate['brand'])])
            ->whereRaw('lower(coalesce(product_type_name, "")) = ?', [Str::lower((string) $candidate['product_type'])])
            ->whereRaw('lower(family_name) = ?', [Str::lower((string) $candidate['family_name'])])
            ->first();

        if ($existing instanceof ProductFamily) {
            return DB::transaction(function () use ($existing, $candidate): ProductFamily {
                $this->enrichExistingFamily($existing, $candidate);

                return $existing;
            });
        }

        return DB::transaction(function () use ($candidate): ProductFamily {
            $brand = $this->findOrCreateBrand($candidate['brand']);
            $family = ProductFamily::query()->create([
                'brand_id' => $brand?->id,
                'root_catalogue_name' => $candidate['department'],
                'brand_name' => $candidate['brand'],
                'line_name' => $candidate['line_name'],
                'product_type_name' => $candidate['product_type'],
                'family_name' => $candidate['family_name'],
                'slug' => $this->uniqueSlug('product_families', $candidate['family_name']),
                'description' => $this->cleanCustomerDescription($candidate['description']),
                'source_url' => $candidate['source_url'],
                'status' => 'draft',
                'sort_order' => ((int) ProductFamily::query()->max('sort_order')) + 1,
            ]);

            $this->createVariantOptions($family, $candidate['variant_axes']);
            $this->createFamilyMedia($family, $candidate['image_url']);
            $this->createFamilySources($family, $candidate);

            return $family;
        });
    }

    private function enrichExistingFamily(ProductFamily $family, array $candidate): void
    {
        $brand = $family->brand_id ? null : $this->findOrCreateBrand($candidate['brand']);

        $family->fill([
            'brand_id' => $family->brand_id ?: $brand?->id,
            'line_name' => $family->line_name ?: $candidate['line_name'],
            'product_type_name' => $family->product_type_name ?: $candidate['product_type'],
            'description' => $family->description ?: $this->cleanCustomerDescription($candidate['description']),
            'source_url' => $family->source_url ?: $candidate['source_url'],
        ])->save();

        $this->mergeVariantOptions($family, $candidate['variant_axes']);

        if ($candidate['image_url'] && ! $family->media()->where('external_url', $candidate['image_url'])->exists()) {
            $this->createFamilyMedia($family, $candidate['image_url']);
        }

        $this->createFamilySources($family, $candidate);
    }

    private function mergeVariantOptions(ProductFamily $family, array $variantAxes): void
    {
        $existingGroups = $family->variantGroups()
            ->with('options')
            ->get()
            ->keyBy(fn (ProductVariantGroup $group): string => Str::lower($group->name));

        foreach ($variantAxes as $axisIndex => $axis) {
            $axisName = $axis['name'];
            $group = $existingGroups->get(Str::lower($axisName));

            if (! $group) {
                $group = ProductVariantGroup::query()->create([
                    'product_family_id' => $family->id,
                    'name' => $axisName,
                    'variant_type' => $this->variantTypeForAxis($axisName),
                    'sort_order' => ((int) $family->variantGroups()->max('sort_order')) + 1 + $axisIndex,
                ]);
                $existingGroups->put(Str::lower($axisName), $group->load('options'));
            }

            $existingOptions = $group->options
                ->keyBy(fn (ProductVariantOption $option): string => Str::lower($option->label));

            foreach (($axis['values'] ?? []) as $valueIndex => $value) {
                if ($existingOptions->has(Str::lower($value))) {
                    continue;
                }

                ProductVariantOption::query()->create([
                    'product_variant_group_id' => $group->id,
                    'label' => $value,
                    'value' => $value,
                    'sort_order' => ((int) $group->options()->max('sort_order')) + 1 + $valueIndex,
                ]);
            }
        }
    }

    public function createManualDraftFamily(array $data): ProductFamily
    {
        $brandName = $this->normaliseBrand($data['brand_name'] ?? '');
        $familyName = $this->tidyProductName($data['family_name'] ?? '');
        $department = $this->tidyProductName($data['department_name'] ?? '') ?: 'Shop Products';
        $productType = $this->tidyProductName($data['product_type_name'] ?? '') ?: 'General Beauty Product';
        $description = $this->cleanCustomerDescription($data['description'] ?? null);
        $variantAxes = $this->manualVariantAxes($data);

        if ($brandName === 'Unknown') {
            throw ValidationException::withMessages([
                'brand_name' => 'Choose or enter the brand before building from scratch.',
            ]);
        }

        if ($familyName === '') {
            throw ValidationException::withMessages([
                'family_name' => 'Enter the product family name visible on the pack.',
            ]);
        }

        $existing = ProductFamily::query()
            ->whereRaw('lower(brand_name) = ?', [Str::lower($brandName)])
            ->whereRaw('lower(family_name) = ?', [Str::lower($familyName)])
            ->first();

        if ($existing instanceof ProductFamily) {
            return $existing;
        }

        return DB::transaction(function () use ($brandName, $familyName, $department, $productType, $description, $variantAxes): ProductFamily {
            $brand = $this->findOrCreateBrand($brandName);

            $family = ProductFamily::query()->create([
                'brand_id' => $brand?->id,
                'root_catalogue_name' => $department,
                'brand_name' => $brandName,
                'line_name' => null,
                'product_type_name' => $productType,
                'family_name' => $familyName,
                'slug' => $this->uniqueSlug('product_families', $brandName.' '.$familyName),
                'description' => $description,
                'source_url' => null,
                'status' => 'draft',
                'sort_order' => ((int) ProductFamily::query()->max('sort_order')) + 1,
            ]);

            $this->createVariantOptions($family, $variantAxes);

            ProductSource::query()->create([
                'product_family_id' => $family->id,
                'product_id' => null,
                'source_type' => 'manual_scratch',
                'source_table' => null,
                'source_id' => null,
                'source_url' => null,
                'confidence' => 'C',
                'notes' => 'Created manually from the source normalization not-found flow.',
            ]);

            return $family;
        });
    }

    private function candidateByKey(string $candidateKey): ?array
    {
        return $this->buildCandidates($this->sourceRows(['search' => '', 'brand' => '', 'source' => '', 'department' => '', 'confidence' => '']))
            ->first(fn (array $candidate): bool => hash_equals($candidate['key'], $candidateKey));
    }

    private function manualVariantAxes(array $data): array
    {
        $axes = [];

        foreach ([1, 2] as $index) {
            $axisName = $this->normaliseAxisName((string) ($data["variant_axis_{$index}"] ?? ''));
            $values = $this->manualVariantValues($data["variant_values_{$index}"] ?? null);

            if ($values === []) {
                continue;
            }

            $axes[] = [
                'name' => $axisName ?: 'Variant',
                'values' => $values,
            ];
        }

        return $axes;
    }

    private function manualVariantValues(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return collect(preg_split('/[\r\n,]+/', $value) ?: [])
            ->map(fn (string $item): string => $this->cleanVariantLabel($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique(fn (string $item): string => Str::lower($item))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildCandidates(Collection $rows): Collection
    {
        $existingMap = $this->existingFamilyMap();
        $groups = [];

        foreach ($rows as $row) {
            $row = $this->normaliseRow($row);
            if (! $row) {
                continue;
            }

            $key = $this->candidateKey($row['department'], $row['brand'], $row['product_type'], $row['family_name']);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'department' => $row['department'],
                    'brand' => $row['brand'],
                    'line_name' => $row['line_name'],
                    'product_type' => $row['product_type'],
                    'family_name' => $row['family_name'],
                    'description' => null,
                    'source_url' => null,
                    'image_url' => null,
                    'variant_axes' => [],
                    'sources' => [],
                    'source_types' => [],
                    'prices' => [],
                    'has_image' => false,
                    'has_variant' => false,
                    'score' => 0.0,
                    'confidence' => 'C',
                    'existing_family_id' => null,
                    'existing_family_url' => null,
                ];
            }

            $groups[$key]['line_name'] ??= $row['line_name'];
            $groups[$key]['description'] ??= $row['description'];
            $groups[$key]['source_url'] ??= $row['source_url'];
            $groups[$key]['image_url'] ??= $row['image_url'];
            $groups[$key]['has_image'] = $groups[$key]['has_image'] || $row['image_url'] !== null;
            $groups[$key]['source_types'][$row['source']] = true;

            foreach ($row['variant_axes'] as $axis => $values) {
                foreach ($values as $value) {
                    $groups[$key]['variant_axes'][$axis][$value] = true;
                    $groups[$key]['has_variant'] = true;
                }
            }

            if ($row['price'] !== null) {
                $groups[$key]['prices'][] = $row['price'];
            }

            $groups[$key]['sources'][] = [
                'source' => $row['source'],
                'source_label' => $this->sourceLabels()[$row['source']] ?? Str::headline($row['source']),
                'source_table' => $row['source_table'],
                'source_id' => $row['source_id'],
                'title' => $row['title'],
                'variant_name' => $row['variant_name'],
                'source_url' => $row['source_url'],
                'price' => $row['price'],
                'currency' => $row['currency'],
                'image_url' => $row['image_url'],
                'confidence' => $row['row_confidence'],
            ];
        }

        return collect($groups)
            ->map(function (array $candidate) use ($existingMap): array {
                $candidate['variant_axes'] = collect($candidate['variant_axes'])
                    ->map(fn (array $values, string $axis): array => [
                        'name' => $axis,
                        'values' => collect(array_keys($values))
                            ->sortBy(fn (string $value): string => $this->variantSortKey($value))
                            ->values()
                            ->all(),
                    ])
                    ->sortBy(fn (array $axis): string => sprintf('%02d:%s', $this->axisSortOrder($axis['name']), $axis['name']))
                    ->values()
                    ->all();

                $candidate['sources'] = collect($candidate['sources'])
                    ->unique(fn (array $source): string => $source['source'].'-'.$source['source_id'])
                    ->sortBy(fn (array $source): string => $source['source_label'].' '.$source['title'])
                    ->values()
                    ->all();

                $candidate['source_types'] = array_keys($candidate['source_types']);
                sort($candidate['source_types']);
                $candidate['source_count'] = count($candidate['sources']);
                $candidate['variant_count'] = collect($candidate['variant_axes'])->sum(fn (array $axis): int => count($axis['values']));
                $candidate['price_summary'] = $this->priceSummary($candidate['prices']);

                $candidate['score'] = $this->scoreCandidate($candidate);
                $candidate['confidence'] = $candidate['score'] >= 0.85 ? 'A' : ($candidate['score'] >= 0.70 ? 'B' : 'C');
                $candidate['quality_notes'] = $this->qualityNotes($candidate);

                $existingKey = $this->candidateKey(
                    $candidate['department'],
                    $candidate['brand'],
                    $candidate['product_type'],
                    $candidate['family_name'],
                );
                $candidate['existing_family_id'] = $existingMap[$existingKey] ?? null;
                $candidate['existing_family_url'] = $candidate['existing_family_id']
                    ? route('retail-products.families.show', $candidate['existing_family_id'])
                    : null;

                unset($candidate['prices'], $candidate['has_image'], $candidate['has_variant']);

                return $candidate;
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function sourceRows(array $filters): Collection
    {
        $filters = $this->normaliseFilters($filters);
        $rows = collect();

        $loaders = [
            'shaba' => fn (): Collection => $this->shabaRows(),
            'deliveroo' => fn (): Collection => $this->deliverooRows(),
            'mamado' => fn (): Collection => $this->mamadoRows(),
            'janson' => fn (): Collection => $this->jansonRows(),
            'pdf' => fn (): Collection => $this->pdfRows(),
            'pictures' => fn (): Collection => $this->pictureRows(),
        ];

        foreach ($loaders as $source => $loader) {
            if ($filters['source'] !== '' && $filters['source'] !== $source) {
                continue;
            }

            $rows = $rows->merge($loader());
        }

        return $rows
            ->filter(fn (array $row): bool => ! $this->isHairExtensionSourceRow($row))
            ->filter(function (array $row) use ($filters): bool {
                if ($filters['brand'] !== '' && Str::lower((string) ($row['brand'] ?? '')) !== Str::lower($filters['brand'])) {
                    return false;
                }

                if ($filters['search'] === '') {
                    return true;
                }

                $haystack = Str::lower(implode(' ', [
                    $row['brand'] ?? '',
                    $row['title'] ?? '',
                    $row['family_name'] ?? '',
                    $row['variant_name'] ?? '',
                    $row['item_code'] ?? '',
                    $row['source_ref'] ?? '',
                ]));

                foreach (preg_split('/\s+/', Str::lower($filters['search'])) ?: [] as $term) {
                    if ($term !== '' && ! str_contains($haystack, $term)) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function shabaRows(): Collection
    {
        $products = DB::table('shaba_reference_products')
            ->select([
                'id',
                'brand',
                'title',
                'description',
                'canonical_url',
                'department',
                'options',
                'main_image_url',
                'min_price_pence',
                'currency',
            ])
            ->get();

        $variants = DB::table('shaba_reference_variants')
            ->select(['shaba_reference_product_id', 'title', 'options', 'price_current_pence'])
            ->whereIn('shaba_reference_product_id', $products->pluck('id')->all())
            ->orderBy('sort_order')
            ->get()
            ->groupBy('shaba_reference_product_id');

        return $products->map(function (object $row) use ($variants): array {
            $productOptions = $this->decodeJsonArray($row->options);
            $variantRows = $variants->get($row->id, collect())
                ->map(fn (object $variant): array => [
                    'title' => (string) $variant->title,
                    'options' => $this->decodeJsonArray($variant->options),
                    'price' => $variant->price_current_pence !== null ? ((float) $variant->price_current_pence) / 100 : null,
                ])
                ->values()
                ->all();

            return [
                'source' => 'shaba',
                'source_table' => 'shaba_reference_products',
                'source_id' => (int) $row->id,
                'brand' => $this->cleanText($row->brand),
                'family_name' => null,
                'variant_name' => null,
                'title' => $this->cleanText($row->title),
                'description' => $row->description,
                'source_url' => $row->canonical_url,
                'source_ref' => $row->canonical_url,
                'image_url' => $row->main_image_url,
                'image_urls' => array_values(array_filter([$row->main_image_url])),
                'price' => $row->min_price_pence !== null ? ((float) $row->min_price_pence) / 100 : null,
                'currency' => $row->currency ?: 'GBP',
                'department' => $row->department ?: null,
                'item_code' => null,
                'row_confidence' => 'A',
                'source_options' => $productOptions,
                'source_variants' => $variantRows,
            ];
        });
    }

    private function deliverooRows(): Collection
    {
        return DB::table('deliveroo_official_products')
            ->select([
                'id',
                'brand_label',
                'family_name',
                'variant_name',
                'official_name',
                'description',
                'official_url',
                'image_urls',
                'price',
                'currency',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'source' => 'deliveroo',
                'source_table' => 'deliveroo_official_products',
                'source_id' => (int) $row->id,
                'brand' => $this->cleanText($row->brand_label),
                'family_name' => $this->cleanText($row->family_name),
                'variant_name' => $this->cleanText($row->variant_name),
                'title' => $this->cleanText($row->official_name),
                'description' => $row->description,
                'source_url' => $row->official_url,
                'source_ref' => $row->official_url,
                'image_url' => $this->firstImage($row->image_urls),
                'image_urls' => $this->decodeImages($row->image_urls),
                'price' => $row->price !== null ? (float) $row->price : null,
                'currency' => $row->currency ?: 'GBP',
                'department' => null,
                'item_code' => null,
                'row_confidence' => 'A',
                'source_options' => [],
                'source_variants' => [],
            ]);
    }

    private function mamadoRows(): Collection
    {
        return DB::table('mamado_products')
            ->select([
                'id',
                'brand_label',
                'family_name',
                'variant_name',
                'sellable_name',
                'item_description',
                'description',
                'item_code',
                'source_order_number',
                'image_urls',
                'sellable_price',
                'gross_unit_price',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'source' => 'mamado',
                'source_table' => 'mamado_products',
                'source_id' => (int) $row->id,
                'brand' => $this->cleanText($row->brand_label),
                'family_name' => $this->cleanText($row->family_name),
                'variant_name' => $this->cleanText($row->variant_name),
                'title' => $this->cleanText($row->sellable_name ?: $row->item_description),
                'description' => $row->description,
                'source_url' => null,
                'source_ref' => $row->source_order_number,
                'image_url' => $this->firstImage($row->image_urls),
                'image_urls' => $this->decodeImages($row->image_urls),
                'price' => $row->sellable_price !== null ? (float) $row->sellable_price : ($row->gross_unit_price !== null ? (float) $row->gross_unit_price : null),
                'currency' => 'GBP',
                'department' => null,
                'item_code' => $row->item_code,
                'row_confidence' => $row->family_name ? 'B' : 'C',
                'source_options' => [],
                'source_variants' => [],
            ]);
    }

    private function jansonRows(): Collection
    {
        return DB::table('janson_products')
            ->select(['id', 'category', 'name', 'special_note', 'code', 'source_name', 'price_gbp', 'currency', 'page', 'page_row'])
            ->get()
            ->map(fn (object $row): array => [
                'source' => 'janson',
                'source_table' => 'janson_products',
                'source_id' => (int) $row->id,
                'brand' => $this->cleanText($row->category),
                'family_name' => null,
                'variant_name' => $this->cleanText($row->special_note),
                'title' => $this->cleanText($row->name),
                'description' => $row->source_name,
                'source_url' => null,
                'source_ref' => 'page '.($row->page ?? 'N/A').' row '.($row->page_row ?? 'N/A'),
                'image_url' => null,
                'image_urls' => [],
                'price' => $row->price_gbp !== null ? (float) $row->price_gbp : null,
                'currency' => $row->currency ?: 'GBP',
                'department' => null,
                'item_code' => $row->code,
                'row_confidence' => 'B',
                'source_options' => [],
                'source_variants' => [],
            ]);
    }

    private function pdfRows(): Collection
    {
        return DB::table('pdf_catalogue_products')
            ->select(['id', 'brand', 'product_name', 'product_code', 'source_name', 'page_number', 'raw_name_text', 'confidence'])
            ->get()
            ->map(fn (object $row): array => [
                'source' => 'pdf',
                'source_table' => 'pdf_catalogue_products',
                'source_id' => (int) $row->id,
                'brand' => $this->cleanText($row->brand),
                'family_name' => null,
                'variant_name' => null,
                'title' => $this->cleanText($row->product_name),
                'description' => $row->raw_name_text,
                'source_url' => null,
                'source_ref' => $row->source_name.' page '.$row->page_number,
                'image_url' => null,
                'image_urls' => [],
                'price' => null,
                'currency' => 'GBP',
                'department' => null,
                'item_code' => $row->product_code,
                'row_confidence' => in_array($row->confidence, ['A', 'B', 'C'], true) ? $row->confidence : 'C',
                'source_options' => [],
                'source_variants' => [],
            ]);
    }

    private function pictureRows(): Collection
    {
        return DB::table('observed_products')
            ->leftJoin('categories', 'categories.id', '=', 'observed_products.category_id')
            ->select([
                'observed_products.id',
                'observed_products.picture_id',
                'observed_products.brand',
                'observed_products.canonical_brand',
                'observed_products.brand_line',
                'observed_products.product_name',
                'categories.name as category_name',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'source' => 'pictures',
                'source_table' => 'observed_products',
                'source_id' => (int) $row->id,
                'brand' => $this->cleanText($row->canonical_brand ?: $row->brand),
                'family_name' => $this->cleanText($row->brand_line),
                'variant_name' => null,
                'title' => $this->cleanText($row->product_name),
                'description' => null,
                'source_url' => null,
                'source_ref' => $row->picture_id,
                'image_url' => null,
                'image_urls' => [],
                'price' => null,
                'currency' => 'GBP',
                'department' => $row->category_name,
                'item_code' => $row->picture_id,
                'row_confidence' => 'C',
                'source_options' => [],
                'source_variants' => [],
            ]);
    }

    /**
     * @return ?array<string, mixed>
     */
    private function normaliseRow(array $row): ?array
    {
        $brand = $this->normaliseBrand($row['brand'] ?? null);
        $title = $this->cleanText($row['title'] ?? '');
        if ($title === '') {
            return null;
        }

        $text = trim(implode(' ', array_filter([
            $brand,
            $title,
            $row['family_name'] ?? null,
            $row['variant_name'] ?? null,
            $row['description'] ?? null,
        ])));

        $productType = $this->detectProductType($text);
        $department = $this->detectDepartment($productType, $text, $row['department'] ?? null);

        $variantAxes = $this->variantAxesFromSource($row, $productType);
        $familyName = $this->cleanText($row['family_name'] ?? '');
        if ($familyName === '') {
            $familyName = $this->deriveFamilyName($brand, $title, $productType, $variantAxes);
        }

        if ($familyName === '') {
            return null;
        }

        if (! Str::startsWith(Str::lower($familyName), Str::lower($brand))) {
            $familyName = trim($brand.' '.$familyName);
        }

        return [
            ...$row,
            'brand' => $brand,
            'department' => $department,
            'line_name' => $this->detectLineName($brand, $familyName, $productType),
            'product_type' => $productType,
            'family_name' => $this->tidyProductName($familyName),
            'variant_axes' => $variantAxes,
            'title' => $title,
            'description' => $this->cleanCustomerDescription($row['description'] ?? null),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function variantAxesFromSource(array $row, string $productType): array
    {
        $axes = [];

        foreach ($this->variantAxesFromShaba($row) as $axis => $values) {
            foreach ($values as $value) {
                $this->addVariantValue($axes, $axis, $value);
            }
        }

        foreach ([$row['variant_name'] ?? null, $row['title'] ?? null] as $text) {
            foreach ($this->extractVariantTokens((string) $text, $productType) as $axis => $values) {
                foreach ($values as $value) {
                    $this->addVariantValue($axes, $axis, $value);
                }
            }
        }

        if (($row['variant_name'] ?? null) && $productType === 'Hair Colour / Dye') {
            $variant = $this->cleanVariantLabel((string) $row['variant_name']);
            if ($variant !== '' && ! $this->looksLikeSize($variant)) {
                $this->addVariantValue($axes, 'Shade', $variant);
            }
        }

        return $axes;
    }

    /**
     * @return array<string, list<string>>
     */
    private function variantAxesFromShaba(array $row): array
    {
        $axes = [];
        $optionNames = collect($row['source_options'] ?? [])
            ->pluck('type')
            ->map(fn (mixed $value): string => $this->normaliseAxisName((string) $value))
            ->values()
            ->all();

        foreach (($row['source_variants'] ?? []) as $variant) {
            $options = $variant['options'] ?? [];
            if (! is_array($options)) {
                continue;
            }

            foreach (array_values($options) as $index => $value) {
                $axis = $optionNames[$index] ?? 'Variant';
                $this->addVariantValue($axes, $axis, (string) $value);
            }
        }

        return $axes;
    }

    /**
     * @return array<string, list<string>>
     */
    private function extractVariantTokens(string $text, string $productType): array
    {
        $axes = [];
        $text = $this->cleanText($text);
        if ($text === '') {
            return [];
        }

        if (preg_match_all('/\b\d+(?:\.\d+)?\s?(?:ml|l|litre|liter|g|kg|mg|oz|fl\.?\s?oz|cl)\b(?:\s?\/\s?\d+(?:\.\d+)?\s?(?:ml|l|g|kg|mg|oz|fl\.?\s?oz|cl)\b)?/i', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $this->addVariantValue($axes, 'Size', $this->normaliseSize($match));
            }
        }

        if (preg_match_all('/\b(?:pack\s+of\s+\d+|\d+\s?(?:pack|packs|pc|pcs|pieces))\b/i', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $this->addVariantValue($axes, 'Pack Count', $this->tidyProductName($match));
            }
        }

        if (preg_match('/\b(extra\s+strength|maximum\s+strength|super|regular|normal|sensitive|mild|kids|children)\b/i', $text, $match)) {
            $this->addVariantValue($axes, 'Strength / Formula', $this->tidyProductName($match[1]));
        }

        if (preg_match('/^(?:size|sz)\s*[:\-]\s*(.+)$/i', $text, $match)) {
            $this->addVariantValue($axes, 'Size', $this->normaliseSize($match[1]));
        }

        if (preg_match('/^(?:shade|colour|color)\s*[:\-]\s*(.+)$/i', $text, $match)) {
            $this->addVariantValue($axes, $productType === 'Hair Colour / Dye' ? 'Shade' : 'Colour', $this->cleanVariantLabel($match[1]));
        }

        return $axes;
    }

    /**
     * @param  array<string, list<string>>  $variantAxes
     */
    private function deriveFamilyName(string $brand, string $title, string $productType, array &$variantAxes): string
    {
        $base = $this->removeLeadingBrand($title, $brand);
        $base = $this->removeVariantText($base, $variantAxes);
        $base = preg_replace('/\b(?:doz|dozen|case|single)\b/i', '', $base) ?: $base;
        $base = trim((string) preg_replace('/\s+/', ' ', $base), " \t\n\r\0\x0B-–|,");

        if (in_array($productType, ['Body Lotion', 'Body Wash', 'Body Cream', 'Body Butter', 'Body Oil'], true)) {
            $typePattern = match ($productType) {
                'Body Lotion' => '(?:Body\s+)?Lotion',
                'Body Wash' => '(?:Body\s+)?(?:Wash|Shower\s+Gel)',
                'Body Cream' => '(?:Body\s+)?Cream',
                'Body Butter' => '(?:Body\s+)?Butter',
                'Body Oil' => '(?:Body\s+)?Oil',
                default => preg_quote($productType, '/'),
            };
            if (preg_match('/^(.+?)\s+('.$typePattern.')$/i', $base, $match)) {
                $scent = trim($match[1]);
                if ($scent !== '' && str_word_count($scent) <= 5) {
                    $this->addVariantValue($variantAxes, 'Scent / Formula', $this->tidyProductName($scent));
                    $base = $productType;
                }
            }
        }

        if ($base === '' || Str::lower($base) === Str::lower($brand)) {
            $base = $productType;
        }

        return $this->tidyProductName($brand.' '.$base);
    }

    /**
     * @param  array<string, list<string>>  $variantAxes
     */
    private function removeVariantText(string $value, array $variantAxes): string
    {
        foreach ($variantAxes as $values) {
            foreach ($values as $variant) {
                if ($variant === '') {
                    continue;
                }
                $value = str_ireplace([$variant, str_replace(' / ', '/', $variant), str_replace('"', ' inch', $variant)], '', $value);
            }
        }

        return $value;
    }

    private function detectProductType(string $text): string
    {
        $haystack = Str::lower($text);
        $rules = [
            'Hair Colour / Dye' => ['hair dye', 'hair colour', 'hair color', 'semi permanent', 'permanent powder hair colour', 'coloration', 'colouration', 'colour cream', 'color cream', 'beard color', 'beard colour', 'hair & beard dye', 'ez color', 'color alive', 'temp spray', 'tinted color temporary spray', 'temporary hair & body', 'color spray', 'colour spray', 'illumina opal essence', 'rich conditioning color', 'liquid pure black', 'shiny black', 'adore colours', 'crazy color', 'crazy colour', 'bigen speedy', 'dark & lovely dye', 'cover your gray', 'henna'],
            'Relaxer' => ['relaxer', 'no-lye', 'no lye', 'lye relax', 'valuepack 2app kit', '2app kit', 'single kit regular', 'double kit regular', 'single kit for kids', 'no lye kit'],
            'Texturizer' => ['texturizer', 'texturiser', 'texture release kit', 'texture softener', 'texturising softening', 'text kit', 'texturizing kit', 'softening kit', 'softner kit'],
            'Perm / Curl System' => ['shape transformer', 'permanent wave', 'curl reformer'],
            'Peroxide / Developer' => ['liquid peroxide', 'cream peroxide', 'creme oxydant', 'oxydant', 'peroxide', 'developer', 'volume'],
            'Edge Control' => ['edge control', 'edge gel', 'edge wax', 'edge booster', 'edge tamer', 'edge ex/hold', 'edgelift', 'smooth edge glaze', 'edge hold', 'perfect edge', 'silky edges', 'smooth edges', 'control glue'],
            'Hair System Tape' => ['hair system tape', 'lace front hair system tape', 'no-shine', 'no shine hair system'],
            'Adhesive Remover' => ['tape remover', 'adhesive remover', 'c-22 citrus solvent', 'c22 citrus solvent', 'bonding glue', 'lace spray', 'lace melt', 'lace melting', 'lace bond', 'adhesive spray'],
            'Lash Adhesive' => ['duo rosewater & biotin adhesive', 'lash adhesive'],
            'Styling Gel' => ['styling gel', 'hair gel', 'lock gel', 'locking gel', 'braid gel', 'gel-wax', 'gel wax', 'gel activator', 'activator gel', 'pro expert gel', 'eco styler gel', 'lock it up', 'tight hold', 'hold me down gelle', 'curl sealer', 'styling jelly', 'curl jelly', 'ceramide jelly', 'scrunching jelly', 'curl gelee', 'gelatin', 'shining jam', 'magic fingers', 'flaxseed styler', 'extra hold', 'super hold', 'leaf hold', 'lock gro', 'hydro style flexi jelly', 'flexi jelly'],
            'Hair Wax' => ['hair wax', 'styling wax', 'moulding wax', 'molding wax', 'wax stick', 'hair stick', 'slick stick', 'locking wax', 'matte wax', 'red one wax', 'aqua wax', 'black wax', 'bees wax', 'beeswax', 'creme wax'],
            'Hair Spray / Mist' => ['hair spray', 'hairspray', 'styling spray', 'toning spray', 'soray', 'spary', 'finishing spray', 'thickening spray', 'densifying spray', 'uplifting spray', 'lift spray', 'hair lifter', 'dry finish spray', 'sea salt spray', 'de-frizz spray', 'money mist', 'finishing mist', 'holding spray', 'freeze spray', 'blasting freeze', 'protective mist', 'bodifier', 'mist spray', 'waves spray', 'spritz', 'moisture mist', 'hold & shine'],
            'Mousse / Foam' => ['mousse', 'foam', 'wrap lotion'],
            'Braid Spray / Sheen' => ['braid spray', 'braid sheen', 'sheen spray', 'braid extra spray', 'x-dry spray', 'hi sheen hair polish spray'],
            'Shampoo' => ['shampoo'],
            'Co-Wash' => ['co wash', 'co-wash'],
            'Detangling Spray' => ['detangling spray', 'detangling moisturizer spray'],
            'Conditioner' => ['conditioner', 'cond.', 'cond ', 'deep cond', 'deep conditioner', 'detangler', 'tangles out', 'comb out'],
            'Hair Care Kit' => ['giftset', 'gift set', 'discovery set', 'styling set', 'trio gift set', 'hair set', 'xmas giftset', 'mini styling set', 'thermal protection kit', 'perfectly poo free kit', 'kinky and curly girls kit', 'compact kit'],
            'Hair Treatment' => ['treatment', 'hair mask', 'hair masque', 'masque', 'mask', 'silver mask', 'no yellow mask', 'color enhancing mask', 'brightening mask', 'repair mask', 'conditioning mask', 'bond reinforcer', 'reconstructor', 'amino refiller', 'fusion emulsion', 'scalp protector', 'scalp detox', 'phyto-peeling', 'peeling', 'trial kit', 'loyalty kit', 'system 2', 'system 3', 'system 4', 'leave in', 'leave-in', 'deep repair', 'hair mayo', 'liquid mayo', 'mayonnaise', 'hair fertilizer', 'hair fertiliser', 'hair fert', 'medicated original', 'medicated light formula', 'root stimulator', 'sup gro', 'super gro', 'sure gro', 'herbal gro', 'hair nutrition', 'triple repair', 'anti break', 'anti-itch', 'anti itch', 'scalp relief', 'cholesterol', 'hair & scalp remedy', 'strengthening mask', 'strengthening balm', 'root rinse', 'hair rinse', 'dream filter', 'dream coat', 'bond curl rehab', 'rehab salve', 'miracle shield', 'gro strong', 'magic gro', 'head full of hair', 'gro complex', 'kocatah', 'deep quencher', 'hair perfector'],
            'Heat Protectant' => ['heat protectant', 'thermal spray', 'heat protect', 'thermal protector', 'thermal protection', 'therm prot'],
            'Hair Tonic' => ['hair tonic'],
            'Hair Oil' => ['hair oil', 'scalp oil', 'growth oil', 'castor oil', 'argan oil', 'coconut oil', 'essential oils', 'ttoil', 'tea tree oil', 'oil elixir', 'oyl elixir', 'sublime elixir', 'hair drops', 'scalp nutrients balm', 'bco ', ' bco', 'jbco', 'jamaican black castor'],
            'Hair Butter' => ['hair butter', 'pure butter', 'root repair growth butter', 'batana butter', 'finishing hair butter'],
            'Hair Pomade' => ['pomade', 'hair food', 'bergamot', 'super lanolin', 'hair & scalp', 'blue magic blue', 'neat waves'],
            'Hair Polish / Gloss' => ['hair polish', 'hair polisher', 'polisher', 'glossifier', 'high sheen gloss', 'liquid sheen', 'silken seal', 'natures shine', 'shine mist', 'shine coat', 'hair gloss', 'liquid glass', 'silk infusion', 'high gloss finish', 'protect + shine gloss', 'pop & lock', 'shake & shine', 'hair silk'],
            'Hair Moisturizer' => ['hair moisturizer', 'hair moisturiser', 'oil moisturizer', 'oil moisturiser', 'hair moisturize', 'moisturizing spray', 'moisturising spray', 'moisturizing', 'moisturising', 'moisture splash', 'curl moist spray', 'curl moisturizer spray', 'curl moisturiser spray', 'curl activating spray', 'curl refresher', 'comeback curl', 'next day revt', 'detang lot', 'softener', 'h -two', 'h-two', 'special blend lot', 'special blend curl', 'rosemary water', 'bond water'],
            'Hair Styling Product' => ['blowdry potion', 'blowdry', 'leaf hold', 'curl stimulator', 'miracle worker'],
            'Hair Cream' => ['hair cream', 'curl cream', 'styling cream', 'style balm', 'style fixer', 'curl retainer', 'defining cream', 'curl activator', 'moisturising cream', 'moisturizing cream', 'pudding', 'curling creme', 'curl creme', 'curl fluid', 'curl styler', 'curl elixir', 'curl definer', 'curl defining cream', 'styling custard', 'cream custard', 'curl custard', 'curly custard', 'conditioning custard', 'custard for kids', 'twisting cream', 'twisting souffle', 'buttery creme', 'curl boss', 'curl la la', 'curl mane tenance', 'curling & twisting custard', 'moisture milk', 'hair milk', 'moisturizer', 'moisturiser', 'moisture butter', 'moisture memory', 'smoothing butter', 'butter moisturizer', 'mango butter', 'curl love', 'style setter', 'smoothie', 'double butter cream', 'coco shea whip', 'cocoshea whip', 'coco repair', 'fix my hair', 'hydrating curling cream', 'curl hair milk', 'moisture whip', 'straight creme', 'cream complex', 'curl keeper', 'curl smoothie', 'curl creator', 'curl style milk', 'multi use styling cream', 'curl stretcher', 'daily styling cream', 'whipping crème', 'whipping creme', 'hair grows 4 n 1 curl', 'heavy cream', 'hydra-lite styling cream', 'smoothing air dry cream', 'air dry cream', 'effortless waves', 'hairdress', 'hair dress', 'blue hdress', 'hair shaper', 'short & neat', 'super neat', 'soft & curly', 'style & shine', 'curling butter cream', 'curl enhancing smoothie', 'curl shine milk', 'skala creme', 'creme mais', 'creme oleo', 'creme divina', 'creme amido', 'maionese capilar'],
            'Hair Accessory' => ['edge comb', 'comb & brush', 'comb set', 'tail comb', 'afro comb', 'razor comb', 'rake comb', 'dressing comb', 'wooden brush', 'styler detangling', 'curl defining brush', 'hair brush', 'styling brush', 'wet/dry brush', 'scalp renewal brush', 'smooth & polish brush', 'detail & define styling brush', 'edgelift brush', 'curl-art styler', 'detangle brush', 'twist sponge', 'roller', 'rollers', 'barrette', 'barrettes', 'cloud clip', 'claw clip', 'hair clip', 'clip set', 'hair elastics', 'french hair pin', 'bow french hair pin', 'curling headband', 'flexi rods', 'headbands', 'hair ties', 'wave cap', 'stocking wave cap', 'stocking cap', 'dome cap', 'sleep cap', 'bonnet', 'satin-lined beanie', 'durag', 'du-rag', 'compression rag', 'hair towel', 'towel turban', 'scrunchies', 'pillowcase', 'creaseless clips', 'hair net', 'wigcap', 'wig cap', 'mesh wrap', 'weaving net', 'satin wrap', 'conditioning cap', 'turban', 'turban hat', 'shower cap', 'wave net', 'spandex cap', 'tie-down', 'hook and loop fastener', 'barber neck strips', 'neck strips', 'hair beads', 'bobby pins', 'hair band', 'rubber band', 'bandanna', 'bandana', 'hair wrap', 'neck rolls'],
            'Body Lotion' => ['body lotion', 'lotion', 'moist locking body', 'essential healing pump'],
            'Body Wash' => ['body wash', 'baby wash', 'shower gel'],
            'Body Cream' => ['body cream', 'skin cream', 'body creme', 'cocoa butter jar', 'cocoa butter cream', 'cocoa glow body creme', 'cocoa butter', 'shea butter', 'papaya butter cream', 'aloe vera jar', 'bust firming cream', 'heel repair', 'foot magic', 'hand cream', 'baby butter'],
            'Body Butter' => ['body butter'],
            'Body Oil' => ['body oil', 'body gloss', 'glycerine', 'glycerin'],
            'Lip Balm' => ['lip therapy', 'lip balm', 'lip jelly'],
            'Petroleum Jelly' => ['petroleum jelly', 'pure vaseline', 'blue seal jelly', 'nursery jelly', 'nury jelly', 'soft skin jelly'],
            'Soap' => ['soap', 'beauty bar'],
            'Skin Cream' => ['cream jar', 'jar cream', 'cream tube', 'tube cream', 'crème tube', 'creme tube', 'carrot jar', 'classic cream', 'complexion cream', 'lightening cream', 'original cream', 'hand & face care cream', 'bright cream', 'brightening cream', 'fade cream', 'stretch mark cream', 'revita balm', 'whitening cream'],
            'Skin Solution' => ['tend skin solution', 'skin solution', 'professional solution', 'bump stopper', 'bump patrol', 'bump petrol', 'corrector', 'dark spot remover', 'dark spot radiance elixir'],
            'Floral Water' => ['rose water'],
            'Skin Scrub' => ['scrub'],
            'Face Pads / Patches' => ['cleansing pads', 'under-eye patches'],
            'Hair Removal Wax' => ['wax strips', 'hair remover'],
            'Sunscreen' => ['sun stick', 'spf'],
            'Self Tan' => ['self tan'],
            'Face Cleanser' => ['face cleanser', 'cleanser', 'face wash', 'facial wash', 'micellar water'],
            'Astringent / Toner' => ['astringent', 'toner', 'facial tonic', 'whitening tonic'],
            'Face Gel' => ['face gel'],
            'Face Cream' => ['face cream', 'facial cream'],
            'Skin Serum' => ['serum'],
            'Skin Corrector' => ['black spot corrector', 'dark spot corrector', 'spot corrector'],
            'Deodorant' => ['deodorant', 'roll on', 'roll-on'],
            'Fragrance' => ['perfume', 'body mist', 'eau de', 'fragrance', 'barber cologne', 'natural cologne', 'bay rum'],
            'Nail Care' => ['acetone'],
            'Alcohol / Sanitizer' => ['rubbing alcohol', 'hand sanitizer', 'hand senitizer', 'handwash', 'anti bacterial handwash'],
            'Face Mask' => ['surgical face mask', 'respirator face mask', 'kn95', 'ffp2'],
            'Toothpaste' => ['toothpaste'],
            'Toothbrush' => ['toothbrush'],
            'Shaving Cream' => ['shaving cream', 'shave cream'],
            'Razor Blades' => ['razor blade', 'razor blades', 'shaving blade', 'shaving blades', 'safety razor', 'blades per pack'],
            'Clipper Oil' => ['clipper oil'],
            'Clipper Blade' => ['clipper blade', 'blade for taper', 'blade for super taper', 'blade for', 'blade set'],
            'Clipper Accessory' => ['replacement parts', 'transformer 4v', 'clipper transformer'],
            'Hair Tool' => ['root styler', 'straightening comb', 'pressing comb', 'curling iron', 'foil shaver', 'recharge shaver', 'electric shaver'],
            'Clipper / Trimmer' => ['clipper', 'trimmer', 'magic clip', 'super taper', 'detailer', 'finale', 'foiler', 'bald fader', 'baldfader', 'cordless'],
            'Personal Care Accessory' => ['nail file', 'tweezer', 'cuticle trimmer', 'cuticle trimmers', 'professional scissor', 'scissor', 'desk mirror', 'compact mirror', 'keychain'],
            'Cosmetic Powder' => ['powder', 'loose powder', 'pressed powder'],
            'Makeup' => ['foundation', 'concealer', 'mascara', 'lipstick', 'lip gloss', 'eyeliner', 'make up', 'makeup'],
        ];

        $rules['Hair Cream'] = array_merge($rules['Hair Cream'], [
            'milk therapy butter',
            'hydrating balm',
            'poppin creme',
            'butter cream',
            'curl revival replenishing cream',
        ]);
        $rules['Hair Accessory'] = array_merge($rules['Hair Accessory'], [
            'scrunchie',
            'cloud cuffs',
            'flexi brush',
        ]);
        $rules['Skin Cream'] = array_merge($rules['Skin Cream'], [
            'whting milk',
            'whiting milk',
            'whitening milk',
        ]);
        $rules['Nail Care'] = array_merge($rules['Nail Care'], [
            'power file',
            'nail dyyer',
            'nail dryer',
            'nail care kit',
        ]);
        $rules['Hair Tool'] = array_merge($rules['Hair Tool'], [
            'ceramic styler',
            'flat iron',
            'thermal round brush',
            'volumizing thermal round brush',
            'defrizzion dryer',
            'dryer & xxl diffuser',
        ]);
        $rules['Personal Care Accessory'] = array_merge($rules['Personal Care Accessory'], [
            'body bar saver bag',
            'travel',
        ]);
        $rules['Lash Adhesive'] = array_merge($rules['Lash Adhesive'], [
            'secure and perfect your look in seconds',
        ]);
        $rules['Detangling Spray'] = array_merge($rules['Detangling Spray'], [
            'tangle-tamer',
            'tangle tamer',
        ]);
        $rules['Hair Styling Product'] = array_merge($rules['Hair Styling Product'], [
            'bombshell volumizer',
            'volumizer',
        ]);
        $rules['Styling Gel'] = array_merge($rules['Styling Gel'], [
            'styling souffle',
            'styling souffl',
        ]);
        $rules['Co-Wash'] = array_merge($rules['Co-Wash'], [
            'conditioning wash',
        ]);
        $rules['Hair Treatment'] = array_merge($rules['Hair Treatment'], [
            'lamellar water',
            'instant shine smooth moves',
        ]);
        $rules['Shampoo'] = array_merge($rules['Shampoo'], [
            'moisture clenz',
            'cleansing rinse',
        ]);
        $rules['Hair Wax'] = array_merge($rules['Hair Wax'], [
            'marcel curling wax',
            'locking creme wax',
            'locking cr',
            'locking firm wax',
            'curling wax',
            'stick gum',
        ]);
        $rules['Hair Pomade'] = array_merge($rules['Hair Pomade'], [
            'wave & groom',
        ]);
        $rules['Hair Treatment'] = array_merge($rules['Hair Treatment'], [
            'indian hemp',
            'cactus gro',
            'mega thick jar',
        ]);
        $rules['Hair Polish / Gloss'] = array_merge($rules['Hair Polish / Gloss'], [
            'shine-a-loc',
            'shine a loc',
        ]);
        $rules['Skin Solution'] = array_merge($rules['Skin Solution'], [
            'bump control',
        ]);
        $rules['Hair Styling Product'] = array_merge($rules['Hair Styling Product'], [
            'frizz control paste',
        ]);
        $rules['Styling Gel'] = array_merge($rules['Styling Gel'], [
            'coil defining jelly',
        ]);
        $rules['Alcohol / Sanitizer'] = array_merge($rules['Alcohol / Sanitizer'], [
            'antiseptic liquid',
        ]);

        foreach ($rules as $type => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $type;
                }
            }
        }

        $hairCareBrandMarkers = [
            "africa's best",
            'african pride',
            "aunt jack",
            'as i am',
            'blue magic',
            'camille rose',
            'cantu',
            'creme of nature',
            'curlychic',
            'dax',
            'doo gro',
            'fantasia',
            'hawaiian silky',
            'jamaican mango',
            'just for me',
            'keracare',
            'kuza',
            "luster's",
            'mielle',
            'ors',
            'soft n free',
            "soft 'n free",
        ];

        $looksLikeHairCareBrand = false;
        foreach ($hairCareBrandMarkers as $brandMarker) {
            if (str_contains($haystack, $brandMarker)) {
                $looksLikeHairCareBrand = true;
                break;
            }
        }

        if ($looksLikeHairCareBrand) {
            if (preg_match('/\b(text|textur|soften|softner|softener).*\bkit\b/i', $text) || preg_match('/\bkit\b.*\b(text|textur|soften|softner|softener)\b/i', $text)) {
                return 'Texturizer';
            }

            if (preg_match('/\b(relaxer|no[- ]?lye|kit|value kit)\b/i', $text)) {
                return 'Relaxer';
            }

            if (preg_match('/\b(spray|mist|spritz)\b/i', $text)) {
                return 'Hair Spray / Mist';
            }

            if (preg_match('/\b(custard|cream|creme|smoothie|souffle|milk|butter|curl|curls)\b/i', $text)) {
                return 'Hair Cream';
            }
        }

        $bodyCareBrandMarkers = [
            'jergens',
            "palmer's",
            'queen helene',
            'american dream',
            'makari',
            'maxi light',
            'perfect clear',
            'countryside',
        ];

        $looksLikeBodyCareBrand = false;
        foreach ($bodyCareBrandMarkers as $brandMarker) {
            if (str_contains($haystack, $brandMarker)) {
                $looksLikeBodyCareBrand = true;
                break;
            }
        }

        if ($looksLikeBodyCareBrand) {
            if (preg_match('/\b(lotion|daily moisture|skin smoothing|fade milk)\b/i', $text)) {
                return 'Body Lotion';
            }

            if (preg_match('/\b(cream|creme|butter|jar|vaseline|body gloss)\b/i', $text)) {
                return 'Body Cream';
            }
        }

        if (preg_match('/\bgel\b/i', $text)) {
            return 'Styling Gel';
        }

        if (preg_match('/\boil\b/i', $text)) {
            return 'Hair Oil';
        }

        return 'General Beauty Product';
    }

    private function detectDepartment(string $productType, string $text, ?string $sourceDepartment): string
    {
        $sourceDepartment = $this->cleanText($sourceDepartment);
        if ($sourceDepartment !== '' && ! in_array(Str::lower($sourceDepartment), ['body_care', 'hair_extensions', 'hair extensions'], true)) {
            return $this->tidyProductName(str_replace('_', ' ', $sourceDepartment));
        }

        if (in_array($productType, ['Body Lotion', 'Body Wash', 'Body Cream', 'Body Butter', 'Body Oil', 'Lip Balm', 'Petroleum Jelly', 'Soap', 'Deodorant', 'Fragrance', 'Shaving Cream', 'Razor Blades'], true)) {
            return 'Body Care';
        }

        if (in_array($productType, ['Hair Colour / Dye', 'Relaxer', 'Texturizer', 'Perm / Curl System', 'Peroxide / Developer', 'Edge Control', 'Hair System Tape', 'Adhesive Remover', 'Styling Gel', 'Hair Wax', 'Hair Spray / Mist', 'Mousse / Foam', 'Braid Spray / Sheen', 'Shampoo', 'Co-Wash', 'Detangling Spray', 'Conditioner', 'Hair Care Kit', 'Hair Treatment', 'Heat Protectant', 'Hair Tonic', 'Hair Oil', 'Hair Butter', 'Hair Pomade', 'Hair Polish / Gloss', 'Hair Moisturizer', 'Hair Styling Product', 'Hair Cream', 'Hair Accessory'], true)) {
            return 'Hair Care';
        }

        if (in_array($productType, ['Skin Cream', 'Skin Solution', 'Floral Water', 'Skin Scrub', 'Face Pads / Patches', 'Hair Removal Wax', 'Sunscreen', 'Self Tan', 'Face Cleanser', 'Astringent / Toner', 'Face Gel', 'Face Cream', 'Skin Serum', 'Skin Corrector'], true)) {
            return 'Skin Care';
        }

        if (in_array($productType, ['Toothpaste', 'Toothbrush'], true)) {
            return 'Oral Care';
        }

        if (in_array($productType, ['Cosmetic Powder', 'Makeup', 'Lash Adhesive', 'Nail Care'], true)) {
            return 'Cosmetics';
        }

        if (in_array($productType, ['Personal Care Accessory', 'Alcohol / Sanitizer', 'Face Mask'], true)) {
            return 'Accessories';
        }

        if (in_array($productType, ['Hair Tool', 'Clipper / Trimmer', 'Clipper Blade', 'Clipper Oil', 'Clipper Accessory'], true)) {
            return 'Electrical';
        }

        return 'Shop Products';
    }

    private function detectLineName(string $brand, string $familyName, string $productType): ?string
    {
        $withoutBrand = $this->removeLeadingBrand($familyName, $brand);
        $withoutType = trim(str_ireplace($productType, '', $withoutBrand));
        $withoutType = trim((string) preg_replace('/\s+/', ' ', $withoutType));

        if ($withoutType === '' || Str::lower($withoutType) === Str::lower($withoutBrand)) {
            return null;
        }

        return Str::length($withoutType) >= 3 ? $this->tidyProductName($withoutType) : null;
    }

    private function isHairExtensionSourceRow(array $row): bool
    {
        $department = Str::lower((string) ($row['department'] ?? ''));
        if (in_array($department, ['hair_extensions', 'hair extensions'], true)) {
            return true;
        }

        $text = Str::lower(implode(' ', [
            $row['brand'] ?? '',
            $row['title'] ?? '',
            $row['family_name'] ?? '',
            $row['variant_name'] ?? '',
        ]));

        $strongExtensionMarkers = [
            'lace wig',
            'wig ',
            'weave',
            'crochet',
            'bulk hair',
            'bulk -',
            'bulk :',
            'human hair',
            'synthetic hair',
            'pre-stretched',
            'pre stretched',
            'passion twist',
            'ponytail',
            'clip-in',
            'clip in',
            'closure',
            'frontal',
            'drawstring',
            'remy hair',
            'human remy',
            'european weft',
            'peruvian remi',
            'afro kinky',
            'bohemian',
            'butterfly locs',
            'poppin twist',
            'soft locs',
            'rasta locs',
            'urban soft dread',
            'water wave',
            'spanish curl',
            'deep twist',
            'bubbly curl',
            'ultimate comfort junior',
            'springy afro twist',
            'afro twist',
            'x-pression lil looks',
        ];

        foreach ($strongExtensionMarkers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        $careSafeTerms = ['shampoo', 'conditioner', 'spray', 'oil', 'foam', 'gel', 'sheen', 'treatment', 'mousse', 'relaxer', 'dye', 'wax', 'glue'];
        foreach ($careSafeTerms as $safeTerm) {
            if (str_contains($text, $safeTerm)) {
                return false;
            }
        }

        $extensionMarkers = [
            'hair extension',
            'braid',
        ];

        foreach ($extensionMarkers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    private function existingFamilyMap(): array
    {
        return ProductFamily::query()
            ->where(function ($query): void {
                $query->whereNull('root_catalogue_name')
                    ->orWhere('root_catalogue_name', '!=', 'Hair Extensions');
            })
            ->get(['id', 'root_catalogue_name', 'brand_name', 'product_type_name', 'family_name'])
            ->mapWithKeys(fn (ProductFamily $family): array => [
                $this->candidateKey(
                    $family->root_catalogue_name ?: 'Shop Products',
                    $family->brand_name,
                    $family->product_type_name ?: 'General Beauty Product',
                    $family->family_name,
                ) => (int) $family->id,
            ])
            ->all();
    }

    private function scoreCandidate(array $candidate): float
    {
        $score = 0.45;

        if ($candidate['brand'] !== 'Unknown') {
            $score += 0.15;
        }

        if ($candidate['product_type'] !== 'General Beauty Product') {
            $score += 0.10;
        }

        if (array_intersect($candidate['source_types'], ['shaba', 'deliveroo'])) {
            $score += 0.15;
        }

        if (count($candidate['source_types']) > 1) {
            $score += 0.08;
        }

        if ($candidate['has_variant']) {
            $score += 0.04;
        }

        if ($candidate['has_image']) {
            $score += 0.03;
        }

        if ($this->hasClearFamilyVariantEvidence($candidate)) {
            $score = max($score, 0.86);
        }

        return min(0.99, $score);
    }

    private function hasClearFamilyVariantEvidence(array $candidate): bool
    {
        if (($candidate['brand'] ?? 'Unknown') === 'Unknown' || ! ($candidate['has_variant'] ?? false)) {
            return false;
        }

        $familyName = $this->cleanText($candidate['family_name'] ?? '');
        if ($familyName === '') {
            return false;
        }

        $familyWithoutBrand = $this->removeLeadingBrand($familyName, (string) $candidate['brand']);
        $familyWithoutBrand = trim((string) preg_replace('/\s+/', ' ', $familyWithoutBrand));
        $variantValueCount = collect($candidate['variant_axes'] ?? [])
            ->sum(fn (array $axis): int => count($axis['values'] ?? []));

        if ($familyWithoutBrand === '') {
            return $variantValueCount >= 2 && Str::length($familyName) >= 4;
        }

        if (Str::length($familyWithoutBrand) < 3) {
            return false;
        }

        return ! in_array(Str::lower($familyWithoutBrand), [
            'assorted',
            'beauty product',
            'general beauty product',
            'misc',
            'n/a',
            'na',
            'product',
            'unknown',
        ], true);
    }

    /**
     * @return list<string>
     */
    private function qualityNotes(array $candidate): array
    {
        $notes = [];
        $hasFamilyVariantEvidence = $this->hasClearFamilyVariantEvidence($candidate);

        if ($candidate['brand'] === 'Unknown') {
            $notes[] = 'Brand is not safe enough.';
        }

        if ($candidate['product_type'] === 'General Beauty Product' && ! $hasFamilyVariantEvidence) {
            $notes[] = 'Product type was not recognized.';
        }

        if (! array_intersect($candidate['source_types'], ['shaba', 'deliveroo']) && ! $hasFamilyVariantEvidence) {
            $notes[] = 'No image-backed Shaba/Deliveroo source.';
        }

        if (count($candidate['source_types']) === 1) {
            $notes[] = 'Only one source confirms this family.';
        }

        if (! $candidate['has_image'] && ! $hasFamilyVariantEvidence) {
            $notes[] = 'No product image found in the source evidence.';
        }

        if (! $candidate['has_variant']) {
            $notes[] = 'No safe variant axis extracted.';
        }

        if ($notes === [] && $hasFamilyVariantEvidence) {
            $notes[] = 'Clear brand, family name, and variant map; image/product type not required for this confidence.';
        }

        if ($notes === []) {
            $notes[] = 'Strong candidate; review source evidence before creating the draft family.';
        }

        return $notes;
    }

    private function createVariantOptions(ProductFamily $family, array $variantAxes): void
    {
        foreach ($variantAxes as $axisIndex => $axis) {
            $group = ProductVariantGroup::query()->create([
                'product_family_id' => $family->id,
                'name' => $axis['name'],
                'variant_type' => $this->variantTypeForAxis($axis['name']),
                'sort_order' => $axisIndex + 1,
            ]);

            foreach (($axis['values'] ?? []) as $valueIndex => $value) {
                ProductVariantOption::query()->create([
                    'product_variant_group_id' => $group->id,
                    'label' => $value,
                    'value' => $value,
                    'sort_order' => $valueIndex + 1,
                ]);
            }
        }
    }

    private function createFamilyMedia(ProductFamily $family, ?string $imageUrl): void
    {
        if (! $imageUrl) {
            return;
        }

        ProductMedia::query()->create([
            'product_family_id' => $family->id,
            'image_role' => 'main',
            'external_url' => $imageUrl,
            'notes' => 'Created from source normalization candidate.',
            'is_primary' => true,
            'sort_order' => 1,
        ]);
    }

    private function createFamilySources(ProductFamily $family, array $candidate): void
    {
        foreach ($candidate['sources'] as $source) {
            ProductSource::query()->updateOrCreate(
                [
                    'product_family_id' => $family->id,
                    'product_id' => null,
                    'source_type' => 'normalization_'.$source['source'],
                    'source_table' => $source['source_table'],
                    'source_id' => $source['source_id'],
                ],
                [
                    'source_url' => $source['source_url'],
                    'confidence' => $candidate['confidence'],
                    'notes' => trim('Imported candidate evidence: '.$source['title'].($source['variant_name'] ? ' / '.$source['variant_name'] : '')),
                ],
            );
        }
    }

    private function findOrCreateBrand(string $brandName): ?Brand
    {
        if ($brandName === '' || $brandName === 'Unknown') {
            return null;
        }

        $slug = Str::slug($brandName);
        $existing = Brand::query()
            ->where('slug', $slug)
            ->orWhere('name', $brandName)
            ->first();

        if ($existing instanceof Brand) {
            return $existing;
        }

        return Brand::query()->create([
            'name' => $brandName,
            'slug' => $this->uniqueSlug('brands', $brandName),
            'is_active' => true,
            'is_generic' => false,
        ]);
    }

    private function candidateKey(string $department, string $brand, string $productType, string $familyName): string
    {
        return hash('sha256', implode('|', [
            $this->keyPart($department),
            $this->keyPart($brand),
            $this->keyPart($productType),
            $this->keyPart($familyName),
        ]));
    }

    private function keyPart(string $value): string
    {
        return Str::slug(Str::lower($value));
    }

    private function addDuplicateSignals(Collection $candidates): Collection
    {
        $duplicateCounts = $candidates
            ->groupBy(fn (array $candidate): string => $this->duplicateFamilyKey($candidate))
            ->map(fn (Collection $items): int => $items->count());

        return $candidates
            ->map(function (array $candidate) use ($duplicateCounts): array {
                $candidate['duplicate_family_count'] = (int) ($duplicateCounts[$this->duplicateFamilyKey($candidate)] ?? 1);

                return $candidate;
            })
            ->values();
    }

    private function duplicateFamilyKey(array $candidate): string
    {
        return implode('|', [
            $this->keyPart((string) ($candidate['brand'] ?? '')),
            $this->keyPart((string) ($candidate['family_name'] ?? '')),
        ]);
    }

    private function candidateVariantCount(array $candidate): int
    {
        return collect($candidate['variant_axes'] ?? [])
            ->sum(fn (array $axis): int => count($axis['values'] ?? []));
    }

    private function candidateMatchesIssueFilter(array $candidate, string $issue): bool
    {
        return match ($issue) {
            'duplicate_family_name' => (int) ($candidate['duplicate_family_count'] ?? 1) > 1,
            'general_type' => $candidate['product_type'] === 'General Beauty Product',
            'no_image' => empty($candidate['image_url']),
            'single_source' => (int) ($candidate['source_count'] ?? 0) === 1,
            'existing_family' => ! empty($candidate['existing_family_id']),
            'not_created' => empty($candidate['existing_family_id']),
            default => true,
        };
    }

    private function normaliseFilters(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        if (! in_array($perPage, [50, 100, 250, 500], true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $issue = (string) ($filters['issue'] ?? '');
        if (! in_array($issue, ['duplicate_family_name', 'general_type', 'no_image', 'single_source', 'existing_family', 'not_created'], true)) {
            $issue = '';
        }

        $variantState = (string) ($filters['variant_state'] ?? '');
        if (! in_array($variantState, ['with', 'without'], true)) {
            $variantState = '';
        }

        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'brand' => trim((string) ($filters['brand'] ?? '')),
            'source' => in_array(($filters['source'] ?? ''), array_keys($this->sourceLabels()), true) ? (string) $filters['source'] : '',
            'department' => trim((string) ($filters['department'] ?? '')),
            'confidence' => in_array(($filters['confidence'] ?? ''), ['A', 'B', 'C'], true) ? (string) $filters['confidence'] : '',
            'variant_state' => $variantState,
            'issue' => $issue,
            'page' => $page,
            'per_page' => min($perPage, self::MAX_PER_PAGE),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sourceLabels(): array
    {
        return [
            'shaba' => 'Shaba',
            'deliveroo' => 'Deliveroo',
            'mamado' => 'Mamado',
            'janson' => 'Janson',
            'pdf' => 'PDFs',
            'pictures' => 'Shop pictures',
        ];
    }

    private function normaliseBrand(?string $brand): string
    {
        $brand = $this->cleanText($brand);

        if ($brand === '' || in_array(Str::lower($brand), ['n/a', 'na', 'unknown', 'misc', 'miscellaneous'], true)) {
            return 'Unknown';
        }

        return $this->tidyProductName($brand);
    }

    private function cleanText(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
        $value = str_replace(["\u{00A0}", "\r", "\n", "\t"], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?: $value;

        return trim($value);
    }

    private function tidyProductName(string $value): string
    {
        $value = $this->cleanText($value);
        if ($value === '') {
            return '';
        }

        $tokens = preg_split('/(\s+)/', Str::lower($value), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];
        $knownUpper = ['a3', 'ors', 'sof', 'dna', 'kt', 'l.a', 'l.a.', 'spf', 'uv', 'bb', 'cc', 'eos', 'osmo', 'dax'];

        $value = collect($tokens)
            ->map(function (string $token) use ($knownUpper): string {
                if (trim($token) === '') {
                    return $token;
                }

                $plain = trim($token, " \t\n\r\0\x0B.,()[]");
                if (in_array($plain, $knownUpper, true)) {
                    return Str::upper($token);
                }

                if (preg_match('/^\d+(?:\.\d+)?(?:ml|g|kg|mg|oz|l|cl)$/i', $token)) {
                    return Str::lower($token);
                }

                return Str::ucfirst($token);
            })
            ->implode('');

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function cleanCustomerDescription(?string $value): ?string
    {
        $value = $this->cleanText($value);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\b(?:shipping|delivery|click and collect|add to cart|sold out)\b.*$/i', '', $value) ?: $value;
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function removeLeadingBrand(string $value, string $brand): string
    {
        $value = $this->cleanText($value);
        $brand = $this->cleanText($brand);
        if ($brand === '' || $brand === 'Unknown') {
            return $value;
        }

        return trim((string) preg_replace('/^'.preg_quote($brand, '/').'\b[\s\-:]*/i', '', $value));
    }

    private function cleanVariantLabel(string $value): string
    {
        $value = preg_replace('/^(?:size|shade|colour|color|variant)\s*[:\-]\s*/i', '', $this->cleanText($value)) ?: $value;
        $value = trim($value, " \t\n\r\0\x0B-–|,");

        return $this->tidyProductName($value);
    }

    private function addVariantValue(array &$axes, string $axis, string $value): void
    {
        $axis = $this->normaliseAxisName($axis);
        $value = $this->cleanVariantLabel($value);

        if ($value === '' || Str::lower($value) === 'default title') {
            return;
        }

        $axes[$axis] ??= [];
        if (! collect($axes[$axis])->contains(fn (string $existing): bool => Str::lower($existing) === Str::lower($value))) {
            $axes[$axis][] = $value;
        }
    }

    private function normaliseAxisName(string $axis): string
    {
        $axis = Str::lower($this->cleanText($axis));

        return match (true) {
            in_array($axis, ['title', 'default title'], true) => 'Variant',
            str_contains($axis, 'size') => 'Size',
            str_contains($axis, 'colour'), str_contains($axis, 'color') => 'Colour',
            str_contains($axis, 'shade') => 'Shade',
            str_contains($axis, 'scent'), str_contains($axis, 'fragrance') => 'Scent / Formula',
            str_contains($axis, 'strength'), str_contains($axis, 'formula') => 'Strength / Formula',
            str_contains($axis, 'pack') => 'Pack Count',
            default => $this->tidyProductName($axis ?: 'Variant'),
        };
    }

    private function normaliseSize(string $value): string
    {
        $value = $this->cleanText($value);
        $value = preg_replace('/\s*\/\s*/', ' / ', $value) ?: $value;
        $value = preg_replace('/(\d)\s+(ml|l|litre|liter|g|kg|mg|oz|fl\.?\s?oz|cl)\b/i', '$1$2', $value) ?: $value;
        $value = str_ireplace(['litre', 'liter', 'fl. oz', 'fl oz'], ['L', 'L', 'fl oz', 'fl oz'], $value);

        return trim($value);
    }

    private function looksLikeSize(string $value): bool
    {
        return (bool) preg_match('/\b\d+(?:\.\d+)?\s?(?:ml|l|g|kg|mg|oz|fl\.?\s?oz|cl)\b/i', $value);
    }

    private function axisSortOrder(string $axis): int
    {
        return match ($axis) {
            'Size' => 1,
            'Shade', 'Colour', 'Scent / Formula' => 2,
            'Strength / Formula' => 3,
            'Pack Count' => 4,
            default => 9,
        };
    }

    private function variantSortKey(string $value): string
    {
        if (preg_match('/^(\d+(?:\.\d+)?)/', $value, $match)) {
            return sprintf('%012.4f:%s', (float) $match[1], $value);
        }

        return '999999999999:'.Str::lower($value);
    }

    private function variantTypeForAxis(string $axis): string
    {
        return in_array($axis, ['Size', 'Pack Count'], true) ? 'number' : 'text';
    }

    private function priceSummary(array $prices): ?string
    {
        $prices = collect($prices)
            ->filter(fn ($price): bool => $price !== null)
            ->map(fn ($price): float => (float) $price)
            ->unique()
            ->sort()
            ->values();

        if ($prices->isEmpty()) {
            return null;
        }

        if ($prices->count() === 1) {
            return '£'.number_format((float) $prices->first(), 2);
        }

        return '£'.number_format((float) $prices->first(), 2).' - £'.number_format((float) $prices->last(), 2);
    }

    private function firstImage(?string $imageUrls): ?string
    {
        return $this->decodeImages($imageUrls)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function decodeImages(?string $imageUrls): array
    {
        $decoded = $this->decodeJsonArray($imageUrls);

        return collect($decoded)
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): string => trim($url))
            ->values()
            ->all();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function uniqueSlug(string $table, string $value): string
    {
        $base = Str::slug($value) ?: 'item';
        $candidate = $base;
        $suffix = 2;

        while (DB::table($table)->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }
}
