@extends('layouts.app')

@section('title', 'Mobile Capture')
@section('section', 'Settings')
@section('heading', 'Mobile Capture')

@section('content')
    <section class="mc-page"
        data-mobile-capture-dashboard
        data-status-url="{{ $statusUrl }}"
        data-delete-all-url="{{ route('settings.mobile-capture.uploads.destroy-all') }}">
        <header class="mc-hero">
            <div>
                <p class="mc-eyebrow">Phone camera bridge</p>
                <h1>Connect your phone to this catalogue app</h1>
                <p>Use the same Wi-Fi network so the phone can open this PC app, take product photos, and upload directly into the correct product record.</p>
            </div>
            <span class="mc-status-pill {{ $settings->is_enabled ? 'is-on' : 'is-off' }}" data-mc-enabled-pill>
                {{ $settings->is_enabled ? 'Enabled' : 'Disabled' }}
            </span>
        </header>

        <div class="mc-grid">
            <section class="mc-card">
                <div class="mc-card-head">
                    <h2>1. Turn on phone access</h2>
                    <p>Keep this enabled only while you are working in the shop.</p>
                </div>

                <form method="POST" action="{{ route('settings.mobile-capture.update') }}" class="mc-toggle-form">
                    @csrf
                    @method('PATCH')
                    <label class="mc-toggle">
                        <input type="checkbox" name="is_enabled" value="1" @checked($settings->is_enabled)>
                        <span>
                            <strong>Allow mobile capture from same Wi-Fi</strong>
                            <small>Phone connection page works only while this is enabled.</small>
                        </span>
                    </label>
                    <button type="submit" class="mc-primary">{{ $settings->is_enabled ? 'Save enabled' : 'Enable mobile capture' }}</button>
                </form>

                <div class="mc-actions-row">
                    <form method="POST" action="{{ route('settings.mobile-capture.update') }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="mc-secondary">Clear test status</button>
                    </form>
                    <form method="POST" action="{{ route('settings.mobile-capture.update') }}" onsubmit="return confirm('Regenerate the phone link? The old phone link will stop working.')">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="action" value="regenerate">
                        <button type="submit" class="mc-danger">Regenerate link</button>
                    </form>
                </div>
            </section>

            <section class="mc-card">
                <div class="mc-card-head">
                <h2>2. Open the phone capture station once</h2>
                <p>Your PC Wi-Fi IP is detected below. Keep this page open on the phone while you work from the PC.</p>
                </div>

                @if ($networkIps)
                    <div class="mc-ip-list">
                        @foreach ($networkIps as $ip)
                            <span>{{ $ip }}</span>
                        @endforeach
                    </div>
                @else
                    <div class="mc-warning">No Wi-Fi IPv4 address was detected. Connect the PC to Wi-Fi, then refresh this page.</div>
                @endif

                <label class="mc-url-box">
                    <span>Phone URL</span>
                    <input type="text" value="{{ $preferredPhoneUrl }}" readonly data-mc-phone-url>
                </label>

                <button type="button" class="mc-primary" data-mc-copy-url>Copy link</button>

                <label class="mc-url-box">
                    <span>Product intake URL</span>
                    <input type="text" value="{{ $preferredProductIntakeUrl }}" readonly data-mc-product-intake-url>
                </label>

                <button type="button" class="mc-secondary" data-mc-copy-product-intake-url>Copy product intake link</button>

                <ol class="mc-steps">
                    <li>Connect your phone to the same Wi-Fi as this PC.</li>
                    <li>Open the URL above on the phone browser.</li>
                    <li>Keep the phone capture station open while working.</li>
                    <li>On the PC, click Phone on any product image slot. The request appears on the phone automatically.</li>
                </ol>
            </section>

            <section class="mc-card mc-card-wide">
                <div class="mc-card-head">
                    <h2>3. Live connection check</h2>
                    <p>This updates automatically when the phone page is open.</p>
                </div>

                <div class="mc-live-grid">
                    <div class="mc-live-tile">
                        <span>Phone connection</span>
                        <strong data-mc-connected>Waiting</strong>
                        <small data-mc-last-seen>No phone seen yet.</small>
                    </div>
                    <div class="mc-live-tile">
                        <span>Phone IP</span>
                        <strong data-mc-ip>--</strong>
                        <small>Should be on the same local network.</small>
                    </div>
                    <div class="mc-live-tile">
                        <span>Camera test</span>
                        <strong data-mc-camera>{{ ucfirst(str_replace('-', ' ', $settings->camera_status)) }}</strong>
                        <small data-mc-camera-error>{{ $settings->camera_error ?: 'Not tested yet.' }}</small>
                    </div>
                </div>

                <div class="mc-note">
                    <strong>Camera reality check:</strong>
                    <span>Live camera preview needs HTTPS on most phones. The Take photo and Send to desktop flow works on the local Wi-Fi HTTP page and is the real shop workflow.</span>
                </div>

                <div class="mc-upload-panel">
                    <div class="mc-card-head">
                        <h2>Received phone photos</h2>
                        <p>Photos sent from the phone are saved on this PC and appear here immediately.</p>
                    </div>
                    <div class="mc-upload-actions">
                        <button type="button" class="mc-danger" data-mc-delete-all-uploads>Delete all received photos</button>
                        <span data-mc-delete-status></span>
                    </div>
                    <div class="mc-upload-strip" data-mc-upload-strip>
                        <div class="mc-empty-upload">No mobile photos received yet.</div>
                    </div>
                </div>
            </section>
        </div>
    </section>

    <script>
        (() => {
            const root = document.querySelector('[data-mobile-capture-dashboard]');
            if (!root) return;

            const statusUrl = root.dataset.statusUrl;
            const deleteAllUrl = root.dataset.deleteAllUrl;
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const connectedEl = root.querySelector('[data-mc-connected]');
            const lastSeenEl = root.querySelector('[data-mc-last-seen]');
            const ipEl = root.querySelector('[data-mc-ip]');
            const cameraEl = root.querySelector('[data-mc-camera]');
            const cameraErrorEl = root.querySelector('[data-mc-camera-error]');
            const enabledPill = root.querySelector('[data-mc-enabled-pill]');
            const phoneUrl = root.querySelector('[data-mc-phone-url]');
            const productIntakeUrl = root.querySelector('[data-mc-product-intake-url]');
            const copyButton = root.querySelector('[data-mc-copy-url]');
            const copyProductIntakeButton = root.querySelector('[data-mc-copy-product-intake-url]');
            const uploadStrip = root.querySelector('[data-mc-upload-strip]');
            const deleteAllButton = root.querySelector('[data-mc-delete-all-uploads]');
            const deleteStatus = root.querySelector('[data-mc-delete-status]');

            const copyInput = async (input, button, defaultText) => {
                try {
                    await navigator.clipboard.writeText(input.value);
                    button.textContent = 'Copied';
                    setTimeout(() => button.textContent = defaultText, 1400);
                } catch (_) {
                    input.select();
                }
            };

            copyButton?.addEventListener('click', () => copyInput(phoneUrl, copyButton, 'Copy link'));
            copyProductIntakeButton?.addEventListener('click', () => copyInput(productIntakeUrl, copyProductIntakeButton, 'Copy product intake link'));

            const prettyStatus = (value) => (value || 'untested').replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
            const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, character => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            })[character]);

            const requestDelete = async (url) => {
                const response = await fetch(url, {
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

                return data;
            };

            uploadStrip?.addEventListener('click', async (event) => {
                const button = event.target.closest('[data-mc-delete-upload]');
                if (!button) return;

                event.preventDefault();
                event.stopPropagation();

                if (!confirm('Permanently delete this received phone photo from the system? This removes the file from disk and cannot be undone.')) {
                    return;
                }

                button.disabled = true;
                button.textContent = 'Deleting...';

                try {
                    await requestDelete(button.dataset.mcDeleteUpload);
                    if (deleteStatus) deleteStatus.textContent = 'Photo deleted.';
                    await refresh();
                } catch (error) {
                    button.disabled = false;
                    button.textContent = 'Delete';
                    if (deleteStatus) deleteStatus.textContent = error?.message || 'Delete failed.';
                }
            });

            deleteAllButton?.addEventListener('click', async () => {
                if (!confirm('Permanently delete ALL received phone photos from the system? This removes the local files and database records and cannot be undone.')) {
                    return;
                }

                deleteAllButton.disabled = true;
                deleteAllButton.textContent = 'Deleting...';

                try {
                    const data = await requestDelete(deleteAllUrl);
                    if (deleteStatus) deleteStatus.textContent = data.message || 'Photos deleted.';
                    await refresh();
                } catch (error) {
                    if (deleteStatus) deleteStatus.textContent = error?.message || 'Delete failed.';
                } finally {
                    deleteAllButton.disabled = false;
                    deleteAllButton.textContent = 'Delete all received photos';
                }
            });

            const refresh = async () => {
                const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                const data = await response.json();

                enabledPill.textContent = data.enabled ? 'Enabled' : 'Disabled';
                enabledPill.classList.toggle('is-on', data.enabled);
                enabledPill.classList.toggle('is-off', !data.enabled);

                connectedEl.textContent = data.connected ? 'Connected' : (data.enabled ? 'Waiting' : 'Disabled');
                connectedEl.classList.toggle('is-good', data.connected);
                connectedEl.classList.toggle('is-warn', data.enabled && !data.connected);
                lastSeenEl.textContent = data.last_seen_at
                    ? `Last seen ${data.seconds_ago}s ago`
                    : 'No phone seen yet.';
                ipEl.textContent = data.last_ip || '--';
                cameraEl.textContent = prettyStatus(data.camera_status);
                cameraEl.classList.toggle('is-good', ['granted', 'upload-tested', 'upload-ready', 'uploaded'].includes(data.camera_status));
                cameraEl.classList.toggle('is-warn', ['denied', 'unsupported', 'error'].includes(data.camera_status));
                cameraErrorEl.textContent = data.camera_error || (data.camera_tested_at ? `Tested at ${data.camera_tested_at}` : 'Not tested yet.');

                if (uploadStrip) {
                    const uploads = Array.isArray(data.recent_uploads) ? data.recent_uploads : [];
                    uploadStrip.innerHTML = uploads.length
                        ? uploads.map(upload => `
                            <article class="mc-upload-card">
                                <a href="${escapeHtml(upload.url)}" target="_blank" rel="noopener">
                                    <img src="${escapeHtml(upload.url)}" alt="${escapeHtml(upload.filename || 'Mobile uploaded photo')}">
                                    <span>${escapeHtml(upload.filename || 'Mobile photo')}</span>
                                    <small>${escapeHtml(upload.created_at || '')}</small>
                                </a>
                                <div class="mc-upload-meta">
                                    <strong class="is-${escapeHtml(upload.processing_status || 'disabled')}">${escapeHtml(prettyStatus(upload.processing_status || 'disabled'))}</strong>
                                    ${upload.processing_error ? `<small>${escapeHtml(upload.processing_error)}</small>` : ''}
                                    ${upload.original_url ? `<a href="${escapeHtml(upload.original_url)}" target="_blank" rel="noopener">View original</a>` : ''}
                                </div>
                                <button type="button" data-mc-delete-upload="${escapeHtml(upload.delete_url)}">Delete</button>
                            </article>
                        `).join('')
                        : '<div class="mc-empty-upload">No mobile photos received yet.</div>';
                }
            };

            refresh().catch(() => {});
            setInterval(() => refresh().catch(() => {}), 3000);
        })();
    </script>
@endsection
