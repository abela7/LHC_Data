<?php

namespace App\Services;

use App\Models\ProductFamily;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pushes a published ProductFamily into the Pink-Commerce (Railway) API and
 * uploads its images to Cloudflare R2.
 *
 * Safe by design: no-ops unless configured (see config/pinkcommerce.php), and
 * callers wrap pushFamily() in try/catch so a bridge failure never breaks the
 * local publish flow.
 */
class PinkCommerceBridge
{
    public function isEnabled(): bool
    {
        return (bool) config('pinkcommerce.enabled')
            && config('pinkcommerce.api_url') !== ''
            && config('pinkcommerce.ingest_token') !== '';
    }

    /**
     * @return array{ok: bool, status?: int, skipped?: string, body?: mixed}
     */
    public function pushFamily(ProductFamily $family): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => false, 'skipped' => 'disabled'];
        }

        $family->loadMissing([
            'variantGroups.options',
            'products.variantValues.group',
            'products.variantValues.option',
            'products.price',
            'products.media',
            'media',
        ]);

        $payload = $this->buildPayload($family);

        if (count($payload['skus']) === 0) {
            Log::info('PinkCommerce push skipped: no SKUs with codes', ['family_id' => $family->id]);
            return ['ok' => false, 'skipped' => 'no_skus'];
        }

        $response = Http::timeout((int) config('pinkcommerce.timeout', 20))
            ->withHeaders(['x-lhc-token' => (string) config('pinkcommerce.ingest_token')])
            ->acceptJson()
            ->post(config('pinkcommerce.api_url').'/api/integrations/lhc/products', $payload);

        if (! $response->successful()) {
            Log::warning('PinkCommerce push non-2xx', [
                'family_id' => $family->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        return ['ok' => $response->successful(), 'status' => $response->status(), 'body' => $response->json()];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(ProductFamily $family): array
    {
        // Variant options (groups) and their values, in group order.
        $options = [];
        $values = [];
        foreach ($family->variantGroups as $group) {
            $groupName = (string) $group->name;
            $options[] = ['name' => $groupName];
            foreach ($group->options as $opt) {
                $label = $opt->label ?? $opt->value;
                if ($label !== null && $label !== '') {
                    $values[] = ['optionName' => $groupName, 'name' => (string) $label];
                }
            }
        }
        $groupOrder = $family->variantGroups->pluck('name')->map(fn ($n) => (string) $n)->all();

        // One SKU per published Product, with its variant combination (in group order).
        $skus = [];
        foreach ($family->products as $product) {
            $byGroup = [];
            foreach ($product->variantValues as $vv) {
                $gName = $vv->group?->name;
                $optLabel = $vv->option?->label ?? $vv->option?->value;
                if ($gName !== null && $optLabel !== null && $optLabel !== '') {
                    $byGroup[(string) $gName] = (string) $optLabel;
                }
            }
            $combination = [];
            foreach ($groupOrder as $gName) {
                if (isset($byGroup[$gName])) {
                    $combination[] = $byGroup[$gName];
                }
            }

            // Only push sellable, complete products: must have SKU + barcode + non-zero price.
            // Drafts/incomplete records stay local until they're finished.
            $code = $product->sku;
            $barcode = $product->barcode;
            $price = $product->price?->retail_price;
            if (! $code || ! $barcode || $price === null || (float) $price <= 0) {
                continue;
            }

            $skus[] = [
                'combination' => $combination,
                'code' => (string) $code,
                'productBarcode' => (string) $barcode,
                'price' => (float) $price,
            ];
        }

        return [
            'externalId' => (string) $family->id,
            'brand' => $family->brand_name ?: ($family->brand?->name),
            'category' => $family->product_type_name ?: $family->line_name,
            'name' => (string) $family->family_name,
            'description' => $family->description,
            'variants' => ['options' => $options, 'values' => $values],
            'skus' => $skus,
            'images' => $this->collectImageUrls($family),
        ];
    }

    /**
     * Upload each image to R2 (idempotent key) and return public URLs.
     *
     * @return list<string>
     */
    private function collectImageUrls(ProductFamily $family): array
    {
        $disk = (string) config('pinkcommerce.r2_disk', 'r2');
        $media = collect($family->media);
        foreach ($family->products as $product) {
            $media = $media->merge($product->media);
        }

        $urls = [];
        foreach ($media as $m) {
            try {
                $url = $this->ensureUploaded($m, $disk, (int) $family->id);
                if ($url) {
                    $urls[] = $url;
                }
            } catch (Throwable $e) {
                Log::warning('PinkCommerce image upload failed', [
                    'media_id' => $m->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return array_values(array_unique($urls));
    }

    private function ensureUploaded(object $media, string $disk, int $familyId): ?string
    {
        // Local file: copy to R2 under a deterministic key (skip if already there), return R2 public URL.
        if (! empty($media->storage_disk) && ! empty($media->storage_path)) {
            $source = Storage::disk($media->storage_disk);
            if ($source->exists($media->storage_path)) {
                $ext = pathinfo($media->storage_path, PATHINFO_EXTENSION) ?: 'jpg';
                $mediaId = $media->id ?? md5((string) $media->storage_path);
                $key = "products/{$familyId}/{$mediaId}.{$ext}";

                if (! Storage::disk($disk)->exists($key)) {
                    Storage::disk($disk)->put($key, $source->get($media->storage_path), 'public');
                }

                return Storage::disk($disk)->url($key);
            }
        }

        // Already remote (e.g. supplier CDN): pass the URL through unchanged.
        if (! empty($media->external_url)) {
            return (string) $media->external_url;
        }

        return null;
    }
}
