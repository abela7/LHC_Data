<?php

namespace App\Http\Controllers;

use App\Support\ShopPhotoLocator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShopPhotoController extends Controller
{
    public function show(string $pictureId, ShopPhotoLocator $shopPhotoLocator): BinaryFileResponse
    {
        $path = $shopPhotoLocator->findPath($pictureId);

        abort_if($path === null, 404);

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
