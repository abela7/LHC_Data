<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ShopSkuNamingSuggestionService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function suggest(array $input): array
    {
        $fallback = $this->fallbackNames($input);
        $apiKey = (string) config('services.openrouter.api_key');

        if ($apiKey === '') {
            return [
                'ai_used' => false,
                'model' => null,
                'names' => $fallback,
                'warnings' => ['AI naming is unavailable because OPENROUTER_API_KEY is not configured.'],
            ];
        }

        try {
            $result = $this->openRouterNames($input, $fallback);
            $names = $this->mergeNames($fallback, $result['names'] ?? []);

            return [
                'ai_used' => true,
                'model' => $result['model'] ?? config('services.openrouter.model'),
                'names' => $names,
                'warnings' => $result['warnings'] ?? [],
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'ai_used' => false,
                'model' => null,
                'names' => $fallback,
                'warnings' => ['AI naming failed; deterministic names were used. '.$exception->getMessage()],
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, array<string, mixed>>
     */
    private function fallbackNames(array $input): array
    {
        $base = $this->baseName($input);

        return collect($input['sku_rows'] ?? [])
            ->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row) use ($base): array {
                $nameLabel = $this->variantNameLabel($row, $input['common_variants'] ?? []);
                $name = $this->clean(trim($base.' '.$nameLabel));

                return [
                    'key' => $this->clean($row['key'] ?? ''),
                    'suggested_name' => $name !== '' ? $name : $this->clean($row['suggested_name'] ?? ''),
                    'confidence' => 'B',
                    'reason' => 'Generated from brand, family name, and non-common variants.',
                ];
            })
            ->filter(fn (array $row): bool => $row['key'] !== '' && $row['suggested_name'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function baseName(array $input): string
    {
        $brand = $this->clean($input['brand_name'] ?? '');
        $family = $this->clean($input['family_name'] ?? '');

        if ($brand !== '' && Str::startsWith(Str::lower($family), Str::lower($brand))) {
            return $family;
        }

        return $this->clean(trim($brand.' '.$family));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function variantNameLabel(array $row, mixed $commonVariants = []): string
    {
        $hasNameLabel = array_key_exists('name_label', $row);
        $label = $this->clean($row['name_label'] ?? '');
        if (! $hasNameLabel && $label === '') {
            $label = $this->clean($row['label'] ?? '');
        }

        $commonParts = collect($this->cleanCommonValues($row['common_attributes'] ?? $commonVariants))
            ->map(fn (string $value): string => Str::lower($value))
            ->all();

        return collect(preg_split('/\s*\/\s*/', $label) ?: [])
            ->map(fn (string $part): string => $this->clean($part))
            ->filter(fn (string $part): bool => $part !== '' && Str::lower($part) !== 'standard')
            ->reject(fn (string $part): bool => in_array(Str::lower($part), $commonParts, true))
            ->implode(' ');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>> $fallback
     * @return array<string, mixed>
     */
    private function openRouterNames(array $input, array $fallback): array
    {
        $model = (string) config('services.openrouter.model', 'google/gemini-3-flash-preview');
        $prompt = $this->prompt($input, $fallback);
        $body = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You produce clean retail sellable product names. Return only valid JSON.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.05,
            'max_tokens' => 2500,
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
            ->timeout((int) config('services.openrouter.timeout', 45))
            ->retry(1, 500)
            ->withHeaders([
                'Authorization' => 'Bearer '.((string) config('services.openrouter.api_key')),
                'HTTP-Referer' => (string) config('services.openrouter.site_url', config('app.url')),
                'X-Title' => (string) config('services.openrouter.app_name', config('app.name')),
            ])
            ->acceptJson()
            ->asJson()
            ->post('https://openrouter.ai/api/v1/chat/completions', $body);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: $response->body();
            throw new RuntimeException('OpenRouter SKU naming failed: '.Str::limit((string) $message, 500, ''));
        }

        $payload = $response->json();
        $decoded = $this->decode((string) data_get($payload, 'choices.0.message.content', ''));

        return [
            'model' => (string) data_get($payload, 'model', $model),
            'names' => $decoded['names'] ?? [],
            'warnings' => $decoded['warnings'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>> $fallback
     */
    private function prompt(array $input, array $fallback): string
    {
        $examples = $this->localExamples($input);
        $trustedSites = $this->trustedReferenceSites();
        $payload = [
            'rules' => [
                'Return one name for every key in sku_rows.',
                'Use the shop/family product as the base product identity.',
                'Use main and sub variant values to make each sellable SKU unique.',
                'Do not include common_variants in the suggested_name because they apply to every SKU in this intake.',
                'Do not invent barcodes, prices, descriptions, claims, extra variants, or new products.',
                'Keep brand separate conceptually, but include brand in the final customer/staff product name.',
                'Prefer concise Janson/catalogue-style names from examples when available.',
                'If the family/product identity is unclear, check the official brand site first when obvious, then the reference_site_priority list in order.',
                'Use reference sites only to clarify product identity and naming style; never mention source names in the sellable product name.',
            ],
            'input' => [
                'brand_name' => $this->clean($input['brand_name'] ?? ''),
                'department_name' => $this->clean($input['department_name'] ?? ''),
                'product_type_name' => $this->clean($input['product_type_name'] ?? ''),
                'family_name' => $this->clean($input['family_name'] ?? ''),
                'variant_main_axis' => $this->clean($input['variant_main_axis'] ?? ''),
                'variant_sub_axis' => $this->clean($input['variant_sub_axis'] ?? ''),
                'common_variants_to_exclude_from_names' => $input['common_variants'] ?? [],
                'sku_rows' => collect($input['sku_rows'] ?? [])->map(fn ($row): array => [
                    'key' => $this->clean($row['key'] ?? ''),
                    'variant_label_for_display' => $this->clean($row['label'] ?? ''),
                    'variant_label_for_name_excluding_common' => $this->clean($row['name_label'] ?? ''),
                    'current_suggested_name' => $this->clean($row['suggested_name'] ?? ''),
                ])->values()->all(),
                'deterministic_names' => $fallback,
                'local_janson_style_examples' => $examples,
                'reference_site_priority_when_confused' => $trustedSites,
            ],
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return trim(<<<PROMPT
You are helping a real UK beauty shop name sellable POS/ecommerce products after the human has mapped variants.

Create clean sellable product names only. The human will scan barcodes after accepting names.

Critical rule:
- common_variants are shared attributes for this intake. Do NOT include them in suggested_name.
- Only include family/product identity and the SKU's own main/sub variant values.
- Trust the human-created variant map. Do not add, remove, or merge SKUs from web search.
- If web evidence is needed, check the official brand/manufacturer site first when obvious.
- If no official page is obvious, prefer these reference sites in this order: {$this->csv($trustedSites)}.
- Use wider web only if those sites do not clarify the product identity.

Input:
{$json}

Return only valid JSON:
{
  "warnings": [],
  "names": [
    {
      "key": "",
      "suggested_name": "",
      "confidence": "A|B|C|D",
      "reason": ""
    }
  ]
}
PROMPT);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, array<string, mixed>>
     */
    private function localExamples(array $input): array
    {
        $brand = $this->clean($input['brand_name'] ?? '');
        $type = $this->clean($input['product_type_name'] ?? '');
        $brandProbe = $brand !== '' ? '%'.$this->escapeLike($brand).'%' : '%';

        return DB::table('products as p')
            ->join('product_families as pf', 'pf.id', '=', 'p.product_family_id')
            ->where('pf.root_catalogue_name', '!=', 'Hair Extensions')
            ->where('pf.brand_name', 'like', $brandProbe)
            ->when($type !== '', fn ($query) => $query->where('pf.product_type_name', $type))
            ->select('pf.brand_name', 'pf.family_name', 'pf.product_type_name', 'p.name')
            ->orderByDesc('p.id')
            ->limit(18)
            ->get()
            ->map(fn ($row): array => [
                'brand' => $this->clean($row->brand_name),
                'family' => $this->clean($row->family_name),
                'product_type' => $this->clean($row->product_type_name),
                'sellable_name' => $this->clean($row->name),
            ])
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $fallback
     * @param array<int, array<string, mixed>> $aiNames
     * @return array<int, array<string, mixed>>
     */
    private function mergeNames(array $fallback, array $aiNames): array
    {
        $aiByKey = collect($aiNames)
            ->filter(fn ($row): bool => is_array($row))
            ->mapWithKeys(fn (array $row): array => [$this->clean($row['key'] ?? '') => [
                'key' => $this->clean($row['key'] ?? ''),
                'suggested_name' => $this->clean($row['suggested_name'] ?? ''),
                'confidence' => $this->confidence($row['confidence'] ?? null),
                'reason' => $this->clean($row['reason'] ?? ''),
            ]])
            ->filter(fn (array $row, string $key): bool => $key !== '' && $row['suggested_name'] !== '');

        return collect($fallback)
            ->map(function (array $row) use ($aiByKey): array {
                $ai = $aiByKey->get($row['key']);

                return $ai ?: $row;
            })
            ->values()
            ->all();
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
            throw new RuntimeException('OpenRouter returned non-JSON SKU names.');
        }

        $decoded['warnings'] = $this->stringList($decoded['warnings'] ?? []);

        return $decoded;
    }

    /**
     * @return array<int, string>
     */
    private function cleanCommonValues(mixed $groups): array
    {
        if (! is_array($groups)) {
            return [];
        }

        return collect($groups)
            ->filter(fn ($group): bool => is_array($group))
            ->flatMap(fn (array $group): array => is_array($group['values'] ?? null) ? $group['values'] : [])
            ->map(fn (mixed $value): string => $this->clean($value))
            ->filter()
            ->values()
            ->all();
    }

    private function confidence(mixed $value): string
    {
        $confidence = strtoupper(substr(trim((string) $value), 0, 1));

        return in_array($confidence, ['A', 'B', 'C', 'D'], true) ? $confidence : 'B';
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
            ->map(fn (mixed $value): string => $this->clean($value))
            ->filter()
            ->values()
            ->all();
    }

    private function clean(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $value)) ?: '';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return array<int, string>
     */
    private function trustedReferenceSites(): array
    {
        return [
            'shabacosmetics.com',
            'beautyflex.co.uk',
            'britshairandbeauty.co.uk',
            'beautizone.co.uk',
            'tjbeautyproducts.co.uk',
        ];
    }

    /**
     * @param array<int, string> $values
     */
    private function csv(array $values): string
    {
        return implode(', ', $values);
    }
}
