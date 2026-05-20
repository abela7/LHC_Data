<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\CatalogueFamily;
use App\Models\CatalogueVariant;
use App\Models\DuplicateCandidate;
use App\Models\ImportBatch;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $pendingStatuses = ['imported', 'identified', 'researching', 'matched', 'needs_review'];

        return view('dashboard', [
            'stats' => [
                'brands' => Brand::query()->count(),
                'families' => CatalogueFamily::query()->count(),
                'variants' => CatalogueVariant::query()->count(),
                'pending_review' => CatalogueFamily::query()->whereIn('status', $pendingStatuses)->count(),
                'approved' => CatalogueFamily::query()->where('status', 'approved')->count(),
                'unmatched' => CatalogueFamily::query()->whereDoesntHave('shopMatch')->count(),
                'needs_source_verification' => CatalogueFamily::query()->where('needs_source_verification', true)->count(),
                'duplicate_candidates' => DuplicateCandidate::query()->where('status', 'open')->count(),
            ],
            'recentFamilies' => CatalogueFamily::query()
                ->with(['brand', 'category', 'shopMatch'])
                ->latest()
                ->limit(8)
                ->get(),
            'recentBatches' => ImportBatch::query()
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }
}
