<?php

namespace App\Services;

use App\Models\DeliverooOfficialProduct;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DeliverooPhotoFormatter
{
    public const WIDTH = 1200;
    public const HEIGHT = 800;
    public const PRODUCT_FILL_RATIO = 0.82;

    public function formattedPublicPath(DeliverooOfficialProduct $product, string $sourceUrl): string
    {
        $fingerprint = sha1(implode('|', [
            $sourceUrl,
            (string) optional($product->updated_at)->timestamp,
            'deliveroo-1200x800-centre-safe-no-watermark-v2',
        ]));

        $directory = 'deliveroo-products/deliveroo-upload';
        $path = $directory.'/product-'.$product->getKey().'-'.$fingerprint.'.jpg';
        $disk = Storage::disk('public');

        if ($disk->exists($path)) {
            return $path;
        }

        $body = $this->downloadImage($sourceUrl);
        $source = @imagecreatefromstring($body);
        if (! $source) {
            throw new RuntimeException('The source product image could not be opened.');
        }

        $canvas = $this->buildCanvas($source);
        imagedestroy($source);

        $disk->makeDirectory($directory);
        $absolutePath = $disk->path($path);
        $saved = imagejpeg($canvas, $absolutePath, 92);
        imagedestroy($canvas);

        if (! $saved) {
            throw new RuntimeException('The Deliveroo-ready product image could not be saved.');
        }

        $this->removeOldProductFormats($product, $path, $directory);

        return $path;
    }

    private function downloadImage(string $sourceUrl): string
    {
        $response = Http::timeout(25)
            ->retry(1, 250)
            ->withHeaders([
                'Accept' => 'image/jpeg,image/png,image/gif,image/*;q=0.8,*/*;q=0.5',
                'User-Agent' => 'LHC Deliveroo Photo Formatter/1.0',
            ])
            ->get($sourceUrl);

        if (! $response->successful()) {
            throw new RuntimeException('The source product image could not be downloaded.');
        }

        $body = $response->body();
        if (strlen($body) < 100) {
            throw new RuntimeException('The source product image was empty.');
        }

        return $body;
    }

    private function buildCanvas(mixed $source): mixed
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            throw new RuntimeException('The source product image has invalid dimensions.');
        }

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, self::WIDTH, self::HEIGHT, $white);

        // Deliveroo requires 3:2 landscape and then shows a cropper. Keep breathing room so Apply does not cut product edges.
        $usableWidth = (int) round(self::WIDTH * self::PRODUCT_FILL_RATIO);
        $usableHeight = (int) round(self::HEIGHT * self::PRODUCT_FILL_RATIO);
        $scale = min($usableWidth / $sourceWidth, $usableHeight / $sourceHeight);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $targetX = (int) round((self::WIDTH - $targetWidth) / 2);
        $targetY = (int) round((self::HEIGHT - $targetHeight) / 2);

        imagecopyresampled(
            $canvas,
            $source,
            $targetX,
            $targetY,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );

        return $canvas;
    }

    private function removeOldProductFormats(DeliverooOfficialProduct $product, string $keepPath, string $directory): void
    {
        $disk = Storage::disk('public');
        $prefix = 'product-'.$product->getKey().'-';

        foreach ($disk->files($directory) as $file) {
            if ($file !== $keepPath && str_starts_with(basename($file), $prefix)) {
                $disk->delete($file);
            }
        }
    }
}
