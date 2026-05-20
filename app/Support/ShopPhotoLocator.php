<?php

namespace App\Support;

class ShopPhotoLocator
{
    /**
     * @return array<int, string>
     */
    public function allPictureIds(): array
    {
        $baseDirectory = base_path('LHC PRODUCTS');

        if (! is_dir($baseDirectory)) {
            return [];
        }

        $extensions = ['jpg', 'jpeg', 'png', 'webp'];
        $pictureIds = [];

        foreach ($extensions as $extension) {
            foreach (glob($baseDirectory.DIRECTORY_SEPARATOR.'picture*.'.$extension) ?: [] as $path) {
                $pictureId = pathinfo($path, PATHINFO_FILENAME);

                if ($pictureId !== '') {
                    $pictureIds[$pictureId] = true;
                }
            }
        }

        $pictureIds = array_keys($pictureIds);

        usort($pictureIds, function (string $left, string $right): int {
            return (int) preg_replace('/\D+/', '', $left) <=> (int) preg_replace('/\D+/', '', $right);
        });

        return $pictureIds;
    }

    public function findPath(string $pictureId): ?string
    {
        $pictureId = trim($pictureId);

        if ($pictureId === '') {
            return null;
        }

        $baseDirectory = base_path('LHC PRODUCTS');
        $extensions = ['jpg', 'jpeg', 'png', 'webp'];

        foreach ($extensions as $extension) {
            $path = $baseDirectory.DIRECTORY_SEPARATOR.$pictureId.'.'.$extension;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
