<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductMedia;
use App\Services\ImageWatermarker;
use App\Support\ProductImageNamer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class RetailProductMediaController extends Controller
{
    private const MAX_IMAGE_KB = 35840;

    /**
     * @var array<int, string>
     */
    private const IMAGE_ROLES = [
        'main',
        'style',
        'hero',
        'variant',
        'swatch',
        'gallery',
        'detail',
        'texture',
        'packaging',
        'barcode',
        'back',
        'label_ingredients',
        'shelf_context',
        'source',
    ];

    /**
     * @var array<int, string>
     */
    private const USAGE_CONTEXTS = ['all', 'pos', 'ecommerce', 'inventory', 'admin'];

    public function storeFamily(Request $request, ProductFamily $family): RedirectResponse|JsonResponse
    {
        return $this->store($request, $family, null);
    }

    public function storeProduct(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        return $this->store($request, $product->family()->firstOrFail(), $product);
    }

    public function update(Request $request, ProductMedia $media): RedirectResponse
    {
        $data = $request->validate([
            'image_role' => ['required', Rule::in(self::IMAGE_ROLES)],
            'usage_context' => ['required', Rule::in(self::USAGE_CONTEXTS)],
            'source_label' => ['nullable', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'external_url' => ['nullable', 'url', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        if (! $media->storage_path && ! filled($data['external_url'] ?? null)) {
            throw ValidationException::withMessages([
                'external_url' => 'External-only image records must keep a valid image URL.',
            ]);
        }

        $isPrimary = (bool) ($data['is_primary'] ?? false);
        if ($isPrimary) {
            $this->clearPrimarySiblings($media->product_family_id, $media->product_id, $media->id);
        }

        $media->loadMissing('family', 'product');

        $targetName = $media->product?->name ?? $media->family?->family_name ?? 'Product';
        $imageName = ProductImageNamer::displayName($targetName, $data['image_role']);
        $storageUpdates = $this->syncStoredFilename($media, $imageName);

        $media->update([
            'image_role' => $data['image_role'],
            'usage_context' => $data['usage_context'],
            'source_label' => $this->nullTrim($data['source_label'] ?? null),
            'alt_text' => $imageName,
            'external_url' => $this->nullTrim($data['external_url'] ?? null),
            'notes' => $this->nullTrim($data['notes'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? $media->sort_order),
            'is_primary' => $isPrimary,
            'is_offline_ready' => (bool) $media->storage_path,
        ] + $storageUpdates);

        $this->ensurePrimaryExists($media->product_family_id, $media->product_id);

        return back()->with('status', 'Image details updated.');
    }

    public function makePrimary(ProductMedia $media): RedirectResponse
    {
        $this->clearPrimarySiblings($media->product_family_id, $media->product_id, $media->id);
        $media->update(['is_primary' => true]);

        return back()->with('status', 'Primary image updated.');
    }

    public function replace(Request $request, ProductMedia $media): RedirectResponse|JsonResponse
    {
        $media->loadMissing('family', 'product');

        $data = $request->validate([
            'image_role' => ['required', Rule::in(self::IMAGE_ROLES)],
            'usage_context' => ['required', Rule::in(self::USAGE_CONTEXTS)],
            'source_label' => ['nullable', 'string', 'max:255'],
            'external_url' => ['nullable', 'url', 'max:2000'],
            'uploaded_image' => ['nullable', 'file', 'max:'.self::MAX_IMAGE_KB],
            'notes' => ['nullable', 'string', 'max:2000'],
            'mirror_external' => ['nullable', 'boolean'],
            'paste_upload' => ['nullable', 'boolean'],
        ]);

        if (! $request->hasFile('uploaded_image') && ! filled($data['external_url'] ?? null)) {
            throw ValidationException::withMessages([
                'uploaded_image' => 'Provide an image URL, upload a file, or paste an image.',
            ]);
        }

        $family = $media->family;
        if (! $family) {
            abort(404);
        }

        $targetName = $media->product?->name ?? $family->family_name;
        $imageName = ProductImageNamer::displayName($targetName, $data['image_role']);
        $directory = ProductImageNamer::retailDirectory($family, $media->product);
        $applyWatermark = $this->shouldApplyRetailWatermarkForRole($data['image_role']);

        $oldDisk = $media->storage_disk;
        $oldPath = $media->storage_path;
        $canDeleteOldLocalFile = $media->catalogue_image_id === null && $oldDisk && $oldPath;

        $payload = [
            'catalogue_image_id' => null,
            'image_role' => $data['image_role'],
            'source_type' => 'external_url',
            'source_label' => $this->nullTrim($data['source_label'] ?? null),
            'usage_context' => $data['usage_context'],
            'external_url' => $this->nullTrim($data['external_url'] ?? null),
            'storage_disk' => null,
            'storage_path' => null,
            'original_filename' => ProductImageNamer::filename($imageName, ProductImageNamer::extensionFromUrl($data['external_url'] ?? null)),
            'mime_type' => null,
            'file_size' => null,
            'alt_text' => $imageName,
            'notes' => $this->nullTrim($data['notes'] ?? null),
            'is_offline_ready' => false,
        ];

        if ($request->hasFile('uploaded_image')) {
            $payload = array_merge($payload, $this->storeUploadedFile(
                $request->file('uploaded_image'),
                $directory,
                (bool) ($data['paste_upload'] ?? false),
                $imageName,
                $applyWatermark,
            ));
        } elseif (filled($data['external_url'] ?? null)) {
            $payload = array_merge($payload, $this->mirrorExternalImage($data['external_url'], $directory, $imageName, $applyWatermark));
        }

        $media->update($payload);

        if ($canDeleteOldLocalFile && ($payload['storage_path'] ?? null) !== $oldPath) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        $this->ensurePrimaryExists($media->product_family_id, $media->product_id);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Image replaced.',
                'media' => [
                    'id' => $media->id,
                    'url' => $media->fresh()?->displayUrl(),
                    'alt_text' => $media->alt_text,
                ],
            ]);
        }

        return back()->with('status', 'Image replaced.');
    }

    public function destroy(Request $request, ProductMedia $media): RedirectResponse|JsonResponse
    {
        $familyId = $media->product_family_id;
        $productId = $media->product_id;

        if ($media->catalogue_image_id === null && $media->storage_disk && $media->storage_path) {
            Storage::disk($media->storage_disk)->delete($media->storage_path);
        }

        $media->delete();
        $this->ensurePrimaryExists($familyId, $productId);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Image removed.']);
        }

        return back()->with('status', 'Image removed.');
    }

    private function store(Request $request, ProductFamily $family, ?Product $product): RedirectResponse|JsonResponse
    {
        if ($product && $product->product_family_id !== $family->id) {
            abort(404);
        }

        $data = $request->validate([
            'image_role' => ['required', Rule::in(self::IMAGE_ROLES)],
            'usage_context' => ['required', Rule::in(self::USAGE_CONTEXTS)],
            'source_label' => ['nullable', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'external_url' => ['nullable', 'url', 'max:2000'],
            'uploaded_image' => ['nullable', 'file', 'max:'.self::MAX_IMAGE_KB],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_primary' => ['nullable', 'boolean'],
            'mirror_external' => ['nullable', 'boolean'],
            'paste_upload' => ['nullable', 'boolean'],
            'quick_image_mode' => ['nullable', 'boolean'],
        ]);

        if (! $request->hasFile('uploaded_image') && ! filled($data['external_url'] ?? null)) {
            throw ValidationException::withMessages([
                'uploaded_image' => 'Provide an image URL, upload a file, or paste an image.',
            ]);
        }

        $targetName = $product?->name ?? $family->family_name;
        $imageName = ProductImageNamer::displayName($targetName, $data['image_role']);
        $directory = ProductImageNamer::retailDirectory($family, $product);
        $quickImageMode = (bool) ($data['quick_image_mode'] ?? false);
        $applyWatermark = $this->shouldApplyRetailWatermarkForRole($data['image_role']);

        if ($quickImageMode && in_array($data['image_role'], ['main', 'variant'], true)) {
            $existing = ProductMedia::query()
                ->where('product_family_id', $family->id)
                ->where('product_id', $product?->id)
                ->where('image_role', $data['image_role'])
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            if ($existing) {
                return $this->replaceExistingFromStoreRequest($request, $existing, $family, $product, $data, $directory, $imageName);
            }
        }

        $payload = [
            'product_family_id' => $family->id,
            'product_id' => $product?->id,
            'catalogue_image_id' => null,
            'image_role' => $data['image_role'],
            'source_type' => 'external_url',
            'source_label' => $this->nullTrim($data['source_label'] ?? null),
            'usage_context' => $data['usage_context'],
            'external_url' => $this->nullTrim($data['external_url'] ?? null),
            'alt_text' => $imageName,
            'notes' => $this->nullTrim($data['notes'] ?? null),
            'sort_order' => $this->nextSortOrder($family->id, $product?->id),
            'is_offline_ready' => false,
            'original_filename' => ProductImageNamer::filename($imageName, ProductImageNamer::extensionFromUrl($data['external_url'] ?? null)),
        ];

        if ($request->hasFile('uploaded_image')) {
            $payload = array_merge($payload, $this->storeUploadedFile(
                $request->file('uploaded_image'),
                $directory,
                (bool) ($data['paste_upload'] ?? false),
                $imageName,
                $applyWatermark,
            ));
        } elseif (($data['mirror_external'] ?? false) && filled($data['external_url'] ?? null)) {
            $payload = array_merge($payload, $this->mirrorExternalImage($data['external_url'], $directory, $imageName, $applyWatermark));
        }

        $hasExistingMedia = ProductMedia::query()
            ->where('product_family_id', $family->id)
            ->where('product_id', $product?->id)
            ->exists();

        $canAutoPrimary = ! in_array($data['image_role'], ['gallery', 'detail', 'barcode', 'back', 'label_ingredients', 'shelf_context'], true);
        $isPrimary = (bool) ($data['is_primary'] ?? false)
            || (! $hasExistingMedia && $canAutoPrimary)
            || $data['image_role'] === 'main';
        if ($quickImageMode && $data['image_role'] === 'gallery') {
            $isPrimary = false;
        }
        if ($isPrimary) {
            $this->clearPrimarySiblings($family->id, $product?->id);
        }

        $media = ProductMedia::query()->create($payload + [
            'is_primary' => $isPrimary,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Image added.',
                'media' => [
                    'id' => $media->id,
                    'url' => $media->displayUrl(),
                    'role' => $media->image_role,
                    'alt_text' => $media->alt_text,
                    'source' => $media->sourceDisplay(),
                    'is_primary' => $media->is_primary,
                    'delete_url' => route('retail-products.media.destroy', $media),
                    'mobile_target_type' => in_array($media->image_role, ['main', 'variant'], true) ? 'retail_media' : 'retail_product',
                    'mobile_target_id' => in_array($media->image_role, ['main', 'variant'], true) ? $media->id : ($product?->id ?? $family->id),
                    'count' => ProductMedia::query()
                        ->where('product_family_id', $family->id)
                        ->where('product_id', $product?->id)
                        ->where('image_role', $media->image_role)
                        ->count(),
                ],
            ]);
        }

        return back()->with('status', 'Image added.');
    }

    /**
     * Replace the one allowed quick-image slot for roles such as main/variant.
     *
     * @param array<string, mixed> $data
     */
    private function replaceExistingFromStoreRequest(
        Request $request,
        ProductMedia $media,
        ProductFamily $family,
        ?Product $product,
        array $data,
        string $directory,
        string $imageName
    ): RedirectResponse|JsonResponse {
        $oldDisk = $media->storage_disk;
        $oldPath = $media->storage_path;
        $canDeleteOldLocalFile = $media->catalogue_image_id === null && $oldDisk && $oldPath;
        $applyWatermark = $this->shouldApplyRetailWatermarkForRole($data['image_role']);

        $payload = [
            'catalogue_image_id' => null,
            'image_role' => $data['image_role'],
            'source_type' => 'external_url',
            'source_label' => $this->nullTrim($data['source_label'] ?? null),
            'usage_context' => $data['usage_context'],
            'external_url' => $this->nullTrim($data['external_url'] ?? null),
            'storage_disk' => null,
            'storage_path' => null,
            'original_filename' => ProductImageNamer::filename($imageName, ProductImageNamer::extensionFromUrl($data['external_url'] ?? null)),
            'mime_type' => null,
            'file_size' => null,
            'alt_text' => $imageName,
            'notes' => $this->nullTrim($data['notes'] ?? null),
            'is_offline_ready' => false,
            'is_primary' => true,
        ];

        if ($request->hasFile('uploaded_image')) {
            $payload = array_merge($payload, $this->storeUploadedFile(
                $request->file('uploaded_image'),
                $directory,
                (bool) ($data['paste_upload'] ?? false),
                $imageName,
                $applyWatermark,
            ));
        } elseif (($data['mirror_external'] ?? false) && filled($data['external_url'] ?? null)) {
            $payload = array_merge($payload, $this->mirrorExternalImage($data['external_url'], $directory, $imageName, $applyWatermark));
        } elseif (filled($data['external_url'] ?? null)) {
            $payload['is_primary'] = true;
        }

        $this->clearPrimarySiblings($family->id, $product?->id, $media->id);
        $media->update($payload);

        if ($canDeleteOldLocalFile && ($payload['storage_path'] ?? null) !== $oldPath) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Image replaced.',
                'media' => [
                    'id' => $media->id,
                    'url' => $media->fresh()?->displayUrl(),
                    'role' => $media->image_role,
                    'alt_text' => $media->alt_text,
                    'source' => $media->sourceDisplay(),
                    'is_primary' => $media->is_primary,
                    'delete_url' => route('retail-products.media.destroy', $media),
                    'mobile_target_type' => 'retail_media',
                    'mobile_target_id' => $media->id,
                    'count' => ProductMedia::query()
                        ->where('product_family_id', $family->id)
                        ->where('product_id', $product?->id)
                        ->where('image_role', $media->image_role)
                        ->count(),
                ],
            ]);
        }

        return back()->with('status', 'Image replaced.');
    }

    /**
     * @return array<string, mixed>
     */
    private function storeUploadedFile(mixed $file, string $directory, bool $isPaste, string $imageName, bool $applyWatermark = true): array
    {
        [$sourcePath, $mimeType, $temporaryPath] = $this->prepareUploadedImageSource($file);
        $extension = ProductImageNamer::extensionFromMime($mimeType);
        $filename = ProductImageNamer::uniqueFilename($directory, $imageName, $extension);
        $path = $directory.'/'.$filename;

        try {
            if ($temporaryPath === null) {
                $path = $file->storeAs($directory, $filename, 'public');
            } else {
                $contents = @file_get_contents($sourcePath);
                if ($contents === false) {
                    throw ValidationException::withMessages([
                        'uploaded_image' => 'The uploaded image could not be prepared for saving.',
                    ]);
                }

                Storage::disk('public')->makeDirectory($directory);
                Storage::disk('public')->put($path, $contents);
            }

            if ($applyWatermark) {
                $this->applyWatermarkOrFail($path, 'uploaded_image');
            }
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        return [
            'source_type' => $isPaste ? 'pasted_upload' : 'file_upload',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'original_filename' => $filename,
            'mime_type' => Storage::disk('public')->mimeType($path) ?: $mimeType,
            'file_size' => Storage::disk('public')->size($path),
            'is_offline_ready' => true,
        ];
    }

    /**
     * @return array{0:string,1:string,2:?string}
     */
    private function prepareUploadedImageSource(mixed $file): array
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw ValidationException::withMessages([
                'uploaded_image' => 'The uploaded image could not be read by the server.',
            ]);
        }

        $imageInfo = @getimagesize($path);
        $mimeType = strtolower((string) ($imageInfo['mime'] ?? ''));
        if ($imageInfo && str_starts_with($mimeType, 'image/')) {
            return [$path, $mimeType, null];
        }

        $convertedPath = $this->convertUploadedImageToJpeg($path);
        if ($convertedPath !== null) {
            return [$convertedPath, 'image/jpeg', $convertedPath];
        }

        $clientMime = strtolower((string) ($file->getClientMimeType() ?: $file->getMimeType()));
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $looksLikeHeic = in_array($extension, ['heic', 'heif'], true)
            || in_array($clientMime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true);

        throw ValidationException::withMessages([
            'uploaded_image' => $looksLikeHeic
                ? 'This phone photo is HEIC/HEIF and the server could not convert it. Change the phone camera format to JPEG/Most Compatible and try again.'
                : 'The uploaded file is not a readable image. Use JPEG, PNG, WebP, or GIF.',
        ]);
    }

    private function convertUploadedImageToJpeg(string $path): ?string
    {
        if (! class_exists(\Imagick::class)) {
            return null;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'lhc-upload-');
        if ($temporaryPath === false) {
            return null;
        }

        try {
            $image = new \Imagick();
            $image->readImage($path);
            if ($image->getNumberImages() > 1) {
                $image->setIteratorIndex(0);
            }

            $image->setImageBackgroundColor('white');
            if ($image->getImageAlphaChannel()) {
                $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            }
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(88);
            $image->writeImage($temporaryPath);
            $image->clear();
            $image->destroy();

            $imageInfo = @getimagesize($temporaryPath);
            if (! $imageInfo || ! str_starts_with(strtolower((string) ($imageInfo['mime'] ?? '')), 'image/')) {
                @unlink($temporaryPath);

                return null;
            }

            return $temporaryPath;
        } catch (Throwable) {
            @unlink($temporaryPath);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mirrorExternalImage(string $url, string $directory, string $imageName, bool $applyWatermark = true): array
    {
        $response = Http::timeout(15)->retry(1, 250)->get($url);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'external_url' => 'The image URL could not be downloaded.',
            ]);
        }

        $mimeType = strtolower((string) $response->header('Content-Type'));
        if (! str_starts_with($mimeType, 'image/')) {
            throw ValidationException::withMessages([
                'external_url' => 'The URL did not return an image file.',
            ]);
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_IMAGE_KB * 1024) {
            throw ValidationException::withMessages([
                'external_url' => 'The image is larger than 10MB.',
            ]);
        }

        $extension = ProductImageNamer::extensionFromMime($mimeType);
        $filename = ProductImageNamer::uniqueFilename($directory, $imageName, $extension);
        $path = $directory.'/'.$filename;
        Storage::disk('public')->put($path, $body);
        if ($applyWatermark) {
            $this->applyWatermarkOrFail($path, 'external_url');
        }

        return [
            'source_type' => 'mirrored_url',
            'storage_disk' => 'public',
            'storage_path' => $path,
            'original_filename' => $filename,
            'mime_type' => Storage::disk('public')->mimeType($path) ?: $mimeType,
            'file_size' => Storage::disk('public')->size($path),
            'is_offline_ready' => true,
        ];
    }

    private function nextSortOrder(int $familyId, ?int $productId): int
    {
        return ((int) ProductMedia::query()
            ->where('product_family_id', $familyId)
            ->where('product_id', $productId)
            ->max('sort_order')) + 1;
    }

    private function applyWatermarkOrFail(string $path, string $field): void
    {
        try {
            app(ImageWatermarker::class)->applyToPublicStoragePath($path);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);

            throw ValidationException::withMessages([
                $field => 'The image was saved locally, but the current watermark settings could not be applied. Check the watermark settings and try again.',
            ]);
        }
    }

    private function shouldApplyRetailWatermarkForRole(string $imageRole): bool
    {
        return in_array($imageRole, ['main', 'gallery'], true);
    }

    private function clearPrimarySiblings(int $familyId, ?int $productId, ?int $exceptId = null): void
    {
        ProductMedia::query()
            ->where('product_family_id', $familyId)
            ->where('product_id', $productId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_primary' => false]);
    }

    private function ensurePrimaryExists(int $familyId, ?int $productId): void
    {
        $hasPrimary = ProductMedia::query()
            ->where('product_family_id', $familyId)
            ->where('product_id', $productId)
            ->where('is_primary', true)
            ->exists();

        if ($hasPrimary) {
            return;
        }

        $fallback = ProductMedia::query()
            ->where('product_family_id', $familyId)
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        $fallback?->update(['is_primary' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function syncStoredFilename(ProductMedia $media, string $imageName): array
    {
        $extension = $this->mediaExtension($media);
        $filename = ProductImageNamer::filename($imageName, $extension);

        if (! $media->storage_disk || ! $media->storage_path) {
            return ['original_filename' => $filename];
        }

        if ($media->catalogue_image_id !== null && $media->source_type === 'catalogue_source') {
            return ['original_filename' => $filename];
        }

        $family = $media->family;
        if (! $family) {
            return ['original_filename' => $filename];
        }

        $directory = ProductImageNamer::retailDirectory($family, $media->product);
        $desiredFilename = ProductImageNamer::filename($imageName, $extension);
        $desiredPath = $directory.'/'.$desiredFilename;

        if ($desiredPath === $media->storage_path) {
            return ['original_filename' => $desiredFilename];
        }

        $newFilename = ProductImageNamer::uniqueFilename($directory, $imageName, $extension, $media->storage_disk);
        $newPath = $directory.'/'.$newFilename;

        if ($newPath === $media->storage_path) {
            return ['original_filename' => $newFilename];
        }

        $disk = Storage::disk($media->storage_disk);
        if (! $disk->exists($media->storage_path)) {
            return [
                'original_filename' => $newFilename,
                'storage_path' => $newPath,
            ];
        }

        $disk->makeDirectory($directory);
        $disk->move($media->storage_path, $newPath);

        return [
            'original_filename' => $newFilename,
            'storage_path' => $newPath,
            'is_offline_ready' => true,
        ];
    }

    private function mediaExtension(ProductMedia $media): string
    {
        if ($media->mime_type) {
            return ProductImageNamer::extensionFromMime($media->mime_type);
        }

        if ($media->storage_path) {
            return ProductImageNamer::extensionFromUrl($media->storage_path);
        }

        return ProductImageNamer::extensionFromUrl($media->external_url);
    }

    private function nullTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
