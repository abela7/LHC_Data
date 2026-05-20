<?php

namespace App\Support;

class CatalogueAiCsvLocator
{
    public function path(): string
    {
        foreach ($this->candidatePaths() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return public_path('catalogue-ai-input.csv');
    }

    /**
     * @return array<int, string>
     */
    public function candidatePaths(): array
    {
        return [
            public_path('catalogue-ai-input.csv'),
            base_path('catalogue-ai-input.csv'),
        ];
    }
}
