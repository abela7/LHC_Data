<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use RuntimeException;

class OpenRouterRetailNamingService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function suggest(ProductFamily $family, ?string $requestedModel = null): array
    {
        $apiKey = (string) config('services.openrouter.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenRouter API key is missing. Add OPENROUTER_API_KEY to .env.');
        }

        $family->loadMissing([
            'brand',
            'catalogueStyle',
            'categoryAssignments.scaffold',
            'categoryAssignments.axis',
            'categoryAssignments.node.parent',
            'variantGroups.options',
            'products.price',
            'products.inventoryLevels',
            'products.posProfile',
            'products.ecommerceProfile',
            'products.variantValues.group',
            'products.variantValues.option',
        ]);

        $model = $this->model(trim((string) $requestedModel));
        $prompt = $this->prompt($family);
        $body = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a precise retail PIM naming assistant. Return only valid JSON.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.05,
            'max_tokens' => 9000,
        ];

        $response = $this->http
            ->timeout((int) config('services.openrouter.timeout', 45))
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
            throw new RuntimeException('OpenRouter naming suggestion failed: '.Str::limit((string) $message, 500, ''));
        }

        $payload = $response->json();
        $result = $this->decodeResult((string) data_get($payload, 'choices.0.message.content', ''));
        $suggestions = $this->normaliseSuggestions($result['suggestions'] ?? [], $family);
        $warnings = $this->stringList($result['warnings'] ?? []);
        $expectedIds = $family->products->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $actualIds = collect($suggestions)->pluck('product_id')->map(fn ($id): int => (int) $id)->values();
        $missingCount = $expectedIds->diff($actualIds)->count();

        if ($missingCount > 0) {
            $warnings[] = 'AI did not return suggestions for '.$missingCount.' sellable product'.($missingCount === 1 ? '' : 's').'.';
        }

        return [
            'model' => (string) data_get($payload, 'model', $model),
            'prompt_hash' => hash('sha256', $prompt),
            'family_id' => $family->id,
            'rules' => [
                'receipt_name_max' => 35,
                'inventory_name_max' => 80,
                'ecommerce_title_max' => 150,
            ],
            'warnings' => $warnings,
            'suggestions' => $suggestions,
            'raw_response' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function model(string $requestedModel): string
    {
        $allowed = collect(config('services.openrouter.models', []))
            ->pluck('id')
            ->filter()
            ->all();

        if ($requestedModel !== '' && in_array($requestedModel, $allowed, true)) {
            return $requestedModel;
        }

        return (string) config('services.openrouter.model', 'google/gemini-3-flash-preview');
    }

    private function prompt(ProductFamily $family): string
    {
        $context = [
            'standard' => 'International retail PIM naming based on GS1-style separation of brand, functional name, variant, and channel-specific short/long names.',
            'rules' => [
                'Use only the provided shop catalogue data. Do not invent variants, lengths, colours, pack counts, materials, barcodes, SKUs, or products.',
                'Every suggestion must refer to an existing product_id from the input.',
                'Receipt name is a short shelf-tag/receipt name. Max 35 characters. Keep it human readable. Use consistent abbreviations only when needed.',
                'Inventory/POS name is an operational short name for staff search and inventory lists. Max 80 characters.',
                'Ecommerce title is the customer-facing product title. Max 150 characters. Include brand, family/style, product type when useful, and key variant values.',
                'Do not include prices, stock, supplier, barcode, SKU code, promotional phrases, emoji, all-caps ecommerce titles, or claims not present in the data.',
                'Colour code and length are critical differentiators for hair extension SKUs.',
                'Return one suggestion for every sellable product in the input.',
            ],
            'family' => [
                'id' => $family->id,
                'brand' => $family->brand_name,
                'line' => $family->line_name,
                'product_type' => $family->product_type_name,
                'family_name' => $family->family_name,
                'status' => $family->status,
                'category_paths' => $family->categoryAssignments->map(function ($assignment): array {
                    return [
                        'assignment_type' => $assignment->assignment_type,
                        'scaffold' => $assignment->scaffold?->name,
                        'axis' => $assignment->axis?->name,
                        'node' => $assignment->node?->name,
                        'parent_node' => $assignment->node?->parent?->name,
                    ];
                })->values()->all(),
                'variant_groups' => $family->variantGroups->map(fn ($group): array => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'variant_type' => $group->variant_type,
                    'options' => $group->options->map(fn ($option): array => [
                        'id' => $option->id,
                        'label' => $option->label,
                        'value' => $option->value,
                    ])->values()->all(),
                ])->values()->all(),
            ],
            'sellable_products' => $family->products->map(function (Product $product): array {
                $variants = $product->variantValues
                    ->sortBy(fn ($value) => sprintf('%04d:%s', $value->group?->sort_order ?? 0, $value->option?->label ?? ''))
                    ->map(fn ($value): array => [
                        'group' => $value->group?->name,
                        'option' => $value->option?->label,
                        'value' => $value->option?->value,
                    ])
                    ->values()
                    ->all();

                return [
                    'product_id' => $product->id,
                    'current_product_name' => $product->name,
                    'current_inventory_name' => $product->inventory_name,
                    'current_receipt_name' => $product->receipt_name ?: $product->posProfile?->receipt_name,
                    'current_ecommerce_title' => $product->ecommerceProfile?->online_title,
                    'sku' => $product->sku,
                    'barcode_present' => filled($product->barcode),
                    'variants' => $variants,
                ];
            })->values()->all(),
        ];

        $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return trim(<<<PROMPT
You are helping a real UK retail shop build clean product names for POS, receipts, inventory and ecommerce.

Follow the international retail/PIM rule:
- Structured data is the truth.
- Product master/family data stays separate from variant dimensions.
- Receipt/shelf-tag names are short.
- Ecommerce titles are longer customer-facing titles.
- Operational POS/inventory names are concise staff-facing names.

Input data:
{$json}

Return only valid JSON in this exact shape:
{
  "family_id": {$family->id},
  "warnings": [],
  "suggestions": [
    {
      "product_id": 123,
      "receipt_name": "",
      "inventory_name": "",
      "ecommerce_title": "",
      "confidence": "A|B|C|D",
      "reason": ""
    }
  ]
}

Rules:
- Include exactly one suggestion for every product_id from sellable_products.
- Do not include product IDs that were not provided.
- receipt_name must be 35 characters or less.
- inventory_name must be 80 characters or less.
- ecommerce_title must be 150 characters or less.
- Confidence A means the name follows all rules from clear structured variant data.
- Confidence B means minor wording uncertainty but still safe for review.
- Confidence C/D means the human should review carefully.
- Keep names consistent across all rows.
- For hair extension colour codes, never use a bare hash format such as "#1" or "#1B" in customer, POS, inventory, or receipt names. Write them as "Colour 1", "Colour 1B", "Colour 613", or similar.
- Do not translate colour codes into colour names unless the input already gives the colour name. If both are known, use the colour name only when it is already present in the shop data.
- For lengths, use a readable format like 14 inch in ecommerce titles and 14" in short names.
- For pack counts, keep values such as 2X or 3X when present.
PROMPT);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResult(string $text): array
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
            throw new RuntimeException('OpenRouter returned non-JSON naming suggestions.');
        }

        return $decoded;
    }

    /**
     * @param mixed $suggestions
     * @return array<int, array<string, mixed>>
     */
    private function normaliseSuggestions(mixed $suggestions, ProductFamily $family): array
    {
        if (! is_array($suggestions)) {
            return [];
        }

        $validProducts = $family->products->keyBy('id');

        return collect($suggestions)
            ->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row) use ($validProducts): ?array {
                $productId = (int) ($row['product_id'] ?? 0);
                $product = $validProducts->get($productId);
                if (! $product) {
                    return null;
                }

                return [
                    'product_id' => $productId,
                    'current' => [
                        'product_name' => $product->name,
                        'receipt_name' => $product->receipt_name ?: $product->posProfile?->receipt_name ?: $product->name,
                        'inventory_name' => $product->inventory_name ?: $product->name,
                        'ecommerce_title' => $product->ecommerceProfile?->online_title ?: $product->name,
                    ],
                    'suggested' => [
                        'receipt_name' => $this->cleanName((string) ($row['receipt_name'] ?? ''), 35),
                        'inventory_name' => $this->cleanName((string) ($row['inventory_name'] ?? ''), 80),
                        'ecommerce_title' => $this->cleanName((string) ($row['ecommerce_title'] ?? ''), 150),
                    ],
                    'confidence' => $this->normaliseConfidence($row['confidence'] ?? null),
                    'reason' => Str::limit(trim((string) ($row['reason'] ?? '')), 240, ''),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function cleanName(string $value, int $maxLength): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($value)) ?: '';
        $clean = str_replace(['£', '$', '€'], '', $clean);

        $clean = $this->normaliseColourCodesInName($clean);

        return Str::limit($clean, $maxLength, '');
    }

    private function normaliseColourCodesInName(string $value): string
    {
        $clean = preg_replace('/\b(?:colou?r|shade)\s*#\s*([A-Za-z0-9][A-Za-z0-9\/.\-]*)\b/i', 'Colour $1', $value) ?: $value;
        $clean = preg_replace('/(?<![A-Za-z0-9])#\s*([A-Za-z0-9][A-Za-z0-9\/.\-]*)\b/', 'Colour $1', $clean) ?: $clean;

        return preg_replace('/\s+/', ' ', trim($clean)) ?: '';
    }

    private function normaliseConfidence(mixed $value): string
    {
        $confidence = strtoupper(substr(trim((string) $value), 0, 1));

        return in_array($confidence, ['A', 'B', 'C', 'D'], true) ? $confidence : 'D';
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
}
