<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

class OpenRouterPackagingTextService
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * @return array<int, array{id:string,name:string}>
     */
    public function models(): array
    {
        return collect(config('services.openrouter.vision_models', []))
            ->map(fn (array $model): array => [
                'id' => (string) ($model['id'] ?? ''),
                'name' => (string) ($model['name'] ?? ($model['id'] ?? 'Model')),
            ])
            ->filter(fn (array $model): bool => $model['id'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param array{brand_name?:?string, observed_product_name?:?string, current_notes?:?string, ai_model?:?string} $input
     * @return array<string, mixed>
     */
    public function extract(UploadedFile $image, array $input = []): array
    {
        $apiKey = (string) config('services.openrouter.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenRouter API key is missing. Add OPENROUTER_API_KEY to .env.');
        }

        $model = $this->model((string) ($input['ai_model'] ?? ''));
        $imageDataUrl = $this->imageDataUrl($image);
        $prompt = $this->prompt($input);
        $body = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a precise packaging text extraction assistant for a UK hair and cosmetics retailer. Return only valid JSON.',
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $imageDataUrl,
                            ],
                        ],
                    ],
                ],
            ],
            'temperature' => 0,
            'max_tokens' => 1200,
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
            throw new RuntimeException('OpenRouter packaging vision failed: '.Str::limit((string) $message, 500, ''));
        }

        $payload = $response->json();
        $result = $this->decodeResult((string) data_get($payload, 'choices.0.message.content', ''));

        return [
            'model' => (string) data_get($payload, 'model', $model),
            'result' => $result,
            'raw_response' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function model(string $requestedModel): string
    {
        $allowed = collect($this->models())->pluck('id')->all();
        if ($requestedModel !== '' && in_array($requestedModel, $allowed, true)) {
            return $requestedModel;
        }

        return (string) config('services.openrouter.vision_model', 'openrouter/free');
    }

    /**
     * @param array{brand_name?:?string, observed_product_name?:?string, current_notes?:?string} $input
     */
    private function prompt(array $input): string
    {
        $brand = trim((string) ($input['brand_name'] ?? ''));
        $observedProduct = trim((string) ($input['observed_product_name'] ?? ''));
        $currentNotes = trim((string) ($input['current_notes'] ?? ''));

        return trim(<<<PROMPT
You are reading one product packaging/reference photo taken in a real shop.

Product context:
- Brand typed by human: {$brand}
- Product name typed by human: {$observedProduct}
- Existing notes, if any: {$currentNotes}

Goal:
Extract useful packaging text for later ecommerce, POS, inventory and AI catalogue review.

Rules:
- Read only text that is visible or strongly inferable from the image.
- Do not invent ingredients, directions, warnings, claims, sizes, colours, lengths, pack counts, barcodes, or usage instructions.
- If text is unclear, put it in unclear_text instead of guessing.
- Preserve important product wording, size, length, colour, pack count, texture, material, claims, usage, warnings, directions and barcode/EAN if visible.
- Keep it practical for a retailer. Do not write marketing fluff.
- Return short structured text that can be pasted into the intake notes field.

Return only valid JSON:
{
  "confidence": "A|B|C|D",
  "detected_text": [],
  "structured_notes": "",
  "product_facts": {
    "brand": "",
    "product_name": "",
    "size_or_length": "",
    "colour_or_variant": "",
    "pack_count": "",
    "material_or_fibre": "",
    "key_claims": [],
    "directions": [],
    "warnings": [],
    "barcode": ""
  },
  "ecommerce_copy_candidates": {
    "short_description": "",
    "bullet_points": []
  },
  "unclear_text": [],
  "notes": ""
}

Confidence:
- A: packaging text is clear and directly readable.
- B: most important text is readable, minor uncertainty.
- C: partially readable, needs human review.
- D: image is not usable for text extraction.
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
            throw new RuntimeException('OpenRouter returned non-JSON packaging text.');
        }

        $decoded['confidence'] = $this->normaliseConfidence($decoded['confidence'] ?? null);
        $decoded['detected_text'] = $this->stringList($decoded['detected_text'] ?? []);
        $decoded['unclear_text'] = $this->stringList($decoded['unclear_text'] ?? []);
        $decoded['structured_notes'] = trim((string) ($decoded['structured_notes'] ?? ''));
        $decoded['notes'] = trim((string) ($decoded['notes'] ?? ''));
        $decoded['product_facts'] = is_array($decoded['product_facts'] ?? null) ? $decoded['product_facts'] : [];
        $decoded['ecommerce_copy_candidates'] = is_array($decoded['ecommerce_copy_candidates'] ?? null) ? $decoded['ecommerce_copy_candidates'] : [];

        return $decoded;
    }

    private function imageDataUrl(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (! $path || ! is_file($path)) {
            throw new RuntimeException('Uploaded image could not be read.');
        }

        [$bytes, $mime] = $this->compressedImageBytes($path);

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function compressedImageBytes(string $path): array
    {
        $info = @getimagesize($path);
        $mime = strtolower((string) ($info['mime'] ?? ''));

        if (! $info || ! in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true)) {
            return [file_get_contents($path) ?: '', $mime ?: 'image/jpeg'];
        }

        $source = match ($mime) {
            'image/jpeg', 'image/jpg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
            default => false,
        };

        if (! $source) {
            return [file_get_contents($path) ?: '', $mime ?: 'image/jpeg'];
        }

        $source = $this->normalizeExifOrientation($source, $path, $mime);
        $width = imagesx($source);
        $height = imagesy($source);
        $maxSide = 1600;
        $scale = min(1, $maxSide / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($target, null, 78);
        $bytes = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        return [$bytes, 'image/jpeg'];
    }

    private function normalizeExifOrientation(mixed $image, string $path, string $mime): mixed
    {
        if (! in_array($mime, ['image/jpeg', 'image/jpg'], true) || ! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($image, 180, 0) ?: $image,
            6 => imagerotate($image, -90, 0) ?: $image,
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
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
