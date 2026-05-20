<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\DeliverooBrands;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualDeliverooProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed|Closure|string>>
     */
    public function rules(): array
    {
        return [
            'brand_mode' => ['required', 'string', Rule::in(['existing', 'new'])],
            'brand_slug' => ['nullable', 'string', 'max:255'],
            'brand_new_label' => ['nullable', 'string', 'max:255'],
            'brand_new_category' => ['nullable', 'string', Rule::in(DeliverooBrands::categories())],
            'official_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'variant_name' => ['nullable', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'official_url' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (! is_string($value)) {
                        return;
                    }
                    if (str_starts_with($value, 'manual:lhc:')) {
                        return;
                    }
                    if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
                        return;
                    }
                    $fail(__('deliveroo.manual_product.official_url_invalid'));
                },
            ],
            'image_urls' => [
                'nullable',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (is_array($value)) {
                        if (count($value) > 40) {
                            $fail(__('deliveroo.manual_product.image_urls_max_items'));
                            return;
                        }
                        foreach ($value as $row) {
                            if ($row === null || $row === '') {
                                continue;
                            }
                            if (! is_string($row)) {
                                $fail(__('deliveroo.manual_product.image_urls_row_invalid'));
                                return;
                            }
                            if (strlen($row) > 2048) {
                                $fail(__('deliveroo.manual_product.image_url_too_long'));
                                return;
                            }
                        }
                        return;
                    }
                    if (is_string($value)) {
                        if (strlen($value) > 20000) {
                            $fail(__('deliveroo.manual_product.image_urls_text_too_long'));
                        }
                        return;
                    }
                    $fail(__('deliveroo.manual_product.image_urls_invalid_shape'));
                },
            ],
            'family_link' => ['required', 'string', Rule::in(['none', 'existing', 'new'])],
            'family_existing' => ['nullable', 'string', 'max:255'],
            'family_new' => ['nullable', 'string', 'max:255'],
        ];
    }
}
