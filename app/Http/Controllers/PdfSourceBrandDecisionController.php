<?php

namespace App\Http\Controllers;

use App\Models\PdfSourceBrandDecision;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class PdfSourceBrandDecisionController extends Controller
{
    private const JANSON_SOURCE = "JANSON PRODUCT LIST Dec'25.pdf";

    public function index(Request $request): View
    {
        $source = trim((string) $request->string('source')->value());
        $source = $source !== '' ? $source : self::JANSON_SOURCE;
        $search = trim((string) $request->string('search')->value());

        $this->seedSourceBrands($source);

        $brandQuery = PdfSourceBrandDecision::query()
            ->where('source_name', $source)
            ->when($search !== '', fn ($query) => $query->where('brand_name', 'like', '%'.$search.'%'))
            ->orderBy('brand_name');

        $brands = $brandQuery->get();

        return view('pdf-products.brand-decisions', [
            'source' => $source,
            'search' => $search,
            'activeBrands' => $brands->where('is_excluded', false)->values(),
            'unimportantBrands' => $brands->where('is_excluded', true)->values(),
            'stats' => [
                'total' => PdfSourceBrandDecision::query()->where('source_name', $source)->count(),
                'active' => PdfSourceBrandDecision::query()->where('source_name', $source)->where('is_excluded', false)->count(),
                'excluded' => PdfSourceBrandDecision::query()->where('source_name', $source)->where('is_excluded', true)->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:255'],
            'selected_ids' => ['array'],
            'selected_ids.*' => ['integer'],
            'action' => ['required', 'in:remove,restore'],
        ]);

        $source = trim((string) $validated['source']);
        $selectedIds = collect($validated['selected_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
        $action = (string) $validated['action'];

        if ($selectedIds === []) {
            return redirect()
                ->route('pdf-brand-decisions.index', ['source' => $source])
                ->with('status', 'No brands were selected.');
        }

        PdfSourceBrandDecision::query()
            ->where('source_name', $source)
            ->whereIn('id', $selectedIds)
            ->update([
                'is_excluded' => $action === 'remove',
            ]);

        return redirect()
            ->route('pdf-brand-decisions.index', ['source' => $source])
            ->with('status', $action === 'remove'
                ? 'Selected brands moved to Unimportant.'
                : 'Selected brands restored to Active.');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:255'],
            'brand_name' => ['required', 'string', 'max:255'],
        ]);

        $source = trim((string) $validated['source']);
        $brandName = trim((string) $validated['brand_name']);

        $nextSortOrder = (int) PdfSourceBrandDecision::query()
            ->where('source_name', $source)
            ->max('sort_order');

        PdfSourceBrandDecision::query()->firstOrCreate(
            [
                'source_name' => $source,
                'brand_name' => $brandName,
            ],
            [
                'is_excluded' => false,
                'sort_order' => $nextSortOrder + 10,
                'notes' => 'Manually added during brand review.',
            ]
        );

        return redirect()
            ->route('pdf-brand-decisions.index', ['source' => $source])
            ->with('status', "Added {$brandName}.");
    }

    private function seedSourceBrands(string $source): void
    {
        if ($source !== self::JANSON_SOURCE) {
            return;
        }

        $brands = $this->detectedJansonBrands();

        PdfSourceBrandDecision::query()
            ->where('source_name', $source)
            ->where(function ($query): void {
                $query->whereNull('notes')
                    ->orWhere('notes', '!=', 'Manually added during brand review.');
            })
            ->whereNotIn('brand_name', $brands)
            ->delete();

        collect($brands)
            ->values()
            ->each(function (string $brand, int $index) use ($source): void {
                PdfSourceBrandDecision::query()->firstOrCreate(
                    [
                        'source_name' => $source,
                        'brand_name' => $brand,
                    ],
                    [
                        'is_excluded' => false,
                        'sort_order' => ($index + 1) * 10,
                        'notes' => 'Seeded from Janson Products.txt line scan.',
                    ]
                );
            });
    }

    private function detectedJansonBrands(): array
    {
        $textPath = public_path('Products.txt');

        if (! File::exists($textPath)) {
            return [];
        }

        $lines = collect(preg_split("/\\r\\n|\\n|\\r/", (string) File::get($textPath)) ?: [])
            ->map(fn (string $line) => trim((string) preg_replace('/\s+/', ' ', $line)))
            ->filter();

        $hasProductCode = static function (string $line): bool {
            return preg_match('/\b[A-Z]{2,}\d{2,}[A-Z]?\b/', $line) === 1;
        };

        $isBoilerplate = static function (string $line): bool {
            return preg_match('/^(Some Products For Sale|Enquiry Within\b|Quantity In PCS ONLY$|DECEMBER 2025$|NEW IN STOCK NEW IN STOCK$|Price QTY\b)/i', $line) === 1;
        };

        return $lines
            ->reject($hasProductCode)
            ->reject($isBoilerplate)
            ->unique(fn (string $line) => mb_strtoupper($line))
            ->sort()
            ->values()
            ->all();
    }
}
