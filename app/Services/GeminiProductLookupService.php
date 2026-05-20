<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class GeminiProductLookupService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @param array{brand_name:string, observed_product_name:string, source_url?:?string} $input
     * @return array<string, mixed>
     */
    public function suggest(array $input): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('Gemini API key is missing. Add GEMINI_API_KEY to .env.');
        }

        $model = (string) config('services.gemini.model', 'gemini-3-flash-preview');
        $timeout = (int) config('services.gemini.timeout', 30);
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        $prompt = $this->prompt($input);

        $response = $this->http
            ->timeout($timeout)
            ->retry(1, 500)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->post($endpoint, [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [[
                        'text' => $prompt,
                    ]],
                ]],
                'tools' => [[
                    'google_search' => (object) [],
                ]],
                'generationConfig' => [
                    'temperature' => 0.1,
                ],
            ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: $response->body();
            throw new RuntimeException('Gemini lookup failed: '.Str::limit((string) $message, 500, ''));
        }

        $payload = $response->json();
        $text = (string) data_get($payload, 'candidates.0.content.parts.0.text', '');
        $suggestion = $this->decodeSuggestion($text);
        $sources = $this->sourceUrls($payload, $suggestion);

        return [
            'model' => $model,
            'prompt_hash' => hash('sha256', $prompt),
            'suggestion' => $suggestion,
            'confidence' => $this->normaliseConfidence($suggestion['confidence'] ?? null),
            'source_urls' => $sources,
            'raw_response' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param array{brand_name:string, observed_product_name:string, source_url?:?string} $input
     */
    private function prompt(array $input): string
    {
        $preferredSources = [
            'shabacosmetics.com',
            'beautyflex.co.uk',
            'britshairandbeauty.co.uk',
            'beautizone.co.uk',
            'tjbeautyproducts.co.uk',
            'citruscosmetics.co.uk',
            'mamado.co.uk',
            'feme.com',
            'pakcosmetics.com',
        ];

        $sourceUrl = trim((string) ($input['source_url'] ?? ''));

        return trim(<<<PROMPT
You are a product identification assistant for a UK hair and cosmetics retailer.

Your job is NOT to create the final retail product. Your job is to help the human on the product intake page by verifying the brand + observed product name from internet sources and suggesting likely fields.

Input:
- Brand: {$input['brand_name']}
- Product name observed on pack: {$input['observed_product_name']}
- User supplied source URL: {$sourceUrl}

Search rules:
1. If a user supplied source URL exists, check it first.
2. Prioritise exact brand + product name matches.
3. Prefer these source domains before wider web search: {$this->csv($preferredSources)}.
4. Do not invent variants or fields. If the web does not clearly prove it, leave it empty or mark confidence C/D.
5. Keep brand separate from product name.
6. For hair extensions, useful product type examples include Braiding Hair, Bulk Hair, Crochet, Twist & Loc Hair, Ponytails & Hair Pieces, Wigs, Weave / Weft.
7. Style/family means the sellable line name such as Passion Twist, French Curl, Ultra Braid, Pre-Stretched 2x 46.
8. Variant axes are the attributes the shop will observe, such as Length, Colour, Pack count, Texture, Size, Material.

Return only valid JSON with these exact keys:
{
  "confidence": "A|B|C|D",
  "suggested_product_name": "",
  "product_type": "",
  "style_family": "",
  "variant_axes": [],
  "likely_variant_values": [{"axis": "Colour", "values": ["1", "1B"]}],
  "product_clues": [],
  "source_urls": [],
  "confidence_reason": "",
  "notes": ""
}

Do not wrap the JSON in markdown. Confidence rules:
- A: exact product verified on a reliable source.
- B: strong likely match but minor naming/variant uncertainty.
- C: product likely exists but classification or variant data is uncertain.
- D: not enough evidence.
PROMPT);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'confidence' => ['type' => 'string', 'enum' => ['A', 'B', 'C', 'D']],
                'suggested_product_name' => ['type' => 'string'],
                'product_type' => ['type' => 'string'],
                'style_family' => ['type' => 'string'],
                'variant_axes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'likely_variant_values' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'axis' => ['type' => 'string'],
                            'values' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['axis', 'values'],
                    ],
                ],
                'product_clues' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'source_urls' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'confidence_reason' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
            ],
            'required' => [
                'confidence',
                'suggested_product_name',
                'product_type',
                'style_family',
                'variant_axes',
                'likely_variant_values',
                'product_clues',
                'source_urls',
                'confidence_reason',
                'notes',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSuggestion(string $text): array
    {
        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?: $clean;
        $clean = preg_replace('/\s*```$/', '', $clean) ?: $clean;

        $decoded = json_decode($clean, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Gemini returned a non-JSON suggestion.');
        }

        $decoded['confidence'] = $this->normaliseConfidence($decoded['confidence'] ?? null);
        $decoded['variant_axes'] = $this->stringList($decoded['variant_axes'] ?? []);
        $decoded['product_clues'] = $this->stringList($decoded['product_clues'] ?? []);
        $decoded['source_urls'] = $this->stringList($decoded['source_urls'] ?? []);

        $decoded['likely_variant_values'] = $this->normaliseVariantValueMap($decoded['likely_variant_values'] ?? []);

        foreach (['suggested_product_name', 'product_type', 'style_family', 'confidence_reason', 'notes'] as $key) {
            $decoded[$key] = trim((string) ($decoded[$key] ?? ''));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $suggestion
     * @return array<int, string>
     */
    private function sourceUrls(array $payload, array $suggestion): array
    {
        $sources = $this->stringList($suggestion['source_urls'] ?? []);
        $chunks = Arr::get($payload, 'candidates.0.groundingMetadata.groundingChunks', []);

        if (is_array($chunks)) {
            foreach ($chunks as $chunk) {
                $uri = data_get($chunk, 'web.uri');
                if (is_string($uri) && $uri !== '') {
                    $sources[] = $uri;
                }
            }
        }

        return collect($sources)
            ->map(fn (string $source): string => trim($source))
            ->filter(fn (string $source): bool => filter_var($source, FILTER_VALIDATE_URL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    private function normaliseConfidence(mixed $value): string
    {
        $confidence = strtoupper(substr(trim((string) $value), 0, 1));

        return in_array($confidence, ['A', 'B', 'C', 'D'], true) ? $confidence : 'D';
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function normaliseVariantValueMap(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $map = [];
        foreach ($values as $key => $value) {
            if (is_array($value) && array_key_exists('axis', $value)) {
                $axis = trim((string) $value['axis']);
                $map[$axis] = $this->stringList($value['values'] ?? []);
                continue;
            }

            if (is_string($key)) {
                $map[$key] = $this->stringList($value);
            }
        }

        return collect($map)
            ->filter(fn (array $list, string $axis): bool => $axis !== '' || $list !== [])
            ->mapWithKeys(fn (array $list, string $axis): array => [$axis ?: 'Variant' => $list])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $values
     */
    private function csv(array $values): string
    {
        return implode(', ', $values);
    }
}
