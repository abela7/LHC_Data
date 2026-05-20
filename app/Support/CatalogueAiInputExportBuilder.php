<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class CatalogueAiInputExportBuilder
{
    public function __construct(
        private readonly CatalogueAiProductIdResolver $catalogueAiProductIdResolver,
    ) {
    }

    /**
     * @return array<int, array{product_id: string, category: string, name: string, brand: string}>
     */
    public function rows(?PictureRange $pictureRange = null): array
    {
        return array_map(
            fn (array $row): array => [
                'product_id' => $row['product_id'],
                'category' => $row['category'],
                'name' => $row['name'],
                'brand' => $row['brand'],
            ],
            $this->groupedRows($pictureRange),
        );
    }

    /**
     * @return array<int, array{product_id: string, category: string, name: string, brand: string, picture_ids: array<int, string>}>
     */
    public function groupedRows(?PictureRange $pictureRange = null): array
    {
        $rowsQuery = DB::table('observed_products')
            ->leftJoin('categories', 'categories.id', '=', 'observed_products.category_id')
            ->select([
                'observed_products.brand',
                'observed_products.canonical_brand',
                'observed_products.product_name',
                'observed_products.picture_id',
                'categories.name as category_name',
            ])
            ->orderBy('observed_products.canonical_brand')
            ->orderBy('observed_products.brand')
            ->orderBy('observed_products.product_name');

        if ($pictureRange !== null) {
            $pictureRange->apply($rowsQuery, 'observed_products.picture_id');
        }

        $rows = $rowsQuery->get();

        $groups = [];

        foreach ($rows as $row) {
            $observedBrand = trim((string) $row->brand);
            $canonicalBrand = trim((string) $row->canonical_brand);
            $brand = $this->catalogueAiProductIdResolver->displayBrand($canonicalBrand, $observedBrand);
            $productName = trim((string) $row->product_name);

            if ($productName === '') {
                continue;
            }

            $groupKey = $this->catalogueAiProductIdResolver->groupKey($brand, $productName);

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'brand' => $brand,
                    'product_name' => $productName,
                    'category_counts' => [],
                    'picture_ids' => [],
                ];
            }

            $categoryName = trim((string) ($row->category_name ?? ''));
            if ($categoryName === '') {
                $categoryName = 'Unassigned';
            }

            $groups[$groupKey]['category_counts'][$categoryName] = ($groups[$groupKey]['category_counts'][$categoryName] ?? 0) + 1;

            $pictureId = trim((string) $row->picture_id);
            if ($pictureId !== '') {
                $groups[$groupKey]['picture_ids'][$pictureId] = true;
            }
        }

        $categoryPriority = [
            'Hair' => 1,
            'Body Care' => 2,
            'Cosmetics' => 3,
            'Unassigned' => 99,
        ];

        $usedIds = [];
        $exportRows = [];

        foreach ($groups as $groupKey => $group) {
            $categoryCounts = $group['category_counts'];

            uksort($categoryCounts, function (string $a, string $b) use ($categoryCounts, $categoryPriority): int {
                $countCompare = $categoryCounts[$b] <=> $categoryCounts[$a];

                if ($countCompare !== 0) {
                    return $countCompare;
                }

                $priorityCompare = ($categoryPriority[$a] ?? 999) <=> ($categoryPriority[$b] ?? 999);

                if ($priorityCompare !== 0) {
                    return $priorityCompare;
                }

                return strcmp($a, $b);
            });

            $category = array_key_first($categoryCounts) ?: 'Unassigned';
            $productId = $this->catalogueAiProductIdResolver->productId(
                canonicalBrand: $group['brand'],
                observedBrand: null,
                productName: $group['product_name'],
            );

            if ($productId === null) {
                continue;
            }

            if (isset($usedIds[$productId]) && $usedIds[$productId] !== $groupKey) {
                $productId = 'PRD-'.strtoupper(substr(sha1($groupKey), 0, 16));
            }

            $usedIds[$productId] = $groupKey;
            $pictureIds = array_keys($group['picture_ids']);
            sort($pictureIds);

            $exportRows[] = [
                'product_id' => $productId,
                'category' => $category,
                'name' => $group['product_name'],
                'brand' => $group['brand'],
                'picture_ids' => $pictureIds,
            ];
        }

        usort($exportRows, function (array $a, array $b): int {
            $brandCompare = strcmp($a['brand'], $b['brand']);

            if ($brandCompare !== 0) {
                return $brandCompare;
            }

            return strcmp($a['name'], $b['name']);
        });

        return $exportRows;
    }

    /**
     * @return array{raw_rows: int, pictures: int, grouped_products: int}
     */
    public function stats(?PictureRange $pictureRange = null): array
    {
        $rowsQuery = DB::table('observed_products');

        if ($pictureRange !== null) {
            $pictureRange->apply($rowsQuery, 'observed_products.picture_id');
        }

        return [
            'raw_rows' => (clone $rowsQuery)->count(),
            'pictures' => (clone $rowsQuery)->distinct('observed_products.picture_id')->count('observed_products.picture_id'),
            'grouped_products' => count($this->rows($pictureRange)),
        ];
    }

    public function count(?PictureRange $pictureRange = null): int
    {
        return count($this->rows($pictureRange));
    }

    public function writeCsv(string $path, ?PictureRange $pictureRange = null): int
    {
        $rows = $this->rows($pictureRange);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $handle = fopen($path, 'w');

        fputcsv($handle, ['product_id', 'category', 'name', 'brand']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['product_id'],
                $row['category'],
                $row['name'],
                $row['brand'],
            ]);
        }

        fclose($handle);

        return count($rows);
    }
}
