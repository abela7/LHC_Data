<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAiRetailNamingService
{
    private const CHUNK_SIZE = 20;

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return array<string, mixed>
     */
    public function suggest(ProductFamily $family, ?string $requestedModel = null): array
    {
        $this->loadFamilyRelations($family);

        if ($family->products->isEmpty()) {
            throw new RuntimeException('This family has no sellable products to name yet.');
        }

        $model = trim((string) $requestedModel) ?: (string) config('services.openai.retail_naming_model', 'gpt-5-nano');

        if ($family->products->count() > self::CHUNK_SIZE) {
            return $this->suggestInChunks($family, $model);
        }

        return $this->suggestForProducts($family, $family->products, $model);
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestInChunks(ProductFamily $family, string $model): array
    {
        $chunks = $family->products->chunk(self::CHUNK_SIZE);
        $suggestions = [];
        $warnings = [
            'Large family: naming was generated in '.$chunks->count().' batches of up to '.self::CHUNK_SIZE.' SKUs.',
        ];
        $resolvedModel = $model;
        $promptHashes = [];

        foreach ($chunks as $index => $chunk) {
            $batch = $this->suggestForProducts($family, $chunk->values(), $model);
            $resolvedModel = (string) ($batch['model'] ?? $resolvedModel);
            $promptHashes[] = (string) ($batch['prompt_hash'] ?? '');
            $suggestions = array_merge($suggestions, $batch['suggestions'] ?? []);
            $warnings = array_merge($warnings, $this->stringList($batch['warnings'] ?? []));
        }

        $expectedIds = $family->products->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $actualIds = collect($suggestions)->pluck('product_id')->map(fn ($id): int => (int) $id)->values();
        $missingCount = $expectedIds->diff($actualIds)->count();

        if ($missingCount > 0) {
            $warnings[] = 'AI did not return suggestions for '.$missingCount.' sellable product'.($missingCount === 1 ? '' : 's').'.';
        }

        return [
            'model' => $resolvedModel,
            'provider' => 'openai',
            'prompt_hash' => hash('sha256', implode('|', $promptHashes)),
            'family_id' => $family->id,
            'rules' => [
                'receipt_name_max' => 35,
                'inventory_name_max' => 80,
                'ecommerce_title_max' => 150,
            ],
            'warnings' => collect($warnings)->unique()->values()->all(),
            'suggestions' => $suggestions,
            'raw_response' => null,
        ];
    }

    /**
     * @param  Collection<int, Product>|iterable<int, Product>  $products
     * @return array<string, mixed>
     */
    private function suggestForProducts(ProductFamily $family, iterable $products, string $model): array
    {
        $productCollection = $products instanceof Collection ? $products : collect($products);
        $prompt = $this->prompt($family, $productCollection);
        $payload = $this->requestNamingPayload($prompt, $model, $productCollection->count());
        $result = $this->decodeResult($payload);
        $suggestions = $this->normaliseSuggestions($result['suggestions'] ?? [], $family);
        $warnings = $this->stringList($result['warnings'] ?? []);
        $expectedIds = $productCollection->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $actualIds = collect($suggestions)->pluck('product_id')->map(fn ($id): int => (int) $id)->values();
        $missingCount = $expectedIds->diff($actualIds)->count();

        if ($missingCount > 0) {
            $warnings[] = 'AI did not return suggestions for '.$missingCount.' sellable product'.($missingCount === 1 ? '' : 's').'.';
        }

        return [
            'model' => (string) data_get($payload, 'model', $model),
            'provider' => 'openai',
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

    /**
     * @return array<string, mixed>
     */
    private function requestNamingPayload(string $prompt, string $model, int $productCount): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is missing. Add OPENAI_API_KEY to .env on the server.');
        }

        $body = [
            'model' => $model,
            'instructions' => 'You are a precise retail PIM naming assistant for a UK beauty and hair-extension shop. Return only valid JSON that matches the requested schema.',
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $prompt],
                    ],
                ],
            ],
            'max_output_tokens' => $this->maxOutputTokensForCount($productCount),
            'reasoning' => [
                'effort' => 'minimal',
            ],
            'text' => [
                'verbosity' => 'low',
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'retail_naming_suggestions',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['family_id', 'warnings', 'suggestions'],
                        'properties' => [
                            'family_id' => ['type' => 'integer'],
                            'warnings' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'suggestions' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['product_id', 'receipt_name', 'inventory_name', 'ecommerce_title', 'confidence', 'reason'],
                                    'properties' => [
                                        'product_id' => ['type' => 'integer'],
                                        'receipt_name' => ['type' => 'string'],
                                        'inventory_name' => ['type' => 'string'],
                                        'ecommerce_title' => ['type' => 'string'],
                                        'confidence' => ['type' => 'string', 'enum' => ['A', 'B', 'C', 'D']],
                                        'reason' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $headers = [];
        if (filled(env('OPENAI_ORG_ID'))) {
            $headers['OpenAI-Organization'] = (string) env('OPENAI_ORG_ID');
        }
        if (filled(env('OPENAI_PROJECT_ID'))) {
            $headers['OpenAI-Project'] = (string) env('OPENAI_PROJECT_ID');
        }

        $timeout = max(60, (int) config('services.openai.timeout', 60));
        $response = $this->http
            ->timeout($timeout)
            ->retry(1, 500)
            ->withToken($apiKey)
            ->withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->post('https://api.openai.com/v1/responses', $body);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: $response->body();
            throw new RuntimeException('OpenAI naming suggestion failed: '.Str::limit((string) $message, 500, ''));
        }

        $payload = $response->json();
        $this->assertSuccessfulResponse($payload);

        return is_array($payload) ? $payload : [];
    }

    private function maxOutputTokensForCount(int $productCount): int
    {
        return min(16000, max(2500, 700 + ($productCount * 180)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSuccessfulResponse(array $payload): void
    {
        $status = (string) data_get($payload, 'status', '');

        if ($status === 'failed') {
            $message = (string) data_get($payload, 'error.message', 'Unknown OpenAI error.');
            throw new RuntimeException('OpenAI naming suggestion failed: '.Str::limit($message, 500, ''));
        }

        if ($status === 'incomplete') {
            $reason = (string) data_get($payload, 'incomplete_details.reason', 'max_output_tokens');
            throw new RuntimeException(
                'OpenAI naming response was incomplete ('.$reason.'). Try again; very large families are processed in batches automatically.'
            );
        }
    }

    private function loadFamilyRelations(ProductFamily $family): void
    {
        $family->loadMissing([
            'brand',
            'catalogueStyle',
            'categoryAssignments.scaffold',
            'categoryAssignments.axis',
            'categoryAssignments.node.parent',
            'variantGroups.options',
            'products.posProfile',
            'products.ecommerceProfile',
            'products.variantValues.group',
            'products.variantValues.option',
        ]);
    }

    /**
     * @param  Collection<int, Product>|null  $productsSubset
     */
    private function prompt(ProductFamily $family, ?Collection $productsSubset = null): string
    {
        $variantAnalysis = $this->variantAnalysis($family);
        $consumerStyleName = $this->consumerStyleName($family);
        $canonicalTitleBase = $this->canonicalTitleBase($family, $consumerStyleName);

        $context = [
            'standard' => 'Retail PIM naming with separate family/style identity, channel-specific names, and explicit variant dimensions.',
            'core_decision_rule' => 'Receipt names are short and should include only the variant values needed at till/shelf level. POS/inventory and ecommerce titles should carry the useful common variant dimensions when they help staff or customers understand the sellable product.',
            'naming_rules' => [
                'Use only the provided catalogue and variant data. Do not invent lengths, colours, pack counts, materials, SKUs, barcodes, claims, or product types.',
                'Every suggestion must refer to an existing product_id from sellable_products.',
                'Use canonical_title_base as the starting point for every generated name. It is already cleaned for customer-facing naming.',
                'Receipt name: max 35 characters. Use brand + short family/style + main variant|sub variant. Example: Cherish Bohemian 16"|1B. Do not include common pack/count variants in receipt names unless there is no main/sub variant.',
                'Inventory/POS name: max 80 characters. Use brand + consumer_style_name + useful common variants + differentiating variants. Use hyphen separators.',
                'Ecommerce title: max 150 characters. Use brand + consumer_style_name + common variant values before a pipe, then differentiating sub-variant after the pipe.',
                'Avoid duplicate product identity words. If line_name or family_name contains a broad grouping word that is already implied by consumer_style_name, do not include it. Example: line_name "BOHO", family_name "BOHO Bohemian Braid", consumer_style_name "Bohemian Braid" => use "Cherish Bohemian Braid", not "Cherish BOHO Bohemian Braid".',
                'Do not include both an abbreviation and its expanded style when they refer to the same identity, for example BOHO + Bohemian, Remy + Remy, Braid + Braid, Weave + Weave.',
                'Only include a product line/grouping word when it is a true customer-facing range that adds meaning and is not repeated by the style, for example Style Icon, Noble Gold, Premium Now, Empire, X-Pression Twisted Up.',
                'Do not put broad catalogue line words such as Braids, Crochet Braids, Weave On, or product_type into the generated names unless they are part of consumer_style_name.',
                'For length values, preserve measurement symbols: use 46" in POS/inventory and ecommerce; use inch only when the input does not contain a quote mark and a readable title needs it.',
                'For colour values, do not prefix ecommerce titles with "Colour" after the pipe. Use the value only, for example "| UV-PINK".',
                'For receipt names, use exact provided variant values and colour codes. Do not convert 1B, 1, 2, T530, UV-PINK, etc. into guessed colour names.',
                'For hair extension colour codes, never use a bare hash format such as "#1" or "#1B". Use "Colour 1" where a code needs a label.',
                'Never add question marks, exclamation marks, emojis, decorative symbols, or uncertainty markers to receipt_name, inventory_name, or ecommerce_title.',
                'If a value is uncertain, use the original provided variant value exactly. Do not mark uncertainty with "?", "(?)", "maybe", "approx", or similar text.',
                'Names must end with a letter, number, quote mark, or a valid hair colour code character. Do not end names with stray punctuation.',
                'Do not include prices, stock, supplier names, source site names, barcode, internal SKU code, promotional phrases, emojis, or all-caps ecommerce titles.',
                'Keep names consistent across every row in the family.',
            ],
            'example_for_this_kind_of_family' => [
                'input_product_name' => 'X-Pression Pre-Stretched Ultraviolet - 46 - Colour UV-PINK',
                'family_has_common_length' => '46"',
                'receipt_name_good' => 'X-Pression Ultraviolet 46"|UV-PINK',
                'inventory_name_good' => 'X-Pression Pre-Stretched Ultraviolet - 46" - UV-PINK',
                'ecommerce_title_good' => 'X-Pression Pre-Stretched Ultraviolet - 46" | UV-PINK',
                'why' => 'Receipt uses main variant|sub variant for fast till identification. POS/inventory and ecommerce can include longer separators and common context.',
            ],
            'family' => [
                'id' => $family->id,
                'brand' => $family->brand_name,
                'line' => $family->line_name,
                'product_type' => $family->product_type_name,
                'family_name' => $family->family_name,
                'consumer_style_name' => $consumerStyleName,
                'canonical_title_base' => $canonicalTitleBase,
                'title_base_rule' => 'Start names from canonical_title_base. Add only useful variant values. Do not re-add line_name or family_name words that make the title repeat itself.',
                'status' => $family->status,
                'source_url' => $family->source_url,
                'variant_analysis' => $variantAnalysis,
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
                    'is_common_to_family' => (bool) data_get($variantAnalysis, "groups.{$group->name}.is_common_to_family", false),
                    'common_value' => data_get($variantAnalysis, "groups.{$group->name}.common_value"),
                    'options' => $group->options->map(fn ($option): array => [
                        'id' => $option->id,
                        'label' => $option->label,
                        'value' => $option->value,
                    ])->values()->all(),
                ])->values()->all(),
            ],
            'sellable_products' => ($productsSubset ?? $family->products)->map(function (Product $product) use ($variantAnalysis): array {
                $variants = $product->variantValues
                    ->sortBy(fn ($value) => sprintf('%04d:%s', $value->group?->sort_order ?? 0, $value->option?->label ?? ''))
                    ->map(function ($value) use ($variantAnalysis): array {
                        $group = (string) $value->group?->name;

                        return [
                            'group' => $group,
                            'option' => $value->option?->label,
                            'value' => $value->option?->value,
                            'is_common_to_family' => (bool) data_get($variantAnalysis, "groups.{$group}.is_common_to_family", false),
                        ];
                    })
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
You are generating careful sellable product names for POS, receipt, inventory and ecommerce.

The data below is the only source of truth. The product family is already known; your job is not to identify the product from the internet. Your job is to produce sensible channel-specific names for each existing sellable product.

Input:
{$json}

Return JSON only:
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

Critical checks before returning:
- Include exactly one suggestion for every product_id from sellable_products.
- Do not include any product_id that was not provided.
- The receipt name must be <= 35 characters.
- The inventory name must be <= 80 characters.
- The ecommerce title must be <= 150 characters.
- Receipt names must use exact main variant|sub variant values where present, for example 16"|1B. Do not convert colour codes into colour words.
- If a variant group is common_to_family, normally omit pack/count values from receipt_name and include them in inventory_name/ecommerce_title when useful.
- If a variant group is not common_to_family, include it in every name because it differentiates the sellable SKU.
- Do not repeat grouping/path words around the style. If canonical_title_base is "Cherish Bohemian Braid", ecommerce_title must not become "Cherish BOHO Bohemian Braid".
- Do not put question marks, uncertainty markers, or decorative punctuation in receipt_name, inventory_name, or ecommerce_title.
PROMPT);
    }

    /**
     * @return array<string, mixed>
     */
    private function variantAnalysis(ProductFamily $family): array
    {
        $products = $family->products;
        $groups = [];

        foreach ($family->variantGroups as $group) {
            $values = $products
                ->map(function (Product $product) use ($group): ?string {
                    $value = $product->variantValues->first(fn ($variantValue) => (int) $variantValue->product_variant_group_id === (int) $group->id);

                    return $value?->option?->label;
                })
                ->filter(fn (?string $value): bool => filled($value))
                ->values();

            $uniqueValues = $values
                ->unique(fn (string $value): string => Str::lower($value))
                ->values();

            $groups[$group->name] = [
                'variant_type' => $group->variant_type,
                'is_common_to_family' => $products->isNotEmpty() && $uniqueValues->count() === 1 && $values->count() === $products->count(),
                'common_value' => $uniqueValues->count() === 1 ? $this->displayVariantValue($group->name, (string) $uniqueValues->first()) : null,
                'unique_values' => $uniqueValues->map(fn (string $value): string => $this->displayVariantValue($group->name, $value))->all(),
            ];
        }

        return [
            'product_count' => $products->count(),
            'groups' => $groups,
        ];
    }

    private function displayVariantValue(string $groupName, string $value): string
    {
        $value = trim($value);

        if (Str::lower($groupName) === 'length' && preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return $value.'"';
        }

        return $value;
    }

    private function canonicalTitleBase(ProductFamily $family, string $consumerStyleName): string
    {
        return trim(implode(' ', array_filter([
            trim((string) $family->brand_name),
            trim($consumerStyleName),
        ])));
    }

    private function consumerStyleName(ProductFamily $family): string
    {
        $styleName = trim((string) $family->catalogueStyle?->name);

        if ($styleName !== '') {
            return $styleName;
        }

        $name = trim((string) $family->family_name);

        foreach ([$family->brand_name, $family->line_name, $family->product_type_name] as $prefix) {
            $prefix = trim((string) $prefix);
            if ($prefix !== '' && Str::startsWith(Str::lower($name), Str::lower($prefix).' ')) {
                $name = trim(substr($name, strlen($prefix)));
            }
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function decodeResult(array $payload): array
    {
        $text = (string) data_get($payload, 'output_text', '');

        if ($text === '') {
            $text = collect((array) data_get($payload, 'output', []))
                ->flatMap(fn ($item): Collection => collect((array) ($item['content'] ?? [])))
                ->map(fn ($content): string => (string) ($content['text'] ?? ''))
                ->filter()
                ->implode("\n");
        }

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
            $status = (string) data_get($payload, 'status', 'unknown');
            $snippet = Str::limit($clean, 240, '');

            throw new RuntimeException(
                'OpenAI returned non-JSON naming suggestions (status: '.$status.').'
                .($snippet !== '' ? ' Response started: '.$snippet : '')
            );
        }

        return $decoded;
    }

    /**
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
            ->map(function (array $row) use ($validProducts, $family): ?array {
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
                        'receipt_name' => $this->deterministicReceiptName($family, $product, 35)
                            ?: $this->cleanNameForFamily((string) ($row['receipt_name'] ?? ''), 35, $family),
                        'inventory_name' => $this->cleanNameForFamily((string) ($row['inventory_name'] ?? ''), 80, $family),
                        'ecommerce_title' => $this->cleanNameForFamily((string) ($row['ecommerce_title'] ?? ''), 150, $family),
                    ],
                    'confidence' => $this->normaliseConfidence($row['confidence'] ?? null),
                    'reason' => Str::limit(trim((string) ($row['reason'] ?? '')), 240, ''),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function deterministicReceiptName(ProductFamily $family, Product $product, int $maxLength): ?string
    {
        $variantText = $this->receiptVariantText($product);
        if ($variantText === '') {
            return null;
        }

        $base = $this->receiptTitleBase($family);
        if ($base === '') {
            return Str::limit($variantText, $maxLength, '');
        }

        $reserved = strlen($variantText) + 1;
        $baseLimit = max(8, $maxLength - $reserved);
        $base = Str::limit($base, $baseLimit, '');

        return $this->removeUnwantedNameCharacters($base.' '.$variantText);
    }

    private function receiptTitleBase(ProductFamily $family): string
    {
        return trim(implode(' ', array_filter([
            trim((string) $family->brand_name),
            $this->receiptStyleName($family),
        ])));
    }

    private function receiptStyleName(ProductFamily $family): string
    {
        $style = $this->consumerStyleName($family);
        $productType = Str::lower((string) $family->product_type_name);

        $genericSuffixes = ['Braid', 'Braids', 'Hair', 'Weave', 'Wig', 'Bulk'];
        foreach ($genericSuffixes as $suffix) {
            if (
                str_contains($productType, Str::lower($suffix))
                && preg_match('/\s+'.preg_quote($suffix, '/').'$/i', $style)
                && str_word_count($style) > 1
            ) {
                $style = trim(preg_replace('/\s+'.preg_quote($suffix, '/').'$/i', '', $style) ?: $style);
                break;
            }
        }

        return $style;
    }

    private function receiptVariantText(Product $product): string
    {
        $values = $product->variantValues
            ->sortBy(fn ($value) => sprintf('%04d:%s', $value->group?->sort_order ?? 0, $value->option?->label ?? ''))
            ->values();

        if ($values->isEmpty()) {
            return '';
        }

        $main = $values->first(fn ($value): bool => $this->isMainReceiptVariant($value->group?->name, $value->group?->variant_type));
        $sub = $values->first(fn ($value): bool => $this->isSubReceiptVariant($value->group?->name, $value->group?->variant_type));

        $fallbacks = $values
            ->reject(fn ($value): bool => $this->isCommonReceiptVariant($value->group?->name, $value->group?->variant_type))
            ->values();

        $parts = [];
        if ($main) {
            $parts[] = $this->displayVariantValue((string) $main->group?->name, (string) ($main->option?->label ?: $main->option?->value));
        }
        if ($sub && (! $main || (int) $sub->id !== (int) $main->id)) {
            $parts[] = $this->displayVariantValue((string) $sub->group?->name, (string) ($sub->option?->label ?: $sub->option?->value));
        }

        if ($parts === []) {
            $parts = $fallbacks
                ->take(2)
                ->map(fn ($value): string => $this->displayVariantValue((string) $value->group?->name, (string) ($value->option?->label ?: $value->option?->value)))
                ->filter()
                ->values()
                ->all();
        }

        return implode('|', array_filter($parts));
    }

    private function isMainReceiptVariant(?string $groupName, ?string $variantType): bool
    {
        $group = Str::lower((string) $groupName);
        $type = Str::lower((string) $variantType);

        return in_array($type, ['measurement', 'size', 'length'], true)
            || in_array($group, ['length', 'size', 'inch', 'inches'], true);
    }

    private function isSubReceiptVariant(?string $groupName, ?string $variantType): bool
    {
        $group = Str::lower((string) $groupName);
        $type = Str::lower((string) $variantType);

        return in_array($type, ['colour_code', 'colour_name', 'color_code', 'color_name'], true)
            || in_array($group, ['colour', 'color', 'shade'], true);
    }

    private function isCommonReceiptVariant(?string $groupName, ?string $variantType): bool
    {
        $group = Str::lower((string) $groupName);
        $type = Str::lower((string) $variantType);

        return in_array($type, ['count', 'pack_count'], true)
            || in_array($group, ['pack', 'bundle', 'bundles', 'piece', 'pieces', 'pcs', 'count'], true);
    }

    private function cleanNameForFamily(string $value, int $maxLength, ProductFamily $family): string
    {
        $clean = $this->cleanName($value, 500);
        $clean = $this->removeRedundantIdentityTerms($clean, $family);

        return Str::limit($clean, $maxLength, '');
    }

    private function cleanName(string $value, int $maxLength): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($value)) ?: '';
        $clean = str_replace(['Â£', '$', 'â‚¬'], '', $clean);
        $clean = $this->normaliseColourCodesInName($clean);
        $clean = $this->removeUnwantedNameCharacters($clean);

        return Str::limit($clean, $maxLength, '');
    }

    private function removeUnwantedNameCharacters(string $value): string
    {
        $clean = str_replace(['?', '!', '¿', '¡'], '', $value);
        $clean = preg_replace('/\s*\(\s*\)\s*/', ' ', $clean) ?: $clean;
        $clean = preg_replace('/\s+/', ' ', trim($clean)) ?: '';
        $clean = preg_replace('/[\s\-,|:;\/\\\\]+$/', '', $clean) ?: $clean;

        return trim($clean);
    }

    private function removeRedundantIdentityTerms(string $value, ProductFamily $family): string
    {
        $styleName = $this->consumerStyleName($family);
        if ($styleName === '') {
            return $value;
        }

        $phrases = collect([
            $family->line_name,
            $family->family_name,
        ])
            ->map(fn (mixed $phrase): string => trim((string) $phrase))
            ->filter(fn (string $phrase): bool => $phrase !== '' && ! $this->sameNormalisedText($phrase, $styleName))
            ->flatMap(fn (string $phrase): array => $this->redundantIdentityPhrases($phrase, $styleName))
            ->unique(fn (string $phrase): string => Str::lower($phrase))
            ->values();

        $clean = $value;
        foreach ($phrases as $phrase) {
            $clean = $this->removePhraseImmediatelyBeforeStyle($clean, $phrase, $styleName);
        }

        return preg_replace('/\s+/', ' ', trim($clean)) ?: '';
    }

    /**
     * @return array<int, string>
     */
    private function redundantIdentityPhrases(string $phrase, string $styleName): array
    {
        $normalisedPhrase = $this->normalisedIdentityText($phrase);
        $normalisedStyle = $this->normalisedIdentityText($styleName);
        $isRedundant = str_contains(' '.$normalisedStyle.' ', ' '.$normalisedPhrase.' ');

        $synonyms = [
            'boho' => ['bohemian'],
        ];

        foreach ($synonyms[$normalisedPhrase] ?? [] as $synonym) {
            if (str_contains(' '.$normalisedStyle.' ', ' '.$synonym.' ')) {
                $isRedundant = true;
                break;
            }
        }

        return $isRedundant ? [$phrase] : [];
    }

    private function removePhraseImmediatelyBeforeStyle(string $value, string $phrase, string $styleName): string
    {
        $pattern = '/\b'.preg_quote($phrase, '/').'\s+'.preg_quote($styleName, '/').'\b/i';

        return preg_replace_callback($pattern, fn (): string => $styleName, $value) ?: $value;
    }

    private function sameNormalisedText(string $left, string $right): bool
    {
        return $this->normalisedIdentityText($left) === $this->normalisedIdentityText($right);
    }

    private function normalisedIdentityText(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower($value)) ?: '');
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
