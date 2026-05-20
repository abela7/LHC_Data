<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;

class PictureProductMapRepository
{
    /**
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $pictures = null;

    /**
     * @var array{exact: array<string, array<string, string>>, by_name: array<string, array<int, array<string, string>>>}|null
     */
    private ?array $aiIndex = null;

    public function __construct(
        private readonly CatalogueAiCsvLocator $catalogueAiCsvLocator,
        private readonly CatalogueAiProductIdResolver $catalogueAiProductIdResolver,
    ) {
    }

    public function hasData(): bool
    {
        return is_file($this->mappingPath());
    }

    /**
     * @return array<int, string>
     */
    public function pictureIds(): array
    {
        return array_values(array_map(
            fn (array $picture): string => (string) $picture['picture_id'],
            $this->pictures(),
        ));
    }

    public function find(string $pictureId): ?array
    {
        foreach ($this->pictures() as $picture) {
            if ($picture['picture_id'] === $pictureId) {
                return $this->aggregatePicture($picture['picture_id'], $picture['product_entries']);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function brandOptions(?PictureRange $pictureRange = null): array
    {
        $brands = [];

        foreach ($this->pictures() as $picture) {
            if ($pictureRange !== null && ! $this->matchesPictureRange($picture['picture_id'], $pictureRange)) {
                continue;
            }

            foreach ($picture['product_entries'] as $entry) {
                $brandName = trim((string) ($entry['brand_name'] ?? ''));

                if ($brandName === '') {
                    continue;
                }

                $brands[$brandName] = true;
            }
        }

        $options = array_keys($brands);
        sort($options);

        return $options;
    }

    /**
     * @return array{pictures: int, rows: int, products: int}
     */
    public function stats(string $search = '', string $brandFilter = '', string $categoryFilter = '', ?PictureRange $pictureRange = null): array
    {
        $pictures = $this->filteredPictures($search, $brandFilter, $categoryFilter, $pictureRange);

        return [
            'pictures' => count($pictures),
            'rows' => array_sum(array_map(fn (array $picture): int => $picture['row_count'], $pictures)),
            'products' => array_sum(array_map(fn (array $picture): int => $picture['product_count'], $pictures)),
        ];
    }

    public function paginate(
        string $search = '',
        string $brandFilter = '',
        string $categoryFilter = '',
        ?PictureRange $pictureRange = null,
        int $perPage = 24,
    ): LengthAwarePaginator {
        $pictures = $this->filteredPictures($search, $brandFilter, $categoryFilter, $pictureRange);
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $offset = max(0, ($currentPage - 1) * $perPage);

        return new LengthAwarePaginator(
            array_map(
                fn (array $picture): object => (object) $picture,
                array_slice($pictures, $offset, $perPage),
            ),
            count($pictures),
            $perPage,
            $currentPage,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    private function mappingPath(): string
    {
        return storage_path('app/picture-product-map.json');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pictures(): array
    {
        if ($this->pictures !== null) {
            return $this->pictures;
        }

        if (! $this->hasData()) {
            return $this->pictures = [];
        }

        $contents = (string) file_get_contents($this->mappingPath());
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The picture-product map is not valid JSON.');
        }

        $pictures = [];

        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $pictureId = trim((string) ($item['picture_id'] ?? ''));

            if ($pictureId === '') {
                continue;
            }

            $entries = [];

            foreach ((array) ($item['products'] ?? []) as $product) {
                if (! is_array($product)) {
                    continue;
                }

                $brandName = trim((string) ($product['brand'] ?? ''));
                $productName = trim((string) ($product['product_name'] ?? ''));

                if ($productName === '') {
                    continue;
                }

                $aiRow = $this->lookupAiRow($brandName, $productName);

                $entries[] = [
                    'brand_name' => $brandName,
                    'product_name' => $productName,
                    'product_id' => $aiRow['product_id'] ?? null,
                    'category_name' => $aiRow['category'] ?? null,
                    'category_slug' => $this->categorySlug($aiRow['category'] ?? null),
                    'subcategory' => $aiRow['subcategory'] ?? null,
                    'confidence' => $aiRow['confidence'] ?? null,
                    'confidence_reason' => $aiRow['confidence_reason'] ?? null,
                    'brand_url' => null,
                    'product_url' => null,
                ];
            }

            if ($entries === []) {
                continue;
            }

            $pictures[] = [
                'picture_id' => $pictureId,
                'product_entries' => $entries,
            ];
        }

        usort($pictures, fn (array $left, array $right): int => strcmp($left['picture_id'], $right['picture_id']));

        return $this->pictures = $pictures;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function filteredPictures(
        string $search = '',
        string $brandFilter = '',
        string $categoryFilter = '',
        ?PictureRange $pictureRange = null,
    ): array {
        $needle = $this->normalize($search);
        $pictures = [];

        foreach ($this->pictures() as $picture) {
            $pictureId = $picture['picture_id'];

            if ($pictureRange !== null && ! $this->matchesPictureRange($pictureId, $pictureRange)) {
                continue;
            }

            $pictureMatchesSearch = $needle !== '' && str_contains($this->normalize($pictureId), $needle);
            $matchingEntries = [];

            foreach ($picture['product_entries'] as $entry) {
                if (! $this->matchesFilters($entry, $needle, $brandFilter, $categoryFilter, $pictureMatchesSearch)) {
                    continue;
                }

                $matchingEntries[] = $entry;
            }

            if ($matchingEntries === []) {
                continue;
            }

            $pictures[] = $this->aggregatePicture($pictureId, $matchingEntries);
        }

        return $pictures;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function matchesFilters(
        array $entry,
        string $needle,
        string $brandFilter,
        string $categoryFilter,
        bool $pictureMatchesSearch,
    ): bool {
        $brandName = trim((string) ($entry['brand_name'] ?? ''));

        if ($brandFilter !== '') {
            if ($brandFilter === '__blank__' && $brandName !== '') {
                return false;
            }

            if ($brandFilter !== '__blank__' && $brandName !== $brandFilter) {
                return false;
            }
        }

        if ($categoryFilter !== '' && ($entry['category_slug'] ?? null) !== $categoryFilter) {
            return false;
        }

        if ($needle === '' || $pictureMatchesSearch) {
            return true;
        }

        foreach ([
            (string) ($entry['brand_name'] ?? ''),
            (string) ($entry['product_name'] ?? ''),
            (string) ($entry['product_id'] ?? ''),
            (string) ($entry['category_name'] ?? ''),
            (string) ($entry['subcategory'] ?? ''),
        ] as $haystack) {
            if ($haystack !== '' && str_contains($this->normalize($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function aggregatePicture(string $pictureId, array $entries): array
    {
        $brands = [];
        $categories = [];
        $productNames = [];

        foreach ($entries as $entry) {
            $brandName = trim((string) ($entry['brand_name'] ?? ''));
            if ($brandName !== '') {
                $brands[$brandName] = true;
            }

            $categoryName = trim((string) ($entry['category_name'] ?? ''));
            if ($categoryName !== '') {
                $categories[$categoryName] = true;
            }

            $productName = trim((string) ($entry['product_name'] ?? ''));
            if ($productName !== '') {
                $productNames[$productName] = true;
            }
        }

        ksort($brands);
        ksort($categories);
        ksort($productNames);

        return [
            'picture_id' => $pictureId,
            'row_count' => count($entries),
            'product_count' => count($productNames),
            'brand_count' => count($brands),
            'category_count' => count($categories),
            'products' => array_keys($productNames),
            'brands' => array_keys($brands),
            'categories' => array_keys($categories),
            'product_entries' => array_map(fn (array $entry): object => (object) $entry, $entries),
            'brand_entries' => array_map(
                fn (string $brandName): object => (object) ['name' => $brandName, 'url' => null],
                array_keys($brands),
            ),
        ];
    }

    private function matchesPictureRange(string $pictureId, PictureRange $pictureRange): bool
    {
        if ($pictureRange->from !== null && strcmp($pictureId, $pictureRange->from) < 0) {
            return false;
        }

        if ($pictureRange->to !== null && strcmp($pictureId, $pictureRange->to) > 0) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, string>|null
     */
    private function lookupAiRow(string $brandName, string $productName): ?array
    {
        $index = $this->aiIndex();
        $groupKey = $this->catalogueAiProductIdResolver->groupKey($brandName, $productName);

        if (isset($index['exact'][$groupKey])) {
            return $index['exact'][$groupKey];
        }

        $nameKey = $this->normalize($productName);
        $candidates = $index['by_name'][$nameKey] ?? [];

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        return null;
    }

    /**
     * @return array{exact: array<string, array<string, string>>, by_name: array<string, array<int, array<string, string>>>}
     */
    private function aiIndex(): array
    {
        if ($this->aiIndex !== null) {
            return $this->aiIndex;
        }

        $path = $this->catalogueAiCsvLocator->path();

        if (! is_file($path)) {
            return $this->aiIndex = ['exact' => [], 'by_name' => []];
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return $this->aiIndex = ['exact' => [], 'by_name' => []];
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return $this->aiIndex = ['exact' => [], 'by_name' => []];
        }

        $header = array_map(fn ($value): string => trim((string) $value), $header);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];

        $exact = [];
        $byName = [];

        while (($row = fgetcsv($handle)) !== false) {
            $record = [];

            foreach ($header as $index => $column) {
                $record[$column] = trim((string) ($row[$index] ?? ''));
            }

            $productName = $record['name'] ?? '';

            if ($productName === '') {
                continue;
            }

            $brandName = $record['brand'] ?? '';
            $groupKey = $this->catalogueAiProductIdResolver->groupKey($brandName, $productName);
            $exact[$groupKey] = $record;

            $nameKey = $this->normalize($productName);
            $byName[$nameKey] ??= [];
            $byName[$nameKey][] = $record;
        }

        fclose($handle);

        return $this->aiIndex = [
            'exact' => $exact,
            'by_name' => $byName,
        ];
    }

    private function categorySlug(?string $categoryName): ?string
    {
        return match (trim((string) $categoryName)) {
            'Hair' => 'hair',
            'Body Care' => 'body-care',
            'Cosmetics' => 'cosmetics',
            default => null,
        };
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
