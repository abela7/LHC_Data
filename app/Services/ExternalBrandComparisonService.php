<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\CatalogueAiEnrichment;
use App\Models\ObservedProduct;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ExternalBrandComparisonService
{
    /**
     * Obvious collection/category labels that are not brands on external brand pages.
     *
     * @var array<int, string>
     */
    private const GENERIC_LABELS = [
        'Accessories',
        'Air Fresheners',
        'All products',
        'Bath/Soaps',
        'Best Sellers',
        'Body Oils',
        'Braids/Plaiting Hair',
        'Bundle Deals',
        'Butter Creams',
        "Children's",
        'Cleansers & Exfoliators',
        'Clippers and Trimmers',
        'Co-Wash',
        'Conditioner',
        'Crochet Hair Extensions',
        'Curly Hair Products',
        'Edge Control',
        'Extensions',
        'Facial Cleansers',
        'Featured Products',
        'Gift Sets',
        'Hair Care',
        'Hair Color',
        'Hair Extensions',
        'Hair Food',
        'Hair Gel',
        'Hair Oils',
        'Hair Relaxer',
        'Hair Spray',
        'Hair Styling',
        'Hair Tools',
        'Kids Hair Care',
        'Leave In Conditioner',
        'Make Up',
        'Men',
        'Moisturisers',
        'New Arrivals',
        'Perfumes',
        'Sale',
        'Shampoo',
        'Skin Care',
        'Straighteners',
        'Treatments',
        'Wigs',
    ];

    /**
     * Common suffixes that are descriptive, not core brand identity.
     *
     * @var array<int, string>
     */
    private const ALIAS_SUFFIXES = [
        'hair color',
        'hair dye',
        'hair products',
        'hair product',
        'curl collection',
        'collection',
        'original',
        'paris',
        'uk',
    ];

    /**
     * @return array<string, mixed>
     */
    public function compare(string $url, ?string $label = null, ?string $outputDir = null): array
    {
        $label = $this->makeLabel($label ?: $this->hostLabelFromUrl($url));
        $outputDir = $outputDir ?: base_path('output/brand-comparison');

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $html = $this->fetchHtml($url);
        $htmlPath = $outputDir.DIRECTORY_SEPARATOR."{$label}-brands-page.html";
        file_put_contents($htmlPath, $html);

        $rawEntries = $this->extractEntries($html, $url);
        $candidateEntries = array_values(array_filter(
            $rawEntries,
            static fn (array $entry): bool => ($entry['is_brand_candidate'] ?? 'No') === 'Yes'
        ));

        $internalBrands = $this->loadInternalBrands();
        [$externalComparisonRows, $internalComparisonRows, $summary] = $this->buildComparison(
            $candidateEntries,
            $internalBrands,
            $label,
            $url,
            $htmlPath,
        );
        $summary['raw_entries'] = count($rawEntries);

        $rawPath = $outputDir.DIRECTORY_SEPARATOR."{$label}-brands-raw.csv";
        $candidatePath = $outputDir.DIRECTORY_SEPARATOR."{$label}-brand-candidates.csv";
        $internalPath = $outputDir.DIRECTORY_SEPARATOR.'internal-brand-list.csv';
        $comparisonPath = $outputDir.DIRECTORY_SEPARATOR."{$label}-vs-internal-brands.csv";
        $internalComparisonPath = $outputDir.DIRECTORY_SEPARATOR."internal-vs-{$label}-brands.csv";
        $summaryPath = $outputDir.DIRECTORY_SEPARATOR."{$label}-brand-summary.json";

        $this->writeCsv($rawPath, $rawEntries);
        $this->writeCsv($candidatePath, $candidateEntries);
        $this->writeCsv($internalPath, $internalBrands);
        $this->writeCsv($comparisonPath, $externalComparisonRows);
        $this->writeCsv($internalComparisonPath, $internalComparisonRows);
        file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'label' => $label,
            'url' => $url,
            'output_dir' => $outputDir,
            'html_path' => $htmlPath,
            'raw_path' => $rawPath,
            'candidate_path' => $candidatePath,
            'internal_path' => $internalPath,
            'comparison_path' => $comparisonPath,
            'internal_comparison_path' => $internalComparisonPath,
            'summary_path' => $summaryPath,
            'summary' => $summary,
        ];
    }

    private function fetchHtml(string $url): string
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
            'Accept-Language' => 'en-GB,en;q=0.9',
        ])->timeout(30)->retry(2, 500)->get($url);

        $response->throw();

        return $response->body();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractEntries(string $html, string $baseUrl): array
    {
        libxml_use_internal_errors(true);

        $document = new \DOMDocument();
        $document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $anchors = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' brand ')]/ancestor::a[1]");

        if ($anchors === false) {
            return [];
        }

        $entries = [];

        foreach ($anchors as $anchor) {
            if (! $anchor instanceof \DOMElement) {
                continue;
            }

            $brandNode = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' brand ')]", $anchor)?->item(0);
            $name = '';

            if ($brandNode instanceof \DOMElement) {
                $name = trim(html_entity_decode($brandNode->getAttribute('title') ?: $brandNode->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            if ($name === '') {
                continue;
            }

            $href = trim((string) $anchor->getAttribute('href'));
            $resolvedUrl = $this->resolveUrl($baseUrl, $href);
            $normalizedName = $this->normalizeName($name);
            $comparisonKey = $this->comparisonKey($name);
            $linkKind = $this->linkKind($resolvedUrl);
            [$isBrandCandidate, $filterReason] = $this->classifyEntry($name, $normalizedName, $linkKind);

            $dedupeKey = $normalizedName.'|'.$resolvedUrl;

            $entries[$dedupeKey] = [
                'brand_name' => $name,
                'brand_url' => $resolvedUrl,
                'normalized_name' => $normalizedName,
                'comparison_key' => $comparisonKey,
                'link_kind' => $linkKind,
                'is_brand_candidate' => $isBrandCandidate ? 'Yes' : 'No',
                'filter_reason' => $filterReason,
            ];
        }

        ksort($entries);

        return array_values($entries);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function classifyEntry(string $name, string $normalizedName, string $linkKind): array
    {
        static $genericMap = null;

        if ($genericMap === null) {
            $genericMap = [];

            foreach (self::GENERIC_LABELS as $genericLabel) {
                $genericMap[$this->normalizeName($genericLabel)] = true;
            }
        }

        if (isset($genericMap[$normalizedName])) {
            return [false, 'obvious collection/category label'];
        }

        if ($linkKind === 'vendor_query') {
            return [true, 'vendor query link'];
        }

        return [true, 'brand-like collection label'];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function loadInternalBrands(): array
    {
        $rows = [];

        $brandNames = Brand::query()
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->pluck('name')
            ->all();

        foreach ($brandNames as $brandName) {
            $this->pushInternalBrand($rows, $brandName, 'brands');
        }

        $observedBrandNames = ObservedProduct::query()
            ->whereNotNull('canonical_brand')
            ->where('canonical_brand', '!=', '')
            ->pluck('canonical_brand')
            ->all();

        foreach ($observedBrandNames as $brandName) {
            $this->pushInternalBrand($rows, $brandName, 'observed_products');
        }

        $enrichmentBrandNames = CatalogueAiEnrichment::query()
            ->whereNotNull('brand_name')
            ->where('brand_name', '!=', '')
            ->pluck('brand_name')
            ->all();

        foreach ($enrichmentBrandNames as $brandName) {
            $this->pushInternalBrand($rows, $brandName, 'catalogue_ai_enrichments');
        }

        $brands = array_values(array_map(function (array $row): array {
            sort($row['sources']);

            return [
                'brand_name' => $row['brand_name'],
                'normalized_name' => $row['normalized_name'],
                'comparison_key' => $row['comparison_key'],
                'sources' => implode(' | ', $row['sources']),
            ];
        }, $rows));

        usort($brands, static fn (array $left, array $right): int => strcmp($left['brand_name'], $right['brand_name']));

        return $brands;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     */
    private function pushInternalBrand(array &$rows, string $brandName, string $source): void
    {
        $brandName = trim($brandName);

        if ($brandName === '') {
            return;
        }

        $normalizedName = $this->normalizeName($brandName);

        if ($normalizedName === '') {
            return;
        }

        if (! isset($rows[$normalizedName])) {
            $rows[$normalizedName] = [
                'brand_name' => $brandName,
                'normalized_name' => $normalizedName,
                'comparison_key' => $this->comparisonKey($brandName),
                'sources' => [],
            ];
        } elseif (strlen($brandName) < strlen($rows[$normalizedName]['brand_name'])) {
            $rows[$normalizedName]['brand_name'] = $brandName;
        }

        $rows[$normalizedName]['sources'][$source] = $source;
    }

    /**
     * @param array<int, array<string, string>> $externalBrands
     * @param array<int, array<string, string>> $internalBrands
     * @return array{0: array<int, array<string, string>>, 1: array<int, array<string, string>>, 2: array<string, mixed>}
     */
    private function buildComparison(
        array $externalBrands,
        array $internalBrands,
        string $label,
        string $url,
        string $htmlPath,
    ): array {
        $internalExact = [];
        $internalAlias = [];

        foreach ($internalBrands as $brand) {
            $internalExact[$brand['normalized_name']][] = $brand;
            $internalAlias[$brand['comparison_key']][] = $brand;
        }

        $externalExact = [];
        $externalAlias = [];
        $externalComparisonRows = [];
        $matchedExternal = 0;
        $unmatchedExternal = 0;

        foreach ($externalBrands as $brand) {
            $externalExact[$brand['normalized_name']][] = $brand;
            $externalAlias[$brand['comparison_key']][] = $brand;

            [$matches, $matchMethod] = $this->findMatches(
                $brand['normalized_name'],
                $brand['comparison_key'],
                $internalExact,
                $internalAlias,
            );

            $matched = $matches !== [];

            if ($matched) {
                $matchedExternal++;
            } else {
                $unmatchedExternal++;
            }

            $externalComparisonRows[] = [
                'site_brand_name' => $brand['brand_name'],
                'site_brand_url' => $brand['brand_url'],
                'normalized_name' => $brand['normalized_name'],
                'comparison_key' => $brand['comparison_key'],
                'link_kind' => $brand['link_kind'],
                'matched_internal_brand' => $matched ? 'Yes' : 'No',
                'match_method' => $matchMethod,
                'matched_internal_brand_count' => (string) count($matches),
                'matched_internal_brands' => implode(' | ', array_column($matches, 'brand_name')),
                'matched_internal_sources' => implode(' | ', array_column($matches, 'sources')),
            ];
        }

        $internalComparisonRows = [];
        $matchedInternal = 0;
        $unmatchedInternal = 0;

        foreach ($internalBrands as $brand) {
            [$matches, $matchMethod] = $this->findMatches(
                $brand['normalized_name'],
                $brand['comparison_key'],
                $externalExact,
                $externalAlias,
            );

            $matched = $matches !== [];

            if ($matched) {
                $matchedInternal++;
            } else {
                $unmatchedInternal++;
            }

            $internalComparisonRows[] = [
                'internal_brand_name' => $brand['brand_name'],
                'normalized_name' => $brand['normalized_name'],
                'comparison_key' => $brand['comparison_key'],
                'sources' => $brand['sources'],
                'found_on_site' => $matched ? 'Yes' : 'No',
                'match_method' => $matchMethod,
                'matched_site_brand_count' => (string) count($matches),
                'matched_site_brands' => implode(' | ', array_column($matches, 'brand_name')),
                'matched_site_urls' => implode(' | ', array_column($matches, 'brand_url')),
            ];
        }

        $summary = [
            'site_label' => $label,
            'site_url' => $url,
            'saved_html_path' => $htmlPath,
            'brand_candidates' => count($externalBrands),
            'internal_brand_count' => count($internalBrands),
            'matched_external_brand_count' => $matchedExternal,
            'unmatched_external_brand_count' => $unmatchedExternal,
            'matched_internal_brand_count' => $matchedInternal,
            'unmatched_internal_brand_count' => $unmatchedInternal,
        ];

        return [$externalComparisonRows, $internalComparisonRows, $summary];
    }

    /**
     * @param array<string, array<int, array<string, string>>> $exactMap
     * @param array<string, array<int, array<string, string>>> $aliasMap
     * @return array{0: array<int, array<string, string>>, 1: string}
     */
    private function findMatches(
        string $normalizedName,
        string $comparisonKey,
        array $exactMap,
        array $aliasMap,
    ): array {
        if (isset($exactMap[$normalizedName])) {
            return [$exactMap[$normalizedName], 'exact'];
        }

        if ($comparisonKey !== '' && isset($aliasMap[$comparisonKey])) {
            return [$aliasMap[$comparisonKey], 'alias'];
        }

        return [[], 'none'];
    }

    private function normalizeName(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["’", "'"], '', $value);
        $value = preg_replace('/&/u', ' and ', $value) ?? $value;
        $value = Str::ascii($value);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return $value;
    }

    private function comparisonKey(string $value): string
    {
        $normalized = $this->normalizeName($value);

        foreach (self::ALIAS_SUFFIXES as $suffix) {
            $normalized = preg_replace('/\b'.preg_quote($this->normalizeName($suffix), '/').'$/', '', $normalized) ?? $normalized;
            $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
        }

        return $normalized;
    }

    private function linkKind(string $url): string
    {
        if (str_contains($url, '/collections/vendors?q=')) {
            return 'vendor_query';
        }

        if (str_contains($url, '/collections/')) {
            return 'collection';
        }

        return 'other';
    }

    private function resolveUrl(string $baseUrl, string $href): string
    {
        if ($href === '') {
            return $baseUrl;
        }

        if (Str::startsWith($href, ['http://', 'https://'])) {
            return $href;
        }

        $parts = parse_url($baseUrl);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $href;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        return $origin.'/'.ltrim($href, '/');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException("Unable to write CSV file: {$path}");
        }

        if ($rows === []) {
            fclose($handle);

            return;
        }

        fputcsv($handle, array_keys($rows[0]));

        foreach ($rows as $row) {
            fputcsv($handle, array_values($row));
        }

        fclose($handle);
    }

    private function hostLabelFromUrl(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '') {
            return 'external-site';
        }

        return str_replace('.co.uk', '', $host);
    }

    private function makeLabel(string $value): string
    {
        $label = Str::slug($value);

        return $label !== '' ? $label : 'external-site';
    }
}
