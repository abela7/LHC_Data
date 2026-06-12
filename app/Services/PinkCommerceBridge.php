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

            // Mirror everything that has a SKU code (or barcode as fallback). Incomplete
            // fields (null barcode, missing price) are passed through as null/0 so the
            // Railway DB progressively reflects whatever state his app has — barcode and
            // price get filled in on later edits/republishes and update upserts cleanly.
            $code = $product->sku ?: $product->barcode;
            if (! $code) {
                continue;
            }

            $skus[] = [
                'combination' => $combination,
                'code' => (string) $code,
                'productBarcode' => $product->barcode ? (string) $product->barcode : null,
                'price' => $product->price ? (float) $product->price->retail_price : 0,
                // Per-variant POS display name + the variant's own photo (falls back to family on the POS).
                'name' => $this->productPosName($product),
                'imageUrl' => $this->productImageUrl($product, $family),
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
     * The variant's POS display name: inventory_name (the "POS name"), then receipt_name,
     * then the product name. Null when all are blank.
     */
    private function productPosName($product): ?string
    {
        $name = trim((string) ($product->inventory_name ?: $product->receipt_name ?: $product->name));

        return $name !== '' ? $name : null;
    }

    /**
     * The variant's OWN primary photo, uploaded to R2. Null when the product has no
     * media of its own (the POS then falls back to the family photo).
     */
    private function productImageUrl($product, ProductFamily $family): ?string
    {
        $disk = (string) config('pinkcommerce.r2_disk', 'r2');
        foreach ($product->media as $m) {
            try {
                $url = $this->ensureUploaded($m, $disk, (int) $family->id);
                if ($url) {
                    return $url;
                }
            } catch (Throwable $e) {
                Log::warning('PinkCommerce sku image upload failed', [
                    'product_id' => $product->id ?? null,
                    'media_id' => $m->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
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

        // Remote URL (e.g. supplier CDN): fetch + re-host into our R2 so we own
        // the asset and don't break when suppliers rotate their URLs. The R2
        // key is deterministic so a second push is a no-op (no re-download, no
        // re-upload). If the fetch or upload fails we fall back to the original
        // URL — better a working supplier URL than a broken image.
        if (! empty($media->external_url)) {
            $url = (string) $media->external_url;
            $mediaId = $media->id ?? md5($url);
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');
            // Drop anything weird (querystring chars, codepoints) — keep it
            // alphanumeric so the R2 key is safe.
            $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'jpg';
            if (strlen($ext) > 8) {
                $ext = 'jpg';
            }
            $key = "products/{$familyId}/{$mediaId}.{$ext}";

            try {
                if (! Storage::disk($disk)->exists($key)) {
                    $response = Http::timeout(20)->get($url);
                    if ($response->successful()) {
                        $body = $response->body();
                        if ($body !== '' && strlen($body) <= 25 * 1024 * 1024) {
                            Storage::disk($disk)->put($key, $body, 'public');
                        }
                    }
                }
                if (Storage::disk($disk)->exists($key)) {
                    return Storage::disk($disk)->url($key);
                }
            } catch (Throwable $e) {
                Log::warning('PinkCommerce R2 rehost failed; falling back to supplier URL', [
                    'media_id' => $media->id ?? null,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }

            // R2 unavailable for this image — keep the supplier URL so the
            // product still has *something* to display.
            return $url;
        }

        return null;
    }
}
