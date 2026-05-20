<?php

namespace App\Support;

use Illuminate\Http\Request;

class PictureRange
{
    public function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly string $inputFrom = '',
        public readonly string $inputTo = '',
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return self::fromInputs(
            trim((string) $request->string('picture_from')->value()),
            trim((string) $request->string('picture_to')->value()),
        );
    }

    public static function fromInputs(?string $inputFrom, ?string $inputTo): self
    {
        $inputFrom = trim((string) $inputFrom);
        $inputTo = trim((string) $inputTo);

        $from = self::normalize($inputFrom);
        $to = self::normalize($inputTo);

        if ($from !== null && $to !== null && $from['number'] > $to['number']) {
            [$from, $to] = [$to, $from];
        }

        return new self(
            $from['value'] ?? null,
            $to['value'] ?? null,
            $from['value'] ?? $inputFrom,
            $to['value'] ?? $inputTo,
        );
    }

    public function apply(object $query, string $column = 'picture_id'): object
    {
        if ($this->from !== null && $this->to !== null) {
            $query->whereBetween($column, [$this->from, $this->to]);

            return $query;
        }

        if ($this->from !== null) {
            $query->where($column, '>=', $this->from);
        }

        if ($this->to !== null) {
            $query->where($column, '<=', $this->to);
        }

        return $query;
    }

    public function isActive(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    /**
     * @return array{picture_from: string, picture_to: string}
     */
    public function toFilterArray(): array
    {
        return [
            'picture_from' => $this->inputFrom,
            'picture_to' => $this->inputTo,
        ];
    }

    /**
     * @return array{value: string, number: int}|null
     */
    private static function normalize(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        if (! preg_match('/(\d+)/', $value, $matches)) {
            return null;
        }

        $number = (int) $matches[1];
        $digits = (string) $number;
        $width = max(3, strlen($digits));

        return [
            'value' => 'picture'.str_pad($digits, $width, '0', STR_PAD_LEFT),
            'number' => $number,
        ];
    }
}
