<?php

namespace App\Services;

use App\Models\IntakeSessionVariant;
use App\Models\Product;

class HairIntakeBarcodeService
{
    public const PREFIX = 'LHC';
    private const WIDTH = 8;

    public function generate(): string
    {
        $max = collect([
            Product::query()
                ->where('barcode', 'like', self::PREFIX.'%')
                ->pluck('barcode'),
            IntakeSessionVariant::query()
                ->where('barcode', 'like', self::PREFIX.'%')
                ->pluck('barcode'),
        ])
            ->flatten()
            ->map(fn (mixed $barcode): int => $this->numberFromBarcode((string) $barcode))
            ->max() ?? 0;

        return self::PREFIX.str_pad((string) ($max + 1), self::WIDTH, '0', STR_PAD_LEFT);
    }

    public function isPlausible(?string $barcode): bool
    {
        $barcode = trim((string) $barcode);

        if ($barcode === '') {
            return false;
        }

        if (preg_match('/^'.preg_quote(self::PREFIX, '/').'\d{'.self::WIDTH.'}$/', $barcode) === 1) {
            return true;
        }

        $digits = preg_replace('/\D+/', '', $barcode) ?: '';

        if (strlen($digits) === 13) {
            return $this->validEan13($digits);
        }

        if (strlen($digits) === 12) {
            return $this->validUpcA($digits);
        }

        if (strlen($digits) === 8) {
            return $this->validEan8($digits);
        }

        return false;
    }

    private function numberFromBarcode(string $barcode): int
    {
        if (preg_match('/^'.preg_quote(self::PREFIX, '/').'(\d+)$/', $barcode, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    private function validEan13(string $digits): bool
    {
        if (preg_match('/^(\d)\1{12}$/', $digits) === 1) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $digits[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        $check = (10 - ($sum % 10)) % 10;

        return $check === (int) $digits[12];
    }

    private function validUpcA(string $digits): bool
    {
        if (preg_match('/^(\d)\1{11}$/', $digits) === 1) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 11; $i++) {
            $sum += (int) $digits[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        $check = (10 - ($sum % 10)) % 10;

        return $check === (int) $digits[11];
    }

    private function validEan8(string $digits): bool
    {
        if (preg_match('/^(\d)\1{7}$/', $digits) === 1) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $digits[$i] * ($i % 2 === 0 ? 3 : 1);
        }

        $check = (10 - ($sum % 10)) % 10;

        return $check === (int) $digits[7];
    }
}
