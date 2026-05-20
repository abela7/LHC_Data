<?php

namespace App\Services;

use App\Models\ProductFamily;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ShopStructureSuggestionService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @param array{brand_name:string,family_name:string,current_department_name?:?string,current_product_type_name?:?string} $input
     * @return array<string, mixed>
     */
    public function suggest(array $input): array
    {
        $brandName = $this->clean($input['brand_name'] ?? '');
        $familyName = $this->clean($input['family_name'] ?? '');
        $localSuggestions = $this->localSuggestions($brandName, $familyName);
        $bestLocal = $this->withAmbiguityNote($localSuggestions[0] ?? null, $localSuggestions);
        $apiKey = (string) config('services.openrouter.api_key');

        if ($this->isStrongLocal($bestLocal, $localSuggestions)) {
            return [
                'primary' => $bestLocal,
                'local_suggestions' => $localSuggestions,
                'ai_used' => false,
                'ai_available' => $apiKey !== '',
                'ai_error' => null,
            ];
        }

        if ($apiKey === '') {
            return [
                'primary' => $bestLocal ?: $this->reviewSuggestion('OpenRouter API key is not configured, and no strong catalogue match was found.'),
                'local_suggestions' => $localSuggestions,
                'ai_used' => false,
                'ai_available' => false,
                'ai_error' => null,
            ];
        }

        try {
            $aiSuggestion = $this->openRouterSuggestion($input, $localSuggestions);

            return [
                'primary' => $this->isUsable($aiSuggestion) ? $aiSuggestion : ($bestLocal ?: $aiSuggestion),
                'local_suggestions' => $localSuggestions,
                'ai_used' => true,
                'ai_available' => true,
                'ai_error' => null,
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'primary' => $bestLocal ?: $this->reviewSuggestion('AI lookup failed. Review manually.'),
                'local_suggestions' => $localSuggestions,
                'ai_used' => false,
                'ai_available' => true,
                'ai_error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function localSuggestions(string $brandName, string $familyName): array
    {
        $brandTokens = $this->tokens($brandName);
        $familyTokens = $this->tokens($familyName);
        $brandProbe = $brandTokens[0] ?? $brandName;
        $familyProbe = $familyTokens[0] ?? $familyName;

        $sourceTypes = DB::table('product_sources')
            ->select('product_family_id')
            ->selectRaw("group_concat(distinct source_type order by source_type separator ', ') as source_types")
            ->whereNotNull('product_family_id')
            ->groupBy('product_family_id');

        $families = ProductFamily::query()
            ->leftJoinSub($sourceTypes, 'source_types', fn ($join) => $join->on('source_types.product_family_id', '=', 'product_families.id'))
            ->where('root_catalogue_name', '!=', 'Hair Extensions')
            ->where(function ($query) use ($brandName, $familyName, $brandProbe, $familyProbe): void {
                $brandLike = '%'.$this->escapeLike($brandName).'%';
                $familyLike = '%'.$this->escapeLike($familyName).'%';
                $brandProbeLike = '%'.$this->escapeLike($brandProbe).'%';
                $familyProbeLike = '%'.$this->escapeLike($familyProbe).'%';

                $query->where('brand_name', 'like', $brandLike)
                    ->orWhere('family_name', 'like', $familyLike)
                    ->orWhere('brand_name', 'like', $brandProbeLike)
                    ->orWhere('family_name', 'like', $familyProbeLike);
            })
            ->select('product_families.id', 'product_families.brand_name', 'product_families.family_name', 'product_families.root_catalogue_name', 'product_families.product_type_name', 'source_types.source_types')
            ->limit(250)
            ->get();

        $familySuggestions = $families
            ->map(fn (ProductFamily $family): array => $this->scoreFamily($family, $brandName, $familyName, $brandTokens, $familyTokens))
            ->filter(fn (array $row): bool => $row['score'] >= 35 && ($row['department_name'] !== '' || $row['product_type_name'] !== ''))
            ->values();

        return $familySuggestions
            ->merge($this->pdfCatalogueSuggestions($brandName, $familyName, $brandTokens, $familyTokens, $brandProbe, $familyProbe))
            ->sortByDesc('score')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $brandTokens
     * @param array<int, string> $familyTokens
     * @return array<string, mixed>
     */
    private function scoreFamily(ProductFamily $family, string $brandName, string $familyName, array $brandTokens, array $familyTokens): array
    {
        $candidateBrand = $this->clean((string) $family->brand_name);
        $candidateName = $this->clean((string) $family->family_name);
        $brandScore = $this->textScore($brandName, $candidateBrand, $brandTokens, 42);
        $nameScore = $this->textScore($familyName, $candidateName, $familyTokens, 62);
        $score = $brandScore + $nameScore;

        if ($family->root_catalogue_name) {
            $score += 7;
        }

        if ($family->product_type_name) {
            $score += 9;
        }

        $confidence = match (true) {
            $score >= 95 => 'A',
            $score >= 72 => 'B',
            $score >= 45 => 'C',
            default => 'D',
        };

        return [
            'source' => 'local_catalogue',
            'confidence' => $confidence,
            'score' => round($score, 1),
            'department_name' => $this->clean($family->root_catalogue_name),
            'product_type_name' => $this->clean($family->product_type_name),
            'matched_family_id' => $family->id,
            'matched_brand_name' => $candidateBrand,
            'matched_family_name' => $candidateName,
            'source_types' => $this->clean($family->source_types),
            'reason' => 'Matched existing catalogue/source structure.',
            'source_urls' => [],
        ];
    }

    /**
     * @param array<int, string> $brandTokens
     * @param array<int, string> $familyTokens
     * @return array<int, array<string, mixed>>
     */
    private function pdfCatalogueSuggestions(
        string $brandName,
        string $familyName,
        array $brandTokens,
        array $familyTokens,
        string $brandProbe,
        string $familyProbe,
    ): array {
        if (! \Illuminate\Support\Facades\Schema::hasTable('pdf_catalogue_products')) {
            return [];
        }

        $brandLike = '%'.$this->escapeLike($brandName).'%';
        $familyLike = '%'.$this->escapeLike($familyName).'%';
        $brandProbeLike = '%'.$this->escapeLike($brandProbe).'%';
        $familyProbeLike = '%'.$this->escapeLike($familyProbe).'%';

        $rows = DB::table('pdf_catalogue_products')
            ->where(function ($query) use ($brandLike, $familyLike, $brandProbeLike, $familyProbeLike): void {
                $query->where('brand', 'like', $brandLike)
                    ->orWhere('product_name', 'like', $familyLike)
                    ->orWhere('brand', 'like', $brandProbeLike)
                    ->orWhere('product_name', 'like', $familyProbeLike)
                    ->orWhere('product_code', 'like', $familyLike);
            })
            ->select('id', 'source_name', 'brand', 'product_code', 'product_name', 'confidence')
            ->limit(250)
            ->get();

        return $rows
            ->map(function ($row) use ($brandName, $familyName, $brandTokens, $familyTokens): array {
                $candidateBrand = $this->clean((string) $row->brand);
                $candidateName = $this->clean((string) $row->product_name);
                $brandScore = $this->textScore($brandName, $candidateBrand, $brandTokens, 36);
                $nameScore = $this->textScore($familyName, $candidateName, $familyTokens, 66);
                $structure = $this->inferStructureFromPdfProduct($candidateName);
                $score = $brandScore + $nameScore + ($structure['product_type_name'] !== '' ? 8 : 0);
                $sourceName = $this->clean((string) $row->source_name);

                $confidence = match (true) {
                    $score >= 92 => 'A',
                    $score >= 70 => 'B',
                    $score >= 45 => 'C',
                    default => 'D',
                };

                return [
                    'source' => 'pdf_catalogue',
                    'confidence' => $confidence,
                    'score' => round($score, 1),
                    'department_name' => $structure['department_name'],
                    'product_type_name' => $structure['product_type_name'],
                    'matched_family_id' => null,
                    'matched_brand_name' => $candidateBrand,
                    'matched_family_name' => $candidateName,
                    'source_types' => $sourceName,
                    'reason' => "Matched PDF source {$sourceName} product code {$row->product_code}.",
                    'source_urls' => [route('pdf-products.index', ['search' => $row->product_code, 'source' => $sourceName])],
                ];
            })
            ->filter(fn (array $row): bool => $row['score'] >= 35 && ($row['department_name'] !== '' || $row['product_type_name'] !== ''))
            ->values()
            ->all();
    }

    /**
     * @return array{department_name: string, product_type_name: string}
     */
    private function inferStructureFromPdfProduct(string $productName): array
    {
        $name = ' '.(preg_replace('/[^a-z0-9]+/', ' ', Str::lower($productName)) ?: '').' ';

        $rules = [
            ['/(shampoo|shamp\b)/', 'Hair Products', 'Shampoo'],
            ['/(conditioner|cond\b|leave in|leave-in)/', 'Hair Products', 'Conditioner'],
            ['/(relaxer|texturizer|texturiser)/', 'Hair Products', 'Relaxer'],
            ['/(hair colour|hair color|dye|semi permanent|semi-permanent)/', 'Hair Products', 'Hair Colour'],
            ['/(face gel|face cream|bright cream|skin cream)/', 'Skin Care', 'Face Cream'],
            ['/(cleanser|face wash|scrub|toner)/', 'Skin Care', 'Face Wash'],
            ['/(serum|skin treatment|acne|blemish|fade)/', 'Skin Care', 'Skin Treatment'],
            ['/(body lotion|lotion\b)/', 'Body Care', 'Body Lotion'],
            ['/(body cream|body butter|body whip)/', 'Body Care', 'Body Cream'],
            ['/(soap|wash\b|shower gel)/', 'Body Care', 'Soap'],
            ['/(body oil|glycerine|glycerin)/', 'Body Care', 'Body Oil'],
            ['/(edge|wax|styling gel|loc gel|gel\b)/', 'Hair Products', 'Styling Gel'],
            ['/(hair food|pomade|grease|super gro|growth|scalp|treatment|cholesterol)/', 'Hair Products', 'Hair Treatment'],
            ['/(deodorant|roll on|roll-on)/', 'Body Care', 'Deodorant'],
            ['/(perfume|fragrance|eau de|body spray)/', 'Fragrance', 'Perfume'],
            ['/(lip|mascara|foundation|powder|makeup|make up)/', 'Makeup', 'Makeup'],
        ];

        foreach ($rules as [$pattern, $department, $productType]) {
            if (preg_match($pattern, $name) === 1) {
                return [
                    'department_name' => $department,
                    'product_type_name' => $productType,
                ];
            }
        }

        return [
            'department_name' => 'General Products',
            'product_type_name' => 'Other',
        ];
    }

    /**
     * @param array<int, string> $tokens
     */
    private function textScore(string $needle, string $candidate, array $tokens, int $max): float
    {
        $needleNorm = $this->norm($needle);
        $candidateNorm = $this->norm($candidate);

        if ($needleNorm === '' || $candidateNorm === '') {
            return 0;
        }

        if ($needleNorm === $candidateNorm) {
            return $max;
        }

        if (str_contains($candidateNorm, $needleNorm) || str_contains($needleNorm, $candidateNorm)) {
            return $max * 0.82;
        }

        if ($tokens === []) {
            return 0;
        }

        $candidateTokens = $this->tokens($candidate);
        $hits = count(array_intersect($tokens, $candidateTokens));
        $ratio = $hits / max(count($tokens), 1);

        return $max * $ratio;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>> $localSuggestions
     * @return array<string, mixed>
     */
    private function openRouterSuggestion(array $input, array $localSuggestions): array
    {
        $model = (string) config('services.openrouter.model', 'google/gemini-3-flash-preview');
        $prompt = $this->prompt($input, $localSuggestions);
        $body = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You classify UK beauty shop products into Department and Product Type. Return only valid JSON.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.05,
            'max_tokens' => 900,
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
            throw new RuntimeException('OpenRouter structure suggestion failed: '.Str::limit((string) $message, 500, ''));
        }

        $payload = $response->json();
        $suggestion = $this->decodeSuggestion((string) data_get($payload, 'choices.0.message.content', ''));
        $suggestion['source'] = 'openrouter';
        $suggestion['model'] = (string) data_get($payload, 'model', $model);
        $suggestion['prompt_hash'] = hash('sha256', $prompt);
        $suggestion['source_urls'] = $this->sourceUrls($payload, $suggestion);

        return $suggestion;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>> $localSuggestions
     */
    private function prompt(array $input, array $localSuggestions): string
    {
        $trustedSites = $this->trustedReferenceSites();
        $context = json_encode([
            'brand_name' => $this->clean($input['brand_name'] ?? ''),
            'product_family_name_seen_on_pack' => $this->clean($input['family_name'] ?? ''),
            'current_department_name' => $this->clean($input['current_department_name'] ?? ''),
            'current_product_type_name' => $this->clean($input['current_product_type_name'] ?? ''),
            'local_catalogue_candidates' => $localSuggestions,
            'reference_site_priority_when_confused' => $trustedSites,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $date = date('Y-m-d');

        return trim(<<<PROMPT
Current date: {$date}.

The human is physically in a UK beauty/hair shop entering a product into a fast mobile intake form.
They may only know the Brand and Product / Family Name from the pack.

Your task:
- Suggest the best Department and Product Type only.
- Use local catalogue/Janson/PDF/Mamado/shop-photo source candidates as the first evidence.
- If local candidates are weak or conflicting, check the official brand/manufacturer site first when it is obvious.
- If no official page is obvious, use web search and prefer these domains in this order: {$this->csv($trustedSites)}.
- Use wider web only if these sites do not identify the product clearly.
- Do not invent a product if the brand/product is ambiguous.
- If evidence conflicts, return confidence C or D and explain the uncertainty.
- Do not suggest ecommerce descriptions, claims, variants, barcodes, prices, or images.
- Keep the answer short enough for a mobile UI.

Allowed Department examples:
Skin Care, Hair Products, Body Care, General Products, Makeup, Fragrance, Accessories, Electrical, Other.

Product Type examples:
Body Lotion, Body Cream, Body Oil, Soap, Face Cream, Face Wash, Skin Treatment, Shampoo, Conditioner, Hair Treatment, Styling Gel, Edge Control, Hair Food, Hair Colour, Relaxer, Deodorant, Perfume, Makeup.

Input/context:
{$context}

Return only valid JSON in this exact shape:
{
  "confidence": "A|B|C|D",
  "department_name": "",
  "product_type_name": "",
  "matched_brand_name": "",
  "matched_family_name": "",
  "reason": "",
  "source_urls": []
}

Confidence:
- A: exact or near-exact local/source match with clear department and product type.
- B: strong web/local match with minor uncertainty.
- C: useful suggestion but needs human checking.
- D: not enough evidence; leave fields blank.
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
            throw new RuntimeException('OpenRouter returned a non-JSON structure suggestion.');
        }

        return [
            'confidence' => $this->confidence($decoded['confidence'] ?? null),
            'department_name' => $this->clean($decoded['department_name'] ?? ''),
            'product_type_name' => $this->clean($decoded['product_type_name'] ?? ''),
            'matched_brand_name' => $this->clean($decoded['matched_brand_name'] ?? ''),
            'matched_family_name' => $this->clean($decoded['matched_family_name'] ?? ''),
            'reason' => $this->clean($decoded['reason'] ?? ''),
            'source_urls' => $this->stringList($decoded['source_urls'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed>|null $suggestion
     */
    /**
     * @param array<string, mixed>|null $suggestion
     * @param array<int, array<string, mixed>> $localSuggestions
     */
    private function isStrongLocal(?array $suggestion, array $localSuggestions = []): bool
    {
        if (! $suggestion) {
            return false;
        }

        return in_array($suggestion['confidence'] ?? 'D', ['A', 'B'], true)
            && $this->clean($suggestion['department_name'] ?? '') !== ''
            && $this->clean($suggestion['product_type_name'] ?? '') !== ''
            && ! $this->hasCloseConflictingLocalMatch($suggestion, $localSuggestions);
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<int, array<string, mixed>> $localSuggestions
     */
    private function hasCloseConflictingLocalMatch(array $primary, array $localSuggestions): bool
    {
        $primaryScore = (float) ($primary['score'] ?? 0);
        $primaryPair = $this->norm(($primary['department_name'] ?? '').'|'.($primary['product_type_name'] ?? ''));

        return collect($localSuggestions)
            ->skip(1)
            ->contains(function (array $candidate) use ($primaryScore, $primaryPair): bool {
                $candidatePair = $this->norm(($candidate['department_name'] ?? '').'|'.($candidate['product_type_name'] ?? ''));

                return $candidatePair !== ''
                    && $candidatePair !== $primaryPair
                    && ((float) ($candidate['score'] ?? 0)) >= ($primaryScore - 8);
            });
    }

    /**
     * @param array<string, mixed>|null $primary
     * @param array<int, array<string, mixed>> $localSuggestions
     * @return array<string, mixed>|null
     */
    private function withAmbiguityNote(?array $primary, array $localSuggestions): ?array
    {
        if (! $primary || ! $this->hasCloseConflictingLocalMatch($primary, $localSuggestions)) {
            return $primary;
        }

        $primary['confidence'] = 'C';
        $primary['reason'] = 'Multiple close catalogue matches found. Pick the matching product type from the options shown.';

        return $primary;
    }

    /**
     * @param array<string, mixed>|null $suggestion
     */
    private function isUsable(?array $suggestion): bool
    {
        if (! $suggestion) {
            return false;
        }

        return in_array($suggestion['confidence'] ?? 'D', ['A', 'B', 'C'], true)
            && ($this->clean($suggestion['department_name'] ?? '') !== '' || $this->clean($suggestion['product_type_name'] ?? '') !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewSuggestion(string $reason): array
    {
        return [
            'source' => 'review',
            'confidence' => 'D',
            'score' => 0,
            'department_name' => '',
            'product_type_name' => '',
            'matched_family_id' => null,
            'matched_brand_name' => '',
            'matched_family_name' => '',
            'source_types' => '',
            'reason' => $reason,
            'source_urls' => [],
        ];
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

    /**
     * @return array<int, string>
     */
    private function tokens(string $value): array
    {
        return collect(preg_split('/[^a-z0-9]+/', Str::lower($value)) ?: [])
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => strlen($token) >= 2 && ! in_array($token, ['and', 'the', 'with', 'for'], true))
            ->unique()
            ->values()
            ->all();
    }

    private function confidence(mixed $value): string
    {
        $confidence = strtoupper(substr(trim((string) $value), 0, 1));

        return in_array($confidence, ['A', 'B', 'C', 'D'], true) ? $confidence : 'D';
    }

    private function norm(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower($value)) ?: '';
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
     * @param array<int, string> $values
     */
    private function csv(array $values): string
    {
        return implode(', ', $values);
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
            ->unique(fn (string $value): string => Str::lower($value))
            ->values()
            ->all();
    }
}
