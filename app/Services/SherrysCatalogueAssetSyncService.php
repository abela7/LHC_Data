<?php

namespace App\Services;

use App\Models\CatalogueImage;
use App\Models\PdfCatalogueProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SherrysCatalogueAssetSyncService
{
    private const SOURCE_NAME = 'SHERRYS CATALOGUE 2026 JAN .pdf';

    private const SOURCE_LABELS = [
        'Sherrys catalogue extracted product image',
        'Sherrys catalogue extracted product image - high quality',
    ];

    /**
     * @return array<string, mixed>
     */
    public function sync(?string $folder = null, bool $fresh = false, bool $dryRun = false): array
    {
        $folder = $folder !== null && trim($folder) !== ''
            ? trim($folder)
            : public_path('SHERRYS CATALOGUE');

        if (! is_dir($folder)) {
            throw new RuntimeException("Sherrys extracted folder was not found at {$folder}.");
        }

        $manifestPath = $folder.DIRECTORY_SEPARATOR.'manifest.csv';

        if (! is_file($manifestPath)) {
            throw new RuntimeException("Sherrys manifest.csv was not found at {$manifestPath}.");
        }

        $manifestRows = $this->loadManifest($manifestPath, $folder);
        $manifestBySku = collect($manifestRows)->groupBy('sku');
        $highQualityBySku = $this->loadHighQualityImages($folder);
        $products = PdfCatalogueProduct::query()
            ->where('source_name', self::SOURCE_NAME)
            ->get(['id', 'product_code']);

        $productCodes = $products
            ->pluck('product_code')
            ->map(fn (string $code): string => $this->normalizeSku($code))
            ->filter()
            ->unique()
            ->values();

        $manifestCodes = $manifestBySku->keys()->values();
        $matchedCodes = $productCodes->intersect($manifestCodes)->values();
        $missingImageCodes = $productCodes->diff($manifestCodes)->values();
        $orphanManifestCodes = $manifestCodes->diff($productCodes)->values();

        $summary = [
            'folder' => $folder,
            'dry_run' => $dryRun,
            'fresh' => $fresh,
            'db_products' => $products->count(),
            'db_unique_codes' => $productCodes->count(),
            'manifest_rows' => count($manifestRows),
            'manifest_unique_codes' => $manifestCodes->count(),
            'high_quality_files' => $highQualityBySku->flatten(1)->count(),
            'matched_unique_codes' => $matchedCodes->count(),
            'db_codes_without_manifest_image' => $missingImageCodes->count(),
            'manifest_codes_not_in_db' => $orphanManifestCodes->count(),
            'products_touched' => 0,
            'images_deleted' => 0,
            'images_created' => 0,
            'sample_missing_codes' => $missingImageCodes->take(25)->values()->all(),
            'sample_orphan_manifest_codes' => $orphanManifestCodes->take(25)->values()->all(),
        ];

        if ($dryRun) {
            $summary['products_touched'] = $products
                ->filter(fn (PdfCatalogueProduct $product): bool => $manifestBySku->has($this->normalizeSku($product->product_code)))
                ->count();
            $summary['images_created'] = $this->plannedImageCount($products, $manifestBySku, $highQualityBySku);

            return $summary;
        }

        DB::transaction(function () use ($products, $manifestBySku, $highQualityBySku, $fresh, &$summary): void {
            foreach ($products as $product) {
                $sku = $this->normalizeSku($product->product_code);
                $manifestImages = $manifestBySku->get($sku, collect());
                $highQualityImages = $highQualityBySku->get($sku, collect());

                if ($manifestImages->isEmpty() && $highQualityImages->isEmpty()) {
                    continue;
                }

                if ($fresh) {
                    $deleted = CatalogueImage::query()
                        ->where('imageable_type', PdfCatalogueProduct::class)
                        ->where('imageable_id', $product->id)
                        ->whereIn('source_label', self::SOURCE_LABELS)
                        ->delete();

                    $summary['images_deleted'] += $deleted;
                }

                $existing = CatalogueImage::query()
                    ->where('imageable_type', PdfCatalogueProduct::class)
                    ->where('imageable_id', $product->id)
                    ->whereIn('source_label', self::SOURCE_LABELS)
                    ->pluck('external_url')
                    ->all();

                $existing = array_fill_keys($existing, true);
                $sortOrder = 0;
                $createdForProduct = 0;

                foreach ($highQualityImages as $image) {
                    $createdForProduct += $this->createImageIfMissing(
                        product: $product,
                        image: $image,
                        existing: $existing,
                        role: 'source_image_hq',
                        sourceLabel: 'Sherrys catalogue extracted product image - high quality',
                        sortOrder: $sortOrder++,
                        primary: $createdForProduct === 0,
                    );
                }

                foreach ($manifestImages as $image) {
                    $createdForProduct += $this->createImageIfMissing(
                        product: $product,
                        image: $image,
                        existing: $existing,
                        role: 'source_image',
                        sourceLabel: 'Sherrys catalogue extracted product image',
                        sortOrder: $sortOrder++,
                        primary: $createdForProduct === 0,
                    );
                }

                if ($createdForProduct > 0) {
                    $summary['products_touched']++;
                    $summary['images_created'] += $createdForProduct;
                }
            }
        });

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadManifest(string $manifestPath, string $folder): array
    {
        $handle = fopen($manifestPath, 'r');

        if ($handle === false) {
            throw new RuntimeException("Could not open {$manifestPath}.");
        }

        $header = fgetcsv($handle);

        if (! is_array($header)) {
            fclose($handle);
            throw new RuntimeException('Sherrys manifest.csv has no header row.');
        }

        $rows = [];
        $imagesDir = $folder.DIRECTORY_SEPARATOR.'images';

        while (($csvRow = fgetcsv($handle)) !== false) {
            $raw = array_combine($header, $csvRow);

            if (! is_array($raw)) {
                continue;
            }

            $sku = $this->normalizeSku((string) ($raw['sku'] ?? ''));
            $imageFile = trim((string) ($raw['image_file'] ?? ''));
            $path = $imagesDir.DIRECTORY_SEPARATOR.$imageFile;

            if ($sku === '' || $imageFile === '' || ! is_file($path)) {
                continue;
            }

            $rows[] = [
                'sku' => $sku,
                'page' => (int) ($raw['page'] ?? 0),
                'name' => trim((string) ($raw['name'] ?? '')),
                'image_file' => $imageFile,
                'path' => $path,
                'url' => asset('SHERRYS%20CATALOGUE/images/'.rawurlencode($imageFile)),
                'source_note' => 'Manifest page '.((int) ($raw['page'] ?? 0)).' crop bbox '.trim((string) ($raw['image_bbox'] ?? '')),
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private function loadHighQualityImages(string $folder): Collection
    {
        $dir = $folder.DIRECTORY_SEPARATOR.'product_images';

        if (! is_dir($dir)) {
            return collect();
        }

        $rows = [];

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.png') ?: [] as $path) {
            $file = basename($path);
            $sku = $this->skuFromImageFilename($file);

            if ($sku === '') {
                continue;
            }

            $rows[] = [
                'sku' => $sku,
                'page' => null,
                'name' => '',
                'image_file' => $file,
                'path' => $path,
                'url' => asset('SHERRYS%20CATALOGUE/product_images/'.rawurlencode($file)),
                'source_note' => 'High quality extracted Sherrys product image',
            ];
        }

        return collect($rows)->groupBy('sku');
    }

    /**
     * @param  Collection<int, PdfCatalogueProduct>  $products
     * @param  Collection<string, Collection<int, array<string, mixed>>>  $manifestBySku
     * @param  Collection<string, Collection<int, array<string, mixed>>>  $highQualityBySku
     */
    private function plannedImageCount(Collection $products, Collection $manifestBySku, Collection $highQualityBySku): int
    {
        return $products->sum(function (PdfCatalogueProduct $product) use ($manifestBySku, $highQualityBySku): int {
            $sku = $this->normalizeSku($product->product_code);

            return $manifestBySku->get($sku, collect())->count()
                + $highQualityBySku->get($sku, collect())->count();
        });
    }

    /**
     * @param  array<string, mixed>  $image
     * @param  array<string, bool>  $existing
     */
    private function createImageIfMissing(
        PdfCatalogueProduct $product,
        array $image,
        array &$existing,
        string $role,
        string $sourceLabel,
        int $sortOrder,
        bool $primary,
    ): int {
        $url = (string) $image['url'];

        if (isset($existing[$url])) {
            return 0;
        }

        CatalogueImage::query()->create([
            'imageable_type' => PdfCatalogueProduct::class,
            'imageable_id' => $product->id,
            'image_role' => $role,
            'storage_disk' => null,
            'storage_path' => null,
            'external_url' => $url,
            'original_filename' => (string) $image['image_file'],
            'mime_type' => 'image/png',
            'file_size' => filesize((string) $image['path']) ?: null,
            'sort_order' => $sortOrder,
            'is_primary' => $primary,
            'source_label' => $sourceLabel,
            'usage_context' => 'pdf_catalogue',
            'notes' => (string) ($image['source_note'] ?? ''),
        ]);

        $existing[$url] = true;

        return 1;
    }

    private function skuFromImageFilename(string $filename): string
    {
        $prefix = explode('__', pathinfo($filename, PATHINFO_FILENAME), 2)[0] ?? '';
        $prefix = preg_replace('/_(?:a|v)?\d+$/i', '', $prefix) ?? $prefix;

        return $this->normalizeSku($prefix);
    }

    private function normalizeSku(string $sku): string
    {
        return strtoupper(trim($sku));
    }
}
