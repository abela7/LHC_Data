<?php

namespace App\Http\Controllers;

use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariantOption;
use App\Services\PinkCommerceBridge;
use App\Services\RetailProductPublisher;
use App\Support\RetailStyleFamilyCatalogue;
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

    /**
     * Publish (or refresh) the sellable family for one catalogue variant option
     * (e.g. Length 20") on this style.
     */
    public function publishRetailForVariantOption(
        BrandCatalogueVariantOption $option,
        RetailProductPublisher $publisher,
    ): RedirectResponse {
        $option->loadMissing('variant.style.productType.line', 'variant.style.brand.catalogue');
        $variant = $option->variant;
        $style = $variant?->style;

        if (! $style instanceof BrandCatalogueStyle) {
            abort(404);
        }

        $skuIds = RetailStyleFamilyCatalogue::catalogueSkuIdsForOption($style, $option);
        $scopeKey = RetailStyleFamilyCatalogue::splitScopeKey($variant->name, $option->label);

        $family = $publisher->publishBrandCatalogueStyle(
            $style,
            $skuIds !== [] ? $skuIds : null,
            $scopeKey,
            $option->label,
        );

        try {
            app(PinkCommerceBridge::class)->pushFamily($family);
        } catch (Throwable $e) {
            Log::warning('PinkCommerce push failed (scoped publish unaffected)', [
                'family_id' => $family->id,
                'error' => $e->getMessage(),
            ]);
        }

        $productCount = $family->products()->count();
        $catalogue = $style->brand?->catalogue;
        $line = $style->productType?->line;
        $productType = $style->productType;

        $redirectUrl = $catalogue && $line && $productType
            ? route('brand-catalogue.styles.show', [
                $catalogue,
                $style->brand,
                $line,
                $productType,
                $style,
            ]).'?catalogue=1#vg-'.$variant->id
            : route('retail-products.families.show', $family);

        return redirect()
            ->to($redirectUrl)
            ->with(
                'status',
                "Sellable family for {$option->label} is ready ({$productCount} SKU"
                .($productCount === 1 ? '' : 's').').',
            );
    }
}
