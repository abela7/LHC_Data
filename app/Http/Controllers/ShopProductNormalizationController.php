<?php

namespace App\Http\Controllers;

use App\Services\ShopProductSourceNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShopProductNormalizationController extends Controller
{
    public function index(Request $request, ShopProductSourceNormalizer $normalizer): View
    {
        $data = $normalizer->review([
            'search' => $request->string('search')->value(),
            'brand' => $request->string('brand')->value(),
            'source' => $request->string('source')->value(),
            'department' => $request->string('department')->value(),
            'confidence' => $request->string('confidence')->value(),
            'variant_state' => $request->string('variant_state')->value(),
            'issue' => $request->string('issue')->value(),
            'page' => $request->integer('page', 1),
            'per_page' => $request->integer('per_page', 250),
        ]);

        return view('shop-product-intake.normalization', $data);
    }

    public function storeFamily(Request $request, ShopProductSourceNormalizer $normalizer): RedirectResponse
    {
        $data = $request->validate([
            'candidate_key' => ['required', 'string', 'size:64'],
        ]);

        $family = $normalizer->createDraftFamily($data['candidate_key']);

        return redirect()
            ->route('retail-products.families.show', $family)
            ->with('status', "Draft family ready: {$family->family_name}.");
    }

    public function storeScratchFamily(Request $request, ShopProductSourceNormalizer $normalizer): RedirectResponse
    {
        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:255'],
            'department_name' => ['nullable', 'string', 'max:255'],
            'product_type_name' => ['nullable', 'string', 'max:255'],
            'family_name' => ['required', 'string', 'max:255'],
            'variant_axis_1' => ['nullable', 'string', 'max:80'],
            'variant_values_1' => ['nullable', 'string', 'max:4000'],
            'variant_axis_2' => ['nullable', 'string', 'max:80'],
            'variant_values_2' => ['nullable', 'string', 'max:4000'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);

        $family = $normalizer->createManualDraftFamily($data);

        return redirect()
            ->route('retail-products.families.show', $family)
            ->with('status', "Scratch draft family ready: {$family->family_name}.");
    }
}
