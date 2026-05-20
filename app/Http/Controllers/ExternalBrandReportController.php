<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\ObservedProduct;
use App\Models\ObservedBrandMapping;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ExternalBrandReportController extends Controller
{
    public function show(Request $request, ?string $label = null): View
    {
        $reports = $this->discoverReports();

        abort_if($reports === [], 404, 'No external brand reports found.');

        $label = $label ?: (array_key_exists('beautizone', $reports) ? 'beautizone' : array_key_first($reports));

        abort_unless(isset($reports[$label]), 404, 'External brand report not found.');

        $report = $reports[$label];
        $summary = $report['summary'];
        $search = trim((string) $request->input('search', ''));

        $savedBrands = $this->savedBrandIndex();
        $realBrandIndex = $this->realBrandIndex();

        $brands = collect($this->readCsv($report['comparison_path']))
            ->map(function (array $row): array {
                return [
                    'brand_name' => $row['site_brand_name'] ?? '',
                    'brand_url' => $row['site_brand_url'] ?? '',
                    'match_status' => $row['matched_internal_brand'] ?? 'No',
                    'match_method' => $row['match_method'] ?? 'none',
                    'matched_internal_brands' => $row['matched_internal_brands'] ?? '',
                    'matched_internal_brand_count' => (int) ($row['matched_internal_brand_count'] ?? 0),
                    'link_kind' => $row['link_kind'] ?? '',
                ];
            })
            ->filter(fn (array $row): bool => $row['brand_name'] !== '')
            ->map(function (array $row) use ($savedBrands, $realBrandIndex): array {
                $savedBrand = $savedBrands[$this->brandLookupKey($row['brand_name'])] ?? null;
                $hasRealBrandEntry = isset($realBrandIndex[$this->brandLookupKey($row['brand_name'])]);

                $row['saved_in_db'] = $savedBrand !== null;
                $row['saved_brand_name'] = $savedBrand['name'] ?? null;
                $row['has_real_brand_entry'] = $hasRealBrandEntry;

                return $row;
            })
            ->sortBy('brand_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        if ($search !== '') {
            $searchLower = mb_strtolower($search);

            $brands = $brands
                ->filter(function (array $row) use ($searchLower): bool {
                    return str_contains(mb_strtolower($row['brand_name']), $searchLower)
                        || str_contains(mb_strtolower($row['matched_internal_brands']), $searchLower);
                })
                ->values();
        }

        return view('external-brands.show', [
            'reports' => $reports,
            'activeLabel' => $label,
            'summary' => $summary,
            'brands' => $brands,
            'search' => $search,
            'savedCount' => $brands->where('saved_in_db', true)->count(),
        ]);
    }

    public function storeBrand(Request $request, string $label): RedirectResponse
    {
        $reports = $this->discoverReports();

        abort_unless(isset($reports[$label]), 404, 'External brand report not found.');

        $validated = $request->validate([
            'brand_name' => ['required', 'string', 'max:255'],
            'brand_url' => ['required', 'url', 'max:2000'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $brandName = trim($validated['brand_name']);
        $brandUrl = trim($validated['brand_url']);

        $reportRows = collect($this->readCsv($reports[$label]['comparison_path']));
        $matchedReportRow = $reportRows->first(function (array $row) use ($brandName, $brandUrl): bool {
            return trim((string) ($row['site_brand_name'] ?? '')) === $brandName
                && trim((string) ($row['site_brand_url'] ?? '')) === $brandUrl;
        });

        abort_if($matchedReportRow === null, 422, 'That brand is not present in the saved external report.');

        $brand = Brand::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($brandName)])
            ->first();

        $brandCreated = false;

        if ($brand === null) {
            $brand = Brand::query()->create([
                'name' => $brandName,
                'slug' => $this->generateUniqueBrandSlug($brandName),
                'notes' => "Saved from external brand report: {$label}.\nSource: {$brandUrl}",
                'is_active' => true,
                'is_generic' => false,
            ]);

            $brandCreated = true;
        }

        $mapping = ObservedBrandMapping::query()
            ->where('canonical_brand', $brandName)
            ->orWhere('observed_brand', $brandName)
            ->first();

        if ($mapping === null) {
            ObservedBrandMapping::query()->create([
                'observed_brand' => $brandName,
                'canonical_brand' => $brandName,
                'brand_line' => null,
                'official_source_url' => $brandUrl,
                'notes' => "Saved from external brand report: {$label}.",
            ]);
        } elseif (trim((string) $mapping->official_source_url) === '') {
            $mapping->update([
                'official_source_url' => $brandUrl,
            ]);
        }

        $status = $brandCreated
            ? "Saved {$brandName} to the brand list."
            : "{$brandName} was already saved in the brand list.";

        return redirect()
            ->route('external-brands.show', array_filter([
                'label' => $label,
                'search' => trim((string) ($validated['search'] ?? '')) ?: null,
            ]))
            ->with('status', $status);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function discoverReports(): array
    {
        $reports = [];

        foreach (glob(base_path('output/brand-comparison/*-brand-summary.json')) ?: [] as $summaryPath) {
            $summary = json_decode((string) file_get_contents($summaryPath), true);

            if (! is_array($summary)) {
                continue;
            }

            $label = (string) ($summary['site_label'] ?? '');

            if ($label === '') {
                continue;
            }

            $comparisonPath = base_path("output/brand-comparison/{$label}-vs-internal-brands.csv");

            if (! is_file($comparisonPath)) {
                continue;
            }

            $reports[$label] = [
                'label' => $label,
                'summary' => $summary,
                'comparison_path' => $comparisonPath,
            ];
        }

        ksort($reports);

        return $reports;
    }

    /**
     * @return array<string, array{name: string}>
     */
    private function savedBrandIndex(): array
    {
        $savedBrands = [];

        foreach (Brand::query()->orderBy('name')->get(['name']) as $brand) {
            $name = trim((string) $brand->name);

            if ($name === '') {
                continue;
            }

            $savedBrands[$this->brandLookupKey($name)] = [
                'name' => $name,
            ];
        }

        return $savedBrands;
    }

    /**
     * @return array<string, true>
     */
    private function realBrandIndex(): array
    {
        $brandKeys = [];

        foreach (ObservedProduct::query()->where('canonical_brand', '!=', '')->pluck('canonical_brand') as $brandName) {
            $brandName = trim((string) $brandName);

            if ($brandName !== '') {
                $brandKeys[$this->brandLookupKey($brandName)] = true;
            }
        }

        foreach (ObservedBrandMapping::query()->where('canonical_brand', '!=', '')->pluck('canonical_brand') as $brandName) {
            $brandName = trim((string) $brandName);

            if ($brandName !== '') {
                $brandKeys[$this->brandLookupKey($brandName)] = true;
            }
        }

        return $brandKeys;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) !== count($header)) {
                continue;
            }

            $rows[] = array_combine($header, $data);
        }

        fclose($handle);

        return $rows;
    }

    private function brandLookupKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function generateUniqueBrandSlug(string $brandName): string
    {
        $baseSlug = Str::slug($brandName);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'brand';
        $slug = $baseSlug;
        $suffix = 2;

        while (Brand::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
