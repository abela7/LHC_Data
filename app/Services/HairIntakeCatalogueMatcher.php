<?php

namespace App\Services;

use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\IntakeSession;
use App\Models\IntakeSessionFamilyGroup;
use App\Models\IntakeSessionVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class HairIntakeCatalogueMatcher
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function shortlistedStyles(BrandCatalogueBrand $brand, ?string $hint, array $observations, int $limit = 35): array
    {
        $brand->loadMissing([
            'productTypes.line',
            'productTypes.styles.skus.optionValues.variant',
            'productTypes.styles.variants.options',
            'productTypes.styles.images',
        ]);

        $query = Str::lower(trim(collect([$hint, ...$this->flattenObservations($observations)])->filter()->implode(' ')));
        $queryTokens = $this->tokens($query);

        return $brand->productTypes
            ->flatMap(fn ($type) => $type->styles->map(fn (BrandCatalogueStyle $style) => [$type, $style]))
            ->map(function (array $pair) use ($query, $queryTokens): array {
                [$type, $style] = $pair;
                $haystack = Str::lower(collect([
                    $style->name,
                    $type->name,
                    $type->line?->name,
                    $style->material_name,
                    $style->skus->pluck('name')->implode(' '),
                    $style->variants->pluck('name')->implode(' '),
                    $style->variants->flatMap->options->pluck('label')->implode(' '),
                ])->filter()->implode(' '));

                $score = 0;
                if ($query !== '' && str_contains($haystack, $query)) {
                    $score += 100;
                }

                foreach ($queryTokens as $token) {
                    if (str_contains($haystack, $token)) {
                        $score += strlen($token) >= 4 ? 8 : 3;
                    }
                }

                return [
                    'style' => $style,
                    'type' => $type,
                    'score' => $score,
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $row): array => $this->styleSummary($row['style'], $row['type'], $row['score']))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int|string>  $selectedSkuIds
     */
    public function createSessionVariants(IntakeSession $session, BrandCatalogueStyle $style, array $selectedSkuIds = []): void
    {
        $style->loadMissing('skus.optionValues.variant');
        $selected = collect($selectedSkuIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $skus = $selected->isNotEmpty()
            ? $style->skus->whereIn('id', $selected->all())
            : $style->skus;

        foreach ($skus as $sku) {
            $this->createSessionVariantForSku($session, $sku);
        }
    }

    /**
     * @param  array<int, array{name?:string, scope?:array<string, mixed>, sku_ids?:array<int, int|string>}>  $familyGroups
     */
    public function createSessionFamilyGroups(IntakeSession $session, BrandCatalogueStyle $style, array $familyGroups): void
    {
        $style->loadMissing('skus.optionValues.variant');
        $skusById = $style->skus->keyBy('id');

        foreach (array_values($familyGroups) as $index => $groupData) {
            $skuIds = collect($groupData['sku_ids'] ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique()
                ->values();

            if ($skuIds->isEmpty()) {
                continue;
            }

            $group = $session->familyGroups()->create([
                'name' => $this->familyGroupName($style, $groupData, $index),
                'scope_json' => $groupData['scope'] ?? null,
                'sort_order' => $index,
            ]);

            foreach ($skuIds as $skuId) {
                $sku = $skusById->get($skuId);
                if ($sku instanceof BrandCatalogueSku) {
                    $this->createSessionVariantForSku($session, $sku, $group);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function matchResponse(IntakeSession $session, array $aiResult): array
    {
        $status = (string) ($aiResult['match_status'] ?? 'not_found');
        $confidence = max(0, min(1, (float) ($aiResult['confidence'] ?? 0)));
        $selectedStyleId = (int) ($aiResult['matched_style_id'] ?? 0);
        $reasoning = trim((string) ($aiResult['reasoning'] ?? 'No reasoning returned.'));

        if ($status === 'confirmed' && $selectedStyleId > 0) {
            $style = BrandCatalogueStyle::query()
                ->with(['brand', 'productType.line', 'variants.options', 'skus.optionValues.variant'])
                ->find($selectedStyleId);

            if ($style) {
                return [
                    'submission_id' => $session->session_uuid,
                    'call_type' => 'match',
                    'match_status' => 'confirmed',
                    'confidence' => $confidence,
                    'matched_family' => ['family_id' => $style->id, 'family_name' => $style->name],
                    'matched_type' => ['type_id' => $style->productType?->id, 'type_name' => $style->productType?->name],
                    'matched_style' => ['style_id' => $style->id, 'style_name' => $style->name],
                    'variant_taxonomy' => $this->variantTaxonomy($style),
                    'variants' => $this->styleVariants($style, $aiResult['matching_sku_ids'] ?? []),
                    'reasoning' => $reasoning,
                ];
            }
        }

        if ($status === 'needs_user_choice') {
            $candidateIds = collect($aiResult['candidates'] ?? [])
                ->pluck('style_id')
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->take(3)
                ->values();

            $styles = BrandCatalogueStyle::query()
                ->with(['brand', 'productType.line', 'variants.options', 'skus.optionValues.variant'])
                ->whereIn('id', $candidateIds)
                ->get()
                ->keyBy('id');

            $candidates = collect($aiResult['candidates'] ?? [])
                ->map(function (array $candidate) use ($styles): ?array {
                    $style = $styles->get((int) ($candidate['style_id'] ?? 0));
                    if (! $style) {
                        return null;
                    }

                    return [
                        'matched_family' => ['family_id' => $style->id, 'family_name' => $style->name],
                        'matched_type' => ['type_id' => $style->productType?->id, 'type_name' => $style->productType?->name],
                        'matched_style' => ['style_id' => $style->id, 'style_name' => $style->name],
                        'confidence' => max(0, min(1, (float) ($candidate['confidence'] ?? 0))),
                        'reasoning' => trim((string) ($candidate['reasoning'] ?? 'Candidate match.')),
                    ];
                })
                ->filter()
                ->values()
                ->all();

            return [
                'submission_id' => $session->session_uuid,
                'call_type' => 'match',
                'match_status' => 'needs_user_choice',
                'confidence' => $confidence,
                'candidates' => $candidates,
                'reasoning' => $reasoning,
            ];
        }

        return [
            'submission_id' => $session->session_uuid,
            'call_type' => 'match',
            'match_status' => 'not_found',
            'confidence' => $confidence,
            'reasoning' => $reasoning,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function skuAxes(BrandCatalogueSku $sku): array
    {
        $sku->loadMissing('optionValues.variant');
        $options = $sku->optionValues
            ->sortBy(fn ($option) => sprintf('%04d:%s', $option->variant?->sort_order ?? 9999, $option->label))
            ->values();

        $main = $this->firstOptionByNames($options, ['length', 'size', 'inch', 'main']);
        $sub = $this->firstOptionByNames($options, ['colour', 'color', 'shade']);
        $common = $options
            ->reject(fn ($option) => $main && (int) $option->id === (int) $main->id)
            ->reject(fn ($option) => $sub && (int) $option->id === (int) $sub->id)
            ->map(fn ($option) => $this->axisLabel($option->variant?->name, $option->label))
            ->implode(' / ');

        return [
            'main' => $main ? $this->axisLabel($main->variant?->name, $main->label) : null,
            'sub' => $sub ? $this->axisLabel($sub->variant?->name, $sub->label) : null,
            'common' => $common ?: null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function styleVariants(BrandCatalogueStyle $style, mixed $matchingSkuIds): array
    {
        $matching = collect(is_array($matchingSkuIds) ? $matchingSkuIds : [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values();

        return $style->skus->map(function (BrandCatalogueSku $sku) use ($matching): array {
            $axes = $this->skuAxes($sku);

            return [
                'variant_id' => $sku->id,
                'display_name' => $sku->name,
                'main' => $axes['main'],
                'sub' => $axes['sub'],
                'common' => $axes['common'],
                'matches_observation' => $matching->contains((int) $sku->id),
                'status' => 'pending_user_confirmation',
            ];
        })->values()->all();
    }

    private function createSessionVariantForSku(IntakeSession $session, BrandCatalogueSku $sku, ?IntakeSessionFamilyGroup $group = null): void
    {
        $axes = $this->skuAxes($sku);

        IntakeSessionVariant::query()->updateOrCreate(
            [
                'intake_session_id' => $session->id,
                'brand_catalogue_sku_id' => $sku->id,
            ],
            [
                'intake_session_family_group_id' => $group?->id,
                'manually_added' => false,
                'manual_axes_json' => null,
                'display_name' => $sku->name,
                'main_value' => $axes['main'] ?? null,
                'sub_value' => $axes['sub'] ?? null,
                'common_value' => $axes['common'] ?? null,
                'status' => 'empty',
            ],
        );
    }

    /**
     * @param  array{name?:string, scope?:array<string, mixed>}  $groupData
     */
    private function familyGroupName(BrandCatalogueStyle $style, array $groupData, int $index): string
    {
        $name = trim((string) ($groupData['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $filters = collect($groupData['scope']['filters'] ?? [])
            ->filter(fn (mixed $value): bool => trim((string) $value) !== '')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->values()
            ->implode(' / ');

        return $filters !== '' ? $filters : $style->name.' family '.($index + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function variantTaxonomy(BrandCatalogueStyle $style): array
    {
        $variants = $style->variants->sortBy('sort_order')->values();
        $main = $this->firstVariantByNames($variants, ['length', 'size', 'inch', 'main']) ?? $variants->first();
        $sub = $this->firstVariantByNames($variants, ['colour', 'color', 'shade']);
        $common = $variants
            ->reject(fn ($variant) => $main && (int) $variant->id === (int) $main->id)
            ->reject(fn ($variant) => $sub && (int) $variant->id === (int) $sub->id)
            ->first();

        return [
            'main_axis' => $main?->name ?: 'Main',
            'sub_axis' => $sub?->name ?: 'Colour',
            'common_axis' => $common?->name ?: 'Common',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function styleSummary(BrandCatalogueStyle $style, mixed $type, int $score): array
    {
        return [
            'style_id' => $style->id,
            'style_name' => $style->name,
            'type_id' => $type?->id,
            'type_name' => $type?->name,
            'line_name' => $type?->line?->name,
            'material_name' => $style->material_name,
            'score' => $score,
            'variant_axes' => $style->variants->map(fn ($variant): array => [
                'id' => $variant->id,
                'name' => $variant->name,
                'variant_type' => $variant->variant_type,
                'values' => $variant->options->pluck('label')->values()->all(),
            ])->values()->all(),
            'skus' => $style->skus->take(80)->map(fn (BrandCatalogueSku $sku): array => [
                'id' => $sku->id,
                'name' => $sku->name,
                'axes' => $this->skuAxes($sku),
            ])->values()->all(),
        ];
    }

    private function firstOptionByNames(Collection $options, array $names): mixed
    {
        return $options->first(function ($option) use ($names): bool {
            $name = Str::lower((string) $option->variant?->name);

            return collect($names)->contains(fn (string $needle): bool => str_contains($name, $needle));
        });
    }

    private function firstVariantByNames(Collection $variants, array $names): mixed
    {
        return $variants->first(function ($variant) use ($names): bool {
            $name = Str::lower((string) $variant->name);

            return collect($names)->contains(fn (string $needle): bool => str_contains($name, $needle));
        });
    }

    private function axisLabel(?string $axis, string $value): string
    {
        $axis = trim((string) $axis);
        $value = trim($value);

        return $axis === '' ? $value : "{$axis}: {$value}";
    }

    /**
     * @return array<int, string>
     */
    private function flattenObservations(array $observations): array
    {
        return collect(['main', 'sub', 'common'])
            ->flatMap(fn (string $key) => (array) ($observations[$key] ?? []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $value): array
    {
        preg_match_all('/[a-z0-9][a-z0-9\-\/"]{1,}/i', $value, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $token): string => Str::lower($token))
            ->filter(fn (string $token): bool => strlen($token) >= 2)
            ->unique()
            ->values()
            ->all();
    }
}
