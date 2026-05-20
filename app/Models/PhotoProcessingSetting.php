<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhotoProcessingSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'remove_background_enabled' => 'boolean',
            'apply_to_mobile_capture' => 'boolean',
            'keep_original' => 'boolean',
            'timeout_seconds' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'remove_background_enabled' => false,
            'apply_to_mobile_capture' => true,
            'keep_original' => true,
            'background_color' => '#ffffff',
            'python_command' => 'py',
            'timeout_seconds' => 120,
        ]);
    }
}
