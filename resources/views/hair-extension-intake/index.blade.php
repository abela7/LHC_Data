@extends('layouts.app')

@section('title', 'Hair Extension Intake')
@section('section', 'Hair Extensions')
@section('heading', 'Product Intake')

@section('content')
    <section
        class="hei-page"
        data-hei-root
        data-autosave-url="{{ $autosaveUrl }}"
        data-phone-capture-url="{{ $phoneCaptureUrl }}"
        data-ai-lookup-url="{{ $aiLookupUrl }}"
        data-packaging-text-url="{{ $packagingTextUrl }}"
        data-export-url="{{ $exportUrl }}"
        data-brand-data='@json($brandData)'
        data-recent-intakes='@json($recentIntakeData)'
    >
        <div class="hei-toolbar">
            <div class="hei-toolbar-meta">
                <div class="hei-badge-row">
                    <span class="hei-pill"><span class="hei-pill-dot" aria-hidden="true"></span> Autosave after brand</span>
                    <span class="hei-session-meter">This session: <strong data-hei-session-count>0</strong> submitted</span>
                </div>
                <div class="hei-sync-block">
                    <strong data-hei-sync-title>New draft</strong>
                    <span data-hei-sync-detail></span>
                </div>
            </div>
            <div class="hei-toolbar-actions">
                <a class="hei-btn secondary" href="{{ route('hair-extension-intake.v2') }}">V2 family intake</a>
                <a class="hei-btn secondary" href="{{ route('hair-extension-intake.submitted') }}">Submitted</a>
                <a class="hei-btn secondary" href="{{ $exportUrl }}" target="_blank" rel="noopener">JSON</a>
                <button type="button" class="hei-btn linkish" data-hei-new>New observation</button>
                <button type="button" class="hei-btn primary hei-btn-primary-wide" data-hei-submit>Submit for AI</button>
                <button type="button" class="hei-btn danger hei-btn-primary-wide" data-hei-cancel>Cancel</button>
            </div>
        </div>

        <form class="hei-form-card hei-form-grid" data-hei-form enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="brand_catalogue_product_type_id" data-hei-product-type-id>
            <input type="hidden" name="brand_catalogue_style_id" data-hei-style-id>
            <input type="hidden" name="variant_groups" data-hei-variant-json>
            <input type="hidden" name="variant_structure" data-hei-variant-structure-json>

            <section class="hei-step" aria-labelledby="hei-step-brand">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="hei-step-brand">1</span>
                    <h2>Brand</h2>
                </div>
                <details class="hei-accordion hei-accordion--allow-overflow">
                    <summary class="hei-accordion-summary">
                        <span class="hei-accordion-summary-copy">
                            <span class="hei-accordion-summary-label">Pick from catalogue</span>
                        </span>
                    </summary>
                    <div class="hei-accordion-panel">
                        <p class="hei-help hei-accordion-help">Choosing a row loads the brand name and refreshes catalogue type / style lists.</p>
                        <div class="hei-field hei-field-accordion-inner">
                            <input type="hidden" name="brand_catalogue_brand_id" id="hei-brand-catalogue-select" data-hei-brand-select value="">
                            <div class="hei-brand-combobox" data-hei-brand-combobox>
                                <input
                                    type="text"
                                    class="hei-brand-combobox-input"
                                    data-hei-brand-search
                                    autocomplete="off"
                                    autocorrect="off"
                                    spellcheck="false"
                                    placeholder="Search brand…"
                                    role="combobox"
                                    aria-autocomplete="list"
                                    aria-controls="hei-brand-listbox"
                                    aria-expanded="false"
                                    aria-label="Search catalogue brands"
                                    enterkeyhint="search"
                                >
                                <ul class="hei-brand-combobox-list" id="hei-brand-listbox" role="listbox" aria-label="Catalogue brands" hidden></ul>
                            </div>
                        </div>
                    </div>
                </details>
                <label class="hei-field">
                    <span>Brand name (as on pack)</span>
                    <input type="text" name="brand_name" data-hei-brand-name placeholder="e.g. Cherish" enterkeyhint="next" autocomplete="off">
                </label>
            </section>

            <section class="hei-step" aria-labelledby="hei-step-observed">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="hei-step-observed">2</span>
                    <h2>Observed product</h2>
                </div>
                <label class="hei-field">
                    <span>Product name observed on pack</span>
                    <input type="text" name="observed_product_name" data-hei-product-type-name list="hei-common-types" placeholder="e.g. Cherish Bulk Passion Twist, French Curl 22 inch" enterkeyhint="next">
                </label>
                <div class="hei-ai-assist">
                    <label class="hei-field">
                        <span>AI model</span>
                        <select data-hei-ai-model>
                            @foreach ($aiModels as $model)
                                <option value="{{ $model['id'] }}" @selected($model['id'] === $aiDefaultModel)>
                                    {{ $model['name'] }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <button type="button" class="hei-btn secondary" data-hei-ai-suggest>AI suggest</button>
                    <div class="hei-ai-status" data-hei-ai-status hidden></div>
                </div>
                <div class="hei-ai-section-suggestions" data-hei-ai-product-suggestion hidden></div>
                <datalist id="hei-common-types">
                    <option value="Braiding Hair"></option>
                    <option value="Bulk Hair"></option>
                    <option value="Crochet, Twist & Loc Hair"></option>
                    <option value="Ponytails & Hair Pieces"></option>
                    <option value="Wigs"></option>
                    <option value="Weave / Weft"></option>
                </datalist>
                <label class="hei-not-sure">
                    <input
                        type="checkbox"
                        name="product_type_unknown"
                        value="1"
                        data-hei-product-type-unknown
                        aria-label="Not sure — observed product name unclear on shelf"
                    >
                    <span class="hei-not-sure-text" aria-hidden="true">Not sure</span>
                </label>
            </section>

            <section class="hei-step" aria-labelledby="hei-step-type">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="hei-step-type">3</span>
                    <h2>Product Type</h2>
                </div>
                <details class="hei-accordion">
                    <summary class="hei-accordion-summary">
                        <span class="hei-accordion-summary-copy">
                            <span class="hei-accordion-summary-label">Catalogue product type</span>
                        </span>
                    </summary>
                    <div class="hei-accordion-panel">
                        <p class="hei-help hei-accordion-help">Populated after you choose a catalogue brand (step 1). This controls the category path before choosing style / family.</p>
                        <div class="hei-field hei-field-accordion-inner">
                            <select
                                id="hei-product-type-catalogue-select"
                                data-hei-product-type-select
                                aria-label="Catalogue product type"
                            >
                                <option value="">Tap if known…</option>
                                <option value="__unknown">Unknown — let AI decide</option>
                            </select>
                        </div>
                    </div>
                </details>
                <div class="hei-ai-section-suggestions" data-hei-ai-type-suggestion hidden></div>
            </section>

            <section class="hei-step" aria-labelledby="hei-step-style">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="hei-step-style">4</span>
                    <h2>Style / family</h2>
                </div>
                <details class="hei-accordion">
                    <summary class="hei-accordion-summary">
                        <span class="hei-accordion-summary-copy">
                            <span class="hei-accordion-summary-label">Known style from catalogue</span>
                        </span>
                    </summary>
                    <div class="hei-accordion-panel">
                        <p class="hei-help hei-accordion-help">Uses catalogue styles for the selected classification. Ignore if you only have free-text names from the pack.</p>
                        <div class="hei-field hei-field-accordion-inner">
                            <select
                                id="hei-style-catalogue-select"
                                data-hei-style-select
                                aria-label="Known style line from catalogue"
                            >
                                <option value="">Tap if known…</option>
                                <option value="__unknown">Unknown — let AI decide</option>
                            </select>
                        </div>
                    </div>
                </details>
                <label class="hei-field">
                    <span>Style or family name</span>
                    <input type="text" name="style_name" data-hei-style-name placeholder="e.g. Butterfly Locs, Passion Twist" enterkeyhint="next" autocomplete="off">
                </label>
                <label class="hei-not-sure">
                    <input
                        type="checkbox"
                        name="style_unknown"
                        value="1"
                        data-hei-style-unknown
                        aria-label="Not sure — style or family unclear"
                    >
                    <span class="hei-not-sure-text" aria-hidden="true">Not sure</span>
                </label>
                <div class="hei-ai-section-suggestions" data-hei-ai-style-suggestion hidden></div>
            </section>

            <section class="hei-step" aria-labelledby="hei-step-variants">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="hei-step-variants">5</span>
                    <h2>Variant mapping</h2>
                </div>
                <div class="hei-ai-section-suggestions" data-hei-ai-variant-suggestion hidden></div>

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
                        <div class="hei-catalogue-variant-grid" data-hei-catalogue-variant-grid></div>
                    </div>
                </details>

                <div class="hei-variant-mapping" data-hei-variant-mapping>
                    <div class="hei-variant-mapping-panel">
                    <ol class="hei-vm-flow">
                        <li class="hei-vm-block">
                            <div class="hei-vm-block-head">
                                <span class="hei-vm-idx" aria-hidden="true">1</span>
                                <span class="hei-vm-block-title" id="hei-vm-label-main-variant">Main variant</span>
                            </div>
                            <label class="hei-field hei-vm-field">
                                <span class="hei-vm-field-lbl">Axis name</span>
                                <input
                                    type="text"
                                    data-hei-map-main-axis
                                    list="hei-variant-group-names"
                                    placeholder="e.g. Length"
                                    aria-describedby="hei-vm-label-main-variant"
                                    enterkeyhint="next"
                                >
                            </label>
                            <details class="hei-mini-accordion">
                                <summary>Choose main variant</summary>
                                <div class="hei-variant-presets" role="group" aria-label="Quick-set main variant axis">
                                    @foreach (['Colour', 'Length', 'Pack count', 'Texture', 'Size', 'Material'] as $preset)
                                        <button type="button" class="hei-chip-add" data-hei-map-axis-preset="{{ $preset }}">+ {{ $preset }}</button>
                                    @endforeach
                                </div>
                            </details>
                        </li>
                        <li class="hei-vm-block">
                            <div class="hei-vm-block-head">
                                <span class="hei-vm-idx" aria-hidden="true">2</span>
                                <span class="hei-vm-block-title" id="hei-vm-label-sub-variant">Sub-variant</span>
                            </div>
                            <details class="hei-mini-accordion">
                                <summary>Choose sub-variant</summary>
                                <div class="hei-variant-presets" role="group" aria-label="Quick-set sub-variant axis for all rows">
                                    @foreach (['Colour', 'Length', 'Pack count', 'Texture', 'Size', 'Material'] as $preset)
                                        <button type="button" class="hei-chip-add" data-hei-vm-sub-preset="{{ $preset }}">+ {{ $preset }}</button>
                                    @endforeach
                                </div>
                            </details>
                            <div class="hei-map-groups" data-hei-map-groups></div>
                            <button type="button" class="hei-btn secondary hei-vm-action" data-hei-add-map-group>Add main variant value</button>
                        </li>
                        <li class="hei-vm-block hei-vm-block--footer">
                            <div class="hei-vm-block-head">
                                <span class="hei-vm-idx" aria-hidden="true">3</span>
                                <span class="hei-vm-block-title" id="hei-vm-label-common">Common variants</span>
                            </div>
                            <details class="hei-mini-accordion">
                                <summary>Choose common variant</summary>
                                <div class="hei-variant-presets" role="group" aria-label="Quick-set common variant group for all entries">
                                    @foreach (['Colour', 'Length', 'Pack count', 'Texture', 'Size', 'Material'] as $preset)
                                        <button type="button" class="hei-chip-add" data-hei-vm-common-preset="{{ $preset }}">+ {{ $preset }}</button>
                                    @endforeach
                                </div>
                            </details>
                            <div class="hei-common-variant-list" data-hei-common-variant-list></div>
                            <button type="button" class="hei-btn secondary hei-vm-action" data-hei-add-common-variant>Add common variant</button>
                        </li>
                    </ol>
                    </div>
                </div>

                <details class="hei-accordion hei-simple-variants">
                    <summary class="hei-accordion-summary">
                        <span class="hei-accordion-summary-copy">
                            <span class="hei-accordion-summary-label">Variants you see</span>
                        </span>
                    </summary>
                    <div class="hei-accordion-panel">
                        <p class="hei-help">Type each value and press comma to add it. Unfinished text is not saved as a variant.</p>
                        <div class="hei-variant-list" data-hei-variant-list></div>
                        <button type="button" class="hei-btn secondary" data-hei-add-variant>+ Custom group</button>
                    </div>
                </details>
            </section>

            <section class="hei-step" aria-labelledby="hei-step-source">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="hei-step-source">6</span>
                    <h2>Verification URL</h2>
                </div>
                <details class="hei-accordion" data-hei-source-section>
                    <summary class="hei-accordion-summary">
                        <span class="hei-accordion-summary-copy">
                            <span class="hei-accordion-summary-label">Source website</span>
                        </span>
                    </summary>
                    <div class="hei-accordion-panel">
                        <input type="hidden" name="source_url" data-hei-source-url-primary>
                        <input type="hidden" name="verification_urls" data-hei-source-urls-json>
                        <div class="hei-source-url-list" data-hei-source-url-list></div>
                        <button type="button" class="hei-btn secondary" data-hei-add-source-url>+ Add URL</button>
                        <p class="hei-help">Official / distributor pages help AI verify; shelf notes always come first.</p>
                    </div>
                </details>
                <div class="hei-ai-section-suggestions" data-hei-ai-source-suggestion hidden></div>
            </section>

            <section class="hei-step" aria-labelledby="hei-step-photo">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="hei-step-photo">7</span>
                    <h2>Photo evidence (optional)</h2>
                </div>
                <details class="hei-accordion" data-hei-photo-section>
                    <summary class="hei-accordion-summary">
                        <span class="hei-accordion-summary-copy">
                            <span class="hei-accordion-summary-label">Photos &amp; camera</span>
                        </span>
                    </summary>
                    <div class="hei-accordion-panel">
                        <div class="hei-photo-wrap">
                            <div class="hei-photo-preview" data-hei-photo-preview>No photo</div>
                            <div>
                                <div class="hei-phone-photo-panel">
                                    <label class="hei-field">
                                        <span>Image purpose</span>
                                        <select data-hei-phone-photo-role>
                                            <option value="packaging_front">Packaging front</option>
                                            <option value="packaging_back">Packaging back</option>
                                            <option value="label_closeup">Label close-up</option>
                                            <option value="variant_evidence">Variant evidence</option>
                                            <option value="colour_evidence">Colour evidence</option>
                                            <option value="shelf_reference">Shelf reference</option>
                                            <option value="source_reference">Source website/reference</option>
                                            <option value="main">Main product photo</option>
                                        </select>
                                    </label>
                                    <label class="hei-field">
                                        <span>Photo note</span>
                                        <input type="text" data-hei-phone-photo-notes placeholder="e.g. front pack, colour label, shelf range">
                                    </label>
                                    <div class="hei-direct-photo-actions">
                                        <label class="hei-btn primary">
                                            Take photo
                                            <input class="hei-direct-photo-input" type="file" accept="image/*" capture="environment" data-hei-direct-photo-input>
                                        </label>
                                        <label class="hei-btn secondary">
                                            Gallery
                                            <input class="hei-direct-photo-input" type="file" accept="image/*" multiple data-hei-direct-photo-gallery>
                                        </label>
                                    </div>
                                    <div class="hei-phone-photo-status" data-hei-phone-photo-status hidden></div>
                                    <div class="hei-intake-photo-grid" data-hei-intake-photo-grid></div>
                                </div>

                                <details class="hei-accordion hei-photo-fallback">
                                    <summary class="hei-accordion-summary">
                                        <span class="hei-accordion-summary-copy">
                                            <span class="hei-accordion-summary-label">Fallback upload</span>
                                            <span class="hei-accordion-summary-hint">Use only if phone station is not available</span>
                                        </span>
                                    </summary>
                                    <div class="hei-accordion-panel">
                                        <label class="hei-field">
                                            <span>Camera or gallery</span>
                                            <input type="file" name="product_photo" accept="image/*" capture="environment" data-hei-photo-input>
                                        </label>
                                        <div class="hei-photo-actions">
                                            <button type="button" class="hei-btn danger" data-hei-remove-photo>Remove fallback photo</button>
                                        </div>
                                        <p class="hei-help">This old single-photo field remains as a backup. Phone evidence can hold multiple images.</p>
                                    </div>
                                </details>
                            </div>
                        </div>
                    </div>
                </details>
            </section>

            <section class="hei-step" aria-labelledby="hei-step-notes">
                <div class="hei-step-head">
                    <span class="hei-step-num" id="hei-step-notes">8</span>
                    <h2>Packaging text & notes</h2>
                </div>
                <div class="hei-packaging-vision">
                    <label class="hei-field">
                        <span>Vision model</span>
                        <select data-hei-packaging-model>
                            @foreach ($visionModels as $model)
                                <option value="{{ $model['id'] }}" @selected($model['id'] === $visionDefaultModel)>
                                    {{ $model['name'] }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <div class="hei-packaging-actions">
                        <label class="hei-btn secondary">
                            Camera OCR
                            <input class="hei-direct-photo-input" type="file" accept="image/*" capture="environment" data-hei-packaging-camera>
                        </label>
                        <label class="hei-btn secondary">
                            Upload OCR
                            <input class="hei-direct-photo-input" type="file" accept="image/*" data-hei-packaging-upload>
                        </label>
                    </div>
                    <div class="hei-packaging-status" data-hei-packaging-status hidden></div>
                    <div class="hei-packaging-result" data-hei-packaging-result hidden></div>
                </div>
                <label class="hei-field">
                    <span>Everything readable on pack</span>
                    <textarea name="visible_text_notes" data-hei-notes placeholder="Sizes, fibres, counts, buzzwords — dump it here." rows="5"></textarea>
                </label>
            </section>
        </form>

        <div class="hei-intake-preview" data-hei-preview hidden role="dialog" aria-modal="true" aria-labelledby="hei-preview-title">
            <button type="button" class="hei-intake-preview-backdrop" data-hei-preview-close aria-label="Close preview"></button>
            <article class="hei-intake-preview-panel">
                <header class="hei-intake-preview-head">
                    <div>
                        <span class="hei-intake-preview-status" data-hei-preview-status>Record preview</span>
                        <h3 id="hei-preview-title" data-hei-preview-title>Saved intake</h3>
                    </div>
                    <button type="button" class="hei-intake-preview-close" data-hei-preview-close aria-label="Close preview">&times;</button>
                </header>
                <div class="hei-intake-preview-body" data-hei-preview-body>
                    Loading saved data...
                </div>
                <footer class="hei-intake-preview-actions">
                    <button type="button" class="hei-btn secondary" data-hei-preview-load>Load/Edit</button>
                    <button type="button" class="hei-btn primary" data-hei-preview-submit>Submit this draft</button>
                    <button type="button" class="hei-btn linkish" data-hei-preview-close>Close</button>
                </footer>
            </article>
        </div>

        <div class="hei-mobile-dock" aria-label="Quick actions">
            <div class="hei-dock-sync">
                <strong data-hei-sync-title>New draft</strong>
                <span data-hei-sync-detail></span>
            </div>
            <div class="hei-dock-actions">
                <button type="button" class="hei-btn secondary hei-dock-new-btn" data-hei-dock-new hidden>+ New</button>
                <button type="button" class="hei-btn primary" data-hei-submit>Submit</button>
                <button type="button" class="hei-btn danger" data-hei-cancel>Cancel</button>
            </div>
        </div>

        <script>
            (() => {
                const root = document.querySelector('[data-hei-root]');
                const form = root?.querySelector('[data-hei-form]');
                if (!root || !form) return;

                const SESSION_SUBMIT_KEY = 'hei_session_submits_v1';
                const ACTIVE_DRAFT_KEY = 'hei_active_draft_id_v1';
                const LOCAL_DRAFT_KEY = 'hei_local_form_backup_v1';
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                const brandData = JSON.parse(root.dataset.brandData || '[]');
                const recentIntakes = JSON.parse(root.dataset.recentIntakes || '[]');
                const pageBaseUrl = `${window.location.origin}${window.location.pathname.replace(/\/+$/, '')}`;
                const requestedEditId = new URLSearchParams(window.location.search).get('edit_intake');
                let autosaveUrl = root.dataset.autosaveUrl;
                const phoneCaptureUrl = root.dataset.phoneCaptureUrl;
                const aiLookupUrl = root.dataset.aiLookupUrl;
                const packagingTextUrl = root.dataset.packagingTextUrl;
                let submitUrl = null;
                let currentIntakeId = null;
                let isSubmitted = false;
                let syncTimer = null;
                let pendingPhoto = false;
                let phonePhotoPollTimer = null;
                let restoringDraft = false;

                const syncTitles = root.querySelectorAll('[data-hei-sync-title]');
                const syncDetails = root.querySelectorAll('[data-hei-sync-detail]');
                const sessionCountEl = root.querySelector('[data-hei-session-count]');
                const brandSelect = root.querySelector('[data-hei-brand-select]');
                const brandSearchInput = root.querySelector('[data-hei-brand-search]');
                const brandListbox = root.querySelector('#hei-brand-listbox');
                const brandComboboxEl = root.querySelector('[data-hei-brand-combobox]');
                let brandBlurCloseTimer = null;
                let brandListFiltered = [];
                let brandListHighlight = -1;

                const brandName = root.querySelector('[data-hei-brand-name]');
                const productTypeSelect = root.querySelector('[data-hei-product-type-select]');
                const productTypeId = root.querySelector('[data-hei-product-type-id]');
                const productTypeName = root.querySelector('[data-hei-product-type-name]');
                const productTypeUnknown = root.querySelector('[data-hei-product-type-unknown]');
                const aiSuggestButton = root.querySelector('[data-hei-ai-suggest]');
                const aiModelSelect = root.querySelector('[data-hei-ai-model]');
                const aiStatus = root.querySelector('[data-hei-ai-status]');
                const aiProductSuggestion = root.querySelector('[data-hei-ai-product-suggestion]');
                const aiTypeSuggestion = root.querySelector('[data-hei-ai-type-suggestion]');
                const aiStyleSuggestion = root.querySelector('[data-hei-ai-style-suggestion]');
                const aiSourceSuggestion = root.querySelector('[data-hei-ai-source-suggestion]');
                const aiVariantSuggestion = root.querySelector('[data-hei-ai-variant-suggestion]');
                const packagingModelSelect = root.querySelector('[data-hei-packaging-model]');
                const packagingCameraInput = root.querySelector('[data-hei-packaging-camera]');
                const packagingUploadInput = root.querySelector('[data-hei-packaging-upload]');
                const packagingStatus = root.querySelector('[data-hei-packaging-status]');
                const packagingResult = root.querySelector('[data-hei-packaging-result]');
                const styleSelect = root.querySelector('[data-hei-style-select]');
                const styleId = root.querySelector('[data-hei-style-id]');
                const styleName = root.querySelector('[data-hei-style-name]');
                const styleUnknown = root.querySelector('[data-hei-style-unknown]');
                const catalogueVariantPanel = root.querySelector('[data-hei-catalogue-variant-panel]');
                const catalogueVariantTitle = root.querySelector('[data-hei-catalogue-variant-title]');
                const catalogueVariantGrid = root.querySelector('[data-hei-catalogue-variant-grid]');
                const applyCatalogueVariantMatrixButton = root.querySelector('[data-hei-apply-catalogue-variant-matrix]');
                const variantList = root.querySelector('[data-hei-variant-list]');
                const variantJson = root.querySelector('[data-hei-variant-json]');
                const variantStructureJson = root.querySelector('[data-hei-variant-structure-json]');
                const mapMainAxis = root.querySelector('[data-hei-map-main-axis]');
                const mapGroups = root.querySelector('[data-hei-map-groups]');
                const addMapGroupButton = root.querySelector('[data-hei-add-map-group]');
                const commonVariantList = root.querySelector('[data-hei-common-variant-list]');
                const addCommonVariantButton = root.querySelector('[data-hei-add-common-variant]');
                const photoInput = root.querySelector('[data-hei-photo-input]');
                const photoPreview = root.querySelector('[data-hei-photo-preview]');
                const phonePhotoRole = root.querySelector('[data-hei-phone-photo-role]');
                const phonePhotoNotes = root.querySelector('[data-hei-phone-photo-notes]');
                const phonePhotoRequest = root.querySelector('[data-hei-phone-photo-request]');
                const directPhotoInput = root.querySelector('[data-hei-direct-photo-input]');
                const directPhotoGallery = root.querySelector('[data-hei-direct-photo-gallery]');
                const phonePhotoStatus = root.querySelector('[data-hei-phone-photo-status]');
                const intakePhotoGrid = root.querySelector('[data-hei-intake-photo-grid]');
                const photoSectionDetails = root.querySelector('[data-hei-photo-section]');
                const sourceUrlSectionDetails = root.querySelector('[data-hei-source-section]');
                const sourceUrlList = root.querySelector('[data-hei-source-url-list]');
                const sourceUrlPrimary = root.querySelector('[data-hei-source-url-primary]');
                const sourceUrlJson = root.querySelector('[data-hei-source-urls-json]');
                const addSourceUrlButton = root.querySelector('[data-hei-add-source-url]');
                const submitButtons = root.querySelectorAll('[data-hei-submit]');
                const cancelButtons = root.querySelectorAll('[data-hei-cancel]');
                const newButton = root.querySelector('[data-hei-new]');
                const addVariantButton = root.querySelector('[data-hei-add-variant]');
                const removePhotoButton = root.querySelector('[data-hei-remove-photo]');
                const previewModal = root.querySelector('[data-hei-preview]');
                const previewTitle = root.querySelector('[data-hei-preview-title]');
                const previewStatus = root.querySelector('[data-hei-preview-status]');
                const previewBody = root.querySelector('[data-hei-preview-body]');
                const previewLoadButton = root.querySelector('[data-hei-preview-load]');
                const previewSubmitButton = root.querySelector('[data-hei-preview-submit]');
                let previewRecord = null;

                const commonVariantGroups = ['Colour', 'Length', 'Pack count', 'Texture', 'Size', 'Material', 'Style', 'Other'];
                const normalizeVariantValue = (value) => String(value || '').trim().replace(/\s+/g, ' ');
                const variantKey = (value) => normalizeVariantValue(value).toLocaleLowerCase();

                const refreshSessionCount = () => {
                    if (!sessionCountEl) return;
                    const n = Number.parseInt(sessionStorage.getItem(SESSION_SUBMIT_KEY) || '0', 10) || 0;
                    sessionCountEl.textContent = String(n);
                };

                const bumpSessionCount = () => {
                    const next = (Number.parseInt(sessionStorage.getItem(SESSION_SUBMIT_KEY) || '0', 10) || 0) + 1;
                    sessionStorage.setItem(SESSION_SUBMIT_KEY, String(next));
                    refreshSessionCount();
                };

                const setStatus = (title, detail = '') => {
                    syncTitles.forEach((el) => {
                        el.textContent = title;
                    });
                    syncDetails.forEach((el) => {
                        el.textContent = detail;
                    });
                };

                const escapeHtml = (value) =>
                    String(value || '').replace(/[&<>"']/g, (character) =>
                        ({
                            '&': '&amp;',
                            '<': '&lt;',
                            '>': '&gt;',
                            '"': '&quot;',
                            "'": '&#039;',
                        })[character],
                    );

                const normalizeBrandSearch = (value) =>
                    String(value || '')
                        .normalize('NFD')
                        .replace(/\p{M}/gu, '')
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, ' ')
                        .trim()
                        .replace(/\s+/g, ' ');

                const formatBrandOptionHtml = (brand, rawQuery) => {
                    const name = String(brand.name || '');
                    const q = String(rawQuery || '').trim();
                    if (!q) return escapeHtml(name);
                    const idx = name.toLocaleLowerCase('en-GB').indexOf(q.toLocaleLowerCase('en-GB'));
                    if (idx === -1) return escapeHtml(name);
                    const before = escapeHtml(name.slice(0, idx));
                    const mid = escapeHtml(name.slice(idx, idx + q.length));
                    const after = escapeHtml(name.slice(idx + q.length));
                    return `${before}<mark class="hei-brand-combobox-mark">${mid}</mark>${after}`;
                };

                const selectedBrand = () => brandData.find((brand) => String(brand.id) === String(brandSelect?.value));
                const selectedProductType = () => selectedBrand()?.product_types?.find((type) => String(type.id) === String(productTypeId.value));
                const selectedStyle = () => {
                    const wantedStyleId = String(styleId?.value || styleSelect?.value || '');
                    if (!wantedStyleId || wantedStyleId === '__unknown') return null;

                    for (const type of selectedBrand()?.product_types || []) {
                        const style = (type.styles || []).find((candidate) => String(candidate.id) === wantedStyleId);
                        if (style) return style;
                    }

                    return null;
                };

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

                const normalisedAxisName = (value) => normalizeVariantValue(value).toLocaleLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

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

                const sortedCatalogueBrands = () =>
                    [...brandData].sort((a, b) =>
                        String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' }),
                    );

                const BRAND_SUGGESTION_CAP = 50;

                const filterBrandsForQuery = (raw) => {
                    const qNorm = normalizeBrandSearch(raw);
                    const all = sortedCatalogueBrands();
                    if (!qNorm) return all;
                    const tokens = qNorm.split(' ').filter(Boolean);
                    const scored = [];
                    for (const brand of all) {
                        const nameNorm = normalizeBrandSearch(brand.name);
                        if (!nameNorm) continue;
                        if (!tokens.every((t) => nameNorm.includes(t))) continue;
                        const needle = tokens.join(' ');
                        let rank = 40;
                        if (nameNorm === needle) rank = 0;
                        else if (nameNorm.startsWith(needle)) rank = nameNorm[needle.length] === ' ' ? 1 : 2;
                        else {
                            const first = tokens[0];
                            const i0 = nameNorm.indexOf(first);
                            if (i0 === 0) rank = 10;
                            else if (i0 > 0 && nameNorm[i0 - 1] === ' ') rank = 15;
                            else rank = 25 + i0;
                        }
                        scored.push({ brand, rank, nameNorm });
                    }
                    scored.sort(
                        (a, b) =>
                            a.rank - b.rank ||
                            a.nameNorm.localeCompare(b.nameNorm, undefined, { sensitivity: 'base' }),
                    );
                    return scored.map((row) => row.brand);
                };

                const syncBrandSearchDisplay = () => {
                    if (!brandSearchInput || !brandSelect) return;
                    const b = selectedBrand();
                    brandSearchInput.value = b ? b.name : '';
                };

                const setBrandListOpen = (open) => {
                    if (!brandListbox || !brandSearchInput) return;
                    brandListbox.hidden = !open;
                    brandSearchInput.setAttribute('aria-expanded', open ? 'true' : 'false');
                };

                const paintBrandListHighlight = () => {
                    if (!brandListbox) return;
                    brandListbox.querySelectorAll('[role=option]').forEach((el, idx) => {
                        const on = idx === brandListHighlight;
                        el.classList.toggle('is-active', on);
                        el.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    if (brandListHighlight >= 0 && brandListFiltered[brandListHighlight]) {
                        const id = brandListFiltered[brandListHighlight].id;
                        brandSearchInput?.setAttribute('aria-activedescendant', `hei-brand-opt-${id}`);
                    } else {
                        brandSearchInput?.removeAttribute('aria-activedescendant');
                    }
                };

                const getBrandSuggestions = (raw) => {
                    const full = filterBrandsForQuery(raw);
                    const trimmed = full.slice(0, BRAND_SUGGESTION_CAP);
                    return {
                        trimmed,
                        overflowExtra: Math.max(0, full.length - trimmed.length),
                    };
                };

                const renderBrandListbox = (brands, rawQuery, overflowExtra = 0, highlightIdx = null) => {
                    brandListFiltered = brands;
                    if (highlightIdx !== null && highlightIdx !== undefined) {
                        brandListHighlight =
                            brands.length === 0
                                ? -1
                                : Math.min(Math.max(highlightIdx, 0), brands.length - 1);
                    } else {
                        brandListHighlight = brands.length
                            ? Math.min(Math.max(brandListHighlight, 0), brands.length - 1)
                            : -1;
                        if (brandListFiltered.length && brandListHighlight < 0) {
                            brandListHighlight = 0;
                        }
                    }
                    if (!brandListbox) return;
                    if (!brands.length) {
                        brandListbox.innerHTML =
                            '<li class="hei-brand-combobox-empty" role="presentation">No matches</li>';
                        brandSearchInput?.removeAttribute('aria-activedescendant');
                        return;
                    }
                    const items = brands
                        .map(
                            (brand, idx) =>
                                `<li class="hei-brand-combobox-option${idx === brandListHighlight ? ' is-active' : ''}" role="option" id="hei-brand-opt-${brand.id}" data-hei-brand-option="${String(brand.id)}" aria-selected="${idx === brandListHighlight ? 'true' : 'false'}">${formatBrandOptionHtml(brand, rawQuery)}</li>`,
                        )
                        .join('');
                    const more =
                        overflowExtra > 0
                            ? `<li class="hei-brand-combobox-more" role="presentation">${escapeHtml(`Type more to narrow (${overflowExtra} more match${overflowExtra === 1 ? '' : 'es'}).`)}</li>`
                            : '';
                    brandListbox.innerHTML = items + more;
                    paintBrandListHighlight();
                };

                const refreshBrandListFromInput = () => {
                    const raw = brandSearchInput?.value || '';
                    const { trimmed, overflowExtra } = getBrandSuggestions(raw);
                    brandListHighlight = trimmed.length ? 0 : -1;
                    renderBrandListbox(trimmed, raw, overflowExtra, brandListHighlight);
                };

                const cascadeCatalogueBrandUnset = () => {
                    productTypeId.value = '';
                    productTypeName.value = '';
                    styleId.value = '';
                    styleName.value = '';
                    if (productTypeSelect) productTypeSelect.value = '';
                    if (styleSelect) styleSelect.value = '';
                    rebuildProductTypes();
                    rebuildStyles();
                    renderCatalogueVariantAssist();
                };

                const commitCatalogueBrandId = (id, options = {}) => {
                    const { fromUser = false } = options;
                    if (!brandSelect) return;
                    const prev = brandSelect.value;
                    brandSelect.value = id ? String(id) : '';
                    syncBrandSearchDisplay();
                    clearTimeout(brandBlurCloseTimer);
                    setBrandListOpen(false);
                    if (!fromUser) return;
                    const brand = selectedBrand();
                    if (brand) {
                        brandName.value = brand.name;
                    }
                    if (String(prev) !== String(brandSelect.value)) {
                        cascadeCatalogueBrandUnset();
                    }
                    scheduleAutosave();
                };

                const rebuildProductTypes = () => {
                    const brand = selectedBrand();
                    productTypeSelect.innerHTML =
                        '<option value="">Tap if known…</option><option value="__unknown">Unknown — let AI decide</option>';
                    (brand?.product_types || []).forEach((type) => {
                        productTypeSelect.insertAdjacentHTML(
                            'beforeend',
                            `<option value="${type.id}">${escapeHtml(type.name)}</option>`,
                        );
                    });
                };

                const rebuildStyles = () => {
                    const type = selectedProductType();
                    styleSelect.innerHTML =
                        '<option value="">Tap if known…</option><option value="__unknown">Unknown — let AI decide</option>';
                    (type?.styles || []).forEach((style) => {
                        styleSelect.insertAdjacentHTML(
                            'beforeend',
                            `<option value="${style.id}">${escapeHtml(style.name)}</option>`,
                        );
                    });
                };

                const addVariantGroup = (group = { name: '', values: [] }) => {
                    const card = document.createElement('div');
                    card.className = 'hei-variant-card';
                    card.innerHTML = `
                        <div class="hei-variant-head">
                            <label class="hei-field hei-variant-name-field">
                                <span>Group</span>
                                <input type="text" list="hei-variant-group-names" data-hei-variant-name value="${escapeHtml(group.name || '')}" placeholder="Colour">
                            </label>
                            <button type="button" class="hei-variant-remove-btn" data-hei-remove-variant aria-label="Remove this variant group">✕</button>
                        </div>
                        <div class="hei-field">
                            <span>Values you see</span>
                            <div class="hei-variant-value-box">
                                <div class="hei-variant-chip-list" data-hei-variant-chip-list></div>
                                <input type="text" data-hei-variant-values placeholder="Type 1B, 2, OT30 then comma">
                            </div>
                            <small class="hei-variant-warning" data-hei-variant-warning hidden></small>
                        </div>
                    `;
                    variantList.appendChild(card);
                    (group.values || []).forEach((value) => addVariantValue(card, value, false));
                };

                const ensureDatalist = () => {
                    if (document.getElementById('hei-variant-group-names')) return;
                    const datalist = document.createElement('datalist');
                    datalist.id = 'hei-variant-group-names';
                    datalist.innerHTML = commonVariantGroups.map((name) => `<option value="${escapeHtml(name)}"></option>`).join('');
                    document.body.appendChild(datalist);
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
                        return false;
                    }

                    let added = false;
                    parts.forEach((part) => {
                        added = addVariantValue(card, part, true) || added;
                    });
                    input.value = remainder.trimStart();

                    if (added) {
                        scheduleAutosave();
                    }

                    return added;
                };

                const addMappedVariantGroup = (group = { main_value: '', sub_axis: 'Colour', sub_values: [] }) => {
                    const card = document.createElement('div');
                    card.className = 'hei-map-group-card hei-variant-card';
                    card.dataset.heiMapGroup = '1';
                    card.innerHTML = `
                        <div class="hei-map-card-toolbar">
                            <button type="button" class="hei-variant-remove-btn hei-icon-btn" data-hei-remove-map-group aria-label="Remove this mapping">&#x2715;</button>
                        </div>
                        <div class="hei-map-card-row">
                            <label class="hei-field hei-map-main-field">
                                <span>Main value</span>
                                <input type="text" data-hei-map-main-value value="${escapeHtml(group.main_value || '')}" placeholder="e.g. 22″" enterkeyhint="next">
                            </label>
                            <div class="hei-map-arrow" aria-hidden="true">&#8594;</div>
                            <label class="hei-field hei-map-sub-field">
                                <span>Sub-variant</span>
                                <input type="text" list="hei-variant-group-names" data-hei-map-sub-axis value="${escapeHtml(group.sub_axis || 'Colour')}" placeholder="e.g. Colour" enterkeyhint="next">
                            </label>
                        </div>
                        <details class="hei-mini-accordion">
                            <summary>Choose sub-variant</summary>
                            <div class="hei-variant-presets" role="group" aria-label="Quick-set sub-variant axis">
                                ${commonVariantGroups.map((preset) => `<button type="button" class="hei-chip-add" data-hei-map-sub-axis-preset="${escapeHtml(preset)}">+ ${escapeHtml(preset)}</button>`).join('')}
                            </div>
                        </details>
                        <div class="hei-field">
                            <span>Sub-variant values</span>
                            <div class="hei-variant-value-box">
                                <div class="hei-variant-chip-list" data-hei-variant-chip-list></div>
                                <input type="text" data-hei-variant-values placeholder="Type values, comma to add">
                            </div>
                            <small class="hei-variant-warning" data-hei-variant-warning hidden></small>
                        </div>
                    `;
                    mapGroups?.appendChild(card);
                    (group.sub_values || []).forEach((value) => addVariantValue(card, value, false));
                };

                const addCommonVariantGroup = (group = { name: '', values: [] }) => {
                    const card = document.createElement('div');
                    card.className = 'hei-common-variant-card hei-variant-card';
                    card.dataset.heiCommonVariant = '1';
                    card.innerHTML = `
                        <div class="hei-variant-head">
                            <label class="hei-field hei-variant-name-field">
                                <span>Variant group</span>
                                <input type="text" list="hei-variant-group-names" data-hei-common-name value="${escapeHtml(group.name || '')}" placeholder="e.g. Bundle" enterkeyhint="next">
                            </label>
                            <button type="button" class="hei-variant-remove-btn hei-icon-btn" data-hei-remove-common-variant aria-label="Remove common variant">&#x2715;</button>
                        </div>
                        <details class="hei-mini-accordion">
                            <summary>Choose common variant</summary>
                            <div class="hei-variant-presets" role="group" aria-label="Quick-set common variant group">
                                ${commonVariantGroups.map((preset) => `<button type="button" class="hei-chip-add" data-hei-common-name-preset="${escapeHtml(preset)}">+ ${escapeHtml(preset)}</button>`).join('')}
                            </div>
                        </details>
                        <div class="hei-field">
                            <span>Values</span>
                            <div class="hei-variant-value-box">
                                <div class="hei-variant-chip-list" data-hei-variant-chip-list></div>
                                <input type="text" data-hei-variant-values placeholder="Type values, comma to add">
                            </div>
                            <small class="hei-variant-warning" data-hei-variant-warning hidden></small>
                        </div>
                    `;
                    commonVariantList?.appendChild(card);
                    (group.values || []).forEach((value) => addVariantValue(card, value, false));
                };

                const renderCatalogueVariantAssist = () => {
                    if (!catalogueVariantPanel || !catalogueVariantGrid) return;
                    const style = selectedStyle();
                    const variants = styleVariantsWithValues();

                    if (!style || variants.length === 0) {
                        catalogueVariantPanel.hidden = true;
                        catalogueVariantPanel.open = false;
                        catalogueVariantGrid.innerHTML = '';
                        return;
                    }

                    if (catalogueVariantTitle) {
                        const count = variants.reduce((sum, variant) => sum + variant.options.length, 0);
                        catalogueVariantTitle.textContent = `${style.name} variants (${count})`;
                    }

                    catalogueVariantGrid.innerHTML = variants.map((variant) => `
                        <section class="hei-catalogue-variant-axis">
                            <div class="hei-catalogue-variant-axis-head">
                                <strong>${escapeHtml(variant.name)}</strong>
                                <span>${variant.options.length}</span>
                            </div>
                            <div class="hei-variant-presets">
                                ${variant.options.map((option) => `
                                    <button
                                        type="button"
                                        class="hei-chip-add"
                                        data-hei-catalogue-variant-value="${escapeHtml(option.label)}"
                                        data-hei-catalogue-variant-axis="${escapeHtml(variant.name)}"
                                    >${escapeHtml(option.label)}</button>
                                `).join('')}
                            </div>
                        </section>
                    `).join('');
                    catalogueVariantPanel.hidden = false;
                };

                const lastMappedCardOrCreate = (subAxis = 'Colour') => {
                    let cards = Array.from(mapGroups?.querySelectorAll('[data-hei-map-group]') || []);
                    if (cards.length === 0) {
                        addMappedVariantGroup({
                            main_value: '',
                            sub_axis: subAxis,
                            sub_values: [],
                        });
                        cards = Array.from(mapGroups?.querySelectorAll('[data-hei-map-group]') || []);
                    }

                    return cards.at(-1) || null;
                };

                const applyCatalogueVariantValue = (axisName, value) => {
                    const variants = styleVariantsWithValues();
                    if (variants.length === 0) return;

                    const mainVariant = findVariantByAxis(variants, mapMainAxis?.value || '') || fallbackMainVariant(variants);
                    const subVariant = fallbackSubVariant(variants, mainVariant);
                    const isMainAxis = mainVariant && normalisedAxisName(axisName) === normalisedAxisName(mainVariant.name);

                    if (isMainAxis) {
                        if (mapMainAxis && !mapMainAxis.value) {
                            mapMainAxis.value = mainVariant.name;
                        }
                        addMappedVariantGroup({
                            main_value: value,
                            sub_axis: subVariant?.name || 'Variant',
                            sub_values: [],
                        });
                    } else {
                        if (mapMainAxis && !mapMainAxis.value && mainVariant) {
                            mapMainAxis.value = mainVariant.name;
                        }
                        const card = lastMappedCardOrCreate(axisName || subVariant?.name || 'Variant');
                        const subAxisInput = card?.querySelector('[data-hei-map-sub-axis]');
                        if (subAxisInput && axisName) {
                            subAxisInput.value = axisName;
                        }
                        if (card) {
                            addVariantValue(card, value, true);
                        }
                    }

                    scheduleAutosave();
                };

                const applyCatalogueVariantMatrix = () => {
                    const variants = styleVariantsWithValues();
                    if (variants.length === 0) return false;

                    const mainVariant = findVariantByAxis(variants, mapMainAxis?.value || '') || fallbackMainVariant(variants);
                    const subVariant = fallbackSubVariant(variants, mainVariant);
                    if (!mainVariant) return false;

                    if (mapMainAxis) {
                        mapMainAxis.value = mainVariant.name;
                    }
                    if (mapGroups) {
                        mapGroups.innerHTML = '';
                    }

                    const subValues = subVariant?.options?.map((option) => option.label) || [];
                    mainVariant.options.forEach((option) => {
                        addMappedVariantGroup({
                            main_value: option.label,
                            sub_axis: subVariant?.name || 'Variant',
                            sub_values: subValues,
                        });
                    });

                    catalogueVariantPanel.open = true;
                    scheduleAutosave();
                    setStatus('Catalogue variants applied', `${selectedStyle()?.name || 'Selected style'} variants were copied from the database. Remove anything not on the shelf.`);

                    return true;
                };

                const collectVariantGroups = () =>
                    Array.from(variantList.querySelectorAll('.hei-variant-card'))
                        .map((card) => {
                            const name = card.querySelector('[data-hei-variant-name]')?.value?.trim() || '';
                            const seen = new Set();
                            const values = variantValuesForCard(card).filter((value) => {
                                const key = variantKey(value);
                                if (seen.has(key)) return false;
                                seen.add(key);
                                return true;
                            });

                            return { name, values };
                        })
                        .filter((group) => group.name || group.values.length);

                const collectVariantStructure = () => {
                    const mainAxis = normalizeVariantValue(mapMainAxis?.value || '');
                    const groups = Array.from(mapGroups?.querySelectorAll('[data-hei-map-group]') || [])
                        .map((card) => {
                            return {
                                main_value: normalizeVariantValue(card.querySelector('[data-hei-map-main-value]')?.value || ''),
                                sub_axis: normalizeVariantValue(card.querySelector('[data-hei-map-sub-axis]')?.value || '') || 'Colour',
                                sub_values: variantValuesForCard(card),
                            };
                        })
                        .filter((group) => group.main_value || group.sub_values.length);

                    const commonVariants = Array.from(commonVariantList?.querySelectorAll('[data-hei-common-variant]') || [])
                        .map((card) => {
                            return {
                                name: normalizeVariantValue(card.querySelector('[data-hei-common-name]')?.value || ''),
                                values: variantValuesForCard(card),
                            };
                        })
                        .filter((group) => group.name || group.values.length);

                    if (!mainAxis && groups.length === 0 && commonVariants.length === 0) {
                        return null;
                    }

                    return {
                        mode: 'mapped',
                        main_axis: mainAxis || 'Main variant',
                        groups,
                        common_variants: commonVariants,
                    };
                };

                const aiVariantGroups = (suggestion) => {
                    const valuesByAxis = suggestion?.likely_variant_values || {};
                    const axes = Array.isArray(suggestion?.variant_axes) ? suggestion.variant_axes : [];

                    return axes
                        .map((axis) => {
                            const name = normalizeVariantValue(axis);
                            const rawValues = valuesByAxis?.[axis] || valuesByAxis?.[name] || [];
                            const values = Array.isArray(rawValues)
                                ? rawValues.map((value) => normalizeVariantValue(value)).filter(Boolean)
                                : [];
                            return { name, values };
                        })
                        .filter((group) => group.name || group.values.length);
                };

                const applyCatalogueProductTypeName = (name) => {
                    const cleanTypeName = (value) => normalizeVariantValue(value).toLocaleLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
                    const normalised = cleanTypeName(name);
                    const typeAliases = {
                        crochet: 'crochet twist loc hair',
                        'crochet hair': 'crochet twist loc hair',
                        'crochet braids': 'crochet twist loc hair',
                        twist: 'crochet twist loc hair',
                        locs: 'crochet twist loc hair',
                        braids: 'braiding hair',
                        braid: 'braiding hair',
                        'braid hair': 'braiding hair',
                        'braiding': 'braiding hair',
                        bulk: 'bulk hair',
                        weave: 'weave weft',
                        weft: 'weave weft',
                    };
                    const wanted = typeAliases[normalised] || normalised;
                    if (!wanted) return false;

                    const type = (selectedBrand()?.product_types || []).find(
                        (item) => cleanTypeName(item.name) === wanted,
                    );

                    if (!type) return false;

                    productTypeId.value = type.id;
                    productTypeSelect.value = type.id;
                    rebuildStyles();
                    return true;
                };

                const clearAiSuggestionCards = () => {
                    [aiProductSuggestion, aiTypeSuggestion, aiStyleSuggestion, aiSourceSuggestion, aiVariantSuggestion].forEach((target) => {
                        if (!target) return;
                        target.hidden = true;
                        target.innerHTML = '';
                    });
                };

                const normalizeSourceUrl = (value) => {
                    let url = String(value || '').trim();
                    if (!url) return '';
                    if (!/^https?:\/\//i.test(url)) {
                        url = `https://${url}`;
                    }
                    return url;
                };

                const collectSourceUrls = () => {
                    const seen = new Set();
                    return Array.from(sourceUrlList?.querySelectorAll('[data-hei-source-url-input]') || [])
                        .map((input) => normalizeSourceUrl(input.value))
                        .filter((url) => {
                            if (!url) return false;
                            const key = url.toLocaleLowerCase().replace(/\/+$/, '');
                            if (seen.has(key)) return false;
                            seen.add(key);
                            return true;
                        });
                };

                const syncSourceUrlFields = () => {
                    const urls = collectSourceUrls();
                    if (sourceUrlPrimary) sourceUrlPrimary.value = urls[0] || '';
                    if (sourceUrlJson) sourceUrlJson.value = JSON.stringify(urls);
                    return urls;
                };

                const addSourceUrlRow = (value = '', options = {}) => {
                    if (!sourceUrlList) return null;
                    const row = document.createElement('div');
                    row.className = 'hei-source-url-row';
                    row.innerHTML = `
                        <label class="hei-field">
                            <span>Verification URL</span>
                            <input type="url" data-hei-source-url-input placeholder="https://..." enterkeyhint="next" inputmode="url" autocomplete="off" value="${escapeHtml(value)}">
                        </label>
                        <button type="button" class="hei-btn danger" data-hei-remove-source-url aria-label="Remove verification URL">Remove</button>
                    `;
                    sourceUrlList.appendChild(row);
                    syncSourceUrlFields();
                    if (options.focus !== false) {
                        row.querySelector('[data-hei-source-url-input]')?.focus();
                    }
                    return row;
                };

                const renderSourceUrls = (urls = []) => {
                    if (!sourceUrlList) return;
                    sourceUrlList.innerHTML = '';
                    const cleanUrls = Array.isArray(urls) ? urls.map(normalizeSourceUrl).filter(Boolean) : [];
                    (cleanUrls.length ? cleanUrls : ['']).forEach((url) => addSourceUrlRow(url, { focus: false }));
                    syncSourceUrlFields();
                };

                renderSourceUrls();

                const setAiStatus = (message = '') => {
                    if (!aiStatus) return;
                    aiStatus.textContent = message;
                    aiStatus.hidden = !message;
                };

                const renderSectionSuggestion = (target, options) => {
                    if (!target || !options?.value) return;

                    target.hidden = false;
                    target.innerHTML = `
                        <article class="hei-ai-section-card">
                            <button type="button" class="hei-ai-section-dismiss" data-hei-ai-dismiss aria-label="Ignore AI suggestion">&times;</button>
                            <button type="button" class="hei-ai-section-use" data-hei-ai-use>
                                <span>${escapeHtml(options.label || 'AI suggestion')}</span>
                                <strong>${escapeHtml(options.value)}</strong>
                                ${options.detail ? `<small>${escapeHtml(options.detail)}</small>` : ''}
                            </button>
                        </article>
                    `;

                    target.querySelector('[data-hei-ai-use]')?.addEventListener('click', () => {
                        const applied = options.onApply?.();
                        if (applied === false) return;
                        target.hidden = true;
                        target.innerHTML = '';
                    });

                    target.querySelector('[data-hei-ai-dismiss]')?.addEventListener('click', () => {
                        target.hidden = true;
                        target.innerHTML = '';
                    });
                };

                const renderAiSuggestion = (suggestion, sources = [], model = '') => {
                    const confidence = String(suggestion?.confidence || 'D').toUpperCase().slice(0, 1) || 'D';
                    const variantGroups = aiVariantGroups(suggestion);
                    const sourceLinks = [...new Set([...(sources || []), ...(suggestion?.source_urls || [])])]
                        .filter(Boolean)
                        .slice(0, 5);

                    clearAiSuggestionCards();
                    setAiStatus(`AI ready: confidence ${confidence}${model ? ` / ${model}` : ''}`);

                    const reason = suggestion?.confidence_reason || suggestion?.notes || 'Click if it matches what you see.';
                    renderSectionSuggestion(aiProductSuggestion, {
                        label: `AI product name (${confidence})`,
                        value: suggestion?.suggested_product_name || '',
                        detail: reason,
                        onApply: () => {
                            if (suggestion?.suggested_product_name && productTypeName) {
                                productTypeName.value = suggestion.suggested_product_name;
                                productTypeUnknown.checked = false;
                                scheduleAutosave();
                                setStatus('AI product name applied', 'Review the observed product before submitting.');
                                return true;
                            }
                            return false;
                        },
                    });

                    renderSectionSuggestion(aiTypeSuggestion, {
                        label: `AI product type (${confidence})`,
                        value: suggestion?.product_type || '',
                        detail: 'Click to set the catalogue product type if it exists under this brand.',
                        onApply: () => {
                            const applied = applyCatalogueProductTypeName(suggestion.product_type);
                            if (!applied) {
                                setStatus('Type not in catalogue', `AI suggested "${suggestion.product_type}", but it is not an exact type under this brand.`);
                                return false;
                            }
                            scheduleAutosave();
                            setStatus('AI product type applied', 'Review the catalogue type before submitting.');
                            return true;
                        },
                    });

                    renderSectionSuggestion(aiStyleSuggestion, {
                        label: `AI style / family (${confidence})`,
                        value: suggestion?.style_family || '',
                        detail: (suggestion?.product_clues || []).slice(0, 4).join(', '),
                        onApply: () => {
                            if (suggestion?.style_family && styleName) {
                                styleName.value = suggestion.style_family;
                                styleUnknown.checked = false;
                                scheduleAutosave();
                                setStatus('AI style applied', 'Review the family/style before submitting.');
                                return true;
                            }
                            return false;
                        },
                    });

                    renderSectionSuggestion(aiVariantSuggestion, {
                        label: `AI variant suggestion (${confidence})`,
                        value: variantGroups.map((group) => `${group.name}: ${group.values.join(', ') || 'axis only'}`).join(' / '),
                        detail: 'Click to fill the simple variant list. Keep manual mapping if shop variants differ.',
                        onApply: () => {
                            if (variantGroups.length) {
                                variantList.innerHTML = '';
                                variantGroups.forEach((group) => addVariantGroup(group));
                                scheduleAutosave();
                                setStatus('AI variants applied', 'Review variant values against the shop shelf.');
                                return true;
                            }
                            return false;
                        },
                    });

                    renderSectionSuggestion(aiSourceSuggestion, {
                        label: `AI source (${confidence})`,
                        value: sourceLinks[0] || '',
                        detail: sourceLinks.slice(1, 4).join(' | '),
                        onApply: () => {
                            if (sourceLinks[0]) {
                                renderSourceUrls([...collectSourceUrls(), sourceLinks[0]]);
                                if (sourceUrlSectionDetails) sourceUrlSectionDetails.open = true;
                                scheduleAutosave();
                                setStatus('AI source applied', 'Source URL added for verification.');
                                return true;
                            }
                            return false;
                        },
                    });
                };

                const runAiSuggest = async () => {
                    const brand = brandName?.value?.trim() || selectedBrand()?.name || '';
                    const observed = productTypeName?.value?.trim() || '';
                    const source = collectSourceUrls()[0] || '';

                    if (!brand) {
                        setStatus('Brand required', 'Choose or type the brand before using AI suggest.');
                        brandName?.focus();
                        return;
                    }

                    if (!observed) {
                        setStatus('Product name required', 'Type the product name you see on the pack first.');
                        productTypeName?.focus();
                        return;
                    }

                    if (!aiLookupUrl) {
                        setStatus('AI unavailable', 'AI lookup URL is missing.');
                        return;
                    }

                    aiSuggestButton.disabled = true;
                    clearAiSuggestionCards();
                    setAiStatus('Checking internet sources...');
                    setStatus('AI checking product', 'Searching for a verified product match.');

                    try {
                        const response = await fetch(aiLookupUrl, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                            },
                            body: JSON.stringify({
                                hair_extension_intake_id: currentIntakeId,
                                brand_name: brand,
                                observed_product_name: observed,
                                source_url: source,
                                ai_model: aiModelSelect?.value || '',
                            }),
                        });
                        const data = await response.json().catch(() => ({}));

                        if (!response.ok || !data.ok) {
                            throw new Error(data.message || 'AI lookup failed.');
                        }

                        renderAiSuggestion(data.suggestion, data.source_urls || [], data.model || aiModelSelect?.value || '');
                        setStatus(`AI suggestion ready (${data.confidence || 'D'})`, `Model: ${data.model || aiModelSelect?.value || 'selected model'}. Click a suggestion button if it matches what you see.`);
                    } catch (error) {
                        setAiStatus(error.message || 'AI lookup failed.');
                        setStatus('AI lookup failed', error.message || 'Check OpenRouter setup.');
                    } finally {
                        aiSuggestButton.disabled = false;
                    }
                };

                const setPackagingStatus = (message = '', type = '') => {
                    if (!packagingStatus) return;
                    packagingStatus.hidden = !message;
                    packagingStatus.textContent = message;
                    packagingStatus.classList.toggle('is-good', type === 'good');
                    packagingStatus.classList.toggle('is-error', type === 'error');
                };

                const packagingNotesText = (result) => {
                    const facts = result?.product_facts || {};
                    const lines = [];
                    if (result?.structured_notes) {
                        lines.push(String(result.structured_notes).trim());
                    }

                    const factLines = [
                        ['Brand', facts.brand],
                        ['Product', facts.product_name],
                        ['Size / length', facts.size_or_length],
                        ['Colour / variant', facts.colour_or_variant],
                        ['Pack count', facts.pack_count],
                        ['Material / fibre', facts.material_or_fibre],
                        ['Barcode', facts.barcode],
                    ]
                        .filter(([, value]) => String(value || '').trim())
                        .map(([label, value]) => `${label}: ${value}`);

                    if (factLines.length) {
                        lines.push(factLines.join('\n'));
                    }

                    const claims = Array.isArray(facts.key_claims) ? facts.key_claims.filter(Boolean) : [];
                    if (claims.length) lines.push(`Key claims:\n- ${claims.join('\n- ')}`);

                    const directions = Array.isArray(facts.directions) ? facts.directions.filter(Boolean) : [];
                    if (directions.length) lines.push(`Directions:\n- ${directions.join('\n- ')}`);

                    const warnings = Array.isArray(facts.warnings) ? facts.warnings.filter(Boolean) : [];
                    if (warnings.length) lines.push(`Warnings:\n- ${warnings.join('\n- ')}`);

                    const unclear = Array.isArray(result?.unclear_text) ? result.unclear_text.filter(Boolean) : [];
                    if (unclear.length) lines.push(`Unclear text:\n- ${unclear.join('\n- ')}`);

                    return lines.filter(Boolean).join('\n\n').trim();
                };

                const renderPackagingResult = (result, model = '') => {
                    if (!packagingResult) return;
                    const notesText = packagingNotesText(result);
                    const detected = Array.isArray(result?.detected_text) ? result.detected_text : [];
                    const bullets = Array.isArray(result?.ecommerce_copy_candidates?.bullet_points)
                        ? result.ecommerce_copy_candidates.bullet_points
                        : [];
                    packagingResult.hidden = false;
                    packagingResult.innerHTML = `
                        <article class="hei-packaging-card">
                            <div class="hei-packaging-card-head">
                                <strong>Vision result ${result?.confidence ? `(${escapeHtml(result.confidence)})` : ''}</strong>
                                <small>${escapeHtml(model || packagingModelSelect?.value || '')}</small>
                            </div>
                            ${notesText ? `<pre>${escapeHtml(notesText)}</pre>` : '<p>No clear packaging text was extracted.</p>'}
                            ${detected.length ? `<details><summary>Raw detected text</summary><ul>${detected.map((text) => `<li>${escapeHtml(text)}</li>`).join('')}</ul></details>` : ''}
                            ${bullets.length ? `<details><summary>Ecommerce bullet candidates</summary><ul>${bullets.map((text) => `<li>${escapeHtml(text)}</li>`).join('')}</ul></details>` : ''}
                            <div class="hei-packaging-card-actions">
                                <button type="button" class="hei-btn primary" data-hei-packaging-apply>Apply to notes</button>
                                <button type="button" class="hei-btn secondary" data-hei-packaging-append>Append</button>
                                <button type="button" class="hei-btn linkish" data-hei-packaging-dismiss>Ignore</button>
                            </div>
                        </article>
                    `;

                    packagingResult.querySelector('[data-hei-packaging-apply]')?.addEventListener('click', () => {
                        const notesInput = form.querySelector('[data-hei-notes]');
                        if (!notesInput || !notesText) return;
                        notesInput.value = notesText;
                        scheduleAutosave();
                        setPackagingStatus('Packaging text applied to notes.', 'good');
                    });

                    packagingResult.querySelector('[data-hei-packaging-append]')?.addEventListener('click', () => {
                        const notesInput = form.querySelector('[data-hei-notes]');
                        if (!notesInput || !notesText) return;
                        notesInput.value = [notesInput.value.trim(), notesText].filter(Boolean).join('\n\n');
                        scheduleAutosave();
                        setPackagingStatus('Packaging text appended to notes.', 'good');
                    });

                    packagingResult.querySelector('[data-hei-packaging-dismiss]')?.addEventListener('click', () => {
                        packagingResult.hidden = true;
                        packagingResult.innerHTML = '';
                    });
                };

                const runPackagingVision = async (file) => {
                    if (!file) return;
                    if (!packagingTextUrl) {
                        setPackagingStatus('Packaging vision endpoint is missing.', 'error');
                        return;
                    }

                    const body = new FormData();
                    body.set('image', file);
                    body.set('brand_name', brandName?.value?.trim() || selectedBrand()?.name || '');
                    body.set('observed_product_name', productTypeName?.value?.trim() || '');
                    body.set('current_notes', form.querySelector('[data-hei-notes]')?.value || '');
                    body.set('ai_model', packagingModelSelect?.value || '');

                    setPackagingStatus('Reading packaging text...');
                    if (packagingResult) {
                        packagingResult.hidden = true;
                        packagingResult.innerHTML = '';
                    }

                    try {
                        const response = await fetch(packagingTextUrl, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body,
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok || !data.ok) {
                            throw new Error(data.message || 'Packaging text recognition failed.');
                        }
                        renderPackagingResult(data.result || {}, data.model || '');
                        setPackagingStatus(`Packaging text ready (${data.result?.confidence || 'D'}).`, 'good');
                    } catch (error) {
                        setPackagingStatus(error.message || 'Packaging text recognition failed.', 'error');
                    } finally {
                        if (packagingCameraInput) packagingCameraInput.value = '';
                        if (packagingUploadInput) packagingUploadInput.value = '';
                    }
                };

                const collectFormData = (options = {}) => {
                    variantJson.value = JSON.stringify(collectVariantGroups());
                    variantStructureJson.value = JSON.stringify(collectVariantStructure());
                    syncSourceUrlFields();
                    const data = new FormData(form);

                    if (!pendingPhoto || !options.includePhoto) {
                        data.delete('product_photo');
                    }

                    if (options.removePhoto) {
                        data.set('remove_photo', '1');
                    }

                    return data;
                };

                const draftSnapshot = () => ({
                    id: currentIntakeId,
                    status: isSubmitted ? 'submitted' : 'draft',
                    brand_catalogue_brand_id: brandSelect?.value || '',
                    brand_name: brandName?.value || '',
                    brand_catalogue_product_type_id: productTypeId?.value || '',
                    observed_product_name: productTypeName?.value || '',
                    product_type_name: productTypeName?.value || '',
                    product_type_unknown: Boolean(productTypeUnknown?.checked),
                    brand_catalogue_style_id: styleId?.value || '',
                    style_name: styleName?.value || '',
                    style_unknown: Boolean(styleUnknown?.checked),
                    variant_groups: collectVariantGroups(),
                    variant_structure: collectVariantStructure(),
                    source_url: collectSourceUrls()[0] || '',
                    verification_urls: collectSourceUrls(),
                    visible_text_notes: form.querySelector('[data-hei-notes]')?.value || '',
                    phone_photo_role: phonePhotoRole?.value || '',
                    phone_photo_notes: phonePhotoNotes?.value || '',
                    photo_url: null,
                    photos: [],
                    last_synced_at: new Date().toISOString(),
                    saved_locally_at: new Date().toISOString(),
                });

                const hasDraftMinimum = () => Boolean(
                    brandName?.value?.trim() ||
                    brandSelect?.value
                );

                const hasMeaningfulDraftContent = (record) => Boolean(
                    record?.brand_name ||
                    record?.brand_catalogue_brand_id
                );

                const saveLocalDraft = () => {
                    if (restoringDraft || isSubmitted) return;
                    const snapshot = draftSnapshot();
                    if (!hasMeaningfulDraftContent(snapshot)) {
                        clearLocalDraft();
                        return;
                    }
                    localStorage.setItem(LOCAL_DRAFT_KEY, JSON.stringify(snapshot));
                    if (currentIntakeId) {
                        localStorage.setItem(ACTIVE_DRAFT_KEY, String(currentIntakeId));
                    }
                };

                const clearLocalDraft = () => {
                    localStorage.removeItem(ACTIVE_DRAFT_KEY);
                    localStorage.removeItem(LOCAL_DRAFT_KEY);
                };

                const detachEmptyDraftState = () => {
                    currentIntakeId = null;
                    autosaveUrl = root.dataset.autosaveUrl;
                    submitUrl = null;
                    pendingPhoto = false;
                    clearLocalDraft();
                };

                const readLocalDraft = () => {
                    try {
                        return JSON.parse(localStorage.getItem(LOCAL_DRAFT_KEY) || 'null');
                    } catch {
                        return null;
                    }
                };

                const applyPhotoPreview = (url) => {
                    if (!photoPreview) return;
                    photoPreview.innerHTML = url ? `<img src="${escapeHtml(url)}" alt="">` : 'No photo';
                };

                const hasPhotoEvidence = (intakeLike) =>
                    Boolean(
                        intakeLike?.photo_url ||
                            (Array.isArray(intakeLike?.photos) && intakeLike.photos.length > 0),
                    );

                const syncPhotoSectionForLoadedRecord = (intakeLike) => {
                    if (!photoSectionDetails) return;
                    photoSectionDetails.open = hasPhotoEvidence(intakeLike);
                };

                const expandPhotoSectionIfEvidence = (intakeLike) => {
                    if (!photoSectionDetails || !hasPhotoEvidence(intakeLike)) return;
                    photoSectionDetails.open = true;
                };

                const sourceUrlsForRecord = (recordLike) => {
                    const urls = Array.isArray(recordLike?.verification_urls) ? [...recordLike.verification_urls] : [];
                    if (recordLike?.source_url) urls.push(recordLike.source_url);
                    return [...new Set(urls.map(normalizeSourceUrl).filter(Boolean))];
                };

                const hasSourceUrlEvidence = (recordLike) => sourceUrlsForRecord(recordLike).length > 0;

                const syncSourceSectionForLoadedRecord = (recordLike) => {
                    if (!sourceUrlSectionDetails) return;
                    sourceUrlSectionDetails.open = hasSourceUrlEvidence(recordLike);
                };

                const expandSourceSectionIfUrl = (rawUrl) => {
                    if (!sourceUrlSectionDetails || !normalizeSourceUrl(rawUrl)) return;
                    sourceUrlSectionDetails.open = true;
                };

                const renderIntakePhotos = (photos = []) => {
                    if (!intakePhotoGrid) return;

                    if (!Array.isArray(photos) || photos.length === 0) {
                        intakePhotoGrid.innerHTML = '<div class="hei-intake-photo-empty">No phone evidence photos yet.</div>';
                        return;
                    }

                    intakePhotoGrid.innerHTML = photos.map((photo) => `
                        <a class="hei-intake-photo-card" href="${escapeHtml(photo.url || '#')}" target="_blank" rel="noopener">
                            <span class="hei-intake-photo-thumb">
                                ${photo.url ? `<img src="${escapeHtml(photo.url)}" alt="${escapeHtml(photo.role_label || 'Product evidence photo')}">` : 'No photo'}
                            </span>
                            <span class="hei-intake-photo-copy">
                                <strong>${escapeHtml(photo.role_label || 'Evidence photo')}</strong>
                                <small>${escapeHtml(photo.notes || photo.source_label || 'Phone camera')}</small>
                            </span>
                        </a>
                    `).join('');
                };

                const refreshIntakePhotos = async () => {
                    if (!currentIntakeId) return;
                    const response = await fetch(`${pageBaseUrl}/${currentIntakeId}/photos`, {
                        headers: { Accept: 'application/json' },
                    });
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok || !data.ok) {
                        throw new Error(data.message || 'Unable to refresh intake photos.');
                    }
                    renderIntakePhotos(data.photos || []);
                    applyPhotoPreview(data.photo_url);
                    expandPhotoSectionIfEvidence({
                        photos: data.photos,
                        photo_url: data.photo_url,
                    });
                };

                const applyDraftRecord = (record, options = {}) => {
                    restoringDraft = true;
                    const reopenedSubmitted = record.status === 'submitted' && options.reopenForEdit;
                    currentIntakeId = record.id || null;
                    autosaveUrl = currentIntakeId ? `${pageBaseUrl}/${currentIntakeId}/autosave` : root.dataset.autosaveUrl;
                    submitUrl = currentIntakeId ? `${pageBaseUrl}/${currentIntakeId}/submit` : null;
                    isSubmitted = record.status === 'submitted' && !reopenedSubmitted;
                    submitButtons.forEach((btn) => {
                        btn.disabled = false;
                    });

                    brandSelect.value = record.brand_catalogue_brand_id || '';
                    syncBrandSearchDisplay();
                    brandName.value = record.brand_name || '';
                    rebuildProductTypes();
                    productTypeId.value = record.brand_catalogue_product_type_id || '';
                    productTypeSelect.value = record.brand_catalogue_product_type_id || '';
                    productTypeName.value = record.observed_product_name || record.product_type_name || '';
                    productTypeUnknown.checked = Boolean(record.product_type_unknown);
                    rebuildStyles();
                    styleId.value = record.brand_catalogue_style_id || '';
                    styleSelect.value = record.brand_catalogue_style_id || '';
                    styleName.value = record.style_name || '';
                    styleUnknown.checked = Boolean(record.style_unknown);
                    renderCatalogueVariantAssist();
                    renderSourceUrls(sourceUrlsForRecord(record));
                    syncSourceSectionForLoadedRecord(record);
                    form.querySelector('[data-hei-notes]').value = record.visible_text_notes || '';
                    if (phonePhotoRole && record.phone_photo_role) {
                        phonePhotoRole.value = record.phone_photo_role;
                    }
                    if (phonePhotoNotes) {
                        phonePhotoNotes.value = record.phone_photo_notes || '';
                    }
                    variantList.innerHTML = '';
                    (record.variant_groups || []).forEach((group) => addVariantGroup(group));
                    if (!variantList.children.length) {
                        addVariantGroup({ name: 'Colour', values: [] });
                        addVariantGroup({ name: 'Length', values: [] });
                    }
                    if (mapMainAxis) {
                        mapMainAxis.value = record.variant_structure?.main_axis || '';
                    }
                    if (mapGroups) {
                        mapGroups.innerHTML = '';
                        (record.variant_structure?.groups || []).forEach((group) => addMappedVariantGroup(group));
                    }
                    if (commonVariantList) {
                        commonVariantList.innerHTML = '';
                        (record.variant_structure?.common_variants || []).forEach((group) => addCommonVariantGroup(group));
                    }
                    applyPhotoPreview(record.photo_url);
                    renderIntakePhotos(record.photos || []);
                    syncPhotoSectionForLoadedRecord(record);

                    if (currentIntakeId && !isSubmitted && !reopenedSubmitted) {
                        localStorage.setItem(ACTIVE_DRAFT_KEY, String(currentIntakeId));
                    }

                    const statusTitle = options.statusTitle || (reopenedSubmitted
                        ? `Editing submitted #${currentIntakeId}`
                        : (
                        isSubmitted ? `Submitted #${currentIntakeId}` : currentIntakeId ? `Draft #${currentIntakeId}` : 'Recovered local draft'
                        )
                    );
                    setStatus(statusTitle, options.statusDetail || record.last_synced_at || 'Loaded.');
                    restoringDraft = false;

                    if (options.scroll !== false) {
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }
                };

                const restoreSavedDraft = async () => {
                    const activeId = localStorage.getItem(ACTIVE_DRAFT_KEY);
                    const localDraft = readLocalDraft();
                    const recentDraft = activeId
                        ? recentIntakes.find((item) => String(item.id) === String(activeId))
                        : null;

                    if (recentDraft) {
                        if (recentDraft.status === 'submitted') {
                            if (localDraft && String(localDraft.id) === String(activeId) && hasMeaningfulDraftContent(localDraft)) {
                                localDraft.status = 'draft';
                                applyDraftRecord(localDraft, {
                                    scroll: false,
                                    statusTitle: `Recovered edit #${activeId}`,
                                    statusDetail: 'Syncing your submitted-product edits back to this PC...',
                                });
                                autosave().catch((error) => setStatus('Sync failed', error.message));
                                return;
                            }

                            clearLocalDraft();
                            return;
                        }

                        applyDraftRecord(recentDraft, {
                            scroll: false,
                            statusDetail: 'Restored after refresh.',
                        });
                        return;
                    }

                    if (activeId) {
                        try {
                            const response = await fetch(`${pageBaseUrl}/${activeId}/draft`, {
                                headers: { Accept: 'application/json' },
                            });
                            const data = await response.json().catch(() => ({}));

                            if (response.ok && data.ok && data.intake?.status !== 'submitted') {
                                if (!hasMeaningfulDraftContent(data.intake)) {
                                    if (localDraft && String(localDraft.id) === String(activeId) && hasMeaningfulDraftContent(localDraft)) {
                                        localDraft.id = null;
                                        localDraft.status = 'draft';
                                        applyDraftRecord(localDraft, {
                                            scroll: false,
                                            statusTitle: 'Recovered local draft',
                                            statusDetail: 'Syncing it back to this PC...',
                                        });
                                        autosave().catch((error) => setStatus('Sync failed', error.message));
                                        return;
                                    }

                                    clearLocalDraft();
                                    return;
                                }

                                applyDraftRecord(data.intake, {
                                    scroll: false,
                                    statusDetail: 'Restored after refresh.',
                                });
                                return;
                            }

                            if (response.ok && data.ok && data.intake?.status === 'submitted' && localDraft && String(localDraft.id) === String(activeId) && hasMeaningfulDraftContent(localDraft)) {
                                localDraft.status = 'draft';
                                applyDraftRecord(localDraft, {
                                    scroll: false,
                                    statusTitle: `Recovered edit #${activeId}`,
                                    statusDetail: 'Syncing your submitted-product edits back to this PC...',
                                });
                                autosave().catch((error) => setStatus('Sync failed', error.message));
                                return;
                            }
                        } catch {
                            // Local browser backup is used below if the database draft cannot be fetched.
                        }
                    }

                    if (localDraft && hasMeaningfulDraftContent(localDraft)) {
                        localDraft.id = null;
                        localDraft.status = 'draft';
                        applyDraftRecord(localDraft, {
                            scroll: false,
                            statusTitle: 'Recovered local draft',
                            statusDetail: 'Syncing it back to this PC...',
                        });
                        autosave().catch((error) => setStatus('Sync failed', error.message));
                    } else if (localDraft) {
                        clearLocalDraft();
                    }
                };

                const fetchIntakeRecord = async (intakeId) => {
                    const response = await fetch(`${pageBaseUrl}/${intakeId}/draft`, {
                        headers: { Accept: 'application/json' },
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data.ok) {
                        throw new Error(data.message || 'Unable to load this intake.');
                    }

                    return data.intake;
                };

                const loadDraftById = async (intakeId, options = {}) => {
                    const intake = await fetchIntakeRecord(intakeId);
                    const record = normalizeIntakeRecord(intake);
                    upsertRecentRecord(record);

                    if (record.status === 'submitted' && options.reopenForEdit) {
                        clearLocalDraft();
                    }
                    applyDraftRecord(record, options);
                    if (!(record.status === 'submitted' && options.reopenForEdit)) {
                        saveLocalDraft();
                    }
                };

                const autosave = async (options = {}) => {
                    if (isSubmitted) return;

                    if (!hasDraftMinimum()) {
                        clearTimeout(syncTimer);
                        detachEmptyDraftState();
                        setStatus('Brand required to start draft', 'Enter the brand first. Nothing has been saved yet.');
                        return;
                    }

                    setStatus('Syncing…', 'Saving to this PC.');

                    const response = await fetch(autosaveUrl, {
                        method: currentIntakeId ? 'PATCH' : 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: collectFormData(options),
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data.ok) {
                        throw new Error(data.message || 'Autosave failed.');
                    }

                    if (!data.intake) {
                        setStatus('Brand required to start draft', data.message || 'Enter the brand first.');
                        return;
                    }

                    currentIntakeId = data.intake.id;
                    autosaveUrl = data.autosave_url;
                    submitUrl = data.submit_url;
                    localStorage.setItem(ACTIVE_DRAFT_KEY, String(currentIntakeId));
                    pendingPhoto = false;
                    if (photoInput) photoInput.value = '';
                    applyPhotoPreview(data.intake.photo_url);
                    renderIntakePhotos(data.intake.photos || []);
                    expandPhotoSectionIfEvidence(data.intake);
                    if (hasSourceUrlEvidence(data.intake)) {
                        expandSourceSectionIfUrl(data.intake?.source_url || data.intake?.verification_urls?.[0]);
                    }
                    saveLocalDraft();
                    setStatus(`Draft #${currentIntakeId}`, data.intake.last_synced_at || 'Saved.');
                };

                const scheduleAutosave = () => {
                    if (isSubmitted) return;
                    if (!hasDraftMinimum()) {
                        detachEmptyDraftState();
                        clearTimeout(syncTimer);
                        setStatus('Brand required to start draft', 'Enter the brand first. Nothing has been saved yet.');
                        return;
                    }
                    saveLocalDraft();
                    clearTimeout(syncTimer);
                    setStatus(
                        currentIntakeId ? `Draft #${currentIntakeId}` : 'New draft',
                        'Waiting to sync…',
                    );
                    syncTimer = setTimeout(() => autosave().catch((error) => setStatus('Sync failed', error.message)), 650);
                };

                brandSearchInput?.addEventListener('focus', () => {
                    clearTimeout(brandBlurCloseTimer);
                    refreshBrandListFromInput();
                    setBrandListOpen(true);
                });

                brandSearchInput?.addEventListener('blur', () => {
                    brandBlurCloseTimer = setTimeout(() => {
                        setBrandListOpen(false);
                        syncBrandSearchDisplay();
                    }, 150);
                });

                brandSearchInput?.addEventListener('input', () => {
                    if (!brandSelect) return;
                    const sel = selectedBrand();
                    if (sel && brandSearchInput.value.trim() !== sel.name) {
                        const hadId = Boolean(brandSelect.value);
                        brandSelect.value = '';
                        if (hadId) {
                            cascadeCatalogueBrandUnset();
                            scheduleAutosave();
                        }
                    }
                    refreshBrandListFromInput();
                    setBrandListOpen(true);
                });

                brandSearchInput?.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        if (brandListbox && !brandListbox.hidden) {
                            event.preventDefault();
                            setBrandListOpen(false);
                            syncBrandSearchDisplay();
                        }
                        return;
                    }
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        const raw = brandSearchInput?.value || '';
                        const { trimmed, overflowExtra } = getBrandSuggestions(raw);
                        if (!brandListbox || brandListbox.hidden) {
                            brandListHighlight = trimmed.length ? 0 : -1;
                            renderBrandListbox(trimmed, raw, overflowExtra, brandListHighlight);
                            setBrandListOpen(true);
                            return;
                        }
                        if (trimmed.length) {
                            const next = brandListHighlight < 0 ? 0 : (brandListHighlight + 1) % trimmed.length;
                            renderBrandListbox(trimmed, raw, overflowExtra, next);
                        }
                        return;
                    }
                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        const raw = brandSearchInput?.value || '';
                        const { trimmed, overflowExtra } = getBrandSuggestions(raw);
                        if (!brandListbox || brandListbox.hidden) {
                            const last = trimmed.length ? trimmed.length - 1 : -1;
                            renderBrandListbox(trimmed, raw, overflowExtra, last);
                            setBrandListOpen(true);
                            return;
                        }
                        if (trimmed.length) {
                            const next = brandListHighlight <= 0 ? trimmed.length - 1 : brandListHighlight - 1;
                            renderBrandListbox(trimmed, raw, overflowExtra, next);
                        }
                        return;
                    }
                    if (event.key === 'Enter' && brandListbox && !brandListbox.hidden) {
                        const pick = brandListFiltered[brandListHighlight];
                        if (pick) {
                            event.preventDefault();
                            commitCatalogueBrandId(pick.id, { fromUser: true });
                        }
                    }
                });

                brandListbox?.addEventListener('mousedown', (event) => {
                    const opt = event.target.closest('[data-hei-brand-option]');
                    if (!opt) return;
                    event.preventDefault();
                    commitCatalogueBrandId(opt.getAttribute('data-hei-brand-option'), { fromUser: true });
                });

                document.addEventListener(
                    'mousedown',
                    (event) => {
                        if (!brandComboboxEl?.contains(event.target)) {
                            setBrandListOpen(false);
                        }
                    },
                    true,
                );

                productTypeSelect?.addEventListener('change', () => {
                    if (productTypeSelect.value === '__unknown') {
                        productTypeId.value = '';
                        productTypeName.value = '';
                        productTypeUnknown.checked = true;
                    } else {
                        const option = productTypeSelect.selectedOptions[0];
                        productTypeId.value = productTypeSelect.value || '';
                        productTypeName.value = option?.textContent || '';
                        productTypeUnknown.checked = false;
                    }
                    styleId.value = '';
                    styleName.value = '';
                    rebuildStyles();
                    renderCatalogueVariantAssist();
                    scheduleAutosave();
                });

                styleSelect?.addEventListener('change', () => {
                    if (styleSelect.value === '__unknown') {
                        styleId.value = '';
                        styleName.value = '';
                        styleUnknown.checked = true;
                    } else {
                        const option = styleSelect.selectedOptions[0];
                        styleId.value = styleSelect.value || '';
                        styleName.value = option?.textContent || '';
                        styleUnknown.checked = false;
                    }
                    renderCatalogueVariantAssist();
                    scheduleAutosave();
                });

                form.addEventListener('input', (event) => {
                    if (event.target.matches('[type=file]')) return;
                    scheduleAutosave();
                });

                form.addEventListener('change', (event) => {
                    if (event.target.matches('[type=file]')) return;
                    scheduleAutosave();
                });

                addVariantButton?.addEventListener('click', () => {
                    addVariantGroup();
                    scheduleAutosave();
                });

                addSourceUrlButton?.addEventListener('click', () => {
                    addSourceUrlRow('', { focus: true });
                    if (sourceUrlSectionDetails) sourceUrlSectionDetails.open = true;
                    scheduleAutosave();
                });

                addMapGroupButton?.addEventListener('click', () => {
                    addMappedVariantGroup({
                        main_value: '',
                        sub_axis: 'Colour',
                        sub_values: [],
                    });
                    if (!mapMainAxis?.value) {
                        mapMainAxis.value = 'Length';
                    }
                    scheduleAutosave();
                });

                addCommonVariantButton?.addEventListener('click', () => {
                    addCommonVariantGroup({
                        name: '',
                        values: [],
                    });
                    scheduleAutosave();
                });

                applyCatalogueVariantMatrixButton?.addEventListener('click', () => {
                    applyCatalogueVariantMatrix();
                });

                root.querySelectorAll('[data-hei-map-axis-preset]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const name = btn.getAttribute('data-hei-map-axis-preset') || '';
                        if (mapMainAxis) {
                            mapMainAxis.value = name;
                        }
                        scheduleAutosave();
                        mapGroups?.querySelector('[data-hei-map-main-value]')?.focus();
                    });
                });

                root.addEventListener('click', (event) => {
                    const removeSourceUrlButton = event.target.closest('[data-hei-remove-source-url]');
                    if (removeSourceUrlButton) {
                        const row = removeSourceUrlButton.closest('.hei-source-url-row');
                        row?.remove();
                        if (!sourceUrlList?.querySelector('[data-hei-source-url-input]')) {
                            addSourceUrlRow('', { focus: false });
                        }
                        syncSourceUrlFields();
                        scheduleAutosave();
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

                    const vmSubPreset = event.target.closest('[data-hei-vm-sub-preset]');
                    if (vmSubPreset) {
                        const name = vmSubPreset.getAttribute('data-hei-vm-sub-preset') || '';
                        const inputs = mapGroups?.querySelectorAll('[data-hei-map-sub-axis]') ?? [];
                        if (inputs.length) {
                            inputs.forEach((input) => {
                                input.value = name;
                            });
                        } else {
                            addMappedVariantGroup({
                                main_value: '',
                                sub_axis: name,
                                sub_values: [],
                            });
                            if (!mapMainAxis?.value) {
                                mapMainAxis.value = 'Length';
                            }
                        }
                        scheduleAutosave();
                        mapGroups?.querySelector('[data-hei-map-main-value]')?.focus();
                        return;
                    }

                    const vmCommonPreset = event.target.closest('[data-hei-vm-common-preset]');
                    if (vmCommonPreset) {
                        const name = vmCommonPreset.getAttribute('data-hei-vm-common-preset') || '';
                        const inputs = commonVariantList?.querySelectorAll('[data-hei-common-name]') ?? [];
                        if (inputs.length) {
                            inputs.forEach((input) => {
                                input.value = name;
                            });
                        } else {
                            addCommonVariantGroup({ name, values: [] });
                        }
                        scheduleAutosave();
                        commonVariantList?.querySelector('[data-hei-variant-values]')?.focus();
                        return;
                    }

                    const subAxisPreset = event.target.closest('[data-hei-map-sub-axis-preset]');
                    if (subAxisPreset) {
                        const card = subAxisPreset.closest('[data-hei-map-group]');
                        const input = card?.querySelector('[data-hei-map-sub-axis]');
                        if (input) {
                            input.value = subAxisPreset.getAttribute('data-hei-map-sub-axis-preset') || '';
                            scheduleAutosave();
                            card.querySelector('[data-hei-variant-values]')?.focus();
                        }
                        return;
                    }

                    const commonPreset = event.target.closest('[data-hei-common-name-preset]');
                    if (commonPreset) {
                        const card = commonPreset.closest('[data-hei-common-variant]');
                        const input = card?.querySelector('[data-hei-common-name]');
                        if (input) {
                            input.value = commonPreset.getAttribute('data-hei-common-name-preset') || '';
                            scheduleAutosave();
                            card.querySelector('[data-hei-variant-values]')?.focus();
                        }
                    }
                });

                root.addEventListener('click', (event) => {
                    const chip = event.target.closest('[data-hei-variant-chip]');
                    if (chip) {
                        chip.remove();
                        scheduleAutosave();
                        return;
                    }

                    const mapRemoveButton = event.target.closest('[data-hei-remove-map-group]');
                    if (mapRemoveButton) {
                        mapRemoveButton.closest('[data-hei-map-group]')?.remove();
                        scheduleAutosave();
                        return;
                    }

                    const commonRemoveButton = event.target.closest('[data-hei-remove-common-variant]');
                    if (commonRemoveButton) {
                        commonRemoveButton.closest('[data-hei-common-variant]')?.remove();
                        scheduleAutosave();
                        return;
                    }

                    const button = event.target.closest('[data-hei-remove-variant]');
                    if (!button) return;
                    button.closest('.hei-variant-card')?.remove();
                    scheduleAutosave();
                });

                root.addEventListener('input', (event) => {
                    const input = event.target.closest('[data-hei-variant-values]');
                    if (!input) return;
                    consumeVariantInput(input, false);
                });

                root.addEventListener('keydown', (event) => {
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

                photoInput?.addEventListener('change', async () => {
                    const file = photoInput.files?.[0];
                    if (!file) return;
                    pendingPhoto = true;
                    applyPhotoPreview(URL.createObjectURL(file));
                    try {
                        await autosave({ includePhoto: true });
                    } catch (error) {
                        setStatus('Photo sync failed', error.message);
                    }
                });

                removePhotoButton?.addEventListener('click', async () => {
                    try {
                        await autosave({ removePhoto: true });
                    } catch (error) {
                        setStatus('Photo remove failed', error.message);
                    }
                });

                const setPhonePhotoStatus = (message, type = '') => {
                    if (!phonePhotoStatus) return;
                    phonePhotoStatus.hidden = !message;
                    phonePhotoStatus.textContent = message || '';
                    phonePhotoStatus.classList.toggle('is-good', type === 'good');
                    phonePhotoStatus.classList.toggle('is-error', type === 'error');
                };

                const ensureIntakeForPhotos = async () => {
                    if (currentIntakeId && hasDraftMinimum()) return;
                    if (!hasDraftMinimum()) {
                        brandName?.focus();
                        setPhonePhotoStatus('Enter the brand before adding photos.', 'error');
                        throw new Error('Enter the brand before adding photos.');
                    }
                    setPhonePhotoStatus('Creating draft before saving photos...');
                    await autosave();
                };

                const uploadDirectIntakePhotos = async (fileList) => {
                    const files = Array.from(fileList || []).filter(Boolean);
                    if (!files.length) return;

                    try {
                        await ensureIntakeForPhotos();
                        if (!currentIntakeId) {
                            throw new Error('Create or autosave the draft before adding photos.');
                        }

                        const body = new FormData();
                        files.forEach((file) => body.append('photos[]', file));
                        body.set('image_role', phonePhotoRole?.value || 'packaging_front');
                        body.set('source_label', 'Phone intake page');
                        body.set('notes', phonePhotoNotes?.value || '');
                        body.set('is_primary', (phonePhotoRole?.value === 'main' && files.length === 1) ? '1' : '0');

                        setPhonePhotoStatus(files.length === 1 ? 'Saving photo...' : `Saving ${files.length} photos...`);
                        const response = await fetch(`${pageBaseUrl}/${currentIntakeId}/photos`, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body,
                        });
                        const data = await response.json().catch(() => ({}));

                        if (!response.ok || !data.ok) {
                            throw new Error(data.message || 'Photo upload failed.');
                        }

                        renderIntakePhotos(data.photos || []);
                        applyPhotoPreview(data.photo_url);
                        expandPhotoSectionIfEvidence(data);
                        saveLocalDraft();
                        setPhonePhotoStatus(data.message || 'Photo saved.', 'good');
                    } catch (error) {
                        setPhonePhotoStatus(error.message || 'Photo upload failed.', 'error');
                    } finally {
                        if (directPhotoInput) directPhotoInput.value = '';
                        if (directPhotoGallery) directPhotoGallery.value = '';
                    }
                };

                directPhotoInput?.addEventListener('change', () => {
                    uploadDirectIntakePhotos(directPhotoInput.files);
                });

                directPhotoGallery?.addEventListener('change', () => {
                    uploadDirectIntakePhotos(directPhotoGallery.files);
                });

                const normalizeIntakeRecord = (intake) => ({
                    id: intake.id,
                    status: intake.status || 'draft',
                    brand_catalogue_brand_id: intake.brand_catalogue_brand_id || intake.brand?.catalogue_brand_id || '',
                    brand_name: intake.brand_name || intake.brand?.name || '',
                    brand_catalogue_product_type_id: intake.brand_catalogue_product_type_id || intake.product_type?.catalogue_product_type_id || '',
                    observed_product_name: intake.observed_product_name || intake.product_type?.observed_product_name || intake.product_type_name || '',
                    product_type_name: intake.observed_product_name || intake.product_type?.observed_product_name || intake.product_type_name || '',
                    product_type_unknown: Boolean(intake.product_type_unknown ?? intake.product_type?.unknown),
                    brand_catalogue_style_id: intake.brand_catalogue_style_id || intake.style_family?.catalogue_style_id || '',
                    style_name: intake.style_name || intake.style_family?.name || '',
                    style_unknown: Boolean(intake.style_unknown ?? intake.style_family?.unknown),
                    variant_groups: intake.variant_groups || [],
                    variant_structure: intake.variant_structure || null,
                    source_url: intake.source_url || '',
                    verification_urls: intake.verification_urls || [],
                    visible_text_notes: intake.visible_text_notes || '',
                    photo_url: intake.photo_url || '',
                    photos: intake.photos || [],
                    last_synced_at: intake.last_synced_at || intake.submitted_at || intake.created_at || '',
                });

                const ucfirstStr = (str) => (str ? str.charAt(0).toUpperCase() + str.slice(1) : '');

                const buildRecentCard = (intake) => {
                    const record = normalizeIntakeRecord(intake);
                    const article = document.createElement('article');
                    article.className = 'hei-recent-card';
                    const statusLabel = ucfirstStr(record.status || 'draft');
                    article.innerHTML = `
                        <strong>${escapeHtml(record.brand_name || 'Unknown brand')}</strong>
                        <span>${escapeHtml(record.style_name || 'Unknown style')}</span>
                        <span>${statusLabel} · just now</span>
                        <div class="hei-recent-actions">
                            <button type="button" class="hei-btn secondary hei-btn-view" data-hei-view-intake="${record.id}" aria-label="View intake ${record.id}">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M12 5c5 0 8.5 4.2 9.6 6.1a1.8 1.8 0 0 1 0 1.8C20.5 14.8 17 19 12 19s-8.5-4.2-9.6-6.1a1.8 1.8 0 0 1 0-1.8C3.5 9.2 7 5 12 5Zm0 2c-3.9 0-6.8 3.2-7.8 5 1 1.8 3.9 5 7.8 5s6.8-3.2 7.8-5c-1-1.8-3.9-5-7.8-5Zm0 2.2a2.8 2.8 0 1 1 0 5.6 2.8 2.8 0 0 1 0-5.6Z"/>
                                </svg>
                                View
                            </button>
                            <button type="button" class="hei-btn danger" data-hei-delete-intake="${record.id}">Delete</button>
                        </div>
                    `;

                    return { article, record };
                };

                const upsertRecentRecord = (record) => {
                    const index = recentIntakes.findIndex((item) => String(item.id) === String(record.id));
                    if (index === -1) {
                        recentIntakes.unshift(record);
                    } else {
                        recentIntakes.splice(index, 1, record);
                    }
                };

                const prependToRecentPanel = (intake) => {
                    const { article, record } = buildRecentCard(intake);
                    upsertRecentRecord(record);
                    const recentGrid = root.querySelector('.hei-recent-grid');
                    if (recentGrid) {
                        recentGrid.querySelector('.hei-help')?.remove();
                        recentGrid.querySelector(`[data-hei-delete-intake="${record.id}"]`)?.closest('.hei-recent-card')?.remove();
                        recentGrid.prepend(article);
                    }
                    const recentPanel = document.getElementById('hei-recent-panel');
                    if (recentPanel) recentPanel.open = true;
                };

                const renderPreviewField = (label, value) => `
                    <div class="hei-preview-field">
                        <span>${escapeHtml(label)}</span>
                        <strong>${escapeHtml(value || 'Not set')}</strong>
                    </div>
                `;

                const renderPreviewTags = (values = []) => {
                    if (!Array.isArray(values) || values.length === 0) {
                        return '<span class="hei-preview-empty">No values captured</span>';
                    }

                    return `
                        <div class="hei-preview-tags">
                            ${values.map((value) => `<span>${escapeHtml(value)}</span>`).join('')}
                        </div>
                    `;
                };

                const renderPreviewVariants = (record) => {
                    const groups = Array.isArray(record.variant_groups) ? record.variant_groups : [];
                    const mappedGroups = record.variant_structure?.groups || [];
                    const matrix = record.variant_structure?.sku_matrix || [];

                    if (!groups.length && !mappedGroups.length && !matrix.length) {
                        return '<div class="hei-preview-empty">No variants captured yet.</div>';
                    }

                    const simpleGroups = groups.map((group) => `
                        <section class="hei-preview-variant-group">
                            <h4>${escapeHtml(group.name || 'Variant')}</h4>
                            ${renderPreviewTags(group.values || [])}
                        </section>
                    `).join('');

                    const mapped = mappedGroups.length ? `
                        <section class="hei-preview-variant-group">
                            <h4>Mapped variants</h4>
                            ${mappedGroups.map((group) => `
                                <div class="hei-preview-map-row">
                                    <strong>${escapeHtml(record.variant_structure?.main_axis || 'Main')} ${escapeHtml(group.main_value || '')}</strong>
                                    <span>${escapeHtml(group.sub_axis || 'Variant')}</span>
                                    ${renderPreviewTags(group.sub_values || [])}
                                </div>
                            `).join('')}
                        </section>
                    ` : '';

                    const matrixPreview = matrix.length ? `
                        <section class="hei-preview-variant-group">
                            <h4>Sellable combinations (${matrix.length})</h4>
                            <div class="hei-preview-combo-list">
                                ${matrix.slice(0, 18).map((row) => `
                                    <span>${escapeHtml([row.main_value, row.sub_value].filter(Boolean).join(' / ') || 'Combination')}</span>
                                `).join('')}
                                ${matrix.length > 18 ? `<span>+${matrix.length - 18} more</span>` : ''}
                            </div>
                        </section>
                    ` : '';

                    return simpleGroups + mapped + matrixPreview;
                };

                const renderPreviewPhotos = (record) => {
                    const photos = Array.isArray(record.photos) ? record.photos : [];
                    if (!photos.length && !record.photo_url) {
                        return '<div class="hei-preview-empty">No photos saved.</div>';
                    }

                    const photoList = photos.length ? photos : [{
                        url: record.photo_url,
                        role_label: 'Fallback photo',
                        notes: '',
                    }];

                    return `
                        <div class="hei-preview-photo-grid">
                            ${photoList.map((photo) => `
                                <a href="${escapeHtml(photo.url || '#')}" target="_blank" rel="noopener">
                                    <span>${photo.url ? `<img src="${escapeHtml(photo.url)}" alt="${escapeHtml(photo.role_label || 'Product photo')}">` : 'No photo'}</span>
                                    <strong>${escapeHtml(photo.role_label || 'Product photo')}</strong>
                                    <small>${escapeHtml(photo.notes || photo.source_label || '')}</small>
                                </a>
                            `).join('')}
                        </div>
                    `;
                };

                const renderIntakePreview = (record) => {
                    if (!previewModal || !previewBody) return;

                    previewRecord = normalizeIntakeRecord(record);
                    const statusLabel = ucfirstStr(previewRecord.status || 'draft');
                    if (previewTitle) {
                        previewTitle.textContent = previewRecord.style_name || previewRecord.brand_name || `Intake #${previewRecord.id}`;
                    }
                    if (previewStatus) {
                        previewStatus.textContent = `${statusLabel} database record #${previewRecord.id}`;
                    }
                    if (previewSubmitButton) {
                        const alreadySubmitted = previewRecord.status === 'submitted';
                        previewSubmitButton.hidden = alreadySubmitted;
                        previewSubmitButton.disabled = alreadySubmitted;
                    }

                    previewBody.innerHTML = `
                        <section class="hei-preview-summary">
                            ${renderPreviewField('Brand', previewRecord.brand_name)}
                            ${renderPreviewField('Observed product name', previewRecord.product_type_name || (previewRecord.product_type_unknown ? 'Unknown' : ''))}
                            ${renderPreviewField('Style / family', previewRecord.style_name || (previewRecord.style_unknown ? 'Unknown' : ''))}
                            ${renderPreviewField('Last saved', previewRecord.last_synced_at)}
                        </section>

                        <section class="hei-preview-section">
                            <h4>Variants</h4>
                            ${renderPreviewVariants(previewRecord)}
                        </section>

                        <section class="hei-preview-section">
                            <h4>Photos</h4>
                            ${renderPreviewPhotos(previewRecord)}
                        </section>

                        <section class="hei-preview-section">
                            <h4>Notes</h4>
                            <p>${escapeHtml(previewRecord.visible_text_notes || 'No notes captured.')}</p>
                            ${sourceUrlsForRecord(previewRecord).map((url, index) => `<a href="${escapeHtml(url)}" target="_blank" rel="noopener">Open verification URL ${index + 1}</a>`).join('')}
                        </section>
                    `;
                };

                const closeIntakePreview = () => {
                    if (!previewModal) return;
                    previewModal.hidden = true;
                    document.body.classList.remove('is-modal-open');
                    previewRecord = null;
                };

                const openIntakePreview = async (intakeId) => {
                    if (!previewModal || !previewBody) return;

                    previewModal.hidden = false;
                    document.body.classList.add('is-modal-open');
                    previewBody.innerHTML = '<div class="hei-preview-empty">Loading saved database record...</div>';
                    if (previewTitle) previewTitle.textContent = 'Saved intake';
                    if (previewStatus) previewStatus.textContent = `Database record #${intakeId}`;

                    const intake = await fetchIntakeRecord(intakeId);
                    const record = normalizeIntakeRecord(intake);
                    upsertRecentRecord(record);
                    renderIntakePreview(record);
                };

                const submitPreviewRecord = async () => {
                    if (!previewRecord || previewRecord.status === 'submitted') return;
                    if (!previewRecord.brand_name?.trim()) {
                        setStatus('Brand required', 'Load this draft and add the brand before submitting.');
                        return;
                    }

                    previewSubmitButton.disabled = true;
                    try {
                        const body = new FormData();
                        body.set('brand_catalogue_brand_id', previewRecord.brand_catalogue_brand_id || '');
                        body.set('brand_name', previewRecord.brand_name || '');
                        body.set('brand_catalogue_product_type_id', previewRecord.brand_catalogue_product_type_id || '');
                        body.set('observed_product_name', previewRecord.observed_product_name || previewRecord.product_type_name || '');
                        body.set('product_type_name', previewRecord.product_type_name || '');
                        body.set('product_type_unknown', previewRecord.product_type_unknown ? '1' : '0');
                        body.set('brand_catalogue_style_id', previewRecord.brand_catalogue_style_id || '');
                        body.set('style_name', previewRecord.style_name || '');
                        body.set('style_unknown', previewRecord.style_unknown ? '1' : '0');
                        body.set('variant_groups', JSON.stringify(previewRecord.variant_groups || []));
                        body.set('variant_structure', previewRecord.variant_structure ? JSON.stringify(previewRecord.variant_structure) : '');
                        body.set('source_url', sourceUrlsForRecord(previewRecord)[0] || '');
                        body.set('verification_urls', JSON.stringify(sourceUrlsForRecord(previewRecord)));
                        body.set('visible_text_notes', previewRecord.visible_text_notes || '');

                        const response = await fetch(`${pageBaseUrl}/${previewRecord.id}/submit`, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body,
                        });
                        const data = await response.json().catch(() => ({}));

                        if (!response.ok || !data.ok) {
                            throw new Error(data.message || 'Submit failed.');
                        }

                        const submitted = normalizeIntakeRecord(data.intake);
                        prependToRecentPanel(submitted);
                        renderIntakePreview(submitted);
                        bumpSessionCount();
                        setStatus(`Submitted #${submitted.id}`, 'Queued for AI catalogue.');
                    } catch (error) {
                        setStatus('Submit failed', error.message || 'Unable to submit this record.');
                    } finally {
                        if (previewSubmitButton && previewRecord?.status !== 'submitted') {
                            previewSubmitButton.disabled = false;
                        }
                    }
                };

                const runSubmit = async () => {
                    if (!(brandName?.value?.trim())) {
                        brandName?.focus();
                        brandName?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        setStatus('Brand required', 'Enter the brand name before submitting.');
                        return;
                    }

                    submitButtons.forEach((btn) => {
                        btn.disabled = true;
                    });

                    try {
                        if (!currentIntakeId) {
                            await autosave();
                        }

                        const response = await fetch(submitUrl, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: collectFormData({ includePhoto: pendingPhoto }),
                        });
                        const data = await response.json().catch(() => ({}));

                        if (!response.ok || !data.ok) {
                            throw new Error(data.message || 'Submit failed.');
                        }

                        isSubmitted = true;
                        clearLocalDraft();
                        bumpSessionCount();
                        setStatus(`Submitted #${data.intake.id}`, 'Queued for AI catalogue.');
                        const dockNewBtn = root.querySelector('[data-hei-dock-new]');
                        if (dockNewBtn) {
                            dockNewBtn.hidden = false;
                            submitButtons.forEach((btn) => {
                                if (btn.closest('.hei-mobile-dock')) btn.hidden = true;
                            });
                        }
                        prependToRecentPanel(data.intake);
                    } catch (error) {
                        setStatus('Submit failed', error.message);
                    } finally {
                        submitButtons.forEach((btn) => {
                            btn.disabled = false;
                        });
                    }
                };

                const runCancel = async () => {
                    if (!confirm('Cancel this intake? Any saved draft and photos for this intake will be deleted.')) {
                        return;
                    }

                    cancelButtons.forEach((btn) => {
                        btn.disabled = true;
                    });
                    clearTimeout(syncTimer);

                    try {
                        if (currentIntakeId) {
                            const response = await fetch(`${pageBaseUrl}/${currentIntakeId}`, {
                                method: 'DELETE',
                                headers: {
                                    Accept: 'application/json',
                                    'X-CSRF-TOKEN': csrf,
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });
                            const data = await response.json().catch(() => ({}));

                            if (!response.ok || !data.ok) {
                                throw new Error(data.message || 'Cancel failed.');
                            }
                        }

                        clearLocalDraft();
                        window.location.href = @json(route('hair-extension-intake.submitted'));
                    } catch (error) {
                        cancelButtons.forEach((btn) => {
                            btn.disabled = false;
                        });
                        setStatus('Cancel failed', error.message || 'Unable to cancel this intake.');
                    }
                };

                submitButtons.forEach((btn) => {
                    btn.addEventListener('click', runSubmit);
                });

                cancelButtons.forEach((btn) => {
                    btn.addEventListener('click', runCancel);
                });

                aiSuggestButton?.addEventListener('click', runAiSuggest);
                packagingCameraInput?.addEventListener('change', () => {
                    runPackagingVision(packagingCameraInput.files?.[0]);
                });
                packagingUploadInput?.addEventListener('change', () => {
                    runPackagingVision(packagingUploadInput.files?.[0]);
                });

                newButton?.addEventListener('click', () => {
                    clearLocalDraft();
                    window.location.href = window.location.pathname;
                });

                root.querySelector('[data-hei-dock-new]')?.addEventListener('click', () => {
                    clearLocalDraft();
                    window.location.href = window.location.pathname;
                });

                root.querySelectorAll('[data-hei-preview-close]').forEach((button) => {
                    button.addEventListener('click', closeIntakePreview);
                });

                previewLoadButton?.addEventListener('click', async () => {
                    if (!previewRecord) return;
                    const wasSubmitted = previewRecord.status === 'submitted';
                    previewLoadButton.disabled = true;
                    try {
                        await loadDraftById(previewRecord.id, {
                            reopenForEdit: wasSubmitted,
                            statusDetail: wasSubmitted
                                ? 'Edit and submit again when ready.'
                                : 'Loaded fresh from the database.',
                        });
                        closeIntakePreview();
                    } catch (error) {
                        setStatus('Load failed', error.message || 'Unable to load this intake from the database.');
                    } finally {
                        previewLoadButton.disabled = false;
                    }
                });

                previewSubmitButton?.addEventListener('click', submitPreviewRecord);

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && previewModal && !previewModal.hidden) {
                        closeIntakePreview();
                    }
                });

                root.addEventListener('click', async (event) => {
                    const viewBtn = event.target.closest('[data-hei-view-intake]');
                    if (viewBtn) {
                        viewBtn.disabled = true;
                        try {
                            await openIntakePreview(viewBtn.dataset.heiViewIntake);
                        } catch (error) {
                            closeIntakePreview();
                            setStatus('Preview failed', error.message || 'Unable to view this database record.');
                        } finally {
                            viewBtn.disabled = false;
                        }
                        return;
                    }

                    const loadBtn = event.target.closest('[data-hei-load-intake]');
                    if (loadBtn) {
                        const record = recentIntakes.find((item) => String(item.id) === String(loadBtn.dataset.heiLoadIntake));
                        const wasSubmitted = record?.status === 'submitted';
                        loadBtn.disabled = true;
                        try {
                            await loadDraftById(loadBtn.dataset.heiLoadIntake, {
                                reopenForEdit: wasSubmitted,
                                statusDetail: wasSubmitted
                                    ? 'Edit and submit again when ready.'
                                    : 'Loaded fresh from the database.',
                            });
                        } catch (error) {
                            setStatus('Load failed', error.message || 'Unable to load this intake from the database.');
                        } finally {
                            loadBtn.disabled = false;
                        }
                        return;
                    }

                    const deleteBtn = event.target.closest('[data-hei-delete-intake]');
                    if (!deleteBtn) return;

                    const intakeId = deleteBtn.dataset.heiDeleteIntake;
                    if (!confirm('Delete this intake record from the database, including its saved photos?')) return;

                    deleteBtn.disabled = true;
                    try {
                        const response = await fetch(`${pageBaseUrl}/${intakeId}`, {
                            method: 'DELETE',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        const data = await response.json().catch(() => ({}));

                        if (!response.ok || !data.ok) {
                            throw new Error(data.message || 'Delete failed.');
                        }

                        const idx = recentIntakes.findIndex((record) => String(record.id) === String(intakeId));
                        if (idx !== -1) recentIntakes.splice(idx, 1);
                        deleteBtn.closest('.hei-recent-card')?.remove();

                        if (localStorage.getItem(ACTIVE_DRAFT_KEY) === String(intakeId)) {
                            clearLocalDraft();
                        }

                        if (String(currentIntakeId) === String(intakeId)) {
                            clearLocalDraft();
                            window.location.href = window.location.pathname;
                            return;
                        }

                        setStatus('Deleted from database', `Intake #${intakeId} and its photos were removed.`);
                    } catch (error) {
                        deleteBtn.disabled = false;
                        alert(error.message || 'Delete failed.');
                    }
                });

                ensureDatalist();
                addVariantGroup({ name: 'Colour', values: [] });
                addVariantGroup({ name: 'Length', values: [] });
                rebuildProductTypes();
                rebuildStyles();
                renderCatalogueVariantAssist();
                renderIntakePhotos([]);
                refreshSessionCount();

                if (requestedEditId) {
                    loadDraftById(requestedEditId, {
                        reopenForEdit: true,
                        scroll: false,
                        statusDetail: 'Edit and submit again when ready.',
                    }).catch((error) => setStatus('Edit load failed', error.message));
                } else {
                    restoreSavedDraft().catch((error) => setStatus('Draft restore failed', error.message));
                }

                const recentPanel = document.getElementById('hei-recent-panel');
                if (recentPanel && window.matchMedia('(min-width: 768px)').matches) {
                    recentPanel.open = true;
                }
            })();
        </script>
    </section>
@endsection
