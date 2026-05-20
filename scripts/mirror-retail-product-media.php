<?php

use App\Models\ProductMedia;
use App\Support\ProductImageNamer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$options = getopt('', ['family::', 'product::', 'limit::', 'dry-run']);
$familyId = isset($options['family']) ? (int) $options['family'] : null;
$productId = isset($options['product']) ? (int) $options['product'] : null;
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : null;
$dryRun = array_key_exists('dry-run', $options);

$query = ProductMedia::query()
    ->with(['family', 'product'])
    ->whereNull('storage_path')
    ->whereNotNull('external_url')
    ->when($familyId, fn ($query) => $query->where('product_family_id', $familyId))
    ->when($productId, fn ($query) => $query->where('product_id', $productId))
    ->orderBy('id');

if ($limit) {
    $query->limit($limit);
}

$total = 0;
$mirrored = 0;
$failed = 0;

$query->get()->each(function (ProductMedia $media) use (&$total, &$mirrored, &$failed, $dryRun): void {
    $total++;

    $family = $media->family;
    if (! $family) {
        echo "[skip] media {$media->id}: missing family\n";
        $failed++;
        return;
    }

    $product = $media->product;
    $targetName = $product?->name ?? $family->family_name;
    $imageName = $media->alt_text ?: ProductImageNamer::displayName($targetName, $media->image_role);
    $directory = ProductImageNamer::retailDirectory($family, $product);

    echo ($dryRun ? '[dry-run]' : '[mirror]')." media {$media->id}: {$imageName}\n";

    if ($dryRun) {
        return;
    }

    try {
        $response = Http::timeout(20)->retry(1, 250)->get($media->external_url);

        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status());
        }

        $mimeType = strtolower((string) $response->header('Content-Type'));
        if (! str_starts_with($mimeType, 'image/')) {
            throw new RuntimeException('URL did not return an image: '.$mimeType);
        }

        $body = $response->body();
        if (strlen($body) > 10 * 1024 * 1024) {
            throw new RuntimeException('Image larger than 10MB');
        }

        $extension = ProductImageNamer::extensionFromMime($mimeType);
        $filename = ProductImageNamer::uniqueFilename($directory, $imageName, $extension);
        $path = $directory.'/'.$filename;

        Storage::disk('public')->put($path, $body);

        $media->update([
            'source_type' => 'mirrored_url',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'original_filename' => $filename,
            'mime_type' => $mimeType,
            'file_size' => strlen($body),
            'is_offline_ready' => true,
            'alt_text' => $imageName,
        ]);

        $mirrored++;
    } catch (Throwable $error) {
        $failed++;
        echo "[fail] media {$media->id}: {$error->getMessage()}\n";
    }
});

echo "Checked: {$total}\n";
echo "Mirrored: {$mirrored}\n";
echo "Failed: {$failed}\n";

