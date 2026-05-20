<?php

namespace App\Http\Controllers;

use App\Models\CatalogueFamily;
use App\Models\CatalogueSource;
use App\Support\CatalogueOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogueSourceController extends Controller
{
    public function store(Request $request, CatalogueFamily $family): RedirectResponse
    {
        $validated = $this->validateSource($request);

        if (($validated['is_primary'] ?? false) === true) {
            $family->sources()->update(['is_primary' => false]);
        }

        $family->sources()->create($validated + [
            'verified_by' => ($validated['is_verified'] ?? false) ? $request->user()?->id : null,
            'verified_at' => ($validated['is_verified'] ?? false) ? now() : null,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('status', 'Source added.');
    }

    public function update(Request $request, CatalogueSource $source): RedirectResponse
    {
        $validated = $this->validateSource($request);

        if (($validated['is_primary'] ?? false) === true) {
            $source->sourceable->sources()->whereKeyNot($source->id)->update(['is_primary' => false]);
        }

        $source->update($validated + [
            'verified_by' => ($validated['is_verified'] ?? false) ? $request->user()?->id : null,
            'verified_at' => ($validated['is_verified'] ?? false) ? now() : null,
        ]);

        return back()->with('status', 'Source updated.');
    }

    public function destroy(CatalogueSource $source): RedirectResponse
    {
        $source->delete();

        return back()->with('status', 'Source removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSource(Request $request): array
    {
        return $request->validate([
            'role' => ['required', Rule::in(CatalogueOptions::sourceRoles())],
            'source_type' => ['required', Rule::in(CatalogueOptions::sourceTypes())],
            'trust_status' => ['required', Rule::in(CatalogueOptions::sourceTrustStatuses())],
            'url' => ['nullable', 'url'],
            'title' => ['nullable', 'string', 'max:255'],
            'confidence' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
            'is_primary' => ['nullable', 'boolean'],
            'is_verified' => ['nullable', 'boolean'],
        ]);
    }
}
