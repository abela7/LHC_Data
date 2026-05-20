<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brand;
use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues SKU codes that follow the unified scheme:
 *
 *     {DEPT}-{BRAND}-{FFFFF}{V}    e.g. HE-XPR-00012A
 *
 * Responsibilities:
 *
 *  - Resolve a 2-letter department code from product_families.root_catalogue_name
 *    using the config map.
 *  - Resolve / persist a 3-4 letter brand code on brands.sku_code, auto-deriving
 *    one from the brand name when no override has been set.
 *  - Allocate (and lock) a per-family sequence number on product_families.sku_family_seq,
 *    scoped to (department, brand) so two unrelated families never collide.
 *  - Allocate the next variant letter (A, B, ... Z, AA, ...) inside a family.
 *
 * The class is intentionally stateless and idempotent — calling allocateForProduct
 * twice on the same product returns the same code as long as nothing in the
 * family has changed between calls.
 */
class SkuCodeAllocator
{
    /**
     * Allocate (or re-issue) a unified SKU for the given product.
     *
     * Performs any side-effects required (auto-deriving the brand code, locking
     * the family sequence number) but does not persist the SKU itself — the
     * caller is expected to assign the return value to $product->sku and save.
     */
    public function allocateForProduct(Product $product): string
    {
        $family = $product->family ?: $product->family()->first();

        if ($family === null) {
            throw new RuntimeException("Product #{$product->id} has no product family; cannot allocate SKU.");
        }

        $prefix = $this->ensureFamilyPrefix($family);
        $letter = $this->nextVariantLetterFor($family, $prefix, excludeProductId: $product->id);

        return $prefix.$letter;
    }

    /**
     * Return the family-level prefix ({DEPT}-{BRAND}-{FFFFF}) without allocating
     * a new variant letter. Used by the UI to preview the next SKU.
     *
     * If the family has not yet been allocated a sequence number this will
     * peek at what the next number would be without persisting it.
     */
    public function previewFamilyPrefix(ProductFamily $family): string
    {
        $deptCode  = $this->departmentCodeFor($family->root_catalogue_name);
        $brandCode = $this->previewBrandCode($family->brand);

        $seq = $family->sku_family_seq ?: $this->peekNextFamilySeq($family);

        return $this->formatPrefix($deptCode, $brandCode, $seq);
    }

    /**
     * Resolve the next variant letter that would be issued for the family.
     */
    public function previewNextVariantLetter(ProductFamily $family): string
    {
        $prefix = $this->previewFamilyPrefix($family);

        return $this->nextVariantLetterFor($family, $prefix);
    }

    /**
     * Public so the migration command can ensure every brand has a code first,
     * which keeps the rewrite deterministic.
     *
     * Brand codes are unique globally across BOTH retail brands and catalogue
     * brands, except when two brands share the same name — those represent
     * the same real-world brand and share a code by design.
     */
    public function ensureBrandCode(Brand $brand): string
    {
        if (! empty($brand->sku_code)) {
            return $brand->sku_code;
        }

        // Same-name catalogue brand wins outright (one brand, two tables).
        $sibling = $this->catalogueBrandByName((string) $brand->name);
        if ($sibling && ! empty($sibling->sku_code)
            && ! $this->brandCodeTaken($sibling->sku_code, exceptBrandId: $brand->id)
        ) {
            $brand->sku_code = $sibling->sku_code;
            $brand->save();

            return $brand->sku_code;
        }

        $target = (int) config('sku.brand_code_length', 3);
        $max    = (int) config('sku.brand_code_max', 4);

        $candidates = $this->brandCodeCandidates($brand, $target, $max);

        foreach ($candidates as $code) {
            if ($code === ''
                || $this->brandCodeTaken($code, exceptBrandId: $brand->id)
                || $this->catalogueBrandCodeTaken($code)
            ) {
                continue;
            }

            $brand->sku_code = $code;
            $brand->save();

            return $code;
        }

        for ($i = 1; $i < 9999; $i++) {
            $code = 'B'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            if (! $this->brandCodeTaken($code, exceptBrandId: $brand->id)
                && ! $this->catalogueBrandCodeTaken($code)
            ) {
                $brand->sku_code = $code;
                $brand->save();

                return $code;
            }
        }

        throw new RuntimeException("Could not allocate a unique SKU code for brand #{$brand->id} ({$brand->name}).");
    }

    public function departmentCodeFor(?string $departmentName): string
    {
        $map = (array) config('sku.department_codes', []);
        $name = trim((string) $departmentName);

        if ($name !== '' && isset($map[$name])) {
            return $map[$name];
        }

        return (string) config('sku.department_fallback', 'GP');
    }

    /**
     * Allocate (if missing) and return the family sequence number.
     *
     * Cross-namespace allocation: the seq counter for a (dept, brand) pair
     * is the MAX of both retail product_families.sku_family_seq AND any
     * catalogue style that lives under a catalogue with the same dept and a
     * catalogue brand with the same sku_code. This guarantees that retail
     * and catalogue never collide on the same (dept, brand, seq) tuple.
     */
    public function ensureFamilySeq(ProductFamily $family): int
    {
        if ($family->sku_family_seq) {
            return (int) $family->sku_family_seq;
        }

        return DB::transaction(function () use ($family) {
            $fresh = ProductFamily::query()->lockForUpdate()->find($family->id);

            if ($fresh && $fresh->sku_family_seq) {
                $family->sku_family_seq = $fresh->sku_family_seq;

                return (int) $fresh->sku_family_seq;
            }

            $next = $this->peekNextSeqForBrandNamespace(
                $family->root_catalogue_name,
                $family->brand_id,
                $family->brand?->sku_code
            );

            $family->sku_family_seq = $next;
            $family->save();

            return $next;
        });
    }

    /**
     * Cross-namespace MAX over retail families and catalogue styles for a
     * given (dept, retail brand). Returns the next free slot.
     */
    private function peekNextSeqForBrandNamespace(?string $deptName, ?int $retailBrandId, ?string $brandCode): int
    {
        $retailMax = ProductFamily::query()
            ->where('root_catalogue_name', $deptName)
            ->where(function ($query) use ($retailBrandId) {
                if ($retailBrandId === null) {
                    $query->whereNull('brand_id');
                } else {
                    $query->where('brand_id', $retailBrandId);
                }
            })
            ->max('sku_family_seq');

        $catalogueMax = 0;
        if ($brandCode !== null && $brandCode !== '' && $deptName !== null && $deptName !== '') {
            $catalogueMax = BrandCatalogueStyle::query()
                ->whereIn(
                    'brand_catalogue_brand_id',
                    BrandCatalogueBrand::query()
                        ->where('sku_code', $brandCode)
                        ->whereIn(
                            'brand_catalogue_id',
                            BrandCatalogue::query()->where('name', $deptName)->pluck('id')
                        )
                        ->pluck('id')
                )
                ->max('sku_family_seq');
        }

        return max((int) ($retailMax ?? 0), (int) ($catalogueMax ?? 0)) + 1;
    }

    /**
     * Lock and return the full family-level prefix ({DEPT}-{BRAND}-{FFFFF}).
     */
    public function ensureFamilyPrefix(ProductFamily $family): string
    {
        $deptCode  = $this->departmentCodeFor($family->root_catalogue_name);
        $brandCode = $family->brand
            ? $this->ensureBrandCode($family->brand)
            : (string) config('sku.generic_brand_code', 'GEN');

        $seq = $this->ensureFamilySeq($family);

        return $this->formatPrefix($deptCode, $brandCode, $seq);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Catalogue side (Brand Catalogue → Style Workspace)
    //
    // The catalogue mirrors the retail allocator but uses its own tables:
    //   - BrandCatalogue (dept, e.g. "Hair Extensions")
    //   - BrandCatalogueBrand.sku_code (3-4 char code)
    //   - BrandCatalogueStyle.sku_family_seq (per catalogue+brand family number)
    //   - BrandCatalogueSku (variant rows)
    //
    // The user picked "single source of truth": catalogue codes are authoritative
    // and linked retail products inherit them via the migration command.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Allocate (or re-issue) a unified SKU code for a catalogue SKU row.
     *
     * The catalogue row is identified by its style and its current set of
     * variant options; the variant letter is allocated within the style.
     */
    public function allocateForCatalogueSku(BrandCatalogueSku $sku): string
    {
        $style = $sku->style ?: $sku->style()->first();

        if ($style === null) {
            throw new RuntimeException("Catalogue SKU #{$sku->id} has no style; cannot allocate code.");
        }

        $prefix = $this->ensureCatalogueStylePrefix($style);
        $letter = $this->nextCatalogueVariantLetterFor($style, $prefix, excludeSkuId: $sku->id);

        return $prefix.$letter;
    }

    /**
     * Family-level prefix ({DEPT}-{BRAND}-{FFFFF}) for a catalogue style,
     * without allocating a new variant letter. Used by the UI to preview the
     * code that will be saved.
     */
    public function previewCatalogueStylePrefix(BrandCatalogueStyle $style): string
    {
        $deptCode  = $this->catalogueDeptCodeFor($style);
        $brandCode = $this->previewCatalogueBrandCode($style->brand);

        $seq = $style->sku_family_seq ?: $this->peekNextCatalogueStyleSeq($style);

        return $this->formatPrefix($deptCode, $brandCode, $seq);
    }

    /**
     * Preview-only: the next variant letter that would be issued for a
     * brand-new catalogue SKU under this style.
     */
    public function previewNextCatalogueVariantLetter(BrandCatalogueStyle $style): string
    {
        $prefix = $this->previewCatalogueStylePrefix($style);

        return $this->nextCatalogueVariantLetterFor($style, $prefix);
    }

    /**
     * Resolve / persist a catalogue brand's SKU code, deriving one from the
     * brand name if it hasn't been set explicitly yet.
     *
     * The code is globally unique across both retail and catalogue brand
     * tables, except when a retail brand with the same name already exists
     * (in which case the catalogue inherits its code).
     */
    public function ensureCatalogueBrandCode(BrandCatalogueBrand $brand): string
    {
        if (! empty($brand->sku_code)) {
            return $brand->sku_code;
        }

        // Same-name retail brand wins outright (one brand, two tables).
        $sibling = $this->retailBrandByName((string) $brand->name);
        if ($sibling && ! empty($sibling->sku_code)
            && ! $this->catalogueBrandCodeTaken($sibling->sku_code, exceptBrandId: $brand->id)
        ) {
            $brand->sku_code = $sibling->sku_code;
            $brand->save();

            return $brand->sku_code;
        }

        $target = (int) config('sku.brand_code_length', 3);
        $max    = (int) config('sku.brand_code_max', 4);

        $candidates = $this->brandCodeCandidatesForName(
            (string) ($brand->name ?: $brand->slug ?: 'GEN'),
            $target,
            $max
        );

        foreach ($candidates as $code) {
            if ($code === ''
                || $this->catalogueBrandCodeTaken($code, exceptBrandId: $brand->id)
                || $this->brandCodeTaken($code)
            ) {
                continue;
            }

            $brand->sku_code = $code;
            $brand->save();

            return $code;
        }

        for ($i = 1; $i < 9999; $i++) {
            $code = 'B'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            if (! $this->catalogueBrandCodeTaken($code, exceptBrandId: $brand->id)
                && ! $this->brandCodeTaken($code)
            ) {
                $brand->sku_code = $code;
                $brand->save();

                return $code;
            }
        }

        throw new RuntimeException("Could not allocate a unique catalogue SKU code for brand #{$brand->id} ({$brand->name}).");
    }

    /**
     * Allocate (if missing) and return the catalogue style's family sequence.
     *
     * Cross-namespace: the same (dept, brand_code) counter governs both
     * catalogue style seq and retail family seq, so the two never collide.
     */
    public function ensureCatalogueStyleSeq(BrandCatalogueStyle $style): int
    {
        if ($style->sku_family_seq) {
            return (int) $style->sku_family_seq;
        }

        return DB::transaction(function () use ($style) {
            $fresh = BrandCatalogueStyle::query()->lockForUpdate()->find($style->id);

            if ($fresh && $fresh->sku_family_seq) {
                $style->sku_family_seq = $fresh->sku_family_seq;

                return (int) $fresh->sku_family_seq;
            }

            $next = $this->peekNextCatalogueStyleSeq($style);

            $style->sku_family_seq = $next;
            $style->save();

            return $next;
        });
    }

    /**
     * Lock and return the catalogue style's full prefix {DEPT}-{BRAND}-{FFFFF}.
     */
    public function ensureCatalogueStylePrefix(BrandCatalogueStyle $style): string
    {
        $deptCode  = $this->catalogueDeptCodeFor($style);
        $brandCode = $style->brand
            ? $this->ensureCatalogueBrandCode($style->brand)
            : (string) config('sku.generic_brand_code', 'GEN');

        $seq = $this->ensureCatalogueStyleSeq($style);

        return $this->formatPrefix($deptCode, $brandCode, $seq);
    }

    /**
     * Resolve the department code for a catalogue style by walking up to its
     * brand → catalogue and looking up the config map.
     */
    public function catalogueDeptCodeFor(BrandCatalogueStyle $style): string
    {
        $catalogue = $style->brand?->catalogue;

        if ($catalogue === null && $style->brand) {
            $catalogue = BrandCatalogueBrand::with('catalogue')->find($style->brand_catalogue_brand_id)?->catalogue;
        }

        return $this->departmentCodeFor($catalogue?->name);
    }

    /**
     * Convert a 1-based index to a base-26 letter sequence (1=A, 26=Z, 27=AA, ...).
     */
    public function indexToLetters(int $n): string
    {
        if ($n <= 0) {
            return 'A';
        }

        $result = '';

        while ($n > 0) {
            $n--;
            $result = chr(ord('A') + ($n % 26)).$result;
            $n = intdiv($n, 26);
        }

        return $result;
    }

    // ----- private helpers -----

    /**
     * Decide the next free variant letter for $prefix inside $family.
     *
     * @param  int|null  $excludeProductId  Useful when re-issuing the SKU for a product
     *                                      that already has one assigned in this family.
     */
    private function nextVariantLetterFor(ProductFamily $family, string $prefix, ?int $excludeProductId = null): string
    {
        $query = Product::query()
            ->where('product_family_id', $family->id)
            ->where('sku', 'like', $prefix.'%');

        if ($excludeProductId !== null) {
            $query->where('id', '!=', $excludeProductId);
        }

        $existingLetters = $query->pluck('sku')
            ->map(fn ($sku) => substr((string) $sku, strlen($prefix)))
            ->filter(fn ($suffix) => $suffix !== '' && preg_match('/^[A-Z]+$/', (string) $suffix) === 1)
            ->values()
            ->all();

        $taken = array_flip($existingLetters);

        for ($i = 1; $i <= 17576; $i++) {
            $letter = $this->indexToLetters($i);
            if (! isset($taken[$letter])) {
                return $letter;
            }
        }

        throw new RuntimeException("Variant letter space exhausted for prefix {$prefix} in family #{$family->id}.");
    }

    private function peekNextFamilySeq(ProductFamily $family): int
    {
        return $this->peekNextSeqForBrandNamespace(
            $family->root_catalogue_name,
            $family->brand_id,
            $family->brand?->sku_code
        );
    }

    private function previewBrandCode(?Brand $brand): string
    {
        if ($brand === null) {
            return (string) config('sku.generic_brand_code', 'GEN');
        }

        if (! empty($brand->sku_code)) {
            return $brand->sku_code;
        }

        $candidates = $this->brandCodeCandidates(
            $brand,
            (int) config('sku.brand_code_length', 3),
            (int) config('sku.brand_code_max', 4)
        );

        return $candidates[0] ?? (string) config('sku.generic_brand_code', 'GEN');
    }

    private function formatPrefix(string $deptCode, string $brandCode, int $seq): string
    {
        $width = (int) config('sku.family_seq_width', 5);

        return sprintf(
            '%s-%s-%s',
            $deptCode,
            $brandCode,
            str_pad((string) $seq, $width, '0', STR_PAD_LEFT)
        );
    }

    private function brandCodeTaken(string $code, ?int $exceptBrandId = null): bool
    {
        $query = Brand::query()->where('sku_code', $code);

        if ($exceptBrandId !== null) {
            $query->where('id', '!=', $exceptBrandId);
        }

        return $query->exists();
    }

    /**
     * @return string[]
     */
    private function brandCodeCandidates(Brand $brand, int $target, int $max): array
    {
        return $this->brandCodeCandidatesForName(
            (string) ($brand->name ?: $brand->slug ?: 'GEN'),
            $target,
            $max
        );
    }

    /**
     * Generate an ordered list of code candidates to try for a brand name.
     *
     * Strategy (in order):
     *   1. Initials of words (e.g. "African Pride" -> "AP" -> padded to "APX")
     *   2. First N alphanumerics of the name ("African Pride" -> "AFR")
     *   3. Consonant-only of the name ("Cantu" -> "CNT")
     *   4. Initials filled from the first word's consonants ("African Pride" -> "APF")
     *   5. Same strategies but extended to the max length
     *
     * @return string[]
     */
    private function brandCodeCandidatesForName(string $brandName, int $target, int $max): array
    {
        $name = strtoupper($brandName === '' ? 'GEN' : $brandName);
        $clean = preg_replace('/[^A-Z0-9 ]/', ' ', $name) ?? '';
        $words = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $joined = implode('', $words);

        $candidates = [];

        for ($length = $target; $length <= $max; $length++) {
            if (count($words) >= $length) {
                $initials = '';
                foreach (array_slice($words, 0, $length) as $word) {
                    $initials .= substr($word, 0, 1);
                }
                $candidates[] = str_pad($initials, $length, 'X');
            }

            $candidates[] = str_pad(substr($joined, 0, $length), $length, 'X');

            $consonants = preg_replace('/[AEIOU]/', '', $joined) ?? '';
            if ($consonants !== '') {
                $candidates[] = str_pad(substr($consonants, 0, $length), $length, 'X');
            }

            if (! empty($words)) {
                $firstWordConsonants = preg_replace('/[AEIOU]/', '', $words[0]) ?? '';
                $blend = '';
                foreach ($words as $word) {
                    $blend .= substr($word, 0, 1);
                    if (strlen($blend) >= $length) {
                        break;
                    }
                }
                $blend .= $firstWordConsonants;
                $candidates[] = str_pad(substr($blend, 0, $length), $length, 'X');
            }
        }

        $seen = [];
        $unique = [];
        foreach ($candidates as $candidate) {
            if (! isset($seen[$candidate]) && $candidate !== '') {
                $seen[$candidate] = true;
                $unique[] = $candidate;
            }
        }

        return $unique;
    }

    // ----- catalogue-side private helpers -----

    private function nextCatalogueVariantLetterFor(BrandCatalogueStyle $style, string $prefix, ?int $excludeSkuId = null): string
    {
        $query = BrandCatalogueSku::query()
            ->where('brand_catalogue_style_id', $style->id)
            ->where('sku_code', 'like', $prefix.'%');

        if ($excludeSkuId !== null) {
            $query->where('id', '!=', $excludeSkuId);
        }

        $existingLetters = $query->pluck('sku_code')
            ->map(fn ($code) => substr((string) $code, strlen($prefix)))
            ->filter(fn ($suffix) => $suffix !== '' && preg_match('/^[A-Z]+$/', (string) $suffix) === 1)
            ->values()
            ->all();

        $taken = array_flip($existingLetters);

        for ($i = 1; $i <= 17576; $i++) {
            $letter = $this->indexToLetters($i);
            if (! isset($taken[$letter])) {
                return $letter;
            }
        }

        throw new RuntimeException("Variant letter space exhausted for prefix {$prefix} in catalogue style #{$style->id}.");
    }

    private function peekNextCatalogueStyleSeq(BrandCatalogueStyle $style): int
    {
        // Determine the catalogue (department) name and brand code so we
        // can compute the cross-namespace MAX correctly.
        $brand = $style->brand ?: BrandCatalogueBrand::with('catalogue')->find($style->brand_catalogue_brand_id);
        $catalogue = $brand?->catalogue;
        $brandCode = $brand?->sku_code
            ?? ($brand ? ($this->brandCodeCandidatesForName(
                (string) ($brand->name ?: $brand->slug ?: 'GEN'),
                (int) config('sku.brand_code_length', 3),
                (int) config('sku.brand_code_max', 4)
            )[0] ?? null) : null);

        // Catalogue-side MAX: any style under any brand with the same code
        // in the same catalogue (a single brand code can only belong to one
        // brand thanks to the unique index, so this is effectively the same
        // brand, but the query also handles the pre-migration state).
        $catalogueMax = BrandCatalogueStyle::query()
            ->where('brand_catalogue_brand_id', $style->brand_catalogue_brand_id)
            ->max('sku_family_seq');

        // Retail-side MAX for the same (dept, brand code).
        $retailMax = 0;
        if ($catalogue && $brandCode) {
            $retailBrandIds = Brand::query()->where('sku_code', $brandCode)->pluck('id');
            if ($retailBrandIds->isNotEmpty()) {
                $retailMax = ProductFamily::query()
                    ->whereIn('brand_id', $retailBrandIds)
                    ->where('root_catalogue_name', $catalogue->name)
                    ->max('sku_family_seq');
            }
        }

        return max((int) ($catalogueMax ?? 0), (int) ($retailMax ?? 0)) + 1;
    }

    private function previewCatalogueBrandCode(?BrandCatalogueBrand $brand): string
    {
        if ($brand === null) {
            return (string) config('sku.generic_brand_code', 'GEN');
        }

        if (! empty($brand->sku_code)) {
            return $brand->sku_code;
        }

        $candidates = $this->brandCodeCandidatesForName(
            (string) ($brand->name ?: $brand->slug ?: 'GEN'),
            (int) config('sku.brand_code_length', 3),
            (int) config('sku.brand_code_max', 4)
        );

        return $candidates[0] ?? (string) config('sku.generic_brand_code', 'GEN');
    }

    private function catalogueBrandCodeTaken(string $code, ?int $exceptBrandId = null): bool
    {
        $query = BrandCatalogueBrand::query()->where('sku_code', $code);

        if ($exceptBrandId !== null) {
            $query->where('id', '!=', $exceptBrandId);
        }

        return $query->exists();
    }

    /**
     * Find a catalogue brand whose name matches the given retail brand name
     * (case-insensitive, ignoring punctuation/whitespace). Used so we can
     * share a single code between sibling retail+catalogue brand records.
     */
    private function catalogueBrandByName(string $name): ?BrandCatalogueBrand
    {
        $key = $this->normalizeBrandName($name);
        if ($key === '') {
            return null;
        }

        return BrandCatalogueBrand::query()
            ->get(['id', 'name', 'sku_code'])
            ->first(fn ($b) => $this->normalizeBrandName((string) $b->name) === $key);
    }

    private function retailBrandByName(string $name): ?Brand
    {
        $key = $this->normalizeBrandName($name);
        if ($key === '') {
            return null;
        }

        return Brand::query()
            ->get(['id', 'name', 'sku_code'])
            ->first(fn ($b) => $this->normalizeBrandName((string) $b->name) === $key);
    }

    private function normalizeBrandName(string $name): string
    {
        $name = strtoupper(trim($name));
        $name = preg_replace('/[^A-Z0-9]+/', '', $name) ?? '';

        return $name;
    }
}
