@extends('layouts.app')

@section('title', 'Watermark Settings')
@section('section', 'Settings')
@section('heading', 'Watermark')

@php
    $previewPosition = 'wm-preview-mark--'.$settings->position;
    $logoPreviewPosition = 'wm-preview-logo--'.old('logo_position', $settings->logo_position ?? 'bottom-left');
    $textEnabled = (bool) old('text_enabled', $settings->text_enabled ?? true);
    $logoEnabled = (bool) old('logo_enabled', $settings->logo_enabled ?? false);
    $textSizePercent = (int) old('text_size_percent', $settings->text_size_percent ?? 6);
    $previewFontSize = max(12, min(64, $textSizePercent * 4));
    $layoutMode = old('layout_mode', $settings->layout_mode ?? 'fit');
    $maxWidthPercent = (int) old('max_width_percent', $settings->max_width_percent ?? 90);
    $marginPercent = (int) old('margin_percent', $settings->margin_percent ?? 4);
    $rotationDegrees = (int) old('rotation_degrees', $settings->rotation_degrees ?? 0);
    $shadowOpacity = (int) old('shadow_opacity', $settings->shadow_opacity ?? 55);
    $backgroundEnabled = (bool) old('background_enabled', $settings->background_enabled ?? false);
    $backgroundColor = old('background_color', $settings->background_color ?? '#000000');
    $backgroundOpacity = (int) old('background_opacity', $settings->background_opacity ?? 20);
    $backgroundPaddingPercent = (int) old('background_padding_percent', $settings->background_padding_percent ?? 2);
    $logoSizePercent = (int) old('logo_size_percent', $settings->logo_size_percent ?? 18);
    $logoOpacity = (int) old('logo_opacity', $settings->logo_opacity ?? 45);
    $logoMarginPercent = (int) old('logo_margin_percent', $settings->logo_margin_percent ?? 4);
    $logoRotationDegrees = (int) old('logo_rotation_degrees', $settings->logo_rotation_degrees ?? 0);
    $logoUrl = $settings->logo_path ? asset('storage/'.$settings->logo_path) : null;
@endphp

@section('content')
    <section class="wm-page" data-watermark-settings>
        <header class="wm-hero">
            <div>
                <p class="wm-eyebrow">Image protection</p>
                <h1>Watermark settings</h1>
                <p>Control the watermark that is applied when images are saved locally from camera, file upload, pasted images, or mirrored URLs.</p>
            </div>
            <span class="wm-status {{ $settings->is_enabled ? 'is-on' : 'is-off' }}">
                {{ $settings->is_enabled ? 'Enabled' : 'Disabled' }}
            </span>
        </header>

        <form method="POST" action="{{ route('settings.watermark.update') }}" class="wm-layout" enctype="multipart/form-data">
            @csrf
            @method('PATCH')

            <section class="wm-panel">
                <div class="wm-panel-head">
                    <h2>Watermark control</h2>
                    <p>Choose text, logo, or both. Each layer keeps its own position, size, opacity, and rotation.</p>
                </div>

                <label class="wm-toggle">
                    <input type="checkbox" name="is_enabled" value="1" data-wm-enabled @checked(old('is_enabled', $settings->is_enabled))>
                    <span>
                        <strong>Apply watermark to new local images</strong>
                        <small>Camera, upload, paste, and mirrored URL images will use this setting.</small>
                    </span>
                </label>

                <div class="wm-layer-toggles">
                    <label class="wm-toggle wm-toggle-compact">
                        <input type="checkbox" name="text_enabled" value="1" data-wm-text-enabled @checked($textEnabled)>
                        <span>
                            <strong>Use text watermark</strong>
                            <small>Keep this on for the current typed watermark.</small>
                        </span>
                    </label>

                    <label class="wm-toggle wm-toggle-compact">
                        <input type="checkbox" name="logo_enabled" value="1" data-wm-logo-enabled @checked($logoEnabled)>
                        <span>
                            <strong>Use logo watermark</strong>
                            <small>Upload your shop logo and control it separately.</small>
                        </span>
                    </label>
                </div>

                <div class="wm-section-title">
                    <h3>Text layer</h3>
                </div>

                <div class="wm-form-grid" data-wm-text-panel>
                    <label class="wm-field wm-field-wide">
                        <span>Watermark text</span>
                        <input type="text" name="text" value="{{ old('text', $settings->text) }}" maxlength="120" required data-wm-text>
                    </label>

                    <label class="wm-field">
                        <span>Text color</span>
                        <input type="color" name="text_color" value="{{ old('text_color', $settings->text_color) }}" required data-wm-color>
                    </label>

                    <label class="wm-field">
                        <span>Font</span>
                        <select name="font_family" required data-wm-font>
                            @foreach ($fonts as $font)
                                <option value="{{ $font }}" @selected(old('font_family', $settings->font_family) === $font)>{{ $font }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="wm-field wm-field-wide">
                        <span>Text size</span>
                        <div class="wm-range-row">
                            <input type="range" name="text_size_percent" min="2" max="16" value="{{ $textSizePercent }}" data-wm-size>
                            <output data-wm-size-output>{{ $textSizePercent }}%</output>
                        </div>
                        <small>Size is saved as a percentage of the image, so it scales correctly on real product photos.</small>
                    </label>

                    <label class="wm-field">
                        <span>Text layout</span>
                        <select name="layout_mode" required data-wm-layout>
                            @foreach ($layoutModes as $value => $label)
                                <option value="{{ $value }}" @selected($layoutMode === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <small>Auto-fit keeps long text on one line. Wrap only when you deliberately want multiple lines.</small>
                    </label>

                    <label class="wm-field">
                        <span>Maximum width</span>
                        <div class="wm-range-row">
                            <input type="range" name="max_width_percent" min="20" max="100" value="{{ $maxWidthPercent }}" data-wm-max-width>
                            <output data-wm-max-width-output>{{ $maxWidthPercent }}%</output>
                        </div>
                    </label>

                    <label class="wm-field wm-field-wide">
                        <span>Transparency level</span>
                        <div class="wm-range-row">
                            <input type="range" name="opacity" min="0" max="100" value="{{ old('opacity', $settings->opacity) }}" data-wm-opacity>
                            <output data-wm-opacity-output>{{ old('opacity', $settings->opacity) }}%</output>
                        </div>
                        <small>0% is invisible. 100% is fully solid.</small>
                    </label>

                    <label class="wm-field">
                        <span>Photo edge margin</span>
                        <div class="wm-range-row">
                            <input type="range" name="margin_percent" min="0" max="15" value="{{ $marginPercent }}" data-wm-margin>
                            <output data-wm-margin-output>{{ $marginPercent }}%</output>
                        </div>
                    </label>

                    <label class="wm-field">
                        <span>Rotation</span>
                        <div class="wm-range-row">
                            <input type="range" name="rotation_degrees" min="-45" max="45" value="{{ $rotationDegrees }}" data-wm-rotation>
                            <output data-wm-rotation-output>{{ $rotationDegrees }}&deg;</output>
                        </div>
                    </label>

                    <label class="wm-field wm-field-wide">
                        <span>Shadow strength</span>
                        <div class="wm-range-row">
                            <input type="range" name="shadow_opacity" min="0" max="100" value="{{ $shadowOpacity }}" data-wm-shadow>
                            <output data-wm-shadow-output>{{ $shadowOpacity }}%</output>
                        </div>
                    </label>
                </div>

                <div class="wm-subpanel">
                    <label class="wm-toggle wm-toggle-compact">
                        <input type="checkbox" name="background_enabled" value="1" data-wm-bg-enabled @checked($backgroundEnabled)>
                        <span>
                            <strong>Use background plate behind text</strong>
                            <small>Useful when the product photo is busy and the watermark needs more contrast.</small>
                        </span>
                    </label>

                    <div class="wm-form-grid">
                        <label class="wm-field">
                            <span>Plate color</span>
                            <input type="color" name="background_color" value="{{ $backgroundColor }}" required data-wm-bg-color>
                        </label>
                        <label class="wm-field">
                            <span>Plate transparency</span>
                            <div class="wm-range-row">
                                <input type="range" name="background_opacity" min="0" max="100" value="{{ $backgroundOpacity }}" data-wm-bg-opacity>
                                <output data-wm-bg-opacity-output>{{ $backgroundOpacity }}%</output>
                            </div>
                        </label>
                        <label class="wm-field wm-field-wide">
                            <span>Plate padding</span>
                            <div class="wm-range-row">
                                <input type="range" name="background_padding_percent" min="0" max="8" value="{{ $backgroundPaddingPercent }}" data-wm-bg-padding>
                                <output data-wm-bg-padding-output>{{ $backgroundPaddingPercent }}%</output>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="wm-position-field" data-wm-text-panel>
                    <span>Text position</span>
                    <div class="wm-position-grid">
                        @foreach ($positions as $value => $label)
                            <label class="wm-position-option">
                                <input type="radio" name="position" value="{{ $value }}" data-wm-position @checked(old('position', $settings->position) === $value)>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="wm-subpanel" data-wm-logo-panel>
                    <div class="wm-section-title">
                        <h3>Logo layer</h3>
                    </div>

                    @if ($logoUrl)
                        <div class="wm-current-logo">
                            <img src="{{ $logoUrl }}" alt="Current watermark logo">
                            <label>
                                <input type="checkbox" name="remove_logo" value="1" data-wm-logo-remove>
                                <span>Remove current logo</span>
                            </label>
                        </div>
                    @endif

                    <div class="wm-form-grid">
                        <label class="wm-field wm-field-wide">
                            <span>Logo file</span>
                            <input type="file" name="logo_file" accept="image/*" data-wm-logo-file>
                            <small>PNG with transparency is best. JPG, WebP and GIF are also accepted.</small>
                        </label>

                        <label class="wm-field wm-field-wide">
                            <span>Logo size</span>
                            <div class="wm-range-row">
                                <input type="range" name="logo_size_percent" min="4" max="60" value="{{ $logoSizePercent }}" data-wm-logo-size>
                                <output data-wm-logo-size-output>{{ $logoSizePercent }}%</output>
                            </div>
                            <small>Logo size is based on the shortest side of the real product photo.</small>
                        </label>

                        <label class="wm-field">
                            <span>Logo transparency</span>
                            <div class="wm-range-row">
                                <input type="range" name="logo_opacity" min="0" max="100" value="{{ $logoOpacity }}" data-wm-logo-opacity>
                                <output data-wm-logo-opacity-output>{{ $logoOpacity }}%</output>
                            </div>
                        </label>

                        <label class="wm-field">
                            <span>Logo edge margin</span>
                            <div class="wm-range-row">
                                <input type="range" name="logo_margin_percent" min="0" max="15" value="{{ $logoMarginPercent }}" data-wm-logo-margin>
                                <output data-wm-logo-margin-output>{{ $logoMarginPercent }}%</output>
                            </div>
                        </label>

                        <label class="wm-field wm-field-wide">
                            <span>Logo rotation</span>
                            <div class="wm-range-row">
                                <input type="range" name="logo_rotation_degrees" min="-45" max="45" value="{{ $logoRotationDegrees }}" data-wm-logo-rotation>
                                <output data-wm-logo-rotation-output>{{ $logoRotationDegrees }}&deg;</output>
                            </div>
                        </label>
                    </div>

                    <div class="wm-position-field">
                        <span>Logo position</span>
                        <div class="wm-position-grid">
                            @foreach ($positions as $value => $label)
                                <label class="wm-position-option">
                                    <input type="radio" name="logo_position" value="{{ $value }}" data-wm-logo-position @checked(old('logo_position', $settings->logo_position ?? 'bottom-left') === $value)>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="wm-actions">
                    <button type="submit" class="wm-save">Save watermark settings</button>
                    <a href="{{ route('brand-catalogue.index') }}" class="wm-secondary">Back to catalogue</a>
                </div>
            </section>

            <aside class="wm-preview-panel">
                <div class="wm-preview-card">
                    <div class="wm-preview-tools">
                        <label class="wm-sample-upload">
                            <input type="file" accept="image/*" data-wm-sample>
                            <span>Upload sample photo</span>
                        </label>
                        <button type="button" class="wm-sample-clear" data-wm-sample-clear hidden>Clear sample</button>
                    </div>
                    <div class="wm-preview-image" data-wm-preview>
                        <div class="wm-preview-pack">
                            <span>Product photo</span>
                            <strong>Preview</strong>
                        </div>
                        <span class="wm-preview-mark {{ $previewPosition }}" data-wm-preview-mark
                              style="color: {{ $settings->text_color }}; font-family: '{{ $settings->font_family }}', sans-serif; opacity: {{ $settings->opacity / 100 }}; font-size: {{ $previewFontSize }}px; --wm-margin: {{ $marginPercent }}%; rotate: {{ $rotationDegrees }}deg;">
                            {{ $settings->text }}
                        </span>
                        <span class="wm-preview-logo {{ $logoPreviewPosition }} {{ $logoUrl ? 'has-logo' : '' }}" data-wm-preview-logo
                              style="--wm-logo-margin: {{ $logoMarginPercent }}%; width: {{ $logoSizePercent }}%; opacity: {{ $logoOpacity / 100 }}; rotate: {{ $logoRotationDegrees }}deg;">
                            @if ($logoUrl)
                                <img src="{{ $logoUrl }}" alt="">
                            @else
                                Logo
                            @endif
                        </span>
                    </div>
                    <p>Use a real product photo here for testing only. The sample photo is not saved. Existing images are not changed automatically.</p>
                </div>
            </aside>
        </form>
    </section>

    <script>
        (() => {
            const root = document.querySelector('[data-watermark-settings]');
            if (!root) return;

            const text = root.querySelector('[data-wm-text]');
            const textEnabled = root.querySelector('[data-wm-text-enabled]');
            const textPanels = root.querySelectorAll('[data-wm-text-panel]');
            const color = root.querySelector('[data-wm-color]');
            const font = root.querySelector('[data-wm-font]');
            const size = root.querySelector('[data-wm-size]');
            const sizeOutput = root.querySelector('[data-wm-size-output]');
            const layout = root.querySelector('[data-wm-layout]');
            const maxWidth = root.querySelector('[data-wm-max-width]');
            const maxWidthOutput = root.querySelector('[data-wm-max-width-output]');
            const opacity = root.querySelector('[data-wm-opacity]');
            const opacityOutput = root.querySelector('[data-wm-opacity-output]');
            const margin = root.querySelector('[data-wm-margin]');
            const marginOutput = root.querySelector('[data-wm-margin-output]');
            const rotation = root.querySelector('[data-wm-rotation]');
            const rotationOutput = root.querySelector('[data-wm-rotation-output]');
            const shadow = root.querySelector('[data-wm-shadow]');
            const shadowOutput = root.querySelector('[data-wm-shadow-output]');
            const bgEnabled = root.querySelector('[data-wm-bg-enabled]');
            const bgColor = root.querySelector('[data-wm-bg-color]');
            const bgOpacity = root.querySelector('[data-wm-bg-opacity]');
            const bgOpacityOutput = root.querySelector('[data-wm-bg-opacity-output]');
            const bgPadding = root.querySelector('[data-wm-bg-padding]');
            const bgPaddingOutput = root.querySelector('[data-wm-bg-padding-output]');
            const preview = root.querySelector('[data-wm-preview]');
            const mark = root.querySelector('[data-wm-preview-mark]');
            const logoEnabled = root.querySelector('[data-wm-logo-enabled]');
            const logoPanel = root.querySelector('[data-wm-logo-panel]');
            const logoFile = root.querySelector('[data-wm-logo-file]');
            const logoRemove = root.querySelector('[data-wm-logo-remove]');
            const logoMark = root.querySelector('[data-wm-preview-logo]');
            const logoSize = root.querySelector('[data-wm-logo-size]');
            const logoSizeOutput = root.querySelector('[data-wm-logo-size-output]');
            const logoOpacity = root.querySelector('[data-wm-logo-opacity]');
            const logoOpacityOutput = root.querySelector('[data-wm-logo-opacity-output]');
            const logoMargin = root.querySelector('[data-wm-logo-margin]');
            const logoMarginOutput = root.querySelector('[data-wm-logo-margin-output]');
            const logoRotation = root.querySelector('[data-wm-logo-rotation]');
            const logoRotationOutput = root.querySelector('[data-wm-logo-rotation-output]');
            const sample = root.querySelector('[data-wm-sample]');
            const sampleClear = root.querySelector('[data-wm-sample-clear]');
            const positions = root.querySelectorAll('[data-wm-position]');
            const logoPositions = root.querySelectorAll('[data-wm-logo-position]');

            const update = () => {
                mark.hidden = !textEnabled.checked;
                textPanels.forEach((panel) => panel.classList.toggle('is-muted', !textEnabled.checked));
                logoMark.hidden = !logoEnabled.checked;
                logoPanel.classList.toggle('is-muted', !logoEnabled.checked);

                mark.textContent = text.value || 'Watermark';
                mark.style.color = color.value;
                mark.style.fontFamily = `'${font.value}', sans-serif`;
                mark.style.fontSize = `${Math.max(12, Math.min(64, Number(size.value) * 4))}px`;
                mark.style.opacity = String(Number(opacity.value) / 100);
                mark.style.rotate = `${rotation.value}deg`;
                mark.style.setProperty('--wm-margin', `${margin.value}%`);
                mark.style.textShadow = Number(shadow.value) > 0
                    ? `0 2px 8px rgba(0, 0, 0, ${Number(shadow.value) / 100})`
                    : 'none';
                mark.style.backgroundColor = bgEnabled.checked
                    ? hexToRgba(bgColor.value, Number(bgOpacity.value) / 100)
                    : 'transparent';
                mark.style.padding = bgEnabled.checked
                    ? `${Math.max(0, Number(bgPadding.value) * 4)}px`
                    : '0';
                sizeOutput.textContent = `${size.value}%`;
                maxWidthOutput.textContent = `${maxWidth.value}%`;
                opacityOutput.textContent = `${opacity.value}%`;
                marginOutput.textContent = `${margin.value}%`;
                rotationOutput.innerHTML = `${rotation.value}&deg;`;
                shadowOutput.textContent = `${shadow.value}%`;
                bgOpacityOutput.textContent = `${bgOpacity.value}%`;
                bgPaddingOutput.textContent = `${bgPadding.value}%`;
                logoMark.style.width = `${logoSize.value}%`;
                logoMark.style.opacity = String(Number(logoOpacity.value) / 100);
                logoMark.style.rotate = `${logoRotation.value}deg`;
                logoMark.style.setProperty('--wm-logo-margin', `${logoMargin.value}%`);
                logoSizeOutput.textContent = `${logoSize.value}%`;
                logoOpacityOutput.textContent = `${logoOpacity.value}%`;
                logoMarginOutput.textContent = `${logoMargin.value}%`;
                logoRotationOutput.innerHTML = `${logoRotation.value}&deg;`;

                positions.forEach((input) => {
                    mark.classList.toggle(`wm-preview-mark--${input.value}`, input.checked);
                });
                logoPositions.forEach((input) => {
                    logoMark.classList.toggle(`wm-preview-logo--${input.value}`, input.checked);
                });

                mark.classList.toggle('is-wrap', layout.value === 'wrap');
                mark.classList.toggle('is-fit', layout.value !== 'wrap');
                requestAnimationFrame(applyLayout);
            };

            const applyLayout = () => {
                const maxPixels = preview.clientWidth * (Number(maxWidth.value) / 100);
                mark.style.scale = '1';

                if (layout.value === 'wrap') {
                    mark.style.maxWidth = `${maxWidth.value}%`;
                    mark.style.whiteSpace = 'normal';
                    return;
                }

                mark.style.maxWidth = 'none';
                mark.style.whiteSpace = 'nowrap';

                const naturalWidth = mark.offsetWidth;
                if (naturalWidth > maxPixels && naturalWidth > 0) {
                    mark.style.scale = String(Math.max(0.2, maxPixels / naturalWidth));
                }
            };

            const hexToRgba = (hex, alpha) => {
                const value = hex.replace('#', '');
                const r = parseInt(value.slice(0, 2), 16) || 0;
                const g = parseInt(value.slice(2, 4), 16) || 0;
                const b = parseInt(value.slice(4, 6), 16) || 0;
                return `rgba(${r}, ${g}, ${b}, ${alpha})`;
            };

            sample.addEventListener('change', () => {
                const file = sample.files && sample.files[0];
                if (!file) return;

                const reader = new FileReader();
                reader.addEventListener('load', () => {
                    preview.style.backgroundImage = `url("${reader.result}")`;
                    preview.classList.add('has-custom-photo');
                    sampleClear.hidden = false;
                });
                reader.readAsDataURL(file);
            });

            sampleClear.addEventListener('click', () => {
                sample.value = '';
                preview.style.backgroundImage = '';
                preview.classList.remove('has-custom-photo');
                sampleClear.hidden = true;
            });

            logoFile.addEventListener('change', () => {
                const file = logoFile.files && logoFile.files[0];
                if (!file) return;

                const reader = new FileReader();
                reader.addEventListener('load', () => {
                    logoMark.innerHTML = `<img src="${reader.result}" alt="">`;
                    logoMark.classList.add('has-logo');
                    if (logoRemove) logoRemove.checked = false;
                });
                reader.readAsDataURL(file);
            });

            if (logoRemove) {
                logoRemove.addEventListener('change', () => {
                    if (!logoRemove.checked) return;

                    logoFile.value = '';
                    logoMark.textContent = 'Logo';
                    logoMark.classList.remove('has-logo');
                });
            }

            [
                textEnabled, logoEnabled, text, color, font, size, layout, maxWidth, opacity, margin, rotation, shadow,
                bgEnabled, bgColor, bgOpacity, bgPadding, logoSize, logoOpacity, logoMargin, logoRotation,
                ...positions, ...logoPositions
            ].forEach((field) => field.addEventListener('input', update));
            [textEnabled, logoEnabled, font, layout, bgEnabled, ...positions, ...logoPositions].forEach((field) => field.addEventListener('change', update));
            window.addEventListener('resize', applyLayout);
            update();
        })();
    </script>
@endsection
