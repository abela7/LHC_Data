<?php

namespace App\Http\Controllers;

use App\Models\BrandCatalogueStyle;
use App\Services\PinkCommerceBridge;
use App\Services\RetailProductPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class BrandCatalogueProductPublishController extends Controller
{
    public function publishStyle(BrandCatalogueStyle $style, RetailProductPublisher $publisher): RedirectResponse
    {
        $family = $publisher->publishBrandCatalogueStyle($style);

        // Mirror the published family to Pink-Commerce (Railway) + R2. Never let a
        // bridge failure break the local publish — it's best-effort and logged.
        try {
            app(PinkCommerceBridge::class)->pushFamily($family);
        } catch (Throwable $e) {
            Log::warning('PinkCommerce push failed (publish unaffected)', [
                'family_id' => $family->id,
                'error' => $e->getMessage(),
            ]);
        }

        $productCount = $family->products()->count();

        return redirect()
            ->route('retail-products.families.show', $family)
            ->with('status', "{$family->family_name} published to {$productCount} draft product record(s).");
    }
}
