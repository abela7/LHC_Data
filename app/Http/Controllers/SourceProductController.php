<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SourceProductController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search')->value());
        $source = trim((string) $request->string('source')->value());
        $allowedSources = ['deliveroo', 'pdf', 'pictures', 'mamado', 'janson', 'shaba'];
        $allowedPerPage = [50, 100, 200, 500];
        $perPage = (int) $request->integer('per_page', 100);

        if (! in_array($source, $allowedSources, true)) {
            $source = '';
        }

        if (! in_array($perPage, $allowedPerPage, true)) {
            $perPage = 100;
        }

        $query = DB::query()
            ->fromSub($this->sourceUnion(), 'source_products')
            ->when($source !== '', function (Builder $builder) use ($source): void {
                $builder->where('source', $source);
            })
            ->when($search !== '', function (Builder $builder) use ($search): void {
                $builder->where(function (Builder $inner) use ($search): void {
                    $inner
                        ->where('brand', 'like', '%'.$search.'%')
                        ->orWhere('family_name', 'like', '%'.$search.'%')
                        ->orWhere('variant_name', 'like', '%'.$search.'%')
                        ->orWhere('product_name', 'like', '%'.$search.'%')
                        ->orWhere('item_code', 'like', '%'.$search.'%')
                        ->orWhere('source_ref', 'like', '%'.$search.'%');
                });
            });

        $products = (clone $query)
            ->orderBy('source')
            ->orderBy('brand')
            ->orderBy('family_name')
            ->orderBy('product_name')
            ->paginate($perPage)
            ->withQueryString();

        $products->setCollection(
            $products->getCollection()->map(function (object $product): object {
                $product->source_label = $this->sourceLabels()[$product->source] ?? ucfirst((string) $product->source);
                $product->image_count = $this->imageCount($product->image_urls);

                return $product;
            })
        );

        return view('source-products.index', [
            'products' => $products,
            'search' => $search,
            'source' => $source,
            'sourceLabels' => $this->sourceLabels(),
            'allowedPerPage' => $allowedPerPage,
            'perPage' => $perPage,
            'stats' => $this->stats(),
        ]);
    }

    private function sourceUnion(): Builder
    {
        $deliveroo = DB::table('deliveroo_official_products')
            ->selectRaw("
                'deliveroo' as source,
                id as source_id,
                brand_slug as source_key,
                brand_label as brand,
                family_name,
                variant_name,
                official_name as product_name,
                NULL as item_code,
                price,
                currency,
                source_site,
                official_url as source_ref,
                description,
                image_urls,
                NULL as status,
                created_at
            ");

        $pdf = DB::table('pdf_catalogue_products')
            ->selectRaw("
                'pdf' as source,
                id as source_id,
                pdf_catalogue_page_id as source_key,
                brand,
                NULL as family_name,
                NULL as variant_name,
                product_name,
                product_code as item_code,
                NULL as price,
                NULL as currency,
                source_name as source_site,
                CONCAT(source_name, ' / page ', page_number) as source_ref,
                raw_name_text as description,
                NULL as image_urls,
                CONCAT('Confidence ', confidence) as status,
                created_at
            ");

        $pictures = DB::table('observed_products')
            ->selectRaw("
                'pictures' as source,
                id as source_id,
                id as source_key,
                COALESCE(NULLIF(canonical_brand, ''), brand) as brand,
                brand_line as family_name,
                NULL as variant_name,
                product_name,
                picture_id as item_code,
                NULL as price,
                NULL as currency,
                'Store photos' as source_site,
                picture_id as source_ref,
                NULL as description,
                NULL as image_urls,
                NULL as status,
                created_at
            ");

        $mamado = DB::table('mamado_products')
            ->selectRaw("
                'mamado' as source,
                id as source_id,
                id as source_key,
                brand_label as brand,
                family_name,
                variant_name,
                COALESCE(sellable_name, item_description) as product_name,
                item_code,
                COALESCE(sellable_price, gross_unit_price) as price,
                'GBP' as currency,
                'Mamado' as source_site,
                source_order_number as source_ref,
                description,
                image_urls,
                status,
                created_at
            ");

        $janson = DB::table('janson_products')
            ->selectRaw("
                'janson' as source,
                id as source_id,
                page as source_key,
                category as brand,
                category as family_name,
                special_note as variant_name,
                name as product_name,
                code as item_code,
                price_gbp as price,
                currency,
                'Janson Beauty Dec 2025' as source_site,
                CONCAT('page ', COALESCE(page, 0), ' row ', COALESCE(page_row, 0)) as source_ref,
                source_name as description,
                NULL as image_urls,
                CASE
                    WHEN JSON_LENGTH(review_flags) > 0 THEN CONCAT('review ', JSON_LENGTH(review_flags), ' flag(s)')
                    WHEN is_new = 1 THEN 'new'
                    ELSE NULL
                END as status,
                created_at
            ");

        $shaba = DB::table('shaba_reference_products')
            ->selectRaw("
                'shaba' as source,
                id as source_id,
                source_product_id as source_key,
                brand,
                NULL as family_name,
                NULL as variant_name,
                title as product_name,
                source_product_id as item_code,
                (min_price_pence / 100) as price,
                currency,
                'Shaba Cosmetics' as source_site,
                canonical_url as source_ref,
                description,
                CASE WHEN main_image_url IS NULL THEN NULL ELSE JSON_ARRAY(main_image_url) END as image_urls,
                stock_status as status,
                created_at
            ");

        return $deliveroo
            ->unionAll($pdf)
            ->unionAll($pictures)
            ->unionAll($mamado)
            ->unionAll($janson)
            ->unionAll($shaba);
    }

    /**
     * @return array<string, string>
     */
    private function sourceLabels(): array
    {
        return [
            'deliveroo' => 'Deliveroo',
            'pdf' => 'PDFs',
            'pictures' => 'Pictures',
            'mamado' => 'Mamado',
            'janson' => 'Janson',
            'shaba' => 'Shaba',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        $counts = [
            'deliveroo' => DB::table('deliveroo_official_products')->count(),
            'pdf' => DB::table('pdf_catalogue_products')->count(),
            'pictures' => DB::table('observed_products')->count(),
            'mamado' => DB::table('mamado_products')->count(),
            'janson' => DB::table('janson_products')->count(),
            'shaba' => DB::table('shaba_reference_products')->count(),
        ];

        $counts['total'] = array_sum($counts);

        return $counts;
    }

    private function imageCount(?string $imageUrls): int
    {
        if ($imageUrls === null || trim($imageUrls) === '' || $imageUrls === '[]') {
            return 0;
        }

        $decoded = json_decode($imageUrls, true);

        return is_array($decoded) ? count(array_filter($decoded)) : 0;
    }
}
