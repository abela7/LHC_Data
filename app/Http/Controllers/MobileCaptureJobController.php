<?php

namespace App\Http\Controllers;

use App\Models\BrandCatalogueSku;
use App\Models\BrandCatalogueStyle;
use App\Models\BrandCatalogueVariantOption;
use App\Models\CatalogueFamily;
use App\Models\CatalogueImage;
use App\Models\CatalogueType;
use App\Models\CatalogueVariant;
use App\Models\HairExtensionIntake;
use App\Models\HairExtensionIntakePhoto;
use App\Models\ImportRecord;
use App\Models\MobileCaptureJob;
use App\Models\MobileCaptureSetting;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductMedia;
use App\Services\ImageWatermarker;
use App\Support\ProductImageNamer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileCaptureJobController extends Controller
{
    private const MAX_MOBILE_PHOTO_KB = 35840;

    private const RETAIL_IMAGE_ROLES = [
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

    private const RETAIL_USAGE_CONTEXTS = ['all', 'pos', 'ecommerce', 'inventory', 'admin'];

    private const MULTI_PHOTO_ROLES = ['gallery', 'detail', 'texture', 'packaging', 'shelf_context', 'source'];

    public function store(Request $request): JsonResponse
    {
        $settings = MobileCaptureSetting::current();

        if (! $settings->is_enabled) {
            return response()->json([
                'ok' => false,
                'message' => 'Mobile capture is disabled. Enable it from Settings > Mobile Capture first.',
            ], 423);
        }

        $data = $request->validate([
            'mobile_capture_destination' => ['required', Rule::in(['retail', 'catalogue', 'intake'])],
            'mobile_capture_target_type' => ['nullable', Rule::in(['retail_family', 'retail_product', 'retail_media'])],
            'mobile_capture_target_id' => ['nullable', 'integer'],
            'target_type' => ['nullable', Rule::in(['family', 'type', 'variant', 'import_record', 'brand_catalogue_style', 'brand_catalogue_sku', 'brand_catalogue_variant_option', 'hair_extension_intake'])],
            'target_id' => ['nullable', 'integer'],
            'image_role' => ['required', 'string', 'max:255'],
            'usage_context' => ['nullable', 'string', 'max:255'],
            'source_label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $destination = $data['mobile_capture_destination'];
        $targetType = null;
        $targetId = null;
        $targetLabel = null;

        if ($destination === 'retail') {
            $targetType = $data['mobile_capture_target_type'] ?? null;
            $targetId = (int) ($data['mobile_capture_target_id'] ?? 0);
            if (! in_array($targetType, ['retail_family', 'retail_product', 'retail_media'], true) || $targetId <= 0) {
                throw ValidationException::withMessages([
                    'mobile_capture_target_id' => 'This image form is missing a valid retail target.',
                ]);
            }

            if (! in_array($data['image_role'], self::RETAIL_IMAGE_ROLES, true)) {
                throw ValidationException::withMessages(['image_role' => 'Invalid retail image purpose.']);
            }

            if (! in_array($data['usage_context'] ?? 'all', self::RETAIL_USAGE_CONTEXTS, true)) {
                throw ValidationException::withMessages(['usage_context' => 'Invalid retail image usage.']);
            }

            $targetLabel = match ($targetType) {
                'retail_family' => ProductFamily::query()->findOrFail($targetId)->family_name,
                'retail_product' => Product::query()->findOrFail($targetId)->name,
                'retail_media' => $this->retailMediaTargetLabel(ProductMedia::query()->with('family', 'product')->findOrFail($targetId)),
                default => null,
            };
        } elseif ($destination === 'intake') {
            $targetType = $data['target_type'] ?? null;
            $targetId = (int) ($data['target_id'] ?? 0);

            if ($targetType !== 'hair_extension_intake' || $targetId <= 0) {
                throw ValidationException::withMessages([
                    'target_id' => 'This phone request is missing a valid hair extension intake target.',
                ]);
            }

            $intake = HairExtensionIntake::query()->findOrFail($targetId);
            $targetLabel = $this->intakeImageBaseName($intake);
        } else {
            $targetType = $data['target_type'] ?? null;
            $targetId = (int) ($data['target_id'] ?? 0);
            if (! $targetType || $targetId <= 0) {
                throw ValidationException::withMessages([
                    'target_id' => 'This image form is missing a valid catalogue target.',
                ]);
            }

            $target = $this->resolveCatalogueTarget($targetType, $targetId);
            $targetLabel = $this->catalogueTargetImageBaseName($target, $targetType);
        }

        $job = MobileCaptureJob::query()->create([
            'token' => MobileCaptureJob::newToken(),
            'status' => MobileCaptureJob::STATUS_PENDING,
            'destination_type' => $destination,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_label' => ProductImageNamer::cleanHumanText((string) $targetLabel),
            'image_role' => $data['image_role'],
            'usage_context' => $data['usage_context'] ?? 'all',
            'source_label' => $this->nullTrim($data['source_label'] ?? null),
            'notes' => $this->nullTrim($data['notes'] ?? null),
            'is_primary' => (bool) ($data['is_primary'] ?? false),
            'expires_at' => now()->addHours(8),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Phone upload request created.',
            'job' => $this->jobPayload($job),
            'phone_connected' => $this->phoneIsConnected($settings),
            'phone_url' => $this->preferredPhoneUrl($request, $settings, $job),
            'status_url' => route('mobile-capture.jobs.status', $job->token),
        ]);
    }

    public function status(string $token): JsonResponse
    {
        $job = MobileCaptureJob::query()->where('token', $token)->firstOrFail();

        return response()->json([
            'ok' => true,
            'job' => $this->jobPayload($job),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function index(Request $request, string $accessToken): JsonResponse
    {
        $settings = MobileCaptureSetting::current();

        if (! hash_equals($settings->access_token, $accessToken)) {
            abort(404);
        }

        if (! $settings->is_enabled) {
            return response()->json([
                'ok' => false,
                'message' => 'Mobile capture is disabled on the PC.',
                'jobs' => [],
            ], 423);
        }

        $jobs = MobileCaptureJob::query()
            ->where('status', MobileCaptureJob::STATUS_PENDING)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (MobileCaptureJob $job) => $this->jobPayload($job, $accessToken))
            ->values();

        return response()->json([
            'ok' => true,
            'jobs' => $jobs,
        ]);
    }

    public function upload(Request $request, string $accessToken, string $jobToken): JsonResponse
    {
        $settings = MobileCaptureSetting::current();

        if (! hash_equals($settings->access_token, $accessToken)) {
            abort(404);
        }

        if (! $settings->is_enabled) {
            return response()->json([
                'ok' => false,
                'message' => 'Mobile capture is disabled on the PC.',
            ], 423);
        }

        $job = MobileCaptureJob::query()->where('token', $jobToken)->firstOrFail();
        if (! $job->isPending()) {
            return response()->json([
                'ok' => false,
                'message' => 'This phone upload request is no longer pending.',
                'job' => $this->jobPayload($job),
            ], 409);
        }

        $validated = $request->validate([
            'photo' => ['nullable', 'image', 'max:'.self::MAX_MOBILE_PHOTO_KB],
            'photos' => ['nullable', 'array', 'max:12'],
            'photos.*' => ['image', 'max:'.self::MAX_MOBILE_PHOTO_KB],
        ]);

        $files = $this->uploadedPhotos($request);
        if ($files === []) {
            throw ValidationException::withMessages([
                'photo' => 'Provide at least one product photo.',
            ]);
        }

        if (count($files) > 1 && ! $this->jobAllowsMultiplePhotos($job)) {
            throw ValidationException::withMessages([
                'photos' => 'This image purpose accepts one photo only.',
            ]);
        }

        try {
            $results = [];
            $fileCount = count($files);
            foreach ($files as $index => $file) {
                $isFirst = $index === 0;
                $primaryOverride = $fileCount > 1 ? ($isFirst && $job->is_primary) : null;
                $photoNumber = $fileCount > 1 ? $index + 1 : null;
                $results[] = match ($job->destination_type) {
                    'retail' => $this->storeRetailImage($job, $file, $primaryOverride, $photoNumber),
                    'intake' => $this->storeIntakeImage($job, $file, $primaryOverride, $photoNumber),
                    default => $this->storeCatalogueImage($job, $file, $primaryOverride, $photoNumber),
                };
            }

            $result = $results[0];

            $job->update([
                'status' => MobileCaptureJob::STATUS_COMPLETED,
                'result_type' => $result['type'],
                'result_id' => $result['id'],
                'last_ip' => $request->ip(),
                'last_user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'completed_at' => now(),
                'error_message' => null,
            ]);

            return response()->json([
                'ok' => true,
                'message' => count($results) === 1
                    ? 'Photo saved to '.($job->destination_type === 'intake' ? 'the intake evidence record.' : 'the selected product image slot.')
                    : count($results).' photos saved to '.($job->destination_type === 'intake' ? 'the intake evidence record.' : 'the selected product image slot.'),
                'job' => $this->jobPayload($job->fresh()),
                'results' => $results,
            ]);
        } catch (\Throwable $exception) {
            $job->update([
                'status' => MobileCaptureJob::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
                'last_ip' => $request->ip(),
                'last_user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'completed_at' => now(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage() ?: 'Unable to save the phone photo.',
                'job' => $this->jobPayload($job->fresh()),
            ], 500);
        }
    }

    public function cancel(Request $request, string $accessToken, string $jobToken): JsonResponse
    {
        $settings = MobileCaptureSetting::current();

        if (! hash_equals($settings->access_token, $accessToken)) {
            abort(404);
        }

        $job = MobileCaptureJob::query()->where('token', $jobToken)->firstOrFail();
        if (! $job->isPending()) {
            return response()->json([
                'ok' => false,
                'message' => 'This phone upload request is no longer pending.',
                'job' => $this->jobPayload($job),
            ], 409);
        }

        $job->update([
            'status' => MobileCaptureJob::STATUS_CANCELLED,
            'error_message' => 'Closed on phone.',
            'last_ip' => $request->ip(),
            'last_user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'completed_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Photo request closed.',
            'job' => $this->jobPayload($job->fresh()),
        ]);
    }

    /**
     * @return array{type:string,id:int}
     */
    private function storeRetailImage(MobileCaptureJob $job, mixed $file, ?bool $isPrimaryOverride = null, ?int $photoNumber = null): array
    {
        if ($job->target_type === 'retail_media') {
            return $this->replaceRetailImage($job, $file);
        }

        $family = null;
        $product = null;

        if ($job->target_type === 'retail_family') {
            $family = ProductFamily::query()->findOrFail($job->target_id);
        } elseif ($job->target_type === 'retail_product') {
            $product = Product::query()->with('family')->findOrFail($job->target_id);
            $family = $product->family;
        }

        if (! $family) {
            throw new \RuntimeException('Retail product target was not found.');
        }

        $imageRole = $job->image_role ?: 'main';
        $targetName = $product?->name ?? $family->family_name;
        $imageName = $this->imageDisplayName($targetName, $imageRole, $photoNumber);
        $directory = ProductImageNamer::retailDirectory($family, $product);
        $stored = $this->storeUploadedFile(
            $file,
            $directory,
            $imageName,
            applyWatermark: $this->shouldApplyRetailWatermarkForRole($imageRole),
        );
        $hasExistingMedia = ProductMedia::query()
            ->where('product_family_id', $family->id)
            ->where('product_id', $product?->id)
            ->exists();
        $canAutoPrimary = ! in_array($imageRole, ['gallery', 'detail', 'barcode', 'back', 'label_ingredients', 'shelf_context'], true);
        $isPrimary = ($isPrimaryOverride ?? $job->is_primary) || (! $hasExistingMedia && $canAutoPrimary) || $imageRole === 'main';

        if ($isPrimary) {
            ProductMedia::query()
                ->where('product_family_id', $family->id)
                ->where('product_id', $product?->id)
                ->update(['is_primary' => false]);
        }

        $media = ProductMedia::query()->create([
            'product_family_id' => $family->id,
            'product_id' => $product?->id,
            'catalogue_image_id' => null,
            'image_role' => $imageRole,
            'source_type' => 'phone_camera',
            'source_label' => $job->source_label ?: 'Phone camera',
            'usage_context' => $job->usage_context ?: 'all',
            'external_url' => null,
            'alt_text' => $imageName,
            'notes' => $job->notes,
            'sort_order' => $this->nextRetailSortOrder($family->id, $product?->id),
            'is_offline_ready' => true,
            'is_primary' => $isPrimary,
        ] + $stored);

        return ['type' => 'product_media', 'id' => $media->id];
    }

    /**
     * @return array{type:string,id:int}
     */
    private function replaceRetailImage(MobileCaptureJob $job, mixed $file): array
    {
        $media = ProductMedia::query()->with('family', 'product')->findOrFail($job->target_id);
        $family = $media->family;

        if (! $family) {
            throw new \RuntimeException('Retail product target was not found.');
        }

        $imageRole = $job->image_role ?: $media->image_role ?: 'main';
        $targetName = $media->product?->name ?? $family->family_name;
        $imageName = ProductImageNamer::displayName($targetName, $imageRole);
        $directory = ProductImageNamer::retailDirectory($family, $media->product);
        $stored = $this->storeUploadedFile(
            $file,
            $directory,
            $imageName,
            applyWatermark: $this->shouldApplyRetailWatermarkForRole($imageRole),
        );

        $oldDisk = $media->storage_disk;
        $oldPath = $media->storage_path;
        $canDeleteOldLocalFile = $media->catalogue_image_id === null && $oldDisk && $oldPath;
        $isPrimary = $job->is_primary || $media->is_primary || $imageRole === 'main';

        if ($isPrimary) {
            $this->clearRetailPrimarySiblings($media->product_family_id, $media->product_id, $media->id);
        }

        $media->update([
            'catalogue_image_id' => null,
            'image_role' => $imageRole,
            'source_type' => 'phone_camera',
            'source_label' => $job->source_label ?: 'Phone camera',
            'usage_context' => $job->usage_context ?: $media->usage_context ?: 'all',
            'external_url' => null,
            'alt_text' => $imageName,
            'notes' => $job->notes,
            'is_offline_ready' => true,
            'is_primary' => $isPrimary,
        ] + $stored);

        if ($canDeleteOldLocalFile && ($stored['storage_path'] ?? null) !== $oldPath) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        $this->ensureRetailPrimaryExists($media->product_family_id, $media->product_id);

        return ['type' => 'product_media', 'id' => $media->id];
    }

    /**
     * @return array{type:string,id:int}
     */
    private function storeCatalogueImage(MobileCaptureJob $job, mixed $file, ?bool $isPrimaryOverride = null, ?int $photoNumber = null): array
    {
        $targetType = (string) $job->target_type;
        $target = $this->resolveCatalogueTarget($targetType, (int) $job->target_id);
        $imageRole = $job->image_role ?: 'main';
        $targetName = $this->catalogueTargetImageBaseName($target, $targetType);
        $imageName = $this->imageDisplayName($targetName, $imageRole, $photoNumber);
        $directory = ProductImageNamer::catalogueDirectory($targetType, (int) $job->target_id, $targetName);
        $stored = $this->storeUploadedFile(
            $file,
            $directory,
            $imageName,
            applyWatermark: $this->shouldApplyCatalogueWatermarkForRole($imageRole, $targetType),
        );

        $isPrimary = $isPrimaryOverride ?? $job->is_primary;
        if ($isPrimary) {
            $target->images()->update(['is_primary' => false]);
        }

        /** @var CatalogueImage $image */
        $image = $target->images()->create([
            'image_role' => $imageRole,
            'notes' => $job->notes,
            'is_primary' => $isPrimary,
            'uploaded_by' => null,
            'external_url' => null,
        ] + $stored);

        return ['type' => 'catalogue_image', 'id' => $image->id];
    }

    /**
     * @return array{type:string,id:int}
     */
    private function storeIntakeImage(MobileCaptureJob $job, mixed $file, ?bool $isPrimaryOverride = null, ?int $photoNumber = null): array
    {
        $intake = HairExtensionIntake::query()->findOrFail($job->target_id);
        $imageRole = $job->image_role ?: 'evidence';
        $targetName = $this->intakeImageBaseName($intake);
        $imageName = $this->imageDisplayName($targetName, $imageRole, $photoNumber);
        $directory = 'hair-extension-intake/evidence/'.$intake->id.'-'.Str::slug(Str::limit($targetName, 80, ''));
        $stored = $this->storeUploadedFile($file, $directory, $imageName);

        $hasExistingPhotos = $intake->photos()->exists();
        $isPrimary = ($isPrimaryOverride ?? $job->is_primary)
            || ! $hasExistingPhotos
            || ($imageRole === 'main' && $photoNumber === null);

        if ($isPrimary) {
            $intake->photos()->update(['is_primary' => false]);
        }

        /** @var HairExtensionIntakePhoto $photo */
        $photo = $intake->photos()->create([
            'image_role' => $imageRole,
            'source_label' => $job->source_label ?: 'Phone camera',
            'notes' => $job->notes,
            'source_type' => 'phone_camera',
            'sort_order' => ((int) $intake->photos()->max('sort_order')) + 1,
            'is_primary' => $isPrimary,
        ] + $stored);

        return ['type' => 'hair_extension_intake_photo', 'id' => $photo->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function storeUploadedFile(mixed $file, string $directory, string $imageName, bool $applyWatermark = true): array
    {
        $extension = $file->guessExtension() ?: $file->extension() ?: 'jpg';
        $filename = ProductImageNamer::uniqueFilename($directory, $imageName, $extension);
        $path = $file->storeAs($directory, $filename, 'public');

        if ($applyWatermark) {
            try {
                app(ImageWatermarker::class)->applyToPublicStoragePath($path);
            } catch (\Throwable $exception) {
                Storage::disk('public')->delete($path);
                throw $exception;
            }
        }

        return [
            'storage_disk' => 'public',
            'storage_path' => $path,
            'original_filename' => $filename,
            'mime_type' => Storage::disk('public')->mimeType($path) ?: $file->getClientMimeType(),
            'file_size' => Storage::disk('public')->size($path),
        ];
    }

    private function shouldApplyCatalogueWatermarkForRole(string $imageRole, ?string $targetType = null): bool
    {
        $role = strtolower(trim($imageRole));

        if (! in_array($role, ['variant', 'variant_reference'], true)) {
            return true;
        }

        return $targetType !== 'brand_catalogue_variant_option';
    }

    private function shouldApplyRetailWatermarkForRole(string $imageRole): bool
    {
        return in_array($imageRole, ['main', 'gallery'], true);
    }

    private function imageDisplayName(string $targetName, string $imageRole, ?int $photoNumber = null): string
    {
        $imageName = ProductImageNamer::displayName($targetName, $imageRole);

        return $photoNumber ? $imageName.' '.$photoNumber : $imageName;
    }

    private function intakeImageBaseName(HairExtensionIntake $intake): string
    {
        $brand = $this->nullTrim($intake->brand_name);
        $product = $this->nullTrim($intake->style_name)
            ?: $this->nullTrim($intake->observed_product_name ?? null)
            ?: $this->nullTrim($intake->product_type_name ?? null)
            ?: 'Product observation';

        if ($brand && Str::startsWith(Str::lower($product), Str::lower($brand))) {
            return ProductImageNamer::cleanHumanText($product);
        }

        return ProductImageNamer::cleanHumanText(trim(collect([$brand, $product])->filter()->implode(' ')) ?: 'Hair extension intake');
    }

    private function nextRetailSortOrder(int $familyId, ?int $productId): int
    {
        return ((int) ProductMedia::query()
            ->where('product_family_id', $familyId)
            ->where('product_id', $productId)
            ->max('sort_order')) + 1;
    }

    private function clearRetailPrimarySiblings(int $familyId, ?int $productId, ?int $exceptId = null): void
    {
        ProductMedia::query()
            ->where('product_family_id', $familyId)
            ->where('product_id', $productId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_primary' => false]);
    }

    private function ensureRetailPrimaryExists(int $familyId, ?int $productId): void
    {
        $hasPrimary = ProductMedia::query()
            ->where('product_family_id', $familyId)
            ->where('product_id', $productId)
            ->where('is_primary', true)
            ->exists();

        if ($hasPrimary) {
            return;
        }

        ProductMedia::query()
            ->where('product_family_id', $familyId)
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first()
            ?->update(['is_primary' => true]);
    }

    private function retailMediaTargetLabel(ProductMedia $media): string
    {
        $targetName = $media->product?->name ?? $media->family?->family_name ?? 'Product image';
        $role = $media->image_role ? ucfirst(str_replace('_', ' ', $media->image_role)) : 'Image';

        return ProductImageNamer::cleanHumanText($targetName.' - '.$role);
    }

    private function resolveCatalogueTarget(string $type, int $id): Model
    {
        return match ($type) {
            'family' => CatalogueFamily::query()->findOrFail($id),
            'type' => CatalogueType::query()->findOrFail($id),
            'variant' => CatalogueVariant::query()->findOrFail($id),
            'import_record' => ImportRecord::query()->findOrFail($id),
            'brand_catalogue_style' => BrandCatalogueStyle::query()->findOrFail($id),
            'brand_catalogue_sku' => BrandCatalogueSku::query()->findOrFail($id),
            'brand_catalogue_variant_option' => BrandCatalogueVariantOption::query()->findOrFail($id),
            default => throw new \InvalidArgumentException('Invalid catalogue target.'),
        };
    }

    private function catalogueTargetImageBaseName(Model $target, string $type): string
    {
        if ($target instanceof BrandCatalogueSku || $target instanceof BrandCatalogueStyle) {
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

    /**
     * @return array<string, mixed>
     */
    private function jobPayload(MobileCaptureJob $job, ?string $accessToken = null): array
    {
        return [
            'token' => $job->token,
            'status' => $job->status,
            'destination_type' => $job->destination_type,
            'target_type' => $job->target_type,
            'target_id' => $job->target_id,
            'target_label' => $job->target_label,
            'image_role' => $job->image_role,
            'usage_context' => $job->usage_context,
            'source_label' => $job->source_label,
            'notes' => $job->notes,
            'is_primary' => $job->is_primary,
            'error_message' => $job->error_message,
            'result_type' => $job->result_type,
            'result_id' => $job->result_id,
            'created_at' => $job->created_at?->toDateTimeString(),
            'completed_at' => $job->completed_at?->toDateTimeString(),
            'upload_url' => $accessToken ? route('mobile-capture.jobs.upload', [$accessToken, $job->token]) : null,
            'cancel_url' => $accessToken ? route('mobile-capture.jobs.cancel', [$accessToken, $job->token]) : null,
            'allows_multiple_photos' => $this->jobAllowsMultiplePhotos($job),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function uploadedPhotos(Request $request): array
    {
        $files = [];

        if ($request->hasFile('photos')) {
            $photoFiles = $request->file('photos');
            $files = array_values(is_array($photoFiles) ? $photoFiles : [$photoFiles]);
        }

        if ($request->hasFile('photo')) {
            $files[] = $request->file('photo');
        }

        return array_values(array_filter($files));
    }

    private function jobAllowsMultiplePhotos(MobileCaptureJob $job): bool
    {
        if ($job->destination_type === 'intake') {
            return true;
        }

        if ($job->destination_type === 'retail' && $job->target_type === 'retail_media') {
            return false;
        }

        return in_array((string) $job->image_role, self::MULTI_PHOTO_ROLES, true);
    }

    private function preferredPhoneUrl(Request $request, MobileCaptureSetting $settings, MobileCaptureJob $job): string
    {
        $scheme = $request->getScheme() ?: 'http';
        $port = $request->getPort();
        $portPart = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443) ? '' : ':'.$port;
        $basePath = rtrim($request->getBaseUrl(), '/');
        $ip = $this->localIpv4Addresses()[0] ?? null;

        if ($ip) {
            return "{$scheme}://{$ip}{$portPart}{$basePath}/mobile-capture/{$settings->access_token}?job={$job->token}";
        }

        return URL::route('mobile-capture.phone', $settings->access_token).'?job='.$job->token;
    }

    private function phoneIsConnected(MobileCaptureSetting $settings): bool
    {
        return $settings->is_enabled
            && $settings->last_seen_at
            && $settings->last_seen_at->diffInSeconds(now(), true) <= 20;
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

    private function nullTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
