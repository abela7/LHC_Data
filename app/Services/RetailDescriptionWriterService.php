<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generates a customer-facing ecommerce description for a product family by
 * (a) asking Gemini (via OpenRouter) to search the web for the official
 *     manufacturer/brand description, then
 * (b) cleaning that text — stripping references to other retailers and links —
 *     so the result reads as our own store's copy.
 *
 * If the model cannot verify the product from the web it falls back to writing
 * a generic but accurate description from the structured fields the caller
 * provides (brand, family name, line, product type, variant axes).
 */
class RetailDescriptionWriterService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @param array{
     *     brand_name?: ?string,
     *     family_name?: ?string,
     *     line_name?: ?string,
     *     product_type?: ?string,
     *     department?: ?string,
     *     variant_axes?: array<int, string>,
     *     variant_samples?: array<string, array<int, string>>,
     *     source_url?: ?string,
     *     existing_description?: ?string,
     * } $input
     * @return array{
     *     description: string,
     *     confidence: 'A'|'B'|'C'|'D',
     *     used_search: bool,
     *     source_urls: array<int, string>,
     *     notes: string,
     *     model: string,
     * }
     */
    public function suggest(array $input): array
    {
        $apiKey = (string) config('services.openrouter.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenRouter API key is missing. Add OPENROUTER_API_KEY to .env.');
        }

        $model = (string) config('services.openrouter.model', 'google/gemini-3-flash-preview');
        $timeout = (int) config('services.openrouter.timeout', 45);

        $prompt = $this->prompt($input);
        $body = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a UK hair & beauty retailer\'s in-house product copywriter. You write clear, factual, customer-friendly ecommerce descriptions. Return only valid JSON.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.3,
            'max_tokens' => 1200,
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
            throw new RuntimeException('OpenRouter description generation failed: '.Str::limit((string) $message, 500, ''));
        }

        $payload = $response->json();
        $text = (string) data_get($payload, 'choices.0.message.content', '');
        $decoded = $this->decode($text);

        return [
            'description' => $this->cleanDescription($decoded['description'] ?? '', $input),
            'confidence' => $this->normaliseConfidence($decoded['confidence'] ?? null),
            'used_search' => (bool) ($decoded['used_search'] ?? false),
            'source_urls' => $this->sourceUrls($payload, $decoded),
            'notes' => trim((string) ($decoded['notes'] ?? '')),
            'model' => (string) data_get($payload, 'model', $model),
        ];
    }

    /**
     * Build the prompt that drives the model.
     *
     * @param array<string, mixed> $input
     */
    private function prompt(array $input): string
    {
        $brand = trim((string) ($input['brand_name'] ?? ''));
        $family = trim((string) ($input['family_name'] ?? ''));
        $line = trim((string) ($input['line_name'] ?? ''));
        $productType = trim((string) ($input['product_type'] ?? ''));
        $department = trim((string) ($input['department'] ?? ''));
        $sourceUrl = trim((string) ($input['source_url'] ?? ''));
        $existing = trim((string) ($input['existing_description'] ?? ''));
        $variantAxes = $this->joinList($input['variant_axes'] ?? []);
        $variantSamples = $this->renderVariantSamples($input['variant_samples'] ?? []);

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
        $preferredCsv = implode(', ', $preferredSources);
        $currentDate = date('Y-m-d');

        return trim(<<<PROMPT
Current date: {$currentDate}.

ROLE
You write ecommerce product descriptions for a UK hair and beauty retailer. The text you produce will appear on OUR shop's product page — not a third party's.

PRODUCT CONTEXT
- Brand: {$brand}
- Product family / name: {$family}
- Line: {$line}
- Product type: {$productType}
- Department: {$department}
- Variant axes the shop offers: {$variantAxes}
- Variant sample values: {$variantSamples}
- User-supplied source URL (may be empty): {$sourceUrl}
- Existing description (may be empty — improve only if clearly weak): {$existing}

PROCESS
1. If a user-supplied source URL exists, check it first — it is the strongest signal.
2. Search the web for "{$brand} {$family}" (and "{$brand} {$line}" if helpful). Look for the brand's OWN product page first.
3. If the brand site is unhelpful, look on these trusted UK reseller sites: {$preferredCsv}. Read the description shown there but do NOT copy verbatim.
4. Synthesise a clean, customer-facing description from what the web actually says about this product. Stay factual.
5. If web evidence is missing, weak, or conflicting, write a generic but accurate description from the structured fields above — and set confidence to "D" and used_search to false.

WRITING RULES (very important)
- 80 to 220 words.
- Use second person ("you") sparingly; prefer descriptive product copy.
- Two short paragraphs, OR one paragraph followed by 3-5 short bullet points using "•" at the start of each bullet line. Do not use Markdown list syntax.
- Lead with what the product IS and what it is best for.
- Include verifiable facts: fibre, texture, length range, pack count, finish, suitable styles, suitable hair types — only when actually true for THIS product.
- Avoid superlatives and hype words ("amazing", "best ever", "must-have").
- NEVER mention competitor retailers or sites by name. Strip phrases like "available at Beautyflex", "shop at TJ Beauty", "as seen on Brits Hair & Beauty", and any URLs.
- NEVER include phone numbers, emails, prices, or stock claims.
- NEVER fabricate certifications, ingredients, awards, or technical claims that aren't on the brand's own page.
- Keep the brand name itself ("{$brand}") — that is allowed and expected.
- UK English spelling (colour, fibre, organisation).
- Plain text only — no markdown headings, no HTML tags.

OUTPUT
Return ONLY valid JSON with these exact keys (no markdown fences, no commentary):
{
  "description": "...the cleaned customer-facing description...",
  "confidence": "A|B|C|D",
  "used_search": true,
  "source_urls": ["https://..."],
  "notes": "one short sentence about how confident you are and why"
}

Confidence rules:
- A: brand's official page found and matched; description grounded in it.
- B: trusted UK reseller page matched; description grounded in it.
- C: only partial / indirect web evidence; description is mostly inferred.
- D: no usable web evidence; description was generated from the structured fields only — set used_search to false.
PROMPT);
    }

    /**
     * Strip residual retailer names, URLs, and markdown that the model may
     * have left in despite the prompt rules, so the text is safe to use as-is.
     *
     * @param array<string, mixed> $input
     */
    private function cleanDescription(string $description, array $input): string
    {
        $text = trim((string) $description);
        if ($text === '') {
            return '';
        }

        // Remove URLs (any protocol or bare domain).
        $text = preg_replace('~https?://\S+~i', '', $text) ?? $text;
        $text = preg_replace('~\bwww\.[\w.\-]+\b~i', '', $text) ?? $text;

        // Strip common reseller mentions that may slip in.
        $stripPhrases = [
            'shabacosmetics', 'shaba cosmetics',
            'beautyflex',
            'britshairandbeauty', 'brits hair and beauty', 'brits hair & beauty',
            'beautizone',
            'tjbeautyproducts', 'tj beauty', 'tj beauty products',
            'citruscosmetics', 'citrus cosmetics',
            'mamado',
            'feme.com',
            'pakcosmetics', 'pak cosmetics',
            'available at', 'shop at', 'as seen on', 'order online at',
        ];
        foreach ($stripPhrases as $phrase) {
            $text = preg_replace('~\b'.preg_quote($phrase, '~').'\b[^.\n]*[.\n]?~i', '', $text) ?? $text;
        }

        // Strip stray markdown headings/list syntax.
        $text = preg_replace('~^[#>*\-]+\s*~m', '', $text) ?? $text;

        // Collapse triple+ newlines and trim per-line whitespace.
        $text = preg_replace("~[ \t]+\n~", "\n", $text) ?? $text;
        $text = preg_replace("~\n{3,}~", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @param array<int, string> $values
     */
    private function joinList(array $values): string
    {
        $clean = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $values)));

        return $clean === [] ? '(none provided)' : implode(', ', $clean);
    }

    /**
     * @param array<string, array<int, string>> $samples
     */
    private function renderVariantSamples(array $samples): string
    {
        if ($samples === []) {
            return '(none provided)';
        }

        $parts = [];
        foreach ($samples as $axis => $values) {
            $list = array_slice(array_values(array_filter($values, fn ($v) => $v !== null && $v !== '')), 0, 6);
            if ($list === []) {
                continue;
            }
            $parts[] = sprintf('%s: %s', $axis, implode(' / ', $list));
        }

        return $parts === [] ? '(none provided)' : implode(' | ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $text): array
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
            throw new RuntimeException('OpenRouter returned a non-JSON description.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $decoded
     * @return array<int, string>
     */
    private function sourceUrls(array $payload, array $decoded): array
    {
        $sources = is_array($decoded['source_urls'] ?? null) ? $decoded['source_urls'] : [];
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
            ->map(fn ($v): string => trim((string) $v))
            ->filter(fn (string $v): bool => filter_var($v, FILTER_VALIDATE_URL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return 'A'|'B'|'C'|'D'
     */
    private function normaliseConfidence(mixed $value): string
    {
        $confidence = strtoupper(substr(trim((string) $value), 0, 1));

        return in_array($confidence, ['A', 'B', 'C', 'D'], true) ? $confidence : 'D';
    }
}
