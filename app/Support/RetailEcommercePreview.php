<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductVariantGroup;
use Illuminate\Support\Str;

/**
 * Builds the ecommerce "store preview" data array for a product family — the
 * same shape the family page builds inline for its Shop preview modal. Reused
 * by the public /shop storefront so the product page matches exactly.
 */
final class RetailEcommercePreview
{
    /**
     * @return array<string, mixed>
     */
    public static function forFamily(ProductFamily $family): array
    {
        $family->loadMissing([
            'ecommerceProfile',
            'media',
            'variantGroups.options',
            'products.variantValues.option',
            'products.variantValues.group',
            'products.media',
            'products.price',
            'products.inventoryLevels',
            'products.ecommerceProfile',
        ]);

        $products = $family->products;
        $familyOnline = $family->ecommerceProfile;

        $title = $familyOnline?->online_title ?: $family->display_family_name;
        $short = $familyOnline?->short_description
            ?: ($family->description ? Str::limit($family->description, 200) : null);
        $long = $familyOnline?->long_description ?: $family->description;

        $prices = $products
            ->map(fn (Product $p) => $p->price?->retail_price)
            ->filter(fn ($v) => $v !== null)
            ->map(fn ($v) => (float) $v);
        $priceMin = $prices->min();
        $priceMax = $prices->max();
        $distinctPrices = $prices->unique()->values();
        $sharedPrice = $distinctPrices->count() === 1 ? (float) $distinctPrices->first() : null;

        $mediaItem = static function ($media, string $fallbackAlt, string $fallbackLabel = 'Image'): ?array {
            $url = $media?->displayUrl();
            if (! $url) {
                return null;
            }

            return [
                'url' => $url,
                'alt' => $media->alt_text ?: $fallbackAlt,
                'label' => ucfirst(str_replace('_', ' ', (string) ($media->image_role ?: $fallbackLabel))),
            ];
        };

        $isColourGroup = static function (ProductVariantGroup $group): bool {
            $name = mb_strtolower((string) $group->name);
            $type = mb_strtolower((string) $group->variant_type);

            return str_contains($name, 'colour')
                || str_contains($name, 'color')
                || in_array($type, ['colour_name', 'colour_code'], true);
        };
        $colourGroup = $family->variantGroups->first($isColourGroup);
        $colourGroupId = $colourGroup?->id;

        $familyFallback = collect();
        foreach ($family->media as $media) {
            $item = $mediaItem($media, $title, 'Family');
            if ($item && ! $familyFallback->contains('url', $item['url'])) {
                $familyFallback->push($item);
            }
        }

        $skus = $products->map(function (Product $product) use ($mediaItem) {
            $ecommerceTitle = trim((string) ($product->ecommerceProfile?->online_title ?? ''));
            $imageAltName = $ecommerceTitle !== '' ? $ecommerceTitle : $product->name;

            $mainMedia = $product->media->firstWhere('image_role', 'main')
                ?? $product->media->first(fn ($m) => $m->is_primary && $m->image_role !== 'variant');
            $variantMedia = $product->media->firstWhere('image_role', 'variant');
            $galleryMedia = $product->media->where('image_role', 'gallery')->sortBy('sort_order')->values();
            $gallery = $galleryMedia->map(fn ($m) => $mediaItem($m, $imageAltName, 'Gallery'))->filter()->values()->all();

            $optionsByGroup = [];
            foreach ($product->variantValues as $value) {
                if ($value->product_variant_group_id) {
                    $optionsByGroup[(int) $value->product_variant_group_id] = (int) $value->product_variant_option_id;
                }
            }

            return [
                'id' => $product->id,
                'ecommerceTitle' => $ecommerceTitle !== '' ? $ecommerceTitle : null,
                'internalName' => $product->name,
                'shortDescription' => $product->ecommerceProfile?->short_description
                    ?: ($product->description ? Str::limit($product->description, 200) : null),
                'longDescription' => $product->ecommerceProfile?->long_description ?: $product->description,
                'price' => $product->price?->retail_price !== null ? (float) $product->price->retail_price : null,
                'optionIds' => collect($optionsByGroup)->values()->sort()->values()->all(),
                'optionsByGroup' => $optionsByGroup,
                'inStock' => $product->inventoryLevels->sum('stock_quantity') > 0,
                'media' => [
                    'main' => $mediaItem($mainMedia, $imageAltName, 'Main'),
                    'variant' => $mediaItem($variantMedia, $imageAltName, 'Variant'),
                    'gallery' => $gallery,
                ],
            ];
        })->values()->all();

        $swatches = collect();
        if ($colourGroup) {
            foreach ($colourGroup->options as $option) {
                $representative = collect($skus)->first(
                    fn (array $sku) => ($sku['optionsByGroup'][$colourGroup->id] ?? null) === (int) $option->id,
                );
                $swatchUrl = $representative['media']['variant']['url'] ?? $representative['media']['main']['url'] ?? null;
                $swatches->push([
                    'optionId' => (int) $option->id,
                    'label' => $option->label,
                    'swatchUrl' => $swatchUrl,
                ]);
            }
        }

        return [
            'familyId' => (int) $family->id,
            'familyManageUrl' => route('retail-products.families.show', $family),
            'title' => $title,
            'familyTitle' => $family->display_family_name,
            'titlePlaceholder' => 'Choose options to preview the ecommerce product title',
            'brand' => $family->brand_name,
            'line' => $family->line_name,
            'category' => $family->product_type_name ?: $family->root_catalogue_name,
            'shortDescription' => $short,
            'longDescription' => $long,
            'isPublished' => (bool) ($familyOnline?->is_published ?? false),
            'clickCollect' => $familyOnline?->click_and_collect_enabled !== false,
            'priceMin' => $priceMin,
            'priceMax' => $priceMax,
            'sharedPrice' => $sharedPrice,
            'colourGroupId' => $colourGroupId,
            'colourGroupName' => $colourGroup?->name,
            'swatches' => $swatches->values()->all(),
            'familyFallback' => $familyFallback->values()->all(),
            'variants' => $family->variantGroups
                ->reject(fn (ProductVariantGroup $group) => $colourGroupId && (int) $group->id === (int) $colourGroupId)
                ->map(fn (ProductVariantGroup $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'options' => $group->options->map(fn ($option) => [
                        'id' => $option->id,
                        'label' => $option->label,
                    ])->values()->all(),
                ])
                ->values()
                ->all(),
            'skus' => $skus,
        ];
    }
}
