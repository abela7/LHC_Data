<?php

namespace App\Http\Controllers;

use App\Models\BrandCatalogue;
use App\Models\BrandCatalogueBrand;
use App\Models\BrandCatalogueProductType;
use App\Models\BrandCatalogueStyle;
use App\Models\HairExtensionIntake;
use App\Models\ShopPhotoBatch;
use App\Models\ShopPhotoBatchItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShopPhotoBatchController extends Controller
{
    public function index(): View
    {
        $batches = ShopPhotoBatch::query()
            ->withCount('items')
            ->latest()
            ->get();

        return view('shop-photo-batches.index', [
            'batches' => $batches,
        ]);
    }

    public function show(ShopPhotoBatch $batch): View
    {
        $items = $batch->items()->get();

        return view('shop-photo-batches.show', [
            'batch' => $batch,
            'items' => $items,
            'stats' => [
                'total' => $items->count(),
                'identified' => $items->where('status', 'identified')->count(),
                'pending' => $items->where('status', 'pending_review')->count(),
                'support' => $items->where('status', 'support_photo')->count(),
                'not_hair' => $items->where('status', 'not_hair_extension')->count(),
                'needs_revisit' => $items->where('status', 'needs_revisit')->count(),
            ],
        ]);
    }

    public function updateItem(Request $request, ShopPhotoBatch $batch, ShopPhotoBatchItem $item): JsonResponse
    {
        abort_unless($item->shop_photo_batch_id === $batch->id, Response::HTTP_NOT_FOUND);

        $data = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:160'],
            'grouping_path' => ['nullable', 'string', 'max:1000'],
            'product_type_name' => ['nullable', 'string', 'max:160'],
            'style_name' => ['nullable', 'string', 'max:220'],
            'main_variant' => ['nullable', 'string', 'max:220'],
            'sub_variant' => ['nullable', 'string', 'max:220'],
            'common_variant' => ['nullable', 'string', 'max:220'],
            'ecommerce_note' => ['nullable', 'string', 'max:5000'],
            'analysis_notes' => ['nullable', 'string', 'max:5000'],
            'confidence' => ['nullable', 'in:A,B,C,D'],
            'status' => ['required', 'in:pending_review,identified,support_photo,not_hair_extension,needs_revisit,duplicate'],
        ]);

        foreach ($data as $field => $value) {
            $data[$field] = is_string($value) ? (trim($value) ?: null) : $value;
        }

        $data['grouping_path'] = $this->decodePath($data['grouping_path'] ?? null);

        $item->update($data);

        return response()->json([
            'ok' => true,
            'item' => $item->fresh(),
        ]);
    }

    public function createV2Intake(Request $request, ShopPhotoBatch $batch, ShopPhotoBatchItem $item): JsonResponse
    {
        abort_unless($item->shop_photo_batch_id === $batch->id, Response::HTTP_NOT_FOUND);

        $data = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:160'],
            'grouping_path' => ['nullable', 'string', 'max:1000'],
            'product_type_name' => ['nullable', 'string', 'max:160'],
            'style_name' => ['nullable', 'string', 'max:220'],
            'main_variant' => ['nullable', 'string', 'max:220'],
            'sub_variant' => ['nullable', 'string', 'max:220'],
            'common_variant' => ['nullable', 'string', 'max:220'],
            'ecommerce_note' => ['nullable', 'string', 'max:5000'],
            'analysis_notes' => ['nullable', 'string', 'max:5000'],
            'confidence' => ['nullable', 'in:A,B,C,D'],
            'status' => ['required', 'in:pending_review,identified,support_photo,not_hair_extension,needs_revisit,duplicate'],
        ]);

        foreach ($data as $field => $value) {
            $data[$field] = is_string($value) ? (trim($value) ?: null) : $value;
        }
        $data['grouping_path'] = $this->decodePath($data['grouping_path'] ?? null);
        $item->update($data);
        $item = $item->fresh();

        if ($item->hair_extension_intake_id) {
            return response()->json([
                'ok' => true,
                'message' => 'Already created.',
                'intake_id' => $item->hair_extension_intake_id,
                'edit_url' => route('hair-extension-intake.v2', ['edit_intake' => $item->hair_extension_intake_id]),
                'submitted_url' => route('hair-extension-intake.submitted'),
            ]);
        }

        if ($item->status !== 'identified') {
            return response()->json([
                'ok' => false,
                'message' => 'Mark the photo as identified before creating a V2 intake.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $item->brand_name || ! $item->style_name) {
            return response()->json([
                'ok' => false,
                'message' => 'Brand and Style / Family are required before creating a V2 intake.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $brand = $this->matchCatalogueBrand($item->brand_name);
        $productType = $this->matchProductType($brand, $item->product_type_name);
        $style = $this->matchStyle($brand, $item->style_name);
        $variantStructure = $this->variantStructureFromItem($item);

        $intake = HairExtensionIntake::query()->create([
            'brand_catalogue_brand_id' => $brand?->id,
            'brand_catalogue_product_type_id' => $productType?->id,
            'brand_catalogue_style_id' => $style?->id,
            'brand_name' => $item->brand_name,
            'observed_product_name' => $item->style_name,
            'product_type_name' => $item->product_type_name,
            'catalogue_style_status' => $style ? 'known' : 'not_sure',
            'product_type_status' => $item->product_type_name ? 'known' : 'not_known',
            'style_family_status' => 'known',
            'classification_path' => $item->grouping_path ?? [],
            'product_type_unknown' => ! $item->product_type_name,
            'style_name' => $item->style_name,
            'style_unknown' => false,
            'variant_groups' => $this->variantGroupsFromStructure($variantStructure),
            'variant_structure' => $variantStructure,
            'visible_text_notes' => $this->intakeNotes($item),
            'status' => 'submitted',
            'ai_status' => 'ready_for_ai',
            'submitted_at' => now(),
            'last_synced_at' => now(),
        ]);

        $this->copyBatchPhotoToIntake($item, $intake);
        $item->update(['hair_extension_intake_id' => $intake->id]);

        return response()->json([
            'ok' => true,
            'message' => 'Created V2 intake #'.$intake->id.'.',
            'intake_id' => $intake->id,
            'edit_url' => route('hair-extension-intake.v2', ['edit_intake' => $intake->id]),
            'submitted_url' => route('hair-extension-intake.submitted'),
        ]);
    }

    public function image(ShopPhotoBatch $batch, ShopPhotoBatchItem $item): BinaryFileResponse
    {
        abort_unless((int) $item->shop_photo_batch_id === (int) $batch->id, Response::HTTP_NOT_FOUND);

        $sourcePath = $item->resolvedSourcePath();
        abort_unless($sourcePath !== null, Response::HTTP_NOT_FOUND);

        return response()->file($sourcePath, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function decodePath(mixed $raw): array
    {
        if (is_array($raw)) {
            $items = $raw;
        } else {
            $items = preg_split('/[\n>|,]+/', (string) $raw) ?: [];
        }

        return collect($items)
            ->map(fn (mixed $value): string => $this->cleanText($value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->take(12)
            ->values()
            ->all();
    }

    private function matchCatalogueBrand(?string $brandName): ?BrandCatalogueBrand
    {
        if (! $brandName) {
            return null;
        }

        $catalogue = BrandCatalogue::query()->where('slug', 'hair-extensions')->first();
        $brands = BrandCatalogueBrand::query()
            ->when($catalogue, fn ($query) => $query->where('brand_catalogue_id', $catalogue->id))
            ->get();

        $needle = $this->matchKey($brandName);

        return $brands->first(function (BrandCatalogueBrand $brand) use ($needle): bool {
            $key = $this->matchKey($brand->name);

            return $key === $needle
                || ($key !== '' && Str::startsWith($needle, $key))
                || ($needle !== '' && Str::startsWith($key, $needle));
        });
    }

    private function matchProductType(?BrandCatalogueBrand $brand, ?string $name): ?BrandCatalogueProductType
    {
        if (! $brand || ! $name) {
            return null;
        }

        $needle = $this->matchKey($name);

        return BrandCatalogueProductType::query()
            ->where('brand_catalogue_brand_id', $brand->id)
            ->get()
            ->first(fn (BrandCatalogueProductType $type): bool => $this->matchKey($type->name) === $needle);
    }

    private function matchStyle(?BrandCatalogueBrand $brand, ?string $name): ?BrandCatalogueStyle
    {
        if (! $brand || ! $name) {
            return null;
        }

        $needle = $this->matchKey($name);

        return BrandCatalogueStyle::query()
            ->where('brand_catalogue_brand_id', $brand->id)
            ->get()
            ->first(fn (BrandCatalogueStyle $style): bool => $this->matchKey($style->name) === $needle);
    }

    private function matchKey(?string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?: '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function variantStructureFromItem(ShopPhotoBatchItem $item): ?array
    {
        $mainValue = $this->cleanVariantValue($item->main_variant);
        $subValue = $this->cleanVariantValue($item->sub_variant);
        $commonVariants = $this->commonVariantsFromText($item->common_variant);

        if ($mainValue === null && $subValue === null && $commonVariants === []) {
            return null;
        }

        if ($mainValue === null && $subValue !== null) {
            $mainAxis = $this->axisForValue($subValue, default: 'Colour');
            $groups = [[
                'main_value' => $this->stripAxisPrefix($subValue),
                'sub_axis' => 'Colour',
                'sub_values' => [],
                'notes' => null,
            ]];
        } else {
            $mainAxis = $this->axisForValue($mainValue, default: 'Length');
            $groups = [[
                'main_value' => $this->stripAxisPrefix($mainValue ?: 'Observed'),
                'sub_axis' => $this->axisForValue($subValue, default: 'Colour'),
                'sub_values' => $subValue ? [$this->stripAxisPrefix($subValue)] : [],
                'notes' => null,
            ]];
        }

        return [
            'mode' => 'mapped',
            'source' => 'shop_photo_batch',
            'main_axis' => $mainAxis,
            'groups' => $groups,
            'common_variants' => $commonVariants,
            'sku_matrix' => $this->buildSkuMatrix($mainAxis, $groups, $commonVariants),
            'summary' => [
                'main_group_count' => count($groups),
                'common_variant_count' => count($commonVariants),
                'sellable_combination_count' => collect($groups)->sum(
                    fn (array $group): int => max(1, count($group['sub_values'] ?? []))
                ),
            ],
        ];
    }

    private function axisForValue(?string $value, string $default): string
    {
        $value = Str::lower((string) $value);

        if (str_contains($value, 'colour') || str_contains($value, 'color')) {
            return 'Colour';
        }

        if (preg_match('/\b(inch|inches|cm)\b|["”]/i', $value)) {
            return 'Length';
        }

        if (preg_match('/\b\d+\s*x\b|\bpack\b|\bbundle\b/i', $value)) {
            return 'Pack count';
        }

        return $default;
    }

    private function cleanVariantValue(?string $value): ?string
    {
        $value = $this->cleanText($value);

        return $value !== '' ? $value : null;
    }

    private function stripAxisPrefix(string $value): string
    {
        $value = preg_replace('/^(colour|color|length|size|pack count|pack|bundle count|bundle)\s*[:\-]?\s*/i', '', $value) ?: $value;

        return $this->cleanText($value);
    }

    /**
     * @return array<int, array{name:string,values:array<int,string>}>
     */
    private function commonVariantsFromText(?string $value): array
    {
        $values = preg_split('/[;,|]+/', (string) $value) ?: [];
        $packCounts = collect($values)
            ->map(fn (string $part): ?string => $this->extractSellableCommonVariant($part))
            ->filter()
            ->unique(fn (string $part): string => Str::lower($part))
            ->values()
            ->all();

        return $packCounts === []
            ? []
            : [[
                'name' => 'Pack count',
                'values' => $packCounts,
            ]];
    }

    private function extractSellableCommonVariant(string $value): ?string
    {
        $value = $this->cleanText($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/\b(\d+)\s*x\b/i', $value, $match)) {
            return strtoupper($match[1].'X');
        }

        if (preg_match('/\b(\d+)\s*(pack|packs|pc|pcs|piece|pieces|bundle|bundles)\b/i', $value, $match)) {
            return $match[1].' '.Str::lower($match[2]);
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @param array<int, array<string, mixed>> $commonVariants
     * @return array<int, array<string, mixed>>
     */
    private function buildSkuMatrix(string $mainAxis, array $groups, array $commonVariants): array
    {
        $commonAttributes = collect($commonVariants)
            ->mapWithKeys(fn (array $variant): array => [
                $variant['name'] => $variant['values'] ?? [],
            ])
            ->all();

        return collect($groups)
            ->flatMap(function (array $group) use ($mainAxis, $commonAttributes): array {
                $subValues = $group['sub_values'] ?? [];

                if ($subValues === []) {
                    return [[
                        'main_axis' => $mainAxis,
                        'main_value' => $group['main_value'],
                        'sub_axis' => $group['sub_axis'] ?? null,
                        'sub_value' => null,
                        'common_attributes' => $commonAttributes,
                    ]];
                }

                return collect($subValues)
                    ->map(fn (string $subValue): array => [
                        'main_axis' => $mainAxis,
                        'main_value' => $group['main_value'],
                        'sub_axis' => $group['sub_axis'] ?? 'Variant',
                        'sub_value' => $subValue,
                        'common_attributes' => $commonAttributes,
                    ])
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name:string,values:array<int,string>}>
     */
    private function variantGroupsFromStructure(?array $structure): array
    {
        if (! $structure) {
            return [];
        }

        $mainAxis = $structure['main_axis'] ?? 'Main variant';
        $groups = $structure['groups'] ?? [];
        $commonVariants = $structure['common_variants'] ?? [];
        $mainValues = collect($groups)->pluck('main_value')->filter()->unique()->values()->all();
        $subAxis = collect($groups)->pluck('sub_axis')->filter()->first() ?: 'Variant';
        $subValues = collect($groups)->flatMap(fn (array $group): array => $group['sub_values'] ?? [])->filter()->unique()->values()->all();
        $variantGroups = [];

        if ($mainValues !== []) {
            $variantGroups[] = [
                'name' => $mainAxis,
                'values' => $mainValues,
            ];
        }

        if ($subValues !== []) {
            $variantGroups[] = [
                'name' => $subAxis,
                'values' => $subValues,
            ];
        }

        foreach ($commonVariants as $variant) {
            if (($variant['values'] ?? []) === []) {
                continue;
            }

            $variantGroups[] = [
                'name' => $variant['name'] ?? 'Common variant',
                'values' => $variant['values'],
            ];
        }

        return $variantGroups;
    }

    private function intakeNotes(ShopPhotoBatchItem $item): ?string
    {
        return collect([
            $item->ecommerce_note ? 'Ecommerce note: '.$item->ecommerce_note : null,
            $item->analysis_notes ? 'Photo analysis: '.$item->analysis_notes : null,
            'Source photo: '.$item->filename,
        ])->filter()->implode("\n");
    }

    private function copyBatchPhotoToIntake(ShopPhotoBatchItem $item, HairExtensionIntake $intake): void
    {
        $sourcePath = $item->resolvedSourcePath();

        if ($sourcePath === null) {
            return;
        }

        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'jpg';
        $targetName = Str::slug(collect([$intake->brand_name, $intake->style_name, 'photo-'.$item->sequence])->filter()->implode(' '));
        $directory = 'hair-extension-intake/evidence/'.$intake->id.'-'.$targetName;
        $filename = $targetName.'.'.$extension;
        $path = $directory.'/'.$filename;
        $counter = 2;

        while (Storage::disk('public')->exists($path)) {
            $path = $directory.'/'.$targetName.'-'.$counter.'.'.$extension;
            $counter++;
        }

        Storage::disk('public')->put($path, file_get_contents($sourcePath));

        $intake->photos()->create([
            'image_role' => 'main',
            'source_label' => 'Shop photo batch',
            'notes' => 'Imported from '.$item->filename,
            'storage_disk' => 'public',
            'storage_path' => $path,
            'original_filename' => $item->original_filename ?: $item->filename,
            'mime_type' => mime_content_type($sourcePath) ?: null,
            'file_size' => filesize($sourcePath) ?: null,
            'source_type' => 'shop_photo_batch',
            'is_primary' => true,
            'sort_order' => 1,
        ]);
    }

    private function cleanText(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_replace('/\s+/', ' ', $value) ?: $value;
    }
}
