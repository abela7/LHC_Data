<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CatalogueAiManualReviewRepository
{
    /**
     * @var array<int, string>
     */
    private const EDITABLE_COLUMNS = [
        'name',
        'brand',
        'subcategory',
        'has_variant',
        'variant_types',
        'has_product_type',
        'product_type_details',
        'has_bundle',
        'bundle_details',
        'official_site',
        'official_site_url',
        'best_source_url',
        'confidence',
        'confidence_reason',
        'notes',
        'processed',
    ];

    /**
     * @var array<int, string>|null
     */
    private ?array $header = null;

    /**
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $rows = null;

    /**
     * @var array<string, array<int, string>>|null
     */
    private ?array $pictureIndex = null;

    private ?bool $hasBom = null;

    public function __construct(
        private readonly CatalogueAiCsvLocator $catalogueAiCsvLocator,
        private readonly CatalogueAiInputExportBuilder $catalogueAiInputExportBuilder,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function flaggedRows(string $search = ''): array
    {
        $needle = $this->normalizeSearch($search);

        return array_values(array_filter(
            $this->allRows(),
            fn (array $row): bool => $row['is_flagged'] && $this->matchesSearch($row, $needle),
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allRows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        [$header, $baseRows, $hasBom] = $this->readCsv();
        $this->header = $header;
        $this->hasBom = $hasBom;

        $pictureIndex = $this->pictureIndex();
        $highlightMap = $this->highlightMap();
        $rows = [];

        foreach ($baseRows as $row) {
            $flagReasons = $this->flagReasons($row);
            $pictureIds = $pictureIndex[$row['product_id']] ?? [];

            $rows[] = array_merge($row, [
                'flag_reasons' => $flagReasons,
                'is_flagged' => $flagReasons !== [],
                'picture_ids' => $pictureIds,
                'primary_picture_id' => $pictureIds[0] ?? null,
                'highlight_red' => $highlightMap[(int) $row['sheet_row']] ?? false,
            ]);
        }

        $this->rows = $rows;

        return $this->rows;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateRow(int $sheetRow, array $attributes): void
    {
        [$header, $rows, $hasBom] = $this->readCsv();
        $targetIndex = null;

        foreach ($rows as $index => $row) {
            if ((int) $row['sheet_row'] === $sheetRow) {
                $targetIndex = $index;
                break;
            }
        }

        if ($targetIndex === null) {
            throw new RuntimeException("Row {$sheetRow} was not found in catalogue-ai-input.csv.");
        }

        foreach (self::EDITABLE_COLUMNS as $column) {
            if (! array_key_exists($column, $attributes) || ! in_array($column, $header, true)) {
                continue;
            }

            $rows[$targetIndex][$column] = $this->normalizeCell($attributes[$column]);
        }

        $this->writeCsv($header, $rows, $hasBom);
        $this->header = null;
        $this->rows = null;
        $this->hasBom = null;
    }

    public function updateHighlightState(int $sheetRow, string $productId, bool $highlightRed): void
    {
        if (! Schema::hasTable('catalogue_ai_review_states')) {
            throw new RuntimeException('The highlight table is missing. Run the latest migrations, then try again.');
        }

        DB::table('catalogue_ai_review_states')->updateOrInsert(
            ['sheet_row' => $sheetRow],
            [
                'product_id' => trim($productId),
                'highlight_red' => $highlightRed,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<string, mixed>>, 2: bool}
     */
    private function readCsv(): array
    {
        $path = $this->csvPath();

        if (! is_file($path)) {
            throw new RuntimeException("The review file was not found at {$path}.");
        }

        $bom = file_get_contents($path, false, null, 0, 3);
        $hasBom = $bom === "\xEF\xBB\xBF";

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The review file could not be opened for reading.');
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            throw new RuntimeException('The review file is empty.');
        }

        $header = array_map(
            fn ($value): string => $this->sanitizeCsvValue($value),
            $header,
        );
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];

        $rows = [];
        $sheetRow = 2;

        while (($csvRow = fgetcsv($handle)) !== false) {
            $csvRow = $csvRow === [null] ? [] : $csvRow;
            $row = ['sheet_row' => $sheetRow];

            foreach ($header as $index => $column) {
                $row[$column] = $this->sanitizeCsvValue($csvRow[$index] ?? '');
            }

            $rows[] = $row;
            $sheetRow++;
        }

        fclose($handle);

        return [$header, $rows, $hasBom];
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeCsv(array $header, array $rows, bool $hasBom): void
    {
        $path = $this->csvPath();
        $directory = dirname($path);
        $tempPath = $directory.DIRECTORY_SEPARATOR.'catalogue-ai-input.tmp.csv';
        $handle = fopen($tempPath, 'w');

        if ($handle === false) {
            throw new RuntimeException('A temporary review file could not be created.');
        }

        if ($hasBom) {
            fwrite($handle, "\xEF\xBB\xBF");
        }

        fputcsv($handle, $header);

        foreach ($rows as $row) {
            $values = [];

            foreach ($header as $column) {
                $values[] = (string) ($row[$column] ?? '');
            }

            fputcsv($handle, $values);
        }

        fclose($handle);

        if (is_file($path) && ! @unlink($path)) {
            @unlink($tempPath);

            throw new RuntimeException('The CSV could not be replaced. Close Excel or any app locking catalogue-ai-input.csv, then try again.');
        }

        if (! @rename($tempPath, $path)) {
            throw new RuntimeException("The CSV could not be saved. A temporary copy remains at {$tempPath}; close any app using catalogue-ai-input.csv, then try again.");
        }
    }

    private function csvPath(): string
    {
        return $this->catalogueAiCsvLocator->path();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function flagReasons(array $row): array
    {
        $reasons = [];
        $confidence = strtoupper(trim((string) ($row['confidence'] ?? '')));
        $officialSite = trim((string) ($row['official_site'] ?? ''));

        if (in_array($confidence, ['C', 'D'], true)) {
            $reasons[] = "Confidence {$confidence}";
        }

        if (strcasecmp($officialSite, 'Unknown') === 0) {
            $reasons[] = 'Official site unknown';
        }

        return $reasons;
    }

    private function normalizeSearch(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchesSearch(array $row, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        $haystacks = [
            (string) ($row['product_id'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['brand'] ?? ''),
            (string) ($row['category'] ?? ''),
            (string) ($row['sheet_row'] ?? ''),
        ];

        foreach ($haystacks as $haystack) {
            if (str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function pictureIndex(): array
    {
        if ($this->pictureIndex !== null) {
            return $this->pictureIndex;
        }

        $index = [];

        foreach ($this->catalogueAiInputExportBuilder->groupedRows() as $row) {
            $index[$row['product_id']] = $row['picture_ids'];
        }

        $this->pictureIndex = $index;

        return $this->pictureIndex;
    }

    /**
     * @return array<int, bool>
     */
    private function highlightMap(): array
    {
        if (! Schema::hasTable('catalogue_ai_review_states')) {
            return [];
        }

        return DB::table('catalogue_ai_review_states')
            ->pluck('highlight_red', 'sheet_row')
            ->map(fn (mixed $value): bool => (bool) $value)
            ->all();
    }

    private function normalizeCell(mixed $value): string
    {
        return trim((string) $value);
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
