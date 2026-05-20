<?php

namespace App\Services;

use App\Models\ShabaReferenceProduct;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;

class ShabaReferenceImporter
{
    /**
     * @return array<string, mixed>
     */
    public function import(?string $path = null, bool $fresh = false): array
    {
        $resolvedPath = $this->resolvePath($path);
        $deduplicated = [];
        $totalRows = 0;
        $invalidRows = [];

        $file = new SplFileObject($resolvedPath, 'r');
        while (! $file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line === '') {
                continue;
            }

            $totalRows++;
            $payload = json_decode($line, true);

            if (! is_array($payload)) {
                $invalidRows[] = $totalRows;
                continue;
            }

            $key = $this->dedupeKey($payload);
            if ($key === '') {
                $invalidRows[] = $totalRows;
                continue;
            }

            if (! isset($deduplicated[$key]) || $this->score($payload) > $this->score($deduplicated[$key])) {
                $deduplicated[$key] = $payload;
            }
        }

        if ($fresh) {
            DB::table('shaba_reference_media')->delete();
            DB::table('shaba_reference_variants')->delete();
            DB::table('shaba_reference_products')->delete();
        }

        $created = 0;
        $updated = 0;
        $variantRows = 0;
        $mediaRows = 0;
        $brandCounts = [];

        foreach ($deduplicated as $payload) {
            $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
            $sourceProductId = trim((string) ($source['id'] ?? ''));
            $canonicalUrl = trim((string) ($source['canonicalUrl'] ?? ''));
            $brand = trim((string) ($payload['brand'] ?? $source['retailer'] ?? 'Unknown'));
            $title = trim((string) ($payload['title'] ?? ''));
            $variants = is_array($payload['variants'] ?? null) ? $payload['variants'] : [];
            $media = is_array($payload['medias'] ?? null) ? $payload['medias'] : [];
            $prices = collect($variants)
                ->map(fn (mixed $variant): ?int => is_array($variant) ? $this->nullableInt($variant['price']['current'] ?? null) : null)
                ->filter(fn (?int $value): bool => $value !== null)
                ->values();
            $stockStatuses = collect($variants)
                ->map(fn (mixed $variant): string => is_array($variant) ? trim((string) ($variant['price']['stockStatus'] ?? '')) : '')
                ->filter()
                ->unique()
                ->values();

            $product = ShabaReferenceProduct::query()->updateOrCreate(
                ['source_product_id' => $sourceProductId],
                [
                    'canonical_url_hash' => hash('sha256', $canonicalUrl),
                    'canonical_url' => $canonicalUrl,
                    'retailer' => trim((string) ($source['retailer'] ?? '')) ?: null,
                    'brand' => $brand,
                    'normalized_brand' => $this->normalize($brand),
                    'title' => $title,
                    'normalized_title' => $this->normalize($title),
                    'department' => $this->department($payload, $brand, $title),
                    'description' => trim((string) ($payload['description'] ?? '')) ?: null,
                    'currency' => trim((string) ($source['currency'] ?? 'GBP')) ?: 'GBP',
                    'categories' => is_array($payload['categories'] ?? null) ? array_values($payload['categories']) : null,
                    'tags' => is_array($payload['tags'] ?? null) ? array_values($payload['tags']) : null,
                    'options' => is_array($payload['options'] ?? null) ? array_values($payload['options']) : null,
                    'variant_count' => count($variants),
                    'media_count' => count($media),
                    'min_price_pence' => $prices->isEmpty() ? null : (int) $prices->min(),
                    'max_price_pence' => $prices->isEmpty() ? null : (int) $prices->max(),
                    'stock_status' => $stockStatuses->count() === 1 ? $stockStatuses->first() : ($stockStatuses->isEmpty() ? null : 'Mixed'),
                    'main_image_url' => $this->mainImageUrl($media),
                    'source_created_at' => $this->timestampFromMs($source['createdUTC'] ?? null),
                    'source_updated_at' => $this->timestampFromMs($source['updatedUTC'] ?? null),
                    'source_published_at' => $this->timestampFromMs($source['publishedUTC'] ?? null),
                    'imported_at' => now(),
                    'raw_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                ]
            );

            $product->wasRecentlyCreated ? $created++ : $updated++;
            $brandCounts[$brand] = ($brandCounts[$brand] ?? 0) + 1;

            $product->variants()->delete();
            $product->media()->delete();

            $variantRows += $this->insertVariants($product, $variants);
            $mediaRows += $this->insertMedia($product, $media);
        }

        arsort($brandCounts);

        return [
            'path' => $resolvedPath,
            'total_rows' => $totalRows,
            'unique_products' => count($deduplicated),
            'invalid_rows' => $invalidRows,
            'created' => $created,
            'updated' => $updated,
            'variants' => $variantRows,
            'media' => $mediaRows,
            'brands' => count($brandCounts),
            'top_brands' => array_slice($brandCounts, 0, 12, true),
        ];
    }

    private function resolvePath(?string $path): string
    {
        $path = $path ?: storage_path('app/reference-data/shaba/shaba-shopify-products-v1-2026-05-13.jsonl');
        $candidates = [$path, base_path($path), storage_path('app/'.$path)];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Shaba JSONL file was not found: '.$path);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dedupeKey(array $payload): string
    {
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $id = trim((string) ($source['id'] ?? ''));
        if ($id !== '') {
            return 'id:'.$id;
        }

        $url = trim((string) ($source['canonicalUrl'] ?? ''));
        if ($url !== '') {
            return 'url:'.hash('sha256', $url);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function score(array $payload): int
    {
        return (trim((string) ($payload['description'] ?? '')) !== '' ? 100 : 0)
            + (count(is_array($payload['variants'] ?? null) ? $payload['variants'] : []) * 3)
            + count(is_array($payload['medias'] ?? null) ? $payload['medias'] : [])
            + count(is_array($payload['options'] ?? null) ? $payload['options'] : []);
    }

    /**
     * @param  array<int, mixed>  $media
     */
    private function mainImageUrl(array $media): ?string
    {
        foreach ($media as $item) {
            if (is_array($item) && trim((string) ($item['url'] ?? '')) !== '') {
                return trim((string) $item['url']);
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $variants
     */
    private function insertVariants(ShabaReferenceProduct $product, array $variants): int
    {
        $now = now();
        $rows = [];

        foreach (array_values($variants) as $index => $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $sourceVariantId = trim((string) ($variant['id'] ?? ''));
            if ($sourceVariantId === '') {
                $sourceVariantId = $product->source_product_id.'-'.$index;
            }

            $rows[] = [
                'shaba_reference_product_id' => $product->getKey(),
                'source_variant_id' => $sourceVariantId,
                'title' => trim((string) ($variant['title'] ?? 'Default Title')) ?: 'Default Title',
                'sku' => trim((string) ($variant['sku'] ?? '')) ?: null,
                'options' => json_encode(is_array($variant['options'] ?? null) ? array_values($variant['options']) : null),
                'price_current_pence' => $this->nullableInt($variant['price']['current'] ?? null),
                'price_previous_pence' => $this->nullableInt($variant['price']['previous'] ?? null),
                'stock_status' => trim((string) ($variant['price']['stockStatus'] ?? '')) ?: null,
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('shaba_reference_variants')->insert($rows);
        }

        return count($rows);
    }

    /**
     * @param  array<int, mixed>  $media
     */
    private function insertMedia(ShabaReferenceProduct $product, array $media): int
    {
        $now = now();
        $rows = [];

        foreach (array_values($media) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $rows[] = [
                'shaba_reference_product_id' => $product->getKey(),
                'source_media_id' => trim((string) ($item['id'] ?? '')) ?: null,
                'type' => trim((string) ($item['type'] ?? 'Image')) ?: 'Image',
                'url_hash' => hash('sha256', $url),
                'url' => $url,
                'variant_ids' => json_encode(is_array($item['variantIds'] ?? null) ? array_values($item['variantIds']) : null),
                'alt' => trim((string) ($item['alt'] ?? '')) ?: null,
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('shaba_reference_media')->insert($rows);
        }

        return count($rows);
    }

    private function timestampFromMs(mixed $value): ?CarbonImmutable
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) floor(((int) $value) / 1000));
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function department(array $payload, string $brand, string $title): string
    {
        $categories = collect(is_array($payload['categories'] ?? null) ? $payload['categories'] : [])
            ->map(fn (mixed $value): string => $this->normalize((string) $value))
            ->filter()
            ->values();

        $tags = collect(is_array($payload['tags'] ?? null) ? $payload['tags'] : [])
            ->map(fn (mixed $value): string => $this->normalize((string) $value))
            ->filter()
            ->values();

        $variantTitles = collect(is_array($payload['variants'] ?? null) ? $payload['variants'] : [])
            ->map(fn (mixed $variant): string => is_array($variant) ? $this->normalize((string) ($variant['title'] ?? '')) : '')
            ->filter()
            ->take(20)
            ->values();

        $normalizedTitle = $this->normalize($title);
        $haystack = trim(implode(' ', array_filter([
            $this->normalize($brand),
            $normalizedTitle,
            $this->normalize((string) ($payload['description'] ?? '')),
            $categories->implode(' '),
            $tags->implode(' '),
            $variantTitles->implode(' '),
        ])));

        $extensionCategories = [
            '100 human hair wig',
            '100 unprocessed bulk',
            '100 unprocessed hair',
            '100 virgin human hair weave',
            '13x4 frontal',
            'braiding hair',
            'clip in hair',
            'closure',
            'closures',
            'frontal',
            'glueless 13x6 lace wig',
            'human hair bulk',
            'human hair wig',
            'lace frontal',
            'lace wig',
            'pony tail',
            'ponytail',
            'synthetic bulk',
            'synthetic weave',
            'virgin gold human hair',
            'virgin gold human hair bulk',
            'weave',
            'wig',
        ];

        foreach ($categories as $category) {
            foreach ($extensionCategories as $needle) {
                if (str_contains($category, $needle)) {
                    return ShabaReferenceProduct::DEPARTMENT_HAIR_EXTENSIONS;
                }
            }
        }

        $nonExtensionProductSignals = [
            'adhesive',
            'bar saver',
            'braiding gel',
            'claw clip',
            'cloud clip',
            'edge gel',
            'edge styling',
            'hair clip',
            'hair bond glue',
            'lace color',
            'lace melt',
            'remover',
            'saver bag',
            'wig glue',
            'wig adhesive',
            'wax stick',
        ];

        foreach ($nonExtensionProductSignals as $needle) {
            if (str_contains($haystack, $needle)) {
                return ShabaReferenceProduct::DEPARTMENT_BODY_CARE;
            }
        }

        $extensionTitleSignals = [
            ' braids',
            ' braiding hair',
            ' bulk',
            ' crochet',
            ' frontal',
            ' human hair',
            ' lace wig',
            ' ponytail',
            ' pre stretched',
            ' pre-stretched',
            ' synthetic hair',
            ' weave',
            ' wig',
            'closure',
            'crochet braid',
            'hair extensions',
        ];

        $titleProbe = ' '.$normalizedTitle.' ';
        foreach ($extensionTitleSignals as $needle) {
            if (str_contains($titleProbe, $needle)) {
                return ShabaReferenceProduct::DEPARTMENT_HAIR_EXTENSIONS;
            }
        }

        return ShabaReferenceProduct::DEPARTMENT_BODY_CARE;
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(preg_replace('/[^a-z0-9]+/i', ' ', $value) ?: '')) ?: '');
    }
}
