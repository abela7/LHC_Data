<?php

namespace App\Services;

use App\Models\BrandCatalogueStyle;
use App\Models\IntakeSession;
use App\Models\IntakeSessionFamilyGroup;
use App\Models\IntakeSessionVariant;
use App\Models\IntakeSessionVariantPhoto;
use App\Models\Product;
use App\Models\ProductEcommerceProfile;
use App\Models\ProductFamily;
use App\Models\ProductMedia;
use App\Models\ProductPosProfile;
use App\Models\ProductPrice;
use App\Models\ProductSource;
use App\Models\ReviewAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class HairIntakePublishService
{
    public function __construct(
        private readonly RetailProductPublisher $publisher,
        private readonly HairIntakeReviewService $reviewService,
    ) {
    }

    public function publish(IntakeSession $session): ProductFamily
    {
        $session->loadMissing(['matchedStyle', 'familyGroups', 'variants.photos']);

        $review = $this->reviewService->review($session);
        if (! ($review['ready_to_publish'] ?? false)) {
            throw new RuntimeException('Fix blocker issues before publishing.');
        }

        $style = $session->matchedStyle;
        if (! $style instanceof BrandCatalogueStyle) {
            throw new RuntimeException('This session has no matched catalogue style to publish.');
        }

        $family = DB::transaction(function () use ($session, $style): ProductFamily {
            $families = $session->familyGroups->isNotEmpty()
                ? $session->familyGroups->map(fn (IntakeSessionFamilyGroup $group): ProductFamily => $this->publishGroup($session, $style, $group))
                : collect([$this->publishGroup($session, $style, null)]);

            $family = $families->first();
            if (! $family instanceof ProductFamily) {
                throw new RuntimeException('No family was published from this intake.');
            }

            $session->update([
                'status' => 'published',
                'current_step' => 7,
                'published_family_id' => $family->id,
            ]);

            return $family->fresh(['products.price', 'products.media', 'media']);
        });

        // Best-effort mirror to Pink-Commerce (Railway) + R2 after the transaction commits.
        try {
            app(PinkCommerceBridge::class)->pushFamily($family);
        } catch (Throwable $e) {
            Log::warning('PinkCommerce push failed (publish unaffected)', [
                'family_id' => $family->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $family;
    }

    private function publishGroup(IntakeSession $session, BrandCatalogueStyle $style, ?IntakeSessionFamilyGroup $group): ProductFamily
    {
        $groupVariants = $group
            ? $session->variants->where('intake_session_family_group_id', $group->id)
            : $session->variants;

        $skuIds = $groupVariants
            ->where('manually_added', false)
            ->whereNotNull('brand_catalogue_sku_id')
            ->pluck('brand_catalogue_sku_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $scopeKey = $group ? $this->scopeKey($group, $skuIds) : null;
        $scopeName = $group?->name;

        $family = $this->publisher->publishBrandCatalogueStyle($style, $skuIds ?: null, $scopeKey, $scopeName);
        $family->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        $completedBySku = $groupVariants
            ->where('status', 'complete')
            ->where('manually_added', false)
            ->whereNotNull('brand_catalogue_sku_id')
            ->keyBy('brand_catalogue_sku_id');

        foreach ($family->products as $product) {
            $sessionVariant = $completedBySku->get((int) $product->brand_catalogue_sku_id);

            if ($sessionVariant instanceof IntakeSessionVariant) {
                $this->activateProduct($family, $product, $sessionVariant);
            } else {
                $this->deactivateProduct($product);
            }
        }

        foreach ($groupVariants->where('status', 'complete')->where('manually_added', true) as $manualVariant) {
            $this->publishManualVariant($family, $manualVariant);
        }

        $this->publishFamilyMainPhoto($family, $session, $group);
        $this->logApproval($session, $family, $group);

        return $family;
    }

    private function activateProduct(ProductFamily $family, Product $product, IntakeSessionVariant $variant): void
    {
        $product->update([
            'barcode' => $variant->barcode,
            'status' => 'active',
            'is_pos_active' => true,
            'is_ecommerce_active' => true,
            'is_inventory_tracked' => true,
        ]);

        ProductPrice::query()->updateOrCreate(
            ['product_id' => $product->id],
            [
                'retail_price' => $variant->price,
                'currency' => $variant->currency ?: 'GBP',
                'tax_class' => 'standard',
            ],
        );

        $product->inventoryLevels()->delete();
        $product->inventoryLevels()->create([
            'inventory_location_id' => $variant->store_id,
            'inventory_section_id' => $variant->section_id,
            'inventory_subsection_id' => $variant->subsection_id,
            'stock_quantity' => 0,
        ]);

        ProductPosProfile::query()->updateOrCreate(
            ['product_id' => $product->id],
            [
                'receipt_name' => $product->receipt_name ?: Str::limit($product->name, 80, ''),
                'quick_search_keywords' => $product->search_keywords,
                'pos_category' => $family->root_catalogue_name,
                'discount_allowed' => true,
                'quick_sale_enabled' => true,
                'tax_class' => 'standard',
            ],
        );

        ProductEcommerceProfile::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'profile_level' => 'sku',
            ],
            [
                'product_family_id' => $family->id,
                'online_title' => $product->ecommerceProfile?->online_title ?: $product->name,
                'short_description' => $product->description ? Str::limit($product->description, 180, '') : null,
                'long_description' => $product->description,
                'seo_slug' => $product->slug,
                'seo_title' => $product->name,
                'seo_description' => $product->description ? Str::limit($product->description, 155, '') : null,
                'is_published' => true,
                'click_and_collect_enabled' => true,
            ],
        );

        ProductSource::query()->updateOrCreate(
            [
                'product_family_id' => $family->id,
                'product_id' => $product->id,
                'source_type' => 'hair_intake_wizard',
                'source_table' => 'intake_session_variants',
                'source_id' => $variant->id,
            ],
            [
                'confidence' => 'A',
                'notes' => 'Shop-floor confirmed stocked variant from intake wizard.',
            ],
        );

        foreach ($variant->photos as $photo) {
            if ($photo->role === 'family_main') {
                continue;
            }

            $this->upsertProductMedia($family, $product, $photo);
        }
    }

    private function deactivateProduct(Product $product): void
    {
        $product->update([
            'status' => 'draft',
            'is_pos_active' => false,
            'is_ecommerce_active' => false,
        ]);

        $product->inventoryLevels()->delete();
        $product->ecommerceProfile?->update(['is_published' => false]);
    }

    private function publishManualVariant(ProductFamily $family, IntakeSessionVariant $variant): void
    {
        $name = trim($family->family_name.' - '.$variant->display_name);
        $slug = $this->uniqueProductSlug($family, $name);

        $product = Product::query()->firstOrCreate(
            [
                'product_family_id' => $family->id,
                'slug' => $slug,
            ],
            [
                'brand_id' => $family->brand_id,
                'name' => $name,
                'barcode' => $variant->barcode,
                'status' => 'draft',
                'is_pos_active' => false,
                'is_ecommerce_active' => false,
                'is_inventory_tracked' => true,
                'sort_order' => $family->products()->max('sort_order') + 1,
            ],
        );

        ProductSource::query()->updateOrCreate(
            [
                'product_family_id' => $family->id,
                'product_id' => $product->id,
                'source_type' => 'hair_intake_manual_variant',
                'source_table' => 'intake_session_variants',
                'source_id' => $variant->id,
            ],
            [
                'confidence' => 'C',
                'notes' => 'Manually added shop-floor variant. Keep inactive until catalogue review.',
            ],
        );
    }

    private function publishFamilyMainPhoto(ProductFamily $family, IntakeSession $session, ?IntakeSessionFamilyGroup $group = null): void
    {
        $variants = $group
            ? $session->variants->where('intake_session_family_group_id', $group->id)
            : $session->variants;

        $photo = $variants->flatMap->photos
            ->first(fn (IntakeSessionVariantPhoto $photo): bool => $photo->role === 'family_main');

        if (! $photo) {
            return;
        }

        ProductMedia::query()->updateOrCreate(
            [
                'product_family_id' => $family->id,
                'product_id' => null,
                'source_type' => 'hair_intake_wizard',
                'source_label' => 'Hair intake family main photo',
            ],
            [
                'catalogue_image_id' => null,
                'image_role' => 'main',
                'usage_context' => 'all',
                'storage_disk' => $photo->storage_disk,
                'storage_path' => $photo->storage_path,
                'original_filename' => $photo->original_filename,
                'mime_type' => $photo->mime_type,
                'file_size' => $photo->file_size,
                'alt_text' => $family->family_name,
                'notes' => 'Captured during hair extension intake wizard.',
                'is_primary' => true,
                'is_offline_ready' => true,
                'sort_order' => 0,
            ],
        );

        ProductMedia::query()
            ->where('product_family_id', $family->id)
            ->whereNull('product_id')
            ->where('source_type', '!=', 'hair_intake_wizard')
            ->update(['is_primary' => false]);
    }

    private function scopeKey(IntakeSessionFamilyGroup $group, array $skuIds): string
    {
        $scope = $group->scope_json ?: [];
        $filters = collect($scope['filters'] ?? [])
            ->map(fn (mixed $value): string => Str::lower(trim((string) $value)))
            ->filter()
            ->values()
            ->implode('|');

        if ($filters !== '') {
            return Str::slug($filters);
        }

        sort($skuIds);

        return 'sku-scope-'.substr(sha1(implode('|', $skuIds)), 0, 12);
    }

    private function logApproval(IntakeSession $session, ProductFamily $family, ?IntakeSessionFamilyGroup $group): void
    {
        ReviewAction::query()->create([
            'reviewable_type' => ProductFamily::class,
            'reviewable_id' => $family->id,
            'action' => 'approve',
            'from_status' => 'review_returned',
            'to_status' => 'published',
            'notes' => 'Approved from hair extension intake wizard.',
            'metadata' => [
                'intake_session_id' => $session->id,
                'session_uuid' => $session->session_uuid,
                'family_group_id' => $group?->id,
                'family_group_name' => $group?->name,
            ],
            'acted_by' => $session->user_id,
        ]);
    }

    private function upsertProductMedia(ProductFamily $family, Product $product, IntakeSessionVariantPhoto $photo): void
    {
        $role = $photo->role === 'variant_front' ? 'variant' : $photo->role;
        $isPrimary = $role === 'variant';

        if ($isPrimary) {
            ProductMedia::query()
                ->where('product_family_id', $family->id)
                ->where('product_id', $product->id)
                ->update(['is_primary' => false]);
        }

        ProductMedia::query()->updateOrCreate(
            [
                'product_family_id' => $family->id,
                'product_id' => $product->id,
                'source_type' => 'hair_intake_wizard',
                'source_label' => 'Hair intake '.$photo->role.' photo',
                'storage_path' => $photo->storage_path,
            ],
            [
                'catalogue_image_id' => null,
                'image_role' => $role,
                'usage_context' => 'all',
                'storage_disk' => $photo->storage_disk,
                'original_filename' => $photo->original_filename,
                'mime_type' => $photo->mime_type,
                'file_size' => $photo->file_size,
                'alt_text' => $product->name,
                'notes' => 'Captured during hair extension intake wizard.',
                'is_primary' => $isPrimary,
                'is_offline_ready' => true,
                'sort_order' => $photo->sort_order,
            ],
        );
    }

    private function uniqueProductSlug(ProductFamily $family, string $name): string
    {
        $base = Str::slug($name) ?: 'manual-variant';
        $slug = $base;
        $i = 2;

        while (Product::query()->where('product_family_id', $family->id)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
