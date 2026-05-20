<?php

namespace App\Support;

class JsonPayloadCleaner
{
    /**
     * @return array{cleaned_payload: string, changed: bool, cleanup_notes: array<int, string>}
     */
    public function clean(string $payload): array
    {
        $notes = [];
        $original = $payload;

        $payload = $this->stripUtf8Bom($payload, $notes);
        $payload = $this->normalizeEncoding($payload, $notes);
        $payload = $this->stripMarkdownFence($payload, $notes);
        $payload = $this->normalizeLineEndings($payload, $notes);
        $payload = $this->stripInvisibleCharacters($payload, $notes);
        $payload = $this->cleanControlCharacters($payload, $notes);
        $payload = trim($payload);

        if ($payload !== $original && ! in_array('Trimmed surrounding whitespace.', $notes, true)) {
            $notes[] = 'Trimmed surrounding whitespace.';
        }

        return [
            'cleaned_payload' => $payload,
            'changed' => $payload !== $original,
            'cleanup_notes' => array_values(array_unique($notes)),
        ];
    }

    private function stripUtf8Bom(string $payload, array &$notes): string
    {
        if (str_starts_with($payload, "\xEF\xBB\xBF")) {
            $notes[] = 'Removed UTF-8 BOM marker.';

            return substr($payload, 3);
        }

        return $payload;
    }

    private function normalizeEncoding(string $payload, array &$notes): string
    {
        if (mb_check_encoding($payload, 'UTF-8')) {
            return $payload;
        }

        $converted = @mb_convert_encoding($payload, 'UTF-8', ['UTF-8', 'Windows-1252', 'ISO-8859-1']);

        if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
            $notes[] = 'Normalized payload to valid UTF-8 encoding.';

            return $converted;
        }

        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $payload);

        if (is_string($converted)) {
            $notes[] = 'Dropped invalid UTF-8 bytes from the payload.';

            return $converted;
        }

        return $payload;
    }

    private function stripMarkdownFence(string $payload, array &$notes): string
    {
        $trimmed = trim($payload);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $trimmed, $matches) === 1) {
            $notes[] = 'Removed Markdown code fences around the JSON payload.';

            return $matches[1];
        }

        return $payload;
    }

    private function normalizeLineEndings(string $payload, array &$notes): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $payload);

        if ($normalized !== $payload) {
            $notes[] = 'Normalized line endings.';
        }

        return $normalized;
    }

    private function stripInvisibleCharacters(string $payload, array &$notes): string
    {
        $cleaned = preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $payload) ?? $payload;

        if ($cleaned !== $payload) {
            $notes[] = 'Removed invisible formatting characters.';
        }

        return $cleaned;
    }

    private function cleanControlCharacters(string $payload, array &$notes): string
    {
        if ($payload === '') {
            return $payload;
        }

        $characters = preg_split('//u', $payload, -1, PREG_SPLIT_NO_EMPTY);

        if ($characters === false) {
            return $payload;
        }

        $cleaned = '';
        $inString = false;
        $escaping = false;
        $escapedInString = 0;
        $removedOutsideString = 0;

        foreach ($characters as $character) {
            if ($escaping) {
                $cleaned .= $character;
                $escaping = false;
                continue;
            }

            if ($character === '\\') {
                $cleaned .= $character;
                $escaping = $inString;
                continue;
            }

            if ($character === '"') {
                $cleaned .= $character;
                $inString = ! $inString;
                continue;
            }

            if (! $this->isControlCharacter($character)) {
                $cleaned .= $character;
                continue;
            }

            if ($inString) {
                $cleaned .= match ($character) {
                    "\n" => '\n',
                    "\r" => '\r',
                    "\t" => '\t',
                    default => sprintf('\u%04X', mb_ord($character, 'UTF-8')),
                };
                $escapedInString++;
                continue;
            }

            if (in_array($character, ["\n", "\t", ' '], true)) {
                $cleaned .= $character;
                continue;
            }

            $removedOutsideString++;
        }

        if ($escapedInString > 0) {
            $notes[] = 'Escaped control characters found inside JSON strings.';
        }

        if ($removedOutsideString > 0) {
            $notes[] = 'Removed stray control characters outside JSON strings.';
        }

        return $cleaned;
    }

    private function isControlCharacter(string $character): bool
    {
        return preg_match('/[\x{0000}-\x{001F}\x{007F}]/u', $character) === 1;
    }
}
