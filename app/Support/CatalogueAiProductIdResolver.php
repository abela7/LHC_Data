<?php

namespace App\Support;

use App\Models\ObservedProduct;

class CatalogueAiProductIdResolver
{
    public function productIdForObservedProduct(ObservedProduct $product): ?string
    {
        return $this->productId(
            canonicalBrand: $product->canonical_brand,
            observedBrand: $product->brand,
            productName: $product->product_name,
        );
    }

    public function productId(?string $canonicalBrand, ?string $observedBrand, ?string $productName): ?string
    {
        $brand = $this->displayBrand($canonicalBrand, $observedBrand);
        $productName = trim((string) $productName);

        if ($productName === '') {
            return null;
        }

        $groupKey = $this->groupKey($brand, $productName);

        return 'PRD-'.strtoupper(substr(sha1($groupKey), 0, 12));
    }

    public function displayBrand(?string $canonicalBrand, ?string $observedBrand): string
    {
        $canonicalBrand = trim((string) $canonicalBrand);
        $observedBrand = trim((string) $observedBrand);

        return $canonicalBrand !== '' ? $canonicalBrand : $observedBrand;
    }

    public function groupKey(string $brand, string $productName): string
    {
        $normalizedBrand = preg_replace('/\s+/', ' ', mb_strtolower(trim($brand))) ?? '';
        $normalizedName = preg_replace('/\s+/', ' ', mb_strtolower(trim($productName))) ?? '';

        return $normalizedBrand.'||'.$normalizedName;
    }
}
