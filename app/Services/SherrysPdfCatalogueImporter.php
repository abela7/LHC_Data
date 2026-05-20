<?php

namespace App\Services;

use App\Models\PdfCataloguePage;
use App\Models\PdfCatalogueProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class SherrysPdfCatalogueImporter
{
    public function defaultPath(): string
    {
        return public_path('SHERRYS CATALOGUE 2026 JAN .pdf');
    }

    /**
     * @return array{
     *   path: string,
     *   source_name: string,
     *   from_page: int,
     *   to_page: int,
     *   pages_imported: int,
     *   products_imported: int,
     *   needs_review: int,
     *   confidence_breakdown: array<string, int>
     * }
     */
    public function import(
        ?string $path = null,
        ?int $fromPage = null,
        ?int $toPage = null,
        bool $fresh = false,
    ): array {
        $path = $path !== null && trim($path) !== ''
            ? trim($path)
            : $this->defaultPath();

        if (! is_file($path)) {
            throw new RuntimeException("The PDF catalogue was not found at {$path}.");
        }

        $extracted = $this->extractPages($path, $fromPage, $toPage);

        return $this->importExtractedPages(
            pages: $extracted['pages'] ?? [],
            sourcePath: (string) ($extracted['source_path'] ?? $path),
            sourceName: (string) ($extracted['source_name'] ?? basename($path)),
            fresh: $fresh,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array{
     *   path: string,
     *   source_name: string,
     *   from_page: int,
     *   to_page: int,
     *   pages_imported: int,
     *   products_imported: int,
     *   needs_review: int,
     *   confidence_breakdown: array<string, int>
     * }
     */
    public function importExtractedPages(
        array $pages,
        string $sourcePath,
        ?string $sourceName = null,
        bool $fresh = false,
    ): array {
        $normalizedPages = array_values(array_filter(
            array_map(fn (mixed $page): ?array => is_array($page) ? $page : null, $pages),
            fn (?array $page): bool => $page !== null && isset($page['page_number']),
        ));

        if ($normalizedPages === []) {
            throw new RuntimeException('No PDF pages were extracted to import.');
        }

        $sourceName = $sourceName !== null && trim($sourceName) !== ''
            ? trim($sourceName)
            : basename($sourcePath);

        $pageNumbers = array_values(array_unique(array_map(
            fn (array $page): int => (int) $page['page_number'],
            $normalizedPages,
        )));
        sort($pageNumbers);

        $confidenceBreakdown = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $productsImported = 0;
        $needsReview = 0;
        $now = now();

        DB::transaction(function () use (
            $normalizedPages,
            $sourcePath,
            $sourceName,
            $fresh,
            $pageNumbers,
            &$confidenceBreakdown,
            &$productsImported,
            &$needsReview,
            $now,
        ): void {
            if ($fresh) {
                PdfCatalogueProduct::query()
                    ->where('source_path', $sourcePath)
                    ->whereIn('page_number', $pageNumbers)
                    ->delete();

                PdfCataloguePage::query()
                    ->where('source_path', $sourcePath)
                    ->whereIn('page_number', $pageNumbers)
                    ->delete();
            }

            foreach ($normalizedPages as $pagePayload) {
                $pageNumber = (int) $pagePayload['page_number'];
                $products = array_values(array_filter(
                    array_map(fn (mixed $product): ?array => is_array($product) ? $product : null, $pagePayload['products'] ?? []),
                    fn (?array $product): bool => $product !== null
                        && trim((string) ($product['product_code'] ?? '')) !== ''
                        && trim((string) ($product['product_name'] ?? '')) !== '',
                ));

                $pageNeedsReview = collect($products)->contains(
                    fn (array $product): bool => strtoupper((string) ($product['confidence'] ?? 'D')) === 'D'
                );

                $page = PdfCataloguePage::query()->updateOrCreate(
                    [
                        'source_path' => $sourcePath,
                        'page_number' => $pageNumber,
                    ],
                    [
                        'source_name' => $sourceName,
                        'header_text' => $this->nullableString($pagePayload['header_text'] ?? null),
                        'brand_context' => $this->nullableString($pagePayload['brand_context'] ?? null),
                        'brand_context_source' => $this->nullableString($pagePayload['brand_context_source'] ?? null),
                        'raw_text' => $this->nullableString($pagePayload['raw_text'] ?? null),
                        'products_count' => count($products),
                        'needs_review' => $pageNeedsReview,
                        'updated_at' => $now,
                    ] + ($fresh ? ['created_at' => $now] : []),
                );

                $page->products()->delete();

                $rows = [];

                foreach ($products as $index => $product) {
                    $confidence = strtoupper(trim((string) ($product['confidence'] ?? 'D')));
                    if (! isset($confidenceBreakdown[$confidence])) {
                        $confidence = 'D';
                    }

                    $confidenceBreakdown[$confidence]++;
                    $reviewNeeded = $confidence === 'D';

                    if ($reviewNeeded) {
                        $needsReview++;
                    }

                    $rows[] = [
                        'pdf_catalogue_page_id' => $page->id,
                        'source_name' => $sourceName,
                        'source_path' => $sourcePath,
                        'page_number' => $pageNumber,
                        'sort_order' => (int) ($product['sort_order'] ?? ($index + 1)),
                        'brand' => trim((string) ($product['brand'] ?? ($pagePayload['brand_context'] ?? 'Unknown'))),
                        'brand_source' => $this->nullableString($product['brand_source'] ?? ($pagePayload['brand_context_source'] ?? null)),
                        'product_code' => trim((string) ($product['product_code'] ?? '')),
                        'product_name' => trim((string) ($product['product_name'] ?? '')),
                        'confidence' => $confidence,
                        'confidence_reason' => $this->nullableString($product['confidence_reason'] ?? null),
                        'raw_name_text' => $this->nullableString($product['raw_name_text'] ?? null),
                        'needs_review' => $reviewNeeded,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    PdfCatalogueProduct::query()->insert($rows);
                    $productsImported += count($rows);
                }
            }
        });

        return [
            'path' => $sourcePath,
            'source_name' => $sourceName,
            'from_page' => (int) min($pageNumbers),
            'to_page' => (int) max($pageNumbers),
            'pages_imported' => count($normalizedPages),
            'products_imported' => $productsImported,
            'needs_review' => $needsReview,
            'confidence_breakdown' => $confidenceBreakdown,
        ];
    }

    /**
     * @return array{source_name: string, source_path: string, pages: array<int, array<string, mixed>>}
     */
    private function extractPages(string $path, ?int $fromPage, ?int $toPage): array
    {
        $scriptPath = base_path('scripts/extract_sherrys_catalogue.py');

        if (! is_file($scriptPath)) {
            throw new RuntimeException("The PDF extraction script was not found at {$scriptPath}.");
        }

        $process = new Process(array_values(array_filter([
            'python',
            $scriptPath,
            '--path',
            $path,
            $fromPage !== null ? '--from' : null,
            $fromPage !== null ? (string) $fromPage : null,
            $toPage !== null ? '--to' : null,
            $toPage !== null ? (string) $toPage : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '')));

        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException(
                'The PDF extractor failed'.($errorOutput !== '' ? ": {$errorOutput}" : '.')
            );
        }

        $output = (string) $process->getOutput();
        $output = preg_replace('/^\xEF\xBB\xBF/', '', $output) ?? $output;

        if (! mb_check_encoding($output, 'UTF-8')) {
            $output = mb_convert_encoding($output, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded) || ! isset($decoded['pages']) || ! is_array($decoded['pages'])) {
            throw new RuntimeException('The PDF extractor returned invalid JSON.');
        }

        return $decoded;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 65535, '');
    }
}
