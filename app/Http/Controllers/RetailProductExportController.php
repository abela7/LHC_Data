<?php

namespace App\Http\Controllers;

use App\Models\ProductFamily;
use App\Services\ProductFamilyExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RetailProductExportController extends Controller
{
    public function family(ProductFamily $family, ProductFamilyExportService $exporter): BinaryFileResponse
    {
        $archivePath = $exporter->exportFamily($family);

        return response()
            ->download($archivePath)
            ->deleteFileAfterSend(true);
    }
}
