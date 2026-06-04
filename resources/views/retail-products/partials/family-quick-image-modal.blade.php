@php
    $quickImageChipRoles = ['main', 'variant', 'gallery', 'detail', 'packaging', 'barcode'];
    $quickImageChipOptions = collect($mediaRoles)->whereIn('value', $quickImageChipRoles)->values();
@endphp

<div class="rfm-quick-overlay" data-rfm-quick-image-modal hidden aria-hidden="true">
    <button type="button" class="rfm-quick-backdrop" data-rfm-quick-image-close aria-label="Close"></button>
    <section class="rfm-quick-panel rfm-quick-image-panel" role="dialog" aria-modal="true" aria-label="Add SKU image">
        <header class="rfm-quick-image-head">
            <div class="rfm-quick-image-head-copy">
                <span class="rfm-quick-image-eyebrow">Product image</span>
                <strong class="rfm-quick-image-title" data-rfm-quick-image-title>Add image</strong>
            </div>
            <button type="button" class="rfm-quick-image-close" data-rfm-quick-image-close aria-label="Close">×</button>
        </header>

        <div class="rfm-quick-image-layout">
            <aside class="rfm-quick-image-aside">
                <div class="rfm-quick-image-preview-zone">
                    <div class="rfm-quick-image-preview" data-rfm-quick-image-preview-current>
                        <span class="rfm-quick-image-preview-label">Current</span>
                        <button type="button"
                                class="rfm-quick-image-preview-frame"
                                data-rfm-quick-image-view-open="current"
                                hidden
                                aria-label="View current image full size">
                            <img src="" alt="" data-rfm-quick-image-current-img>
                            <span class="rfm-quick-image-preview-zoom" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="7"></circle>
                                    <path d="m20 20-3.5-3.5M11 8v6M8 11h6" stroke-linecap="round"></path>
                                </svg>
                            </span>
                        </button>
                        <div class="rfm-quick-image-preview-frame is-empty" data-rfm-quick-image-preview-empty-wrap>
                            <p class="rfm-quick-image-preview-empty" data-rfm-quick-image-preview-empty>No image yet</p>
                        </div>
                    </div>
                    <div class="rfm-quick-image-preview" data-rfm-quick-image-preview-next hidden>
                        <span class="rfm-quick-image-preview-label">New photo</span>
                        <button type="button"
                                class="rfm-quick-image-preview-frame"
                                data-rfm-quick-image-view-open="next"
                                aria-label="View new photo full size">
                            <img src="" alt="" data-rfm-quick-image-next-img>
                            <span class="rfm-quick-image-preview-zoom" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="7"></circle>
                                    <path d="m20 20-3.5-3.5M11 8v6M8 11h6" stroke-linecap="round"></path>
                                </svg>
                            </span>
                        </button>
                    </div>
                </div>

                <div class="rfm-quick-image-preview-actions">
                    <button type="button" class="rfm-quick-image-remove" data-rfm-quick-image-delete hidden>
                        Remove current image
                    </button>
                </div>

                <section class="rfm-quick-image-saved" data-rfm-quick-image-saved hidden>
                    <div class="rfm-quick-image-saved-head">
                        <span data-rfm-quick-image-saved-title>Saved photos</span>
                        <em data-rfm-quick-image-saved-count>0</em>
                    </div>
                    <div class="rfm-quick-image-saved-grid" data-rfm-quick-image-saved-grid></div>
                    <p class="rfm-quick-image-saved-empty" data-rfm-quick-image-saved-empty>No saved photos yet.</p>
                </section>
            </aside>

            <div class="rfm-quick-image-main">
                <div class="rfm-quick-image-workflow" data-rfm-quick-image-workflow role="group" aria-label="Suggested photo sequence">
                    <button type="button" class="rfm-quick-image-workflow-step" data-rfm-quick-image-workflow-step="main">
                        <em>1</em><span>Main</span>
                    </button>
                    <button type="button" class="rfm-quick-image-workflow-step" data-rfm-quick-image-workflow-step="variant">
                        <em>2</em><span>Variant</span>
                    </button>
                    <button type="button" class="rfm-quick-image-workflow-step" data-rfm-quick-image-workflow-step="gallery">
                        <em>3</em><span>Gallery</span>
                    </button>
                </div>

                <div class="rfm-media rfm-quick-image-media" data-rfm-media-manager>
            <form method="POST"
                  action="#"
                  enctype="multipart/form-data"
                  class="rfm-media-add rfm-quick-image-form"
                  data-mobile-capture-job-url="{{ route('mobile-capture.jobs.store') }}"
                  data-rfm-media-add-form
                  data-rfm-quick-image-form>
                @csrf
                <input type="hidden" name="quick_image_mode" value="1">
                <input type="hidden" name="mobile_capture_destination" value="retail">
                <input type="hidden" name="mobile_capture_target_type" value="retail_product" data-rfm-quick-image-mobile-target-type>
                <input type="hidden" name="mobile_capture_target_id" value="" data-rfm-quick-image-mobile-target>
                <input type="hidden" name="usage_context" value="all" data-rfm-quick-image-usage>

                <select name="image_role" class="sr-only" tabindex="-1" aria-hidden="true" data-rfm-quick-image-role-select required>
                    @foreach ($mediaRoles as $role)
                        <option value="{{ $role['value'] }}" @selected($role['value'] === 'main')>{{ $role['label'] }}</option>
                    @endforeach
                </select>

                <fieldset class="rfm-quick-image-purpose">
                    <legend>Image purpose</legend>
                    <div class="rfm-quick-image-purpose-chips" data-rfm-quick-image-purpose-chips>
                        @foreach ($quickImageChipOptions as $role)
                            <button type="button"
                                    class="rfm-quick-image-purpose-chip"
                                    data-rfm-quick-image-role-chip="{{ $role['value'] }}">
                                {{ $role['label'] }}
                            </button>
                        @endforeach
                    </div>
                    <details class="rfm-quick-image-purpose-more">
                        <summary>More purposes</summary>
                        <label>
                            <span>All purpose types</span>
                            <select data-rfm-quick-image-role-picker>
                                @foreach ($mediaRoles as $role)
                                    <option value="{{ $role['value'] }}">{{ $role['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    </details>
                </fieldset>

                <div class="rfm-media-tabs" role="tablist" aria-label="Image source">
                    <button type="button" class="rfm-media-tab is-active" data-rfm-media-tab="camera" role="tab" aria-selected="true">Camera</button>
                    <button type="button" class="rfm-media-tab" data-rfm-media-tab="phone" role="tab" aria-selected="false">Phone</button>
                    <button type="button" class="rfm-media-tab" data-rfm-media-tab="upload" role="tab" aria-selected="false">Upload</button>
                    <button type="button" class="rfm-media-tab" data-rfm-media-tab="url" role="tab" aria-selected="false">URL</button>
                    <button type="button" class="rfm-media-tab" data-rfm-media-tab="paste" role="tab" aria-selected="false">Paste</button>
                </div>

                <div class="rfm-media-tab-panels">
                    <div class="rfm-media-tab-panel is-active" data-rfm-media-panel="camera">
                        <label class="rfm-media-capture">
                            <input type="file" name="uploaded_image" accept="image/*" capture="environment" data-rfm-camera>
                            <span class="rfm-media-capture-title">Take photo</span>
                            <small class="rfm-media-capture-hint">Opens the camera on your phone.</small>
                        </label>
                    </div>

                    <div class="rfm-media-tab-panel" data-rfm-media-panel="phone">
                        <div class="rfm-phone-capture">
                            <strong>Send to phone</strong>
                            <span>Opens Mobile Capture on your phone for this SKU.</span>
                            <button type="button" class="rfm-phone-create" data-rfm-phone-create>Send to phone camera</button>
                            <label class="rfm-phone-url" hidden>
                                <span>Fallback link</span>
                                <input type="text" readonly data-rfm-phone-url>
                            </label>
                            <p class="rfm-phone-status" data-rfm-phone-status hidden></p>
                        </div>
                    </div>

                    <div class="rfm-media-tab-panel" data-rfm-media-panel="upload">
                        <label class="rfm-media-capture">
                            <input type="file" name="uploaded_image_alt" accept="image/*" multiple data-rfm-upload>
                            <span class="rfm-media-capture-title">Choose file(s)</span>
                            <small class="rfm-media-capture-hint">JPEG, PNG, WebP or GIF — up to 35 MB. Pick several for gallery.</small>
                        </label>
                    </div>

                    <div class="rfm-media-tab-panel" data-rfm-media-panel="url">
                        <label class="rfm-media-tab-url-field">
                            <span>Paste image link</span>
                            <input type="url" name="external_url" placeholder="https://example.com/photo.jpg" inputmode="url" autocomplete="off">
                        </label>
                        <label class="rfm-media-inline-check">
                            <input type="checkbox" name="mirror_external" value="1" checked>
                            <span>Save a local copy</span>
                        </label>
                    </div>

                    <div class="rfm-media-tab-panel" data-rfm-media-panel="paste">
                        <div class="rfm-media-paste" tabindex="0" data-rfm-media-paste>
                            <strong>Tap here, then paste</strong>
                            <span>Ctrl+V / long-press paste on mobile.</span>
                        </div>
                        <p class="rfm-media-paste-status" data-rfm-media-paste-status hidden></p>
                    </div>
                </div>

                <div class="rfm-media-file-preview" data-rfm-file-preview hidden>
                    <img src="" alt="" data-rfm-file-preview-img>
                    <div>
                        <strong data-rfm-file-preview-name>Selected photo</strong>
                        <span data-rfm-file-preview-meta></span>
                    </div>
                    <button type="button" class="rfm-secondary-btn" data-rfm-file-clear>Change</button>
                </div>

                <details class="rfm-media-meta-fields rfm-quick-image-meta">
                    <summary>Optional details</summary>
                    <div class="rfm-grid">
                        <label>
                            <span>Use on</span>
                            <select data-rfm-quick-image-usage-picker>
                                @foreach ($mediaUsageContexts as $ctx)
                                    <option value="{{ $ctx['value'] }}" @selected($ctx['value'] === 'all')>{{ $ctx['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            <span>Source label</span>
                            <input type="text" name="source_label" placeholder="Shop photo, supplier…">
                        </label>
                        <label class="rfm-grid-wide">
                            <span>Notes</span>
                            <input type="text" name="notes" placeholder="Front pack, colour swatch…">
                        </label>
                    </div>
                    <label class="rfm-media-inline-check">
                        <input type="checkbox" name="is_primary" value="1" checked data-rfm-quick-image-primary>
                        <span>Use as primary thumbnail</span>
                    </label>
                </details>

                <section class="rfm-quick-image-queue" data-rfm-quick-image-queue hidden aria-label="Photos ready to save">
                    <div class="rfm-quick-image-queue-head">
                        <strong>Ready to save</strong>
                        <em data-rfm-quick-image-queue-count>0</em>
                    </div>
                    <div class="rfm-quick-image-queue-grid" data-rfm-quick-image-queue-grid></div>
                    <p class="rfm-quick-image-queue-hint">Take or choose more, then save them all at once.</p>
                </section>

                <footer class="rfm-quick-image-foot">
                    <p class="rfm-quick-image-status" data-rfm-quick-image-status hidden></p>
                    <button type="submit" class="rfm-save-btn rfm-media-add-btn" data-rfm-quick-image-submit>Add image</button>
                </footer>
            </form>
                </div>
            </div>
        </div>

        <div class="rfm-quick-image-viewer" data-rfm-quick-image-viewer hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Image gallery">
            <button type="button" class="rfm-quick-image-viewer-backdrop" data-rfm-quick-image-viewer-close aria-label="Close gallery"></button>
            <div class="rfm-quick-image-viewer-stage">
                <button type="button" class="rfm-quick-image-viewer-close" data-rfm-quick-image-viewer-close aria-label="Close">×</button>
                <button type="button" class="rfm-quick-image-viewer-nav is-prev" data-rfm-quick-image-viewer-prev hidden aria-label="Previous image">‹</button>
                <figure class="rfm-quick-image-viewer-figure">
                    <img src="" alt="" data-rfm-quick-image-viewer-img decoding="async">
                    <figcaption data-rfm-quick-image-viewer-caption></figcaption>
                </figure>
                <button type="button" class="rfm-quick-image-viewer-nav is-next" data-rfm-quick-image-viewer-next hidden aria-label="Next image">›</button>
                <span class="rfm-quick-image-viewer-counter" data-rfm-quick-image-viewer-counter hidden></span>
            </div>
        </div>
    </section>
</div>
