<?php

namespace App\Http\Controllers;

use App\Models\PhotoProcessingSetting;
use App\Services\BackgroundRemovalService;
use App\Services\ImageWatermarker;
use App\Support\ProductImageNamer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PhotoProcessingSettingController extends Controller
{
    private const MAX_SAMPLE_PHOTO_KB = 35840;

    public function edit(BackgroundRemovalService $backgroundRemoval): View
    {
        $settings = PhotoProcessingSetting::current();

        return view('settings.photo-processing', [
            'settings' => $settings,
            'availability' => $backgroundRemoval->availability($settings),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'remove_background_enabled' => ['nullable', 'boolean'],
            'apply_to_mobile_capture' => ['nullable', 'boolean'],
            'keep_original' => ['nullable', 'boolean'],
            'background_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'python_command' => ['required', 'string', 'max:255'],
            'timeout_seconds' => ['required', 'integer', 'min:30', 'max:600'],
        ]);

        PhotoProcessingSetting::current()->update([
            'remove_background_enabled' => (bool) ($validated['remove_background_enabled'] ?? false),
            'apply_to_mobile_capture' => (bool) ($validated['apply_to_mobile_capture'] ?? false),
            'keep_original' => (bool) ($validated['keep_original'] ?? true),
            'background_color' => strtolower($validated['background_color']),
            'python_command' => trim($validated['python_command']),
            'timeout_seconds' => (int) $validated['timeout_seconds'],
        ]);

        return back()->with('status', 'Photo processing settings saved.');
    }

    public function test(Request $request, BackgroundRemovalService $backgroundRemoval): RedirectResponse
    {
        $validated = $request->validate([
            'sample_photo' => ['required', 'image', 'max:'.self::MAX_SAMPLE_PHOTO_KB],
        ]);

        $settings = PhotoProcessingSetting::current();
        $file = $validated['sample_photo'];
        $directory = 'photo-processing-tests/'.now()->format('Y-m-d-His');
        $extension = $file->guessExtension() ?: $file->extension() ?: 'jpg';
        $baseName = 'Photo processing sample '.now()->format('Y-m-d H-i-s');
        $originalDirectory = $directory.'/original';
        $processedDirectory = $directory.'/processed';
        $originalFilename = ProductImageNamer::uniqueFilename($originalDirectory, $baseName.' - Original sample', $extension);
        $originalPath = $file->storeAs($originalDirectory, $originalFilename, 'public');
        $processedPath = $processedDirectory.'/'.ProductImageNamer::uniqueFilename($processedDirectory, $baseName.' - White background sample', 'jpg');
        $result = $backgroundRemoval->removePublicImageToWhite($originalPath, $processedPath, $settings);

        if ($result['ok']) {
            app(ImageWatermarker::class)->applyToPublicStoragePath($processedPath);
        }

        return back()->with('photo_processing_test', [
            'ok' => $result['ok'],
            'message' => $result['message'],
            'details' => $result['details'],
            'original_url' => Storage::disk('public')->url($originalPath),
            'processed_url' => $result['ok'] ? Storage::disk('public')->url($processedPath) : null,
            'original_path' => $originalPath,
            'processed_path' => $result['ok'] ? $processedPath : null,
            'tested_at' => now()->toDateTimeString(),
        ]);
    }

    public function clearTests(): RedirectResponse
    {
        Storage::disk('public')->deleteDirectory('photo-processing-tests');

        return back()->with('status', 'Photo processing test files deleted.');
    }
}
