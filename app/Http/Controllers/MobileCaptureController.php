<?php

namespace App\Http\Controllers;

use App\Models\MobileCaptureSetting;
use App\Models\MobileCaptureUpload;
use App\Models\PhotoProcessingSetting;
use App\Services\BackgroundRemovalService;
use App\Services\ImageWatermarker;
use App\Support\ProductImageNamer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MobileCaptureController extends Controller
{
    private const MAX_MOBILE_PHOTO_KB = 35840;

    public function edit(Request $request): View
    {
        $settings = MobileCaptureSetting::current();
        $phoneUrls = $this->phoneUrls($request, $settings);

        return view('settings.mobile-capture', [
            'settings' => $settings,
            'networkIps' => $this->localIpv4Addresses(),
            'phoneUrls' => $phoneUrls,
            'preferredPhoneUrl' => $phoneUrls[0] ?? URL::route('mobile-capture.phone', $settings->access_token),
            'preferredProductIntakeUrl' => $this->localPathUrls($request, '/hair-extension-product-intake/submitted')[0] ?? url('/hair-extension-product-intake/submitted'),
            'statusUrl' => route('settings.mobile-capture.status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $settings = MobileCaptureSetting::current();

        if ($request->input('action') === 'regenerate') {
            $settings->regenerateToken();

            return back()->with('status', 'Mobile capture link regenerated.');
        }

        if ($request->input('action') === 'clear') {
            $settings->update([
                'last_seen_at' => null,
                'last_ip' => null,
                'last_user_agent' => null,
                'camera_status' => 'untested',
                'camera_error' => null,
                'camera_tested_at' => null,
            ]);

            return back()->with('status', 'Mobile connection status cleared.');
        }

        $settings->update([
            'is_enabled' => (bool) $request->boolean('is_enabled'),
        ]);

        return back()->with('status', $settings->fresh()->is_enabled ? 'Mobile capture enabled.' : 'Mobile capture disabled.');
    }

    public function status(): JsonResponse
    {
        $settings = MobileCaptureSetting::current();

        return response()->json($this->statusPayload($settings));
    }

    public function phone(Request $request, string $token): View
    {
        $settings = MobileCaptureSetting::current();
        $validToken = hash_equals($settings->access_token, $token);

        if ($validToken && $settings->is_enabled) {
            $this->recordHeartbeat($request, $settings, $request->input('camera_status'));
        }

        return view('mobile-capture.phone', [
            'settings' => $settings,
            'validToken' => $validToken,
            'heartbeatUrl' => $validToken ? route('mobile-capture.heartbeat', $settings->access_token) : null,
            'uploadUrl' => $validToken ? route('mobile-capture.uploads.store', $settings->access_token) : null,
            'jobsUrl' => $validToken ? route('mobile-capture.jobs.index', $settings->access_token) : null,
            'maxUploadKb' => self::MAX_MOBILE_PHOTO_KB,
            'maxUploadMb' => round(self::MAX_MOBILE_PHOTO_KB / 1024),
            'appHomeUrl' => url('/'),
        ]);
    }

    public function heartbeat(Request $request, string $token): JsonResponse
    {
        $settings = MobileCaptureSetting::current();

        if (! hash_equals($settings->access_token, $token)) {
            abort(404);
        }

        if (! $settings->is_enabled) {
            return response()->json([
                'ok' => false,
                'message' => 'Mobile capture is disabled on the PC.',
            ], 423);
        }

        $validated = $request->validate([
            'camera_status' => ['nullable', 'string', 'max:40'],
            'camera_error' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->recordHeartbeat($request, $settings, $validated['camera_status'] ?? null, $validated['camera_error'] ?? null);

        return response()->json([
            'ok' => true,
            'status' => $this->statusPayload($settings->fresh()),
        ]);
    }

    public function storeUpload(Request $request, string $token): JsonResponse
    {
        $settings = MobileCaptureSetting::current();

        if (! hash_equals($settings->access_token, $token)) {
            abort(404);
        }

        if (! $settings->is_enabled) {
            return response()->json([
                'ok' => false,
                'message' => 'Mobile capture is disabled on the PC.',
            ], 423);
        }

        $validated = $request->validate([
            'photo' => ['required', 'image', 'max:'.self::MAX_MOBILE_PHOTO_KB],
        ]);

        $file = $validated['photo'];
        $dateDirectory = 'mobile-capture/'.now()->format('Y-m-d');
        $originalDirectory = $dateDirectory.'/originals';
        $finalDirectory = $dateDirectory.'/final';
        $extension = $file->guessExtension() ?: $file->extension() ?: 'jpg';
        $imageName = 'Mobile capture '.now()->format('Y-m-d H-i-s');
        $originalFilename = ProductImageNamer::uniqueFilename($originalDirectory, $imageName.' - Original photo', $extension);
        $originalPath = $file->storeAs($originalDirectory, $originalFilename, 'public');

        [$displayPath, $processedPath, $processingStatus, $processingError, $backgroundRemovedAt] = $this->prepareMobileCaptureFinalImage(
            $originalPath,
            $finalDirectory,
            $imageName,
            $extension
        );
        $photoSettings = PhotoProcessingSetting::current();
        $storedOriginalPath = $originalPath;

        if (! $photoSettings->keep_original && $originalPath !== $displayPath) {
            Storage::disk('public')->delete($originalPath);
            $storedOriginalPath = null;
        }

        $upload = MobileCaptureUpload::query()->create([
            'storage_disk' => 'public',
            'storage_path' => $displayPath,
            'original_storage_path' => $storedOriginalPath,
            'processed_storage_path' => $processedPath,
            'processing_status' => $processingStatus,
            'processing_error' => $processingError,
            'background_removed_at' => $backgroundRemovedAt,
            'original_filename' => basename($displayPath),
            'mime_type' => Storage::disk('public')->mimeType($displayPath) ?: $file->getClientMimeType(),
            'file_size' => Storage::disk('public')->size($displayPath),
            'source_ip' => $request->ip(),
            'source_user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);

        $this->recordHeartbeat($request, $settings, 'uploaded');

        return response()->json([
            'ok' => true,
            'message' => 'Photo sent to desktop.',
            'upload' => $this->uploadPayload($upload),
            'status' => $this->statusPayload($settings->fresh()),
        ]);
    }

    public function destroyUpload(MobileCaptureUpload $upload): JsonResponse
    {
        $this->deleteUpload($upload);

        return response()->json([
            'ok' => true,
            'message' => 'Photo permanently deleted.',
            'status' => $this->statusPayload(MobileCaptureSetting::current()),
        ]);
    }

    public function destroyAllUploads(): JsonResponse
    {
        $deleted = 0;

        MobileCaptureUpload::query()
            ->oldest()
            ->get()
            ->each(function (MobileCaptureUpload $upload) use (&$deleted): void {
                $this->deleteUpload($upload);
                $deleted++;
            });

        return response()->json([
            'ok' => true,
            'message' => "{$deleted} photo(s) permanently deleted.",
            'deleted' => $deleted,
            'status' => $this->statusPayload(MobileCaptureSetting::current()),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function localIpv4Addresses(): array
    {
        $ips = [];

        if (function_exists('shell_exec')) {
            $output = @shell_exec('ipconfig');
            if (is_string($output)) {
                preg_match_all('/IPv4 Address[^\:]*:\s*([0-9\.]+)/i', $output, $matches);
                $ips = array_merge($ips, $matches[1] ?? []);
            }
        }

        $hostIps = gethostbynamel(gethostname()) ?: [];
        $ips = array_merge($ips, $hostIps);

        return collect($ips)
            ->filter(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            ->reject(fn ($ip) => Str::startsWith($ip, ['127.', '169.254.']))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function phoneUrls(Request $request, MobileCaptureSetting $settings): array
    {
        return $this->localPathUrls($request, '/mobile-capture/'.$settings->access_token);
    }

    /**
     * @return array<int, string>
     */
    private function localPathUrls(Request $request, string $path): array
    {
        $scheme = $request->getScheme() ?: 'http';
        $port = $request->getPort();
        $portPart = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443) ? '' : ':'.$port;
        $basePath = rtrim($request->getBaseUrl(), '/');
        $path = '/'.ltrim($path, '/');

        return collect($this->localIpv4Addresses())
            ->map(fn ($ip) => "{$scheme}://{$ip}{$portPart}{$basePath}{$path}")
            ->values()
            ->all();
    }

    private function recordHeartbeat(Request $request, MobileCaptureSetting $settings, ?string $cameraStatus = null, ?string $cameraError = null): void
    {
        $updates = [
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
            'last_user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ];

        if ($cameraStatus) {
            $updates['camera_status'] = $cameraStatus;
            $updates['camera_error'] = $cameraError;
            $updates['camera_tested_at'] = now();
        }

        $settings->update($updates);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(MobileCaptureSetting $settings): array
    {
        $lastSeen = $settings->last_seen_at;
        $secondsAgo = $lastSeen ? max(0, (int) $lastSeen->diffInSeconds(Carbon::now(), true)) : null;

        return [
            'enabled' => $settings->is_enabled,
            'connected' => $settings->is_enabled && $secondsAgo !== null && $secondsAgo <= 20,
            'last_seen_at' => $lastSeen?->toDateTimeString(),
            'seconds_ago' => $secondsAgo,
            'last_ip' => $settings->last_ip,
            'last_user_agent' => $settings->last_user_agent,
            'camera_status' => $settings->camera_status,
            'camera_error' => $settings->camera_error,
            'camera_tested_at' => $settings->camera_tested_at?->toDateTimeString(),
            'recent_uploads' => MobileCaptureUpload::query()
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (MobileCaptureUpload $upload) => $this->uploadPayload($upload))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadPayload(MobileCaptureUpload $upload): array
    {
        return [
            'id' => $upload->id,
            'url' => $upload->publicUrl(),
            'original_url' => $upload->originalUrl(),
            'processed_url' => $upload->processedUrl(),
            'delete_url' => route('settings.mobile-capture.uploads.destroy', $upload),
            'filename' => $upload->original_filename,
            'mime_type' => $upload->mime_type,
            'file_size' => $upload->file_size,
            'processing_status' => $upload->processing_status,
            'processing_error' => $upload->processing_error,
            'background_removed' => $upload->processing_status === 'completed',
            'background_removed_at' => $upload->background_removed_at?->toDateTimeString(),
            'created_at' => $upload->created_at?->toDateTimeString(),
        ];
    }

    private function deleteUpload(MobileCaptureUpload $upload): void
    {
        collect([
            $upload->storage_path,
            $upload->original_storage_path,
            $upload->processed_storage_path,
        ])
            ->filter()
            ->unique()
            ->each(fn (string $path): bool => Storage::disk($upload->storage_disk ?: 'public')->delete($path));

        $upload->delete();
    }

    /**
     * @return array{0:string,1:string|null,2:string,3:string|null,4:\Illuminate\Support\Carbon|null}
     */
    private function prepareMobileCaptureFinalImage(string $originalPath, string $finalDirectory, string $imageName, string $originalExtension): array
    {
        $photoSettings = PhotoProcessingSetting::current();
        $disk = Storage::disk('public');
        $processedPath = null;
        $processingStatus = 'disabled';
        $processingError = null;
        $backgroundRemovedAt = null;

        if ($photoSettings->remove_background_enabled && $photoSettings->apply_to_mobile_capture) {
            $candidateFilename = ProductImageNamer::uniqueFilename($finalDirectory, $imageName.' - White background final photo', 'jpg');
            $candidatePath = $finalDirectory.'/'.$candidateFilename;
            $result = app(BackgroundRemovalService::class)->removePublicImageToWhite($originalPath, $candidatePath, $photoSettings);

            if ($result['ok']) {
                $processedPath = $candidatePath;
                $processingStatus = 'completed';
                $backgroundRemovedAt = now();
                app(ImageWatermarker::class)->applyToPublicStoragePath($processedPath);

                return [$processedPath, $processedPath, $processingStatus, null, $backgroundRemovedAt];
            }

            $processingStatus = 'failed';
            $processingError = trim(($result['message'] ?? 'AI background removal failed.').' '.($result['details'] ?? ''));
        }

        $fallbackFilename = ProductImageNamer::uniqueFilename($finalDirectory, $imageName.' - Final photo', $originalExtension);
        $fallbackPath = $finalDirectory.'/'.$fallbackFilename;
        $disk->copy($originalPath, $fallbackPath);
        app(ImageWatermarker::class)->applyToPublicStoragePath($fallbackPath);

        return [$fallbackPath, $processedPath, $processingStatus, $processingError, $backgroundRemovedAt];
    }
}
