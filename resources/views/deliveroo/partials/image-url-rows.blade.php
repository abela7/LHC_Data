@php
    $apiOld = old('image_urls');
    if (is_array($apiOld)) {
        $imageUrlList = array_values(array_filter($apiOld, static fn ($v) => is_string($v)));
    } else {
        $imageUrlList = array_values(array_filter($initialImageUrls ?? [], static fn ($v) => is_string($v) && $v !== ''));
    }
@endphp
<fieldset class="deliveroo-gallery-fieldset w-full min-w-0">
    <legend class="deliveroo-gallery-legend">{{ __('deliveroo.manual_product.image_urls_label') }}</legend>
    @error('image_urls')
        <p class="deliveroo-gallery-error" role="alert">{{ $message }}</p>
    @enderror
    <p class="deliveroo-gallery-intro">{{ __('deliveroo.manual_product.image_urls_rows_help') }}</p>
    <div
        class="deliveroo-gallery-root"
        data-deliveroo-image-rows
        data-max-rows="40"
        data-upload-url="{{ $imageUploadUrl ?? '' }}"
        data-label-main="{{ e(__('deliveroo.manual_product.image_slot_main')) }}"
        data-label-extra="{{ e(__('deliveroo.manual_product.image_slot_extra')) }}"
        data-preview-empty="{{ e(__('deliveroo.manual_product.image_preview_empty')) }}"
        data-preview-broken="{{ e(__('deliveroo.manual_product.image_preview_broken')) }}"
        data-uploading-label="{{ e(__('deliveroo.manual_product.image_upload_uploading')) }}"
        data-upload-success-label="{{ e(__('deliveroo.manual_product.image_upload_success')) }}"
        data-upload-error-label="{{ e(__('deliveroo.manual_product.image_upload_error')) }}"
        data-upload-invalid-label="{{ e(__('deliveroo.manual_product.image_upload_invalid')) }}"
        data-upload-file-invalid-label="{{ e(__('deliveroo.manual_product.image_upload_file_invalid')) }}"
        data-upload-maxed-label="{{ e(__('deliveroo.manual_product.image_upload_maxed')) }}"
    >
        <ul class="deliveroo-gallery-list" data-image-rows-list>
            @forelse ($imageUrlList as $url)
                <li class="deliveroo-gallery-card" data-image-row draggable="true">
                    <div class="deliveroo-gallery-card-inner">
                        <div class="deliveroo-gallery-card-top">
                            <div class="deliveroo-gallery-meta">
                                <span
                                    class="deliveroo-gallery-grip"
                                    aria-hidden="true"
                                    title="{{ __('deliveroo.manual_product.image_drag_hint') }}"
                                >
                                    <svg width="14" height="18" viewBox="0 0 14 18" fill="currentColor" aria-hidden="true">
                                        <circle cx="4" cy="4" r="1.5" /><circle cx="10" cy="4" r="1.5" />
                                        <circle cx="4" cy="9" r="1.5" /><circle cx="10" cy="9" r="1.5" />
                                        <circle cx="4" cy="14" r="1.5" /><circle cx="10" cy="14" r="1.5" />
                                    </svg>
                                </span>
                                <span class="deliveroo-gallery-badge" data-image-row-label></span>
                            </div>
                            <div class="deliveroo-gallery-actions" role="group" aria-label="{{ __('deliveroo.manual_product.image_row_actions_aria') }}">
                                <button
                                    type="button"
                                    class="deliveroo-gallery-icon-btn"
                                    data-image-row-up
                                    aria-label="{{ __('deliveroo.manual_product.image_row_up') }}"
                                >
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 15l-6-6-6 6"/></svg>
                                </button>
                                <button
                                    type="button"
                                    class="deliveroo-gallery-icon-btn"
                                    data-image-row-down
                                    aria-label="{{ __('deliveroo.manual_product.image_row_down') }}"
                                >
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                                </button>
                                <button
                                    type="button"
                                    class="deliveroo-gallery-icon-btn deliveroo-gallery-icon-btn--danger"
                                    data-image-row-remove
                                    aria-label="{{ __('deliveroo.manual_product.image_row_remove') }}"
                                >
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m-1 0v14H9V6h6z"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="deliveroo-gallery-card-main">
                            <div class="deliveroo-gallery-thumb">
                                <img
                                    class="deliveroo-gallery-thumb-img"
                                    data-image-preview
                                    alt=""
                                    width="96"
                                    height="96"
                                    loading="lazy"
                                    hidden
                                >
                                <span class="deliveroo-gallery-thumb-fallback" data-image-preview-fallback></span>
                            </div>
                            <label class="deliveroo-gallery-url-field">
                                <span class="deliveroo-gallery-url-label">{{ __('deliveroo.manual_product.image_url_field_label') }}</span>
                                <input
                                    type="text"
                                    name="image_urls[]"
                                    class="deliveroo-gallery-input"
                                    data-image-url-input
                                    value="{{ $url }}"
                                    autocomplete="off"
                                    placeholder="{{ __('deliveroo.manual_product.image_urls_placeholder') }}"
                                >
                            </label>
                        </div>
                    </div>
                </li>
            @empty
                <li class="deliveroo-gallery-card" data-image-row draggable="true">
                    <div class="deliveroo-gallery-card-inner">
                        <div class="deliveroo-gallery-card-top">
                            <div class="deliveroo-gallery-meta">
                                <span
                                    class="deliveroo-gallery-grip"
                                    aria-hidden="true"
                                    title="{{ __('deliveroo.manual_product.image_drag_hint') }}"
                                >
                                    <svg width="14" height="18" viewBox="0 0 14 18" fill="currentColor" aria-hidden="true">
                                        <circle cx="4" cy="4" r="1.5" /><circle cx="10" cy="4" r="1.5" />
                                        <circle cx="4" cy="9" r="1.5" /><circle cx="10" cy="9" r="1.5" />
                                        <circle cx="4" cy="14" r="1.5" /><circle cx="10" cy="14" r="1.5" />
                                    </svg>
                                </span>
                                <span class="deliveroo-gallery-badge" data-image-row-label></span>
                            </div>
                            <div class="deliveroo-gallery-actions" role="group" aria-label="{{ __('deliveroo.manual_product.image_row_actions_aria') }}">
                                <button type="button" class="deliveroo-gallery-icon-btn" data-image-row-up aria-label="{{ __('deliveroo.manual_product.image_row_up') }}">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 15l-6-6-6 6"/></svg>
                                </button>
                                <button type="button" class="deliveroo-gallery-icon-btn" data-image-row-down aria-label="{{ __('deliveroo.manual_product.image_row_down') }}">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                                </button>
                                <button type="button" class="deliveroo-gallery-icon-btn deliveroo-gallery-icon-btn--danger" data-image-row-remove aria-label="{{ __('deliveroo.manual_product.image_row_remove') }}">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m-1 0v14H9V6h6z"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="deliveroo-gallery-card-main">
                            <div class="deliveroo-gallery-thumb">
                                <img
                                    class="deliveroo-gallery-thumb-img"
                                    data-image-preview
                                    alt=""
                                    width="96"
                                    height="96"
                                    loading="lazy"
                                    hidden
                                >
                                <span class="deliveroo-gallery-thumb-fallback" data-image-preview-fallback></span>
                            </div>
                            <label class="deliveroo-gallery-url-field">
                                <span class="deliveroo-gallery-url-label">{{ __('deliveroo.manual_product.image_url_field_label') }}</span>
                                <input
                                    type="text"
                                    name="image_urls[]"
                                    class="deliveroo-gallery-input"
                                    data-image-url-input
                                    value=""
                                    autocomplete="off"
                                    placeholder="{{ __('deliveroo.manual_product.image_urls_placeholder') }}"
                                >
                            </label>
                        </div>
                    </div>
                </li>
            @endforelse
        </ul>
        <button type="button" class="deliveroo-gallery-add" data-image-row-add>
            <span class="deliveroo-gallery-add-icon" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            </span>
            {{ __('deliveroo.manual_product.image_row_add') }}
        </button>
        @if (! empty($imageUploadUrl))
            <div class="deliveroo-gallery-paste-wrap">
                <div class="deliveroo-gallery-upload-grid">
                    <div
                        class="deliveroo-gallery-paste-target"
                        data-image-paste-target
                        tabindex="0"
                        role="button"
                        aria-label="{{ __('deliveroo.manual_product.image_upload_paste_title') }}"
                    >
                        <span class="deliveroo-gallery-paste-title">{{ __('deliveroo.manual_product.image_upload_paste_title') }}</span>
                        <span class="deliveroo-gallery-paste-text">{{ __('deliveroo.manual_product.image_upload_paste_help') }}</span>
                    </div>
                    <div class="deliveroo-gallery-file-wrap">
                        <button type="button" class="deliveroo-gallery-file-button" data-image-file-trigger>
                            {{ __('deliveroo.manual_product.image_upload_file_button') }}
                        </button>
                        <input
                            type="file"
                            class="hidden"
                            data-image-file-input
                            accept="image/*"
                            aria-label="{{ __('deliveroo.manual_product.image_upload_file_button') }}"
                        >
                        <span class="deliveroo-gallery-paste-text">{{ __('deliveroo.manual_product.image_upload_file_help') }}</span>
                    </div>
                </div>
                <p class="deliveroo-gallery-paste-status" data-image-paste-status hidden></p>
            </div>
        @endif
        <template data-image-row-template>
            <li class="deliveroo-gallery-card" data-image-row draggable="true">
                <div class="deliveroo-gallery-card-inner">
                    <div class="deliveroo-gallery-card-top">
                        <div class="deliveroo-gallery-meta">
                            <span class="deliveroo-gallery-grip" aria-hidden="true" title="{{ __('deliveroo.manual_product.image_drag_hint') }}">
                                <svg width="14" height="18" viewBox="0 0 14 18" fill="currentColor" aria-hidden="true">
                                    <circle cx="4" cy="4" r="1.5" /><circle cx="10" cy="4" r="1.5" />
                                    <circle cx="4" cy="9" r="1.5" /><circle cx="10" cy="9" r="1.5" />
                                    <circle cx="4" cy="14" r="1.5" /><circle cx="10" cy="14" r="1.5" />
                                </svg>
                            </span>
                            <span class="deliveroo-gallery-badge" data-image-row-label></span>
                        </div>
                        <div class="deliveroo-gallery-actions" role="group" aria-label="{{ __('deliveroo.manual_product.image_row_actions_aria') }}">
                            <button type="button" class="deliveroo-gallery-icon-btn" data-image-row-up aria-label="{{ __('deliveroo.manual_product.image_row_up') }}">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 15l-6-6-6 6"/></svg>
                            </button>
                            <button type="button" class="deliveroo-gallery-icon-btn" data-image-row-down aria-label="{{ __('deliveroo.manual_product.image_row_down') }}">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                            </button>
                            <button type="button" class="deliveroo-gallery-icon-btn deliveroo-gallery-icon-btn--danger" data-image-row-remove aria-label="{{ __('deliveroo.manual_product.image_row_remove') }}">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m-1 0v14H9V6h6z"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="deliveroo-gallery-card-main">
                        <div class="deliveroo-gallery-thumb">
                            <img
                                class="deliveroo-gallery-thumb-img"
                                data-image-preview
                                alt=""
                                width="96"
                                height="96"
                                loading="lazy"
                                hidden
                            >
                            <span class="deliveroo-gallery-thumb-fallback" data-image-preview-fallback></span>
                        </div>
                        <label class="deliveroo-gallery-url-field">
                            <span class="deliveroo-gallery-url-label">{{ __('deliveroo.manual_product.image_url_field_label') }}</span>
                            <input
                                type="text"
                                name="image_urls[]"
                                class="deliveroo-gallery-input"
                                data-image-url-input
                                value=""
                                autocomplete="off"
                                placeholder="{{ __('deliveroo.manual_product.image_urls_placeholder') }}"
                            >
                        </label>
                    </div>
                </div>
            </li>
        </template>
    </div>
    <p class="deliveroo-gallery-footnote">{{ __('deliveroo.manual_product.image_urls_help') }}</p>
</fieldset>
