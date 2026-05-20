<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueStyle;
use Illuminate\Support\Str;

/**
 * Parses the small free-text "intake" format the operator dictates from a shelf
 * photo (e.g. "Brand: Cherish / Style: Saniya Boho Braid / Main (Length): 20\"")
 * and resolves catalogue references for the V2 intake controller.
 */
final class HeiQuickIntake
{
    /**
     * @return array{
     *     photo_number:?int,
     *     brand:?string,
     *     classification_path:array<int,string>,
     *     product_type:?string,
     *     style:?string,
     *     main_axis:?string,
     *     sub_axis:?string,
     *     variant_rows:array<int,array<string,mixed>>,
     *     common_rows:array<int,array<string,mixed>>,
     *     shelf_location:?string,
     *     notes:?string
     * }
     */
    public static function parse(string $raw): array
    {
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $result = [
            'photo_number' => self::detectPhotoNumber($raw),
            'brand' => null,
            'classification_path' => [],
            'product_type' => null,
            'style' => null,
            'main_axis' => null,
            'sub_axis' => null,
            'variant_rows' => [],
            'common_rows' => [],
            'shelf_location' => null,
            'notes' => null,
        ];

        $mainValues = [];
        $subValues = [];
        $collectingNotes = false;
        /** @var array<int,string> */
        $notesBuffer = [];

        for ($i = 0, $lineCount = count($lines); $i < $lineCount; $i++) {
            $clean = trim($lines[$i]);
            if ($clean === '' && ! $collectingNotes) {
                continue;
            }

            if ($collectingNotes) {
                if ($clean !== '' && self::lineLooksLikeFieldHeader($clean)) {
                    $collectingNotes = false;
                    $i--;

                    continue;
                }
                if ($clean !== '' || $notesBuffer !== []) {
                    $notesBuffer[] = $clean;
                }

                continue;
            }

            if (preg_match('/^\s*(?:photo|photo number|photo #)\s*[:#]\s*(.+)$/i', $clean, $m)) {
                $num = self::extractInt($m[1]);
                if ($num !== null) {
                    $result['photo_number'] = $num;
                }

                continue;
            }

            if (preg_match('/^\s*brand\s*[:\-]\s*(.+)$/i', $clean, $m)) {
                $result['brand'] = trim($m[1]) ?: null;

                continue;
            }

            if (preg_match('/^\s*(?:grouping(?:\s*path)?|category|classification)\s*[:\-]\s*(.+)$/i', $clean, $m)) {
                $result['classification_path'] = self::splitPath($m[1]);

                continue;
            }

            if (preg_match('/^\s*product\s*type\s*[:\-]\s*(.+)$/i', $clean, $m)) {
                $result['product_type'] = trim($m[1]) ?: null;

                continue;
            }

            if (preg_match('/^\s*(?:style(?:\s*\/?\s*family)?|family)\s*[:\-]\s*(.+)$/i', $clean, $m)) {
                $result['style'] = trim($m[1]) ?: null;

                continue;
            }

            if (preg_match('/^\s*(?:shelf(?:\s*\/\s*area)?|shop\s*floor|location)\s*[:\-]\s*(.+)$/i', $clean, $m)) {
                $result['shelf_location'] = trim($m[1]) ?: null;

                continue;
            }

            if (preg_match('/^\s*length\s*[:\-]\s*(.+)$/i', $clean, $m)) {
                $result['main_axis'] = 'Length';
                $mainValues = self::splitValues($m[1]);

                continue;
            }

            if (preg_match('/^\s*main\s*\(([^)]+)\)\s*[:\-]\s*(.+)$/i', $clean, $m)) {
                $result['main_axis'] = trim($m[1]) ?: 'Length';
                $mainValues = self::splitValues($m[2]);

                continue;
            }

            if (preg_match('/^\s*sub\s*\(([^)]+)\)\s*[:\-]\s*(.+)$/i', $clean, $m)) {
                $result['sub_axis'] = trim($m[1]) ?: 'Colour';
                $subValues = self::splitValues($m[2]);

                continue;
            }

            if (preg_match('/^\s*common\s*\(([^)]+)\)\s*[:\-]\s*(.+)$/i', $clean, $m)) {
                $name = trim($m[1]) ?: 'Common';
                $values = self::splitValues($m[2]);
                if ($values !== []) {
                    $result['common_rows'][] = ['name' => $name, 'values' => $values];
                }

                continue;
            }

            if (preg_match('/^\s*common\s*[:\-]\s*(.+)$/i', $clean, $m)) {
                $values = self::splitValues($m[1]);
                if ($values !== []) {
                    $result['common_rows'][] = ['name' => 'Pack', 'values' => $values];
                }

                continue;
            }

            if (preg_match('/^\s*notes?\s*[:\-]\s*(.*)$/i', $clean, $m)) {
                $collectingNotes = true;
                $notesBuffer = [];
                if (trim($m[1]) !== '') {
                    $notesBuffer[] = trim($m[1]);
                }

                continue;
            }
        }

        $joinedNotes = trim(implode("\n", array_map('trim', $notesBuffer)));
        $result['notes'] = $notesBuffer === [] || $joinedNotes === '' ? null : $joinedNotes;

        if ($mainValues !== []) {
            foreach ($mainValues as $main) {
                $result['variant_rows'][] = [
                    'main_value' => $main,
                    'sub_axis' => $result['sub_axis'] ?: 'Colour',
                    'sub_values' => $subValues,
                    'notes' => null,
                ];
            }
        } elseif ($subValues !== []) {
            $result['variant_rows'][] = [
                'main_value' => 'Unspecified',
                'sub_axis' => $result['sub_axis'] ?: 'Colour',
                'sub_values' => $subValues,
                'notes' => null,
            ];
        }

        if (! $result['main_axis']) {
            $result['main_axis'] = 'Length';
        }
        if (! $result['sub_axis']) {
            $result['sub_axis'] = 'Colour';
        }

        return $result;
    }

    /**
     * True when a line starts a new structured field (ends free-form notes block).
     */
    private static function lineLooksLikeFieldHeader(string $line): bool
    {
        return (bool) preg_match(
            '/^(photo|photo number|photo #|brand|grouping|category|classification|product\s*type|style|family|variants|length|main\s*\(|sub\s*\(|common(\s|\(|:)|shelf(?:\s*\/\s*area)?|shop\s*floor|location|notes?)\s*[:\-#]/iu',
            $line
        );
    }

    /**
     * @return array{brand:?BrandCatalogueBrand, product_type:?BrandCatalogueProductType, style:?BrandCatalogueStyle, brand_was_created:bool}
     */
    public static function resolveCatalogue(
        ?string $brandName,
        ?string $productTypeName,
        ?string $styleName,
        bool $createBrandIfMissing = true,
        bool $linkCatalogueProductTypeAndStyle = true,
    ): array {
        $brand = self::matchBrand($brandName);
        $brandWasCreated = false;
        if (! $brand && $createBrandIfMissing) {
            $trimmed = $brandName !== null ? trim($brandName) : '';
            if ($trimmed !== '') {
                $brand = self::createCatalogueBrandFromOperatorName($trimmed);
                $brandWasCreated = true;
            }
        }
        $productType = null;
        $style = null;
        if ($linkCatalogueProductTypeAndStyle && $brand) {
            $productType = self::matchProductType($brand, $productTypeName);
            $style = self::matchStyle($brand, $productType, $styleName);
        }

        return [
            'brand' => $brand,
            'product_type' => $productType,
            'style' => $style,
            'brand_was_created' => $brandWasCreated,
        ];
    }

    /**
     * Inserts a row into {@see BrandCatalogueBrand} when the operator type text has no match.
     */
    private static function createCatalogueBrandFromOperatorName(string $name): BrandCatalogueBrand
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($clean === '') {
            throw new \InvalidArgumentException('Brand name required to create catalogue row.');
        }

        $catalogueId = (int) BrandCatalogue::query()->orderBy('id')->value('id');
        if ($catalogueId === 0) {
            throw new \RuntimeException('No brand_catalogues row exists; cannot add a brand.');
        }

        $baseSlug = Str::slug($clean) ?: 'brand';
        $slug = $baseSlug;
        $i = 2;
        while (BrandCatalogueBrand::query()
            ->where('brand_catalogue_id', $catalogueId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug.'-'.$i;
            $i++;
        }

        $nextSort = ((int) BrandCatalogueBrand::query()
            ->where('brand_catalogue_id', $catalogueId)
            ->max('sort_order')) + 1;

        return BrandCatalogueBrand::query()->create([
            'brand_catalogue_id' => $catalogueId,
            'name' => $clean,
            'slug' => $slug,
            'note' => 'Added automatically from php artisan hei:quick (shop shelf intake).',
            'is_active' => true,
            'sort_order' => $nextSort,
        ]);
    }

    private static function matchBrand(?string $name): ?BrandCatalogueBrand
    {
        if (! $name) {
            return null;
        }

        $brands = BrandCatalogueBrand::query()->orderBy('name')->get();
        $byLengthDesc = $brands->sortByDesc(fn (BrandCatalogueBrand $b): int => strlen((string) $b->name))->values();
        $byLengthAsc = $brands->sortBy(fn (BrandCatalogueBrand $b): int => strlen((string) $b->name))->values();

        foreach (self::brandQueryCandidates($name) as $variant) {
            $lower = Str::lower($variant);
            foreach ($byLengthDesc as $brand) {
                if (Str::lower((string) $brand->name) === $lower) {
                    return $brand;
                }
            }
        }

        foreach (self::brandQueryCandidates($name) as $variant) {
            $lower = Str::lower($variant);
            foreach ($byLengthDesc as $brand) {
                $catalogue = Str::lower((string) $brand->name);
                if ($catalogue !== '' && str_starts_with($lower, $catalogue)) {
                    return $brand;
                }
            }
        }

        if (strlen(Str::lower(trim($name))) >= 2) {
            foreach (self::brandQueryCandidates($name) as $variant) {
                $lower = Str::lower($variant);
                foreach ($byLengthAsc as $brand) {
                    $catalogue = Str::lower((string) $brand->name);
                    if ($catalogue !== '' && str_starts_with($catalogue, $lower)) {
                        return $brand;
                    }
                }
            }
        }

        $lowerFull = Str::lower(trim($name));
        foreach ($byLengthDesc as $brand) {
            $catalogue = Str::lower((string) $brand->name);
            if ($catalogue !== '' && str_contains($lowerFull, $catalogue)) {
                return $brand;
            }
        }

        return null;
    }

    /**
     * Variants of operator-entered text (e.g. strip trailing "Collection") used to hit catalogue rows.
     *
     * @return list<string>
     */
    private static function brandQueryCandidates(string $raw): array
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $raw));
        $out = [];
        if ($clean !== '') {
            $out[] = $clean;
        }
        $stripped = (string) preg_replace('/\s+(collection|couture|brands?)\s*$/iu', '', $clean);
        if ($stripped !== '' && ! in_array($stripped, $out, true)) {
            $out[] = $stripped;
        }

        return array_values(array_unique($out));
    }

    /**
     * Hints when typed text does not map to a brand catalogue row (for CLI / operators).
     *
     * @return list<string>
     */
    public static function suggestCatalogueBrandNames(string $typed): array
    {
        $clean = trim($typed);
        if ($clean === '') {
            return [];
        }

        $words = array_values(array_filter(
            preg_split('/\W+/u', Str::lower($clean)) ?: [],
            static fn (string $w): bool => strlen($w) >= 2,
        ));

        $q = BrandCatalogueBrand::query()->orderBy('name');
        if ($words !== []) {
            $q->where(function ($sub) use ($words): void {
                foreach ($words as $word) {
                    $sub->orWhereRaw('LOWER(name) LIKE ?', ['%'.$word.'%']);
                }
            });
        }

        return $q->limit(12)->pluck('name')->all();
    }

    private static function matchProductType(BrandCatalogueBrand $brand, ?string $name): ?BrandCatalogueProductType
    {
        if (! $name) {
            return null;
        }
        $clean = trim($name);
        $lower = Str::lower($clean);

        $base = BrandCatalogueProductType::query()->where('brand_catalogue_brand_id', $brand->id);

        $hit = (clone $base)->whereRaw('LOWER(name) = ?', [$lower])->first();
        if ($hit !== null) {
            return $hit;
        }

        $hit = (clone $base)->where('name', 'like', '%'.$clean.'%')->orderByRaw('LENGTH(name)')->first();
        if ($hit !== null) {
            return $hit;
        }

        $tokens = array_values(array_filter(
            preg_split('/[^a-z0-9]+/i', $lower) ?: [],
            static fn (string $t): bool => strlen($t) >= 3,
        ));

        if ($tokens === []) {
            return null;
        }

        if (count($tokens) === 1) {
            $hits = (clone $base)->whereRaw('LOWER(name) LIKE ?', ['%'.$tokens[0].'%'])->orderByRaw('LENGTH(name)')->get();

            return $hits->count() === 1 ? $hits->first() : null;
        }

        foreach ((clone $base)->orderByRaw('LENGTH(name)')->get() as $productType) {
            $row = Str::lower($productType->name);
            $all = true;
            foreach ($tokens as $token) {
                if (! str_contains($row, $token)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return $productType;
            }
        }

        return null;
    }

    private static function matchStyle(
        ?BrandCatalogueBrand $brand,
        ?BrandCatalogueProductType $productType,
        ?string $name,
    ): ?BrandCatalogueStyle {
        if (! $name) {
            return null;
        }
        $clean = trim($name);
        $lower = Str::lower($clean);

        $base = BrandCatalogueStyle::query();
        if ($productType) {
            $candidates = (clone $base)
                ->where('brand_catalogue_product_type_id', $productType->id)
                ->whereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?? (clone $base)
                    ->where('brand_catalogue_product_type_id', $productType->id)
                    ->where('name', 'like', '%'.$clean.'%')
                    ->orderByRaw('LENGTH(name)')
                    ->first();
            if ($candidates) {
                return $candidates;
            }
        }
        if ($brand) {
            return (clone $base)
                ->where('brand_catalogue_brand_id', $brand->id)
                ->whereRaw('LOWER(name) = ?', [$lower])
                ->first()
                ?? (clone $base)
                    ->where('brand_catalogue_brand_id', $brand->id)
                    ->where('name', 'like', '%'.$clean.'%')
                    ->orderByRaw('LENGTH(name)')
                    ->first();
        }

        return null;
    }

    private static function detectPhotoNumber(string $raw): ?int
    {
        if (preg_match('/photo\s*#?\s*0*(\d{1,4})/i', $raw, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/photo[\s_-]*0*(\d{1,4})\.(?:jpe?g|png|webp|bmp)/i', $raw, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private static function extractInt(string $raw): ?int
    {
        if (preg_match('/0*(\d{1,4})/', $raw, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private static function splitPath(string $raw): array
    {
        $parts = preg_split('/\s*(?:>|→|\/)\s*/u', trim($raw)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $v): bool => $v !== ''));
    }

    /**
     * @return array<int,string>
     */
    private static function splitValues(string $raw): array
    {
        $parts = preg_split('/\s*[,;|]\s*/', trim($raw)) ?: [];
        $cleaned = array_filter(array_map('trim', $parts), fn (string $v): bool => $v !== '');

        return array_values(array_unique($cleaned));
    }
}
