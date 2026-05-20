<?php

namespace App\Http\Controllers;

use App\Models\BrandCatalogueStyle;
use App\Services\RetailProductPublisher;
use Illuminate\Http\RedirectResponse;

class BrandCatalogueProductPublishController extends Controller
{
    public function publishStyle(BrandCatalogueStyle $style, RetailProductPublisher $publisher): RedirectResponse
    {
        $family = $publisher->publishBrandCatalogueStyle($style);
        $productCount = $family->products()->count();

        return redirect()
            ->route('retail-products.families.show', $family)
            ->with('status', "{$family->family_name} published to {$productCount} draft product record(s).");
    }
}
