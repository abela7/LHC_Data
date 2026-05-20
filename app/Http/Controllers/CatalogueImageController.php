<?php

namespace App\Http\Controllers;

use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariantOption;
use App\Models\CatalogueFamily;
use App\Models\CatalogueImage;
use App\Models\CatalogueType;
use App\Models\CatalogueVariant;
use App\Models\ImportRecord;
use App\Services\ImageWatermarker;
use App\Support\ProductImageNamer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CatalogueImageController extends Controller
{
    private const MAX_IMAGE_KB = 10240;

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'target_type' => ['required', 'in:family,type,variant,import_record,brand_catalogue_style,brand_catalogue_sku,brand_catalogue_variant_option'],
            'target_id' => ['required', 'integer'],
            'image_role' => ['required', 'string', 'max:255'],
            'uploaded_image' => ['nullable', 'image', 'max:'.self::MAX_IMAGE_KB],
            'external_url' => ['nullable', 'url', 'max:2000'],
            'notes' => ['nullable', 'string'],
            'is_primary' => ['nullable', 'boolean'],
            'mirror_external' => ['nullable', 'boolean'],
            'paste_upload' => ['nullable', 'boolean'],
        ]);

        $hasFile = $request->hasFile('uploaded_image');
        $externalUrl = filled($validated['external_url'] ?? null) ? (string) $validated['external_url'] : null;
        $mirror = (bool) ($validated['mirror_external'] ?? false);

        if (! $hasFile && $externalUrl === null) {
            throw ValidationException::withMessages([
                'uploaded_image' => 'Provide an image URL, upload a file, or paste an image.',
            ]);
        }

        $target = $this->resolveTarget($validated['target_type'], (int) $validated['target_id']);
        $isPrimary = (bool) ($validated['is_primary'] ?? false);

        if ($isPrimary) {
            $target->images()->update(['is_primary' => false]);
        }

        $targetName = $this->targetImageBaseName($target, $validated['target_type']);
        $imageName = ProductImageNamer::displayName($targetName, $validated['image_role']);
        $directory = ProductImageNamer::catalogueDirectory(
            $validated['target_type'],
            (int) $validated['target_id'],
            $targetName,
        );

        $payload = [
            'image_role' => $validated['image_role'],
            'notes' => $validated['notes'] ?? null,
            'is_primary' => $isPrimary,
            'uploaded_by' => $request->user()?->id,
            'original_filename' => ProductImageNamer::filename($imageName, ProductImageNamer::extensionFromUrl($externalUrl)),
        ];

        $applyWatermark = $this->shouldApplyWatermarkForRole($validated['image_role'], $validated['target_type']);

        if ($hasFile) {
            $file = $request->file('uploaded_image');
            $extension = $file->guessExtension() ?: $file->extension() ?: 'jpg';
            $filename = ProductImageNamer::uniqueFilename($directory, $imageName, $extension);
            $path = $file->storeAs($directory, $filename, 'public');
            if ($applyWatermark) {
                $this->applyWatermarkToPublicPath($path, 'uploaded_image');
            }

            $payload['storage_disk'] = 'public';
            $payload['storage_path'] = $path;
            $payload['original_filename'] = $filename;
            $payload['mime_type'] = Storage::disk('public')->mimeType($path) ?: $file->getClientMimeType();
            $payload['file_size'] = Storage::disk('public')->size($path);
            $payload['external_url'] = null;
        } elseif ($mirror && $externalUrl !== null) {
            $payload = array_merge($payload, $this->mirrorExternalImage($externalUrl, $directory, $imageName, $applyWatermark));
        } else {
            $payload['external_url'] = $externalUrl;
        }

        $target->images()->create($payload);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Image added.']);
        }

        return back()->with('status', 'Image added.');
    }

    /**
     * @return array<string, mixed>
     */
    private function mirrorExternalImage(string $url, string $directoryPrefix, string $imageName, bool $applyWatermark = true): array
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
        $filename = ProductImageNamer::uniqueFilename($directoryPrefix, $imageName, $extension);
        $path = $directoryPrefix.'/'.$filename;
        Storage::disk('public')->put($path, $body);
        if ($applyWatermark) {
            $this->applyWatermarkToPublicPath($path, 'external_url');
        }

        return [
            'storage_disk' => 'public',
            'storage_path' => $path,
            'original_filename' => $filename,
            'mime_type' => Storage::disk('public')->mimeType($path) ?: $mimeType,
            'file_size' => Storage::disk('public')->size($path),
            'external_url' => null,
        ];
    }

    public function update(Request $request, CatalogueImage $image): RedirectResponse
    {
        $validated = $request->validate([
            'image_role' => ['required', 'string', 'max:255'],
            'external_url' => ['nullable', 'url'],
            'notes' => ['nullable', 'string'],
            'source_label' => ['nullable', 'string', 'max:255'],
            'usage_context' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        if (! $image->storage_path && ! filled($validated['external_url'] ?? null)) {
            throw ValidationException::withMessages([
                'external_url' => 'External-only image records must keep a valid external image URL.',
            ]);
        }

        $isPrimary = (bool) ($validated['is_primary'] ?? false);

        if ($isPrimary) {
            $image->imageable->images()->whereKeyNot($image->id)->update(['is_primary' => false]);
        }

        $image->update([
            'image_role' => $validated['image_role'],
            'external_url' => $validated['external_url'] ?? null,
            'source_label' => $validated['source_label'] ?? null,
            'usage_context' => $validated['usage_context'] ?? $image->usage_context ?? 'all',
            'notes' => $validated['notes'] ?? null,
            'is_primary' => $isPrimary,
        ]);

        return back()->with('status', 'Image updated.');
    }

    public function replace(Request $request, CatalogueImage $image): JsonResponse
    {
        $validated = $request->validate([
            'image_role' => ['required', 'string', 'max:255'],
            'external_url' => ['nullable', 'url', 'max:2000'],
            'notes' => ['nullable', 'string'],
            'source_label' => ['nullable', 'string', 'max:255'],
            'usage_context' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
            'mirror_external' => ['nullable', 'boolean'],
            'uploaded_image' => ['nullable', 'image', 'max:'.self::MAX_IMAGE_KB],
        ]);

        $hasFile = $request->hasFile('uploaded_image');
        $externalUrl = filled($validated['external_url'] ?? null) ? (string) $validated['external_url'] : null;
        $mirror = (bool) ($validated['mirror_external'] ?? false);
        $isPrimary = (bool) ($validated['is_primary'] ?? false);

        if (! $hasFile && ! $externalUrl) {
            throw ValidationException::withMessages([
                'uploaded_image' => 'Provide an image file or an image URL.',
            ]);
        }

        $target = $image->imageable()->first();
        if (! $target) {
            throw ValidationException::withMessages([
                'image' => 'The referenced image target no longer exists.',
            ]);
        }

        $targetType = $this->resolveImageableTargetType($target);
        $targetId = $target->getKey();
        $targetName = $this->targetImageBaseName($target, $targetType);
        $directory = ProductImageNamer::catalogueDirectory($targetType, (int) $targetId, $targetName);

        if ($isPrimary) {
            $target->images()->update(['is_primary' => false]);
        }

        $nextImageName = ProductImageNamer::displayName($targetName, $validated['image_role']);
        $nextPayload = [
            'image_role' => $validated['image_role'],
            'source_label' => $validated['source_label'] ?? null,
            'usage_context' => $validated['usage_context'] ?? $image->usage_context ?? 'all',
            'notes' => $validated['notes'] ?? null,
            'is_primary' => $isPrimary,
            'uploaded_by' => $request->user()?->id,
            'original_filename' => ProductImageNamer::filename($nextImageName, ProductImageNamer::extensionFromUrl($externalUrl)),
        ];

        $applyWatermark = $this->shouldApplyWatermarkForRole($validated['image_role'], $targetType);

        if ($hasFile) {
            $file = $request->file('uploaded_image');
            $extension = $file->guessExtension() ?: $file->extension() ?: 'jpg';
            $filename = ProductImageNamer::uniqueFilename($directory, $nextImageName, $extension);
            $path = $file->storeAs($directory, $filename, 'public');
            if ($applyWatermark) {
                $this->applyWatermarkToPublicPath($path, 'uploaded_image');
            }

            if ($image->storage_disk && $image->storage_path) {
                Storage::disk($image->storage_disk)->delete($image->storage_path);
            }

            $nextPayload = array_merge($nextPayload, [
                'storage_disk' => 'public',
                'storage_path' => $path,
                'original_filename' => $filename,
                'mime_type' => Storage::disk('public')->mimeType($path) ?: $file->getClientMimeType(),
                'file_size' => Storage::disk('public')->size($path),
                'external_url' => null,
            ]);
        } elseif ($mirror && $externalUrl !== null) {
            $mirrored = $this->mirrorExternalImage($externalUrl, $directory, $nextImageName, $applyWatermark);
            if ($image->storage_disk && $image->storage_path) {
                Storage::disk($image->storage_disk)->delete($image->storage_path);
            }

            $nextPayload = array_merge($nextPayload, $mirrored);
        } else {
            if ($image->storage_disk && $image->storage_path) {
                Storage::disk($image->storage_disk)->delete($image->storage_path);
            }

            $nextPayload = array_merge($nextPayload, [
                'storage_disk' => null,
                'storage_path' => null,
                'original_filename' => ProductImageNamer::filename($nextImageName, ProductImageNamer::extensionFromUrl($externalUrl)),
                'mime_type' => null,
                'file_size' => null,
                'external_url' => $externalUrl,
            ]);
        }

        $image->update($nextPayload);

        return response()->json([
            'ok' => true,
            'message' => 'Image replaced.',
            'image' => [
                'id' => $image->id,
                'url' => $nextPayload['external_url'] ?? $image->displayUrl(),
            ],
        ]);
    }

    public function destroy(CatalogueImage $image): RedirectResponse
    {
        if ($image->storage_disk && $image->storage_path) {
            Storage::disk($image->storage_disk)->delete($image->storage_path);
        }

        $image->delete();

        return back()->with('status', 'Image removed.');
    }

    private function resolveTarget(string $type, int $id): Model
    {
        return match ($type) {
            'family' => CatalogueFamily::query()->findOrFail($id),
            'type' => CatalogueType::query()->findOrFail($id),
            'variant' => CatalogueVariant::query()->findOrFail($id),
            'import_record' => ImportRecord::query()->findOrFail($id),
            'brand_catalogue_style' => BrandCatalogueStyle::query()->findOrFail($id),
            'brand_catalogue_sku' => BrandCatalogueSku::query()->findOrFail($id),
            'brand_catalogue_variant_option' => BrandCatalogueVariantOption::query()->findOrFail($id),
        };
    }

    private function shouldApplyWatermarkForRole(string $imageRole, ?string $targetType = null): bool
    {
        $role = strtolower(trim($imageRole));

        if (! in_array($role, ['variant', 'variant_reference'], true)) {
            return true;
        }

        return $targetType !== 'brand_catalogue_variant_option';
    }

    private function applyWatermarkToPublicPath(string $path, string $errorField): void
    {
        try {
            app(ImageWatermarker::class)->applyToPublicStoragePath($path);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);

            throw ValidationException::withMessages([
                $errorField => 'The image was saved locally, but the current watermark settings could not be applied. Check the watermark logo and try again.',
            ]);
        }
    }

    private function targetImageBaseName(Model $target, string $type): string
    {
        if ($target instanceof BrandCatalogueSku) {
            return ProductImageNamer::cleanHumanText($target->name);
        }

        if ($target instanceof BrandCatalogueStyle) {
            return ProductImageNamer::cleanHumanText($target->name);
        }

        if ($target instanceof BrandCatalogueVariantOption) {
            $target->loadMissing('variant.style');
            $styleName = $target->variant?->style?->name ?: 'Product';
            $variantName = $target->variant?->name ?: 'Variant';

            return ProductImageNamer::cleanHumanText($styleName.' '.$variantName.' '.$target->label);
        }

        $name = $target->getAttribute('name')
            ?? $target->getAttribute('product_name')
            ?? $target->getAttribute('title')
            ?? $type.' '.$target->getKey();

        return ProductImageNamer::cleanHumanText((string) $name);
    }

    private function resolveImageableTargetType(Model $target): string
    {
        return match (get_class($target)) {
            BrandCatalogueStyle::class => 'brand_catalogue_style',
            BrandCatalogueSku::class => 'brand_catalogue_sku',
            BrandCatalogueVariantOption::class => 'brand_catalogue_variant_option',
            CatalogueFamily::class => 'family',
            CatalogueType::class => 'type',
            CatalogueVariant::class => 'variant',
            ImportRecord::class => 'import_record',
            default => 'brand_catalogue_style',
        };
    }
}
