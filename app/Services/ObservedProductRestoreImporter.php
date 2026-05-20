<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ObservedBrandMapping;
use App\Models\ObservedProduct;
use App\Support\CatalogueAiCsvLocator;
use App\Support\CatalogueAiProductIdResolver;
use App\Support\ObservedBrandVerdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ObservedProductRestoreImporter
{
    public function __construct(
        private readonly CatalogueAiCsvLocator $catalogueAiCsvLocator,
        private readonly CatalogueAiEnrichmentImporter $catalogueAiEnrichmentImporter,
        private readonly CatalogueAiProductIdResolver $catalogueAiProductIdResolver,
    ) {
    }

    /**
     * @return array{
     *   path: string,
     *   pictures: int,
     *   imported_rows: int,
     *   brands: int,
     *   matched_categories: int,
     *   heuristic_categories: int,
     *   skipped_entries: int,
     *   ai_summary: array<string, mixed>|null
     * }
     */
    public function import(?string $path = null, bool $syncAi = true): array
    {
        $path = $path !== null && trim($path) !== ''
            ? trim($path)
            : storage_path('app/picture-product-map.json');

        $pictures = $this->readMapping($path);
        $categoryIds = Category::query()->pluck('id', 'slug')->all();
        $aiLookup = $this->buildAiLookup($this->catalogueAiCsvLocator->path());
        $aiSummary = $syncAi ? $this->catalogueAiEnrichmentImporter->import() : null;

        $rows = [];
        $brandMappings = [];
        $matchedCategories = 0;
        $heuristicCategories = 0;
        $skippedEntries = 0;
        $now = now();

        foreach ($pictures as $picture) {
            $pictureId = trim((string) ($picture['picture_id'] ?? ''));
            $products = $picture['products'] ?? null;

            if ($pictureId === '' || ! is_array($products)) {
                continue;
            }

            foreach (array_values($products) as $index => $product) {
                if (! is_array($product)) {
                    $skippedEntries++;

                    continue;
                }

                $brand = trim((string) ($product['brand'] ?? ''));
                $productName = trim((string) ($product['product_name'] ?? ''));

                if ($brand === '' || $productName === '') {
                    $skippedEntries++;

                    continue;
                }

                $brandVerdict = ObservedBrandVerdict::resolve($brand);
                $canonicalBrand = trim((string) ($brandVerdict['canonical_brand'] ?? ''));
                $brandLine = $brandVerdict['brand_line'] ?? null;
                $aiRow = $this->resolveAiRow($aiLookup, $brand, $canonicalBrand, $productName);
                $categorySlug = $this->resolveCategorySlug($productName, $aiRow['category'] ?? null);

                if (isset($aiRow['category'])) {
                    $matchedCategories++;
                } else {
                    $heuristicCategories++;
                }

                $compositeKey = $pictureId.'|'.$brand.'|'.$productName;
                $rows[$compositeKey] = [
                    'picture_id' => $pictureId,
                    'sort_order' => $index + 1,
                    'brand' => $brand,
                    'canonical_brand' => $canonicalBrand !== '' ? $canonicalBrand : $brand,
                    'brand_line' => $brandLine,
                    'category_id' => $categoryIds[$categorySlug] ?? null,
                    'product_name' => $productName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $brandMappings[$brand] = [
                    'observed_brand' => $brand,
                    'canonical_brand' => $canonicalBrand !== '' ? $canonicalBrand : $brand,
                    'brand_line' => $brandLine,
                    'official_source_url' => $brandVerdict['official_source_url'] ?? null,
                    'notes' => $brandVerdict['notes'] ?? null,
                    'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($rows, $brandMappings, $now): void {
            ObservedProduct::query()->delete();

            foreach (array_chunk(array_values($rows), 250) as $chunk) {
                ObservedProduct::query()->insert($chunk);
            }

            foreach ($brandMappings as $mapping) {
                ObservedBrandMapping::query()->updateOrCreate(
                    ['observed_brand' => $mapping['observed_brand']],
                    $mapping + ['created_at' => $now],
                );
            }
        });

        return [
            'path' => $path,
            'pictures' => count($pictures),
            'imported_rows' => count($rows),
            'brands' => count($brandMappings),
            'matched_categories' => $matchedCategories,
            'heuristic_categories' => $heuristicCategories,
            'skipped_entries' => $skippedEntries,
            'ai_summary' => $aiSummary,
        ];
    }

    /**
     * @return array<int, array{picture_id: string, products: array<int, array<string, mixed>>}>
     */
    private function readMapping(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("The picture-product map was not found at {$path}.");
        }

        $contents = (string) file_get_contents($path);
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("The picture-product map at {$path} is not valid JSON.");
        }

        return array_values(array_filter($decoded, fn ($item): bool => is_array($item)));
    }

    /**
     * @return array<string, array{category: string|null}>
     */
    private function buildAiLookup(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $header = array_map(fn ($value): string => $this->sanitizeCsvValue($value), $header);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
        $lookup = [];

        while (($csvRow = fgetcsv($handle)) !== false) {
            $row = [];

            foreach ($header as $index => $column) {
                if ($column === '') {
                    continue;
                }

                $row[$column] = $this->sanitizeCsvValue($csvRow[$index] ?? '');
            }

            $productName = trim((string) ($row['name'] ?? ''));
            $observedBrand = trim((string) ($row['brand'] ?? ''));

            if ($productName === '' || $observedBrand === '') {
                continue;
            }

            $category = trim((string) ($row['category'] ?? ''));
            $lookup[$this->catalogueAiProductIdResolver->groupKey($observedBrand, $productName)] = [
                'category' => $category !== '' ? $category : null,
            ];

            $canonicalBrand = ObservedBrandVerdict::resolve($observedBrand)['canonical_brand'] ?? $observedBrand;

            if ($canonicalBrand !== '' && $canonicalBrand !== $observedBrand) {
                $lookup[$this->catalogueAiProductIdResolver->groupKey($canonicalBrand, $productName)] = [
                    'category' => $category !== '' ? $category : null,
                ];
            }
        }

        fclose($handle);

        return $lookup;
    }

    /**
     * @param  array<string, array{category: string|null}>  $lookup
     * @return array{category: string|null}|null
     */
    private function resolveAiRow(array $lookup, string $brand, string $canonicalBrand, string $productName): ?array
    {
        $candidates = [
            $this->catalogueAiProductIdResolver->groupKey($brand, $productName),
        ];

        if ($canonicalBrand !== '' && $canonicalBrand !== $brand) {
            $candidates[] = $this->catalogueAiProductIdResolver->groupKey($canonicalBrand, $productName);
        }

        foreach ($candidates as $candidate) {
            if (isset($lookup[$candidate])) {
                return $lookup[$candidate];
            }
        }

        return null;
    }

    private function resolveCategorySlug(string $productName, ?string $aiCategory): string
    {
        $normalizedAiCategory = Str::of((string) $aiCategory)->lower()->trim()->value();

        if ($normalizedAiCategory !== '') {
            return match ($normalizedAiCategory) {
                'hair' => 'hair',
                'body care' => 'body-care',
                'cosmetics' => 'cosmetics',
                default => 'hair',
            };
        }

        $normalized = Str::of($productName)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/u', ' ')
            ->trim()
            ->value();

        $cosmeticsKeywords = [
            'lipstick', 'lip gloss', 'lipgloss', 'foundation', 'concealer', 'powder', 'blush',
            'eyeshadow', 'eye shadow', 'eyeliner', 'mascara', 'highlighter', 'contour',
            'bronzer', 'primer', 'palette', 'nail polish', 'makeup',
        ];

        $hairKeywords = [
            'hair', 'braid', 'wig', 'weave', 'remy', 'clip in', 'bulk', 'curl', 'coil',
            'edge', 'relaxer', 'shampoo', 'conditioner', 'detangler', 'mousse', 'gel',
            'spritz', 'styling', 'sleek stick', 'braid sheen', 'leave in', 'hair color',
            'hair colour', 'scalp', 'perm', 'twist', 'extension', 'lace', 'bond',
        ];

        $bodyCareKeywords = [
            'body', 'lotion', 'soap', 'petroleum', 'jelly', 'glycerine', 'facial', 'face',
            'skin', 'micellar', 'astringent', 'brightening', 'moisturizing', 'moisturiser',
            'moisturizer', 'cleanser', 'cleansing water', 'exfoliating', 'cream',
        ];

        if ($this->containsAnyKeyword($normalized, $cosmeticsKeywords)) {
            return 'cosmetics';
        }

        if ($this->containsAnyKeyword($normalized, $hairKeywords)) {
            return 'hair';
        }

        if ($this->containsAnyKeyword($normalized, $bodyCareKeywords)) {
            return 'body-care';
        }

        return 'hair';
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function containsAnyKeyword(string $normalizedValue, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($normalizedValue, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeCsvValue(mixed $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        $value = str_replace("\0", '', $value);

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
        }

        return trim($value);
    }
}
