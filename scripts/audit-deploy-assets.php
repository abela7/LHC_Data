<?php

use App\Models\ShopPhotoBatchItem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * @return array{checked:int,missing:int,samples:array<int,string>}
 */
function checkPublicDiskPaths(string $table, string $pathColumn, ?string $diskColumn = null): array
{
    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $pathColumn)) {
        return ['checked' => 0, 'missing' => 0, 'samples' => []];
    }

    $query = DB::table($table)
        ->whereNotNull($pathColumn)
        ->where($pathColumn, '<>', '');

    if ($diskColumn && Schema::hasColumn($table, $diskColumn)) {
        $query->where(function ($query) use ($diskColumn): void {
            $query->where($diskColumn, 'public')->orWhereNull($diskColumn);
        });
    }

    $checked = 0;
    $missing = 0;
    $samples = [];
    $disk = Storage::disk('public');

    $query->orderBy('id')->select('id', $pathColumn)->chunk(500, function ($rows) use (&$checked, &$missing, &$samples, $disk, $pathColumn, $table): void {
        foreach ($rows as $row) {
            $path = (string) $row->{$pathColumn};
            $checked++;

            if (! $disk->exists($path)) {
                $missing++;

                if (count($samples) < 12) {
                    $samples[] = $table.'#'.$row->id.' -> '.$path;
                }
            }
        }
    });

    return ['checked' => $checked, 'missing' => $missing, 'samples' => $samples];
}

/**
 * @return array{checked:int,missing:int,samples:array<int,string>}
 */
function checkShopPhotoBatchPaths(): array
{
    if (! Schema::hasTable('shop_photo_batch_items')) {
        return ['checked' => 0, 'missing' => 0, 'samples' => []];
    }

    $checked = 0;
    $missing = 0;
    $samples = [];

    ShopPhotoBatchItem::query()
        ->whereNotNull('source_path')
        ->orderBy('id')
        ->chunk(250, function ($items) use (&$checked, &$missing, &$samples): void {
            foreach ($items as $item) {
                $checked++;

                if ($item->resolvedSourcePath() === null) {
                    $missing++;

                    if (count($samples) < 12) {
                        $samples[] = 'shop_photo_batch_items#'.$item->id.' -> '.$item->source_path;
                    }
                }
            }
        });

    return ['checked' => $checked, 'missing' => $missing, 'samples' => $samples];
}

/**
 * @return array{checked:int,missing:int,samples:array<int,string>}
 */
function checkWatermarkLogo(): array
{
    if (! Schema::hasTable('watermark_settings') || ! Schema::hasColumn('watermark_settings', 'logo_path')) {
        return ['checked' => 0, 'missing' => 0, 'samples' => []];
    }

    $checked = 0;
    $missing = 0;
    $samples = [];
    $disk = Storage::disk('public');

    DB::table('watermark_settings')
        ->whereNotNull('logo_path')
        ->where('logo_path', '<>', '')
        ->orderBy('id')
        ->get(['id', 'logo_path'])
        ->each(function ($row) use (&$checked, &$missing, &$samples, $disk): void {
            $checked++;
            $path = (string) $row->logo_path;

            if (! $disk->exists($path)) {
                $missing++;
                $samples[] = 'watermark_settings#'.$row->id.' -> '.$path;
            }
        });

    return ['checked' => $checked, 'missing' => $missing, 'samples' => $samples];
}

/**
 * @return array{checked:int,missing:int,samples:array<int,string>}
 */
function checkPublicFolder(string $relativePath): array
{
    $path = public_path($relativePath);

    if (is_dir($path)) {
        return ['checked' => 1, 'missing' => 0, 'samples' => []];
    }

    return ['checked' => 1, 'missing' => 1, 'samples' => [$relativePath]];
}

/**
 * @return array{checked:int,missing:int,samples:array<int,string>}
 */
function checkLocalhostPublicUrls(string $table, string $urlColumn): array
{
    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $urlColumn)) {
        return ['checked' => 0, 'missing' => 0, 'samples' => []];
    }

    $checked = 0;
    $missing = 0;
    $samples = [];
    $marker = '/LHC_Data/public/';

    DB::table($table)
        ->where($urlColumn, 'like', 'http://localhost/LHC_Data/public/%')
        ->orderBy('id')
        ->select('id', $urlColumn)
        ->chunk(500, function ($rows) use (&$checked, &$missing, &$samples, $urlColumn, $table, $marker): void {
            foreach ($rows as $row) {
                $checked++;
                $url = (string) $row->{$urlColumn};
                $path = parse_url($url, PHP_URL_PATH) ?: '';
                $markerPosition = strpos($path, $marker);

                if ($markerPosition === false) {
                    continue;
                }

                $relativePath = rawurldecode(ltrim(substr($path, $markerPosition + strlen($marker)), '/'));

                if (! is_file(public_path($relativePath))) {
                    $missing++;

                    if (count($samples) < 12) {
                        $samples[] = $table.'#'.$row->id.' -> '.$relativePath;
                    }
                }
            }
        });

    return ['checked' => $checked, 'missing' => $missing, 'samples' => $samples];
}

/**
 * @return array{checked:int,missing:int,samples:array<int,string>}
 */
function checkPdfSourcePaths(): array
{
    $tables = ['pdf_catalogue_pages', 'pdf_catalogue_products'];
    $checkedPaths = [];
    $missing = 0;
    $samples = [];

    foreach ($tables as $table) {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'source_path')) {
            continue;
        }

        DB::table($table)
            ->whereNotNull('source_path')
            ->where('source_path', '<>', '')
            ->distinct()
            ->orderBy('source_path')
            ->pluck('source_path')
            ->each(function ($sourcePath) use (&$checkedPaths, &$missing, &$samples, $table): void {
                $sourcePath = (string) $sourcePath;
                $normalized = str_replace('\\', '/', $sourcePath);
                $marker = '/public/';
                $markerPosition = stripos($normalized, $marker);

                $candidate = $sourcePath;
                if ($markerPosition !== false) {
                    $candidate = public_path(substr($normalized, $markerPosition + strlen($marker)));
                } elseif (! str_contains($normalized, '/') && str_ends_with(strtolower($normalized), '.pdf')) {
                    $candidate = public_path($normalized);
                }

                if (isset($checkedPaths[$candidate])) {
                    return;
                }

                $checkedPaths[$candidate] = true;

                if (! is_file($candidate)) {
                    $missing++;

                    if (count($samples) < 12) {
                        $samples[] = $table.' -> '.$sourcePath;
                    }
                }
            });
    }

    return ['checked' => count($checkedPaths), 'missing' => $missing, 'samples' => $samples];
}

$checks = [
    'catalogue_images.storage_path' => checkPublicDiskPaths('catalogue_images', 'storage_path', 'storage_disk'),
    'catalogue_images.external_url local public files' => checkLocalhostPublicUrls('catalogue_images', 'external_url'),
    'product_media.storage_path' => checkPublicDiskPaths('product_media', 'storage_path', 'storage_disk'),
    'hair_extension_intakes.photo_path' => checkPublicDiskPaths('hair_extension_intakes', 'photo_path', 'photo_disk'),
    'hair_extension_intake_photos.storage_path' => checkPublicDiskPaths('hair_extension_intake_photos', 'storage_path', 'storage_disk'),
    'intake_sessions.photo_path' => checkPublicDiskPaths('intake_sessions', 'photo_path', 'photo_disk'),
    'intake_session_variant_photos.storage_path' => checkPublicDiskPaths('intake_session_variant_photos', 'storage_path', 'storage_disk'),
    'mobile_capture_uploads.storage_path' => checkPublicDiskPaths('mobile_capture_uploads', 'storage_path', 'storage_disk'),
    'mobile_capture_uploads.original_storage_path' => checkPublicDiskPaths('mobile_capture_uploads', 'original_storage_path', 'storage_disk'),
    'mobile_capture_uploads.processed_storage_path' => checkPublicDiskPaths('mobile_capture_uploads', 'processed_storage_path', 'storage_disk'),
    'watermark_settings.logo_path' => checkWatermarkLogo(),
    'pdf_catalogue source PDFs' => checkPdfSourcePaths(),
    'shop_photo_batch_items.source_path' => checkShopPhotoBatchPaths(),
    'public/SHERRYS CATALOGUE' => checkPublicFolder('SHERRYS CATALOGUE'),
];

$totalChecked = 0;
$totalMissing = 0;

echo "Deployment asset audit\n";
echo "======================\n\n";

foreach ($checks as $label => $result) {
    $totalChecked += $result['checked'];
    $totalMissing += $result['missing'];

    echo $label.': '.$result['checked'].' checked, '.$result['missing'].' missing'.PHP_EOL;

    foreach ($result['samples'] as $sample) {
        echo '  - '.$sample.PHP_EOL;
    }
}

echo PHP_EOL.'TOTAL: '.$totalChecked.' checked, '.$totalMissing.' missing'.PHP_EOL;

exit($totalMissing > 0 ? 1 : 0);
