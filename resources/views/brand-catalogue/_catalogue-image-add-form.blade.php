{{--
    Rich add form (camera / upload / URL / paste) for catalogue images — matches retail `_media-manager` add UX.
    Expects: $targetType, $targetId, $imageRoleOptions (list of ['value'=>, 'label'=>]).
    Optional: $defaultImageRole, $primaryLabel, $addButtonLabel.
--}}
@php
    $defaultImageRole = $defaultImageRole ?? ($imageRoleOptions[0]['value'] ?? 'hero');
    $primaryLabel = $primaryLabel ?? 'Set as primary style image';
    $addButtonLabel = $addButtonLabel ?? 'Add image';
    $defaultImageSource = $defaultImageSource ?? 'camera';
    $validImageSources = ['camera', 'phone', 'upload', 'url', 'paste'];
    if (! in_array($defaultImageSource, $validImageSources, true)) {
        $defaultImageSource = 'camera';
    }
@endphp

<div class="rfm-media sw-catalogue-image-add" data-rfm-media-manager>
    <form method="POST"
          action="{{ route('images.store') }}"
          enctype="multipart/form-data"
          class="rfm-media-add"
          data-mobile-capture-job-url="{{ route('mobile-capture.jobs.store') }}"
          data-rfm-media-add-form>
        @csrf
        <input type="hidden" name="mobile_capture_destination" value="catalogue">
        <input type="hidden" name="target_type" value="{{ $targetType }}">
        <input type="hidden" name="target_id" value="{{ $targetId }}">

        <div class="rfm-media-purpose">
            <label>
                <span>Image purpose</span>
                <select name="image_role" required>
                    @foreach ($imageRoleOptions as $role)
                        <option value="{{ $role['value'] }}" @selected($role['value'] === $defaultImageRole)>{{ $role['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <small>Select this first so the image is saved as the correct product photo type.</small>
        </div>

        <div class="rfm-media-tabs" role="tablist" aria-label="Add image source">
            <button type="button" class="rfm-media-tab {{ $defaultImageSource === 'camera' ? 'is-active' : '' }}" data-rfm-media-tab="camera" role="tab" aria-selected="{{ $defaultImageSource === 'camera' ? 'true' : 'false' }}">Camera</button>
            <button type="button" class="rfm-media-tab {{ $defaultImageSource === 'phone' ? 'is-active' : '' }}" data-rfm-media-tab="phone" role="tab" aria-selected="{{ $defaultImageSource === 'phone' ? 'true' : 'false' }}">Phone</button>
            <button type="button" class="rfm-media-tab {{ $defaultImageSource === 'upload' ? 'is-active' : '' }}" data-rfm-media-tab="upload" role="tab" aria-selected="{{ $defaultImageSource === 'upload' ? 'true' : 'false' }}">Upload</button>
            <button type="button" class="rfm-media-tab {{ $defaultImageSource === 'url' ? 'is-active' : '' }}" data-rfm-media-tab="url" role="tab" aria-selected="{{ $defaultImageSource === 'url' ? 'true' : 'false' }}">URL</button>
            <button type="button" class="rfm-media-tab {{ $defaultImageSource === 'paste' ? 'is-active' : '' }}" data-rfm-media-tab="paste" role="tab" aria-selected="{{ $defaultImageSource === 'paste' ? 'true' : 'false' }}">Paste</button>
        </div>

        <div class="rfm-media-tab-panels">
            <div class="rfm-media-tab-panel {{ $defaultImageSource === 'camera' ? 'is-active' : '' }}" data-rfm-media-panel="camera">
                <label class="rfm-media-capture">
                    <input type="file" name="uploaded_image" accept="image/*" capture="environment" data-rfm-camera>
                    <span class="rfm-media-capture-title">Use camera</span>
                    <small class="rfm-media-capture-hint">Back camera on phones; file picker on desktop.</small>
                </label>
            </div>

            <div class="rfm-media-tab-panel {{ $defaultImageSource === 'phone' ? 'is-active' : '' }}" data-rfm-media-panel="phone">
                <div class="rfm-phone-capture">
                    <strong>Send this image request to the phone</strong>
                    <span>Keep Mobile Capture open on the phone once. This request will appear there with this exact target, Image purpose and primary setting.</span>
                    <button type="button" class="rfm-phone-create" data-rfm-phone-create>Send to phone camera</button>
                    <label class="rfm-phone-url" hidden>
                        <span>Fallback phone link</span>
                        <input type="text" readonly data-rfm-phone-url>
                    </label>
                    <p class="rfm-phone-status" data-rfm-phone-status hidden></p>
                </div>
            </div>

            <div class="rfm-media-tab-panel {{ $defaultImageSource === 'upload' ? 'is-active' : '' }}" data-rfm-media-panel="upload">
                <label class="rfm-media-capture">
                    <input type="file" name="uploaded_image_alt" accept="image/*" data-rfm-upload>
                    <span class="rfm-media-capture-title">Choose file</span>
                    <small class="rfm-media-capture-hint">JPEG, PNG, WebP, or GIF — up to 10&nbsp;MB.</small>
                </label>
            </div>

            <div class="rfm-media-tab-panel {{ $defaultImageSource === 'url' ? 'is-active' : '' }}" data-rfm-media-panel="url">
                <label class="rfm-media-tab-url-field">
                    <span>Image URL</span>
                    <input type="url" name="external_url" placeholder="https://…" inputmode="url" autocomplete="off">
                </label>
                <label class="rfm-media-inline-check">
                    <input type="checkbox" name="mirror_external" value="1" checked>
                    <span>Save a local copy for offline use</span>
                </label>
            </div>

            <div class="rfm-media-tab-panel {{ $defaultImageSource === 'paste' ? 'is-active' : '' }}" data-rfm-media-panel="paste">
                <div class="rfm-media-paste" tabindex="0" data-rfm-media-paste>
                    <strong>Click here, then press Ctrl+V</strong>
                    <span>Paste a copied image — it uploads to local storage.</span>
                </div>
                <p class="rfm-media-paste-status" data-rfm-media-paste-status hidden></p>
            </div>
        </div>

        <details class="rfm-media-meta-fields">
            <summary>Image details (optional)</summary>
            <div class="rfm-grid">
                <label class="rfm-grid-wide">
                    <span>Notes</span>
                    <input type="text" name="notes" placeholder="Hero, pack shot, texture…">
                </label>
            </div>
            <label class="rfm-media-inline-check">
                <input type="checkbox" name="is_primary" value="1">
                <span>{{ $primaryLabel }}</span>
            </label>
        </details>

        <button type="submit" class="rfm-save-btn rfm-media-add-btn">{{ $addButtonLabel }}</button>
    </form>
</div>
