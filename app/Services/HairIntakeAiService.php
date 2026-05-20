<?php

namespace App\Services;

use App\Models\BrandCatalogueBrand;
use App\Models\IntakeSession;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class HairIntakeAiService
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly HairIntakeCatalogueMatcher $matcher,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function match(IntakeSession $session): array
    {
        $brand = $session->brand()->firstOrFail();
        $observations = is_array($session->observations_json) ? $session->observations_json : [];
        $shortlist = $this->matcher->shortlistedStyles($brand, $session->style_name_hint, $observations);
        $prompt = $this->matchPrompt($session, $brand, $shortlist);

        $result = $this->requestJson(
            instructions: 'You match one physical hair-extension product photo to an existing imported catalogue. Return only JSON. Never invent a match.',
            prompt: $prompt,
            imageDataUrl: $this->sessionImageDataUrl($session),
            maxTokens: 1800,
        );

        return $this->normaliseMatchResult($result);
    }

    /**
     * @param  array<string, mixed>  $call2Payload
     * @return array<string, mixed>
     */
    public function review(array $call2Payload): array
    {
        $prompt = "Review this hair-extension intake submission. Backend blockers are already deterministic; you may only return warning-level issues and consistency notes.\n\n"
            .json_encode($call2Payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ."\n\nReturn JSON with keys: issues (array of {variant_id, severity, field, message}), consistency_notes (array of strings). Use severity warning only.";

        $result = $this->requestJson(
            instructions: 'You are a cautious retail intake reviewer. Return only JSON. Do not create blocker issues.',
            prompt: $prompt,
            imageDataUrl: null,
            maxTokens: 1200,
        );

        return [
            'issues' => collect($result['issues'] ?? [])
                ->filter(fn ($issue): bool => is_array($issue))
                ->map(fn (array $issue): array => [
                    'variant_id' => (int) ($issue['variant_id'] ?? 0),
                    'severity' => 'warning',
                    'field' => trim((string) ($issue['field'] ?? 'review')),
                    'message' => Str::limit(trim((string) ($issue['message'] ?? 'Review warning.')), 300, ''),
                ])
                ->filter(fn (array $issue): bool => $issue['message'] !== '')
                ->values()
                ->all(),
            'consistency_notes' => collect($result['consistency_notes'] ?? [])
                ->map(fn (mixed $note): string => Str::limit(trim((string) $note), 300, ''))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $shortlist
     */
    private function matchPrompt(IntakeSession $session, BrandCatalogueBrand $brand, array $shortlist): string
    {
        $input = [
            'submission_id' => $session->session_uuid,
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
            ],
            'style_name_hint' => $session->style_name_hint,
            'observations' => $session->observations_json ?: ['main' => [], 'sub' => [], 'common' => []],
            'user_note' => $session->user_note,
            'candidate_styles_from_this_brand_only' => $shortlist,
        ];

        return trim(<<<PROMPT
You are doing Call 1 MATCH for a shop-floor hair-extension intake.

Rules:
- Search only the candidate_styles_from_this_brand_only.
- Confirm only when brand, style, and at least one strong variant signal line up.
- Observations are loose signals, not exact catalogue values.
- The photo proves at most one or a few visible SKUs.
- Do not invent styles, SKUs, axes, colours, lengths, or pack counts.

Confidence:
- >= 0.85: match_status confirmed
- 0.60 to 0.84: match_status needs_user_choice with 2-3 candidates
- < 0.60: match_status not_found

Return JSON only:
{
  "match_status": "confirmed|needs_user_choice|not_found",
  "confidence": 0.0,
  "matched_style_id": 123,
  "matching_sku_ids": [1,2],
  "candidates": [
    {"style_id": 123, "confidence": 0.75, "reasoning": ""}
  ],
  "reasoning": "One line: what matched, what was inferred, what is uncertain."
}

Input:
PROMPT)."\n".json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(string $instructions, string $prompt, ?string $imageDataUrl, int $maxTokens): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is missing. Add OPENAI_API_KEY to .env.');
        }

        $content = [
            ['type' => 'input_text', 'text' => $prompt],
        ];

        if ($imageDataUrl) {
            $content[] = ['type' => 'input_image', 'image_url' => $imageDataUrl];
        }

        $body = [
            'model' => (string) config('services.openai.retail_naming_model', 'gpt-5-nano'),
            'instructions' => $instructions,
            'input' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'max_output_tokens' => $maxTokens,
            'reasoning' => ['effort' => 'minimal'],
            'text' => ['verbosity' => 'low'],
        ];

        $headers = [];
        if (filled(env('OPENAI_ORG_ID'))) {
            $headers['OpenAI-Organization'] = (string) env('OPENAI_ORG_ID');
        }
        if (filled(env('OPENAI_PROJECT_ID'))) {
            $headers['OpenAI-Project'] = (string) env('OPENAI_PROJECT_ID');
        }

        $response = $this->http
            ->timeout((int) config('services.openai.timeout', 60))
            ->retry(1, 500)
            ->withToken($apiKey)
            ->withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->post('https://api.openai.com/v1/responses', $body);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: $response->body();
            throw new RuntimeException('OpenAI intake call failed: '.Str::limit((string) $message, 500, ''));
        }

        return $this->decodeJson($response->json());
    }

    private function sessionImageDataUrl(IntakeSession $session): ?string
    {
        if (! $session->photo_disk || ! $session->photo_path) {
            return null;
        }

        $disk = Storage::disk($session->photo_disk);
        if (! $disk->exists($session->photo_path)) {
            return null;
        }

        $mime = $disk->mimeType($session->photo_path) ?: $session->photo_mime_type ?: 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($session->photo_path));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function decodeJson(array $payload): array
    {
        $text = (string) data_get($payload, 'output_text', '');

        if ($text === '') {
            $text = collect((array) data_get($payload, 'output', []))
                ->flatMap(fn ($item) => collect((array) ($item['content'] ?? [])))
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
            throw new RuntimeException('OpenAI returned non-JSON intake output.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function normaliseMatchResult(array $result): array
    {
        $status = (string) ($result['match_status'] ?? 'not_found');
        if (! in_array($status, ['confirmed', 'needs_user_choice', 'not_found'], true)) {
            $status = 'not_found';
        }
        $confidence = max(0, min(1, (float) ($result['confidence'] ?? 0)));
        $matchedStyleId = (int) ($result['matched_style_id'] ?? 0);

        if ($status === 'confirmed' && $confidence < 0.85) {
            $status = $confidence >= 0.60 ? 'needs_user_choice' : 'not_found';
        }

        if ($status === 'needs_user_choice' && $confidence < 0.60) {
            $status = 'not_found';
        }

        $candidates = collect($result['candidates'] ?? [])
            ->filter(fn ($candidate): bool => is_array($candidate))
            ->map(fn (array $candidate): array => [
                'style_id' => (int) ($candidate['style_id'] ?? 0),
                'confidence' => max(0, min(1, (float) ($candidate['confidence'] ?? 0))),
                'reasoning' => Str::limit(trim((string) ($candidate['reasoning'] ?? '')), 280, ''),
            ])
            ->filter(fn (array $candidate): bool => $candidate['style_id'] > 0)
            ->take(3)
            ->values();

        if ($status === 'needs_user_choice' && $matchedStyleId > 0 && $candidates->where('style_id', $matchedStyleId)->isEmpty()) {
            $candidates->prepend([
                'style_id' => $matchedStyleId,
                'confidence' => $confidence,
                'reasoning' => Str::limit(trim((string) ($result['reasoning'] ?? 'Candidate match.')), 280, ''),
            ]);
        }

        return [
            'match_status' => $status,
            'confidence' => $confidence,
            'matched_style_id' => $matchedStyleId,
            'matching_sku_ids' => collect($result['matching_sku_ids'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->values()
                ->all(),
            'candidates' => $candidates->take(3)->values()->all(),
            'reasoning' => Str::limit(trim((string) ($result['reasoning'] ?? 'No reasoning returned.')), 300, ''),
        ];
    }
}
