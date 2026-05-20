<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\CatalogueFamily;
use App\Models\DuplicateCandidate;
use App\Models\ImportRecord;
use Illuminate\Contracts\View\View;

class ReviewQueueController extends Controller
{
    public function index(): View
    {
        return view('review.index', [
            'families' => CatalogueFamily::query()
                ->with(['brand', 'category', 'shopMatch'])
                ->whereIn('status', ['imported', 'identified', 'researching', 'matched', 'needs_review'])
                ->latest()
                ->paginate(15, ['*'], 'families'),
            'warningImports' => ImportRecord::query()
                ->with('targetFamily')
                ->where('status', 'parsed_with_warnings')
                ->latest()
                ->limit(10)
                ->get(),
            'duplicateCandidates' => DuplicateCandidate::query()
                ->with(['leftFamily.brand', 'rightFamily.brand'])
                ->where('status', 'open')
                ->latest()
                ->limit(10)
                ->get(),
            'brands' => Brand::query()
                ->withCount('families')
                ->orderByDesc('families_count')
                ->orderBy('name')
                ->limit(20)
                ->get(),
        ]);
    }
}
