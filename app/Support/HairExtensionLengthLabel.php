<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalizes Hair Extensions Length variant labels to inch notation (e.g. 46").
 */
final class HairExtensionLengthLabel
{
    public static function isLengthGroupName(string $name): bool
    {
        $lower = Str::lower(trim($name));

        return $lower === 'length' || str_contains($lower, 'length');
    }

    /**
     * @return array{label: string, changed: bool}
     */
    public static function normalize(string $label): array
    {
        $original = trim(preg_replace('/\s+/u', ' ', $label) ?: '');
        if ($original === '') {
            return ['label' => $original, 'changed' => false];
        }

        if (preg_match('/^\d+(?:\.\d+)?"\s*$/u', $original)) {
            return ['label' => $original, 'changed' => false];
        }

        if (preg_match('/^\d+(?:\.\d+)?$/u', $original)) {
            return ['label' => $original.'"', 'changed' => true];
        }

        if (preg_match('/^\d+(?:\.\d+)?\s*(?:inch|inches|in)\.?$/ui', $original)) {
            $number = trim((string) preg_replace('/\s*(?:inch|inches|in)\.?$/ui', '', $original));

            return ['label' => $number.'"', 'changed' => true];
        }

        if (str_contains($original, '/')) {
            $parts = preg_split('#\s*/\s*#u', $original) ?: [];
            $normalized = [];
            $changed = false;
            foreach ($parts as $part) {
                $child = self::normalize(trim($part));
                $normalized[] = $child['label'];
                $changed = $changed || $child['changed'] || trim($part) !== $child['label'];
            }

            $joined = implode('/', $normalized);

            return ['label' => $joined, 'changed' => $changed || $joined !== $original];
        }

        return ['label' => $original, 'changed' => false];
    }

    public static function normalizeForHairExtensionLength(string $rootCatalogueName, string $groupName, string $label): string
    {
        if ($rootCatalogueName !== 'Hair Extensions' || ! self::isLengthGroupName($groupName)) {
            return $label;
        }

        return self::normalize($label)['label'];
    }
}
