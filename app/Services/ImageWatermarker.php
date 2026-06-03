<?php

namespace App\Services;

use App\Models\WatermarkSetting;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImageWatermarker
{
    /**
     * Apply the current watermark settings to a file stored on the public disk.
     */
    public function applyToPublicStoragePath(string $storagePath): bool
    {
        $settings = WatermarkSetting::current();
        $shouldApplyText = $settings->is_enabled
            && $settings->text_enabled
            && trim((string) $settings->text) !== ''
            && (int) $settings->opacity > 0;
        $shouldApplyLogo = $settings->is_enabled
            && $settings->logo_enabled
            && (int) $settings->logo_opacity > 0
            && is_string($settings->logo_path)
            && trim($settings->logo_path) !== '';

        $disk = Storage::disk('public');
        if (! $disk->exists($storagePath)) {
            return false;
        }

        $absolutePath = $disk->path($storagePath);
        $imageInfo = @getimagesize($absolutePath);
        if (! $imageInfo) {
            return false;
        }

        [$width, $height] = $imageInfo;
        $mimeType = strtolower((string) ($imageInfo['mime'] ?? ''));
        $image = $this->createImage($absolutePath, $mimeType);

        if (! $image) {
            return false;
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        [$image, $orientationChanged] = $this->normalizeExifOrientation($image, $absolutePath, $mimeType);
        $width = imagesx($image);
        $height = imagesy($image);

        if (! $shouldApplyText && ! $shouldApplyLogo) {
            $saved = $orientationChanged ? $this->saveImage($image, $absolutePath, $mimeType) : false;
            imagedestroy($image);

            return $saved;
        }

        $changed = false;

        if ($shouldApplyLogo) {
            $logo = $this->buildLogoLayer($settings, $width, $height);
            if (! $logo) {
                imagedestroy($image);

                throw new RuntimeException('The configured watermark logo could not be loaded.');
            }

            $logo = $this->rotateLayer($logo, max(-45, min(45, (int) ($settings->logo_rotation_degrees ?? 0))));
            if (! $logo) {
                imagedestroy($image);

                throw new RuntimeException('The configured watermark logo could not be rotated.');
            }

            $logoWidth = imagesx($logo);
            $logoHeight = imagesy($logo);
            [$x, $y] = $this->coordinatesFrom(
                (string) ($settings->logo_position ?: 'bottom-left'),
                (int) ($settings->logo_margin_percent ?? 4),
                $width,
                $height,
                $logoWidth,
                $logoHeight
            );

            $this->copyLayerOntoImage($image, $logo, $x, $y);
            imagedestroy($logo);
            $changed = true;
        }

        if ($shouldApplyText) {
            $watermark = $this->buildWatermarkLayer($settings, $width, $height);
            if ($watermark) {
                $watermark = $this->rotateLayer($watermark, max(-45, min(45, (int) ($settings->rotation_degrees ?? 0))));
                if (! $watermark) {
                    imagedestroy($image);

                    return false;
                }

                $watermarkWidth = imagesx($watermark);
                $watermarkHeight = imagesy($watermark);
                [$x, $y] = $this->coordinates($settings, $width, $height, $watermarkWidth, $watermarkHeight);

                $this->copyLayerOntoImage($image, $watermark, $x, $y);
                imagedestroy($watermark);
                $changed = true;
            }
        }

        if (! $changed && ! $orientationChanged) {
            imagedestroy($image);

            return false;
        }

        $saved = $this->saveImage($image, $absolutePath, $mimeType);
        imagedestroy($image);

        return $saved;
    }

    private function buildLogoLayer(WatermarkSetting $settings, int $imageWidth, int $imageHeight): mixed
    {
        $logoPath = (string) $settings->logo_path;
        $disk = Storage::disk('public');

        if (! $disk->exists($logoPath)) {
            return false;
        }

        $absolutePath = $disk->path($logoPath);
        $imageInfo = @getimagesize($absolutePath);
        if (! $imageInfo) {
            return false;
        }

        $mimeType = strtolower((string) ($imageInfo['mime'] ?? ''));
        $logo = $this->createImage($absolutePath, $mimeType);
        if (! $logo) {
            return false;
        }

        imagealphablending($logo, true);
        imagesavealpha($logo, true);

        $sourceWidth = imagesx($logo);
        $sourceHeight = imagesy($logo);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            imagedestroy($logo);

            return false;
        }

        $sizePercent = max(4, min(60, (int) ($settings->logo_size_percent ?? 18)));
        $maxSide = max(16, (int) round(min($imageWidth, $imageHeight) * ($sizePercent / 100)));
        $scale = min($maxSide / $sourceWidth, $maxSide / $sourceHeight);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));

        $canvas = $this->transparentCanvas($targetWidth, $targetHeight);
        imagecopyresampled($canvas, $logo, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        imagedestroy($logo);

        $this->applyLayerOpacity($canvas, (int) ($settings->logo_opacity ?? 45));

        return $canvas;
    }

    private function buildWatermarkLayer(WatermarkSetting $settings, int $imageWidth, int $imageHeight): mixed
    {
        $text = trim($settings->text);
        $fontPath = $this->fontPath($settings->font_family);
        $maxWidth = max(32, (int) round($imageWidth * (max(20, min(100, (int) ($settings->max_width_percent ?? 90))) / 100)));
        $padding = max(0, (int) round(min($imageWidth, $imageHeight) * (max(0, min(8, (int) ($settings->background_padding_percent ?? 2))) / 100)));

        if ($fontPath !== null && function_exists('imagettftext')) {
            return $this->buildTrueTypeLayer($settings, $text, $fontPath, $imageWidth, $imageHeight, $maxWidth, $padding);
        }

        return $this->buildScaledBitmapTextLayer($settings, $text, $imageWidth, $imageHeight, $maxWidth, $padding);
    }

    private function buildTrueTypeLayer(WatermarkSetting $settings, string $text, string $fontPath, int $imageWidth, int $imageHeight, int $maxWidth, int $padding): mixed
    {
        $sizePercent = max(2, min(16, (int) ($settings->text_size_percent ?? 6)));
        $fontSize = max(8, min(160, (int) round(min($imageWidth, $imageHeight) * ($sizePercent / 100))));
        $textMaxWidth = max(24, $maxWidth - ($padding * 2));
        $layoutMode = $settings->layout_mode === 'wrap' ? 'wrap' : 'fit';

        do {
            $lines = $layoutMode === 'wrap'
                ? $this->wrapText($text, $fontPath, $fontSize, $textMaxWidth)
                : [$text];

            [$textWidth, $textHeight, $lineHeight] = $this->measureTrueTypeLines($lines, $fontPath, $fontSize);

            if ($textWidth <= $textMaxWidth || $fontSize <= 8) {
                break;
            }

            $fontSize--;
        } while (true);

        $shadowOffset = $this->shadowOpacity($settings) > 0 ? max(1, (int) round($fontSize * 0.07)) : 0;
        $canvasWidth = max(1, $textWidth + ($padding * 2) + $shadowOffset);
        $canvasHeight = max(1, $textHeight + ($padding * 2) + $shadowOffset);
        $canvas = $this->transparentCanvas($canvasWidth, $canvasHeight);

        $this->drawBackgroundPlate($canvas, $settings, $canvasWidth, $canvasHeight);

        [$red, $green, $blue] = $this->hexToRgb($settings->text_color);
        $textColor = imagecolorallocatealpha($canvas, $red, $green, $blue, $this->alpha((int) $settings->opacity));
        $shadowColor = imagecolorallocatealpha($canvas, 0, 0, 0, $this->alpha($this->shadowOpacity($settings)));

        foreach ($lines as $index => $line) {
            [$lineWidth] = $this->measureTrueTypeLines([$line], $fontPath, $fontSize);
            $x = $padding + max(0, (int) round(($textWidth - $lineWidth) / 2));
            $baseline = $padding + $fontSize + ($index * $lineHeight);

            if ($shadowOffset > 0) {
                imagettftext($canvas, $fontSize, 0, $x + $shadowOffset, $baseline + $shadowOffset, $shadowColor, $fontPath, $line);
            }

            imagettftext($canvas, $fontSize, 0, $x, $baseline, $textColor, $fontPath, $line);
        }

        return $canvas;
    }

    /**
     * Fallback when TrueType fonts are unavailable — scale bitmap text to match text_size_percent.
     */
    private function buildScaledBitmapTextLayer(
        WatermarkSetting $settings,
        string $text,
        int $imageWidth,
        int $imageHeight,
        int $maxWidth,
        int $padding,
    ): mixed {
        $font = 5;
        $sizePercent = max(2, min(16, (int) ($settings->text_size_percent ?? 6)));
        $targetFontSize = max(12, min(160, (int) round(min($imageWidth, $imageHeight) * ($sizePercent / 100))));
        $scale = max(1, (int) round($targetFontSize / max(1, imagefontheight($font))));

        $baseWidth = min($maxWidth, imagefontwidth($font) * strlen($text));
        $baseHeight = imagefontheight($font);
        $shadowOffset = $this->shadowOpacity($settings) > 0 ? max(1, (int) round($scale * 0.15)) : 0;
        $canvasWidth = ($baseWidth * $scale) + ($padding * 2) + $shadowOffset;
        $canvasHeight = ($baseHeight * $scale) + ($padding * 2) + $shadowOffset;
        $canvas = $this->transparentCanvas($canvasWidth, $canvasHeight);

        $this->drawBackgroundPlate($canvas, $settings, $canvasWidth, $canvasHeight);

        $scratch = $this->transparentCanvas($baseWidth, $baseHeight);
        [$red, $green, $blue] = $this->hexToRgb($settings->text_color);
        $textColor = imagecolorallocatealpha($scratch, $red, $green, $blue, $this->alpha((int) $settings->opacity));
        $shadowColor = imagecolorallocatealpha($scratch, 0, 0, 0, $this->alpha($this->shadowOpacity($settings)));

        if ($shadowOffset > 0) {
            imagestring($scratch, $font, 1, 1, $text, $shadowColor);
        }

        imagestring($scratch, $font, 0, 0, $text, $textColor);
        imagecopyresampled(
            $canvas,
            $scratch,
            $padding,
            $padding,
            0,
            0,
            $baseWidth * $scale,
            $baseHeight * $scale,
            $baseWidth,
            $baseHeight,
        );
        imagedestroy($scratch);

        return $canvas;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function measureTrueTypeLines(array $lines, string $fontPath, int $fontSize): array
    {
        $maxWidth = 0;
        $lineHeight = max(10, (int) round($fontSize * 1.25));

        foreach ($lines as $line) {
            $box = imagettfbbox($fontSize, 0, $fontPath, $line);
            $maxWidth = max($maxWidth, $box ? (int) abs($box[2] - $box[0]) : 0);
        }

        $height = max($fontSize, ($lineHeight * max(1, count($lines))) - max(0, $lineHeight - $fontSize));

        return [$maxWidth, $height, $lineHeight];
    }

    /**
     * @return array<int, string>
     */
    private function wrapText(string $text, string $fontPath, int $fontSize, int $maxWidth): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = trim($current.' '.$word);
            [$candidateWidth] = $this->measureTrueTypeLines([$candidate], $fontPath, $fontSize);

            if ($current !== '' && $candidateWidth > $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [$text];
    }

    private function drawBackgroundPlate(mixed $canvas, WatermarkSetting $settings, int $width, int $height): void
    {
        if (! $settings->background_enabled || $settings->background_opacity <= 0) {
            return;
        }

        [$red, $green, $blue] = $this->hexToRgb($settings->background_color);
        $color = imagecolorallocatealpha($canvas, $red, $green, $blue, $this->alpha((int) $settings->background_opacity));
        imagefilledrectangle($canvas, 0, 0, $width, $height, $color);
    }

    private function transparentCanvas(int $width, int $height): mixed
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, max(1, $width), max(1, $height), $transparent);
        imagealphablending($canvas, true);

        return $canvas;
    }

    private function rotateLayer(mixed $layer, int $rotation): mixed
    {
        if ($rotation === 0) {
            return $layer;
        }

        $transparent = imagecolorallocatealpha($layer, 0, 0, 0, 127);
        $rotated = imagerotate($layer, -$rotation, $transparent);
        imagedestroy($layer);

        if (! $rotated) {
            return false;
        }

        imagealphablending($rotated, true);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    private function applyLayerOpacity(mixed $layer, int $opacity): void
    {
        $opacity = max(0, min(100, $opacity));
        if ($opacity >= 100) {
            return;
        }

        $extraAlpha = 127 - (int) round(127 * ($opacity / 100));
        $width = imagesx($layer);
        $height = imagesy($layer);

        imagealphablending($layer, false);

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgba = imagecolorat($layer, $x, $y);
                $alpha = ($rgba & 0x7F000000) >> 24;
                $newAlpha = min(127, $alpha + (int) round((127 - $alpha) * ($extraAlpha / 127)));

                if ($newAlpha === $alpha) {
                    continue;
                }

                $red = ($rgba >> 16) & 0xFF;
                $green = ($rgba >> 8) & 0xFF;
                $blue = $rgba & 0xFF;
                $color = imagecolorallocatealpha($layer, $red, $green, $blue, $newAlpha);
                imagesetpixel($layer, $x, $y, $color);
            }
        }

        imagealphablending($layer, true);
        imagesavealpha($layer, true);
    }

    private function createImage(string $path, string $mimeType): mixed
    {
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/gif' => imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    /**
     * Phone cameras often store portrait photos as landscape pixels with an EXIF
     * orientation flag. GD strips EXIF when saving, so normalize the pixels first.
     *
     * @return array{0:mixed,1:bool}
     */
    private function normalizeExifOrientation(mixed $image, string $path, string $mimeType): array
    {
        if (! in_array($mimeType, ['image/jpeg', 'image/jpg'], true) || ! function_exists('exif_read_data')) {
            return [$image, false];
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        if ($orientation <= 1 || $orientation > 8) {
            return [$image, false];
        }

        $changed = true;

        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = $this->rotateImage($image, 180);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                imageflip($image, IMG_FLIP_VERTICAL);
                $image = $this->rotateImage($image, -90);
                break;
            case 6:
                $image = $this->rotateImage($image, -90);
                break;
            case 7:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                $image = $this->rotateImage($image, -90);
                break;
            case 8:
                $image = $this->rotateImage($image, 90);
                break;
            default:
                $changed = false;
        }

        return [$image, $changed];
    }

    private function rotateImage(mixed $image, int $angle): mixed
    {
        $background = imagecolorallocate($image, 255, 255, 255);
        $rotated = imagerotate($image, $angle, $background);

        if (! $rotated) {
            return $image;
        }

        imagedestroy($image);
        imagealphablending($rotated, true);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    private function saveImage(mixed $image, string $path, string $mimeType): bool
    {
        $tempPath = $path.'.watermarking';

        $saved = match ($mimeType) {
            'image/jpeg', 'image/jpg' => imagejpeg($image, $tempPath, 90),
            'image/png' => imagepng($image, $tempPath, 6),
            'image/gif' => imagegif($image, $tempPath),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, $tempPath, 90) : false,
            default => false,
        };

        if (! $saved) {
            @unlink($tempPath);

            return false;
        }

        return @rename($tempPath, $path);
    }

    private function coordinates(WatermarkSetting $settings, int $imageWidth, int $imageHeight, int $watermarkWidth, int $watermarkHeight): array
    {
        return $this->coordinatesFrom(
            (string) ($settings->position ?: 'bottom-right'),
            (int) ($settings->margin_percent ?? 4),
            $imageWidth,
            $imageHeight,
            $watermarkWidth,
            $watermarkHeight
        );
    }

    private function coordinatesFrom(string $position, int $marginPercent, int $imageWidth, int $imageHeight, int $watermarkWidth, int $watermarkHeight): array
    {
        $margin = (int) round(min($imageWidth, $imageHeight) * (max(0, min(15, $marginPercent)) / 100));

        $x = match (true) {
            str_ends_with($position, 'left') => $margin,
            str_ends_with($position, 'right') => max($margin, $imageWidth - $watermarkWidth - $margin),
            default => max($margin, (int) round(($imageWidth - $watermarkWidth) / 2)),
        };

        $y = match (true) {
            str_starts_with($position, 'top') => $margin,
            str_starts_with($position, 'bottom') => max($margin, $imageHeight - $watermarkHeight - $margin),
            default => max($margin, (int) round(($imageHeight - $watermarkHeight) / 2)),
        };

        return [$x, $y];
    }

    private function fontPath(string $fontFamily): ?string
    {
        $fontFiles = [
            'Arial' => 'arial.ttf',
            'Georgia' => 'georgia.ttf',
            'Times New Roman' => 'times.ttf',
            'Verdana' => 'verdana.ttf',
            'Trebuchet MS' => 'trebuc.ttf',
            'Courier New' => 'cour.ttf',
        ];

        $file = $fontFiles[$fontFamily] ?? 'arial.ttf';
        $candidates = [
            resource_path('fonts/'.$file),
            'C:\\Windows\\Fonts\\'.$file,
            '/usr/share/fonts/truetype/msttcorefonts/'.$file,
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ];

        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                return $path;
            }
        }

        $arialBundled = resource_path('fonts/arial.ttf');

        return file_exists($arialBundled) ? $arialBundled : null;
    }

    private function copyLayerOntoImage(mixed $image, mixed $layer, int $x, int $y): void
    {
        $layerWidth = imagesx($layer);
        $layerHeight = imagesy($layer);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        for ($offsetX = 0; $offsetX < $layerWidth; $offsetX++) {
            for ($offsetY = 0; $offsetY < $layerHeight; $offsetY++) {
                $rgba = imagecolorat($layer, $offsetX, $offsetY);
                $alpha = ($rgba & 0x7F000000) >> 24;

                if ($alpha >= 127) {
                    continue;
                }

                $red = ($rgba >> 16) & 0xFF;
                $green = ($rgba >> 8) & 0xFF;
                $blue = $rgba & 0xFF;
                $color = imagecolorallocatealpha($image, $red, $green, $blue, $alpha);
                imagesetpixel($image, $x + $offsetX, $y + $offsetY, $color);
            }
        }
    }

    private function alpha(int $opacity): int
    {
        return 127 - (int) round(127 * (max(0, min(100, $opacity)) / 100));
    }

    private function shadowOpacity(WatermarkSetting $settings): int
    {
        return max(0, min(100, (int) ($settings->shadow_opacity ?? 55)));
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6) {
            return [255, 255, 255];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
