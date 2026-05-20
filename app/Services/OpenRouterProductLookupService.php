<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class OpenRouterProductLookupService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @return array<int, array{id:string,name:string}>
     */
    public function models(): array
    {
        return collect(config('services.openrouter.models', []))
            ->map(fn (array $model): array => [
                'id' => (string) ($model['id'] ?? ''),
                'name' => (string) ($model['name'] ?? ($model['id'] ?? 'Model')),
            ])
            ->filter(fn (array $model): bool => $model['id'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param array{brand_name:string, observed_product_name:string, source_url?:?string, ai_model?:?string} $input
     * @return array<string, mixed>
     */
    public function suggest(array $input): array
    {
        $apiKey = (string) config('services.openrouter.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenRouter API key is missing. Add OPENROUTER_API_KEY to .env.');
        }

        $model = $this->model((string) ($input['ai_model'] ?? ''));
        $timeout = (int) config('services.openrouter.timeout', 45);
        $prompt = $this->prompt($input);
        $body = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a precise UK hair and cosmetics product identification assistant. Return only valid JSON.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.1,
            'max_tokens' => 1400,
        ];

        if ((bool) config('services.openrouter.web_search', true)) {
            $body['tools'] = [[
                'type' => 'openrouter:web_search',
                'parameters' => [
                    'engine' => 'auto',
                    'max_results' => (int) config('services.openrouter.web_search_max_results', 4),
                    'max_total_results' => (int) config('services.openrouter.web_search_max_total_results', 8),
                    'search_context_size' => 'low',
                ],
            ]];
        }

        $response = $this->http
            ->timeout($timeout)
            ->retry(1, 500)
            ->withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'HTTP-Referer' => (string) config('services.openrouter.site_url', config('app.url')),
                'X-Title' => (string) config('services.openrouter.app_name', config('app.name')),
            ])
            ->acceptJson()
            ->asJson()
            ->post('https://openrouter.ai/api/v1/chat/completions', $body);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: $response->body();
            throw new RuntimeException('OpenRouter lookup failed: '.Str::limit((string) $message, 500, ''));
        }

        $payload = $response->json();
        $text = (string) data_get($payload, 'choices.0.message.content', '');
        $suggestion = $this->decodeSuggestion($text);
        $sources = $this->sourceUrls($payload, $suggestion);

        return [
            'model' => (string) data_get($payload, 'model', $model),
            'prompt_hash' => hash('sha256', $prompt),
            'suggestion' => $suggestion,
            'confidence' => $this->normaliseConfidence($suggestion['confidence'] ?? null),
            'source_urls' => $sources,
            'raw_response' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function model(string $requestedModel): string
    {
        $allowed = collect($this->models())->pluck('id')->all();
        if ($requestedModel !== '' && in_array($requestedModel, $allowed, true)) {
            return $requestedModel;
        }

        return (string) config('services.openrouter.model', 'google/gemini-3-flash-preview');
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
        $currentDate = date('Y-m-d');

        return trim(<<<PROMPT
Current date: {$currentDate}.

You are an AI assistant embedded inside a shop-floor product intake form for a UK hair and cosmetics retailer.
The human is physically standing in the shop and typing what they can see on the packaging. Your job is to act like a fast internet verification assistant beside them.

Important boundary:
- This is NOT the final catalogue builder.
- This is NOT the final POS/inventory/ecommerce product publisher.
- You must only suggest intake fields that the human can approve or ignore section-by-section.
- The human will continue typing manually; your result should be helpful but never force a decision.

Input:
- Brand: {$input['brand_name']}
- Product name observed on pack: {$input['observed_product_name']}
- User supplied source URL: {$sourceUrl}

Use internet search/source checking. Follow this exact research process:
1. If the user supplied a source URL, check it first and treat it as the strongest clue.
2. Search the exact brand + observed product name.
3. Search the exact observed product name without the brand only if the exact brand search is weak.
4. Prefer these trusted source domains before wider internet search: {$this->csv($preferredSources)}.
5. For hair extension products, supplier/retailer pages with exact product titles and variant lists are acceptable evidence.
6. Do not rely on memory when search results disagree.
7. Do not invent variants, lengths, colours, pack counts, fibres, or claims. If the internet source does not clearly prove it, omit it or reduce confidence.
8. Keep brand separate from product name. Do not put the brand into product_type unless it is part of the observed product line name.
9. If you are confused, if multiple different products match, if the brand/product relationship is unclear, or if search evidence is weak, do not guess. Return an "I don't know" result with confidence "D".
10. A safe "I don't know" result is better than a confident wrong answer.

Hair extension field meaning:
- suggested_product_name: cleaned observed product line/name the human can use on the intake form.
- product_type: broad catalogue type only. You MUST use one of these exact strings when possible: Braiding Hair, Bulk Hair, Crochet, Twist & Loc Hair, Ponytails & Hair Pieces, Wigs, Weave / Weft. If the source says crochet hair, return "Crochet, Twist & Loc Hair"; if it says braids or braid hair, return "Braiding Hair"; if it says bulk, return "Bulk Hair".
- style_family: the line/style family, for example Passion Twist, French Curl, Ultra Braid, Pre-Stretched 2x 46, Butterfly Locs, Water Wave.
- variant_axes: only attributes likely used to create sellable variants, for example Length, Colour, Pack count, Texture, Size, Material.
- likely_variant_values: only values clearly found from source pages. Do not guess a full colour chart unless the source lists it.
- product_clues: short packaging/search clues that help the human verify visually, such as 3X, pre-stretched, pre-looped, crochet, flame retardant, synthetic fibre.
- source_urls: pages you actually used.

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

Confidence rules:
- A: exact product verified on a reliable source.
- B: strong likely match but minor naming/variant uncertainty.
- C: product likely exists but classification or variant data is uncertain.
- D: not enough evidence, confusing evidence, conflicting evidence, or product not confidently identified.

If confidence is D, use this safe format:
{
  "confidence": "D",
  "suggested_product_name": "I don't know",
  "product_type": "",
  "style_family": "",
  "variant_axes": [],
  "likely_variant_values": [],
  "product_clues": [],
  "source_urls": [],
  "confidence_reason": "I could not verify this product clearly enough from reliable sources.",
  "notes": "Do not apply AI suggestion; review manually."
}
PROMPT);
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
            $start = strpos($clean, '{');
            $end = strrpos($clean, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenRouter returned a non-JSON suggestion.');
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
        $annotations = Arr::get($payload, 'choices.0.message.annotations', []);

        if (is_array($annotations)) {
            foreach ($annotations as $annotation) {
                $url = data_get($annotation, 'url_citation.url') ?: data_get($annotation, 'url');
                if (is_string($url) && $url !== '') {
                    $sources[] = $url;
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
