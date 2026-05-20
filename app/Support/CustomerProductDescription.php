<?php

namespace App\Support;

class CustomerProductDescription
{
    /**
     * Customer-facing copy must not mention where the product data/photo came from.
     */
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if ($text === '') {
            return null;
        }

        $sourcePatterns = [
            '/\b(?:source|supplier|vendor|distributor|wholesaler?|catalogue|catalog)\s*:/i',
            '/\b(?:sourced?|ordered|bought|purchased|imported|supplied|provided)\s+(?:from|by)\b/i',
            '/\bfrom\s+(?:mamado|orders[-\s]?mamado|shaba cosmetics|shabacosmetics|beautyflex|brits hair|britshairandbeauty|beautizone|tj beauty|tjbeautyproducts|citrus cosmetics|deliveroo)\b/i',
            '/\b(?:mamado|orders[-\s]?mamado|shabacosmetics\.com|beautyflex\.co\.uk|britshairandbeauty\.co\.uk|beautizone\.co\.uk|tjbeautyproducts\.co\.uk|citruscosmetics\.co\.uk|deliveroo)\b/i',
            '/https?:\/\/\S+/i',
        ];

        $lines = preg_split('/\n+/', $text) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/[ \t]+/', ' ', $line) ?? '');
            if ($line === '') {
                continue;
            }

            $mentionsSource = false;
            foreach ($sourcePatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $mentionsSource = true;
                    break;
                }
            }

            if (! $mentionsSource) {
                $kept[] = $line;
            }
        }

        $cleaned = trim(implode("\n", $kept));

        return $cleaned === '' ? null : $cleaned;
    }
}
