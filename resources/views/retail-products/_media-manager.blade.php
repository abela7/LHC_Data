@php
    $mediaCollection = $mediaCollection ?? collect();
    $managerLevel = $managerLevel ?? 'product';
    $targetName = $targetName ?? 'Product';
    $defaultMediaRole = $defaultMediaRole ?? 'main';
    $mobileCaptureTargetType = $mobileCaptureTargetType ?? null;
    $mobileCaptureTargetId = $mobileCaptureTargetId ?? null;
@endphp

<div class="rfm-media" data-rfm-media-manager>
    {{-- Add image: tabs for camera/upload/url/paste --}}
    <form method="POST"
          action="{{ $storeRoute }}"
          enctype="multipart/form-data"
          class="rfm-media-add"
          data-mobile-capture-job-url="{{ route('mobile-capture.jobs.store') }}"
          data-rfm-media-add-form>
        @csrf
        <input type="hidden" name="mobile_capture_destination" value="retail">
        <input type="hidden" name="mobile_capture_target_type" value="{{ $mobileCaptureTargetType }}">
        <input type="hidden" name="mobile_capture_target_id" value="{{ $mobileCaptureTargetId }}">

        <div class="rfm-media-purpose">
            <label>
                <span>Image purpose</span>
                <select name="image_role" required>
                    @foreach ($mediaRoles as $role)
                        <option value="{{ $role['value'] }}" @selected($role['value'] === $defaultMediaRole)>{{ $role['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Use on</span>
                <select name="usage_context" required>
                    @foreach ($mediaUsageContexts as $ctx)
                        <option value="{{ $ctx['value'] }}">{{ $ctx['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <small>Select this first so the image is saved as the correct product photo type.</small>
        </div>

        <div class="rfm-media-tabs" role="tablist" aria-label="Add image source">
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
                    <span class="rfm-media-capture-title">Use camera</span>
                    <small class="rfm-media-capture-hint">Back camera on phones; file picker on desktop.</small>
                </label>
            </div>

            <div class="rfm-media-tab-panel" data-rfm-media-panel="phone">
                <div class="rfm-phone-capture">
                    <strong>Send this image request to the phone</strong>
                    <span>Keep Mobile Capture open on the phone once. This request will appear there with this exact target, Image purpose, Use on and primary setting.</span>
                    <button type="button" class="rfm-phone-create" data-rfm-phone-create>Send to phone camera</button>
                    <label class="rfm-phone-url" hidden>
                        <span>Fallback phone link</span>
                        <input type="text" readonly data-rfm-phone-url>
                    </label>
                    <p class="rfm-phone-status" data-rfm-phone-status hidden></p>
                </div>
            </div>

            <div class="rfm-media-tab-panel" data-rfm-media-panel="upload">
                <label class="rfm-media-capture">
                    <input type="file" name="uploaded_image_alt" accept="image/*" data-rfm-upload>
                    <span class="rfm-media-capture-title">Choose file</span>
                    <small class="rfm-media-capture-hint">JPEG, PNG, WebP, or GIF — up to 35&nbsp;MB.</small>
                </label>
            </div>

            <div class="rfm-media-tab-panel" data-rfm-media-panel="url">
                <label>
                    <span>Image URL</span>
                    <input type="url" name="external_url" placeholder="https://…" inputmode="url" autocomplete="off">
                </label>
                <label class="rfm-media-inline-check">
                    <input type="checkbox" name="mirror_external" value="1" checked>
                    <span>Save a local copy for offline use</span>
                </label>
            </div>

            <div class="rfm-media-tab-panel" data-rfm-media-panel="paste">
                <div class="rfm-media-paste" tabindex="0" data-rfm-media-paste>
                    <strong>Click here, then press Ctrl+V</strong>
                    <span>Paste a copied image — it uploads to local storage.</span>
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

        <details class="rfm-media-meta-fields">
            <summary>Image details (optional)</summary>
            <div class="rfm-grid">
                <label>
                    <span>Source label</span>
                    <input type="text" name="source_label" placeholder="Shop photo, supplier, official site…">
                </label>
                <label>
                    <span>Image name</span>
                    <input type="text" value="Generated after save" readonly aria-readonly="true">
                </label>
                <label class="rfm-grid-wide">
                    <span>Notes</span>
                    <input type="text" name="notes" placeholder="Front pack, colour close-up…">
                </label>
            </div>
            <label class="rfm-media-inline-check">
                <input type="checkbox" name="is_primary" value="1">
                <span>Use as primary image</span>
            </label>
        </details>

        <button type="submit" class="rfm-save-btn rfm-media-add-btn">Add image</button>
    </form>

    {{-- Existing images --}}
    @if ($mediaCollection->isNotEmpty())
        <div class="rfm-media-grid">
            @foreach ($mediaCollection as $image)
                @php($imageUrl = $image->displayUrl())
                <article class="rfm-media-card {{ $image->is_primary ? 'is-primary' : '' }}">
                    <div class="rfm-media-thumb">
                        @if ($imageUrl)
                            <button type="button"
                                    class="rfm-media-thumb-trigger"
                                    data-picture-preview-trigger
                                    data-image-url="{{ $imageUrl }}"
                                    data-picture-id="{{ $image->alt_text ?: $targetName }}"
                                    data-media-id="{{ $image->id }}"
                                    data-image-delete-url="{{ route('retail-products.media.destroy', $image) }}"
                                    data-image-replace-url="{{ route('retail-products.media.replace', $image) }}"
                                    data-image-role="{{ $image->image_role }}"
                                    data-image-usage="{{ $image->usage_context }}"
                                    data-image-source-label="{{ $image->source_label }}"
                                    data-image-notes="{{ $image->notes }}"
                                    aria-label="Open image actions for {{ $image->alt_text ?: $targetName }}">
                                <img src="{{ $imageUrl }}" alt="{{ $image->alt_text ?: $targetName }}" loading="lazy">
                            </button>
                        @else
                            <span>No preview</span>
                        @endif
                    </div>

                    <div class="rfm-media-badges">
                        @if ($image->is_primary)
                            <span class="rfm-badge rfm-badge-primary">★ Primary</span>
                        @endif
                        <span class="rfm-badge">{{ ucfirst($image->image_role) }}</span>
                        <span class="rfm-badge {{ $image->is_offline_ready ? 'rfm-badge-ok' : 'rfm-badge-warn' }}">
                            {{ $image->is_offline_ready ? '⬇ Offline-ready' : '🌐 External URL' }}
                        </span>
                    </div>

                    <p class="rfm-media-source">{{ $image->source_label ?: $image->sourceDisplay() }}</p>

                    <details class="rfm-media-edit">
                        <summary>Edit details</summary>
                        <form method="POST" action="{{ route('retail-products.media.update', $image) }}" class="rfm-media-edit-form">
                            @csrf
                            @method('PATCH')
                            <div class="rfm-grid">
                                <label>
                                    <span>Role</span>
                                    <select name="image_role">
                                        @foreach ($mediaRoles as $role)
                                            <option value="{{ $role['value'] }}" @selected($image->image_role === $role['value'])>{{ $role['label'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>
                                    <span>Usage</span>
                                    <select name="usage_context">
                                        @foreach ($mediaUsageContexts as $ctx)
                                            <option value="{{ $ctx['value'] }}" @selected($image->usage_context === $ctx['value'])>{{ $ctx['label'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>
                                    <span>Source label</span>
                                    <input type="text" name="source_label" value="{{ $image->source_label }}">
                                </label>
                                <label>
                                    <span>Image name</span>
                                    <input type="text" value="{{ $image->alt_text ?: $targetName }}" readonly aria-readonly="true">
                                </label>
                                <label class="rfm-grid-wide">
                                    <span>Source URL</span>
                                    <input type="url" name="external_url" value="{{ $image->external_url }}" placeholder="https://…">
                                </label>
                                <label class="rfm-grid-wide">
                                    <span>Notes</span>
                                    <input type="text" name="notes" value="{{ $image->notes }}">
                                </label>
                                <label>
                                    <span>Sort order</span>
                                    <input type="number" name="sort_order" value="{{ $image->sort_order }}" min="0" step="1">
                                </label>
                                <label class="rfm-media-inline-check rfm-grid-wide">
                                    <input type="checkbox" name="is_primary" value="1" @checked($image->is_primary)>
                                    <span>Primary image</span>
                                </label>
                            </div>
                            <button type="submit" class="rfm-save-btn">Save</button>
                        </form>
                    </details>

                    <div class="rfm-media-actions">
                        @if (! $image->is_primary)
                            <form method="POST" action="{{ route('retail-products.media.primary', $image) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="rfm-secondary-btn">Make main</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('retail-products.media.destroy', $image) }}" onsubmit="return confirm('Remove this image?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rfm-danger-btn">Delete</button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="rfm-media-empty">
            <strong>No images yet.</strong>
            <span>Add a main image before activating POS or ecommerce.</span>
        </div>
    @endif
</div>
