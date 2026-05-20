<?php

namespace App\Http\Controllers;

use App\Models\CatalogueFamily;
use App\Support\CatalogueAiInputExportBuilder;
use App\Support\PictureRange;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function index(\Illuminate\Http\Request $request, CatalogueAiInputExportBuilder $catalogueAiInputExportBuilder): View
    {
        $pictureRange = PictureRange::fromRequest($request);

        return view('exports.index', [
            'approvedCount' => CatalogueFamily::query()->where('status', 'approved')->count(),
            'aiInputCount' => $catalogueAiInputExportBuilder->count($pictureRange),
            'aiInputStats' => $catalogueAiInputExportBuilder->stats($pictureRange),
            'filters' => $pictureRange->toFilterArray(),
        ]);
    }

    public function catalogueAiInputCsv(\Illuminate\Http\Request $request, CatalogueAiInputExportBuilder $catalogueAiInputExportBuilder): BinaryFileResponse
    {
        $pictureRange = PictureRange::fromRequest($request);
        $csvPath = base_path('output/spreadsheet/catalogue_ai_input.csv');
        $catalogueAiInputExportBuilder->writeCsv($csvPath, $pictureRange);

        return response()->download($csvPath, $this->catalogueAiInputFilename($pictureRange, 'csv'), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function catalogueAiInputXlsx(\Illuminate\Http\Request $request, CatalogueAiInputExportBuilder $catalogueAiInputExportBuilder): BinaryFileResponse
    {
        $pictureRange = PictureRange::fromRequest($request);
        $csvPath = base_path('output/spreadsheet/catalogue_ai_input.csv');
        $xlsxPath = base_path('output/spreadsheet/catalogue_ai_input.xlsx');

        $catalogueAiInputExportBuilder->writeCsv($csvPath, $pictureRange);

        $result = Process::path(base_path())->run(['python', 'output/build_catalogue_ai_input_workbook.py']);

        if (! $result->successful() || ! file_exists($xlsxPath)) {
            throw new RuntimeException('The XLSX export could not be generated.');
        }

        return response()->download($xlsxPath, $this->catalogueAiInputFilename($pictureRange, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function approvedJson()
    {
        $families = $this->approvedFamilies();

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'families' => $families->map(fn (CatalogueFamily $family) => $this->transformFamily($family))->all(),
        ]);
    }

    public function approvedCsv(): StreamedResponse
    {
        $families = $this->approvedFamilies();

        return response()->streamDownload(function () use ($families) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'family_id',
                'brand_name',
                'category_name',
                'subcategory_name',
                'product_family_name',
                'type_name',
                'variant_id',
                'variant_display_name',
                'color_code',
                'color_name',
                'size',
                'length',
                'bundle_count',
                'pack_size',
                'texture',
                'shade',
                'finish',
                'shop_match_status',
                'primary_source_url',
            ]);

            foreach ($families as $family) {
                $primarySource = optional($family->sources->firstWhere('is_primary', true))->url;

                foreach ($family->variants->isEmpty() ? [null] : $family->variants as $variant) {
                    fputcsv($handle, [
                        $family->id,
                        $family->brand?->name,
                        $family->category?->name,
                        $family->subcategory?->name,
                        $family->product_family_name,
                        $variant?->type?->name,
                        $variant?->id,
                        $variant?->variant_display_name,
                        $variant?->color_code,
                        $variant?->color_name,
                        $variant?->size,
                        $variant?->length,
                        $variant?->bundle_count,
                        $variant?->pack_size,
                        $variant?->texture,
                        $variant?->shade,
                        $variant?->finish,
                        $variant?->shopMatch?->shop_match_status ?? $family->shopMatch?->shop_match_status,
                        $primarySource,
                    ]);
                }
            }

            fclose($handle);
        }, 'approved-catalogue-export.csv');
    }

    private function approvedFamilies()
    {
        return CatalogueFamily::query()
            ->with([
                'brand',
                'category',
                'subcategory',
                'types',
                'variants.type',
                'variants.shopMatch',
                'sources',
                'images',
                'shopMatch',
            ])
            ->where('status', 'approved')
            ->orderBy('product_family_name')
            ->get();
    }

    private function catalogueAiInputFilename(PictureRange $pictureRange, string $extension): string
    {
        if (! $pictureRange->isActive()) {
            return "catalogue-ai-input.{$extension}";
        }

        $from = $pictureRange->from ?? 'start';
        $to = $pictureRange->to ?? 'end';

        return "catalogue-ai-input-{$from}-{$to}.{$extension}";
    }

    /**
     * @return array<string, mixed>
     */
    private function transformFamily(CatalogueFamily $family): array
    {
        return [
            'family_id' => $family->id,
            'brand_name' => $family->brand?->name,
            'category_name' => $family->category?->name,
            'subcategory_name' => $family->subcategory?->name,
            'product_family_name' => $family->product_family_name,
            'short_description' => $family->short_description,
            'full_description' => $family->full_description,
            'status' => $family->status,
            'approved_at' => optional($family->approved_at)?->toIso8601String(),
            'shop_match' => $family->shopMatch?->only([
                'shop_match_status',
                'confidence',
                'confirmation_method',
                'confirmed_at',
                'notes',
            ]),
            'traceability' => [
                'import_record_count' => $family->importRecords()->count(),
                'has_shop_evidence' => $family->importRecords()->whereHas('images')->exists(),
            ],
            'sources' => $family->sources->map(fn ($source) => $source->only([
                'role',
                'source_type',
                'trust_status',
                'url',
                'title',
                'confidence',
                'is_primary',
                'is_verified',
                'notes',
            ]))->all(),
            'images' => $family->images->map(fn ($image) => $image->only([
                'image_role',
                'external_url',
                'storage_disk',
                'storage_path',
                'is_primary',
                'notes',
            ]))->all(),
            'types' => $family->types->map(fn ($type) => [
                'type_id' => $type->id,
                'name' => $type->name,
                'status' => $type->status,
            ])->all(),
            'variants' => $family->variants->map(fn ($variant) => [
                'variant_id' => $variant->id,
                'type_id' => $variant->catalogue_type_id,
                'type_name' => $variant->type?->name,
                'variant_display_name' => $variant->variant_display_name,
                'color_code' => $variant->color_code,
                'color_name' => $variant->color_name,
                'size' => $variant->size,
                'length' => $variant->length,
                'bundle_count' => $variant->bundle_count,
                'pack_size' => $variant->pack_size,
                'texture' => $variant->texture,
                'shade' => $variant->shade,
                'finish' => $variant->finish,
                'style' => $variant->style,
                'weight' => $variant->weight,
                'volume' => $variant->volume,
                'shop_match' => $variant->shopMatch?->only([
                    'shop_match_status',
                    'confidence',
                    'confirmation_method',
                    'confirmed_at',
                    'notes',
                ]),
                'attributes_json' => $variant->attributes_json,
            ])->all(),
        ];
    }
}
