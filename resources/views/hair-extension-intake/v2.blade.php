@extends('layouts.app')

@section('title', 'Hair Extension Text Intake V2')
@section('section', 'Hair Extensions')
@section('heading', 'Text Intake V2')

@section('content')
    @php
        $oldRows = json_decode(old('variant_rows', '[]'), true);
        $oldRows = is_array($oldRows) ? $oldRows : [];
        $oldCommonRows = json_decode(old('common_variant_rows', '[]'), true);
        $oldCommonRows = is_array($oldCommonRows) ? $oldCommonRows : [];
        $oldClassificationPath = json_decode(old('classification_path', '[]'), true);
        $oldClassificationPath = is_array($oldClassificationPath) ? $oldClassificationPath : [];
    @endphp

    <section
        class="hei-page"
        data-hei-v2-root
        data-old-rows='@json($oldRows)'
        data-old-common-rows='@json($oldCommonRows)'
        data-old-classification-path='@json($oldClassificationPath)'
        data-old-store-id="{{ old('store_id') }}"
        data-old-section-id="{{ old('section_id') }}"
        data-old-subsection-id="{{ old('subsection_id') }}"
        data-has-old="{{ session()->hasOldInput() ? '1' : '0' }}"
        data-clear-draft="{{ session('saved_intake_id') ? '1' : '0' }}"
        data-saved-intake-id="{{ session('saved_intake_id') }}"
        data-brand-data='@json($brandData)'
        data-store-data='@json($storeData)'
        data-edit-payload='@json($editPayload)'
    >
        <style>
            [data-hei-v2-root] {
                max-width: 980px;
                margin: 0 auto;
            }

            .hei-v2-alert {
                border: 1px solid rgba(16, 36, 31, 0.09);
                border-radius: 1.25rem;
                padding: 1rem 1.25rem;
                background: #fffdf8;
                color: var(--hei-ink);
                font-weight: 700;
                box-shadow: 0 2px 12px rgba(16, 36, 31, 0.04);
                animation: hei-slide-in 0.3s ease-out;
            }

            .hei-v2-alert.success {
                border-color: rgba(13, 92, 78, .18);
                background: linear-gradient(135deg, #edf8f3 0%, #e1f5ed 100%);
                color: var(--hei-accent);
            }

            .hei-v2-alert.success a {
                color: var(--hei-accent);
                text-decoration: underline;
                text-underline-offset: 2px;
            }

            .hei-v2-alert.error {
                border-color: rgba(152, 51, 51, .22);
                background: linear-gradient(135deg, #fff5f4 0%, #ffe8e8 100%);
                color: var(--hei-danger);
            }

            @keyframes hei-slide-in {
                from {
                    opacity: 0;
                    transform: translateY(-12px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .hei-v2-inline-error {
                display: block;
                color: var(--hei-danger);
                font-size: .82rem;
                font-weight: 700;
                animation: hei-shake 0.4s ease-in-out;
            }

            @keyframes hei-shake {
                0%, 100% { transform: translateX(0); }
                25% { transform: translateX(-4px); }
                75% { transform: translateX(4px); }
            }

            .hei-v2-submit-row {
                display: flex;
                flex-wrap: wrap;
                gap: .75rem;
                justify-content: flex-end;
                align-items: center;
                margin-top: 1rem;
            }

            .hei-v2-submit-count {
                margin-right: auto;
                padding: .75rem 1.15rem;
                border-radius: 999px;
                background: linear-gradient(135deg, rgba(13, 92, 78, 0.08) 0%, rgba(13, 92, 78, 0.04) 100%);
                font-size: .9375rem;
                font-weight: 700;
                color: var(--hei-accent);
                border: 1px solid rgba(13, 92, 78, 0.12);
            }

            .hei-v2-rapid-row {
                display: flex;
                flex-wrap: wrap;
                gap: 1rem;
                align-items: center;
            }

            .hei-v2-check {
                display: inline-flex;
                align-items: center;
                gap: .5rem;
                cursor: pointer;
                user-select: none;
                -webkit-tap-highlight-color: transparent;
            }

            .hei-v2-check input {
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

            .hei-v2-check input:checked {
                background: var(--hei-accent);
                border-color: var(--hei-accent);
            }

            .hei-v2-check input:checked::after {
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

            .hei-v2-check span {
                font-size: .875rem;
                font-weight: 500;
                color: var(--hei-muted);
            }

            .hei-v2-check:has(input:checked) span {
                color: var(--hei-ink);
                font-weight: 600;
            }

            .hei-v2-quick-map {
                display: grid;
                gap: .75rem;
            }

            .hei-v2-field-title {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: .6rem;
                color: var(--hei-ink);
                font-size: .78rem;
                font-weight: 950;
                letter-spacing: .08em;
                text-transform: uppercase;
            }

            .hei-v2-field-title + .hei-field {
                margin-top: -.35rem;
            }

            .hei-v2-info {
                position: relative;
                margin: 0;
            }

            .hei-v2-info summary {
                display: inline-grid;
                width: 1.75rem;
                height: 1.75rem;
                place-items: center;
                border: 1.5px solid rgba(13, 92, 78, .2);
                border-radius: 999px;
                background: #fffdf8;
                color: var(--hei-accent);
                font-size: .85rem;
                font-weight: 950;
                list-style: none;
                cursor: pointer;
                -webkit-tap-highlight-color: transparent;
            }

            .hei-v2-info summary::-webkit-details-marker {
                display: none;
            }

            .hei-v2-info-card {
                position: absolute;
                right: 0;
                top: calc(100% + .45rem);
                z-index: 12;
                width: min(82vw, 340px);
                border: 1px solid rgba(13, 92, 78, .18);
                border-radius: 1rem;
                background: #fffdf8;
                color: var(--hei-muted);
                padding: .85rem;
                box-shadow: 0 12px 30px rgba(16, 36, 31, .14);
                font-size: .86rem;
                font-weight: 750;
                line-height: 1.45;
                letter-spacing: 0;
                text-transform: none;
            }

            .hei-v2-info-card strong {
                display: block;
                color: var(--hei-ink);
                margin-bottom: .25rem;
                font-size: .9rem;
            }

            .hei-v2-status-row {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: .4rem;
                margin-top: -.35rem;
            }

            .hei-v2-status-btn {
                min-height: 38px;
                border: 1.5px solid rgba(16, 36, 31, .12);
                border-radius: 999px;
                background: #fffdf8;
                color: var(--hei-muted);
                font-size: .78rem;
                font-weight: 950;
                cursor: pointer;
                -webkit-tap-highlight-color: transparent;
            }

            .hei-v2-status-btn.active {
                border-color: rgba(13, 92, 78, .3);
                background: var(--hei-accent);
                color: #fffaf3;
                box-shadow: 0 3px 10px rgba(13, 92, 78, .18);
            }

            .hei-field.is-not-known input,
            .hei-field.is-not-known select {
                background: #f1eee7 !important;
                color: var(--hei-muted);
            }

            .hei-v2-location-grid {
                display: grid;
                gap: .6rem;
                padding: 1rem 1rem 1.1rem;
                border-top: 1px solid rgba(16, 36, 31, .08);
            }

            .hei-v2-location-grid .hei-field {
                margin: 0;
            }

            .hei-v2-classification-accordion summary {
                align-items: center;
            }

            .hei-v2-summary-copy {
                display: grid;
                gap: .15rem;
            }

            .hei-v2-summary-copy strong {
                color: var(--hei-ink);
                font-size: .95rem;
                font-weight: 950;
            }

            .hei-v2-summary-copy small {
                color: var(--hei-muted);
                font-size: .76rem;
                font-weight: 800;
                line-height: 1.25;
            }

            .hei-v2-classification {
                display: grid;
                gap: .75rem;
                padding: 1rem 1rem 1.1rem;
                border-top: 1px solid rgba(16, 36, 31, .08);
            }

            .hei-v2-classification-note {
                margin: 0;
                color: var(--hei-muted);
                font-size: .82rem;
                font-weight: 800;
                line-height: 1.4;
            }

            .hei-v2-path-list {
                display: flex;
                flex-wrap: wrap;
                gap: .45rem;
                min-height: 2.25rem;
                align-items: center;
            }

            .hei-v2-path-list:empty::before {
                content: 'No grouping yet';
                color: var(--hei-muted);
                font-size: .85rem;
                font-weight: 800;
            }

            .hei-v2-path-chip {
                display: inline-flex;
                align-items: center;
                gap: .45rem;
                min-height: 38px;
                border: 1px solid rgba(13, 92, 78, .18);
                border-radius: 999px;
                background: #ffffff;
                color: var(--hei-accent);
                padding: .45rem .55rem .45rem .75rem;
                font-size: .88rem;
                font-weight: 900;
                box-shadow: 0 2px 8px rgba(16, 36, 31, .04);
            }

            .hei-v2-path-chip span:first-child {
                display: inline-grid;
                width: 1.35rem;
                height: 1.35rem;
                place-items: center;
                border-radius: 999px;
                background: rgba(13, 92, 78, .1);
                font-size: .72rem;
            }

            .hei-v2-path-chip button {
                display: inline-grid;
                width: 1.55rem;
                height: 1.55rem;
                place-items: center;
                border: 0;
                border-radius: 999px;
                background: rgba(162, 60, 50, .08);
                color: var(--hei-danger);
                cursor: pointer;
                font-weight: 950;
            }

            .hei-v2-path-add {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                gap: .6rem;
                align-items: stretch;
            }

            .hei-v2-path-presets {
                display: flex;
                gap: .45rem;
                overflow-x: auto;
                padding-bottom: .15rem;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }

            .hei-v2-path-presets::-webkit-scrollbar {
                display: none;
            }

            .hei-v2-cover-wrap {
                display: grid;
                gap: .85rem;
            }

            .hei-v2-cover-preview {
                display: grid;
                min-height: 180px;
                place-items: center;
                overflow: hidden;
                border: 2px dashed rgba(13, 92, 78, 0.25);
                border-radius: 1.25rem;
                background: linear-gradient(145deg, #f8f6f1 0%, #ebe6dc 50%, #f8f6f1 100%);
                color: var(--hei-muted);
                font-size: .875rem;
                font-weight: 700;
                padding: 1rem;
                text-align: center;
                transition: all 0.3s ease;
            }

            .hei-v2-cover-preview:has(img) {
                border-style: solid;
                border-color: rgba(13, 92, 78, 0.2);
                background: linear-gradient(145deg, #fdfcf9 0%, #f3f1ec 100%);
            }

            .hei-v2-cover-preview img {
                width: 100%;
                max-height: 280px;
                object-fit: contain;
                border-radius: .75rem;
                box-shadow: 0 4px 16px rgba(16, 36, 31, 0.08);
            }

            /* Enhanced step headers */
            .hei-step-head {
                position: relative;
                padding-bottom: .85rem !important;
            }

            .hei-step-head::after {
                content: '';
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                height: 2px;
                background: linear-gradient(90deg, var(--hei-accent) 0%, rgba(13, 92, 78, 0.2) 100%);
                border-radius: 999px;
            }

            .hei-step-num {
                box-shadow: 0 2px 8px rgba(13, 92, 78, 0.12) !important;
            }

            /* Enhanced form fields */
            .hei-field input:focus,
            .hei-field select:focus,
            .hei-field textarea:focus {
                outline: none;
                border-color: var(--hei-accent);
                box-shadow: 0 0 0 3px rgba(13, 92, 78, 0.1), 0 2px 12px rgba(13, 92, 78, 0.08);
            }

            .hei-field input[type="file"] {
                cursor: pointer;
                padding: .75rem .95rem;
            }

            /* Larger, cleaner inputs for faster data entry */
            .hei-field input:not([type="checkbox"]):not([type="file"]),
            .hei-field select,
            .hei-field textarea {
                font-size: 1.0625rem !important;
                min-height: 54px !important;
                padding: 0.95rem 1.1rem !important;
            }

            .hei-field textarea {
                min-height: 90px !important;
            }

            .hei-v2-note-field textarea {
                min-height: 118px !important;
            }

            /* Remove label spacing when no label */
            .hei-field:not(:has(> span)) {
                gap: 0;
            }

            /* Hide field labels by default for cleaner look */
            .hei-field > span {
                display: none;
            }

            /* Enhanced buttons */
            .hei-btn.primary {
                box-shadow: 0 4px 12px rgba(13, 92, 78, 0.35) !important;
                font-weight: 700 !important;
                letter-spacing: -0.01em;
                min-height: 56px !important;
                font-size: 1.0625rem !important;
                padding: 0.95rem 1.5rem !important;
            }

            .hei-btn.primary:hover {
                box-shadow: 0 6px 16px rgba(13, 92, 78, 0.4) !important;
            }

            .hei-btn.secondary {
                transition: all 0.2s ease;
                min-height: 48px !important;
            }

            .hei-btn.secondary:hover {
                border-color: var(--hei-accent);
                background: var(--hei-accent-soft);
                color: var(--hei-accent);
            }

            /* Enhanced toolbar */
            .hei-toolbar {
                box-shadow: 0 8px 32px rgba(16, 36, 31, 0.08) !important;
            }

            /* Optional sections accordion styling */
            .hei-step details.hei-mini-accordion {
                background: linear-gradient(135deg, rgba(255, 253, 248, 0.95) 0%, rgba(248, 246, 241, 0.92) 100%);
                border: 1.5px solid rgba(16, 36, 31, 0.1);
                box-shadow: 0 2px 8px rgba(16, 36, 31, 0.04);
            }

            .hei-step details.hei-mini-accordion summary {
                color: var(--hei-ink);
                font-size: 0.9375rem;
                transition: background 0.2s ease;
            }

            .hei-step details.hei-mini-accordion summary:hover {
                background: rgba(237, 248, 243, 0.5);
            }

            .hei-step details.hei-mini-accordion summary::after {
                font-size: 1.25rem;
            }

            .hei-step details.hei-mini-accordion[open] {
                border-color: rgba(13, 92, 78, 0.18);
            }

            /* Cleaner form spacing */
            .hei-form-grid {
                gap: 2rem !important;
                margin-bottom: 3rem !important;
                display: flex;
                flex-direction: column;
            }

            /* Desktop Layout Enhancements */
            @media (min-width: 1024px) {
                .hei-form-grid {
                    max-width: 800px;
                    margin: 0 auto 4rem !important;
                    background: transparent !important;
                    border: none !important;
                    box-shadow: none !important;
                    padding: 0 !important;
                }

                .hei-form-grid > section {
                    background: #fffdf8;
                    border: 1px solid rgba(16, 36, 31, 0.08);
                    border-radius: 24px;
                    padding: 2.5rem;
                    box-shadow: 0 12px 36px rgba(31, 36, 33, 0.04);
                    margin: 0 !important;
                    transition: transform 0.2s ease, box-shadow 0.2s ease;
                }

                .hei-form-grid > section:focus-within {
                    transform: translateY(-2px);
                    box-shadow: 0 16px 48px rgba(31, 36, 33, 0.08);
                    border-color: rgba(13, 92, 78, 0.2);
                }

                .hei-v2-submit-row {
                    background: #fffdf8;
                    border: 1px solid rgba(16, 36, 31, 0.08);
                    border-radius: 24px;
                    padding: 1.5rem 2.5rem;
                    box-shadow: 0 12px 36px rgba(31, 36, 33, 0.04);
                    margin-top: 0 !important;
                }
            }

            @media (max-width: 640px) {
                .hei-form-grid {
                    margin-bottom: 3rem !important;
                }
            }

            .hei-step {
                gap: 1.5rem !important;
            }

            .hei-step-head {
                margin-bottom: 0.5rem;
                padding-bottom: 1rem;
                border-bottom: 1px solid rgba(16, 36, 31, 0.06);
            }

            .hei-step-head h2 {
                font-size: 1.25rem !important;
                font-weight: 800 !important;
                letter-spacing: -0.02em;
            }

            /* Variant mapping simplifications */
            .hei-vm-flow {
                gap: 1.25rem !important;
            }

            .hei-vm-block {
                padding: 0 !important;
                border: none !important;
            }

            .hei-vm-block-head {
                display: none !important;
            }

            .hei-map-groups {
                gap: 1rem !important;
                margin-top: 0.5rem !important;
            }

            .hei-1d-mode .hei-map-card-header {
                display: none !important;
            }
            .hei-1d-mode [data-hei-add-map-group] {
                display: none !important;
            }
            .hei-1d-mode [data-hei-sub-variant-accordion] {
                display: none !important;
            }
            .hei-1d-mode .hei-map-group-card {
                padding: 1rem !important;
            }

            .hei-map-group-card,
            .hei-common-variant-card {
                padding: 1.5rem !important;
                background: #ffffff !important;
                border: 1px solid rgba(16, 36, 31, 0.1) !important;
                border-radius: 16px !important;
                box-shadow: 0 4px 12px rgba(31, 36, 33, 0.03) !important;
                display: flex !important;
                flex-direction: column !important;
                gap: 1.25rem !important;
                position: relative;
            }

            .hei-map-card-header {
                display: flex;
                align-items: flex-start;
                gap: 1rem;
            }

            .hei-map-card-row {
                display: flex !important;
                flex: 1;
                gap: 1rem !important;
                align-items: center !important;
            }

            .hei-map-card-row .hei-field {
                flex: 1;
            }

            .hei-field-label-mini {
                display: block;
                font-size: 0.75rem;
                font-weight: 700;
                color: var(--hei-muted);
                text-transform: uppercase;
                letter-spacing: 0.05em;
                margin-bottom: 0.4rem;
            }

            .hei-map-arrow {
                padding-bottom: 0 !important;
                display: flex !important;
                align-items: center !important;
                color: var(--hei-muted) !important;
                justify-content: center !important;
                margin-top: 1.5rem; /* Align with inputs below labels */
            }

            .hei-map-group-card .hei-variant-remove-btn,
            .hei-common-variant-card .hei-variant-remove-btn {
                position: static !important;
                background: #fff5f4 !important;
                color: var(--hei-danger) !important;
                border: 1px solid rgba(152, 51, 51, 0.2) !important;
                width: 36px !important;
                height: 36px !important;
                border-radius: 8px !important;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-top: 1.5rem; /* Align with inputs below labels */
                transition: all 0.2s ease;
                flex-shrink: 0;
            }

            .hei-map-group-card .hei-variant-remove-btn:hover,
            .hei-common-variant-card .hei-variant-remove-btn:hover {
                background: var(--hei-danger) !important;
                color: white !important;
                border-color: var(--hei-danger) !important;
            }

            .hei-map-card-body {
                display: flex;
                flex-direction: column;
                gap: 1rem;
            }

            /* Larger variant value box */
            .hei-variant-value-box {
                min-height: 100px !important;
                padding: 1rem !important;
                gap: 0.75rem !important;
                background: #fdfbf5 !important;
                border: 1px solid rgba(16, 36, 31, 0.15) !important;
                border-radius: 12px !important;
                transition: border-color 0.2s ease, box-shadow 0.2s ease;
            }
            
            .hei-variant-value-box:focus-within {
                border-color: var(--hei-accent) !important;
                box-shadow: 0 0 0 3px rgba(13, 92, 78, 0.1) !important;
                background: #ffffff !important;
            }

            @media (max-width: 480px) {
                .hei-map-card-header {
                    flex-direction: column;
                }
                .hei-map-card-row {
                    flex-direction: column;
                    width: 100%;
                    align-items: stretch !important;
                }
                .hei-map-arrow {
                    margin-top: 0;
                    margin-bottom: 0;
                    transform: rotate(90deg);
                }
                .hei-map-group-card .hei-variant-remove-btn,
                .hei-common-variant-card .hei-variant-remove-btn {
                    position: absolute !important;
                    top: 1rem;
                    right: 1rem;
                    margin-top: 0;
                }
            }

            .hei-variant-chip {
                min-height: 38px !important;
                padding: 0.6rem 0.85rem !important;
                font-size: 0.9375rem !important;
            }

            .hei-v2-autocomplete-panel {
                display: grid;
                width: 100%;
                gap: .45rem;
                padding: .55rem .15rem .1rem;
            }

            .hei-v2-autocomplete-panel[hidden] {
                display: none !important;
            }

            .hei-v2-autocomplete-label {
                color: var(--hei-muted);
                font-size: .72rem;
                font-weight: 800;
                letter-spacing: .08em;
                text-transform: uppercase;
            }

            .hei-v2-autocomplete-options {
                display: flex;
                gap: .45rem;
                overflow-x: auto;
                padding-bottom: .25rem;
                touch-action: pan-x;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }

            .hei-v2-autocomplete-options::-webkit-scrollbar {
                display: none;
            }

            .hei-v2-autocomplete-option {
                flex: 0 0 auto;
                min-height: 42px;
                border: 1.5px solid rgba(13, 92, 78, .2);
                border-radius: 999px;
                background: #ffffff;
                color: var(--hei-accent);
                font-size: .95rem;
                font-weight: 800;
                padding: .62rem .9rem;
                box-shadow: 0 2px 8px rgba(16, 36, 31, .06);
                cursor: pointer;
                -webkit-tap-highlight-color: transparent;
            }

            .hei-v2-autocomplete-option:active {
                transform: scale(.96);
                background: var(--hei-accent-soft);
            }

            /* Mobile enhancements */
            @media (max-width: 640px) {
                .hei-v2-submit-row .hei-btn,
                .hei-v2-submit-count {
                    width: 100%;
                }

                .hei-v2-submit-row {
                    margin-top: 1rem;
                    padding-top: 1rem;
                    border-top: 1px solid rgba(16, 36, 31, 0.08);
                }

                .hei-v2-submit-count {
                    order: -1;
                    font-size: .8125rem;
                }

                .hei-v2-inline-error {
                    order: -2;
                    width: 100%;
                    text-align: center;
                }

                .hei-v2-cover-preview {
                    min-height: 200px;
                }

                .hei-v2-path-add {
                    grid-template-columns: 1fr;
                }
            }

            @media (prefers-reduced-motion: reduce) {
                .hei-v2-alert,
                .hei-v2-inline-error {
                    animation: none;
                }
                
                .hei-v2-check,
                .hei-btn {
                    transition: none;
                }
            }
        </style>

        <div class="hei-toolbar">
            <div class="hei-toolbar-meta">
                <div class="hei-badge-row">
                    <span class="hei-pill"><span class="hei-pill-dot" aria-hidden="true"></span> {{ $editIntake ? 'Editing #'.$editIntake->id : 'V2' }}</span>
                    <span class="hei-session-meter">Saved: <strong data-session-count>0</strong></span>
                </div>
            </div>
            <div class="hei-toolbar-actions">
                <a class="hei-btn secondary" href="{{ $submittedUrl }}">Submitted</a>
                <button type="button" class="hei-btn linkish" data-clear-form>Clear</button>
            </div>
        </div>

        @if (session('status'))
            <div class="hei-v2-alert success">
                {{ session('status') }}
                <a href="{{ $submittedUrl }}">Open submitted intake</a>
            </div>
        @endif

        @if ($errors->any())
            <div class="hei-v2-alert error">
                <strong>Fix before saving:</strong>
                {{ $errors->first() }}
            </div>
        @endif

        <form class="hei-form-card hei-form-grid" method="POST" action="{{ $submitUrl }}" enctype="multipart/form-data" data-hei-v2-form>
            @csrf
            @if ($editIntake)
                @method('PATCH')
            @endif
            <input type="hidden" name="brand_catalogue_product_type_id" value="{{ old('brand_catalogue_product_type_id') }}" data-product-type-id>
            <input type="hidden" name="brand_catalogue_style_id" value="" data-style-id>
            <input type="hidden" name="classification_path" value="{{ old('classification_path', '[]') }}" data-classification-path>
            <input type="hidden" name="variant_rows" value="{{ old('variant_rows', '[]') }}" data-variant-json>
            <input type="hidden" name="common_variant_rows" value="{{ old('common_variant_rows', '[]') }}" data-common-variant-json>
            <datalist id="hei-variant-group-names">
                @foreach (['Colour', 'Length', 'Pack count', 'Texture', 'Size', 'Material', 'Style', 'Other'] as $preset)
                    <option value="{{ $preset }}"></option>
                @endforeach
            </datalist>
            <datalist id="hei-v2-variant-value-suggestions" data-hei-variant-value-suggestions></datalist>

            <section class="hei-step" aria-labelledby="hei-v2-step-main">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="hei-v2-step-main">1</span>
                    <h2>Product</h2>
                </div>

                <div class="hei-v2-field-title">Brand</div>
                <label class="hei-field">
                    <input type="text" placeholder="Search brand..." data-brand-search class="hei-v2-brand-search" style="margin-bottom: 0.25rem; padding: 0.5rem 0.75rem; border: 1px solid rgba(16, 36, 31, 0.15); border-radius: 12px; font-size: 0.9375rem; width: 100%; box-sizing: border-box;">
                    <select name="brand_catalogue_brand_id" data-brand-select>
                        <option value="">Select brand...</option>
                        @foreach ($brands as $brand)
                            <option
                                value="{{ $brand->id }}"
                                data-brand-name="{{ $brand->name }}"
                                @selected((string) old('brand_catalogue_brand_id') === (string) $brand->id)
                            >
                                {{ $brand->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="hei-field">
                    <input name="brand_name" value="{{ old('brand_name') }}" placeholder="Type brand if not in list" data-brand-name-input autocomplete="off">
                </label>

                <details class="hei-mini-accordion hei-v2-classification-accordion" data-classification-accordion>
                    <summary>
                        <span class="hei-v2-summary-copy">
                            <strong>Grouping path</strong>
                            <small>Optional: sub-brand, range, product group, shelf wording.</small>
                        </span>
                    </summary>
                    <div class="hei-v2-classification">
                        <p class="hei-v2-classification-note">Use shop words in order, e.g. Kuknus Bulk &gt; Fusion &gt; Peruvian Remi.</p>
                        <div class="hei-v2-path-list" data-classification-list></div>
                        <div class="hei-v2-path-add">
                            <label class="hei-field">
                                <input type="text" placeholder="Add group (e.g. Kuknus Bulk, Fusion)" data-classification-input enterkeyhint="done" autocomplete="off">
                            </label>
                            <button type="button" class="hei-btn secondary" data-add-classification>Add group</button>
                        </div>
                        <div class="hei-v2-path-presets" aria-label="Quick grouping presets">
                            @foreach (['Braid', 'Bulk', 'Weave', 'Wig', 'Ponytail', 'Crochet', 'Fusion', 'Human Hair Blend'] as $preset)
                                <button type="button" class="hei-chip-add" data-classification-preset="{{ $preset }}">{{ $preset }}</button>
                            @endforeach
                        </div>
                    </div>
                </details>

                <input type="hidden" name="catalogue_style_status" value="not_known" data-status-input="catalogue_style">

                <div class="hei-v2-field-title">
                    <span>Product type</span>
                    <details class="hei-v2-info">
                        <summary aria-label="What is product type?">?</summary>
                        <div class="hei-v2-info-card">
                            <strong>Product type</strong>
                            What kind of hair product it is. Examples: Bulk Hair, Braid, Crochet Hair, Weave, Wig, Ponytail.
                        </div>
                    </details>
                </div>
                <input type="hidden" name="product_type_status" value="{{ old('product_type_status', 'known') }}" data-status-input="product_type">
                <div class="hei-v2-status-row" data-status-row="product_type">
                    <button type="button" class="hei-v2-status-btn active" data-status-set="product_type" data-status-value="known">Known</button>
                    <button type="button" class="hei-v2-status-btn" data-status-set="product_type" data-status-value="not_known">Not known</button>
                    <button type="button" class="hei-v2-status-btn" data-status-set="product_type" data-status-value="not_sure">Not sure</button>
                </div>
                <label class="hei-field" data-status-field="product_type">
                    <input name="product_type_name" value="{{ old('product_type_name') }}" placeholder="Product type (optional)" data-product-type>
                </label>

                <div class="hei-v2-field-title">
                    <span>Style / family</span>
                    <details class="hei-v2-info">
                        <summary aria-label="What is style or family?">?</summary>
                        <div class="hei-v2-info-card">
                            <strong>Style / family</strong>
                            The actual product/range name you see on the pack. Examples: Peruvian Remi, Deep Twist, Water Wave, French Curl, Afro Kinky.
                        </div>
                    </details>
                </div>
                <input type="hidden" name="style_family_status" value="{{ old('style_family_status', 'known') }}" data-status-input="style_family">
                <div class="hei-v2-status-row" data-status-row="style_family">
                    <button type="button" class="hei-v2-status-btn active" data-status-set="style_family" data-status-value="known">Known</button>
                    <button type="button" class="hei-v2-status-btn" data-status-set="style_family" data-status-value="not_known">Not known</button>
                    <button type="button" class="hei-v2-status-btn" data-status-set="style_family" data-status-value="not_sure">Not sure</button>
                </div>
                <label class="hei-field" data-status-field="style_family">
                    <input name="style_name" value="{{ old('style_name') }}" placeholder="Style / Family name (optional)" data-style-name>
                </label>

                <details class="hei-mini-accordion hei-v2-location-accordion" data-location-accordion>
                    <summary>
                        <span class="hei-v2-summary-copy">
                            <strong>Shelf / area</strong>
                            <small>Optional: choose store, section and subsection.</small>
                        </span>
                    </summary>
                    <div class="hei-v2-location-grid">
                        <label class="hei-field">
                            <select name="store_id" data-store-select>
                                <option value="">Choose store...</option>
                            </select>
                        </label>
                        <label class="hei-field">
                            <select name="section_id" data-section-select>
                                <option value="">Choose section...</option>
                            </select>
                        </label>
                        <label class="hei-field">
                            <select name="subsection_id" data-subsection-select>
                                <option value="">Choose subsection...</option>
                            </select>
                        </label>
                        <label class="hei-field">
                            <input name="shelf_location" value="{{ old('shelf_location') }}" placeholder="Extra shelf note (optional)" data-shelf-location>
                        </label>
                    </div>
                </details>

                <div class="hei-v2-rapid-row">
                    <label class="hei-v2-check">
                        <input type="checkbox" checked data-keep-brand>
                        <span>Keep brand</span>
                    </label>
                    <label class="hei-v2-check">
                        <input type="checkbox" data-keep-type>
                        <span>Keep type</span>
                    </label>
                    <label class="hei-v2-check">
                        <input type="checkbox" checked data-keep-shelf>
                        <span>Keep shelf/area</span>
                    </label>
                </div>
            </section>

            <section class="hei-step" aria-labelledby="hei-v2-step-variants">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="hei-v2-step-variants">2</span>
                    <h2>Variants</h2>
                </div>

                <div style="margin-bottom: 1.5rem; padding: 1rem; background: #f8fcfb; border: 1px solid rgba(13, 92, 78, 0.15); border-radius: 12px;">
                    <label class="hei-v2-check" style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer; margin: 0;">
                        <input type="checkbox" data-hei-variant-mode-toggle>
                        <span style="font-weight: 600; color: var(--hei-ink); font-size: 0.9375rem;">Product has sub-variants (e.g. multiple Lengths, each with different Colours)</span>
                    </label>
                </div>

                <details class="hei-accordion hei-catalogue-variants" data-hei-catalogue-variant-panel hidden>
                    <summary class="hei-accordion-summary">
                        <span class="hei-accordion-summary-copy">
                            <span class="hei-accordion-summary-label" data-hei-catalogue-variant-title>Available catalogue variants</span>
                        </span>
                    </summary>
                    <div class="hei-accordion-panel">
                        <div class="hei-catalogue-variant-actions">
                            <button type="button" class="hei-btn primary" data-hei-apply-catalogue-variant-matrix>Use all available variants</button>
                        </div>
                        <p class="hei-help" data-hei-catalogue-variant-hint></p>
                        <div class="hei-catalogue-variant-grid" data-hei-catalogue-variant-grid></div>
                    </div>
                </details>

                <div class="hei-variant-mapping" data-hei-variant-mapping>
                    <div class="hei-variant-mapping-panel">
                        <ol class="hei-vm-flow">
                            <li class="hei-vm-block">
                                <label class="hei-field hei-vm-field">
                                    <input
                                        type="text"
                                        name="variant_main_axis"
                                        value="{{ old('variant_main_axis', 'Colour') }}"
                                        data-hei-map-main-axis
                                        list="hei-variant-group-names"
                                        placeholder="Main variant (e.g. Colour, Length, Size)"
                                        aria-describedby="hei-v2-main-axis-label"
                                        enterkeyhint="next"
                                    >
                                </label>
                                <details class="hei-mini-accordion">
                                    <summary>Quick presets</summary>
                                    <div class="hei-variant-presets" role="group" aria-label="Quick-set main variant axis">
                                        @foreach (['Colour', 'Length', 'Pack count', 'Texture', 'Size', 'Material'] as $preset)
                                            <button type="button" class="hei-chip-add" data-hei-map-axis-preset="{{ $preset }}">{{ $preset }}</button>
                                        @endforeach
                                    </div>
                                </details>
                            </li>
                            <li class="hei-vm-block">
                                <details class="hei-mini-accordion" data-hei-sub-variant-accordion>
                                    <summary>Sub variant</summary>
                                    <div class="hei-variant-presets" role="group" aria-label="Quick-set sub variant axis">
                                        @foreach (['Colour', 'Length', 'Pack count', 'Texture', 'Size', 'Material'] as $preset)
                                            <button type="button" class="hei-chip-add" data-hei-vm-sub-preset="{{ $preset }}">{{ $preset }}</button>
                                        @endforeach
                                    </div>
                                </details>
                                <div class="hei-map-groups" data-hei-map-groups></div>
                                <details class="hei-mini-accordion">
                                    <summary>Bulk paste / AI JSON</summary>
                                    <div class="hei-v2-quick-map">
                                        <label class="hei-field">
                                            <textarea rows="4" placeholder="Paste AI JSON here, or use format:&#10;20: Black,Brown&#10;22: Black,Brown,Blonde" data-quick-map></textarea>
                                        </label>
                                        <button type="button" class="hei-btn secondary" data-quick-map-apply>Apply data</button>
                                    </div>
                                </details>
                                <button type="button" class="hei-btn secondary hei-vm-action" data-hei-add-map-group>
                                    + Add variant group
                                </button>
                            </li>
                            <li class="hei-vm-block">
                                <details class="hei-mini-accordion">
                                    <summary>Common variant</summary>
                                    <div class="hei-variant-presets" role="group" aria-label="Quick-set common variant group">
                                        @foreach (['Pack count', 'Colour', 'Length', 'Texture', 'Size', 'Material'] as $preset)
                                            <button type="button" class="hei-chip-add" data-hei-vm-common-preset="{{ $preset }}">{{ $preset }}</button>
                                        @endforeach
                                    </div>
                                </details>
                                <div class="hei-common-variant-list" data-hei-common-variant-list></div>
                                <button type="button" class="hei-btn secondary hei-vm-action" data-hei-add-common-variant>
                                    + Add common variant
                                </button>
                            </li>
                        </ol>
                    </div>
                </div>

            </section>

            <section class="hei-step" aria-labelledby="hei-v2-step-notes">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="hei-v2-step-notes">3</span>
                    <h2>Note</h2>
                </div>

                <label class="hei-field hei-v2-note-field">
                    <textarea name="visible_text_notes" rows="3" data-notes aria-label="Product entry note">{{ old('visible_text_notes') }}</textarea>
                </label>
            </section>

            <section class="hei-step" aria-labelledby="hei-v2-step-cover">
                <details class="hei-mini-accordion" style="border-radius: 1.25rem; padding: 0;">
                    <summary style="padding: 1rem 1.25rem; display: flex; align-items: center; gap: 0.75rem; font-size: 0.9375rem;">
                        <span class="hei-step-num" id="hei-v2-step-cover" style="margin: 0;">4</span>
                        <span style="flex: 1; font-weight: 700; color: var(--hei-ink);">Photo</span>
                    </summary>

                    <div style="padding: 0 1.25rem 1.25rem; border-top: 1px solid rgba(16, 36, 31, 0.08);">
                        <div class="hei-v2-cover-wrap" style="margin-top: 1rem; position: relative;">
                            <div class="hei-v2-cover-preview" data-cover-preview>
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.4">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    <span>No photo</span>
                                </div>
                            </div>
                            <button type="button" class="hei-btn secondary" data-cover-remove style="position: absolute; top: 0.5rem; right: 0.5rem; padding: 0.25rem 0.5rem; min-height: unset; font-size: 0.75rem; display: none; background: rgba(255,255,255,0.9); border: 1px solid rgba(0,0,0,0.1); box-shadow: 0 2px 4px rgba(0,0,0,0.1); z-index: 10;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 0.25rem;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                Remove
                            </button>
                            <div style="display: flex; flex-direction: column; gap: 0.5rem; width: 100%;">
                                <input type="hidden" name="remove_photo" value="0" data-remove-photo-input>
                                <label class="hei-field">
                                    <input type="file" name="cover_photo" accept="image/*" capture="environment" data-cover-photo>
                                </label>
                                <div class="hew-capture-paste-zone" tabindex="0" contenteditable="true" role="textbox" aria-label="Paste product photo here" data-cover-paste-zone style="min-height: 60px; border: 2px dashed rgba(16, 36, 31, 0.2); border-radius: 12px; padding: 1rem; text-align: center; color: var(--hei-muted); font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease; outline: none; background: #fffdf8;">
                                    Paste copied image here<br>
                                    <span style="font-size: 0.75rem; font-weight: 500; opacity: 0.7;">Tap here, then paste. On desktop use Ctrl+V.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </details>
            </section>

            <div class="hei-v2-submit-row">
                <span class="hei-session-meter hei-v2-submit-count" data-total-count>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: inline-block; vertical-align: text-bottom; margin-right: 0.25rem;">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    0 variants
                </span>
                <span class="hei-v2-inline-error" data-inline-error></span>
                <button type="submit" class="hei-btn primary hei-btn-primary-wide">
                    {{ $editIntake ? 'Update & Next' : 'Save & Next' }}
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display: inline-block; vertical-align: text-bottom; margin-left: 0.35rem;">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>
            </div>
        </form>

        <script>
            (() => {
                const root = document.querySelector('[data-hei-v2-root]');
                if (!root) return;

                const draftKey = 'hei_text_intake_v2_draft';
                const sessionKey = 'hei_text_intake_v2_session';
                const countKey = 'hei_text_intake_v2_saved_count';
                const lastSavedKey = 'hei_text_intake_v2_last_saved_id';
                const brandData = JSON.parse(root.dataset.brandData || '[]');
                const storeData = JSON.parse(root.dataset.storeData || '[]');
                const form = root.querySelector('[data-hei-v2-form]');
                const brandSelect = root.querySelector('[data-brand-select]');
                const brandSearch = root.querySelector('[data-brand-search]');
                const brandName = root.querySelector('[data-brand-name-input]');
                const productTypeId = root.querySelector('[data-product-type-id]');
                const styleId = root.querySelector('[data-style-id]');
                const styleSelect = root.querySelector('[data-style-select]');
                const productType = root.querySelector('[data-product-type]');
                const styleName = root.querySelector('[data-style-name]');
                const classificationPath = root.querySelector('[data-classification-path]');
                const classificationAccordion = root.querySelector('[data-classification-accordion]');
                const classificationList = root.querySelector('[data-classification-list]');
                const classificationInput = root.querySelector('[data-classification-input]');
                const locationAccordion = root.querySelector('[data-location-accordion]');
                const storeSelect = root.querySelector('[data-store-select]');
                const sectionSelect = root.querySelector('[data-section-select]');
                const subsectionSelect = root.querySelector('[data-subsection-select]');
                const shelfLocation = root.querySelector('[data-shelf-location]');
                const notes = root.querySelector('[data-notes]');
                const mainAxis = root.querySelector('[data-hei-map-main-axis]');
                const mapGroups = root.querySelector('[data-hei-map-groups]');
                const commonVariantList = root.querySelector('[data-hei-common-variant-list]');
                const addCommonVariantButton = root.querySelector('[data-hei-add-common-variant]');
                const hiddenRows = root.querySelector('[data-variant-json]');
                const hiddenCommonRows = root.querySelector('[data-common-variant-json]');
                const catalogueVariantPanel = root.querySelector('[data-hei-catalogue-variant-panel]');
                const catalogueVariantTitle = root.querySelector('[data-hei-catalogue-variant-title]');
                const catalogueVariantHint = root.querySelector('[data-hei-catalogue-variant-hint]');
                const catalogueVariantGrid = root.querySelector('[data-hei-catalogue-variant-grid]');
                const applyCatalogueVariantMatrixButton = root.querySelector('[data-hei-apply-catalogue-variant-matrix]');
                const variantValueSuggestions = root.querySelector('[data-hei-variant-value-suggestions]');
                const autocompletePanel = document.createElement('div');
                const totalCount = root.querySelector('[data-total-count]');
                const inlineError = root.querySelector('[data-inline-error]');
                const sessionCount = root.querySelector('[data-session-count]');
                const keepBrand = root.querySelector('[data-keep-brand]');
                const keepType = root.querySelector('[data-keep-type]');
                const keepShelf = root.querySelector('[data-keep-shelf]');
                const quickMap = root.querySelector('[data-quick-map]');
                const coverPhoto = root.querySelector('[data-cover-photo]');
                const coverPreview = root.querySelector('[data-cover-preview]');
                const coverPasteZone = root.querySelector('[data-cover-paste-zone]');
                const coverRemove = root.querySelector('[data-cover-remove]');
                const removePhotoInput = root.querySelector('[data-remove-photo-input]');
                const variantModeToggle = root.querySelector('[data-hei-variant-mode-toggle]');
                const variantMappingContainer = root.querySelector('[data-hei-variant-mapping]');
                const hasOldInput = root.dataset.hasOld === '1';
                let editPayload = null;
                let activeAutocompleteInput = null;
                let autocompletePointerHandled = false;
                const commonVariantGroups = ['Colour', 'Length', 'Pack count', 'Texture', 'Size', 'Material', 'Style', 'Other'];

                autocompletePanel.className = 'hei-v2-autocomplete-panel';
                autocompletePanel.dataset.heiV2Autocomplete = '1';
                autocompletePanel.hidden = true;
                autocompletePanel.addEventListener('pointerdown', (event) => {
                    const button = event.target.closest('[data-hei-autocomplete-value]');
                    if (!button) return;
                    event.preventDefault();
                    autocompletePointerHandled = true;
                    applyAutocompleteSuggestion(button.getAttribute('data-hei-autocomplete-value') || '');
                    window.setTimeout(() => {
                        autocompletePointerHandled = false;
                    }, 0);
                });
                autocompletePanel.addEventListener('click', (event) => {
                    if (autocompletePointerHandled) return;
                    const button = event.target.closest('[data-hei-autocomplete-value]');
                    if (!button) return;
                    applyAutocompleteSuggestion(button.getAttribute('data-hei-autocomplete-value') || '');
                });
                root.appendChild(autocompletePanel);

                if (root.dataset.clearDraft === '1') {
                    localStorage.removeItem(draftKey);
                }

                if (root.dataset.savedIntakeId && sessionStorage.getItem(lastSavedKey) !== root.dataset.savedIntakeId) {
                    const nextCount = (Number.parseInt(sessionStorage.getItem(countKey) || '0', 10) || 0) + 1;
                    sessionStorage.setItem(countKey, String(nextCount));
                    sessionStorage.setItem(lastSavedKey, root.dataset.savedIntakeId);
                }

                const refreshSessionCount = () => {
                    if (!sessionCount) return;
                    sessionCount.textContent = sessionStorage.getItem(countKey) || '0';
                };

                const parseJson = (value, fallback = null) => {
                    try {
                        return JSON.parse(value);
                    } catch (error) {
                        return fallback;
                    }
                };
                editPayload = parseJson(root.dataset.editPayload || 'null', null);

                const escapeHtml = (value) => String(value ?? '')
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
                const normalizeVariantValue = (value) => String(value || '').trim().replace(/\s+/g, ' ');
                const variantKey = (value) => normalizeVariantValue(value).toLocaleLowerCase();
                const mappedCards = () => Array.from(mapGroups.querySelectorAll('[data-hei-map-group]'));
                const defaultMainAxis = 'Colour';
                const defaultSubAxis = '';
                const validStatus = (value) => ['known', 'not_known', 'not_sure'].includes(value) ? value : 'known';
                const statusInput = (field) => root.querySelector(`[data-status-input="${field}"]`);
                const statusTarget = (field) => {
                    if (field === 'catalogue_style') return styleSelect;
                    if (field === 'product_type') return productType;
                    if (field === 'style_family') return styleName;
                    return null;
                };
                const statusValue = (field) => validStatus(statusInput(field)?.value || 'known');
                const setFieldStatus = (field, value, shouldSave = true) => {
                    const nextValue = validStatus(value);
                    const input = statusInput(field);
                    const target = statusTarget(field);
                    const wrapper = root.querySelector(`[data-status-field="${field}"]`);

                    if (input) input.value = nextValue;
                    root.querySelectorAll(`[data-status-set="${field}"]`).forEach((button) => {
                        button.classList.toggle('active', button.getAttribute('data-status-value') === nextValue);
                    });

                    if (nextValue === 'not_known') {
                        if (field === 'catalogue_style') {
                            if (styleId) styleId.value = '';
                            if (productTypeId) productTypeId.value = '';
                            if (styleSelect) styleSelect.value = '';
                        } else if (target) {
                            target.value = '';
                        }
                    }

                    if (target) {
                        target.toggleAttribute('readonly', nextValue === 'not_known' && target.tagName !== 'SELECT');
                        target.toggleAttribute('disabled', nextValue === 'not_known' && target.tagName === 'SELECT');
                    }
                    wrapper?.classList.toggle('is-not-known', nextValue === 'not_known');

                    if (shouldSave) {
                        saveDraft();
                    }
                };
                const splitVariantValues = (value) => String(value || '')
                    .split(/[,;]+/)
                    .map((item) => normalizeVariantValue(item))
                    .filter(Boolean)
                    .filter((item, index, list) => list.findIndex((candidate) => variantKey(candidate) === variantKey(item)) === index);
                const classificationValues = () => Array.from(classificationList?.querySelectorAll('[data-classification-chip]') || [])
                    .map((chip) => normalizeVariantValue(chip.dataset.classificationValue || ''))
                    .filter(Boolean);
                const syncClassification = () => {
                    if (classificationPath) {
                        classificationPath.value = JSON.stringify(classificationValues());
                    }
                    saveDraft();
                };
                const renderClassificationChip = (value) => {
                    if (!classificationList) return;
                    const chip = document.createElement('span');
                    chip.className = 'hei-v2-path-chip';
                    chip.dataset.classificationChip = '1';
                    chip.dataset.classificationValue = value;
                    chip.innerHTML = `
                        <span>${classificationValues().length + 1}</span>
                        <strong>${escapeHtml(value)}</strong>
                        <button type="button" data-remove-classification aria-label="Remove ${escapeHtml(value)}">x</button>
                    `;
                    classificationList.appendChild(chip);
                };
                const renumberClassification = () => {
                    Array.from(classificationList?.querySelectorAll('[data-classification-chip]') || []).forEach((chip, index) => {
                        const number = chip.querySelector('span:first-child');
                        if (number) number.textContent = String(index + 1);
                    });
                };
                const addClassificationValue = (raw, shouldSync = true) => {
                    const value = normalizeVariantValue(raw);
                    if (!value || classificationValues().some((item) => variantKey(item) === variantKey(value))) {
                        return false;
                    }
                    if (classificationAccordion) {
                        classificationAccordion.open = true;
                    }
                    renderClassificationChip(value);
                    renumberClassification();
                    if (shouldSync) syncClassification();
                    return true;
                };
                const setClassification = (items) => {
                    if (!classificationList) return;
                    classificationList.innerHTML = '';
                    (Array.isArray(items) ? items : [])
                        .map((item) => normalizeVariantValue(item))
                        .filter(Boolean)
                        .filter((item, index, list) => list.findIndex((candidate) => variantKey(candidate) === variantKey(item)) === index)
                        .forEach((item) => addClassificationValue(item, false));
                    renumberClassification();
                    if (classificationAccordion) {
                        classificationAccordion.open = classificationValues().length > 0;
                    }
                    if (classificationPath) {
                        classificationPath.value = JSON.stringify(classificationValues());
                    }
                };

                const selectedBrand = () => brandData.find((brand) => String(brand.id) === String(brandSelect.value));
                const selectedStore = () => storeData.find((store) => String(store.id) === String(storeSelect?.value || '')) || null;
                const selectedSection = () => (selectedStore()?.sections || [])
                    .find((section) => String(section.id) === String(sectionSelect?.value || '')) || null;
                const brandStyles = () => (selectedBrand()?.product_types || []).flatMap((type) =>
                    (type.styles || []).map((style) => ({
                        ...style,
                        product_type_id: type.id,
                        product_type_name: type.name,
                    })),
                );
                const selectedStyle = () => {
                    const wantedStyleId = String(styleId?.value || styleSelect?.value || '');
                    if (!wantedStyleId) return null;

                    return brandStyles().find((style) => String(style.id) === wantedStyleId) || null;
                };
                const setSelectOptions = (select, items, placeholder, current = '') => {
                    if (!select) return;
                    select.innerHTML = `<option value="">${escapeHtml(placeholder)}</option>`;
                    (items || []).forEach((item) => {
                        select.insertAdjacentHTML('beforeend', `<option value="${item.id}">${escapeHtml(item.name)}</option>`);
                    });
                    select.value = current && (items || []).some((item) => String(item.id) === String(current)) ? String(current) : '';
                };
                const rebuildLocationSelects = (preferred = {}) => {
                    const storeValue = preferred.store_id ?? storeSelect?.value ?? '';
                    setSelectOptions(storeSelect, storeData, 'Choose store...', storeValue);

                    const store = selectedStore();
                    const sectionValue = preferred.section_id ?? sectionSelect?.value ?? '';
                    setSelectOptions(sectionSelect, store?.sections || [], store ? 'Choose section...' : 'Choose store first...', sectionValue);

                    const section = selectedSection();
                    const subsectionValue = preferred.subsection_id ?? subsectionSelect?.value ?? '';
                    setSelectOptions(subsectionSelect, section?.subsections || [], section ? 'Choose subsection...' : 'Choose section first...', subsectionValue);
                    if (locationAccordion) {
                        locationAccordion.open = Boolean(storeSelect?.value || sectionSelect?.value || subsectionSelect?.value || shelfLocation?.value);
                    }
                };
                const applyLocationSelection = (options = {}) => {
                    if (locationAccordion) {
                        locationAccordion.open = true;
                    }
                    if (options.resetSection) {
                        if (sectionSelect) sectionSelect.value = '';
                        if (subsectionSelect) subsectionSelect.value = '';
                    } else if (options.resetSubsection && subsectionSelect) {
                        subsectionSelect.value = '';
                    }
                    rebuildLocationSelects();
                    saveDraft();
                    saveSessionPrefs();
                };
                const normalisedAxisName = (value) => normalizeVariantValue(value).toLocaleLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
                const styleVariantsWithValues = () => (selectedStyle()?.variants || [])
                    .map((variant) => ({
                        ...variant,
                        options: (variant.options || [])
                            .map((option) => ({
                                ...option,
                                label: normalizeVariantValue(option.label || option.value || ''),
                            }))
                            .filter((option) => option.label),
                    }))
                    .filter((variant) => variant.name && variant.options.length);
                const findVariantByAxis = (variants, axisName) => {
                    const wanted = normalisedAxisName(axisName);
                    if (!wanted) return null;

                    return variants.find((variant) => normalisedAxisName(variant.name) === wanted) || null;
                };
                const fallbackMainVariant = (variants) =>
                    findVariantByAxis(variants, 'Length') ||
                    findVariantByAxis(variants, 'Size') ||
                    findVariantByAxis(variants, 'Pack count') ||
                    variants[0] ||
                    null;
                const fallbackSubVariant = (variants, mainVariant) =>
                    findVariantByAxis(variants.filter((variant) => variant !== mainVariant), 'Colour') ||
                    variants.find((variant) => variant !== mainVariant) ||
                    null;
                const brandVariantsWithValues = () => {
                    const grouped = new Map();

                    brandStyles().forEach((style) => {
                        (style.variants || []).forEach((variant) => {
                            const axisName = normalizeVariantValue(variant.name || '');
                            if (!axisName) return;
                            const key = normalisedAxisName(axisName);
                            if (!key) return;

                            if (!grouped.has(key)) {
                                grouped.set(key, {
                                    id: `brand-${key}`,
                                    name: axisName,
                                    variant_type: variant.variant_type || 'text',
                                    options: [],
                                    _seen: new Set(),
                                });
                            }

                            const target = grouped.get(key);
                            (variant.options || []).forEach((option) => {
                                const label = normalizeVariantValue(option.label || option.value || '');
                                if (!label) return;
                                const optionKey = variantKey(label);
                                if (target._seen.has(optionKey)) return;
                                target._seen.add(optionKey);
                                target.options.push({
                                    id: option.id,
                                    label,
                                    value: option.value || label,
                                });
                            });
                        });
                    });

                    return Array.from(grouped.values())
                        .map((variant) => {
                            delete variant._seen;
                            return {
                                ...variant,
                                options: variant.options.sort((a, b) => a.label.localeCompare(b.label, undefined, { numeric: true, sensitivity: 'base' })),
                            };
                        })
                        .filter((variant) => variant.options.length)
                        .sort((a, b) => {
                            const rank = (name) => {
                                const n = normalisedAxisName(name);
                                if (n === 'length') return 0;
                                if (n === 'colour' || n === 'color') return 1;
                                if (n === 'pack count') return 2;
                                return 9;
                            };
                            return rank(a.name) - rank(b.name) || a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
                        });
                };
                const suggestionVariantsWithValues = () => {
                    const styleVariants = styleVariantsWithValues();
                    return styleVariants.length ? styleVariants : brandVariantsWithValues();
                };
                const suggestionVariantScopeLabel = () => selectedStyle()?.name || selectedBrand()?.name || 'Selected brand';
                const axisAliases = (axisName) => {
                    const axis = normalisedAxisName(axisName);
                    if (axis === 'color') return ['colour', 'color'];
                    if (axis === 'colour') return ['colour', 'color'];
                    return [axis];
                };
                const valueSuggestionsForAxis = (axisName) => {
                    const aliases = axisAliases(axisName);
                    if (aliases.length === 0 || !aliases[0]) return [];

                    const seen = new Set();
                    return suggestionVariantsWithValues()
                        .filter((variant) => aliases.includes(normalisedAxisName(variant.name)))
                        .flatMap((variant) => variant.options || [])
                        .map((option) => normalizeVariantValue(option.label || option.value || ''))
                        .filter((label) => {
                            if (!label) return false;
                            const key = variantKey(label);
                            if (seen.has(key)) return false;
                            seen.add(key);
                            return true;
                        })
                        .sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
                };
                const axisNameForAutocompleteInput = (input) => {
                    const commonCard = input.closest('[data-hei-common-variant]');
                    if (commonCard) {
                        return commonCard.querySelector('[data-hei-common-name]')?.value || '';
                    }

                    if (input.matches('[data-hei-map-main-value]')) {
                        return mainAxis?.value || defaultMainAxis;
                    }

                    const mapCard = input.closest('[data-hei-map-group]');
                    return mapCard?.querySelector('[data-hei-map-sub-axis]')?.value || defaultSubAxis;
                };
                const refreshVariantValueSuggestions = (input) => {
                    if (!input) return;
                    const typedKey = variantKey(input.value || '');
                    const values = valueSuggestionsForAxis(axisNameForAutocompleteInput(input))
                        .filter((value) => !typedKey || variantKey(value).startsWith(typedKey))
                        .slice(0, 36);

                    if (variantValueSuggestions) {
                        variantValueSuggestions.innerHTML = values
                            .map((value) => `<option value="${escapeHtml(value)}"></option>`)
                            .join('');
                    }

                    if (values.length === 0) {
                        autocompletePanel.hidden = true;
                        activeAutocompleteInput = null;
                        return;
                    }

                    activeAutocompleteInput = input;
                    const host = input.closest('.hei-variant-value-box') || input.closest('.hei-field') || input.parentElement || root;
                    if (autocompletePanel.parentElement !== host) {
                        host.appendChild(autocompletePanel);
                    }

                    autocompletePanel.innerHTML = `
                        <div class="hei-v2-autocomplete-label">${escapeHtml(axisNameForAutocompleteInput(input))} suggestions</div>
                        <div class="hei-v2-autocomplete-options">
                            ${values.map((value) => `<button type="button" class="hei-v2-autocomplete-option" data-hei-autocomplete-value="${escapeHtml(value)}">${escapeHtml(value)}</button>`).join('')}
                        </div>
                    `;
                    autocompletePanel.hidden = false;
                };
                const refreshActiveVariantValueSuggestions = () => {
                    const input = document.activeElement?.closest?.('[data-hei-variant-values], [data-hei-map-main-value]');
                    if (input) refreshVariantValueSuggestions(input);
                };
                const hideVariantAutocomplete = () => {
                    autocompletePanel.hidden = true;
                    activeAutocompleteInput = null;
                };

                const showVariantWarning = (card, message) => {
                    const warning = card.querySelector('[data-hei-variant-warning]');
                    if (!warning) return;
                    warning.textContent = message;
                    warning.hidden = false;
                    clearTimeout(warning._hideTimer);
                    warning._hideTimer = setTimeout(() => {
                        warning.hidden = true;
                    }, 2200);
                };

                const variantValuesForCard = (card) =>
                    Array.from(card.querySelectorAll('[data-hei-variant-chip]'))
                        .map((chip) => normalizeVariantValue(chip.dataset.heiVariantChip || chip.textContent || ''))
                        .filter(Boolean);

                const renderVariantChip = (card, value) => {
                    const list = card.querySelector('[data-hei-variant-chip-list]');
                    if (!list) return;
                    const chip = document.createElement('button');
                    chip.type = 'button';
                    chip.className = 'hei-variant-chip';
                    chip.dataset.heiVariantChip = value;
                    chip.innerHTML = `<span>${escapeHtml(value)}</span><strong aria-hidden="true">x</strong>`;
                    list.appendChild(chip);
                };

                const addVariantValue = (card, rawValue, warnOnDuplicate = true) => {
                    const value = normalizeVariantValue(rawValue);
                    if (!value) return false;

                    const exists = variantValuesForCard(card).some((existing) => variantKey(existing) === variantKey(value));
                    if (exists) {
                        if (warnOnDuplicate) {
                            showVariantWarning(card, `${value} is already added.`);
                        }
                        return false;
                    }

                    renderVariantChip(card, value);
                    return true;
                };

                const consumeVariantInput = (input, forceCommit = false) => {
                    const card = input.closest('.hei-variant-card');
                    if (!card) return false;

                    const raw = input.value || '';
                    const hasSeparator = raw.includes(',');
                    if (!forceCommit && !hasSeparator) {
                        return false;
                    }

                    const splitParts = raw.split(',');
                    const parts = (forceCommit ? [raw] : splitParts.slice(0, -1))
                        .map((value) => normalizeVariantValue(value))
                        .filter(Boolean);
                    const remainder = forceCommit ? '' : splitParts.slice(-1)[0] || '';

                    if (parts.length === 0) {
                        input.value = remainder.trimStart();
                        refreshVariantValueSuggestions(input);
                        return false;
                    }

                    let added = false;
                    parts.forEach((part) => {
                        added = addVariantValue(card, part, true) || added;
                    });
                    input.value = remainder.trimStart();

                    if (added) {
                        syncRows();
                    }

                    refreshVariantValueSuggestions(input);

                    return added;
                };

                const applyAutocompleteSuggestion = (value) => {
                    const input = activeAutocompleteInput;
                    if (!input || !document.documentElement.contains(input)) return;

                    if (input.matches('[data-hei-variant-values]')) {
                        const card = input.closest('.hei-variant-card');
                        if (!card) return;

                        if (addVariantValue(card, value, true)) {
                            input.value = '';
                            syncRows();
                        }
                        input.focus();
                        refreshVariantValueSuggestions(input);
                        return;
                    }

                    input.value = value;
                    syncRows();
                    hideVariantAutocomplete();
                    input.focus();
                };

                const rowPayload = (card) => ({
                    main_value: normalizeVariantValue(card.querySelector('[data-hei-map-main-value]')?.value || ''),
                    sub_axis: normalizeVariantValue(card.querySelector('[data-hei-map-sub-axis]')?.value || '') || defaultSubAxis,
                    sub_values: variantValuesForCard(card),
                    notes: '',
                });

                const commonPayload = (card) => ({
                    name: normalizeVariantValue(card.querySelector('[data-hei-common-name]')?.value || ''),
                    values: variantValuesForCard(card),
                });

                const saveDraft = () => {
                    localStorage.setItem(draftKey, JSON.stringify({
                        brand_catalogue_brand_id: brandSelect.value,
                        brand_name: brandName.value,
                        brand_catalogue_product_type_id: productTypeId?.value || '',
                        brand_catalogue_style_id: styleId?.value || '',
                        product_type_name: productType.value,
                        catalogue_style_status: statusValue('catalogue_style'),
                        product_type_status: statusValue('product_type'),
                        style_family_status: statusValue('style_family'),
                        classification_path: classificationValues(),
                        store_id: storeSelect?.value || '',
                        section_id: sectionSelect?.value || '',
                        subsection_id: subsectionSelect?.value || '',
                        shelf_location: shelfLocation?.value || '',
                        style_name: styleName.value,
                        visible_text_notes: notes.value,
                        variant_main_axis: mainAxis.value,
                        rows: mappedCards().map(rowPayload),
                        common_rows: Array.from(commonVariantList?.querySelectorAll('[data-hei-common-variant]') || []).map(commonPayload),
                    }));
                };

                const saveSessionPrefs = () => {
                    localStorage.setItem(sessionKey, JSON.stringify({
                        keep_brand: keepBrand?.checked ?? true,
                        keep_type: keepType?.checked ?? false,
                        keep_shelf: keepShelf?.checked ?? true,
                        variant_mode_2d: variantModeToggle?.checked ?? false,
                        brand_catalogue_brand_id: (keepBrand?.checked ?? true) ? brandSelect.value : '',
                        brand_name: (keepBrand?.checked ?? true) ? brandName.value : '',
                        product_type_name: (keepType?.checked ?? false) ? productType.value : '',
                        store_id: (keepShelf?.checked ?? true) ? (storeSelect?.value || '') : '',
                        section_id: (keepShelf?.checked ?? true) ? (sectionSelect?.value || '') : '',
                        subsection_id: (keepShelf?.checked ?? true) ? (subsectionSelect?.value || '') : '',
                        shelf_location: (keepShelf?.checked ?? true) ? (shelfLocation?.value || '') : '',
                    }));
                };

                const updateVariantMode = () => {
                    if (!variantModeToggle || !variantMappingContainer) return;
                    if (variantModeToggle.checked) {
                        variantMappingContainer.classList.remove('hei-1d-mode');
                        if (mainAxis) mainAxis.placeholder = 'Main variant (e.g. Length, Size)';
                    } else {
                        variantMappingContainer.classList.add('hei-1d-mode');
                        if (mainAxis) mainAxis.placeholder = 'Variant (e.g. Colour, Length)';
                        
                        // If switching to 1D mode, clear headers of existing cards
                        mappedCards().forEach((card, index) => {
                            if (index > 0) {
                                card.remove();
                            } else {
                                const mainInput = card.querySelector('[data-hei-map-main-value]');
                                const subInput = card.querySelector('[data-hei-map-sub-axis]');
                                if (mainInput) mainInput.value = '';
                                if (subInput) subInput.value = '';
                            }
                        });
                        syncRows();
                    }
                };

                if (variantModeToggle) {
                    variantModeToggle.addEventListener('change', () => {
                        updateVariantMode();
                        saveSessionPrefs();
                    });
                }

                const updateRemoveButtons = () => {
                    const cards = mappedCards();
                    cards.forEach((card) => {
                        const button = card.querySelector('[data-hei-remove-map-group]');
                        if (button) {
                            button.disabled = false;
                        }
                    });
                };

                const syncRows = () => {
                    const payload = [];
                    mappedCards().forEach(card => {
                        const row = rowPayload(card);
                        if (!row.main_value && row.sub_values.length > 0) {
                            // 1D Mode: User left main value empty, treat sub_values as main_values
                            row.sub_values.forEach(val => {
                                payload.push({
                                    main_value: val,
                                    sub_axis: '',
                                    sub_values: [],
                                    notes: ''
                                });
                            });
                        } else if (row.main_value || row.sub_values.length > 0) {
                            payload.push(row);
                        }
                    });

                    const commonPayloadRows = Array.from(commonVariantList?.querySelectorAll('[data-hei-common-variant]') || [])
                        .map(commonPayload)
                        .filter((row) => row.name || row.values.length);
                    const mappedCount = payload.reduce((sum, row) => sum + Math.max(1, row.sub_values.length), 0);

                    hiddenRows.value = JSON.stringify(payload);
                    if (hiddenCommonRows) {
                        hiddenCommonRows.value = JSON.stringify(commonPayloadRows);
                    }
                    totalCount.innerHTML = `
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: inline-block; vertical-align: text-bottom; margin-right: 0.25rem;">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        ${mappedCount} variant${mappedCount === 1 ? '' : 's'}${commonPayloadRows.length ? ` + ${commonPayloadRows.length} common` : ''}
                    `;
                    updateRemoveButtons();
                    saveDraft();

                    return payload;
                };

                const addMappedVariantGroup = (group = { main_value: '', sub_axis: defaultSubAxis, sub_values: [] }) => {
                    const card = document.createElement('div');
                    card.className = 'hei-map-group-card hei-variant-card';
                    card.dataset.heiMapGroup = '1';
                    card.innerHTML = `
                        <div class="hei-map-card-header">
                            <div class="hei-map-card-row">
                                <label class="hei-field hei-map-main-field">
                                    <span class="hei-field-label-mini">Group Value (Optional)</span>
                                    <input type="text" data-hei-map-main-value value="${escapeHtml(group.main_value || '')}" placeholder="e.g. 20&quot;" enterkeyhint="next" autocomplete="off">
                                </label>
                                <div class="hei-map-arrow" aria-hidden="true">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                                </div>
                                <label class="hei-field hei-map-sub-field">
                                    <span class="hei-field-label-mini">Sub-Axis (Optional)</span>
                                    <input type="text" list="hei-variant-group-names" data-hei-map-sub-axis value="${escapeHtml(group.sub_axis || '')}" placeholder="e.g. Colour" enterkeyhint="next">
                                </label>
                            </div>
                            <button type="button" class="hei-variant-remove-btn hei-icon-btn" data-hei-remove-map-group aria-label="Remove" title="Remove">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            </button>
                        </div>
                        <div class="hei-map-card-body">
                            <div class="hei-field">
                                <span class="hei-field-label-mini">Variant Values</span>
                                <div class="hei-variant-value-box">
                                    <div class="hei-variant-chip-list" data-hei-variant-chip-list></div>
                                    <input type="text" data-hei-variant-values placeholder="Type values and press comma..." enterkeyhint="done" autocomplete="off">
                                </div>
                                <small class="hei-variant-warning" data-hei-variant-warning hidden></small>
                            </div>
                            <details class="hei-mini-accordion">
                                <summary>Sub Variant Presets</summary>
                                <div class="hei-variant-presets" role="group">
                                    ${commonVariantGroups.map((preset) => `<button type="button" class="hei-chip-add" data-hei-map-sub-axis-preset="${escapeHtml(preset)}">${escapeHtml(preset)}</button>`).join('')}
                                </div>
                            </details>
                        </div>
                    `;
                    mapGroups.appendChild(card);
                    (group.sub_values || []).forEach((value) => addVariantValue(card, value, false));
                    updateRemoveButtons();
                    syncRows();
                };

                const setRows = (items) => {
                    mapGroups.innerHTML = '';
                    const cleanItems = Array.isArray(items) ? items.filter((item) => item && typeof item === 'object') : [];

                    if (cleanItems.length === 0) {
                        addMappedVariantGroup();
                        return;
                    }

                    const is1D = cleanItems.length > 0 && cleanItems.every(item => item.main_value && (!item.sub_values || item.sub_values.length === 0));
                    
                    if (is1D) {
                        addMappedVariantGroup({
                            main_value: '',
                            sub_axis: '',
                            sub_values: cleanItems.map(item => item.main_value)
                        });
                        return;
                    }

                    cleanItems.forEach((item) => addMappedVariantGroup({
                        main_value: item.main_value || '',
                        sub_axis: item.sub_axis || defaultSubAxis,
                        sub_values: Array.isArray(item.sub_values) ? item.sub_values : [],
                    }));
                };

                const addCommonVariantGroup = (group = { name: '', values: [] }) => {
                    const card = document.createElement('div');
                    card.className = 'hei-common-variant-card hei-variant-card';
                    card.dataset.heiCommonVariant = '1';
                    const defaultName = group.name || 'Pack count';
                    card.innerHTML = `
                        <div class="hei-map-card-header">
                            <div class="hei-map-card-row">
                                <label class="hei-field hei-variant-name-field">
                                    <span class="hei-field-label-mini">Common Variant Axis</span>
                                    <input type="text" list="hei-variant-group-names" data-hei-common-name value="${escapeHtml(defaultName)}" placeholder="e.g. Pack count">
                                </label>
                            </div>
                            <button type="button" class="hei-variant-remove-btn hei-icon-btn" data-hei-remove-common-variant aria-label="Remove common variant" title="Remove">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            </button>
                        </div>
                        <div class="hei-map-card-body">
                            <div class="hei-field">
                                <span class="hei-field-label-mini">Common Variant Values</span>
                                <div class="hei-variant-value-box">
                                    <div class="hei-variant-chip-list" data-hei-variant-chip-list></div>
                                    <input type="text" data-hei-variant-values placeholder="Type values and press comma..." enterkeyhint="done" autocomplete="off">
                                </div>
                                <small class="hei-variant-warning" data-hei-variant-warning hidden></small>
                            </div>
                        </div>
                    `;
                    commonVariantList?.appendChild(card);
                    (group.values || []).forEach((value) => addVariantValue(card, value, false));
                    syncRows();
                };

                const setCommonRows = (items) => {
                    if (!commonVariantList) return;
                    commonVariantList.innerHTML = '';
                    (Array.isArray(items) ? items : [])
                        .filter((item) => item && typeof item === 'object')
                        .forEach((item) => addCommonVariantGroup({
                            name: item.name || '',
                            values: Array.isArray(item.values) ? item.values : [],
                        }));
                    syncRows();
                };

                const rebuildStyleSelect = () => {
                    if (!styleSelect) return;
                    const current = styleId?.value || styleSelect.value || '';
                    styleSelect.innerHTML = '<option value="">Style from catalogue (optional)</option>';
                    brandStyles().forEach((style) => {
                        const label = style.product_type_name ? `${style.product_type_name} - ${style.name}` : style.name;
                        styleSelect.insertAdjacentHTML(
                            'beforeend',
                            `<option value="${style.id}" data-product-type-id="${style.product_type_id || ''}" data-product-type-name="${escapeHtml(style.product_type_name || '')}">${escapeHtml(label)}</option>`,
                        );
                    });
                    styleSelect.value = current && brandStyles().some((style) => String(style.id) === String(current)) ? String(current) : '';
                };

                const renderCatalogueVariantAssist = () => {
                    if (!catalogueVariantPanel || !catalogueVariantGrid) return;
                    const scopeLabel = suggestionVariantScopeLabel();
                    const variants = suggestionVariantsWithValues();

                    if (!selectedBrand() || variants.length === 0) {
                        catalogueVariantPanel.hidden = true;
                        catalogueVariantPanel.open = false;
                        catalogueVariantGrid.innerHTML = '';
                        return;
                    }

                    if (catalogueVariantTitle) {
                        const count = variants.reduce((sum, variant) => sum + variant.options.length, 0);
                        catalogueVariantTitle.textContent = `${scopeLabel} variants (${count})`;
                    }
                    if (catalogueVariantHint) {
                        catalogueVariantHint.textContent = selectedStyle()
                            ? 'Style-specific variants from the database.'
                            : 'Brand-level suggestions from all styles under this brand. Choose only what you see.';
                    }

                    catalogueVariantGrid.innerHTML = variants.map((variant) => `
                        <section class="hei-catalogue-variant-axis">
                            <div class="hei-catalogue-variant-axis-head">
                                <strong>${escapeHtml(variant.name)}</strong>
                                <span>${variant.options.length}</span>
                            </div>
                            <div class="hei-variant-presets">
                                ${variant.options.map((option) => `
                                    <button type="button" class="hei-chip-add" data-hei-catalogue-variant-value="${escapeHtml(option.label)}" data-hei-catalogue-variant-axis="${escapeHtml(variant.name)}">${escapeHtml(option.label)}</button>
                                `).join('')}
                            </div>
                        </section>
                    `).join('');
                    catalogueVariantPanel.hidden = false;
                };

                const lastMappedCardOrCreate = (subAxis = defaultSubAxis) => {
                    let cards = mappedCards();
                    if (cards.length === 0) {
                        addMappedVariantGroup({ main_value: '', sub_axis: subAxis, sub_values: [] });
                        cards = mappedCards();
                    }

                    return cards.at(-1) || null;
                };

                const commonCardForAxis = (axisName) => {
                    const wanted = normalisedAxisName(axisName);
                    let card = Array.from(commonVariantList?.querySelectorAll('[data-hei-common-variant]') || [])
                        .find((candidate) => normalisedAxisName(candidate.querySelector('[data-hei-common-name]')?.value || '') === wanted);

                    if (!card) {
                        addCommonVariantGroup({ name: axisName || 'Pack count', values: [] });
                        card = Array.from(commonVariantList?.querySelectorAll('[data-hei-common-variant]') || []).at(-1) || null;
                    }

                    return card;
                };

                const applyCatalogueVariantValue = (axisName, value) => {
                    const variants = suggestionVariantsWithValues();
                    if (variants.length === 0) return;

                    const mainVariant = findVariantByAxis(variants, mainAxis?.value || '') || fallbackMainVariant(variants);
                    const subVariant = fallbackSubVariant(variants, mainVariant);
                    const isMainAxis = mainVariant && normalisedAxisName(axisName) === normalisedAxisName(mainVariant.name);
                    const isSubAxis = subVariant && normalisedAxisName(axisName) === normalisedAxisName(subVariant.name);

                    if (isMainAxis) {
                        if (mainAxis && !mainAxis.value) {
                            mainAxis.value = mainVariant.name;
                        }
                        addMappedVariantGroup({
                            main_value: value,
                            sub_axis: subVariant?.name || defaultSubAxis,
                            sub_values: [],
                        });
                    } else if (isSubAxis || !subVariant) {
                        if (mainAxis && !mainAxis.value && mainVariant) {
                            mainAxis.value = mainVariant.name;
                        }
                        const card = lastMappedCardOrCreate(axisName || subVariant?.name || defaultSubAxis);
                        const subAxisInput = card?.querySelector('[data-hei-map-sub-axis]');
                        if (subAxisInput && axisName) {
                            subAxisInput.value = axisName;
                        }
                        if (card) {
                            addVariantValue(card, value, true);
                        }
                    } else {
                        const card = commonCardForAxis(axisName);
                        if (card) {
                            addVariantValue(card, value, true);
                        }
                    }

                    syncRows();
                };

                const applyCatalogueVariantMatrix = () => {
                    const variants = suggestionVariantsWithValues();
                    if (variants.length === 0) return false;

                    const mainVariant = findVariantByAxis(variants, mainAxis?.value || '') || fallbackMainVariant(variants);
                    const subVariant = fallbackSubVariant(variants, mainVariant);
                    if (!mainVariant) return false;

                    if (mainAxis) {
                        mainAxis.value = mainVariant.name;
                    }
                    mapGroups.innerHTML = '';

                    const subValues = subVariant?.options?.map((option) => option.label) || [];
                    mainVariant.options.forEach((option) => {
                        addMappedVariantGroup({
                            main_value: option.label,
                            sub_axis: subVariant?.name || defaultSubAxis,
                            sub_values: subValues,
                        });
                    });

                    const extraCommonVariants = variants
                        .filter((variant) => variant !== mainVariant && variant !== subVariant)
                        .map((variant) => ({
                            name: variant.name,
                            values: variant.options.map((option) => option.label),
                        }));
                    setCommonRows(extraCommonVariants);

                    catalogueVariantPanel.open = true;
                    syncRows();
                    return true;
                };

                const parseQuickMap = (raw) => {
                    const str = String(raw || '').trim();
                    if (!str) return [];

                    // Check if it's a JSON payload from AI
                    if (str.startsWith('{') && str.endsWith('}')) {
                        try {
                            const data = JSON.parse(str);
                            
                            // Map JSON fields to form fields
                            if (data.brand_name) {
                                brandName.value = data.brand_name;
                                brandName.dataset.userEdited = '1';
                                // Try to match brand select
                                Array.from(brandSelect.options).forEach(opt => {
                                    if (opt.dataset.brandName && opt.dataset.brandName.toLowerCase() === data.brand_name.toLowerCase()) {
                                        brandSelect.value = opt.value;
                                    }
                                });
                            }
                            
                            if (data.product_type) {
                                productType.value = data.product_type;
                                setFieldStatus('product_type', 'known', false);
                            }
                            
                            if (data.style_family) {
                                styleName.value = data.style_family;
                                setFieldStatus('style_family', 'known', false);
                            }
                            
                            if (data.grouping_path) {
                                const pathParts = data.grouping_path.split('>').map(p => p.trim()).filter(Boolean);
                                if (pathParts.length > 0) {
                                    setClassification(pathParts);
                                }
                            }
                            
                            if (data.notes) {
                                notes.value = data.notes;
                            }
                            
                            if (data.shelf_area && data.shelf_area.toLowerCase() !== 'not sure') {
                                shelfLocation.value = data.shelf_area;
                            }
                            
                            // Handle variants
                            if (data.variants) {
                                if (data.variants.main_axis) {
                                    mainAxis.value = data.variants.main_axis;
                                }
                                
                                if (data.variants.common && data.variants.common.length > 0) {
                                    setCommonRows([{
                                        name: 'Common variant',
                                        values: data.variants.common
                                    }]);
                                }
                                
                                if (data.variants.main_value) {
                                    const subValues = data.variants.sub_values || [];
                                    return [{
                                        main_value: data.variants.main_value,
                                        sub_axis: data.variants.sub_axis || defaultSubAxis,
                                        sub_values: subValues
                                    }];
                                }
                                
                                // Support array of main values
                                if (data.variants.main_values && Array.isArray(data.variants.main_values)) {
                                    return data.variants.main_values.map(val => ({
                                        main_value: val,
                                        sub_axis: data.variants.sub_axis || defaultSubAxis,
                                        sub_values: []
                                    }));
                                }
                            }
                            
                            return [];
                        } catch (e) {
                            console.error('Failed to parse JSON', e);
                        }
                    }

                    // Fallback to standard line-by-line parsing
                    return str
                        .replaceAll('\r', '\n')
                        .split(/\n|\/+/)
                        .map((line) => normalizeVariantValue(line))
                        .filter(Boolean)
                        .map((line) => {
                            let mainValue = '';
                            let valuesRaw = '';
                            const explicit = line.match(/^(.+?)\s*[:=]\s*(.+)$/);
                            const loose = line.match(/^(.+?)\s+(.+[,;].+)$/);

                            if (explicit) {
                                mainValue = explicit[1];
                                valuesRaw = explicit[2];
                            } else if (loose) {
                                mainValue = loose[1];
                                valuesRaw = loose[2];
                            } else {
                                mainValue = line;
                            }

                            return {
                                main_value: normalizeVariantValue(mainValue),
                                sub_axis: mappedCards()[0]?.querySelector('[data-hei-map-sub-axis]')?.value || defaultSubAxis,
                                sub_values: splitVariantValues(valuesRaw),
                            };
                        })
                        .filter((row) => row.main_value || row.sub_values.length);
                };

                const showExistingPhoto = (url) => {
                    if (!coverPreview || !url) return;
                    coverPreview.innerHTML = '';
                    const img = document.createElement('img');
                    img.alt = 'Existing cover photo';
                    img.src = url;
                    coverPreview.appendChild(img);
                    if (coverRemove) coverRemove.style.display = 'flex';
                    if (removePhotoInput) removePhotoInput.value = '0';
                };

                const handleCoverPhotoFile = (file) => {
                    if (!file || !file.type.startsWith('image/')) return;
                    
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    coverPhoto.files = dt.files;
                    
                    const reader = new FileReader();
                    reader.onload = (e) => showExistingPhoto(e.target.result);
                    reader.readAsDataURL(file);
                };

                if (coverRemove) {
                    coverRemove.addEventListener('click', () => {
                        if (coverPhoto) coverPhoto.value = '';
                        if (removePhotoInput) removePhotoInput.value = '1';
                        if (coverPreview) {
                            coverPreview.innerHTML = `
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.4">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    <span>No photo</span>
                                </div>
                            `;
                        }
                        if (coverPasteZone) {
                            coverPasteZone.innerHTML = 'Paste copied image here<br><span style="font-size: 0.75rem; font-weight: 500; opacity: 0.7;">Tap here, then paste. On desktop use Ctrl+V.</span>';
                        }
                        coverRemove.style.display = 'none';
                    });
                }

                if (coverPhoto) {
                    coverPhoto.addEventListener('change', (e) => {
                        const file = e.target.files[0];
                        if (file) handleCoverPhotoFile(file);
                    });
                }

                if (coverPasteZone) {
                    coverPasteZone.addEventListener('paste', (e) => {
                        e.preventDefault();
                        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
                        for (const item of items) {
                            if (item.type.indexOf('image') === 0) {
                                const file = item.getAsFile();
                                handleCoverPhotoFile(file);
                                coverPasteZone.innerHTML = 'Image pasted successfully.<br><span style="font-size: 0.75rem; font-weight: 500; opacity: 0.7;">Paste again to replace.</span>';
                                break;
                            }
                        }
                    });

                    coverPasteZone.addEventListener('keydown', (e) => {
                        if (e.key !== 'v' && e.key !== 'V' && !e.ctrlKey && !e.metaKey) {
                            e.preventDefault();
                        }
                    });
                }

                const applyBrandSelection = (options = {}) => {
                    const selected = brandSelect.options[brandSelect.selectedIndex];
                    const selectedName = selected?.dataset.brandName || '';

                    if (selectedName && !brandName.dataset.userEdited) {
                        brandName.value = selectedName;
                    }
                    if (options.resetStyle) {
                        if (styleId) styleId.value = '';
                        if (productTypeId) productTypeId.value = '';
                    }
                    rebuildStyleSelect();
                    renderCatalogueVariantAssist();
                    saveDraft();
                    saveSessionPrefs();
                };

                if (brandSearch && brandSelect) {
                    const allBrandOptions = Array.from(brandSelect.options).filter(o => o.value !== '');
                    brandSearch.addEventListener('input', (e) => {
                        const term = e.target.value.toLowerCase();
                        const currentVal = brandSelect.value;
                        brandSelect.innerHTML = '<option value="">Select brand...</option>';
                        allBrandOptions.forEach(opt => {
                            if (opt.textContent.toLowerCase().includes(term)) {
                                brandSelect.appendChild(opt);
                            }
                        });
                        brandSelect.value = currentVal;
                        if (!brandSelect.value && brandSelect.options.length === 2 && term) {
                            brandSelect.selectedIndex = 1;
                            applyBrandSelection({ resetStyle: true });
                        }
                    });
                }

                const applyStyleSelection = () => {
                    const style = selectedStyle();
                    if (!style) {
                        if (styleId) styleId.value = '';
                        renderCatalogueVariantAssist();
                        syncRows();
                        return;
                    }

                    if (styleId) styleId.value = style.id;
                    if (productTypeId) productTypeId.value = style.product_type_id || '';
                    setFieldStatus('catalogue_style', 'known', false);
                    if (style.product_type_name && !productType.value) {
                        productType.value = style.product_type_name;
                        setFieldStatus('product_type', 'known', false);
                    }
                    styleName.value = style.name;
                    setFieldStatus('style_family', 'known', false);
                    renderCatalogueVariantAssist();
                    syncRows();
                    styleName.focus();
                };

                const restoreDraft = () => {
                    const oldRows = parseJson(root.dataset.oldRows || '[]', []);
                    const draft = parseJson(localStorage.getItem(draftKey) || 'null', null);
                    const session = parseJson(localStorage.getItem(sessionKey) || 'null', null);

                    if (!hasOldInput && editPayload) {
                        localStorage.removeItem(draftKey);
                        brandSelect.value = editPayload.brand_catalogue_brand_id || '';
                        brandName.value = editPayload.brand_name || '';
                        brandName.dataset.userEdited = brandName.value ? '1' : '';
                        if (productTypeId) productTypeId.value = editPayload.brand_catalogue_product_type_id || '';
                        if (styleId) styleId.value = '';
                        rebuildStyleSelect();
                        if (styleSelect) styleSelect.value = '';
                        productType.value = editPayload.product_type_name || '';
                        setFieldStatus('catalogue_style', 'not_known', false);
                        setFieldStatus('product_type', editPayload.product_type_status || 'known', false);
                        setFieldStatus('style_family', editPayload.style_family_status || 'known', false);
                        setClassification(editPayload.classification_path || []);
                        rebuildLocationSelects({
                            store_id: editPayload.store_id || '',
                            section_id: editPayload.section_id || '',
                            subsection_id: editPayload.subsection_id || '',
                        });
                        if (shelfLocation) shelfLocation.value = editPayload.shelf_location || '';
                        styleName.value = editPayload.style_name || '';
                        notes.value = editPayload.visible_text_notes || '';
                        mainAxis.value = editPayload.variant_main_axis || defaultMainAxis;
                        setRows(editPayload.rows || []);
                        setCommonRows(editPayload.common_rows || []);
                        renderCatalogueVariantAssist();
                        showExistingPhoto(editPayload.photo_url || '');
                        return;
                    }

                    if (!hasOldInput && draft) {
                        brandSelect.value = draft.brand_catalogue_brand_id || '';
                        brandName.value = draft.brand_name || '';
                        if (productTypeId) productTypeId.value = draft.brand_catalogue_product_type_id || '';
                        if (styleId) styleId.value = '';
                        rebuildStyleSelect();
                        if (styleSelect) styleSelect.value = '';
                        productType.value = draft.product_type_name || '';
                        setFieldStatus('catalogue_style', 'not_known', false);
                        setFieldStatus('product_type', draft.product_type_status || 'known', false);
                        setFieldStatus('style_family', draft.style_family_status || 'known', false);
                        setClassification(draft.classification_path || []);
                        rebuildLocationSelects({
                            store_id: draft.store_id || '',
                            section_id: draft.section_id || '',
                            subsection_id: draft.subsection_id || '',
                        });
                        if (shelfLocation) shelfLocation.value = draft.shelf_location || '';
                        styleName.value = draft.style_name || '';
                        notes.value = draft.visible_text_notes || '';
                        mainAxis.value = draft.variant_main_axis || defaultMainAxis;
                        
                        // Check if draft rows look like 1D mode (all have empty sub_axis and empty main_value)
                        const draftRows = draft.rows || [];
                        const looksLike1D = draftRows.length > 0 && draftRows.every(r => !r.sub_axis && !r.main_value);
                        if (variantModeToggle) {
                            variantModeToggle.checked = !looksLike1D;
                            updateVariantMode();
                        }
                        
                        setRows(draftRows);
                        setCommonRows(draft.common_rows || []);
                        renderCatalogueVariantAssist();
                        return;
                    }

                    mainAxis.value = mainAxis.value || defaultMainAxis;
                    setClassification(parseJson(root.dataset.oldClassificationPath || '[]', []));
                    setFieldStatus('catalogue_style', statusInput('catalogue_style')?.value || 'known', false);
                    setFieldStatus('product_type', statusInput('product_type')?.value || 'known', false);
                    setFieldStatus('style_family', statusInput('style_family')?.value || 'known', false);
                    rebuildLocationSelects({
                        store_id: root.dataset.oldStoreId || '',
                        section_id: root.dataset.oldSectionId || '',
                        subsection_id: root.dataset.oldSubsectionId || '',
                    });
                    rebuildStyleSelect();
                    if (styleSelect && styleId?.value) {
                        styleSelect.value = styleId.value;
                    }

                    if (!hasOldInput && session) {
                        keepBrand.checked = session.keep_brand ?? true;
                        keepType.checked = session.keep_type ?? false;
                        if (keepShelf) keepShelf.checked = session.keep_shelf ?? true;
                        if (variantModeToggle && session.variant_mode_2d !== undefined) {
                            variantModeToggle.checked = session.variant_mode_2d;
                            updateVariantMode();
                        }
                        brandSelect.value = session.brand_catalogue_brand_id || '';
                        brandName.value = session.brand_name || '';
                        productType.value = session.product_type_name || '';
                        rebuildLocationSelects({
                            store_id: session.store_id || '',
                            section_id: session.section_id || '',
                            subsection_id: session.subsection_id || '',
                        });
                        if (shelfLocation) shelfLocation.value = session.shelf_location || '';
                        rebuildStyleSelect();
                    }

                    rebuildLocationSelects({
                        store_id: storeSelect?.value || '',
                        section_id: sectionSelect?.value || '',
                        subsection_id: subsectionSelect?.value || '',
                    });
                    
                    const oldRowsParsed = oldRows;
                    const looksLike1DOld = oldRowsParsed.length > 0 && oldRowsParsed.every(r => !r.sub_axis && !r.main_value);
                    if (variantModeToggle && hasOldInput) {
                        variantModeToggle.checked = !looksLike1DOld;
                        updateVariantMode();
                    } else if (variantModeToggle && !hasOldInput && !session) {
                        // Default to 1D mode for new intakes
                        variantModeToggle.checked = false;
                        updateVariantMode();
                    }
                    
                    setRows(oldRowsParsed);
                    setCommonRows(parseJson(root.dataset.oldCommonRows || '[]', []));
                    if (!brandName.value) {
                        applyBrandSelection();
                    }
                    renderCatalogueVariantAssist();
                };

                brandSelect.addEventListener('change', () => applyBrandSelection({ resetStyle: true }));
                styleSelect?.addEventListener('change', applyStyleSelection);
                storeSelect?.addEventListener('change', () => applyLocationSelection({ resetSection: true }));
                sectionSelect?.addEventListener('change', () => applyLocationSelection({ resetSubsection: true }));
                subsectionSelect?.addEventListener('change', () => applyLocationSelection());
                brandName.addEventListener('input', () => {
                    brandName.dataset.userEdited = '1';
                    saveDraft();
                    saveSessionPrefs();
                });
                [productType, styleName, notes, mainAxis].forEach((field) => {
                    field.addEventListener('input', syncRows);
                });
                mainAxis.addEventListener('input', refreshActiveVariantValueSuggestions);
                [keepBrand, keepType, keepShelf, productType, shelfLocation].forEach((field) => {
                    field?.addEventListener('input', saveSessionPrefs);
                    field?.addEventListener('change', saveSessionPrefs);
                });
                addCommonVariantButton?.addEventListener('click', () => {
                    addCommonVariantGroup({ name: 'Pack count', values: [] });
                    commonVariantList?.querySelector('[data-hei-common-variant]:last-child [data-hei-common-name]')?.focus();
                });
                applyCatalogueVariantMatrixButton?.addEventListener('click', () => {
                    applyCatalogueVariantMatrix();
                });

                coverPhoto?.addEventListener('change', () => {
                    const file = coverPhoto.files?.[0];
                    coverPreview.innerHTML = '';

                    if (!file) {
                        coverPreview.innerHTML = `
                            <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.4">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                                <span>No photo</span>
                            </div>
                        `;
                        return;
                    }

                    const img = document.createElement('img');
                    img.alt = 'Selected cover photo preview';
                    img.src = URL.createObjectURL(file);
                    coverPreview.appendChild(img);
                });

                root.addEventListener('click', (event) => {
                    if (!event.target.closest('[data-hei-variant-values], [data-hei-map-main-value], [data-hei-v2-autocomplete]')) {
                        hideVariantAutocomplete();
                    }

                    const addClassificationButton = event.target.closest('[data-add-classification]');
                    if (addClassificationButton) {
                        if (addClassificationValue(classificationInput?.value || '')) {
                            classificationInput.value = '';
                        }
                        classificationInput?.focus();
                        return;
                    }

                    const statusButton = event.target.closest('[data-status-set]');
                    if (statusButton) {
                        setFieldStatus(
                            statusButton.getAttribute('data-status-set') || '',
                            statusButton.getAttribute('data-status-value') || 'known',
                        );
                        statusTarget(statusButton.getAttribute('data-status-set') || '')?.focus();
                        return;
                    }

                    const classificationPreset = event.target.closest('[data-classification-preset]');
                    if (classificationPreset) {
                        addClassificationValue(classificationPreset.getAttribute('data-classification-preset') || '');
                        return;
                    }

                    const removeClassification = event.target.closest('[data-remove-classification]');
                    if (removeClassification) {
                        removeClassification.closest('[data-classification-chip]')?.remove();
                        renumberClassification();
                        syncClassification();
                        return;
                    }

                    const chip = event.target.closest('[data-hei-variant-chip]');
                    if (chip) {
                        chip.remove();
                        syncRows();
                        return;
                    }

                    const mapAxisPreset = event.target.closest('[data-hei-map-axis-preset]');
                    if (mapAxisPreset) {
                        mainAxis.value = mapAxisPreset.dataset.heiMapAxisPreset || '';
                        syncRows();
                        mainAxis.focus();
                        return;
                    }

                    const catalogueVariantButton = event.target.closest('[data-hei-catalogue-variant-value]');
                    if (catalogueVariantButton) {
                        applyCatalogueVariantValue(
                            catalogueVariantButton.getAttribute('data-hei-catalogue-variant-axis') || '',
                            catalogueVariantButton.getAttribute('data-hei-catalogue-variant-value') || '',
                        );
                        return;
                    }

                    const subPreset = event.target.closest('[data-hei-vm-sub-preset]');
                    if (subPreset) {
                        mappedCards().forEach((card) => {
                            const input = card.querySelector('[data-hei-map-sub-axis]');
                            if (input) input.value = subPreset.dataset.heiVmSubPreset || '';
                        });
                        syncRows();
                        return;
                    }

                    const commonPreset = event.target.closest('[data-hei-vm-common-preset]');
                    if (commonPreset) {
                        const name = commonPreset.getAttribute('data-hei-vm-common-preset') || '';
                        const inputs = commonVariantList?.querySelectorAll('[data-hei-common-name]') ?? [];
                        if (inputs.length) {
                            inputs.forEach((input) => {
                                input.value = name;
                            });
                        } else {
                            addCommonVariantGroup({ name, values: [] });
                        }
                        syncRows();
                        commonVariantList?.querySelector('[data-hei-variant-values]')?.focus();
                        return;
                    }

                    const rowSubPreset = event.target.closest('[data-hei-map-sub-axis-preset]');
                    if (rowSubPreset) {
                        const card = rowSubPreset.closest('[data-hei-map-group]');
                        const input = card?.querySelector('[data-hei-map-sub-axis]');
                        if (input) {
                            input.value = rowSubPreset.dataset.heiMapSubAxisPreset || '';
                            syncRows();
                            card.querySelector('[data-hei-variant-values]')?.focus();
                        }
                        return;
                    }

                    const addButton = event.target.closest('[data-hei-add-map-group]');
                    if (addButton) {
                        addMappedVariantGroup({ main_value: '', sub_axis: defaultSubAxis, sub_values: [] });
                        mappedCards().at(-1)?.querySelector('[data-hei-map-main-value]')?.focus();
                        return;
                    }

                    const quickMapButton = event.target.closest('[data-quick-map-apply]');
                    if (quickMapButton) {
                        const parsed = parseQuickMap(quickMap?.value || '');
                        if (parsed.length) {
                            setRows(parsed);
                            quickMap.value = '';
                        } else if (quickMap) {
                            quickMap.focus();
                        }
                        return;
                    }

                    const removeButton = event.target.closest('[data-hei-remove-map-group]');
                    if (removeButton) {
                        removeButton.closest('[data-hei-map-group]')?.remove();
                        syncRows();
                        return;
                    }

                    const removeCommonButton = event.target.closest('[data-hei-remove-common-variant]');
                    if (removeCommonButton) {
                        removeCommonButton.closest('[data-hei-common-variant]')?.remove();
                        syncRows();
                    }
                });

                root.addEventListener('input', (event) => {
                    const autocompleteInput = event.target.closest('[data-hei-variant-values], [data-hei-map-main-value]');
                    if (autocompleteInput) {
                        refreshVariantValueSuggestions(autocompleteInput);
                    }

                    const valueInput = event.target.closest('[data-hei-variant-values]');
                    if (valueInput) {
                        consumeVariantInput(valueInput, false);
                        return;
                    }

                    if (event.target.closest('[data-hei-map-main-value], [data-hei-map-sub-axis], [data-hei-common-name]')) {
                        syncRows();
                        refreshActiveVariantValueSuggestions();
                    }

                    if (event.target.closest('[data-shelf-location]')) {
                        saveDraft();
                    }
                });

                root.addEventListener('focusin', (event) => {
                    const input = event.target.closest('[data-hei-variant-values], [data-hei-map-main-value]');
                    if (input) refreshVariantValueSuggestions(input);
                });

                root.addEventListener('focusout', (event) => {
                    if (!event.target.closest('[data-hei-variant-values], [data-hei-map-main-value]')) return;
                    window.setTimeout(() => {
                        if (!autocompletePanel.contains(document.activeElement)) {
                            hideVariantAutocomplete();
                        }
                    }, 120);
                });

                root.addEventListener('keydown', (event) => {
                    const pathInput = event.target.closest('[data-classification-input]');
                    if (pathInput && (event.key === 'Enter' || event.key === ',')) {
                        event.preventDefault();
                        if (addClassificationValue(pathInput.value || '')) {
                            pathInput.value = '';
                        }
                        return;
                    }

                    const input = event.target.closest('[data-hei-variant-values]');
                    if (!input) return;

                    if (event.key === 'Enter') {
                        event.preventDefault();
                        return;
                    }

                    if (event.key === ',') {
                        event.preventDefault();
                        consumeVariantInput(input, true);
                    }
                });

                root.querySelector('[data-clear-form]').addEventListener('click', () => {
                    const keptShelf = keepShelf?.checked ? {
                        store_id: storeSelect?.value || '',
                        section_id: sectionSelect?.value || '',
                        subsection_id: subsectionSelect?.value || '',
                        shelf_location: shelfLocation?.value || '',
                    } : null;
                    localStorage.removeItem(draftKey);
                    form.reset();
                    brandName.dataset.userEdited = '';
                    inlineError.textContent = '';
                    coverPreview.innerHTML = `
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.4">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                <polyline points="21 15 16 10 5 21"></polyline>
                            </svg>
                            <span>No photo</span>
                        </div>
                    `;
                    quickMap.value = '';
                    if (styleId) styleId.value = '';
                    if (productTypeId) productTypeId.value = '';
                    setClassification([]);
                    if (keptShelf) {
                        if (keepShelf) keepShelf.checked = true;
                        rebuildLocationSelects(keptShelf);
                        if (shelfLocation) shelfLocation.value = keptShelf.shelf_location || '';
                    } else {
                        rebuildLocationSelects();
                        if (shelfLocation) shelfLocation.value = '';
                    }
                    setFieldStatus('catalogue_style', 'known', false);
                    setFieldStatus('product_type', 'known', false);
                    setFieldStatus('style_family', 'known', false);
                    rebuildStyleSelect();
                    setRows([]);
                    setCommonRows([]);
                    renderCatalogueVariantAssist();
                });

                form.addEventListener('submit', (event) => {
                    inlineError.textContent = '';
                    const payload = syncRows();

                    if (!brandSelect.value && !brandName.value.trim()) {
                        event.preventDefault();
                        inlineError.textContent = 'Choose or type the brand.';
                        brandSelect.focus();
                        return;
                    }

                    if (payload.length === 0) {
                        event.preventDefault();
                        inlineError.textContent = 'Add at least one main variant row or sub-variant chip.';
                        mappedCards().at(0)?.querySelector('[data-hei-map-main-value]')?.focus();
                        return;
                    }

                    saveSessionPrefs();
                });

                restoreDraft();
                refreshSessionCount();
            })();
        </script>
    </section>
@endsection
