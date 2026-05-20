<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImageNamer
{
    public static function displayName(string $baseName, ?string $role): string
    {
        $baseName = self::cleanBaseName($baseName);
        $roleLabel = self::roleLabel($role);

        return trim($baseName.' - '.$roleLabel);
    }

    public static function roleLabel(?string $role): string
    {
        return match (Str::of((string) $role)->lower()->replace(' ', '_')->toString()) {
            'main' => 'Main photo',
            'style' => 'Style image',
            'hero' => 'Hero image',
            'variant' => 'Variant photo',
            'swatch' => 'Swatch',
            'gallery' => 'Gallery image',
            'detail' => 'Detail photo',
            'texture' => 'Texture photo',
            'packaging' => 'Packaging photo',
            'barcode' => 'Barcode photo',
            'back' => 'Back photo',
            'label_ingredients' => 'Label ingredients photo',
            'shelf_context' => 'Shelf context photo',
            'source' => 'Source image',
            'packaging_front' => 'Packaging front photo',
            'packaging_back' => 'Packaging back photo',
            'label_closeup' => 'Label close-up photo',
            'variant_evidence' => 'Variant evidence photo',
            'colour_evidence' => 'Colour evidence photo',
            'shelf_reference' => 'Shelf reference photo',
            'source_reference' => 'Source reference photo',
            'evidence' => 'Evidence photo',
            default => 'Product image',
        };
    }

    public static function catalogueDirectory(string $targetType, int $targetId, string $targetName): string
    {
        $folder = match ($targetType) {
            'brand_catalogue_style' => 'styles',
            'brand_catalogue_sku' => 'skus',
            'brand_catalogue_variant_option' => 'variant-options',
            'family' => 'legacy-families',
            'type' => 'legacy-types',
            'variant' => 'legacy-variants',
            'import_record' => 'import-records',
            default => 'other',
        };

        return 'catalogue-images/'.$folder.'/'.$targetId.'-'.self::slugSegment($targetName);
    }

    public static function retailDirectory(ProductFamily $family, ?Product $product = null): string
    {
        if ($product) {
            return 'retail-products/products/'.$product->id.'-'.self::slugSegment($product->name);
        }

        return 'retail-products/families/'.$family->id.'-'.self::slugSegment($family->family_name);
    }

    public static function uniqueFilename(string $directory, string $displayName, string $extension, string $disk = 'public'): string
    {
        $extension = self::cleanExtension($extension);
        $baseName = self::safeFilenameBase($displayName);
        $filename = $baseName.'.'.$extension;

        if (! Storage::disk($disk)->exists($directory.'/'.$filename)) {
            return $filename;
        }

        for ($i = 2; $i < 1000; $i++) {
            $candidate = $baseName.' '.$i.'.'.$extension;
            if (! Storage::disk($disk)->exists($directory.'/'.$candidate)) {
                return $candidate;
            }
        }

        return $baseName.' '.now()->format('YmdHis').'.'.$extension;
    }

    public static function filename(string $displayName, string $extension): string
    {
        return self::safeFilenameBase($displayName).'.'.self::cleanExtension($extension);
    }

    public static function extensionFromMime(?string $mimeType): string
    {
        $mimeType = strtolower((string) $mimeType);

        return match (true) {
            str_contains($mimeType, 'jpeg'), str_contains($mimeType, 'jpg') => 'jpg',
            str_contains($mimeType, 'png') => 'png',
            str_contains($mimeType, 'webp') => 'webp',
            str_contains($mimeType, 'gif') => 'gif',
            default => 'jpg',
        };
    }

    public static function extensionFromUrl(?string $url): string
    {
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
            ? ($extension === 'jpeg' ? 'jpg' : $extension)
            : 'jpg';
    }

    public static function cleanHumanText(string $value): string
    {
        $value = Str::ascii($value);
        $value = preg_replace('/\s+/', ' ', $value) ?: $value;

        return trim($value) ?: 'Product';
    }

    private static function cleanBaseName(string $value): string
    {
        $value = self::cleanHumanText($value);
        $value = preg_replace('/\s+-\s+/', ' ', $value) ?: $value;

        return trim($value) ?: 'Product';
    }

    private static function safeFilenameBase(string $value): string
    {
        $value = self::cleanHumanText($value);
        $value = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/', '', $value) ?: $value;
        $value = preg_replace('/\s+/', ' ', $value) ?: $value;
        $value = trim($value, " .\t\n\r\0\x0B");

        return Str::limit($value ?: 'Product image', 150, '');
    }

    private static function slugSegment(string $value): string
    {
        return Str::slug(Str::limit(self::cleanHumanText($value), 80, '')) ?: 'item';
    }

    private static function cleanExtension(string $extension): string
    {
        $extension = strtolower(trim($extension, ". \t\n\r\0\x0B"));

        return match ($extension) {
            'jpeg' => 'jpg',
            'jpg', 'png', 'webp', 'gif' => $extension,
            default => 'jpg',
        };
    }
}
