<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\CatalogueFamily;
use App\Models\CatalogueType;
use App\Models\CatalogueVariant;
use App\Models\DuplicateCandidate;
use App\Models\ImportBatch;
use App\Models\ImportRecord;
use App\Models\ImportRecordLink;
use App\Models\ReviewAction;
use App\Models\Subcategory;
use App\Models\User;
use App\Support\JsonPayloadCleaner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExternalJsonImportService
{
    public function __construct(
        private readonly ShopMatchService $shopMatchService,
        private readonly JsonPayloadCleaner $jsonPayloadCleaner,
    ) {
    }

    /**
     * @param  array<int, UploadedFile>  $shopPhotos
     */
    public function import(
        string $payload,
        string $channel = 'paste',
        ?string $originalFilename = null,
        ?string $sourceLabel = null,
        ?string $notes = null,
        array $shopPhotos = [],
        ?User $actor = null,
    ): ImportBatch {
        $preparedPayload = $this->jsonPayloadCleaner->clean($payload);
        $payload = $preparedPayload['cleaned_payload'];
        $decoded = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::withMessages([
                'json_payload' => 'The JSON could not be decoded even after auto-cleaning: '.json_last_error_msg(),
            ]);
        }

        $items = $this->normalizeItems($decoded);

        if ($items === []) {
            throw ValidationException::withMessages([
                'json_payload' => 'The payload did not contain any draft items to import.',
            ]);
        }

        if ($shopPhotos !== [] && count($items) > 1) {
            throw ValidationException::withMessages([
                'shop_photos' => 'Shop photo upload is limited to single-record imports so the evidence stays traceable.',
            ]);
        }

        return DB::transaction(function () use ($items, $channel, $originalFilename, $sourceLabel, $notes, $shopPhotos, $actor) {
            $batch = ImportBatch::query()->create([
                'batch_uuid' => (string) Str::uuid(),
                'import_channel' => $channel,
                'original_filename' => $originalFilename,
                'source_label' => $sourceLabel,
                'status' => 'received',
                'total_records' => count($items),
                'accepted_records' => 0,
                'warning_records' => 0,
                'rejected_records' => 0,
                'imported_by' => $actor?->id,
                'notes' => $notes,
            ]);

            $accepted = 0;
            $warningCount = 0;

            foreach ($items as $index => $item) {
                $normalized = $this->normalizeItem($item);
                $warnings = $this->collectWarnings($normalized);
                $rawJson = json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

                $record = ImportRecord::query()->create([
                    'import_batch_id' => $batch->id,
                    'external_reference' => $normalized['external_reference'],
                    'status' => $warnings === [] ? 'parsed' : 'parsed_with_warnings',
                    'raw_json' => $rawJson,
                    'normalized_json' => $normalized,
                    'payload_hash' => hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $rawJson),
                    'import_confidence' => $normalized['confidence'],
                    'parse_warnings' => $warnings,
                    'import_notes' => $normalized['notes'],
                    'imported_by' => $actor?->id,
                ]);

                $family = $this->stageFamily($normalized, $record, $actor);

                if ($family) {
                    $record->update([
                        'target_family_id' => $family->id,
                        'status' => $warnings === [] ? 'staged' : 'parsed_with_warnings',
                        'staged_at' => now(),
                    ]);

                    $this->recordImportLink($record, $family, 'family');
                    $accepted++;

                    if ($warnings !== []) {
                        $warningCount++;
                    }
                }

                if ($shopPhotos !== [] && $index === 0) {
                    $this->storeShopPhotos($record, $shopPhotos, $actor);
                }
            }

            $batch->update([
                'status' => $warningCount > 0 ? 'partially_imported' : 'completed',
                'accepted_records' => $accepted,
                'warning_records' => $warningCount,
            ]);

            return $batch->fresh(['importRecords']);
        });
    }

    /**
     * @return array{cleaned_payload: string, changed: bool, cleanup_notes: array<int, string>}
     */
    public function previewPayloadCleanup(string $payload): array
    {
        return $this->jsonPayloadCleaner->clean($payload);
    }

    public function inferSourceLabel(string $payload): ?string
    {
        $decoded = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return null;
        }

        $pictureIds = $this->collectPictureIds($decoded);

        if ($pictureIds === []) {
            return null;
        }

        if (count($pictureIds) === 1) {
            return $pictureIds[0];
        }

        return $pictureIds[0].' + '.(count($pictureIds) - 1).' more';
    }

    /**
     * @param  mixed  $decoded
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'json_payload' => 'The top-level JSON payload must be an object or an array.',
            ]);
        }

        if ($this->isVisionPhotoListing($decoded)) {
            return $this->expandVisionPhotoListing($decoded);
        }

        if (isset($decoded['photos'])) {
            if (! is_array($decoded['photos'])) {
                throw ValidationException::withMessages([
                    'json_payload' => 'If the payload uses a photos wrapper, photos must be an array.',
                ]);
            }

            return $this->expandVisionWrappedPhotos($decoded['photos']);
        }

        if (array_is_list($decoded)) {
            $items = [];

            foreach ($decoded as $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach ($this->expandVisionPhotoListingIfNeeded($item) as $expandedItem) {
                    $items[] = $expandedItem;
                }
            }

            return $items;
        }

        if (isset($decoded['items'])) {
            if (! is_array($decoded['items'])) {
                throw ValidationException::withMessages([
                    'json_payload' => 'If the payload uses an items wrapper, items must be an array.',
                ]);
            }

            $items = [];

            foreach ($decoded['items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach ($this->expandVisionPhotoListingIfNeeded($item) as $expandedItem) {
                    $items[] = $expandedItem;
                }
            }

            return $items;
        }

        return [$decoded];
    }

    /**
     * @param  array<int, mixed>  $photos
     * @return array<int, array<string, mixed>>
     */
    private function expandVisionWrappedPhotos(array $photos): array
    {
        $items = [];

        foreach ($photos as $photo) {
            if (! is_array($photo)) {
                continue;
            }

            foreach ($this->expandVisionPhotoListingIfNeeded($photo) as $expandedItem) {
                $items[] = $expandedItem;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, array<string, mixed>>
     */
    private function expandVisionPhotoListingIfNeeded(array $item): array
    {
        if ($this->isVisionPhotoListing($item)) {
            return $this->expandVisionPhotoListing($item);
        }

        return [$item];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function expandVisionPhotoListing(array $payload): array
    {
        $pictureId = $this->stringOrNull($payload['picture_id'] ?? $payload['photo_id'] ?? null);
        $products = $payload['products'] ?? [];

        if (! is_array($products)) {
            throw ValidationException::withMessages([
                'json_payload' => 'products must be an array when using the picture_id Vision payload format.',
            ]);
        }

        return collect($products)
            ->filter(fn ($product) => is_array($product))
            ->map(function (array $product, int $index) use ($pictureId) {
                $notes = $this->combineNotes([
                    $this->stringOrNull($product['notes'] ?? null),
                    $pictureId ? "Observed in {$pictureId}." : null,
                ]);

                return array_filter([
                    'import_stage' => 'picture_scan',
                    'picture_id' => $pictureId,
                    'external_reference' => $pictureId ? "{$pictureId}:".($index + 1) : null,
                    'brand_name' => $this->stringOrNull($product['brand'] ?? $product['brand_name'] ?? null),
                    'product_family_name' => $this->stringOrNull($product['product_name'] ?? $product['product_family_name'] ?? null),
                    'status' => 'imported',
                    'confidence' => $product['confidence'] ?? null,
                    'notes' => $notes,
                    'observation_meta' => array_filter([
                        'picture_id' => $pictureId,
                        'visible_text' => is_array($product['visible_text'] ?? null) ? array_values(array_filter($product['visible_text'], fn ($value) => is_scalar($value) && trim((string) $value) !== '')) : [],
                        'observed_count' => isset($product['observed_count']) && is_numeric($product['observed_count']) ? (int) $product['observed_count'] : null,
                        'confidence_reason' => $this->stringOrNull($product['confidence_reason'] ?? null),
                    ], fn ($value) => $value !== null && $value !== []),
                ], fn ($value) => $value !== null);
            })
            ->filter(fn (array $item) => filled($item['brand_name'] ?? null) || filled($item['product_family_name'] ?? null))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isVisionPhotoListing(array $item): bool
    {
        return (isset($item['picture_id']) || isset($item['photo_id'])) && array_key_exists('products', $item);
    }

    /**
     * @param  mixed  $decoded
     * @return array<int, string>
     */
    private function collectPictureIds(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        if ($this->isVisionPhotoListing($decoded)) {
            return array_values(array_filter([
                $this->stringOrNull($decoded['picture_id'] ?? $decoded['photo_id'] ?? null),
            ]));
        }

        if (isset($decoded['photos']) && is_array($decoded['photos'])) {
            return collect($decoded['photos'])
                ->filter(fn ($photo) => is_array($photo) && $this->isVisionPhotoListing($photo))
                ->map(fn ($photo) => $this->stringOrNull($photo['picture_id'] ?? $photo['photo_id'] ?? null))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (isset($decoded['items']) && is_array($decoded['items'])) {
            return collect($decoded['items'])
                ->flatMap(fn ($item) => $this->collectPictureIds($item))
                ->unique()
                ->values()
                ->all();
        }

        if (array_is_list($decoded)) {
            return collect($decoded)
                ->flatMap(fn ($item) => $this->collectPictureIds($item))
                ->unique()
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        $item = $this->adaptProductFinderItem($item);
        $productTypes = $item['product_types'] ?? [];

        if ($productTypes === [] && filled($item['product_type'] ?? null)) {
            $productTypes = [[
                'name' => trim((string) $item['product_type']),
                'description' => null,
                'notes' => 'Imported from top-level product_type field.',
                'variants' => [],
            ]];
        }

        return [
            'import_stage' => $this->stringOrNull($item['import_stage'] ?? null),
            'picture_id' => $this->stringOrNull($item['picture_id'] ?? $item['photo_id'] ?? null),
            'external_reference' => $this->stringOrNull($item['external_reference'] ?? null),
            'brand_name' => $this->stringOrNull($item['brand_name'] ?? $item['brand'] ?? null),
            'category_name' => $this->stringOrNull($item['category_name'] ?? $item['category'] ?? null),
            'subcategory_name' => $this->stringOrNull($item['subcategory_name'] ?? $item['subcategory'] ?? null),
            'product_family_name' => $this->stringOrNull($item['product_family_name'] ?? $item['family_name'] ?? null),
            'short_description' => $this->stringOrNull($item['short_description'] ?? null),
            'full_description' => $this->stringOrNull($item['full_description'] ?? $item['description'] ?? null),
            'status' => $this->stringOrNull($item['status'] ?? null),
            'confidence' => $this->normalizeConfidenceValue($item['confidence'] ?? null),
            'notes' => $this->stringOrNull($item['notes'] ?? null),
            'source_candidates' => $this->normalizeSources($item),
            'image_refs' => $this->normalizeImages($item),
            'product_types' => $this->normalizeProductTypes($productTypes),
            'variants' => $this->normalizeVariants($item['variants'] ?? []),
            'shop_match' => $this->normalizeShopMatch($item['shop_match'] ?? null),
            'observation_meta' => is_array($item['observation_meta'] ?? null) ? $item['observation_meta'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<int, string>
     */
    private function collectWarnings(array $normalized): array
    {
        $warnings = [];
        $isPictureScan = ($normalized['import_stage'] ?? null) === 'picture_scan';

        if (! filled($normalized['brand_name'])) {
            $warnings[] = 'Missing brand_name';
        }

        if (! $isPictureScan && ! filled($normalized['category_name'])) {
            $warnings[] = 'Missing category_name';
        }

        if (! filled($normalized['product_family_name'])) {
            $warnings[] = 'Missing product_family_name';
        }

        if (! $isPictureScan && $normalized['source_candidates'] === []) {
            $warnings[] = 'No source candidates provided';
        }

        if (! $isPictureScan && $normalized['variants'] === [] && $normalized['product_types'] === []) {
            $warnings[] = 'No variants or product types provided';
        }

        $categoryName = $normalized['category_name'];

        if ($categoryName && ! Category::query()->where('slug', Str::slug($categoryName))->exists()) {
            $warnings[] = 'Unknown category_name value';
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function stageFamily(array $normalized, ImportRecord $record, ?User $actor): ?CatalogueFamily
    {
        if (! $this->hasAnyMeaningfulData($normalized)) {
            return null;
        }

        $brand = $this->resolveBrand($normalized['brand_name'], $actor);
        $category = $this->resolveCategory($normalized['category_name']);
        $subcategory = $this->resolveSubcategory($category, $normalized['subcategory_name']);
        $familyName = $normalized['product_family_name'] ?: 'Unidentified Draft';

        $family = CatalogueFamily::query()->create([
            'brand_id' => $brand?->id,
            'category_id' => $category?->id,
            'subcategory_id' => $subcategory?->id,
            'product_family_name' => $familyName,
            'slug' => $this->makeUniqueSlug(CatalogueFamily::class, $familyName, ['brand_id' => $brand?->id]),
            'short_description' => $normalized['short_description'],
            'full_description' => $normalized['full_description'],
            'import_confidence' => $normalized['confidence'],
            'status' => $this->normalizeFamilyStatus($normalized['status'] ?? null) ?? $this->determineFamilyStatus($normalized),
            'needs_source_verification' => true,
            'duplicate_flag' => false,
            'imported_json_snapshot' => $normalized,
            'notes' => $normalized['notes'],
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);

        ReviewAction::query()->create([
            'reviewable_type' => $family->getMorphClass(),
            'reviewable_id' => $family->id,
            'action' => 'submit',
            'to_status' => $family->status,
            'notes' => 'Created from external JSON import.',
            'metadata' => ['import_record_id' => $record->id],
            'acted_by' => $actor?->id,
        ]);

        $this->stageSources($family, $normalized['source_candidates'], $actor);
        $this->stageImages($record, $family, $normalized['image_refs'], $actor);

        if (is_array($normalized['shop_match'])) {
            $this->shopMatchService->sync($family, $normalized['shop_match'], $actor);
        }

        foreach ($normalized['product_types'] as $typePayload) {
            $type = $this->stageType($family, $typePayload, $record, $actor);

            foreach ($typePayload['variants'] as $variantPayload) {
                $this->stageVariant($family, $type, $variantPayload, $record, $actor);
            }
        }

        foreach ($normalized['variants'] as $variantPayload) {
            $this->stageVariant($family, null, $variantPayload, $record, $actor);
        }

        $this->flagDuplicateFamilies($family, $actor);

        return $family;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCandidates
     */
    private function stageSources(Model $sourceable, array $sourceCandidates, ?User $actor): void
    {
        foreach ($sourceCandidates as $candidate) {
            $sourceable->sources()->create([
                'role' => $candidate['role'] ?? 'secondary',
                'source_type' => $candidate['source_type'] ?? 'trusted_retailer',
                'trust_status' => $candidate['trust_status'] ?? 'unverified',
                'url' => $candidate['url'] ?? null,
                'title' => $candidate['title'] ?? null,
                'notes' => $candidate['notes'] ?? null,
                'confidence' => $this->normalizeConfidenceValue($candidate['confidence'] ?? null),
                'is_primary' => (bool) ($candidate['is_primary'] ?? false) || (($candidate['role'] ?? null) === 'primary'),
                'is_verified' => (bool) ($candidate['is_verified'] ?? false) || (($candidate['trust_status'] ?? null) === 'verified'),
                'verified_at' => ((bool) ($candidate['is_verified'] ?? false) || (($candidate['trust_status'] ?? null) === 'verified')) ? now() : null,
                'verified_by' => ((bool) ($candidate['is_verified'] ?? false) || (($candidate['trust_status'] ?? null) === 'verified')) ? $actor?->id : null,
                'created_by' => $actor?->id,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $imageRefs
     */
    private function stageImages(ImportRecord $record, CatalogueFamily $family, array $imageRefs, ?User $actor): void
    {
        foreach ($imageRefs as $imageRef) {
            $target = in_array($imageRef['image_role'], ['shop_photo', 'evidence'], true) ? $record : $family;

            $target->images()->create([
                'image_role' => $imageRef['image_role'] ?? 'source_image',
                'external_url' => $imageRef['external_url'] ?? null,
                'notes' => $imageRef['notes'] ?? null,
                'is_primary' => (bool) ($imageRef['is_primary'] ?? false),
                'uploaded_by' => $actor?->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stageType(CatalogueFamily $family, array $payload, ImportRecord $record, ?User $actor): CatalogueType
    {
        $type = $family->types()->create([
            'name' => $payload['name'] ?: 'Imported Type',
            'slug' => $this->makeUniqueSlug(CatalogueType::class, $payload['name'] ?: 'Imported Type', ['catalogue_family_id' => $family->id]),
            'description' => $payload['description'] ?? null,
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'status' => $this->normalizeTypeStatus($payload['status'] ?? null) ?? 'draft',
            'notes' => $payload['notes'] ?? null,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);

        $this->recordImportLink($record, $type, 'type');

        if (is_array($payload['shop_match'] ?? null)) {
            $this->shopMatchService->sync($type, $payload['shop_match'], $actor);
        }

        if (($payload['source_candidates'] ?? []) !== []) {
            $this->stageSources($type, $payload['source_candidates'], $actor);
        }

        if (($payload['image_refs'] ?? []) !== []) {
            foreach ($payload['image_refs'] as $imageRef) {
                $type->images()->create([
                    'image_role' => $imageRef['image_role'] ?? 'source_image',
                    'external_url' => $imageRef['external_url'] ?? null,
                    'notes' => $imageRef['notes'] ?? null,
                    'is_primary' => (bool) ($imageRef['is_primary'] ?? false),
                    'uploaded_by' => $actor?->id,
                ]);
            }
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stageVariant(CatalogueFamily $family, ?CatalogueType $type, array $payload, ImportRecord $record, ?User $actor): CatalogueVariant
    {
        $variant = $family->variants()->create([
            'catalogue_type_id' => $type?->id,
            'variant_display_name' => $payload['variant_display_name'] ?: 'Imported Variant',
            'color_code' => $payload['color_code'] ?? null,
            'color_name' => $payload['color_name'] ?? null,
            'size' => $payload['size'] ?? null,
            'length' => $payload['length'] ?? null,
            'bundle_count' => $payload['bundle_count'] ?? null,
            'pack_size' => $payload['pack_size'] ?? null,
            'texture' => $payload['texture'] ?? null,
            'shade' => $payload['shade'] ?? null,
            'finish' => $payload['finish'] ?? null,
            'style' => $payload['style'] ?? null,
            'weight' => $payload['weight'] ?? null,
            'volume' => $payload['volume'] ?? null,
            'attributes_json' => $payload['attributes'] ?? [],
            'import_confidence' => $payload['confidence'] ?? null,
            'status' => $this->normalizeVariantStatus($payload['status'] ?? null) ?? ($payload['source_candidates'] !== [] ? 'matched' : 'draft'),
            'notes' => $payload['notes'] ?? null,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);

        $this->recordImportLink($record, $variant, 'variant');

        if (($payload['source_candidates'] ?? []) !== []) {
            $this->stageSources($variant, $payload['source_candidates'], $actor);
        } elseif (filled($payload['source_url'] ?? null)) {
            $this->stageSources($variant, [[
                'role' => 'variant_reference',
                'source_type' => 'trusted_retailer',
                'trust_status' => 'unverified',
                'url' => $payload['source_url'],
                'title' => null,
                'notes' => 'Imported variant source URL.',
                'confidence' => $payload['confidence'] ?? null,
                'is_primary' => false,
            ]], $actor);
        }

        if (($payload['image_refs'] ?? []) !== []) {
            foreach ($payload['image_refs'] as $imageRef) {
                $variant->images()->create([
                    'image_role' => $imageRef['image_role'] ?? 'variant_image',
                    'external_url' => $imageRef['external_url'] ?? null,
                    'notes' => $imageRef['notes'] ?? null,
                    'is_primary' => (bool) ($imageRef['is_primary'] ?? false),
                    'uploaded_by' => $actor?->id,
                ]);
            }
        } elseif (filled($payload['image_url'] ?? null)) {
            $variant->images()->create([
                'image_role' => 'variant_image',
                'external_url' => $payload['image_url'],
                'uploaded_by' => $actor?->id,
            ]);
        }

        if (is_array($payload['shop_match'] ?? null)) {
            $this->shopMatchService->sync($variant, $payload['shop_match'], $actor);
        }

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function determineFamilyStatus(array $normalized): string
    {
        if (! filled($normalized['product_family_name'])) {
            return 'imported';
        }

        if ($normalized['source_candidates'] !== []) {
            return 'matched';
        }

        return 'identified';
    }

    private function normalizeFamilyStatus(?string $status): ?string
    {
        return in_array($status, \App\Support\CatalogueOptions::familyStatuses(), true) ? $status : null;
    }

    private function normalizeTypeStatus(?string $status): ?string
    {
        return in_array($status, \App\Support\CatalogueOptions::typeStatuses(), true) ? $status : null;
    }

    private function normalizeVariantStatus(?string $status): ?string
    {
        return in_array($status, \App\Support\CatalogueOptions::variantStatuses(), true) ? $status : null;
    }

    private function resolveBrand(?string $name, ?User $actor): ?Brand
    {
        if (! filled($name)) {
            return null;
        }

        return Brand::query()->firstOrCreate(
            ['slug' => Str::slug($name)],
            [
                'name' => $name,
                'notes' => 'Created automatically from external JSON import.',
                'is_active' => true,
                'is_generic' => false,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ],
        );
    }

    private function resolveCategory(?string $name): ?Category
    {
        if (! filled($name)) {
            return null;
        }

        return Category::query()
            ->where('slug', Str::slug($name))
            ->orWhere('name', $name)
            ->first();
    }

    private function resolveSubcategory(?Category $category, ?string $name): ?Subcategory
    {
        if (! $category || ! filled($name)) {
            return null;
        }

        return Subcategory::query()->firstOrCreate(
            ['category_id' => $category->id, 'slug' => Str::slug($name)],
            [
                'name' => $name,
                'description' => 'Created automatically from external JSON import.',
                'is_active' => true,
                'sort_order' => 0,
            ],
        );
    }

    private function makeUniqueSlug(string $modelClass, string $value, array $scopedWhere = []): string
    {
        $base = Str::slug($value) ?: 'item';
        $slug = $base;
        $suffix = 2;

        while ($modelClass::query()->where($scopedWhere)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function recordImportLink(ImportRecord $record, Model $linkable, string $role): void
    {
        ImportRecordLink::query()->firstOrCreate([
            'import_record_id' => $record->id,
            'linkable_type' => $linkable->getMorphClass(),
            'linkable_id' => $linkable->getKey(),
        ], [
            'relation_role' => $role,
        ]);
    }

    private function flagDuplicateFamilies(CatalogueFamily $family, ?User $actor): void
    {
        $normalizedName = Str::slug($family->product_family_name);

        $existingFamily = CatalogueFamily::query()
            ->whereKeyNot($family->id)
            ->where('brand_id', $family->brand_id)
            ->where(function ($query) use ($family, $normalizedName) {
                $query->whereRaw('LOWER(product_family_name) = ?', [Str::lower($family->product_family_name)])
                    ->orWhere('slug', $normalizedName)
                    ->orWhere('slug', 'like', $normalizedName.'-%');
            })
            ->first();

        if (! $existingFamily) {
            return;
        }

        DuplicateCandidate::query()->firstOrCreate([
            'left_family_id' => min($existingFamily->id, $family->id),
            'right_family_id' => max($existingFamily->id, $family->id),
        ], [
            'similarity_score' => 100,
            'match_basis' => [
                'rule' => 'same_brand_and_normalized_family_name',
                'brand_id' => $family->brand_id,
                'normalized_name' => $normalizedName,
            ],
            'status' => 'open',
            'reviewed_by' => $actor?->id,
        ]);

        $family->forceFill(['duplicate_flag' => true])->save();
        $existingFamily->forceFill(['duplicate_flag' => true])->save();
    }

    /**
     * @param  array<int, UploadedFile>  $shopPhotos
     */
    private function storeShopPhotos(ImportRecord $record, array $shopPhotos, ?User $actor): void
    {
        foreach ($shopPhotos as $photo) {
            $path = $photo->store('catalogue-imports/'.$record->import_batch_id, 'public');

            $record->images()->create([
                'image_role' => 'shop_photo',
                'storage_disk' => 'public',
                'storage_path' => $path,
                'original_filename' => $photo->getClientOriginalName(),
                'mime_type' => $photo->getClientMimeType(),
                'file_size' => $photo->getSize(),
                'uploaded_by' => $actor?->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function hasAnyMeaningfulData(array $normalized): bool
    {
        return collect([
            $normalized['brand_name'],
            $normalized['category_name'],
            $normalized['subcategory_name'],
            $normalized['product_family_name'],
            $normalized['short_description'],
            $normalized['full_description'],
            $normalized['notes'],
        ])->contains(fn ($value) => filled($value))
            || $normalized['source_candidates'] !== []
            || $normalized['image_refs'] !== []
            || $normalized['product_types'] !== []
            || $normalized['variants'] !== [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function adaptProductFinderItem(array $item): array
    {
        if (! isset($item['family']) || ! is_array($item['family'])) {
            return $item;
        }

        $family = $item['family'];
        $types = is_array($item['types'] ?? null) ? $item['types'] : [];
        $variants = is_array($item['variants'] ?? null) ? $item['variants'] : [];
        $sources = is_array($item['sources'] ?? null) ? $item['sources'] : [];
        $images = is_array($item['images'] ?? null) ? $item['images'] : [];

        $typeMap = collect($types)->map(function ($type, $index) use ($sources, $images, $item) {
            if (! is_array($type)) {
                return null;
            }

            $name = $this->stringOrNull($type['name'] ?? $type['type_name'] ?? null);

            return [
                'name' => $name,
                'description' => $this->stringOrNull($type['description'] ?? null),
                'notes' => $this->combineNotes([
                    $this->stringOrNull($type['notes'] ?? null),
                    $this->stringOrNull($type['finder_confidence_reason'] ?? null),
                ]),
                'sort_order' => (int) ($type['sort_order'] ?? $index),
                'status' => $this->stringOrNull($type['status'] ?? null),
                'shop_match' => $this->extractTargetedShopMatch($item['shop_match'] ?? null, 'type', $name),
                'source_candidates' => $this->extractTargetedSources($sources, 'type', $name),
                'image_refs' => $this->extractTargetedImages($images, 'type', $name),
                'variants' => [],
            ];
        })->filter()->values();

        $normalizedVariants = collect($variants)->map(function ($variant) use ($sources, $images, $item) {
            if (! is_array($variant)) {
                return $variant;
            }

            $variantName = $this->stringOrNull($variant['variant_display_name'] ?? $variant['name'] ?? null);
            $typeName = $this->stringOrNull($variant['type_name'] ?? null);

            $variantPayload = [
                'variant_display_name' => $variantName,
                'color_code' => $variant['color_code'] ?? null,
                'color_name' => $variant['color_name'] ?? null,
                'size' => $variant['size'] ?? null,
                'length' => $variant['length'] ?? null,
                'pack_size' => $variant['pack_size'] ?? null,
                'bundle_count' => $variant['bundle_count'] ?? null,
                'texture' => $variant['texture'] ?? null,
                'shade' => $variant['shade'] ?? null,
                'finish' => $variant['finish'] ?? null,
                'style' => $variant['style'] ?? null,
                'weight' => $variant['weight'] ?? null,
                'volume' => $variant['volume'] ?? null,
                'attributes_json' => $variant['attributes_json'] ?? null,
                'status' => $variant['status'] ?? null,
                'confidence' => $variant['finder_confidence'] ?? $variant['confidence'] ?? null,
                'notes' => $this->combineNotes([
                    $this->stringOrNull($variant['notes'] ?? null),
                    $this->stringOrNull($variant['finder_confidence_reason'] ?? null),
                ]),
                'source_candidates' => $this->extractTargetedSources($sources, 'variant', $variantName),
                'image_refs' => $this->extractTargetedImages($images, 'variant', $variantName),
                'shop_match' => $this->extractTargetedShopMatch($item['shop_match'] ?? null, 'variant', $variantName),
                '_type_name' => $typeName,
            ];

            if ($typeName === null) {
                unset($variantPayload['_type_name']);
            }

            return $variantPayload;
        })->values();

        $normalizedVariants->each(function ($variant) use ($typeMap) {
            if (! is_array($variant)) {
                return;
            }

            $typeName = $variant['_type_name'] ?? null;

            if (! $typeName) {
                return;
            }

            $type = $typeMap->firstWhere('name', $typeName);

            if ($type === null) {
                return;
            }

            $type['variants'][] = Arr::except($variant, ['_type_name']);
            $typeMap->transform(function ($candidate) use ($typeName, $type) {
                return $candidate['name'] === $typeName ? $type : $candidate;
            });
        });

        $familyNotes = $this->combineNotes([
            $this->stringOrNull($family['notes'] ?? null),
            filled($family['product_type'] ?? null) ? 'Product type: '.trim((string) $family['product_type']) : null,
            $this->stringOrNull($family['finder_confidence_reason'] ?? null),
            $this->stringOrNull($item['import_batch_note'] ?? null),
        ]);

        return array_filter([
            'external_reference' => $this->stringOrNull($item['external_reference'] ?? null),
            'brand_name' => $this->stringOrNull($family['brand_name'] ?? null),
            'category_name' => $this->stringOrNull($family['category_name'] ?? null),
            'subcategory_name' => $this->stringOrNull($family['subcategory_name'] ?? null),
            'product_family_name' => $this->stringOrNull($family['product_family_name'] ?? null),
            'product_type' => null,
            'short_description' => $this->stringOrNull($family['short_description'] ?? null),
            'full_description' => $this->stringOrNull($family['full_description'] ?? null),
            'status' => $this->stringOrNull($family['status'] ?? null),
            'confidence' => $family['finder_confidence'] ?? $family['confidence'] ?? null,
            'notes' => $familyNotes,
            'source_candidates' => $this->extractTargetedSources($sources, 'family', 'family'),
            'image_refs' => $this->extractTargetedImages($images, 'family', 'family'),
            'product_types' => $typeMap->values()->all(),
            'variants' => $normalizedVariants
                ->filter(fn ($variant) => ! is_array($variant) || ! filled($variant['_type_name'] ?? null))
                ->map(fn ($variant) => is_array($variant) ? Arr::except($variant, ['_type_name']) : $variant)
                ->values()
                ->all(),
            'shop_match' => $this->extractTargetedShopMatch($item['shop_match'] ?? null, 'family', 'family'),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function extractTargetedSources(array $sources, string $level, ?string $ref): array
    {
        return collect($sources)
            ->filter(fn ($source) => is_array($source) && $this->matchesTarget($source['target_level'] ?? 'family', $source['target_ref'] ?? 'family', $level, $ref))
            ->map(fn ($source) => [
                'role' => $this->normalizeSourceRole($source['role'] ?? null, $level),
                'source_type' => $this->normalizeSourceType($source['source_type'] ?? null),
                'trust_status' => $this->normalizeSourceTrustStatus($source['trust_status'] ?? null),
                'url' => $this->stringOrNull($source['url'] ?? null),
                'title' => $this->stringOrNull($source['title'] ?? null),
                'notes' => $this->stringOrNull($source['notes'] ?? null),
                'confidence' => $this->normalizeConfidenceValue($source['confidence'] ?? null),
                'is_primary' => (bool) ($source['is_primary'] ?? false) || $this->normalizeSourceRole($source['role'] ?? null, $level) === 'primary',
                'is_verified' => (bool) ($source['is_verified'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $images
     * @return array<int, array<string, mixed>>
     */
    private function extractTargetedImages(array $images, string $level, ?string $ref): array
    {
        return collect($images)
            ->filter(fn ($image) => is_array($image) && $this->matchesTarget($image['target_level'] ?? 'family', $image['target_ref'] ?? 'family', $level, $ref))
            ->map(fn ($image) => [
                'image_role' => $this->stringOrNull($image['image_role'] ?? null) ?? ($level === 'variant' ? 'variant_image' : 'source_image'),
                'external_url' => $this->stringOrNull($image['external_url'] ?? $image['url'] ?? null),
                'notes' => $this->stringOrNull($image['notes'] ?? null),
                'is_primary' => (bool) ($image['is_primary'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  mixed  $shopMatch
     * @return array<string, mixed>|null
     */
    private function extractTargetedShopMatch(mixed $shopMatch, string $level, ?string $ref): ?array
    {
        $matches = is_array($shopMatch) && array_is_list($shopMatch) ? $shopMatch : [$shopMatch];

        foreach ($matches as $match) {
            if (! is_array($match)) {
                continue;
            }

            if ($this->matchesTarget($match['target_level'] ?? 'family', $match['target_ref'] ?? 'family', $level, $ref)) {
                return $this->normalizeShopMatch($match);
            }
        }

        return null;
    }

    private function matchesTarget(mixed $targetLevel, mixed $targetRef, string $expectedLevel, ?string $expectedRef): bool
    {
        $normalizedLevel = $this->stringOrNull($targetLevel) ?? 'family';
        $normalizedRef = $this->stringOrNull($targetRef) ?? 'family';
        $expectedRef = $expectedRef ?? 'family';

        if ($normalizedLevel !== $expectedLevel) {
            return false;
        }

        return Str::lower($normalizedRef) === Str::lower($expectedRef);
    }

    private function normalizeSourceRole(mixed $role, string $level): string
    {
        $role = $this->stringOrNull($role);

        return match ($role) {
            'primary' => 'primary',
            'supporting' => $level === 'variant' ? 'variant_reference' : 'secondary',
            'variant_reference', 'image_reference', 'manual_reference', 'secondary' => $role,
            default => $level === 'variant' ? 'variant_reference' : 'secondary',
        };
    }

    private function normalizeSourceType(mixed $type): string
    {
        return match ($this->stringOrNull($type)) {
            'official_brand' => 'official_brand',
            'authorized_distributor' => 'authorized_distributor',
            'retailer', 'trusted_retailer' => 'trusted_retailer',
            'internal_manual' => 'internal_manual',
            default => 'trusted_retailer',
        };
    }

    private function normalizeSourceTrustStatus(mixed $status): string
    {
        return match ($this->stringOrNull($status)) {
            'verified' => 'verified',
            'pending_review' => 'pending_review',
            'trusted' => 'trusted',
            'rejected' => 'rejected',
            'internal_confirmed' => 'internal_confirmed',
            default => 'unverified',
        };
    }

    /**
     * @param  array<int, string|null>  $parts
     */
    private function combineNotes(array $parts): ?string
    {
        $notes = collect($parts)
            ->filter(fn ($part) => filled($part))
            ->map(fn ($part) => trim((string) $part))
            ->unique()
            ->values();

        return $notes->isEmpty() ? null : $notes->implode("\n\n");
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSources(array $item): array
    {
        $sources = $item['source_candidates'] ?? [];

        if ($sources === [] && filled($item['source_url'] ?? null)) {
            $sources = [[
                'role' => 'primary',
                'source_type' => $item['source_type'] ?? 'trusted_retailer',
                'trust_status' => 'unverified',
                'url' => $item['source_url'],
                'title' => null,
                'notes' => null,
                'confidence' => $item['confidence'] ?? null,
                'is_primary' => true,
            ]];
        }

        if (! is_array($sources)) {
            throw ValidationException::withMessages([
                'json_payload' => 'source_candidates must be an array when provided.',
            ]);
        }

        return collect($sources)
            ->filter(fn ($source) => is_array($source) || is_string($source))
            ->map(function ($source) {
                if (is_string($source)) {
                    return [
                        'role' => 'secondary',
                        'source_type' => 'trusted_retailer',
                        'trust_status' => 'unverified',
                        'url' => $source,
                        'title' => null,
                        'notes' => null,
                        'confidence' => null,
                        'is_primary' => false,
                        'is_verified' => false,
                    ];
                }

                return [
                    'role' => $this->stringOrNull($source['role'] ?? null) ?? 'secondary',
                    'source_type' => $this->stringOrNull($source['source_type'] ?? null) ?? 'trusted_retailer',
                    'trust_status' => $this->stringOrNull($source['trust_status'] ?? null) ?? 'unverified',
                    'url' => $this->stringOrNull($source['url'] ?? null),
                    'title' => $this->stringOrNull($source['title'] ?? null),
                    'notes' => $this->stringOrNull($source['notes'] ?? null),
                    'confidence' => $this->normalizeConfidenceValue($source['confidence'] ?? null),
                    'is_primary' => (bool) ($source['is_primary'] ?? false) || (($source['role'] ?? null) === 'primary'),
                    'is_verified' => (bool) ($source['is_verified'] ?? false),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, array<string, mixed>>
     */
    private function normalizeImages(array $item): array
    {
        $images = $item['image_refs'] ?? [];

        if ($images === [] && filled($item['image_urls'] ?? null)) {
            $imageUrls = is_array($item['image_urls']) ? $item['image_urls'] : [$item['image_urls']];
            $images = array_map(fn ($url) => ['external_url' => $url, 'image_role' => 'source_image'], $imageUrls);
        }

        if (! is_array($images)) {
            throw ValidationException::withMessages([
                'json_payload' => 'image_refs must be an array when provided.',
            ]);
        }

        return collect($images)
            ->filter(fn ($image) => is_array($image) || is_string($image))
            ->map(function ($image) {
                if (is_string($image)) {
                    return [
                        'image_role' => 'source_image',
                        'external_url' => $image,
                        'notes' => null,
                        'is_primary' => false,
                    ];
                }

                return [
                    'image_role' => $this->stringOrNull($image['image_role'] ?? null) ?? 'source_image',
                    'external_url' => $this->stringOrNull($image['external_url'] ?? $image['url'] ?? null),
                    'notes' => $this->stringOrNull($image['notes'] ?? null),
                    'is_primary' => (bool) ($image['is_primary'] ?? false),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  mixed  $productTypes
     * @return array<int, array<string, mixed>>
     */
    private function normalizeProductTypes(mixed $productTypes): array
    {
        if ($productTypes === null || $productTypes === []) {
            return [];
        }

        if (! is_array($productTypes)) {
            throw ValidationException::withMessages([
                'json_payload' => 'product_types must be an array when provided.',
            ]);
        }

        return collect($productTypes)
            ->filter(fn ($type) => is_array($type) || is_string($type))
            ->map(function ($type) {
                if (is_string($type)) {
                return [
                    'name' => trim($type),
                    'description' => null,
                    'notes' => null,
                    'sort_order' => 0,
                    'status' => null,
                    'shop_match' => null,
                    'source_candidates' => [],
                    'image_refs' => [],
                    'variants' => [],
                ];
            }

            return [
                'name' => $this->stringOrNull($type['name'] ?? null),
                'description' => $this->stringOrNull($type['description'] ?? null),
                'notes' => $this->stringOrNull($type['notes'] ?? null),
                'sort_order' => (int) ($type['sort_order'] ?? 0),
                'status' => $this->stringOrNull($type['status'] ?? null),
                'shop_match' => $this->normalizeShopMatch($type['shop_match'] ?? null),
                'source_candidates' => isset($type['source_candidates']) ? $this->normalizeSources($type) : [],
                'image_refs' => isset($type['image_refs']) || isset($type['image_urls']) ? $this->normalizeImages($type) : [],
                'variants' => $this->normalizeVariants($type['variants'] ?? []),
            ];
        })
            ->values()
            ->all();
    }

    /**
     * @param  mixed  $variants
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVariants(mixed $variants): array
    {
        if ($variants === null || $variants === []) {
            return [];
        }

        if (! is_array($variants)) {
            throw ValidationException::withMessages([
                'json_payload' => 'variants must be an array when provided.',
            ]);
        }

        return collect($variants)
            ->filter(fn ($variant) => is_array($variant) || is_string($variant))
            ->map(function ($variant) {
                if (is_string($variant)) {
                    return [
                        'variant_display_name' => trim($variant),
                        'color_code' => null,
                        'color_name' => null,
                        'size' => null,
                        'length' => null,
                        'bundle_count' => null,
                        'pack_size' => null,
                        'texture' => null,
                        'shade' => null,
                        'finish' => null,
                        'style' => null,
                        'weight' => null,
                        'volume' => null,
                        'attributes' => [],
                        'confidence' => null,
                        'status' => null,
                        'notes' => null,
                        'source_candidates' => [],
                        'image_refs' => [],
                        'shop_match' => null,
                    ];
                }

                return [
                    'variant_display_name' => $this->stringOrNull($variant['variant_display_name'] ?? $variant['name'] ?? null),
                    'color_code' => $this->stringOrNull($variant['color_code'] ?? null),
                    'color_name' => $this->stringOrNull($variant['color_name'] ?? null),
                    'size' => $this->stringOrNull($variant['size'] ?? null),
                    'length' => $this->stringOrNull($variant['length'] ?? null),
                    'bundle_count' => Arr::has($variant, 'bundle_count') && $variant['bundle_count'] !== null ? (int) $variant['bundle_count'] : null,
                    'pack_size' => $this->stringOrNull($variant['pack_size'] ?? null),
                    'texture' => $this->stringOrNull($variant['texture'] ?? null),
                    'shade' => $this->stringOrNull($variant['shade'] ?? null),
                    'finish' => $this->stringOrNull($variant['finish'] ?? null),
                    'style' => $this->stringOrNull($variant['style'] ?? null),
                    'weight' => $this->stringOrNull($variant['weight'] ?? null),
                    'volume' => $this->stringOrNull($variant['volume'] ?? null),
                    'attributes' => $this->normalizeVariantAttributes($variant),
                    'confidence' => $this->normalizeConfidenceValue($variant['confidence'] ?? null),
                    'status' => $this->stringOrNull($variant['status'] ?? null),
                    'notes' => $this->stringOrNull($variant['notes'] ?? null),
                    'source_url' => $this->stringOrNull($variant['source_url'] ?? null),
                    'image_url' => $this->stringOrNull($variant['image_url'] ?? null),
                    'source_candidates' => isset($variant['source_candidates']) ? $this->normalizeSources($variant) : [],
                    'image_refs' => isset($variant['image_refs']) || isset($variant['image_urls']) ? $this->normalizeImages($variant) : [],
                    'shop_match' => $this->normalizeShopMatch($variant['shop_match'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>
     */
    private function normalizeVariantAttributes(array $variant): array
    {
        $attributes = [];

        if (is_array($variant['attributes'] ?? null)) {
            $attributes = $variant['attributes'];
        } elseif (is_array($variant['attributes_json'] ?? null)) {
            $attributes = $variant['attributes_json'];
        } elseif (is_string($variant['attributes_json'] ?? null)) {
            $decoded = json_decode($variant['attributes_json'], true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $attributes = $decoded;
            }
        }

        $recognizedKeys = [
            'variant_display_name',
            'name',
            'color_code',
            'color_name',
            'size',
            'length',
            'bundle_count',
            'pack_size',
            'texture',
            'shade',
            'finish',
            'style',
            'weight',
            'volume',
            'attributes',
            'attributes_json',
            'confidence',
            'notes',
            'source_url',
            'image_url',
            'source_candidates',
            'image_refs',
            'image_urls',
            'shop_match',
        ];

        foreach (Arr::except($variant, $recognizedKeys) as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_scalar($value) || is_array($value)) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /**
     * @param  mixed  $shopMatch
     * @return array<string, mixed>|null
     */
    private function normalizeShopMatch(mixed $shopMatch): ?array
    {
        if ($shopMatch === null || $shopMatch === '') {
            return null;
        }

        if (! is_array($shopMatch)) {
            return null;
        }

        return [
            'shop_match_status' => $this->stringOrNull($shopMatch['shop_match_status'] ?? null) ?? 'unknown',
            'confidence' => $this->decimalOrNull($shopMatch['confidence'] ?? null),
            'confirmation_method' => $this->stringOrNull($shopMatch['confirmation_method'] ?? null),
            'notes' => $this->stringOrNull($shopMatch['notes'] ?? null),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function decimalOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizeConfidenceValue(mixed $value): ?float
    {
        $numeric = $this->decimalOrNull($value);

        if ($numeric !== null) {
            return $numeric;
        }

        return match (Str::upper(trim((string) $value))) {
            'A+', 'A' => 0.95,
            'A-' => 0.90,
            'B+', 'B' => 0.80,
            'B-' => 0.75,
            'C+', 'C' => 0.65,
            'C-' => 0.60,
            'D', 'D+' => 0.50,
            default => null,
        };
    }
}
