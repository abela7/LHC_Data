<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatermarkSetting extends Model
{
    public const LAYOUT_MODES = [
        'fit' => 'Single line auto-fit',
        'wrap' => 'Allow wrapping',
    ];

    public const POSITIONS = [
        'top-left' => 'Top left',
        'top-center' => 'Top center',
        'top-right' => 'Top right',
        'center-left' => 'Center left',
        'center' => 'Center',
        'center-right' => 'Center right',
        'bottom-left' => 'Bottom left',
        'bottom-center' => 'Bottom center',
        'bottom-right' => 'Bottom right',
    ];

    public const FONTS = [
        'Arial',
        'Georgia',
        'Times New Roman',
        'Verdana',
        'Trebuchet MS',
        'Courier New',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'text_enabled' => 'boolean',
            'opacity' => 'integer',
            'text_size_percent' => 'integer',
            'max_width_percent' => 'integer',
            'margin_percent' => 'integer',
            'rotation_degrees' => 'integer',
            'shadow_opacity' => 'integer',
            'background_enabled' => 'boolean',
            'background_opacity' => 'integer',
            'background_padding_percent' => 'integer',
            'logo_enabled' => 'boolean',
            'logo_size_percent' => 'integer',
            'logo_opacity' => 'integer',
            'logo_margin_percent' => 'integer',
            'logo_rotation_degrees' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'is_enabled' => false,
            'text_enabled' => true,
            'text' => 'LHC',
            'text_color' => '#ffffff',
            'font_family' => 'Arial',
            'text_size_percent' => 6,
            'layout_mode' => 'fit',
            'max_width_percent' => 90,
            'margin_percent' => 4,
            'rotation_degrees' => 0,
            'position' => 'bottom-right',
            'opacity' => 35,
            'shadow_opacity' => 55,
            'background_enabled' => false,
            'background_color' => '#000000',
            'background_opacity' => 20,
            'background_padding_percent' => 2,
            'logo_enabled' => false,
            'logo_path' => null,
            'logo_size_percent' => 18,
            'logo_opacity' => 45,
            'logo_position' => 'bottom-left',
            'logo_margin_percent' => 4,
            'logo_rotation_degrees' => 0,
        ]);
    }
}
