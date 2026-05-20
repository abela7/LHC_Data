<?php

namespace App\Services;

use App\Models\CatalogueAiEnrichment;
use App\Support\CatalogueAiCsvLocator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CatalogueAiEnrichmentImporter
{
    public function __construct(
        private readonly CatalogueAiCsvLocator $catalogueAiCsvLocator,
    ) {
    }

    /**
     * @return array{path: string, total_rows: int, created: int, updated: int, skipped_blank: int, skipped_invalid: int, invalid_rows: array<int, string>, needs_review: int}
     */
    public function import(?string $path = null, bool $dryRun = false): array
    {
        $path = $path !== null && trim($path) !== ''
            ? trim($path)
            : $this->catalogueAiCsvLocator->path();

        [$header, $rows] = $this->readCsv($path);
        $summary = [
            'path' => $path,
            'total_rows' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped_blank' => 0,
            'skipped_invalid' => 0,
            'invalid_rows' => [],
            'needs_review' => 0,
        ];

        $importRow = function (array $row) use (&$summary, $dryRun, $path): void {
            if ($this->isBlankRow($row)) {
                $summary['skipped_blank']++;

                return;
            }

            $summary['total_rows']++;
            $payload = $this->normalizeRow($row, $path);

            if ($payload === null) {
                $summary['skipped_invalid']++;
                $summary['invalid_rows'][] = 'Row '.($row['source_row_number'] ?? '?').' is missing product_id or product name.';

                return;
            }

            if ($payload['needs_review']) {
                $summary['needs_review']++;
            }

            if ($dryRun) {
                $exists = CatalogueAiEnrichment::query()
                    ->where('product_id', $payload['product_id'])
                    ->exists();

                if ($exists) {
                    $summary['updated']++;
                } else {
                    $summary['created']++;
                }

                return;
            }

            $record = CatalogueAiEnrichment::query()->updateOrCreate(
                ['product_id' => $payload['product_id']],
                $payload,
            );

            if ($record->wasRecentlyCreated) {
                $summary['created']++;
            } else {
                $summary['updated']++;
            }
        };

        if ($dryRun) {
            foreach ($rows as $row) {
                $importRow($row);
            }

            return $summary;
        }

        DB::transaction(function () use ($rows, $importRow): void {
            foreach ($rows as $row) {
                $importRow($row);
            }
        });

        return $summary;
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<string, string|int|null>>}
     */
    private function readCsv(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("The CSV file was not found at {$path}.");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("The CSV file at {$path} could not be opened.");
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            throw new RuntimeException("The CSV file at {$path} is empty.");
        }

        $header = array_map(
            fn ($value): string => $this->sanitizeCsvValue($value),
            $header,
        );
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
        $rows = [];
        $sourceRowNumber = 2;

        while (($csvRow = fgetcsv($handle)) !== false) {
            $csvRow = $csvRow === [null] ? [] : $csvRow;
            $row = ['source_row_number' => $sourceRowNumber];

            foreach ($header as $index => $column) {
                if ($column === '') {
                    continue;
                }

                $row[$column] = $this->sanitizeCsvValue($csvRow[$index] ?? '');
            }

            $rows[] = $row;
            $sourceRowNumber++;
        }

        fclose($handle);

        return [$header, $rows];
    }

    /**
     * @param  array<string, string|int|null>  $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row, string $sourceFile): ?array
    {
        $productId = trim((string) ($row['product_id'] ?? ''));
        $productName = trim((string) ($row['name'] ?? ''));

        if ($productId === '' || $productName === '') {
            return null;
        }

        $officialSite = $this->nullableValue($row['official_site'] ?? null);
        $confidence = $this->nullableValue($row['confidence'] ?? null);
        $needsReview = in_array(strtoupper((string) $confidence), ['C', 'D'], true)
            || strcasecmp((string) $officialSite, 'Unknown') === 0;

        $rawRow = Arr::except($row, ['source_row_number']);

        return [
            'source_row_number' => (int) ($row['source_row_number'] ?? 0),
            'source_file' => $sourceFile,
            'product_id' => $productId,
            'category_name' => $this->nullableValue($row['category'] ?? null),
            'product_name' => $productName,
            'brand_name' => $this->nullableValue($row['brand'] ?? null),
            'subcategory_name' => $this->nullableValue($row['subcategory'] ?? null),
            'has_variant' => $this->nullableValue($row['has_variant'] ?? null),
            'variant_types' => $this->nullableValue($row['variant_types'] ?? null),
            'has_product_type' => $this->nullableValue($row['has_product_type'] ?? null),
            'product_type_details' => $this->nullableValue($row['product_type_details'] ?? null),
            'has_bundle' => $this->nullableValue($row['has_bundle'] ?? null),
            'bundle_details' => $this->nullableValue($row['bundle_details'] ?? null),
            'official_site' => $officialSite,
            'official_site_url' => $this->nullableValue($row['official_site_url'] ?? null),
            'best_source_url' => $this->nullableValue($row['best_source_url'] ?? null),
            'confidence' => $confidence !== null ? strtoupper($confidence) : null,
            'confidence_reason' => $this->nullableValue($row['confidence_reason'] ?? null),
            'notes' => $this->nullableValue($row['notes'] ?? null),
            'processed' => $this->nullableValue($row['processed'] ?? null),
            'needs_review' => $needsReview,
            'row_hash' => hash('sha256', json_encode($rawRow, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $productId),
            'raw_row_json' => $rawRow,
            'synced_at' => now(),
        ];
    }

    /**
     * @param  array<string, string|int|null>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $key => $value) {
            if ($key === 'source_row_number') {
                continue;
            }

            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function nullableValue(mixed $value): ?string
    {
        $value = $this->sanitizeCsvValue($value);

        return $value === '' ? null : $value;
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
