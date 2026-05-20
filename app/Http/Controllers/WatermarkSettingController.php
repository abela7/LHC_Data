<?php

namespace App\Http\Controllers;

use App\Models\WatermarkSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WatermarkSettingController extends Controller
{
    public function edit(): View
    {
        return view('settings.watermark', [
            'settings' => WatermarkSetting::current(),
            'positions' => WatermarkSetting::POSITIONS,
            'fonts' => WatermarkSetting::FONTS,
            'layoutModes' => WatermarkSetting::LAYOUT_MODES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'text_enabled' => ['nullable', 'boolean'],
            'text' => ['required', 'string', 'max:120'],
            'text_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_family' => ['required', Rule::in(WatermarkSetting::FONTS)],
            'text_size_percent' => ['required', 'integer', 'min:2', 'max:16'],
            'layout_mode' => ['required', Rule::in(array_keys(WatermarkSetting::LAYOUT_MODES))],
            'max_width_percent' => ['required', 'integer', 'min:20', 'max:100'],
            'margin_percent' => ['required', 'integer', 'min:0', 'max:15'],
            'rotation_degrees' => ['required', 'integer', 'min:-45', 'max:45'],
            'position' => ['required', Rule::in(array_keys(WatermarkSetting::POSITIONS))],
            'opacity' => ['required', 'integer', 'min:0', 'max:100'],
            'shadow_opacity' => ['required', 'integer', 'min:0', 'max:100'],
            'background_enabled' => ['nullable', 'boolean'],
            'background_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'background_opacity' => ['required', 'integer', 'min:0', 'max:100'],
            'background_padding_percent' => ['required', 'integer', 'min:0', 'max:8'],
            'logo_enabled' => ['nullable', 'boolean'],
            'logo_file' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:4096'],
            'remove_logo' => ['nullable', 'boolean'],
            'logo_size_percent' => ['required', 'integer', 'min:4', 'max:60'],
            'logo_opacity' => ['required', 'integer', 'min:0', 'max:100'],
            'logo_position' => ['required', Rule::in(array_keys(WatermarkSetting::POSITIONS))],
            'logo_margin_percent' => ['required', 'integer', 'min:0', 'max:15'],
            'logo_rotation_degrees' => ['required', 'integer', 'min:-45', 'max:45'],
        ]);

        $settings = WatermarkSetting::current();
        $logoPath = $settings->logo_path;
        $disk = Storage::disk('public');

        if ((bool) ($validated['remove_logo'] ?? false)) {
            if ($logoPath) {
                $disk->delete($logoPath);
            }

            $logoPath = null;
        }

        if ($request->hasFile('logo_file')) {
            if ($logoPath) {
                $disk->delete($logoPath);
            }

            $logoPath = $request->file('logo_file')->store('watermarks', 'public');
        }

        $settings->update([
            'is_enabled' => (bool) ($validated['is_enabled'] ?? false),
            'text_enabled' => (bool) ($validated['text_enabled'] ?? false),
            'text' => trim($validated['text']),
            'text_color' => strtolower($validated['text_color']),
            'font_family' => $validated['font_family'],
            'text_size_percent' => (int) $validated['text_size_percent'],
            'layout_mode' => $validated['layout_mode'],
            'max_width_percent' => (int) $validated['max_width_percent'],
            'margin_percent' => (int) $validated['margin_percent'],
            'rotation_degrees' => (int) $validated['rotation_degrees'],
            'position' => $validated['position'],
            'opacity' => (int) $validated['opacity'],
            'shadow_opacity' => (int) $validated['shadow_opacity'],
            'background_enabled' => (bool) ($validated['background_enabled'] ?? false),
            'background_color' => strtolower($validated['background_color']),
            'background_opacity' => (int) $validated['background_opacity'],
            'background_padding_percent' => (int) $validated['background_padding_percent'],
            'logo_enabled' => (bool) ($validated['logo_enabled'] ?? false),
            'logo_path' => $logoPath,
            'logo_size_percent' => (int) $validated['logo_size_percent'],
            'logo_opacity' => (int) $validated['logo_opacity'],
            'logo_position' => $validated['logo_position'],
            'logo_margin_percent' => (int) $validated['logo_margin_percent'],
            'logo_rotation_degrees' => (int) $validated['logo_rotation_degrees'],
        ]);

        return back()->with('status', 'Watermark settings saved.');
    }
}
