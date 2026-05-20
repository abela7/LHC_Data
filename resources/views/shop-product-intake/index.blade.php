@extends('layouts.app')

@section('title', 'Shop Product Intake')
@section('section', 'Shop Intake')
@section('heading', 'Products')

@section('content')
    @php
        $editing = isset($intake) && $intake !== null;
        $editPayload = $editPayload ?? null;

        // When editing we override the JS first-load data attributes with the
        // saved intake's payload so the form pre-fills exactly as it was saved.
        if ($editing && $editPayload) {
            $oldRows = $editPayload['variant_rows'];
            $oldCommonVariants = $editPayload['common_variants'];
            $oldSkus = $editPayload['sku_rows'];
        } else {
            $oldRows = json_decode(old('variant_rows', '[]'), true);
            $oldRows = is_array($oldRows) ? $oldRows : [];
            $oldCommonVariants = json_decode(old('common_variants', '[]'), true);
            $oldCommonVariants = is_array($oldCommonVariants) ? $oldCommonVariants : [];
            $oldSkus = json_decode(old('sku_rows', '[]'), true);
            $oldSkus = is_array($oldSkus) ? $oldSkus : [];
        }

        // Resolve a single scalar field — old() takes priority when validation
        // failed, then the saved intake (edit mode), then the supplied default.
        $val = function (string $key, $default = '') use ($editing, $editPayload) {
            $oldValue = old($key);
            if ($oldValue !== null) {
                return $oldValue;
            }
            if ($editing && $editPayload && array_key_exists($key, $editPayload)) {
                $value = $editPayload[$key];
                return $value === null || $value === '' ? $default : $value;
            }
            return $default;
        };
    @endphp

    <section
        class="hei-page"
        data-shop-intake-root
        data-source-data='@json($sourceData)'
        data-old-rows='@json($oldRows)'
        data-old-common-variants='@json($oldCommonVariants)'
        data-old-skus='@json($oldSkus)'
        data-quick-brand-url="{{ $quickBrandUrl }}"
        data-quick-option-url="{{ $quickOptionUrl }}"
        data-structure-suggest-url="{{ $structureSuggestUrl }}"
        data-sku-name-suggest-url="{{ $skuNameSuggestUrl }}"
        data-has-old="{{ session()->hasOldInput() || $editing ? '1' : '0' }}"
        data-clear-draft="{{ session('saved_intake_id') || $editing ? '1' : '0' }}"
        data-saved-intake-id="{{ session('saved_intake_id') }}"
        data-edit-mode="{{ $editing ? '1' : '0' }}"
        @if ($editing) data-edit-payload='@json($editPayload)' @endif
    >
        <style>
            [data-shop-intake-root] {
                max-width: 980px;
                margin: 0 auto;
            }

            /* Alerts */
            .spi-alert {
                border: 1px solid rgba(16, 36, 31, 0.09);
                border-radius: 1.25rem;
                padding: 1rem 1.25rem;
                background: #fffdf8;
                color: var(--hei-ink);
                font-weight: 700;
                box-shadow: 0 2px 12px rgba(16, 36, 31, 0.04);
                animation: spi-slide-in 0.3s ease-out;
            }
            .spi-alert.success {
                border-color: rgba(13, 92, 78, .18);
                background: linear-gradient(135deg, #edf8f3 0%, #e1f5ed 100%);
                color: var(--hei-accent);
            }
            .spi-alert.success a {
                color: var(--hei-accent);
                text-decoration: underline;
                text-underline-offset: 2px;
            }
            .spi-alert.error {
                border-color: rgba(152, 51, 51, .22);
                background: linear-gradient(135deg, #fff5f4 0%, #ffe8e8 100%);
                color: var(--hei-danger);
            }

            @keyframes spi-slide-in {
                from { opacity: 0; transform: translateY(-12px); }
                to { opacity: 1; transform: translateY(0); }
            }
            @keyframes spi-shake {
                0%, 100% { transform: translateX(0); }
                25% { transform: translateX(-4px); }
                75% { transform: translateX(4px); }
            }

            /* Edit-mode banner & pill */
            .hei-pill.spi-edit-pill {
                background: linear-gradient(135deg, #f4e4c8 0%, #fce7b8 100%);
                color: #7a4c0a;
                border: 1px solid rgba(122, 76, 10, 0.22);
            }
            .hei-pill.spi-edit-pill .hei-pill-dot {
                background: #b8730e;
            }
            .spi-edit-banner {
                display: flex;
                gap: 1rem;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                padding: 0.95rem 1.15rem;
                border-radius: 1.15rem;
                border: 1.5px solid rgba(184, 115, 14, 0.25);
                background: linear-gradient(135deg, #fff8eb 0%, #fef0d3 100%);
                box-shadow: 0 2px 12px rgba(184, 115, 14, 0.08);
                animation: spi-slide-in 0.25s ease-out;
            }
            .spi-edit-banner-info {
                flex: 1;
                min-width: 0;
                color: #7a4c0a;
                font-size: 0.92rem;
                font-weight: 600;
                line-height: 1.45;
            }
            .spi-edit-banner-info strong {
                display: block;
                margin-bottom: 0.15rem;
                font-size: 0.95rem;
                color: #5e3a08;
                font-weight: 800;
            }
            .spi-edit-banner [data-spi-delete-form] {
                margin: 0;
            }
            .spi-edit-banner .hei-btn.danger {
                min-height: 44px;
                padding: 0.6rem 1.05rem;
            }

            /* Form spacing */
            [data-shop-intake-root] .hei-form-grid {
                gap: 2rem !important;
                margin-bottom: 3rem !important;
            }
            [data-shop-intake-root] .hei-step {
                gap: 1rem !important;
            }
            [data-shop-intake-root] .hei-step-head {
                position: relative;
                padding-bottom: .85rem !important;
            }
            [data-shop-intake-root] .hei-step-head::after {
                content: '';
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                height: 2px;
                background: linear-gradient(90deg, var(--hei-accent) 0%, rgba(13, 92, 78, 0.2) 100%);
                border-radius: 999px;
            }
            [data-shop-intake-root] .hei-step-head h2 {
                font-size: 1.125rem !important;
            }
            [data-shop-intake-root] .hei-step-num {
                box-shadow: 0 2px 8px rgba(13, 92, 78, 0.12);
            }

            /* Field titles */
            .spi-field-block { display: grid; gap: .35rem; }
            .spi-field-title {
                color: var(--hei-muted);
                font-size: .72rem;
                font-weight: 800;
                letter-spacing: .06em;
                text-transform: uppercase;
            }

            /* Inputs */
            [data-shop-intake-root] .hei-field > span { display: none; }
            [data-shop-intake-root] .hei-field input:not([type="checkbox"]),
            [data-shop-intake-root] .hei-field select,
            [data-shop-intake-root] .hei-field textarea {
                min-height: 54px !important;
                font-size: 1.0625rem !important;
                padding: .95rem 1.1rem !important;
            }
            [data-shop-intake-root] .hei-field textarea { min-height: 90px !important; }
            [data-shop-intake-root] .hei-field input:focus,
            [data-shop-intake-root] .hei-field select:focus,
            [data-shop-intake-root] .hei-field textarea:focus {
                outline: none;
                border-color: var(--hei-accent);
                box-shadow: 0 0 0 3px rgba(13, 92, 78, 0.1), 0 2px 12px rgba(13, 92, 78, 0.08);
            }

            /* Field + add button */
            .spi-field-with-add {
                display: grid;
                grid-template-columns: minmax(0, 1fr) 3.5rem;
                gap: .5rem;
                align-items: end;
            }
            .spi-plus-btn {
                display: grid;
                place-items: center;
                min-height: 54px;
                border: 2px dashed rgba(13, 92, 78, 0.25);
                border-radius: 1rem;
                background: rgba(237, 248, 243, 0.4);
                color: var(--hei-accent);
                font-size: 1.5rem;
                font-weight: 900;
                line-height: 1;
                cursor: pointer;
                transition: all 0.18s ease;
                -webkit-tap-highlight-color: transparent;
            }
            .spi-plus-btn:hover {
                background: rgba(212, 235, 228, 0.6);
                border-color: var(--hei-accent);
                transform: scale(1.02);
            }
            .spi-plus-btn:active { transform: scale(0.97); }

            .spi-mini-message {
                min-height: 1.1rem;
                color: var(--hei-muted);
                font-size: .78rem;
                font-weight: 700;
            }

            /* Searchable brand combobox */
            .spi-brand-combobox {
                position: relative;
                width: 100%;
            }
            .spi-brand-combobox-native {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                border: 0;
            }
            .spi-brand-combobox-shell {
                position: relative;
                display: flex;
                align-items: center;
            }
            .spi-brand-combobox-icon {
                position: absolute;
                left: 1rem;
                color: var(--hei-muted);
                pointer-events: none;
                opacity: 0.7;
            }
            .spi-brand-combobox-caret {
                position: absolute;
                right: 1rem;
                color: var(--hei-muted);
                pointer-events: none;
                font-size: 0.85rem;
                transition: transform 0.18s ease;
            }
            .spi-brand-combobox.is-open .spi-brand-combobox-caret {
                transform: rotate(180deg);
            }
            .spi-brand-combobox-clear {
                position: absolute;
                right: 2.4rem;
                width: 1.85rem;
                height: 1.85rem;
                display: grid;
                place-items: center;
                border: 0;
                background: rgba(16, 36, 31, 0.06);
                color: var(--hei-muted);
                border-radius: 999px;
                cursor: pointer;
                font-size: 1.05rem;
                line-height: 1;
                font-weight: 700;
                transition: all 0.15s ease;
            }
            .spi-brand-combobox-clear:hover {
                background: rgba(152, 51, 51, 0.12);
                color: var(--hei-danger);
            }
            .spi-brand-combobox-input {
                width: 100%;
                min-height: 54px;
                padding: 0.95rem 2.6rem 0.95rem 2.85rem !important;
                border: 1.5px solid var(--hei-edge);
                border-radius: 1rem;
                background: #fffdf8;
                font-size: 1.0625rem;
                font-weight: 600;
                color: var(--hei-ink);
                font-family: inherit;
                transition: all 0.18s ease;
            }
            .spi-brand-combobox-input::placeholder {
                color: var(--hei-muted);
                font-weight: 500;
            }
            .spi-brand-combobox-input:focus {
                outline: none;
                border-color: var(--hei-accent);
                box-shadow: 0 0 0 3px rgba(13, 92, 78, 0.1), 0 2px 12px rgba(13, 92, 78, 0.08);
                background: #ffffff;
            }
            .spi-brand-combobox.is-open .spi-brand-combobox-input {
                border-color: var(--hei-accent);
                border-bottom-left-radius: 0.35rem;
                border-bottom-right-radius: 0.35rem;
            }
            .spi-brand-combobox-list {
                position: absolute;
                z-index: 30;
                top: calc(100% + 4px);
                left: 0;
                right: 0;
                margin: 0;
                padding: 0.35rem;
                list-style: none;
                max-height: min(48vh, 20rem);
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                background: #ffffff;
                border: 1.5px solid var(--hei-accent);
                border-radius: 1rem;
                box-shadow: 0 16px 40px rgba(16, 36, 31, 0.18);
                animation: spi-slide-in 0.15s ease-out;
            }
            .spi-brand-combobox-option {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.65rem;
                padding: 0.85rem 1rem;
                margin: 0;
                border-radius: 0.65rem;
                font-size: 1rem;
                font-weight: 600;
                color: var(--hei-ink);
                cursor: pointer;
                -webkit-tap-highlight-color: transparent;
                min-height: 48px;
            }
            .spi-brand-combobox-option:hover,
            .spi-brand-combobox-option.is-active {
                background: rgba(13, 92, 78, 0.08);
                color: var(--hei-accent);
            }
            .spi-brand-combobox-option.is-selected {
                background: linear-gradient(135deg, rgba(13, 92, 78, 0.12) 0%, rgba(13, 92, 78, 0.06) 100%);
                color: var(--hei-accent);
                font-weight: 700;
            }
            .spi-brand-combobox-option-count {
                flex-shrink: 0;
                padding: 0.18rem 0.55rem;
                border-radius: 999px;
                background: rgba(13, 92, 78, 0.1);
                color: var(--hei-accent);
                font-size: 0.72rem;
                font-weight: 700;
                font-variant-numeric: tabular-nums;
            }
            .spi-brand-combobox-option-mark {
                background: rgba(13, 92, 78, 0.18);
                color: var(--hei-accent);
                padding: 0 0.1em;
                border-radius: 0.2em;
                font-weight: 700;
            }
            .spi-brand-combobox-empty {
                position: absolute;
                z-index: 30;
                top: calc(100% + 4px);
                left: 0;
                right: 0;
                margin: 0;
                padding: 1rem 1.1rem;
                background: #ffffff;
                border: 1.5px solid var(--hei-edge);
                border-radius: 1rem;
                color: var(--hei-muted);
                font-size: 0.9rem;
                font-weight: 600;
                box-shadow: 0 12px 30px rgba(16, 36, 31, 0.12);
                animation: spi-slide-in 0.15s ease-out;
            }
            .spi-brand-combobox-empty strong {
                color: var(--hei-accent);
                font-weight: 800;
                padding: 0 0.15rem;
            }

            /* Generic searchable combobox (Department, Product Type, …) */
            .spi-combobox {
                position: relative;
                width: 100%;
            }
            .spi-combobox-shell {
                position: relative;
                display: flex;
                align-items: center;
            }
            .spi-combobox-icon {
                position: absolute;
                left: 1rem;
                color: var(--hei-muted);
                pointer-events: none;
                opacity: 0.7;
            }
            .spi-combobox-caret {
                position: absolute;
                right: 1rem;
                color: var(--hei-muted);
                pointer-events: none;
                font-size: 0.85rem;
                transition: transform 0.18s ease;
            }
            .spi-combobox.is-open .spi-combobox-caret {
                transform: rotate(180deg);
            }
            .spi-combobox-clear {
                position: absolute;
                right: 2.4rem;
                width: 1.85rem;
                height: 1.85rem;
                display: grid;
                place-items: center;
                border: 0;
                background: rgba(16, 36, 31, 0.06);
                color: var(--hei-muted);
                border-radius: 999px;
                cursor: pointer;
                font-size: 1.05rem;
                line-height: 1;
                font-weight: 700;
                transition: all 0.15s ease;
            }
            .spi-combobox-clear:hover {
                background: rgba(152, 51, 51, 0.12);
                color: var(--hei-danger);
            }
            [data-shop-intake-root] .spi-combobox-input {
                width: 100%;
                min-height: 54px !important;
                padding: 0.95rem 2.6rem 0.95rem 2.85rem !important;
                border: 1.5px solid var(--hei-edge) !important;
                border-radius: 1rem !important;
                background: #fffdf8 !important;
                font-size: 1.0625rem !important;
                font-weight: 600 !important;
                color: var(--hei-ink);
                font-family: inherit;
                transition: all 0.18s ease;
            }
            [data-shop-intake-root] .spi-combobox-input::placeholder {
                color: var(--hei-muted);
                font-weight: 500;
            }
            [data-shop-intake-root] .spi-combobox-input:focus {
                outline: none;
                border-color: var(--hei-accent) !important;
                box-shadow: 0 0 0 3px rgba(13, 92, 78, 0.1), 0 2px 12px rgba(13, 92, 78, 0.08) !important;
                background: #ffffff !important;
            }
            .spi-combobox.is-open .spi-combobox-input {
                border-color: var(--hei-accent) !important;
                border-bottom-left-radius: 0.35rem !important;
                border-bottom-right-radius: 0.35rem !important;
            }
            .spi-combobox-list {
                position: absolute;
                z-index: 30;
                top: calc(100% + 4px);
                left: 0;
                right: 0;
                margin: 0;
                padding: 0.35rem;
                list-style: none;
                max-height: min(48vh, 20rem);
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                background: #ffffff;
                border: 1.5px solid var(--hei-accent);
                border-radius: 1rem;
                box-shadow: 0 16px 40px rgba(16, 36, 31, 0.18);
                animation: spi-slide-in 0.15s ease-out;
            }
            .spi-combobox-option {
                display: flex;
                align-items: center;
                gap: 0.65rem;
                padding: 0.75rem 1rem;
                margin: 0;
                border-radius: 0.65rem;
                font-size: 0.95rem;
                font-weight: 600;
                color: var(--hei-ink);
                cursor: pointer;
                -webkit-tap-highlight-color: transparent;
                min-height: 44px;
            }
            .spi-combobox-option:hover,
            .spi-combobox-option.is-active {
                background: rgba(13, 92, 78, 0.08);
                color: var(--hei-accent);
            }
            .spi-combobox-option.is-selected {
                background: linear-gradient(135deg, rgba(13, 92, 78, 0.12) 0%, rgba(13, 92, 78, 0.06) 100%);
                color: var(--hei-accent);
                font-weight: 700;
            }
            .spi-combobox-option-mark {
                background: rgba(13, 92, 78, 0.18);
                color: var(--hei-accent);
                padding: 0 0.1em;
                border-radius: 0.2em;
                font-weight: 700;
            }
            .spi-combobox-empty {
                position: absolute;
                z-index: 30;
                top: calc(100% + 4px);
                left: 0;
                right: 0;
                margin: 0;
                padding: 1rem 1.1rem;
                background: #ffffff;
                border: 1.5px solid var(--hei-edge);
                border-radius: 1rem;
                color: var(--hei-muted);
                font-size: 0.9rem;
                font-weight: 600;
                box-shadow: 0 12px 30px rgba(16, 36, 31, 0.12);
                animation: spi-slide-in 0.15s ease-out;
            }
            .spi-combobox-empty strong {
                color: var(--hei-accent);
                font-weight: 800;
                padding: 0 0.15rem;
            }

            /* Chip rows */
            .spi-chip-row,
            .spi-suggestion-row {
                display: flex;
                gap: .35rem;
                overflow-x: auto;
                padding-bottom: .35rem;
                scrollbar-width: thin;
                -webkit-overflow-scrolling: touch;
            }
            .spi-chip-row::-webkit-scrollbar { height: 4px; }
            .spi-chip-row::-webkit-scrollbar-thumb {
                background: rgba(13, 92, 78, 0.2);
                border-radius: 999px;
            }

            /* Compact, sharp preset chips (override the global pill-shaped .hei-chip-add) */
            [data-shop-intake-root] .hei-chip-add {
                min-height: 30px !important;
                padding: 0.3rem 0.6rem !important;
                border-radius: 6px !important;
                border: 1px solid rgba(13, 92, 78, 0.22) !important;
                background: #ffffff !important;
                color: var(--hei-accent);
                font-size: 0.78rem !important;
                font-weight: 600 !important;
                line-height: 1.2;
                letter-spacing: 0;
                white-space: nowrap;
                transition: all 0.15s ease;
            }
            [data-shop-intake-root] .hei-chip-add:hover {
                background: rgba(212, 235, 228, 0.55) !important;
                border-color: var(--hei-accent) !important;
            }
            [data-shop-intake-root] .hei-chip-add:active {
                transform: scale(0.96);
            }
            [data-shop-intake-root] .hei-chip-add.is-selected {
                background: var(--hei-accent) !important;
                color: #ffffff !important;
                border-color: var(--hei-accent) !important;
            }

            /* Suggestion cards */
            .spi-suggestion {
                flex: 0 0 auto;
                max-width: 18rem;
                border: 1px solid rgba(13, 92, 78, .16);
                border-radius: 1rem;
                background: #fff;
                color: var(--hei-ink);
                padding: .75rem .9rem;
                text-align: left;
                box-shadow: 0 2px 10px rgba(16, 36, 31, .04);
                cursor: pointer;
                transition: all 0.18s ease;
            }
            .spi-suggestion:hover {
                border-color: var(--hei-accent);
                background: rgba(237, 248, 243, 0.6);
                transform: translateY(-1px);
                box-shadow: 0 4px 14px rgba(13, 92, 78, 0.12);
            }
            .spi-suggestion strong,
            .spi-suggestion span {
                display: block;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .spi-suggestion strong { font-size: .9rem; font-weight: 700; }
            .spi-suggestion span {
                margin-top: .2rem;
                color: var(--hei-muted);
                font-size: .78rem;
                font-weight: 600;
            }

            /* Source-aware product suggestions (PDF / Mamado / Janson) */
            .spi-source-suggest {
                display: grid;
                gap: 0.6rem;
                padding: 0.85rem 1rem 0.95rem;
                border: 1.5px solid rgba(13, 92, 78, 0.18);
                border-radius: 1.15rem;
                background: linear-gradient(135deg, rgba(237, 248, 243, 0.55) 0%, rgba(255, 253, 248, 0.95) 100%);
                box-shadow: 0 2px 12px rgba(13, 92, 78, 0.06);
                animation: spi-slide-in 0.2s ease-out;
            }
            .spi-source-suggest-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.6rem;
            }
            .spi-source-suggest-title {
                display: inline-flex;
                align-items: center;
                gap: 0.4rem;
                color: var(--hei-accent);
                font-size: 0.7rem;
                font-weight: 800;
                letter-spacing: 0.05em;
                text-transform: uppercase;
            }
            .spi-source-suggest-count {
                color: var(--hei-muted);
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.02em;
            }
            .spi-source-suggest-list {
                display: grid;
                gap: 0.5rem;
            }
            .spi-source-suggest-empty {
                color: var(--hei-muted);
                font-size: 0.85rem;
                font-weight: 600;
                padding: 0.35rem 0.1rem;
            }

            .spi-source-card {
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 0.65rem;
                align-items: center;
                width: 100%;
                padding: 0.85rem 0.95rem;
                border: 1.5px solid rgba(16, 36, 31, 0.1);
                border-radius: 1rem;
                background: #ffffff;
                color: var(--hei-ink);
                cursor: pointer;
                text-align: left;
                font: inherit;
                transition: all 0.18s ease;
                -webkit-tap-highlight-color: transparent;
            }
            .spi-source-card:hover {
                border-color: var(--hei-accent);
                background: rgba(237, 248, 243, 0.6);
                transform: translateY(-1px);
                box-shadow: 0 4px 16px rgba(13, 92, 78, 0.12);
            }
            .spi-source-card:active { transform: translateY(0); }
            .spi-source-card.is-applied {
                border-color: var(--hei-accent);
                background: linear-gradient(135deg, #edf8f3 0%, #e1f5ed 100%);
            }

            .spi-source-card-body {
                display: grid;
                gap: 0.3rem;
                min-width: 0;
            }
            .spi-source-card-name {
                display: block;
                font-size: 0.95rem;
                font-weight: 700;
                color: var(--hei-ink);
                line-height: 1.3;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .spi-source-card-meta {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.35rem 0.55rem;
                color: var(--hei-muted);
                font-size: 0.76rem;
                font-weight: 600;
                line-height: 1.25;
            }
            .spi-source-card-meta-text {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                max-width: 100%;
            }
            .spi-source-card-pills {
                display: flex;
                flex-wrap: wrap;
                gap: 0.3rem;
                flex-shrink: 0;
            }
            .spi-source-pill {
                display: inline-flex;
                align-items: center;
                gap: 0.25rem;
                padding: 0.2rem 0.55rem;
                border-radius: 999px;
                font-size: 0.66rem;
                font-weight: 800;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                line-height: 1.3;
                white-space: nowrap;
            }
            .spi-source-pill::before {
                content: '';
                width: 5px;
                height: 5px;
                border-radius: 999px;
                background: currentColor;
            }
            .spi-source-pill[data-pill="janson"] {
                color: #155e8a;
                background: rgba(22, 119, 175, 0.12);
            }
            .spi-source-pill[data-pill="mamado"] {
                color: #9a4f0e;
                background: rgba(216, 121, 36, 0.13);
            }
            .spi-source-pill[data-pill="pdf"] {
                color: #6f1f5b;
                background: rgba(155, 56, 130, 0.12);
            }
            .spi-source-pill[data-pill="other"] {
                color: var(--hei-muted);
                background: rgba(16, 36, 31, 0.07);
            }

            .spi-source-card-mark {
                background: rgba(13, 92, 78, 0.16);
                color: var(--hei-accent);
                padding: 0 0.15em;
                border-radius: 0.2em;
                font-weight: 800;
            }
            .spi-source-card-cta {
                flex-shrink: 0;
                padding: 0.45rem 0.85rem;
                border-radius: 999px;
                background: var(--hei-accent);
                color: #ffffff;
                font-size: 0.7rem;
                font-weight: 800;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                opacity: 0;
                transform: translateX(-4px);
                transition: all 0.18s ease;
                white-space: nowrap;
            }
            .spi-source-card:hover .spi-source-card-cta {
                opacity: 1;
                transform: translateX(0);
            }
            @media (max-width: 640px) {
                .spi-source-card-cta {
                    opacity: 1;
                    transform: translateX(0);
                }
            }

            /* Sellable SKU rows — designed for fast barcode scanning */
            .spi-sku-list {
                display: grid;
                gap: .85rem;
                margin-top: .35rem;
            }
            .spi-sku-row {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: .75rem;
                align-items: center;
                border: 1.5px solid rgba(16, 36, 31, .1);
                border-radius: 1.15rem;
                background: #fff;
                padding: 1rem;
                transition: all 0.18s ease;
            }
            .spi-sku-row:has(input[data-barcode]:not(:placeholder-shown)) {
                border-color: rgba(13, 92, 78, 0.35);
                background: linear-gradient(135deg, #f6fbf8 0%, #ffffff 100%);
                box-shadow: 0 2px 10px rgba(13, 92, 78, 0.08);
            }
            .spi-sku-row:focus-within {
                border-color: var(--hei-accent);
                box-shadow: 0 0 0 3px rgba(13, 92, 78, 0.08);
            }
            .spi-sku-fields {
                display: grid;
                grid-template-columns: minmax(0, 1fr) minmax(10rem, 16rem);
                gap: .65rem;
            }
            .spi-sku-label {
                min-width: 0;
                color: var(--hei-ink);
                font-size: .9375rem;
                font-weight: 700;
            }
            .spi-sku-label small {
                display: block;
                margin-top: .25rem;
                color: var(--hei-muted);
                font-size: .75rem;
                font-weight: 600;
                letter-spacing: .01em;
            }
            .spi-sku-label strong {
                display: block;
                overflow: hidden;
                text-overflow: ellipsis;
                font-weight: 700;
                font-size: 1rem;
                line-height: 1.3;
            }
            .spi-sku-row input {
                width: 100%;
                min-height: 52px;
                border: 1.5px solid var(--hei-edge);
                border-radius: 1rem;
                padding: .85rem 1rem;
                font-size: 1.0625rem;
                font-weight: 600;
                color: var(--hei-ink);
                background: #fffdf8;
                transition: all 0.18s ease;
            }
            .spi-sku-row input:focus {
                outline: none;
                border-color: var(--hei-accent);
                box-shadow: 0 0 0 3px rgba(13, 92, 78, 0.1);
            }
            .spi-sku-row input[data-barcode] {
                font-variant-numeric: tabular-nums;
                font-size: 1.125rem;
                letter-spacing: .02em;
                background: #fff;
            }

            /* Variant headline at the top of every SKU row — what the user is scanning */
            .spi-sku-headline {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.55rem;
                margin-bottom: 0.15rem;
                padding-right: 5.5rem; /* leave room for the status pill */
            }
            .spi-sku-index {
                display: inline-flex;
                align-items: center;
                padding: 0.18rem 0.5rem;
                border-radius: 6px;
                background: rgba(16, 36, 31, 0.07);
                color: var(--hei-muted);
                font-size: 0.7rem;
                font-weight: 800;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                font-variant-numeric: tabular-nums;
                line-height: 1.3;
            }
            .spi-sku-variant-pills {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.35rem;
                min-width: 0;
            }
            .spi-sku-variant-pill {
                display: inline-flex;
                align-items: center;
                padding: 0.32rem 0.7rem;
                border-radius: 8px;
                background: rgba(16, 36, 31, 0.05);
                color: var(--hei-ink);
                font-size: 0.95rem;
                font-weight: 700;
                line-height: 1.25;
                letter-spacing: -0.01em;
                transition: all 0.18s ease;
            }
            .spi-sku-variant-pill.is-primary {
                background: rgba(13, 92, 78, 0.1);
                color: var(--hei-accent);
                font-weight: 800;
            }
            .spi-sku-variant-pills .spi-sku-variant-pill + .spi-sku-variant-pill::before {
                content: '×';
                margin-right: 0.45rem;
                color: var(--hei-muted);
                font-weight: 600;
                opacity: 0.6;
            }
            @media (max-width: 640px) {
                .spi-sku-headline {
                    padding-right: 4.5rem;
                }
            }

            /* When this row is the one being scanned, blow the variant up */
            .spi-sku-row.is-current .spi-sku-variant-pill {
                font-size: 1.25rem;
                padding: 0.45rem 0.9rem;
                background: linear-gradient(135deg, rgba(13, 92, 78, 0.16) 0%, rgba(13, 92, 78, 0.08) 100%);
                color: var(--hei-accent);
                font-weight: 800;
                box-shadow: 0 2px 8px rgba(13, 92, 78, 0.12);
            }
            .spi-sku-row.is-current .spi-sku-variant-pill.is-primary {
                background: linear-gradient(135deg, var(--hei-accent) 0%, #157a68 100%);
                color: #ffffff;
                box-shadow: 0 4px 14px rgba(13, 92, 78, 0.3);
            }
            .spi-sku-row.is-current .spi-sku-headline {
                margin-bottom: 0.35rem;
            }
            .spi-sku-row.is-current .spi-sku-label strong {
                font-size: 0.875rem;
                color: var(--hei-muted);
                font-weight: 600;
            }
            .spi-sku-row.is-current .spi-sku-label small {
                display: none;
            }

            /* On scanned rows, the variant pills go solid green so completed SKUs are easy to scan visually */
            .spi-sku-row.is-scanned .spi-sku-variant-pill.is-primary {
                background: rgba(13, 92, 78, 0.18);
                color: var(--hei-accent);
            }

            /* === Scan-and-go workflow === */

            /* Sticky progress bar above the SKU list */
            .spi-scan-bar {
                position: sticky;
                top: 0;
                z-index: 15;
                display: grid;
                grid-template-columns: 1fr auto;
                grid-template-areas:
                    "info cta"
                    "progress progress";
                gap: 0.65rem 1rem;
                padding: 0.85rem 1rem;
                margin-top: 0.25rem;
                border: 1.5px solid rgba(13, 92, 78, 0.18);
                border-radius: 1.15rem;
                background: linear-gradient(135deg, rgba(237, 248, 243, 0.95) 0%, rgba(255, 253, 248, 0.98) 100%);
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                box-shadow: 0 4px 16px rgba(13, 92, 78, 0.08);
                animation: spi-slide-in 0.25s ease-out;
            }
            .spi-scan-bar-info {
                grid-area: info;
                display: flex;
                flex-direction: column;
                gap: 0.1rem;
                min-width: 0;
            }
            .spi-scan-bar-label {
                color: var(--hei-muted);
                font-size: 0.7rem;
                font-weight: 800;
                letter-spacing: 0.06em;
                text-transform: uppercase;
            }
            .spi-scan-bar-count {
                color: var(--hei-ink);
                font-size: 1.25rem;
                font-weight: 800;
                letter-spacing: -0.01em;
                font-variant-numeric: tabular-nums;
            }
            .spi-scan-bar-count strong {
                color: var(--hei-accent);
                font-weight: 800;
            }
            .spi-scan-bar-total {
                color: var(--hei-muted);
                font-size: 0.95rem;
                font-weight: 700;
                margin-left: 0.15rem;
            }
            .spi-scan-bar-cta {
                grid-area: cta;
                min-height: 48px !important;
                padding: 0.75rem 1.15rem !important;
                font-size: 0.9375rem !important;
            }
            .spi-scan-bar-progress {
                grid-area: progress;
                position: relative;
                height: 6px;
                width: 100%;
                background: rgba(13, 92, 78, 0.1);
                border-radius: 999px;
                overflow: hidden;
            }
            .spi-scan-bar-progress-fill {
                position: absolute;
                inset: 0;
                width: 0%;
                background: linear-gradient(90deg, var(--hei-accent) 0%, #157a68 100%);
                border-radius: 999px;
                transition: width 0.25s ease-out;
            }
            .spi-scan-bar.is-complete {
                border-color: var(--hei-accent);
                background: linear-gradient(135deg, #d4ebe4 0%, #edf8f3 100%);
            }
            .spi-scan-bar.is-complete .spi-scan-bar-cta {
                background: linear-gradient(180deg, #157a68 0%, var(--hei-accent) 100%);
            }

            /* Per-row scanning state */
            .spi-sku-row {
                position: relative;
            }
            .spi-sku-row .spi-sku-status {
                position: absolute;
                top: 0.85rem;
                right: 0.95rem;
                display: inline-flex;
                align-items: center;
                gap: 0.3rem;
                padding: 0.2rem 0.6rem;
                border-radius: 999px;
                font-size: 0.66rem;
                font-weight: 800;
                letter-spacing: 0.05em;
                text-transform: uppercase;
                background: rgba(16, 36, 31, 0.06);
                color: var(--hei-muted);
                pointer-events: none;
                line-height: 1.3;
                transition: all 0.18s ease;
            }
            .spi-sku-row .spi-sku-status::before {
                content: '';
                width: 6px;
                height: 6px;
                border-radius: 999px;
                background: currentColor;
            }
            .spi-sku-row.is-current .spi-sku-status {
                background: rgba(13, 92, 78, 0.12);
                color: var(--hei-accent);
            }
            .spi-sku-row.is-current .spi-sku-status::before {
                animation: spi-pulse 1.4s ease-in-out infinite;
            }
            .spi-sku-row.is-scanned .spi-sku-status {
                background: linear-gradient(135deg, #157a68 0%, var(--hei-accent) 100%);
                color: #ffffff;
            }
            .spi-sku-row.is-scanned .spi-sku-status::before {
                background: #ffffff;
                animation: none;
            }
            @keyframes spi-pulse {
                0%, 100% { opacity: 1; transform: scale(1); }
                50% { opacity: 0.4; transform: scale(1.4); }
            }
            @media (prefers-reduced-motion: reduce) {
                .spi-sku-row.is-current .spi-sku-status::before { animation: none; }
            }

            .spi-sku-row.is-current {
                border-color: var(--hei-accent);
                background: linear-gradient(135deg, #fafefb 0%, #f0faf4 100%);
                box-shadow: 0 0 0 3px rgba(13, 92, 78, 0.12), 0 4px 16px rgba(13, 92, 78, 0.1);
            }
            .spi-sku-row.is-current input[data-barcode] {
                border-color: var(--hei-accent);
                box-shadow: 0 0 0 3px rgba(13, 92, 78, 0.14);
                background: #ffffff;
            }
            .spi-sku-row.is-current input[data-barcode]::placeholder {
                color: var(--hei-accent);
                font-weight: 700;
                opacity: 0.5;
            }
            .spi-sku-row.is-scanned {
                border-color: rgba(13, 92, 78, 0.45);
                background: linear-gradient(135deg, #f6fbf8 0%, #ebf5ef 100%);
            }
            .spi-sku-row.is-scanned input[data-barcode] {
                border-color: rgba(13, 92, 78, 0.45);
                background: #ffffff;
                color: var(--hei-accent);
                font-weight: 700;
            }

            /* Brief flash when a barcode is captured */
            .spi-sku-row.just-scanned {
                animation: spi-scan-flash 0.55s ease-out;
            }
            @keyframes spi-scan-flash {
                0% { box-shadow: 0 0 0 6px rgba(13, 92, 78, 0.35), 0 4px 16px rgba(13, 92, 78, 0.18); transform: scale(1.005); }
                100% { box-shadow: 0 0 0 0 rgba(13, 92, 78, 0); transform: scale(1); }
            }
            @media (prefers-reduced-motion: reduce) {
                .spi-sku-row.just-scanned { animation: none; }
            }

            @media (max-width: 640px) {
                .spi-scan-bar {
                    grid-template-columns: 1fr;
                    grid-template-areas:
                        "info"
                        "progress"
                        "cta";
                }
                .spi-scan-bar-cta {
                    width: 100%;
                }
            }

            /* Submit row */
            .spi-submit-row {
                display: flex;
                flex-wrap: wrap;
                gap: .75rem;
                justify-content: flex-end;
                align-items: center;
                margin-top: 1.25rem;
            }
            .spi-submit-count {
                margin-right: auto;
                padding: .75rem 1.15rem;
                border-radius: 999px;
                background: linear-gradient(135deg, rgba(13, 92, 78, .08) 0%, rgba(13, 92, 78, .04) 100%);
                font-size: .9375rem;
                font-weight: 700;
                color: var(--hei-accent);
                border: 1px solid rgba(13, 92, 78, .12);
            }
            .spi-inline-error {
                display: block;
                color: var(--hei-danger);
                font-size: .82rem;
                font-weight: 700;
                animation: spi-shake 0.4s ease-in-out;
            }

            /* Buttons */
            [data-shop-intake-root] .hei-btn.primary {
                box-shadow: 0 4px 12px rgba(13, 92, 78, 0.35) !important;
                font-weight: 700 !important;
                letter-spacing: -0.01em;
                min-height: 56px !important;
                font-size: 1.0625rem !important;
                padding: .95rem 1.5rem !important;
            }
            [data-shop-intake-root] .hei-btn.primary:hover {
                box-shadow: 0 6px 16px rgba(13, 92, 78, 0.4) !important;
            }
            [data-shop-intake-root] .hei-btn.secondary {
                transition: all 0.2s ease;
                min-height: 48px !important;
            }
            [data-shop-intake-root] .hei-btn.secondary:hover {
                border-color: var(--hei-accent);
                background: var(--hei-accent-soft);
                color: var(--hei-accent);
            }

            /* Compact "keep" radio-style checkboxes */
            .spi-rapid-row {
                display: flex;
                flex-wrap: wrap;
                gap: 1rem;
                align-items: center;
                padding-top: .25rem;
            }
            .spi-check {
                display: inline-flex;
                align-items: center;
                gap: .5rem;
                cursor: pointer;
                user-select: none;
                -webkit-tap-highlight-color: transparent;
            }
            .spi-check input {
                width: 1rem;
                height: 1rem;
                cursor: pointer;
                flex-shrink: 0;
                appearance: none;
                -webkit-appearance: none;
                border: 2px solid rgba(16, 36, 31, 0.25);
                border-radius: 4px;
                background: #ffffff;
                transition: all 0.15s ease;
                position: relative;
                margin: 0;
            }
            .spi-check input:checked {
                background: var(--hei-accent);
                border-color: var(--hei-accent);
            }
            .spi-check input:checked::after {
                content: '';
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 10px;
                height: 10px;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' fill='none'%3E%3Cpath d='M2 6l3 3 5-5' stroke='white' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
                background-size: contain;
                background-repeat: no-repeat;
            }
            .spi-check span {
                font-size: .875rem;
                font-weight: 500;
                color: var(--hei-muted);
            }
            .spi-check:has(input:checked) span {
                color: var(--hei-ink);
                font-weight: 600;
            }

            /* Modal */
            .spi-modal-backdrop {
                position: fixed;
                inset: 0;
                display: grid;
                place-items: center;
                background: rgba(16, 36, 31, .55);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
                z-index: 80;
                padding: 1rem;
                animation: spi-slide-in 0.2s ease-out;
            }
            .spi-modal-backdrop[hidden] { display: none; }
            .spi-modal {
                width: min(100%, 25rem);
                display: grid;
                gap: 1rem;
                border-radius: 1.35rem;
                background: #fffdf8;
                padding: 1.4rem;
                box-shadow: 0 24px 70px rgba(16, 36, 31, .25);
            }
            .spi-modal-head {
                display: flex;
                justify-content: space-between;
                gap: 1rem;
                align-items: center;
            }
            .spi-modal-title {
                margin: 0;
                color: var(--hei-ink);
                font-size: 1.125rem;
                font-weight: 800;
                letter-spacing: -0.01em;
            }
            .spi-modal-close {
                border: 0;
                background: transparent;
                color: var(--hei-muted);
                font-size: 1.5rem;
                font-weight: 900;
                cursor: pointer;
                width: 2rem;
                height: 2rem;
                border-radius: 999px;
                display: grid;
                place-items: center;
                transition: background 0.15s ease;
            }
            .spi-modal-close:hover { background: rgba(16, 36, 31, 0.06); }
            .spi-modal input {
                width: 100%;
                min-height: 54px;
                border: 1.5px solid var(--hei-edge);
                border-radius: 1rem;
                padding: .9rem 1rem;
                font-size: 1.0625rem;
                font-weight: 600;
            }
            .spi-modal input:focus {
                outline: none;
                border-color: var(--hei-accent);
                box-shadow: 0 0 0 3px rgba(13, 92, 78, 0.1);
            }
            .spi-modal-actions {
                display: flex;
                gap: .6rem;
                justify-content: flex-end;
            }

            /* Variant axis cards & common */
            .spi-axis-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: .85rem;
            }
            .spi-axis-card {
                display: grid;
                gap: .65rem;
                border: 1.5px solid rgba(16, 36, 31, .1);
                border-radius: 1.15rem;
                background: #fff;
                padding: 1rem;
                transition: all 0.18s ease;
            }
            .spi-axis-card:focus-within {
                border-color: var(--hei-accent);
                box-shadow: 0 2px 10px rgba(13, 92, 78, 0.08);
            }

            .spi-common-card {
                display: grid;
                gap: .85rem;
                border: 1.5px solid rgba(16, 36, 31, .1);
                border-radius: 1.15rem;
                background: #fff;
                padding: 1rem;
            }
            .spi-common-card .hei-variant-value-box {
                min-height: 110px !important;
                padding: 1rem !important;
                gap: 0.75rem !important;
            }

            /* Variant rows (mapped values) */
            [data-shop-intake-root] .hei-map-groups {
                display: flex !important;
                flex-direction: column;
                gap: 1rem !important;
                margin-top: .35rem;
            }
            [data-shop-intake-root] .hei-map-group-card {
                padding: 1rem !important;
            }
            [data-shop-intake-root] .hei-variant-value-box {
                min-height: 110px !important;
                padding: 1rem !important;
                gap: 0.75rem !important;
            }
            [data-shop-intake-root] .hei-variant-chip {
                min-height: 38px !important;
                padding: 0.6rem 0.85rem !important;
                font-size: 0.9375rem !important;
            }

            .spi-row-title {
                display: flex;
                flex-wrap: wrap;
                gap: .4rem;
                align-items: center;
                color: var(--hei-muted);
                font-size: .7rem;
                font-weight: 800;
                letter-spacing: .04em;
                text-transform: uppercase;
            }
            .spi-row-title span {
                border-radius: 999px;
                background: rgba(13, 92, 78, .08);
                color: var(--hei-accent);
                padding: .25rem .55rem;
            }

            /* AI assist panel */
            .spi-assist-panel {
                display: grid;
                gap: .65rem;
                border: 1px solid rgba(13, 92, 78, .14);
                border-radius: 1.15rem;
                background: linear-gradient(135deg, rgba(237, 248, 243, .65) 0%, rgba(255, 253, 248, .92) 100%);
                padding: .95rem;
            }
            .spi-assist-top {
                display: flex;
                flex-wrap: wrap;
                gap: .55rem;
                align-items: center;
            }
            .spi-assist-message,
            .spi-name-message {
                color: var(--hei-muted);
                font-size: .82rem;
                font-weight: 700;
            }
            .spi-structure-results { display: grid; gap: .55rem; }
            .spi-structure-card {
                display: grid;
                gap: .5rem;
                border: 1px solid rgba(16, 36, 31, .09);
                border-radius: 1rem;
                background: #fff;
                padding: .85rem;
            }
            .spi-structure-head {
                display: flex;
                justify-content: space-between;
                gap: .6rem;
                align-items: center;
                color: var(--hei-muted);
                font-size: .7rem;
                font-weight: 800;
                letter-spacing: .04em;
                text-transform: uppercase;
            }
            .spi-structure-values {
                display: flex;
                flex-wrap: wrap;
                gap: .4rem;
            }
            .spi-structure-values span {
                border-radius: 999px;
                background: rgba(13, 92, 78, .08);
                color: var(--hei-accent);
                padding: .35rem .65rem;
                font-size: .78rem;
                font-weight: 700;
            }
            .spi-structure-reason {
                color: var(--hei-muted);
                font-size: .8rem;
                font-weight: 600;
            }

            /* Mobile */
            @media (max-width: 640px) {
                .spi-axis-grid,
                .spi-sku-fields {
                    grid-template-columns: 1fr;
                }
                .spi-submit-row .hei-btn,
                .spi-submit-count {
                    width: 100%;
                }
                .spi-submit-row {
                    position: fixed;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    background: rgba(255, 255, 255, .98);
                    backdrop-filter: blur(12px);
                    -webkit-backdrop-filter: blur(12px);
                    padding: 1rem;
                    margin: 0;
                    border-top: 1px solid rgba(16, 36, 31, .08);
                    box-shadow: 0 -4px 24px rgba(16, 36, 31, .08);
                    z-index: 20;
                }
                .spi-submit-count {
                    order: -1;
                    font-size: .8125rem;
                }
                .spi-inline-error {
                    order: -2;
                    width: 100%;
                    text-align: center;
                }
                [data-shop-intake-root] .hei-form-grid {
                    margin-bottom: 8rem !important;
                }
                .spi-sku-row {
                    padding: .85rem;
                }
                .spi-modal { padding: 1.15rem; }
            }

            @media (prefers-reduced-motion: reduce) {
                .spi-alert,
                .spi-inline-error,
                .spi-modal-backdrop {
                    animation: none;
                }
                .spi-suggestion,
                .spi-plus-btn,
                .spi-axis-card,
                .spi-sku-row,
                [data-shop-intake-root] .hei-btn {
                    transition: none;
                }
            }
        </style>

        <div class="hei-toolbar">
            <div class="hei-toolbar-meta">
                <div class="hei-badge-row">
                    @if ($editing)
                        <span class="hei-pill spi-edit-pill">
                            <span class="hei-pill-dot" aria-hidden="true"></span>
                            Editing intake #{{ $intake->id }}
                        </span>
                        @if ($intake->submitted_at)
                            <span class="hei-session-meter">Saved {{ $intake->submitted_at->format('d M Y H:i') }}</span>
                        @endif
                    @else
                        <span class="hei-pill"><span class="hei-pill-dot" aria-hidden="true"></span> Shop intake</span>
                        <span class="hei-session-meter">Saved: <strong data-session-count>0</strong></span>
                    @endif
                </div>
            </div>
            <div class="hei-toolbar-actions">
                @if ($editing)
                    <a class="hei-btn secondary" href="{{ $newIntakeUrl }}">+ New intake</a>
                    <a class="hei-btn secondary" href="{{ $submittedUrl }}">All submitted</a>
                    <a class="hei-btn secondary" href="{{ $normalizationUrl }}">Normalize sources</a>
                @else
                    <a class="hei-btn secondary" href="{{ $submittedUrl }}">Submitted</a>
                    <a class="hei-btn secondary" href="{{ $normalizationUrl }}">Normalize sources</a>
                    <button type="button" class="hei-btn linkish" data-clear-form>Clear</button>
                @endif
            </div>
        </div>

        @if ($editing)
            <div class="spi-edit-banner" role="status">
                <div class="spi-edit-banner-info">
                    <strong>You're editing a submitted intake.</strong>
                    Changes overwrite the saved record. The {{ count($oldSkus) }}
                    SKU{{ count($oldSkus) === 1 ? '' : 's' }} and any scanned barcodes are preserved below.
                </div>
                @if ($destroyUrl)
                    <form method="POST" action="{{ $destroyUrl }}" data-spi-delete-form
                          onsubmit="return confirm('Permanently delete this submitted intake? This cannot be undone.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="hei-btn danger">Delete intake</button>
                    </form>
                @endif
            </div>
        @endif

        @if (session('status'))
            <div class="spi-alert success">
                {{ session('status') }}
                @if (! $editing)
                    <a href="{{ $submittedUrl }}">Open submitted intake</a>
                @endif
            </div>
        @endif

        @if ($errors->any())
            <div class="spi-alert error"><strong>Fix before saving:</strong> {{ $errors->first() }}</div>
        @endif

        <form class="hei-form-card hei-form-grid" method="POST" action="{{ $submitUrl }}" data-shop-intake-form>
            @csrf
            @if ($editing)
                @method('PATCH')
            @endif
            <input type="hidden" name="source_product_family_id" value="{{ $editing ? ($editPayload['source_product_family_id'] ?? '') : old('source_product_family_id') }}" data-source-family-id>
            <input type="hidden" name="variant_rows" value="{{ $editing ? json_encode($oldRows) : old('variant_rows', '[]') }}" data-variant-json>
            <input type="hidden" name="common_variants" value="{{ $editing ? json_encode($oldCommonVariants) : old('common_variants', '[]') }}" data-common-json>
            <input type="hidden" name="sku_rows" value="{{ $editing ? json_encode($oldSkus) : old('sku_rows', '[]') }}" data-sku-json>

            <datalist id="spi-product-type-options">
                @foreach ($productTypeOptions as $option)
                    <option value="{{ $option }}"></option>
                @endforeach
            </datalist>
            <datalist id="spi-department-options">
                @foreach ($departmentOptions as $option)
                    <option value="{{ $option }}"></option>
                @endforeach
            </datalist>
            <datalist id="spi-variant-axis-options">
                @foreach (['Size', 'Scent', 'Colour', 'Strength', 'Formula', 'Pack Count', 'Shade', 'Fragrance', 'Hold Level', 'Skin Type', 'Hair Type', 'Standard'] as $option)
                    <option value="{{ $option }}"></option>
                @endforeach
            </datalist>

            <section class="hei-step" aria-labelledby="spi-step-product">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="spi-step-product">1</span>
                    <h2>Product</h2>
                </div>

                <div class="spi-field-with-add">
                    <div class="spi-field-block">
                        <div class="spi-field-title">Brand</div>
                        <div class="spi-brand-combobox" data-brand-combobox>
                            <div class="spi-brand-combobox-shell">
                                <svg class="spi-brand-combobox-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <circle cx="11" cy="11" r="7"></circle>
                                    <path d="m20 20-3-3"></path>
                                </svg>
                                <input
                                    type="text"
                                    class="spi-brand-combobox-input"
                                    data-brand-combobox-input
                                    placeholder="Search or pick a brand..."
                                    autocomplete="off"
                                    role="combobox"
                                    aria-autocomplete="list"
                                    aria-expanded="false"
                                    aria-controls="spi-brand-combobox-list"
                                    enterkeyhint="search"
                                >
                                <button type="button" class="spi-brand-combobox-clear" data-brand-combobox-clear aria-label="Clear brand" hidden>×</button>
                                <span class="spi-brand-combobox-caret" aria-hidden="true">▾</span>
                            </div>
                            <ul class="spi-brand-combobox-list" id="spi-brand-combobox-list" data-brand-combobox-list role="listbox" hidden></ul>
                            <p class="spi-brand-combobox-empty" data-brand-combobox-empty hidden>
                                No brand matches. Tap <strong>+</strong> to add a new one.
                            </p>
                            <label class="hei-field spi-brand-combobox-native">
                                <select name="brand_name" data-brand-select required tabindex="-1" aria-hidden="true">
                                    <option value="">Choose brand...</option>
                                    @foreach ($brands as $brand)
                                        <option
                                            value="{{ $brand['name'] }}"
                                            data-brand-display="{{ $brand['name'] }}"
                                            data-brand-count="{{ $brand['family_count'] }}"
                                            @selected($val('brand_name') === $brand['name'])
                                        >
                                            {{ $brand['name'] }}{{ $brand['family_count'] ? ' ('.$brand['family_count'].')' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </div>
                    <button type="button" class="spi-plus-btn" data-open-add-modal="brand" aria-label="Add brand">+</button>
                </div>
                <div class="spi-mini-message" data-add-message="brand"></div>

                <div class="spi-field-with-add">
                    <div class="spi-field-block">
                        <div class="spi-field-title">Department</div>
                        <div class="spi-combobox" data-spi-combobox="department" data-options-id="spi-department-options">
                            <div class="spi-combobox-shell">
                                <svg class="spi-combobox-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <circle cx="11" cy="11" r="7"></circle>
                                    <path d="m20 20-3-3"></path>
                                </svg>
                                <input class="spi-combobox-input" name="department_name" value="{{ $val('department_name') }}" list="spi-department-options" placeholder="Search departments..." data-department autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false">
                                <button type="button" class="spi-combobox-clear" data-spi-combobox-clear aria-label="Clear" hidden>×</button>
                                <span class="spi-combobox-caret" aria-hidden="true">▾</span>
                            </div>
                            <ul class="spi-combobox-list" data-spi-combobox-list role="listbox" hidden></ul>
                            <p class="spi-combobox-empty" data-spi-combobox-empty hidden>
                                No match. Tap <strong>+</strong> to add one, or type a new value.
                            </p>
                        </div>
                    </div>
                    <button type="button" class="spi-plus-btn" data-open-add-modal="department" aria-label="Add department">+</button>
                </div>
                <div class="spi-mini-message" data-add-message="department"></div>
                <div class="spi-chip-row">
                    @foreach (['Skin Care', 'Hair Products', 'General Products', 'Body Care'] as $option)
                        <button type="button" class="hei-chip-add" data-department-preset="{{ $option }}">{{ $option }}</button>
                    @endforeach
                </div>

                <div class="spi-field-with-add">
                    <div class="spi-field-block">
                        <div class="spi-field-title">Product Type</div>
                        <div class="spi-combobox" data-spi-combobox="product_type" data-options-id="spi-product-type-options">
                            <div class="spi-combobox-shell">
                                <svg class="spi-combobox-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <circle cx="11" cy="11" r="7"></circle>
                                    <path d="m20 20-3-3"></path>
                                </svg>
                                <input class="spi-combobox-input" name="product_type_name" value="{{ $val('product_type_name') }}" list="spi-product-type-options" placeholder="Search product types..." data-product-type autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false">
                                <button type="button" class="spi-combobox-clear" data-spi-combobox-clear aria-label="Clear" hidden>×</button>
                                <span class="spi-combobox-caret" aria-hidden="true">▾</span>
                            </div>
                            <ul class="spi-combobox-list" data-spi-combobox-list role="listbox" hidden></ul>
                            <p class="spi-combobox-empty" data-spi-combobox-empty hidden>
                                No match. Tap <strong>+</strong> to add one, or type a new value.
                            </p>
                        </div>
                    </div>
                    <button type="button" class="spi-plus-btn" data-open-add-modal="product_type" aria-label="Add product type">+</button>
                </div>
                <div class="spi-mini-message" data-add-message="product_type"></div>
                <div class="spi-chip-row">
                    @foreach (['Body Lotion', 'Body Cream', 'Soap', 'Shampoo', 'Conditioner', 'Hair Treatment', 'Styling Gel', 'Skin Treatment'] as $option)
                        <button type="button" class="hei-chip-add" data-product-type-preset="{{ $option }}">{{ $option }}</button>
                    @endforeach
                </div>

                <div class="spi-field-block">
                    <div class="spi-field-title">Product / Family Name</div>
                    <label class="hei-field">
                        <input name="family_name" value="{{ $val('family_name') }}" placeholder="Name visible on pack" required data-family-name autocomplete="off">
                    </label>
                </div>

                <div class="spi-source-suggest" data-source-suggest hidden>
                    <div class="spi-source-suggest-head">
                        <span class="spi-source-suggest-title">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <circle cx="11" cy="11" r="7"></circle>
                                <path d="m20 20-3-3"></path>
                            </svg>
                            Matches from your sources
                        </span>
                        <span class="spi-source-suggest-count" data-source-suggest-count></span>
                    </div>
                    <div class="spi-source-suggest-list" data-source-suggestions></div>
                    <div class="spi-source-suggest-empty" data-source-suggest-empty hidden>
                        No match in PDF, Mamado or Janson — this looks like a new product.
                    </div>
                </div>

                <div class="spi-assist-panel">
                    <div class="spi-assist-top">
                        <button type="button" class="hei-btn secondary" data-suggest-structure>Suggest Department &amp; Type</button>
                        <span class="spi-assist-message" data-structure-message></span>
                    </div>
                    <div class="spi-structure-results" data-structure-results hidden></div>
                </div>

                <div class="spi-rapid-row">
                    <label class="spi-check"><input type="checkbox" checked data-keep-brand><span>Keep brand</span></label>
                    <label class="spi-check"><input type="checkbox" checked data-keep-department><span>Keep department</span></label>
                    <label class="spi-check"><input type="checkbox" checked data-keep-type><span>Keep type</span></label>
                </div>
            </section>

            <section class="hei-step" aria-labelledby="spi-step-variants">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="spi-step-variants">2</span>
                    <h2>Variants</h2>
                </div>

                <div class="spi-axis-grid">
                    <div class="spi-axis-card">
                        <div class="spi-field-title">Main Variant Axis</div>
                        <label class="hei-field">
                            <input type="text" name="variant_main_axis" value="{{ $val('variant_main_axis', 'Size') }}" data-main-axis list="spi-variant-axis-options" placeholder="Example: Size" enterkeyhint="next">
                        </label>
                        <div class="spi-chip-row">
                            @foreach (['Size', 'Formula', 'Strength', 'Pack Count', 'Shade', 'Standard'] as $option)
                                <button type="button" class="hei-chip-add" data-main-axis-preset="{{ $option }}">{{ $option }}</button>
                            @endforeach
                        </div>
                        <label class="spi-check"><input type="checkbox" checked data-main-name-enabled><span>Use in name</span></label>
                    </div>
                    <div class="spi-axis-card">
                        <div class="spi-field-title">Sub Variant Axis</div>
                        <label class="hei-field">
                            <input type="text" name="variant_sub_axis" data-sub-axis-global list="spi-variant-axis-options" value="{{ $val('variant_sub_axis', 'Colour') }}" placeholder="Example: Colour" enterkeyhint="next">
                        </label>
                        <div class="spi-chip-row">
                            @foreach (['Colour', 'Scent', 'Formula', 'Skin Type', 'Shade', 'Standard'] as $option)
                                <button type="button" class="hei-chip-add" data-sub-axis-preset="{{ $option }}">{{ $option }}</button>
                            @endforeach
                        </div>
                        <label class="spi-check"><input type="checkbox" checked data-sub-name-enabled><span>Use in name</span></label>
                    </div>
                </div>

                <div class="hei-map-groups" data-variant-groups></div>

                <details class="hei-mini-accordion">
                    <summary>Bulk paste</summary>
                    <div style="display:grid;gap:.75rem;padding:1rem;">
                        <label class="hei-field">
                            <textarea rows="3" placeholder="100ml: Red,Blue&#10;200ml: Red,Green" data-quick-map></textarea>
                        </label>
                        <button type="button" class="hei-btn secondary" data-quick-map-apply>Build rows</button>
                    </div>
                </details>

                <button type="button" class="hei-btn secondary" data-add-variant-row>+ Add variant row</button>

                <div class="spi-field-block">
                    <div class="spi-field-title">Common Variant</div>
                    <div class="spi-common-card" data-common-variant>
                        <label class="hei-field">
                            <input type="text" data-common-axis list="spi-variant-axis-options" placeholder="Shared axis, e.g. Size" enterkeyhint="next">
                        </label>
                        <div class="spi-chip-row">
                            @foreach (['Size', 'Strength', 'Pack Count', 'Formula', 'Skin Type'] as $option)
                                <button type="button" class="hei-chip-add" data-common-axis-preset="{{ $option }}">{{ $option }}</button>
                            @endforeach
                        </div>
                        <label class="spi-check"><input type="checkbox" data-common-name-enabled><span>Use common in name</span></label>
                        <div class="hei-field">
                            <div class="hei-variant-value-box">
                                <div class="hei-variant-chip-list" data-chip-list></div>
                                <input type="text" data-common-values placeholder="Shared values, comma to add" enterkeyhint="done">
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="hei-step" aria-labelledby="spi-step-barcodes">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="spi-step-barcodes">3</span>
                    <h2>Sellable Products</h2>
                </div>
                <div class="spi-assist-panel">
                    <div class="spi-assist-top">
                        <button type="button" class="hei-btn secondary" data-refresh-sku-names>Build names</button>
                        <button type="button" class="hei-btn secondary" data-suggest-sku-names>AI improve</button>
                        <button type="button" class="hei-btn secondary" data-apply-pending-sku-names hidden>Apply ready names</button>
                        <span class="spi-name-message" data-name-message></span>
                    </div>
                </div>

                <div class="spi-scan-bar" data-scan-bar hidden>
                    <div class="spi-scan-bar-info">
                        <span class="spi-scan-bar-label">Barcodes scanned</span>
                        <span class="spi-scan-bar-count">
                            <strong data-scan-done>0</strong>
                            <span class="spi-scan-bar-total">/ <span data-scan-total>0</span></span>
                        </span>
                    </div>
                    <div class="spi-scan-bar-progress" aria-hidden="true">
                        <span class="spi-scan-bar-progress-fill" data-scan-progress></span>
                    </div>
                    <button type="button" class="hei-btn primary spi-scan-bar-cta" data-scan-start>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="margin-right: 0.4rem; vertical-align: text-bottom;" aria-hidden="true">
                            <rect x="3" y="6" width="2" height="12"></rect>
                            <rect x="7" y="6" width="1" height="12"></rect>
                            <rect x="10" y="6" width="2" height="12"></rect>
                            <rect x="14" y="6" width="1" height="12"></rect>
                            <rect x="17" y="6" width="2" height="12"></rect>
                            <rect x="20" y="6" width="1" height="12"></rect>
                        </svg>
                        <span data-scan-start-label>Start scanning</span>
                    </button>
                </div>

                <div class="spi-sku-list" data-sku-list></div>
                <div class="spi-submit-row">
                    <span class="spi-submit-count" data-total-count>0 SKUs</span>
                    <span class="spi-inline-error" data-inline-error></span>
                    <button type="submit" class="hei-btn primary hei-btn-primary-wide" data-submit-btn>
                        @if ($editing)
                            Update intake
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display: inline-block; vertical-align: text-bottom; margin-left: 0.35rem;">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        @else
                            Save &amp; Next
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display: inline-block; vertical-align: text-bottom; margin-left: 0.35rem;">
                                <polyline points="9 18 15 12 9 6"></polyline>
                            </svg>
                        @endif
                    </button>
                </div>
            </section>

            <section class="hei-step" aria-labelledby="spi-step-extra">
                <details class="hei-mini-accordion" style="border-radius: 1.25rem; padding: 0; background: linear-gradient(135deg, rgba(255, 253, 248, 0.95) 0%, rgba(248, 246, 241, 0.92) 100%); border: 1.5px solid rgba(16, 36, 31, 0.1);">
                    <summary style="padding: 1rem 1.25rem; display: flex; align-items: center; gap: .75rem; font-size: .9375rem;">
                        <span class="hei-step-num" id="spi-step-extra" style="margin: 0;">4</span>
                        <span style="flex: 1; font-weight: 700; color: var(--hei-ink);">Extra (price, shelf, notes)</span>
                    </summary>
                    <div style="display: grid; gap: 1rem; padding: 0 1.25rem 1.25rem; border-top: 1px solid rgba(16, 36, 31, 0.08);">
                        <div class="spi-field-block" style="margin-top: 1rem;">
                            <div class="spi-field-title">Shelf ticket price</div>
                            <label class="hei-field">
                                <input type="number" step="0.01" min="0" name="shelf_ticket_price" value="{{ $val('shelf_ticket_price') }}" placeholder="0.00" inputmode="decimal">
                            </label>
                        </div>
                        <div class="spi-field-block">
                            <div class="spi-field-title">Shelf / location</div>
                            <label class="hei-field">
                                <input name="shelf_location" value="{{ $val('shelf_location') }}" placeholder="Optional shelf code">
                            </label>
                        </div>
                        <div class="spi-field-block">
                            <div class="spi-field-title">Notes</div>
                            <label class="hei-field">
                                <textarea name="visible_text_notes" placeholder="Anything else worth recording..." rows="3" data-notes>{{ $val('visible_text_notes') }}</textarea>
                            </label>
                        </div>
                    </div>
                </details>
            </section>
        </form>

        <div class="spi-modal-backdrop" data-add-modal hidden>
            <div class="spi-modal" role="dialog" aria-modal="true" aria-labelledby="spi-add-modal-title">
                <div class="spi-modal-head">
                    <h3 class="spi-modal-title" id="spi-add-modal-title" data-add-modal-title>Add option</h3>
                    <button type="button" class="spi-modal-close" data-close-add-modal aria-label="Close">x</button>
                </div>
                <input type="text" data-add-modal-input placeholder="Name">
                <div class="spi-mini-message" data-add-modal-message></div>
                <div class="spi-modal-actions">
                    <button type="button" class="hei-btn secondary" data-close-add-modal>Cancel</button>
                    <button type="button" class="hei-btn primary" data-save-add-modal>Save</button>
                </div>
            </div>
        </div>

        <script>
            (() => {
                const root = document.querySelector('[data-shop-intake-root]');
                if (!root) return;

                const draftKey = 'shop_product_intake_draft';
                const sessionKey = 'shop_product_intake_session';
                const countKey = 'shop_product_intake_saved_count';
                const lastSavedKey = 'shop_product_intake_last_saved_id';
                const sourceData = parseJson(root.dataset.sourceData || '{}', {});
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const hasOldInput = root.dataset.hasOld === '1';

                const form = root.querySelector('[data-shop-intake-form]');
                const brandSelect = root.querySelector('[data-brand-select]');
                const department = root.querySelector('[data-department]');
                const productType = root.querySelector('[data-product-type]');
                const familyName = root.querySelector('[data-family-name]');
                const sourceFamilyId = root.querySelector('[data-source-family-id]');
                const mainAxis = root.querySelector('[data-main-axis]');
                const subAxis = root.querySelector('[data-sub-axis-global]');
                const mainNameEnabled = root.querySelector('[data-main-name-enabled]');
                const subNameEnabled = root.querySelector('[data-sub-name-enabled]');
                const commonNameEnabled = root.querySelector('[data-common-name-enabled]');
                const variantContainer = root.querySelector('[data-variant-groups]');
                const commonVariant = root.querySelector('[data-common-variant]');
                const commonAxis = root.querySelector('[data-common-axis]');
                const skuList = root.querySelector('[data-sku-list]');
                const hiddenRows = root.querySelector('[data-variant-json]');
                const hiddenCommon = root.querySelector('[data-common-json]');
                const hiddenSkus = root.querySelector('[data-sku-json]');
                const totalCount = root.querySelector('[data-total-count]');
                const inlineError = root.querySelector('[data-inline-error]');
                const suggestions = root.querySelector('[data-source-suggestions]');
                const structureResults = root.querySelector('[data-structure-results]');
                const structureMessage = root.querySelector('[data-structure-message]');
                const nameMessage = root.querySelector('[data-name-message]');
                const applyPendingSkuNames = root.querySelector('[data-apply-pending-sku-names]');
                const quickMap = root.querySelector('[data-quick-map]');
                const notes = root.querySelector('[data-notes]');
                const addModal = root.querySelector('[data-add-modal]');
                const addModalTitle = root.querySelector('[data-add-modal-title]');
                const addModalInput = root.querySelector('[data-add-modal-input]');
                const addModalMessage = root.querySelector('[data-add-modal-message]');
                const saveAddModal = root.querySelector('[data-save-add-modal]');
                const keepBrand = root.querySelector('[data-keep-brand]');
                const keepDepartment = root.querySelector('[data-keep-department]');
                const keepType = root.querySelector('[data-keep-type]');
                const sessionCount = root.querySelector('[data-session-count]');
                const defaultSubAxis = 'Colour';
                let activeAddType = null;
                let latestStructureSuggestions = [];
                let scanAndGoRef = null;
                let structureRequestSeq = 0;
                let skuNameRequestSeq = 0;
                let pendingSkuNameResult = null;

                const addMessage = (type, message, isError = false) => {
                    const target = root.querySelector(`[data-add-message="${type}"]`);
                    if (!target) return;
                    target.textContent = message;
                    target.style.color = isError ? 'var(--hei-danger)' : 'var(--hei-muted)';
                    clearTimeout(target._timer);
                    target._timer = setTimeout(() => { target.textContent = ''; }, 2600);
                };

                const modalMessage = (message, isError = false) => {
                    if (!addModalMessage) return;
                    addModalMessage.textContent = message;
                    addModalMessage.style.color = isError ? 'var(--hei-danger)' : 'var(--hei-muted)';
                };

                const setStructureMessage = (message, isError = false) => {
                    if (!structureMessage) return;
                    structureMessage.textContent = message;
                    structureMessage.style.color = isError ? 'var(--hei-danger)' : 'var(--hei-muted)';
                };

                const setNameMessage = (message, isError = false) => {
                    if (!nameMessage) return;
                    nameMessage.textContent = message;
                    nameMessage.style.color = isError ? 'var(--hei-danger)' : 'var(--hei-muted)';
                };

                if (root.dataset.clearDraft === '1') localStorage.removeItem(draftKey);
                if (root.dataset.savedIntakeId && sessionStorage.getItem(lastSavedKey) !== root.dataset.savedIntakeId) {
                    sessionStorage.setItem(countKey, String((Number.parseInt(sessionStorage.getItem(countKey) || '0', 10) || 0) + 1));
                    sessionStorage.setItem(lastSavedKey, root.dataset.savedIntakeId);
                }
                if (sessionCount) sessionCount.textContent = sessionStorage.getItem(countKey) || '0';

                function parseJson(value, fallback = null) {
                    try { return JSON.parse(value); } catch (error) { return fallback; }
                }

                const escapeHtml = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
                const clean = (value) => String(value || '').trim().replace(/\s+/g, ' ');
                const key = (value) => clean(value).toLocaleLowerCase();
                const slug = (value) => clean(value).toLocaleLowerCase().replaceAll('.', '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
                const cards = () => Array.from(variantContainer.querySelectorAll('[data-variant-row]'));
                const comboKey = (mainValue, subValue = '', common = '') => slug(`${mainValue}|${subValue}|${common}`);
                const splitValues = (value) => String(value || '').split(/[,;]+/).map(clean).filter(Boolean).filter((item, index, list) => list.findIndex((candidate) => key(candidate) === key(item)) === index);
                const valuesForCard = (card) => Array.from(card.querySelectorAll('[data-chip]')).map((chip) => clean(chip.dataset.chip || '')).filter(Boolean);
                const commonPayload = () => {
                    const values = commonVariant ? valuesForCard(commonVariant) : [];
                    const axis = clean(commonAxis?.value || '');
                    if (values.length === 0) return [];

                    return [{ name: axis || 'Common', values }];
                };
                const commonLabel = () => commonPayload().flatMap((group) => {
                    const values = Array.isArray(group.values) ? group.values : [];

                    return values.map((value) => values.length === 1 ? value : `${group.name}: ${value}`);
                }).filter(Boolean).join(' / ');
                const displayAxis = (axis, fallback = 'Variant') => clean(axis || '') || fallback;
                const variantLabel = (label) => {
                    const cleaned = clean(label);
                    return cleaned && key(cleaned) !== 'standard' ? cleaned : '';
                };
                const sellableBaseName = () => {
                    const brand = clean(brandSelect.value);
                    const family = clean(familyName.value);
                    if (!family) return brand;
                    if (brand && key(family).startsWith(key(brand))) return family;

                    return [brand, family].filter(Boolean).join(' ');
                };
                const skuNameLabel = (mainValue, subValue, sharedLabel = '') => [
                    (mainNameEnabled?.checked ?? true) ? mainValue : '',
                    (subNameEnabled?.checked ?? true) ? subValue : '',
                    (commonNameEnabled?.checked ?? false) ? sharedLabel : '',
                ].map(variantLabel).filter(Boolean).join(' / ');
                const suggestedSellableName = (label) => [sellableBaseName(), variantLabel(label)].filter(Boolean).join(' ');
                const updateAxisLabels = () => {
                    root.querySelectorAll('[data-main-axis-label]').forEach((target) => {
                        target.textContent = `${displayAxis(mainAxis.value, 'Main')} value`;
                    });
                    root.querySelectorAll('[data-sub-axis-label]').forEach((target) => {
                        target.textContent = `${displayAxis(subAxis?.value, defaultSubAxis)} values`;
                    });
                    root.querySelectorAll('[data-values]').forEach((input) => {
                        input.placeholder = `${displayAxis(subAxis?.value, defaultSubAxis)} values, comma to add`;
                    });
                };

                const postJson = async (url, payload) => {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify(payload),
                    });

                    const result = await response.json().catch(() => ({}));
                    if (!response.ok || !result.ok) {
                        const message = result.message || Object.values(result.errors || {})?.[0]?.[0] || 'Could not save.';
                        throw new Error(message);
                    }

                    return result;
                };

                const ensureSelectOption = (select, value, label = value) => {
                    if (!value) return;
                    const exists = Array.from(select.options).some((option) => key(option.value) === key(value));
                    if (!exists) {
                        const option = document.createElement('option');
                        option.value = value;
                        option.textContent = label;
                        select.appendChild(option);
                    }
                    select.value = value;
                };

                const ensureDatalistOption = (listId, value) => {
                    if (!value) return;
                    const list = document.getElementById(listId);
                    if (!list) return;
                    const exists = Array.from(list.options).some((option) => key(option.value) === key(value));
                    if (!exists) {
                        const option = document.createElement('option');
                        option.value = value;
                        list.appendChild(option);
                    }
                };

                const openAddModal = (type) => {
                    activeAddType = type;
                    const labels = {
                        brand: 'Add Brand',
                        department: 'Add Department',
                        product_type: 'Add Product Type',
                    };
                    addModalTitle.textContent = labels[type] || 'Add Option';
                    addModalInput.value = '';
                    modalMessage('');
                    addModal.hidden = false;
                    setTimeout(() => addModalInput.focus(), 0);
                };

                const closeAddModal = () => {
                    addModal.hidden = true;
                    activeAddType = null;
                    modalMessage('');
                };

                const addBrand = async (rawName) => {
                    const name = clean(rawName || '');
                    if (!name) {
                        modalMessage('Enter brand name.', true);
                        addModalInput?.focus();
                        return;
                    }

                    try {
                        const result = await postJson(root.dataset.quickBrandUrl, { name });
                        ensureSelectOption(brandSelect, result.option.name, result.option.label || result.option.name);
                        sourceFamilyId.value = '';
                        renderSuggestions();
                        saveDraft();
                        saveSessionPrefs();
                        closeAddModal();
                        addMessage('brand', 'Brand added.');
                    } catch (error) {
                        modalMessage(error.message, true);
                    }
                };

                const addOption = async (type, rawName) => {
                    const target = type === 'department' ? department : productType;
                    const listId = type === 'department' ? 'spi-department-options' : 'spi-product-type-options';
                    const name = clean(rawName || '');

                    if (!name) {
                        modalMessage('Enter value.', true);
                        addModalInput?.focus();
                        return;
                    }

                    try {
                        const result = await postJson(root.dataset.quickOptionUrl, { type, name });
                        ensureDatalistOption(listId, result.option.name);
                        target.value = result.option.name;
                        sourceFamilyId.value = '';
                        renderSuggestions();
                        saveDraft();
                        saveSessionPrefs();
                        closeAddModal();
                        addMessage(type, 'Added to list.');
                    } catch (error) {
                        modalMessage(error.message, true);
                    }
                };

                const saveModalValue = () => {
                    if (activeAddType === 'brand') {
                        addBrand(addModalInput.value);
                        return;
                    }

                    if (activeAddType === 'department' || activeAddType === 'product_type') {
                        addOption(activeAddType, addModalInput.value);
                    }
                };

                const structureSourceLabel = (suggestion) => {
                    if ((suggestion.source || '') === 'openrouter') return suggestion.model || 'Gemini';
                    if ((suggestion.source || '') === 'local_catalogue') return 'Catalogue';
                    if ((suggestion.source || '') === 'pdf_catalogue') return 'PDF/Sherrys';
                    return 'Review';
                };

                const uniqueStructureSuggestions = (payload) => {
                    const rows = [payload.primary, ...(payload.local_suggestions || [])]
                        .filter((row) => row && typeof row === 'object');
                    const seen = new Set();

                    return rows.filter((row) => {
                        const signature = key(`${row.department_name || ''}|${row.product_type_name || ''}|${row.matched_family_name || ''}|${row.source || ''}`);
                        if (seen.has(signature)) return false;
                        seen.add(signature);
                        return true;
                    }).slice(0, 3);
                };

                const renderStructureSuggestions = (payload) => {
                    latestStructureSuggestions = uniqueStructureSuggestions(payload);
                    structureResults.innerHTML = '';
                    structureResults.hidden = latestStructureSuggestions.length === 0;

                    latestStructureSuggestions.forEach((suggestion, index) => {
                        const canApply = clean(suggestion.department_name || '') || clean(suggestion.product_type_name || '');
                        const card = document.createElement('div');
                        card.className = 'spi-structure-card';
                        card.innerHTML = `
                            <div class="spi-structure-head"><span>${escapeHtml(structureSourceLabel(suggestion))} - ${escapeHtml(suggestion.confidence || 'D')}</span>${canApply ? `<button type="button" class="hei-btn secondary" data-apply-structure="${index}">Use</button>` : ''}</div>
                            <div class="spi-structure-values">
                                ${suggestion.department_name ? `<span>${escapeHtml(suggestion.department_name)}</span>` : ''}
                                ${suggestion.product_type_name ? `<span>${escapeHtml(suggestion.product_type_name)}</span>` : ''}
                            </div>
                            ${suggestion.matched_family_name ? `<div class="spi-structure-reason">${escapeHtml(suggestion.matched_brand_name || '')} ${escapeHtml(suggestion.matched_family_name || '')}</div>` : ''}
                            ${suggestion.reason ? `<div class="spi-structure-reason">${escapeHtml(suggestion.reason)}</div>` : ''}
                        `;
                        structureResults.appendChild(card);
                    });
                };

                const applyStructureSuggestion = (suggestion) => {
                    if (!suggestion) return;
                    if (suggestion.department_name) {
                        ensureDatalistOption('spi-department-options', suggestion.department_name);
                        department.value = suggestion.department_name;
                    }
                    if (suggestion.product_type_name) {
                        ensureDatalistOption('spi-product-type-options', suggestion.product_type_name);
                        productType.value = suggestion.product_type_name;
                    }
                    sourceFamilyId.value = suggestion.matched_family_id || sourceFamilyId.value || '';
                    saveDraft();
                    saveSessionPrefs();
                    renderSuggestions();
                    setStructureMessage('Applied.');
                };

                const structureSignature = () => JSON.stringify({
                    brand_name: clean(brandSelect.value),
                    family_name: clean(familyName.value),
                    current_department_name: clean(department.value),
                    current_product_type_name: clean(productType.value),
                });

                const suggestStructure = () => {
                    const brandName = clean(brandSelect.value);
                    const family = clean(familyName.value);

                    if (!brandName) {
                        setStructureMessage('Choose brand first.', true);
                        brandSelect.focus();
                        return;
                    }

                    if (!family) {
                        setStructureMessage('Enter product name first.', true);
                        familyName.focus();
                        return;
                    }

                    const requestId = ++structureRequestSeq;
                    const signature = structureSignature();
                    latestStructureSuggestions = [];
                    setStructureMessage('AI check in background. Continue working.');
                    structureResults.hidden = true;
                    structureResults.innerHTML = '';

                    postJson(root.dataset.structureSuggestUrl, {
                        brand_name: brandName,
                        family_name: family,
                        current_department_name: department.value,
                        current_product_type_name: productType.value,
                    }).then((result) => {
                        if (requestId !== structureRequestSeq) return;
                        if (structureSignature() !== signature) {
                            setStructureMessage('AI result was for an old product, so it was not shown.');
                            return;
                        }

                        renderStructureSuggestions(result);

                        const primary = result.primary || {};
                        const suffix = result.ai_used ? 'AI checked.' : (result.ai_available ? 'Catalogue match.' : 'Local only.');
                        setStructureMessage(primary.confidence ? `${primary.confidence} - ${suffix}` : suffix, false);
                    }).catch((error) => {
                        if (requestId !== structureRequestSeq) return;
                        setStructureMessage(error.message || 'Could not suggest.', true);
                    });
                };

                const skuPayload = () => Array.from(skuList.querySelectorAll('[data-sku-row]')).map((row) => ({
                    key: row.dataset.skuKey || '',
                    label: row.dataset.skuLabel || '',
                    name_label: row.dataset.skuNameLabel || '',
                    suggested_name: clean(row.querySelector('[data-suggested-name]')?.value || ''),
                    barcode: clean(row.querySelector('[data-barcode]')?.value || ''),
                }));

                const skuNameSignature = (includeNames = true) => JSON.stringify({
                    brand_name: clean(brandSelect.value),
                    department_name: clean(department.value),
                    product_type_name: clean(productType.value),
                    family_name: clean(familyName.value),
                    variant_main_axis: clean(mainAxis.value),
                    variant_sub_axis: clean(subAxis?.value || ''),
                    include_main_in_name: mainNameEnabled?.checked ?? true,
                    include_sub_in_name: subNameEnabled?.checked ?? true,
                    include_common_in_name: commonNameEnabled?.checked ?? false,
                    common_variants: commonPayload(),
                    sku_rows: skuPayload().map((row) => ({
                        key: row.key,
                        label: row.label,
                        name_label: row.name_label,
                        suggested_name: includeNames ? row.suggested_name : '',
                    })),
                });

                const setPendingSkuNamesButton = (visible) => {
                    if (!applyPendingSkuNames) return;
                    applyPendingSkuNames.hidden = !visible;
                };

                const addChip = (card, value) => {
                    const normalized = clean(value);
                    if (!normalized || valuesForCard(card).some((existing) => key(existing) === key(normalized))) return false;
                    const chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'hei-variant-chip';
                    chip.dataset.chip = normalized;
                    chip.innerHTML = `<span>${escapeHtml(normalized)}</span><strong aria-hidden="true">x</strong>`;
                    card.querySelector('[data-chip-list]').appendChild(chip);
                    return true;
                };

                const rowPayload = (card) => ({
                    main_value: clean(card.querySelector('[data-main-value]')?.value || ''),
                    sub_axis: displayAxis(subAxis?.value, defaultSubAxis),
                    sub_values: valuesForCard(card),
                    notes: null,
                });

                const saveDraft = () => {
                    localStorage.setItem(draftKey, JSON.stringify({
                        brand_name: brandSelect.value,
                        department_name: department.value,
                        product_type_name: productType.value,
                        family_name: familyName.value,
                        source_product_family_id: sourceFamilyId.value,
                        variant_main_axis: mainAxis.value,
                        variant_sub_axis: subAxis?.value || '',
                        include_main_in_name: mainNameEnabled?.checked ?? true,
                        include_sub_in_name: subNameEnabled?.checked ?? true,
                        include_common_in_name: commonNameEnabled?.checked ?? false,
                        rows: cards().map(rowPayload),
                        common_variants: commonPayload(),
                        sku_rows: skuPayload(),
                        visible_text_notes: notes?.value || '',
                    }));
                };

                const saveSessionPrefs = () => {
                    localStorage.setItem(sessionKey, JSON.stringify({
                        keep_brand: keepBrand.checked,
                        keep_department: keepDepartment.checked,
                        keep_type: keepType.checked,
                        brand_name: keepBrand.checked ? brandSelect.value : '',
                        department_name: keepDepartment.checked ? department.value : '',
                        product_type_name: keepType.checked ? productType.value : '',
                    }));
                };

                const buildSkuRows = (rows, existing = {}) => {
                    const previous = { ...Object.fromEntries(skuPayload().map((row) => [row.key, row])), ...existing };
                    const sharedLabel = commonLabel();
                    const combos = rows.flatMap((row) => {
                        const mainValue = clean(row.main_value) || 'Standard';
                        const subValues = Array.isArray(row.sub_values) ? row.sub_values : [];
                        if (subValues.length === 0) {
                            const label = [mainValue, sharedLabel].filter(Boolean).join(' / ');
                            return [{ key: comboKey(mainValue, '', sharedLabel), label, nameLabel: skuNameLabel(mainValue, '', sharedLabel) }];
                        }

                        return subValues.map((subValue) => {
                            const label = [mainValue, subValue, sharedLabel].filter(Boolean).join(' / ');
                            const nameLabel = skuNameLabel(mainValue, subValue, sharedLabel);
                            return { key: comboKey(mainValue, subValue, sharedLabel), label, nameLabel };
                        });
                    });

                    skuList.innerHTML = '';
                    combos.forEach((combo, index) => {
                        const row = document.createElement('div');
                        row.className = 'spi-sku-row';
                        row.dataset.skuRow = '1';
                        row.dataset.skuKey = combo.key;
                        row.dataset.skuLabel = combo.label;
                        row.dataset.skuNameLabel = combo.nameLabel || combo.label;
                        const currentName = previous[combo.key]?.suggested_name || suggestedSellableName(combo.nameLabel || combo.label);
                        const hasBarcode = !!(previous[combo.key]?.barcode || '').trim();
                        if (hasBarcode) row.classList.add('is-scanned');
                        const variantParts = String(combo.label || 'Standard').split(/\s*\/\s*/).filter(Boolean);
                        const variantPills = variantParts.length
                            ? variantParts.map((part, i) => `<span class="spi-sku-variant-pill${i === 0 ? ' is-primary' : ''}">${escapeHtml(part)}</span>`).join('')
                            : `<span class="spi-sku-variant-pill is-primary">Standard</span>`;
                        row.innerHTML = `
                            <span class="spi-sku-status" data-sku-status>${hasBarcode ? 'Scanned' : 'Pending'}</span>
                            <div class="spi-sku-headline">
                                <span class="spi-sku-index">SKU ${index + 1}</span>
                                <div class="spi-sku-variant-pills">${variantPills}</div>
                            </div>
                            <div class="spi-sku-label">
                                <strong>${escapeHtml(currentName || combo.label)}</strong>
                            </div>
                            <div class="spi-sku-fields">
                                <input type="text" data-suggested-name value="${escapeHtml(currentName)}" placeholder="Sellable product name">
                                <input type="text" inputmode="numeric" autocomplete="off" data-barcode value="${escapeHtml(previous[combo.key]?.barcode || '')}" placeholder="Scan barcode here">
                            </div>
                        `;
                        skuList.appendChild(row);
                    });

                    hiddenSkus.value = JSON.stringify(skuPayload());
                    totalCount.textContent = `${combos.length} SKU${combos.length === 1 ? '' : 's'}`;
                    scanAndGoRef?.refreshAll();
                };

                const sync = () => {
                    const rows = cards().map(rowPayload).filter((row) => row.main_value || row.sub_values.length);
                    updateAxisLabels();
                    hiddenRows.value = JSON.stringify(rows);
                    hiddenCommon.value = JSON.stringify(commonPayload());
                    buildSkuRows(rows);
                    saveDraft();
                    return rows;
                };

                const addRow = (row = { main_value: '', sub_axis: defaultSubAxis, sub_values: [] }) => {
                    const card = document.createElement('div');
                    card.className = 'hei-map-group-card hei-variant-card';
                    card.dataset.variantRow = '1';
                    card.innerHTML = `<div class="hei-map-card-toolbar"><div class="spi-row-title"><span data-main-axis-label>${escapeHtml(displayAxis(mainAxis.value, 'Main'))} value</span><span data-sub-axis-label>${escapeHtml(displayAxis(subAxis?.value, defaultSubAxis))} values</span></div><button type="button" class="hei-variant-remove-btn hei-icon-btn" data-remove-row aria-label="Remove" title="Remove">x</button></div><label class="hei-field"><input type="text" data-main-value value="${escapeHtml(row.main_value || '')}" placeholder="Main value e.g. 100ml" enterkeyhint="next"></label><div class="hei-field"><div class="hei-variant-value-box"><div class="hei-variant-chip-list" data-chip-list></div><input type="text" data-values placeholder="${escapeHtml(displayAxis(subAxis?.value, defaultSubAxis))} values, comma to add" enterkeyhint="done"></div></div>`;
                    variantContainer.appendChild(card);
                    (row.sub_values || []).forEach((value) => addChip(card, value));
                    sync();
                };

                const setRows = (rows, existingSkus = {}) => {
                    variantContainer.innerHTML = '';
                    const cleanRows = Array.isArray(rows) ? rows.filter((row) => row && typeof row === 'object') : [];
                    if (cleanRows.length === 0) {
                        addRow();
                        return;
                    }
                    const firstSubAxis = cleanRows.find((row) => clean(row.sub_axis || ''))?.sub_axis;
                    if (subAxis && firstSubAxis) subAxis.value = firstSubAxis;
                    cleanRows.forEach((row) => addRow({ main_value: row.main_value || '', sub_axis: row.sub_axis || defaultSubAxis, sub_values: Array.isArray(row.sub_values) ? row.sub_values : [] }));
                    hiddenRows.value = JSON.stringify(cleanRows);
                    buildSkuRows(cleanRows, existingSkus);
                };

                const setCommonVariants = (commonVariants = []) => {
                    if (!commonVariant) return;

                    commonAxis.value = '';
                    commonVariant.querySelector('[data-chip-list]').innerHTML = '';
                    const first = Array.isArray(commonVariants) ? commonVariants.find((group) => group && typeof group === 'object') : null;
                    if (first) {
                        commonAxis.value = first.name || '';
                        (Array.isArray(first.values) ? first.values : []).forEach((value) => addChip(commonVariant, value));
                    }
                    hiddenCommon.value = JSON.stringify(commonPayload());
                };

                const consumeValueInput = (input, force = false) => {
                    const card = input.closest('[data-variant-row], [data-common-variant]');
                    if (!card) return;
                    const raw = input.value || '';
                    if (!force && !raw.includes(',')) return;
                    const parts = force ? splitValues(raw) : raw.split(',').slice(0, -1);
                    const remainder = force ? '' : raw.split(',').slice(-1)[0] || '';
                    let added = false;
                    parts.map(clean).filter(Boolean).forEach((part) => { added = addChip(card, part) || added; });
                    input.value = remainder.trimStart();
                    if (added) sync();
                };

                const consumePendingValues = () => {
                    root.querySelectorAll('[data-values], [data-common-values]').forEach((input) => consumeValueInput(input, true));
                };

                const refreshSkuNames = () => {
                    skuList.querySelectorAll('[data-sku-row]').forEach((row) => {
                        const label = row.dataset.skuNameLabel || row.dataset.skuLabel || '';
                        const name = suggestedSellableName(label);
                        const nameInput = row.querySelector('[data-suggested-name]');
                        const title = row.querySelector('.spi-sku-label strong');
                        if (nameInput) nameInput.value = name;
                        if (title) title.textContent = name || label || 'Sellable product';
                    });
                    hiddenSkus.value = JSON.stringify(skuPayload());
                    saveDraft();
                };

                const applySkuNameSuggestions = (names = []) => {
                    const rowsByKey = Object.fromEntries(Array.from(skuList.querySelectorAll('[data-sku-row]')).map((row) => [row.dataset.skuKey || '', row]));
                    names.forEach((suggestion) => {
                        const row = rowsByKey[suggestion.key || ''];
                        const name = clean(suggestion.suggested_name || '');
                        if (!row || !name) return;
                        const nameInput = row.querySelector('[data-suggested-name]');
                        const title = row.querySelector('.spi-sku-label strong');
                        if (nameInput) nameInput.value = name;
                        if (title) title.textContent = name;
                    });
                    hiddenSkus.value = JSON.stringify(skuPayload());
                    saveDraft();
                };

                const suggestSkuNames = () => {
                    const rows = sync();
                    const skus = skuPayload();

                    if (!brandSelect.value) {
                        setNameMessage('Choose brand first.', true);
                        brandSelect.focus();
                        return;
                    }

                    if (!familyName.value.trim()) {
                        setNameMessage('Enter product name first.', true);
                        familyName.focus();
                        return;
                    }

                    if (skus.length === 0 || rows.length === 0) {
                        setNameMessage('Build variants first.', true);
                        return;
                    }

                    const requestId = ++skuNameRequestSeq;
                    const fullSignature = skuNameSignature(true);
                    const keySignature = skuNameSignature(false);
                    pendingSkuNameResult = null;
                    setPendingSkuNamesButton(false);
                    setNameMessage('AI naming in background. Continue working.');

                    postJson(root.dataset.skuNameSuggestUrl, {
                        brand_name: brandSelect.value,
                        department_name: department.value,
                        product_type_name: productType.value,
                        family_name: familyName.value,
                        variant_main_axis: mainAxis.value,
                        variant_sub_axis: subAxis?.value || '',
                        include_main_in_name: mainNameEnabled?.checked ?? true,
                        include_sub_in_name: subNameEnabled?.checked ?? true,
                        include_common_in_name: commonNameEnabled?.checked ?? false,
                        variant_rows: rows,
                        common_variants: commonPayload(),
                        sku_rows: skus,
                    }).then((result) => {
                        if (requestId !== skuNameRequestSeq) return;

                        const names = result.names || [];
                        if (skuNameSignature(true) === fullSignature) {
                            applySkuNameSuggestions(names);
                            setNameMessage(result.ai_used ? 'AI names applied.' : 'Names rebuilt.');
                            return;
                        }

                        if (skuNameSignature(false) === keySignature) {
                            pendingSkuNameResult = { names, keySignature };
                            setPendingSkuNamesButton(true);
                            setNameMessage('AI names ready. Tap Apply when ready.');
                            return;
                        }

                        setNameMessage('AI result was for an old variant map, so it was not applied.');
                    }).catch((error) => {
                        if (requestId !== skuNameRequestSeq) return;
                        setNameMessage(error.message || 'Could not suggest names.', true);
                    });
                };

                const suggestionsForBrand = () => sourceData[key(brandSelect.value)] || [];

                const sourceWrap = root.querySelector('[data-source-suggest]');
                const sourceCount = root.querySelector('[data-source-suggest-count]');
                const sourceEmpty = root.querySelector('[data-source-suggest-empty]');

                /* Source priority: Janson first, then Mamado, then PDF, anything else last. */
                const SOURCE_PRIORITY = { janson: 0, mamado: 1, pdf: 2 };
                const SOURCE_LABELS = { janson: 'Janson', mamado: 'Mamado', pdf: 'PDF' };

                const parseSourceTokens = (raw) => {
                    if (!raw) return [];
                    return String(raw)
                        .split(/[,/|]+/)
                        .map((token) => clean(token).toLocaleLowerCase())
                        .filter(Boolean);
                };

                const sourceTokenForPill = (token) => {
                    if (token.includes('janson')) return 'janson';
                    if (token.includes('mamado')) return 'mamado';
                    if (token.includes('pdf')) return 'pdf';
                    return 'other';
                };

                const sourceRank = (item) => {
                    const tokens = parseSourceTokens(item.source_types);
                    if (tokens.length === 0) return 99;
                    return Math.min(...tokens.map((token) => {
                        const pill = sourceTokenForPill(token);
                        return SOURCE_PRIORITY[pill] ?? 90;
                    }));
                };

                const escapeRegex = (value) => String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

                const highlightQuery = (label, query) => {
                    const safe = escapeHtml(label || '');
                    if (!query) return safe;
                    const pattern = new RegExp(escapeRegex(escapeHtml(query)), 'ig');
                    return safe.replace(pattern, (match) => `<mark class="spi-source-card-mark">${match}</mark>`);
                };

                const sourcePillsHtml = (rawSourceTypes) => {
                    const tokens = parseSourceTokens(rawSourceTypes);
                    if (tokens.length === 0) return '';

                    const seen = new Set();
                    const pills = [];
                    tokens.forEach((token) => {
                        const pill = sourceTokenForPill(token);
                        if (seen.has(pill)) return;
                        seen.add(pill);
                        pills.push({ pill, label: SOURCE_LABELS[pill] || token.toUpperCase() });
                    });

                    pills.sort((a, b) => (SOURCE_PRIORITY[a.pill] ?? 90) - (SOURCE_PRIORITY[b.pill] ?? 90));

                    return pills
                        .map((p) => `<span class="spi-source-pill" data-pill="${p.pill}">${escapeHtml(p.label)}</span>`)
                        .join('');
                };

                const renderSuggestions = () => {
                    const query = clean(familyName.value);
                    const queryKey = key(query);
                    const allBrandRows = suggestionsForBrand();

                    if (!brandSelect.value || allBrandRows.length === 0) {
                        if (sourceWrap) sourceWrap.hidden = true;
                        suggestions.hidden = true;
                        return;
                    }

                    const filtered = (queryKey
                        ? allBrandRows.filter((item) => {
                            return key(item.family_name).includes(queryKey)
                                || key(item.product_type_name || '').includes(queryKey)
                                || key(item.department_name || '').includes(queryKey);
                        })
                        : allBrandRows);

                    /* Sort: source priority (Janson first), then prefix match, then alphabetical. */
                    filtered.sort((a, b) => {
                        const rankDiff = sourceRank(a) - sourceRank(b);
                        if (rankDiff !== 0) return rankDiff;

                        if (queryKey) {
                            const aStarts = key(a.family_name).startsWith(queryKey) ? 0 : 1;
                            const bStarts = key(b.family_name).startsWith(queryKey) ? 0 : 1;
                            if (aStarts !== bStarts) return aStarts - bStarts;
                        }

                        return String(a.family_name || '').localeCompare(String(b.family_name || ''));
                    });

                    const limit = queryKey ? 10 : 5;
                    const visible = filtered.slice(0, limit);
                    const selectedId = sourceFamilyId.value;

                    suggestions.innerHTML = '';
                    visible.forEach((item) => {
                        const meta = [item.department_name, item.product_type_name].filter(Boolean).join(' · ');
                        const isApplied = String(item.id || '') === String(selectedId);
                        const card = document.createElement('button');
                        card.type = 'button';
                        card.className = 'spi-source-card' + (isApplied ? ' is-applied' : '');
                        card.dataset.suggestionId = item.id;
                        card.innerHTML = `
                            <span class="spi-source-card-body">
                                <span class="spi-source-card-name">${highlightQuery(item.family_name, query)}</span>
                                <span class="spi-source-card-meta">
                                    <span class="spi-source-card-pills">${sourcePillsHtml(item.source_types)}</span>
                                    ${meta ? `<span class="spi-source-card-meta-text">${escapeHtml(meta)}</span>` : ''}
                                </span>
                            </span>
                            <span class="spi-source-card-cta">${isApplied ? 'Applied' : 'Use'}</span>
                        `;
                        suggestions.appendChild(card);
                    });

                    const totalMatches = filtered.length;
                    if (sourceCount) {
                        if (totalMatches === 0) {
                            sourceCount.textContent = '';
                        } else if (totalMatches > visible.length) {
                            sourceCount.textContent = `Showing ${visible.length} of ${totalMatches}`;
                        } else {
                            sourceCount.textContent = `${totalMatches} match${totalMatches === 1 ? '' : 'es'}`;
                        }
                    }

                    if (sourceEmpty) sourceEmpty.hidden = totalMatches !== 0;
                    suggestions.hidden = visible.length === 0;
                    if (sourceWrap) sourceWrap.hidden = false;
                };

                const applySuggestion = (id) => {
                    const item = suggestionsForBrand().find((candidate) => String(candidate.id) === String(id));
                    if (!item) return;
                    sourceFamilyId.value = item.id || '';
                    familyName.value = item.family_name || familyName.value;
                    department.value = item.department_name || department.value;
                    productType.value = item.product_type_name || productType.value;
                    mainAxis.value = item.main_axis || mainAxis.value || 'Size';
                    if (Array.isArray(item.variant_rows) && item.variant_rows.length && subAxis) {
                        subAxis.value = item.variant_rows.find((row) => clean(row.sub_axis || ''))?.sub_axis || subAxis.value || defaultSubAxis;
                    }
                    setCommonVariants(item.common_variants || []);
                    if (Array.isArray(item.variant_rows) && item.variant_rows.length) setRows(item.variant_rows);
                    saveDraft();
                    renderSuggestions();
                };

                const parseQuickMap = (raw) => String(raw || '').replaceAll('\r', '\n').split(/\n|\/+/).map(clean).filter(Boolean).map((line) => {
                    const explicit = line.match(/^(.+?)\s*[:=]\s*(.+)$/);
                    const loose = line.match(/^(.+?)\s+(.+[,;].+)$/);
                    let mainValue = line;
                    let values = '';
                    if (explicit) { mainValue = explicit[1]; values = explicit[2]; } else if (loose) { mainValue = loose[1]; values = loose[2]; }
                    return { main_value: clean(mainValue), sub_axis: displayAxis(subAxis?.value, defaultSubAxis), sub_values: splitValues(values) };
                });

                const restore = () => {
                    const oldRows = parseJson(root.dataset.oldRows || '[]', []);
                    const oldCommon = parseJson(root.dataset.oldCommonVariants || '[]', []);
                    const oldSkus = parseJson(root.dataset.oldSkus || '[]', []);
                    const draft = parseJson(localStorage.getItem(draftKey) || 'null', null);
                    const session = parseJson(localStorage.getItem(sessionKey) || 'null', null);
                    const skuByKey = Object.fromEntries((oldSkus || []).map((row) => [row.key, row]));

                    if (!hasOldInput && draft) {
                        brandSelect.value = draft.brand_name || '';
                        department.value = draft.department_name || '';
                        productType.value = draft.product_type_name || '';
                        familyName.value = draft.family_name || '';
                        sourceFamilyId.value = draft.source_product_family_id || '';
                        mainAxis.value = draft.variant_main_axis || 'Size';
                        if (subAxis) subAxis.value = draft.variant_sub_axis || draft.rows?.find((row) => clean(row.sub_axis || ''))?.sub_axis || defaultSubAxis;
                        if (mainNameEnabled) mainNameEnabled.checked = draft.include_main_in_name ?? true;
                        if (subNameEnabled) subNameEnabled.checked = draft.include_sub_in_name ?? true;
                        if (commonNameEnabled) commonNameEnabled.checked = draft.include_common_in_name ?? false;
                        if (notes) notes.value = draft.visible_text_notes || '';
                        setCommonVariants(draft.common_variants || []);
                        setRows(draft.rows || [], Object.fromEntries((draft.sku_rows || []).map((row) => [row.key, row])));
                        renderSuggestions();
                        return;
                    }

                    if (!hasOldInput && session) {
                        keepBrand.checked = session.keep_brand ?? true;
                        keepDepartment.checked = session.keep_department ?? true;
                        keepType.checked = session.keep_type ?? true;
                        brandSelect.value = session.brand_name || '';
                        department.value = session.department_name || '';
                        productType.value = session.product_type_name || '';
                    }

                    if (subAxis && oldRows?.length) {
                        subAxis.value = oldRows.find((row) => clean(row.sub_axis || ''))?.sub_axis || subAxis.value || defaultSubAxis;
                    }
                    setCommonVariants(oldCommon);
                    setRows(oldRows, skuByKey);
                    renderSuggestions();
                };

                root.addEventListener('click', (event) => {
                    const chip = event.target.closest('[data-chip]');
                    if (chip) { chip.remove(); sync(); return; }
                    const suggestion = event.target.closest('[data-suggestion-id]');
                    if (suggestion) { applySuggestion(suggestion.dataset.suggestionId); return; }
                    const departmentPreset = event.target.closest('[data-department-preset]');
                    if (departmentPreset) { department.value = departmentPreset.dataset.departmentPreset || ''; saveDraft(); saveSessionPrefs(); return; }
                    const typePreset = event.target.closest('[data-product-type-preset]');
                    if (typePreset) { productType.value = typePreset.dataset.productTypePreset || ''; saveDraft(); saveSessionPrefs(); renderSuggestions(); return; }
                    const axisPreset = event.target.closest('[data-main-axis-preset]');
                    if (axisPreset) { mainAxis.value = axisPreset.dataset.mainAxisPreset || ''; sync(); return; }
                    const subAxisPreset = event.target.closest('[data-sub-axis-preset]');
                    if (subAxisPreset) { subAxis.value = subAxisPreset.dataset.subAxisPreset || ''; sync(); return; }
                    const commonAxisPreset = event.target.closest('[data-common-axis-preset]');
                    if (commonAxisPreset) { commonAxis.value = commonAxisPreset.dataset.commonAxisPreset || ''; sync(); return; }
                    const applyStructure = event.target.closest('[data-apply-structure]');
                    if (applyStructure) { applyStructureSuggestion(latestStructureSuggestions[Number.parseInt(applyStructure.dataset.applyStructure || '0', 10)]); return; }
                    if (event.target.closest('[data-suggest-structure]')) { suggestStructure(); return; }
                    if (event.target.closest('[data-add-variant-row]')) { addRow({ main_value: '', sub_axis: displayAxis(subAxis?.value, defaultSubAxis), sub_values: [] }); cards().at(-1)?.querySelector('[data-main-value]')?.focus(); return; }
                    const removeButton = event.target.closest('[data-remove-row]');
                    if (removeButton) { if (cards().length <= 1) return; removeButton.closest('[data-variant-row]')?.remove(); sync(); return; }
                    if (event.target.closest('[data-quick-map-apply]')) { const parsed = parseQuickMap(quickMap?.value || ''); if (parsed.length) { setRows(parsed); quickMap.value = ''; } return; }
                    const openModalButton = event.target.closest('[data-open-add-modal]');
                    if (openModalButton) { openAddModal(openModalButton.dataset.openAddModal); return; }
                    if (event.target.closest('[data-close-add-modal]')) { closeAddModal(); return; }
                    if (event.target.closest('[data-save-add-modal]')) { saveModalValue(); return; }
                    if (event.target === addModal) { closeAddModal(); return; }
                    if (event.target.closest('[data-refresh-sku-names]')) { refreshSkuNames(); return; }
                    if (event.target.closest('[data-suggest-sku-names]')) { suggestSkuNames(); return; }
                    if (event.target.closest('[data-apply-pending-sku-names]')) {
                        if (!pendingSkuNameResult) { setPendingSkuNamesButton(false); return; }
                        if (skuNameSignature(false) !== pendingSkuNameResult.keySignature) {
                            pendingSkuNameResult = null;
                            setPendingSkuNamesButton(false);
                            setNameMessage('Variant map changed, so ready names were ignored.');
                            return;
                        }
                        applySkuNameSuggestions(pendingSkuNameResult.names || []);
                        pendingSkuNameResult = null;
                        setPendingSkuNamesButton(false);
                        setNameMessage('Ready names applied.');
                        return;
                    }
                    if (event.target.closest('[data-clear-form]')) { localStorage.removeItem(draftKey); form.reset(); sourceFamilyId.value = ''; quickMap.value = ''; setCommonVariants([]); setRows([]); renderSuggestions(); }
                });

                root.addEventListener('input', (event) => {
                    if (event.target.matches('[data-values], [data-common-values]')) { consumeValueInput(event.target, false); return; }
                    if (event.target.matches('[data-main-value], [data-sub-axis-global], [data-main-axis], [data-common-axis]')) { sync(); return; }
                    if (event.target.matches('[data-suggested-name]')) {
                        const row = event.target.closest('[data-sku-row]');
                        const title = row?.querySelector('.spi-sku-label strong');
                        if (title) title.textContent = clean(event.target.value) || row?.dataset.skuLabel || 'Sellable product';
                        hiddenSkus.value = JSON.stringify(skuPayload());
                        saveDraft();
                        return;
                    }
                    if (event.target.matches('[data-barcode]')) {
                        hiddenSkus.value = JSON.stringify(skuPayload());
                        saveDraft();
                        scanAndGoRef?.handleInput(event.target);
                        return;
                    }
                    if (event.target.matches('[data-family-name], [data-product-type]')) {
                        sourceFamilyId.value = '';
                        structureResults.hidden = true;
                        setStructureMessage('');
                        renderSuggestions();
                    }
                    saveDraft();
                    saveSessionPrefs();
                });

                root.addEventListener('change', (event) => {
                    if (event.target === brandSelect) {
                        sourceFamilyId.value = '';
                        structureResults.hidden = true;
                        setStructureMessage('');
                        renderSuggestions();
                    }
                    if (event.target.matches('[data-main-name-enabled], [data-sub-name-enabled], [data-common-name-enabled]')) {
                        sync();
                        refreshSkuNames();
                        return;
                    }
                    saveDraft();
                    saveSessionPrefs();
                });

                root.addEventListener('keydown', (event) => {
                    if (event.target.matches('[data-values], [data-common-values]') && (event.key === ',' || event.key === 'Enter')) { event.preventDefault(); consumeValueInput(event.target, true); return; }
                    if (event.target.matches('[data-add-modal-input]') && event.key === 'Enter') { event.preventDefault(); saveModalValue(); return; }
                    if (event.key === 'Escape' && !addModal.hidden) { event.preventDefault(); closeAddModal(); return; }
                    if (event.target.matches('[data-barcode]') && (event.key === 'Enter' || event.key === 'Tab')) {
                        if (scanAndGoRef) {
                            event.preventDefault();
                            scanAndGoRef.advanceFrom(event.target, { force: true });
                        }
                    }
                });

                form.addEventListener('submit', (event) => {
                    inlineError.textContent = '';
                    consumePendingValues();
                    const rows = sync();
                    hiddenCommon.value = JSON.stringify(commonPayload());
                    hiddenSkus.value = JSON.stringify(skuPayload());
                    if (!brandSelect.value) { event.preventDefault(); inlineError.textContent = 'Choose the brand.'; brandSelect.focus(); return; }
                    if (!familyName.value.trim()) { event.preventDefault(); inlineError.textContent = 'Enter the product name from the pack.'; familyName.focus(); return; }
                    if (rows.length === 0) { event.preventDefault(); inlineError.textContent = 'Add at least one sellable variant.'; cards()[0]?.querySelector('[data-main-value]')?.focus(); return; }
                    saveSessionPrefs();
                });

                restore();

                /* ---------------------------------------------------------
                   Scan-and-go workflow:
                   - Sticky progress bar showing N / total barcodes scanned
                   - Each row gets a status pill (Pending / Scanning / Scanned)
                   - After a barcode lands, focus auto-advances to next empty
                   - "Start scanning" button focuses the first empty barcode
                   - Enter or Tab keys also advance immediately
                --------------------------------------------------------- */
                scanAndGoRef = (function () {
                    const scanBar = root.querySelector('[data-scan-bar]');
                    const scanDone = root.querySelector('[data-scan-done]');
                    const scanTotal = root.querySelector('[data-scan-total]');
                    const scanProgress = root.querySelector('[data-scan-progress]');
                    const scanStartBtn = root.querySelector('[data-scan-start]');
                    const scanStartLabel = root.querySelector('[data-scan-start-label]');
                    const submitBtn = root.querySelector('[data-submit-btn]');

                    /* Threshold: typical retail barcodes are 8 (EAN-8) to 14 (GTIN-14).
                       6 is a safe lower bound to avoid premature advances on partial input. */
                    const MIN_BARCODE_LENGTH = 6;
                    /* Debounce time after the last scanner keystroke before we
                       consider the scan "complete". Hardware scanners burst at
                       <50ms between chars, so 250ms is comfortably above that
                       and below typical human typing pauses. */
                    const SCAN_SETTLE_MS = 250;

                    let settleTimer = null;
                    let lastTouched = null;

                    const allBarcodes = () => Array.from(root.querySelectorAll('[data-barcode]'));
                    const allRows = () => Array.from(root.querySelectorAll('[data-sku-row]'));

                    const updateRowState = (row) => {
                        if (!row) return;
                        const input = row.querySelector('[data-barcode]');
                        const status = row.querySelector('[data-sku-status]');
                        const filled = !!(input?.value || '').trim();
                        const focused = document.activeElement === input;

                        row.classList.toggle('is-scanned', filled && !focused);
                        row.classList.toggle('is-current', focused && !filled);
                        if (status) {
                            if (filled && !focused) status.textContent = 'Scanned';
                            else if (focused) status.textContent = 'Scanning';
                            else status.textContent = 'Pending';
                        }
                    };

                    const refreshAll = () => {
                        allRows().forEach(updateRowState);
                        const inputs = allBarcodes();
                        const total = inputs.length;
                        const done = inputs.filter((i) => (i.value || '').trim()).length;

                        if (!scanBar) return;
                        scanBar.hidden = total === 0;
                        if (total === 0) return;

                        if (scanDone) scanDone.textContent = String(done);
                        if (scanTotal) scanTotal.textContent = String(total);
                        if (scanProgress) {
                            scanProgress.style.width = `${Math.round((done / total) * 100)}%`;
                        }

                        const isComplete = done === total && total > 0;
                        scanBar.classList.toggle('is-complete', isComplete);
                        if (scanStartLabel) {
                            if (isComplete) scanStartLabel.textContent = 'All scanned · Save';
                            else if (done === 0) scanStartLabel.textContent = 'Start scanning';
                            else scanStartLabel.textContent = 'Continue scanning';
                        }
                    };

                    const focusInput = (input, { scroll = true } = {}) => {
                        if (!input) return;
                        input.focus({ preventScroll: true });
                        try { input.select(); } catch (e) { /* ignore */ }
                        if (scroll) {
                            const row = input.closest('[data-sku-row]');
                            row?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        refreshAll();
                    };

                    const nextEmptyAfter = (current) => {
                        const inputs = allBarcodes();
                        const startIndex = current ? inputs.indexOf(current) + 1 : 0;
                        for (let i = startIndex; i < inputs.length; i += 1) {
                            if (!(inputs[i].value || '').trim()) return inputs[i];
                        }
                        // Wrap around: catch any earlier empty rows the user skipped.
                        for (let i = 0; i < startIndex; i += 1) {
                            if (!(inputs[i].value || '').trim()) return inputs[i];
                        }
                        return null;
                    };

                    const flashRow = (row) => {
                        if (!row) return;
                        row.classList.remove('just-scanned');
                        // Force reflow so the animation restarts.
                        void row.offsetWidth;
                        row.classList.add('just-scanned');
                    };

                    const advanceFrom = (current, { force = false } = {}) => {
                        if (!current) return;
                        clearTimeout(settleTimer);
                        const value = (current.value || '').trim();
                        if (!force && value.length < MIN_BARCODE_LENGTH) {
                            refreshAll();
                            return;
                        }

                        const row = current.closest('[data-sku-row]');
                        if (value) {
                            flashRow(row);
                        }

                        const next = nextEmptyAfter(current);
                        if (next) {
                            focusInput(next);
                        } else {
                            current.blur();
                            refreshAll();
                            if (submitBtn) {
                                submitBtn.focus({ preventScroll: true });
                                submitBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        }
                    };

                    const handleInput = (input) => {
                        clearTimeout(settleTimer);
                        lastTouched = input;
                        refreshAll();
                        const value = (input.value || '').trim();
                        if (value.length < MIN_BARCODE_LENGTH) return;
                        settleTimer = setTimeout(() => {
                            // Only auto-advance if this input is still focused;
                            // if user has tabbed away manually, leave them alone.
                            if (document.activeElement !== input) return;
                            advanceFrom(input);
                        }, SCAN_SETTLE_MS);
                    };

                    const start = () => {
                        const target = nextEmptyAfter(null) || allBarcodes()[0];
                        if (target) focusInput(target);
                        else if (submitBtn) submitBtn.focus({ preventScroll: true });
                    };

                    /* Watch focus / blur on barcode inputs to keep the row state pill in sync. */
                    root.addEventListener('focusin', (event) => {
                        if (event.target.matches('[data-barcode]')) refreshAll();
                    });
                    root.addEventListener('focusout', (event) => {
                        if (event.target.matches('[data-barcode]')) {
                            // Slight delay so the next focus has time to land before we recompute.
                            setTimeout(refreshAll, 0);
                        }
                    });

                    /* Repaint when the SKU list rebuilds. */
                    if (typeof MutationObserver !== 'undefined' && skuList) {
                        new MutationObserver(refreshAll).observe(skuList, { childList: true, subtree: false });
                    }

                    scanStartBtn?.addEventListener('click', start);

                    refreshAll();

                    return { handleInput, advanceFrom, refreshAll, start };
                })();

                /* ---------------------------------------------------------
                   Brand combobox: searchable UI on top of the existing
                   <select name="brand_name" data-brand-select>. The select
                   stays in the DOM (visually hidden) so form submission,
                   the "Add brand" modal, and existing JS keep working.
                --------------------------------------------------------- */
                (function initBrandCombobox() {
                    const wrap = root.querySelector('[data-brand-combobox]');
                    if (!wrap || !brandSelect) return;

                    const input = wrap.querySelector('[data-brand-combobox-input]');
                    const list = wrap.querySelector('[data-brand-combobox-list]');
                    const empty = wrap.querySelector('[data-brand-combobox-empty]');
                    const clearBtn = wrap.querySelector('[data-brand-combobox-clear]');
                    let activeIndex = -1;
                    let pending = false;

                    const optionLabel = (opt) => {
                        const name = (opt.dataset.brandDisplay || opt.value || '').trim();
                        const count = Number.parseInt(opt.dataset.brandCount || '0', 10) || 0;
                        return { name, count };
                    };

                    const allBrands = () => Array.from(brandSelect.options)
                        .filter((opt) => opt.value)
                        .map((opt) => ({ value: opt.value, ...optionLabel(opt) }));

                    const findOption = (value) => Array.from(brandSelect.options)
                        .find((opt) => opt.value === value);

                    const syncDisplay = () => {
                        const opt = findOption(brandSelect.value || '');
                        if (opt && opt.value) {
                            const { name } = optionLabel(opt);
                            input.value = name;
                            clearBtn.hidden = false;
                        } else {
                            input.value = '';
                            clearBtn.hidden = true;
                        }
                    };

                    const escapeRegex = (value) => String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

                    const highlight = (label, query) => {
                        if (!query) return escapeHtml(label);
                        const safe = escapeHtml(label);
                        const pattern = new RegExp(escapeRegex(escapeHtml(query)), 'ig');
                        return safe.replace(pattern, (match) => `<mark class="spi-brand-combobox-option-mark">${match}</mark>`);
                    };

                    const renderList = (rawQuery = '') => {
                        const query = rawQuery.trim();
                        const lowered = query.toLocaleLowerCase();
                        const items = allBrands()
                            .filter((b) => !lowered || b.name.toLocaleLowerCase().includes(lowered))
                            .slice(0, 60);

                        list.innerHTML = '';
                        activeIndex = items.length ? 0 : -1;

                        if (items.length === 0) {
                            list.hidden = true;
                            empty.hidden = false;
                            input.setAttribute('aria-expanded', 'true');
                            return;
                        }

                        empty.hidden = true;
                        list.hidden = false;
                        input.setAttribute('aria-expanded', 'true');

                        items.forEach((item, idx) => {
                            const li = document.createElement('li');
                            li.className = 'spi-brand-combobox-option';
                            li.setAttribute('role', 'option');
                            li.dataset.value = item.value;
                            const isSelected = brandSelect.value === item.value;
                            if (isSelected) li.classList.add('is-selected');
                            if (idx === 0) li.classList.add('is-active');
                            li.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                            li.innerHTML = `
                                <span>${highlight(item.name, query)}</span>
                                ${item.count ? `<span class="spi-brand-combobox-option-count">${item.count}</span>` : ''}
                            `;
                            list.appendChild(li);
                        });
                    };

                    const openList = () => {
                        wrap.classList.add('is-open');
                        renderList(input.value);
                    };

                    const closeList = ({ restoreDisplay = true } = {}) => {
                        wrap.classList.remove('is-open');
                        list.hidden = true;
                        empty.hidden = true;
                        input.setAttribute('aria-expanded', 'false');
                        activeIndex = -1;
                        if (restoreDisplay) syncDisplay();
                    };

                    const selectValue = (value) => {
                        if (brandSelect.value === value) {
                            closeList();
                            return;
                        }
                        brandSelect.value = value;
                        brandSelect.dispatchEvent(new Event('change', { bubbles: true }));
                        closeList();
                    };

                    const moveActive = (direction) => {
                        const opts = list.querySelectorAll('.spi-brand-combobox-option');
                        if (opts.length === 0) return;
                        activeIndex = (activeIndex + direction + opts.length) % opts.length;
                        opts.forEach((o, i) => o.classList.toggle('is-active', i === activeIndex));
                        opts[activeIndex]?.scrollIntoView({ block: 'nearest' });
                    };

                    /* Wrap the select's value setter so any programmatic write
                       (restore(), ensureSelectOption, session restore) updates
                       the combobox display automatically. */
                    const proto = HTMLSelectElement.prototype;
                    const desc = Object.getOwnPropertyDescriptor(proto, 'value');
                    if (desc && desc.get && desc.set) {
                        Object.defineProperty(brandSelect, 'value', {
                            configurable: true,
                            get() { return desc.get.call(this); },
                            set(value) {
                                desc.set.call(this, value);
                                if (!pending) {
                                    pending = true;
                                    queueMicrotask(() => {
                                        pending = false;
                                        syncDisplay();
                                    });
                                }
                            },
                        });
                    }

                    input.addEventListener('focus', openList);
                    input.addEventListener('click', openList);
                    input.addEventListener('input', () => {
                        wrap.classList.add('is-open');
                        clearBtn.hidden = !input.value;
                        renderList(input.value);
                    });

                    input.addEventListener('keydown', (event) => {
                        if (event.key === 'ArrowDown') {
                            event.preventDefault();
                            if (list.hidden) openList();
                            else moveActive(1);
                            return;
                        }
                        if (event.key === 'ArrowUp') {
                            event.preventDefault();
                            moveActive(-1);
                            return;
                        }
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            const opts = list.querySelectorAll('.spi-brand-combobox-option');
                            const target = activeIndex >= 0 ? opts[activeIndex] : opts[0];
                            if (target) selectValue(target.dataset.value);
                            return;
                        }
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            closeList();
                            input.blur();
                            return;
                        }
                        if (event.key === 'Tab') {
                            closeList();
                        }
                    });

                    list.addEventListener('mousedown', (event) => {
                        const opt = event.target.closest('.spi-brand-combobox-option');
                        if (!opt) return;
                        event.preventDefault();
                        selectValue(opt.dataset.value);
                    });

                    clearBtn.addEventListener('click', () => {
                        selectValue('');
                        input.focus();
                        openList();
                    });

                    document.addEventListener('mousedown', (event) => {
                        if (!wrap.contains(event.target)) closeList();
                    });

                    /* If the underlying select is focused programmatically
                       (e.g. validation error), bounce focus to the visible input. */
                    brandSelect.addEventListener('focus', (event) => {
                        if (event.target === brandSelect) {
                            event.preventDefault();
                            input.focus();
                        }
                    });

                    syncDisplay();
                })();

                /* ---------------------------------------------------------
                   Generic searchable combobox for free-text inputs that have
                   a backing <datalist>. Used by Department + Product Type so
                   the operator can tap the field, see the full list, type to
                   filter, and tap-to-select — without losing the ability to
                   type a brand-new value (just type and tab/blur away).
                --------------------------------------------------------- */
                const initInputCombobox = (wrap) => {
                    if (!wrap) return;
                    const input = wrap.querySelector('.spi-combobox-input');
                    const list = wrap.querySelector('[data-spi-combobox-list]');
                    const empty = wrap.querySelector('[data-spi-combobox-empty]');
                    const clearBtn = wrap.querySelector('[data-spi-combobox-clear]');
                    const listId = wrap.dataset.optionsId;
                    const datalist = listId ? document.getElementById(listId) : null;
                    if (!input || !list || !datalist) return;

                    let activeIndex = -1;
                    let suppressOpenOnNextFocus = false;

                    const escapeRegex = (value) => String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

                    const allOptions = () => Array.from(datalist.options)
                        .map((opt) => clean(opt.value))
                        .filter(Boolean)
                        .filter((value, index, list) => list.findIndex((candidate) => key(candidate) === key(value)) === index);

                    const highlight = (label, query) => {
                        const safe = escapeHtml(label);
                        if (!query) return safe;
                        const pattern = new RegExp(escapeRegex(escapeHtml(query)), 'ig');
                        return safe.replace(pattern, (match) => `<mark class="spi-combobox-option-mark">${match}</mark>`);
                    };

                    const updateClear = () => {
                        if (!clearBtn) return;
                        clearBtn.hidden = !(input.value || '').trim();
                    };

                    const renderList = (rawQuery = '') => {
                        const query = clean(rawQuery);
                        const lowered = query.toLocaleLowerCase();
                        const items = allOptions()
                            .filter((value) => !lowered || value.toLocaleLowerCase().includes(lowered))
                            .sort((a, b) => {
                                if (lowered) {
                                    const aStart = a.toLocaleLowerCase().startsWith(lowered) ? 0 : 1;
                                    const bStart = b.toLocaleLowerCase().startsWith(lowered) ? 0 : 1;
                                    if (aStart !== bStart) return aStart - bStart;
                                }
                                return a.localeCompare(b, undefined, { sensitivity: 'base' });
                            })
                            .slice(0, 80);

                        list.innerHTML = '';
                        activeIndex = items.length ? 0 : -1;

                        if (items.length === 0) {
                            list.hidden = true;
                            empty.hidden = false;
                            input.setAttribute('aria-expanded', 'true');
                            return;
                        }

                        empty.hidden = true;
                        list.hidden = false;
                        input.setAttribute('aria-expanded', 'true');

                        const currentValue = key(input.value);
                        items.forEach((value, idx) => {
                            const li = document.createElement('li');
                            li.className = 'spi-combobox-option';
                            li.setAttribute('role', 'option');
                            li.dataset.value = value;
                            const isSelected = key(value) === currentValue;
                            if (isSelected) li.classList.add('is-selected');
                            if (idx === 0) li.classList.add('is-active');
                            li.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                            li.innerHTML = highlight(value, query);
                            list.appendChild(li);
                        });
                    };

                    const openList = () => {
                        wrap.classList.add('is-open');
                        renderList(input.value);
                    };

                    const closeList = () => {
                        wrap.classList.remove('is-open');
                        list.hidden = true;
                        empty.hidden = true;
                        input.setAttribute('aria-expanded', 'false');
                        activeIndex = -1;
                    };

                    const selectValue = (value) => {
                        input.value = value;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                        updateClear();
                        closeList();
                    };

                    const moveActive = (direction) => {
                        const opts = list.querySelectorAll('.spi-combobox-option');
                        if (opts.length === 0) return;
                        activeIndex = (activeIndex + direction + opts.length) % opts.length;
                        opts.forEach((o, i) => o.classList.toggle('is-active', i === activeIndex));
                        opts[activeIndex]?.scrollIntoView({ block: 'nearest' });
                    };

                    input.addEventListener('focus', () => {
                        if (suppressOpenOnNextFocus) {
                            suppressOpenOnNextFocus = false;
                            return;
                        }
                        openList();
                    });
                    input.addEventListener('click', () => {
                        if (!wrap.classList.contains('is-open')) openList();
                    });
                    input.addEventListener('input', () => {
                        wrap.classList.add('is-open');
                        updateClear();
                        renderList(input.value);
                    });
                    input.addEventListener('keydown', (event) => {
                        if (event.key === 'ArrowDown') {
                            event.preventDefault();
                            if (list.hidden) openList();
                            else moveActive(1);
                            return;
                        }
                        if (event.key === 'ArrowUp') {
                            event.preventDefault();
                            moveActive(-1);
                            return;
                        }
                        if (event.key === 'Enter') {
                            const opts = list.querySelectorAll('.spi-combobox-option');
                            const target = activeIndex >= 0 ? opts[activeIndex] : opts[0];
                            if (target && !list.hidden) {
                                event.preventDefault();
                                selectValue(target.dataset.value);
                            }
                            return;
                        }
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            closeList();
                            suppressOpenOnNextFocus = true;
                            input.blur();
                            return;
                        }
                        if (event.key === 'Tab') {
                            closeList();
                        }
                    });

                    list.addEventListener('mousedown', (event) => {
                        const opt = event.target.closest('.spi-combobox-option');
                        if (!opt) return;
                        event.preventDefault();
                        selectValue(opt.dataset.value);
                    });

                    clearBtn?.addEventListener('click', () => {
                        selectValue('');
                        input.focus();
                        openList();
                    });

                    document.addEventListener('mousedown', (event) => {
                        if (!wrap.contains(event.target)) closeList();
                    });

                    updateClear();
                };

                root.querySelectorAll('[data-spi-combobox]').forEach(initInputCombobox);
            })();
        </script>
    </section>
@endsection
