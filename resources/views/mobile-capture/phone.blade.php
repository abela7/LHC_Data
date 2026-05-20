<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Phone Capture Station</title>
    <style>
        :root {
            --ink:#11231f;
            --muted:#6d766f;
            --paper:#f5f1e8;
            --panel:#fffdf8;
            --soft:#eef6ef;
            --edge:#ded6c8;
            --accent:#006b5a;
            --accent-dark:#07493f;
            --danger:#a23c32;
            --shadow:0 24px 70px rgba(31,36,33,.13);
        }
        * { box-sizing: border-box; }
        html { background:var(--paper); }
        body {
            margin:0;
            min-height:100dvh;
            font-family:"Segoe UI", "Aptos", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(0,107,90,.14), transparent 34rem),
                linear-gradient(180deg,#f9f5ec 0%, #eef6ef 100%);
            color:var(--ink);
        }
        main {
            width:min(100%, 560px);
            min-height:100dvh;
            margin:0 auto;
            padding:16px;
            display:flex;
            flex-direction:column;
            gap:14px;
        }
        .station-hero {
            border:1px solid rgba(0,107,90,.16);
            border-radius:30px;
            padding:22px;
            background:linear-gradient(145deg, rgba(255,253,248,.96), rgba(238,246,239,.98));
            box-shadow:var(--shadow);
        }
        .station-top { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; }
        .station-kicker { margin:0 0 8px; color:var(--accent); font-size:11px; font-weight:900; letter-spacing:.16em; text-transform:uppercase; }
        h1 { margin:0; font-size:34px; line-height:.96; letter-spacing:-.045em; }
        h2 { margin:0; font-size:22px; line-height:1.1; letter-spacing:-.025em; }
        p { margin:0; color:var(--muted); line-height:1.5; }
        .connection-pill {
            flex:0 0 auto;
            display:inline-flex;
            align-items:center;
            gap:8px;
            min-height:34px;
            padding:8px 12px;
            border-radius:999px;
            background:#e8f4ef;
            color:var(--accent-dark);
            font-size:12px;
            font-weight:900;
            box-shadow:inset 0 0 0 1px rgba(0,107,90,.14);
        }
        .connection-pill::before {
            content:"";
            width:8px;
            height:8px;
            border-radius:50%;
            background:#13a475;
            box-shadow:0 0 0 5px rgba(19,164,117,.14);
        }
        .card {
            background:rgba(255,253,248,.95);
            border:1px solid var(--edge);
            border-radius:28px;
            padding:18px;
            box-shadow:0 16px 44px rgba(31,36,33,.08);
        }
        .good { color:var(--accent); }
        .bad { color:var(--danger); }
        button, .file-button, a.button {
            display:flex;
            width:100%;
            min-height:56px;
            align-items:center;
            justify-content:center;
            border:0;
            border-radius:18px;
            padding:14px 18px;
            font-size:16px;
            font-weight:900;
            letter-spacing:-.01em;
            text-decoration:none;
        }
        button.primary, .file-button {
            background:linear-gradient(135deg, var(--accent), var(--accent-dark));
            color:#fffaf3;
            box-shadow:0 16px 32px rgba(0,107,90,.24);
        }
        .file-button.secondary-file {
            background:#fffdf8;
            border:1px solid var(--edge);
            color:var(--ink);
            box-shadow:none;
        }
        button.secondary, a.button { background:#fffdf8; border:1px solid var(--edge); color:var(--ink); }
        button.disabled, button:disabled { opacity:.5; cursor:not-allowed; }
        input[type=file] { position:absolute; opacity:0; pointer-events:none; }
        video, img.preview {
            display:block;
            width:100%;
            max-height:46dvh;
            object-fit:contain;
            border-radius:22px;
            background:#111;
            box-shadow:inset 0 0 0 1px rgba(255,255,255,.1);
        }
        .preview-slot[hidden] { display:none; }
        .preview-slot.is-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
        .preview-item { position:relative; min-width:0; }
        .preview-item img.preview { aspect-ratio:1 / 1; max-height:none; }
        .preview-tools {
            position:absolute;
            right:8px;
            top:8px;
            display:flex;
            gap:6px;
        }
        .preview-view,
        .preview-remove {
            width:auto;
            min-height:34px;
            padding:7px 10px;
            border-radius:999px;
            background:rgba(17,35,31,.84);
            color:#fffaf3;
            font-size:12px;
            box-shadow:none;
        }
        .preview-remove { background:rgba(162,60,50,.92); }
        .preview-fullscreen {
            position:fixed;
            inset:0;
            z-index:60;
            display:flex;
            flex-direction:column;
            gap:12px;
            padding:14px;
            background:rgba(17,35,31,.92);
        }
        .preview-fullscreen[hidden] { display:none; }
        .preview-fullscreen img {
            width:100%;
            min-height:0;
            flex:1;
            object-fit:contain;
            border-radius:22px;
            background:#fff;
        }
        .preview-fullscreen-actions {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:10px;
        }
        .preview-fullscreen-actions button { min-height:52px; }
        .preview-fullscreen-actions .danger {
            background:#a23c32;
            color:#fffaf3;
        }
        .stack { display:flex; flex-direction:column; gap:14px; }
        .warning { border:1px solid #efc7a7; background:#fff4e8; border-radius:18px; padding:14px; color:#7a4d24; font-size:14px; }
        .disabled { border-color:#f0b6b6; background:#fff2f2; color:#842f2f; }
        .send-status { border:1px solid #d8e6dc; background:#f3faf5; border-radius:18px; padding:14px; color:var(--accent-dark); font-size:14px; font-weight:800; }
        .send-status.is-good { border-color:#bddccd; background:#edf8f3; color:var(--accent); }
        .send-status.is-error { border-color:#f0b6b6; background:#fff2f2; color:var(--danger); }
        .request-head { display:flex; flex-direction:column; gap:7px; }
        .request-head p { font-size:14px; }
        .job-list { display:flex; flex-direction:column; gap:10px; }
        .job-card { width:100%; border:1px solid #d5e3d8; border-radius:22px; background:#f6fbf7; color:var(--ink); padding:16px; text-align:left; }
        .job-card strong { display:block; font-size:17px; letter-spacing:-.015em; }
        .job-card span { display:block; margin-top:5px; color:var(--muted); font-size:13px; }
        .job-active {
            display:flex;
            flex-direction:column;
            gap:14px;
            border:1px solid rgba(0,107,90,.14);
            border-radius:26px;
            background:linear-gradient(180deg,#f6fbf7,#fffdf8);
            padding:16px;
        }
        .job-active-copy span { display:block; color:var(--accent); font-size:11px; font-weight:900; letter-spacing:.14em; text-transform:uppercase; }
        .job-active-copy strong { display:block; margin-top:5px; font-size:24px; line-height:1.1; letter-spacing:-.035em; }
        .job-active-copy small { display:block; margin-top:7px; color:var(--muted); font-size:14px; }
        .job-actions { display:grid; grid-template-columns:1fr; gap:10px; }
        .station-idle {
            display:grid;
            place-items:center;
            min-height:190px;
            border:1px dashed #cbd7ce;
            border-radius:26px;
            background:rgba(255,253,248,.62);
            text-align:center;
            padding:24px;
        }
        .station-idle strong { display:block; font-size:23px; letter-spacing:-.03em; }
        .station-tools { border:1px solid var(--edge); border-radius:22px; background:rgba(255,253,248,.72); padding:4px 14px; }
        .station-tools summary { min-height:50px; display:flex; align-items:center; justify-content:space-between; font-weight:900; color:var(--accent-dark); cursor:pointer; }
        .station-tools-body { display:flex; flex-direction:column; gap:12px; padding:4px 0 14px; }
        .status { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .tile { border:1px solid #e5dbc9; border-radius:16px; padding:12px; background:#fffaf3; }
        .tile span { display:block; font-size:10px; font-weight:900; text-transform:uppercase; letter-spacing:.12em; color:#786d62; }
        .tile strong { display:block; margin-top:4px; font-size:18px; }
        .footer-link { margin-top:auto; font-size:13px; color:var(--muted); text-align:center; text-decoration:none; padding:8px; }
        small { color:#6d716a; }
        @media (max-width: 420px) {
            main { padding:12px; gap:12px; }
            .station-hero { border-radius:26px; padding:18px; }
            h1 { font-size:30px; }
            .connection-pill { padding:7px 10px; font-size:11px; }
            .card { border-radius:24px; padding:14px; }
            .job-active-copy strong { font-size:21px; }
        }
    </style>
</head>
<body>
<main data-mobile-phone
    @if($heartbeatUrl) data-heartbeat-url="{{ $heartbeatUrl }}" @endif
    @if($uploadUrl) data-upload-url="{{ $uploadUrl }}" @endif
    @if($jobsUrl) data-jobs-url="{{ $jobsUrl }}" @endif
    data-max-upload-bytes="{{ $maxUploadKb * 1024 }}">
    <section class="station-hero">
        <div class="station-top">
            <div>
                <p class="station-kicker">LHC mobile capture</p>
                <h1>Phone capture station</h1>
            </div>
            @if ($validToken && $settings->is_enabled)
                <span class="connection-pill" data-phone-connection>Connected</span>
            @endif
        </div>
        @if (! $validToken)
            <p class="warning disabled">This mobile link is not valid. Regenerate or copy the latest link from the PC Mobile Capture settings page.</p>
        @elseif (! $settings->is_enabled)
            <p class="warning disabled">Mobile capture is currently disabled on the PC. Enable it on the PC settings page, then refresh this page.</p>
        @endif
    </section>

    @if ($validToken && $settings->is_enabled)
        <section class="card stack" data-phone-job-section>
            <div class="request-head">
                <h2>Current product photo</h2>
            </div>
            <div class="job-list" data-phone-job-list>
                <div class="station-idle">
                    <div>
                        <strong>Waiting for PC request</strong>
                    </div>
                </div>
            </div>
            <div class="job-active" data-phone-active-job hidden>
                <div class="job-active-copy">
                    <span>Ready to photograph</span>
                    <strong data-phone-active-job-title></strong>
                    <small data-phone-active-job-meta></small>
                </div>
                <label class="file-button" data-phone-job-take-wrap>
                    <span>Take photo</span>
                    <input type="file" accept="image/*" capture="environment" data-phone-job-photo>
                </label>
                <div class="preview-slot" data-phone-job-preview-slot hidden></div>
                <label class="file-button secondary-file" data-phone-job-add-wrap hidden>
                    <span>Add photo</span>
                    <input type="file" accept="image/*" capture="environment" data-phone-job-photo-add>
                </label>
                <div class="job-actions">
                    <button type="button" class="primary disabled" data-phone-job-send disabled>Send product</button>
                    <button type="button" class="secondary" data-phone-job-cancel>Close request</button>
                </div>
                <div class="send-status" data-phone-job-status hidden></div>
            </div>
        </section>

        <details class="station-tools">
            <summary>Connection tools</summary>
            <div class="station-tools-body">
                <section class="status">
                    <div class="tile">
                        <span>Connection</span>
                        <strong class="good" data-phone-connection>Connected</strong>
                    </div>
                    <div class="tile">
                        <span>Camera</span>
                        <strong data-phone-camera>Untested</strong>
                    </div>
                </section>

                <video data-phone-video playsinline autoplay muted hidden></video>
                <button type="button" class="secondary" data-phone-live-test>Test live preview</button>
                <button type="button" class="secondary" data-phone-stop-video hidden>Stop preview</button>
                <div class="warning" data-phone-live-warning hidden></div>

                <label class="file-button">
                    Send loose photo to desktop
                    <input type="file" accept="image/*" capture="environment" data-phone-photo>
                </label>
                <div class="preview-slot" data-phone-upload-preview-slot hidden></div>
                <button type="button" class="secondary disabled" data-phone-send-photo disabled>Send loose photo</button>
                <div class="send-status" data-phone-send-status hidden></div>
            </div>
        </details>

        <a href="{{ $appHomeUrl }}" class="footer-link">Open app home</a>
    @endif
</main>

@if ($validToken && $settings->is_enabled)
    <div class="preview-fullscreen" data-phone-preview-modal hidden>
        <img src="" alt="Selected product photo preview" data-phone-preview-modal-image>
        <div class="preview-fullscreen-actions">
            <button type="button" class="danger" data-phone-preview-modal-delete>Delete photo</button>
            <button type="button" class="secondary" data-phone-preview-modal-close>Close preview</button>
        </div>
    </div>
@endif

@if ($validToken && $settings->is_enabled)
<script>
    (() => {
        const root = document.querySelector('[data-mobile-phone]');
        const heartbeatUrl = root?.dataset.heartbeatUrl;
        const uploadUrl = root?.dataset.uploadUrl;
        const jobsUrl = root?.dataset.jobsUrl;
        const maxUploadBytes = Number(root?.dataset.maxUploadBytes || 35 * 1024 * 1024);
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const cameraStatus = document.querySelector('[data-phone-camera]');
        const message = document.querySelector('[data-phone-message]');
        const liveButton = document.querySelector('[data-phone-live-test]');
        const stopButton = document.querySelector('[data-phone-stop-video]');
        const video = document.querySelector('[data-phone-video]');
        const warning = document.querySelector('[data-phone-live-warning]');
        const upload = document.querySelector('[data-phone-photo]');
        const previewSlot = document.querySelector('[data-phone-upload-preview-slot]');
        const sendButton = document.querySelector('[data-phone-send-photo]');
        const sendStatus = document.querySelector('[data-phone-send-status]');
        const jobList = document.querySelector('[data-phone-job-list]');
        const activeJobPanel = document.querySelector('[data-phone-active-job]');
        const activeJobTitle = document.querySelector('[data-phone-active-job-title]');
        const activeJobMeta = document.querySelector('[data-phone-active-job-meta]');
        const jobPhotoInput = document.querySelector('[data-phone-job-photo]');
        const jobPhotoAddInput = document.querySelector('[data-phone-job-photo-add]');
        const jobTakeWrap = document.querySelector('[data-phone-job-take-wrap]');
        const jobAddWrap = document.querySelector('[data-phone-job-add-wrap]');
        const jobPreviewSlot = document.querySelector('[data-phone-job-preview-slot]');
        const jobSendButton = document.querySelector('[data-phone-job-send]');
        const jobCancelButton = document.querySelector('[data-phone-job-cancel]');
        const jobStatus = document.querySelector('[data-phone-job-status]');
        const previewModal = document.querySelector('[data-phone-preview-modal]');
        const previewModalImage = document.querySelector('[data-phone-preview-modal-image]');
        const previewModalClose = document.querySelector('[data-phone-preview-modal-close]');
        const previewModalDelete = document.querySelector('[data-phone-preview-modal-delete]');
        let stream = null;
        let selectedFile = null;
        let selectedPreviewUrl = null;
        let selectedJob = null;
        let selectedJobFiles = [];
        let selectedJobPreviewUrls = [];
        let previewModalScope = null;
        let previewModalIndex = null;
        const preferredJobToken = new URLSearchParams(window.location.search).get('job');

        const showPreview = (slot, url, alt, scope = 'loose', index = 0) => {
            if (!slot) return;
            slot.innerHTML = '';
            const item = document.createElement('div');
            item.className = 'preview-item';

            const image = document.createElement('img');
            image.className = 'preview';
            image.src = url;
            image.alt = alt;
            image.dataset.phoneViewPhoto = scope;
            image.dataset.phonePhotoIndex = String(index);
            item.appendChild(image);

            const tools = document.createElement('div');
            tools.className = 'preview-tools';

            const view = document.createElement('button');
            view.type = 'button';
            view.className = 'preview-view';
            view.dataset.phoneViewPhoto = scope;
            view.dataset.phonePhotoIndex = String(index);
            view.textContent = 'View';
            tools.appendChild(view);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'preview-remove';
            remove.dataset.phoneDeletePhoto = scope;
            remove.dataset.phonePhotoIndex = String(index);
            remove.textContent = 'Delete';
            tools.appendChild(remove);

            item.appendChild(tools);
            slot.appendChild(item);
            slot.hidden = false;
        };

        const clearPreview = (slot) => {
            if (!slot) return;
            slot.innerHTML = '';
            slot.hidden = true;
            slot.classList.remove('is-grid');
        };

        const closePreviewModal = () => {
            if (!previewModal) return;
            previewModal.hidden = true;
            if (previewModalImage) previewModalImage.src = '';
            previewModalScope = null;
            previewModalIndex = null;
        };

        const openPreviewModal = (url, scope, index) => {
            if (!previewModal || !previewModalImage || !url) return;
            previewModalImage.src = url;
            previewModalScope = scope;
            previewModalIndex = index;
            previewModal.hidden = false;
        };

        const clearLoosePhoto = () => {
            if (selectedPreviewUrl) URL.revokeObjectURL(selectedPreviewUrl);
            selectedPreviewUrl = null;
            selectedFile = null;
            if (upload) upload.value = '';
            clearPreview(previewSlot);
            if (sendButton) {
                sendButton.disabled = true;
                sendButton.classList.add('disabled');
                sendButton.textContent = 'Send loose photo';
            }
            if (sendStatus) {
                sendStatus.hidden = true;
                sendStatus.textContent = '';
                sendStatus.classList.remove('is-error', 'is-good');
            }
        };

        const deleteJobPhoto = (index) => {
            if (Number.isNaN(index) || index < 0 || index >= selectedJobFiles.length) return;
            const [url] = selectedJobPreviewUrls.splice(index, 1);
            if (url) URL.revokeObjectURL(url);
            selectedJobFiles.splice(index, 1);
            renderJobPreviews();
            updateJobPhotoControls();
            if (selectedJobFiles.length === 0 && jobStatus) {
                jobStatus.hidden = true;
                jobStatus.textContent = '';
            }
        };

        const deletePreviewPhoto = (scope, index) => {
            if (scope === 'job') {
                deleteJobPhoto(index);
            } else {
                clearLoosePhoto();
            }
            closePreviewModal();
        };

        const handlePreviewClick = (event, scope) => {
            const deleteButton = event.target.closest('[data-phone-delete-photo]');
            if (deleteButton) {
                const index = Number.parseInt(deleteButton.dataset.phonePhotoIndex || '0', 10);
                deletePreviewPhoto(deleteButton.dataset.phoneDeletePhoto || scope, Number.isNaN(index) ? 0 : index);
                return;
            }

            const viewTarget = event.target.closest('[data-phone-view-photo]');
            if (!viewTarget) return;
            const index = Number.parseInt(viewTarget.dataset.phonePhotoIndex || '0', 10);
            const targetScope = viewTarget.dataset.phoneViewPhoto || scope;
            const url = targetScope === 'job' ? selectedJobPreviewUrls[index] : selectedPreviewUrl;
            openPreviewModal(url, targetScope, Number.isNaN(index) ? 0 : index);
        };

        const jobAllowsMultiple = () => Boolean(selectedJob?.allows_multiple_photos);

        const resetSelectedJobFiles = () => {
            selectedJobPreviewUrls.forEach((url) => URL.revokeObjectURL(url));
            selectedJobPreviewUrls = [];
            selectedJobFiles = [];
            if (jobPhotoInput) jobPhotoInput.value = '';
            if (jobPhotoAddInput) jobPhotoAddInput.value = '';
            clearPreview(jobPreviewSlot);
            if (jobSendButton) {
                jobSendButton.disabled = true;
                jobSendButton.classList.add('disabled');
                jobSendButton.textContent = 'Send product';
            }
            if (jobTakeWrap) jobTakeWrap.hidden = false;
            if (jobAddWrap) jobAddWrap.hidden = true;
        };

        const updateJobPhotoControls = () => {
            const count = selectedJobFiles.length;
            if (jobTakeWrap) jobTakeWrap.hidden = jobAllowsMultiple() && count > 0;
            if (jobAddWrap) jobAddWrap.hidden = !jobAllowsMultiple() || count === 0;
            if (jobSendButton) {
                jobSendButton.disabled = count === 0;
                jobSendButton.classList.toggle('disabled', count === 0);
                jobSendButton.textContent = count > 1 ? `Send ${count} photos` : 'Send product';
            }
            if (jobStatus && count > 0) {
                jobStatus.hidden = false;
                jobStatus.textContent = count > 1 ? `${count} photos ready.` : 'Ready.';
                jobStatus.classList.remove('is-error', 'is-good');
            }
        };

        const renderJobPreviews = () => {
            if (!jobPreviewSlot) return;
            jobPreviewSlot.innerHTML = '';
            jobPreviewSlot.hidden = selectedJobPreviewUrls.length === 0;
            jobPreviewSlot.classList.toggle('is-grid', selectedJobPreviewUrls.length > 1);

            selectedJobPreviewUrls.forEach((url, index) => {
                const item = document.createElement('div');
                item.className = 'preview-item';

                const image = document.createElement('img');
                image.className = 'preview';
                image.src = url;
                image.alt = `Selected product photo ${index + 1}`;
                image.dataset.phoneViewPhoto = 'job';
                image.dataset.phonePhotoIndex = String(index);
                item.appendChild(image);

                const tools = document.createElement('div');
                tools.className = 'preview-tools';

                const view = document.createElement('button');
                view.type = 'button';
                view.className = 'preview-view';
                view.dataset.phoneViewPhoto = 'job';
                view.dataset.phonePhotoIndex = String(index);
                view.textContent = 'View';
                tools.appendChild(view);

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'preview-remove';
                remove.dataset.phoneDeletePhoto = 'job';
                remove.dataset.phonePhotoIndex = String(index);
                remove.textContent = 'Delete';
                tools.appendChild(remove);

                item.appendChild(tools);

                jobPreviewSlot.appendChild(item);
            });
        };

        const formatBytes = (bytes) => {
            if (!Number.isFinite(bytes)) return '0 MB';
            return `${(bytes / 1024 / 1024).toFixed(bytes >= 10 * 1024 * 1024 ? 1 : 2)} MB`;
        };

        const report = async (status = null, error = null) => {
            if (!heartbeatUrl) return;
            await fetch(heartbeatUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ camera_status: status, camera_error: error }),
            }).catch(() => {});
        };

        const setCamera = (status, text, isGood = false) => {
            if (!cameraStatus) return;
            cameraStatus.textContent = text;
            cameraStatus.classList.toggle('good', isGood);
            cameraStatus.classList.toggle('bad', !isGood && status !== 'untested');
        };

        report();
        setInterval(() => report(), 5000);

        liveButton?.addEventListener('click', async () => {
            warning.hidden = true;

            if (!navigator.mediaDevices?.getUserMedia) {
                const msg = 'Live preview unavailable.';
                warning.textContent = msg;
                warning.hidden = false;
                setCamera('unsupported', 'Unsupported', false);
                if (message) message.textContent = msg;
                await report('unsupported', msg);
                return;
            }

            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
                video.srcObject = stream;
                video.hidden = false;
                stopButton.hidden = false;
                setCamera('granted', 'Live camera works', true);
                if (message) message.textContent = 'Live camera preview is running.';
                await report('granted');
            } catch (error) {
                const msg = error?.message || 'Camera permission was denied or blocked.';
                warning.textContent = msg;
                warning.hidden = false;
                setCamera('denied', 'Blocked', false);
                if (message) message.textContent = msg;
                await report('denied', msg);
            }
        });

        stopButton?.addEventListener('click', () => {
            stream?.getTracks().forEach(track => track.stop());
            stream = null;
            video.srcObject = null;
            video.hidden = true;
            stopButton.hidden = true;
        });

        upload?.addEventListener('change', async () => {
            const file = upload.files?.[0];
            if (!file) return;

            selectedFile = file;
            if (selectedPreviewUrl) URL.revokeObjectURL(selectedPreviewUrl);
            selectedPreviewUrl = URL.createObjectURL(file);
            showPreview(previewSlot, selectedPreviewUrl, 'Selected product photo preview');
            sendButton.disabled = false;
            sendButton.classList.remove('disabled');
            sendStatus.hidden = false;
            sendStatus.classList.remove('is-error', 'is-good');
            sendStatus.textContent = file.size > maxUploadBytes
                ? `Optimizing ${formatBytes(file.size)}.`
                : 'Ready.';
            setCamera('upload-ready', 'Photo ready', true);
            if (message) message.textContent = 'Photo selected. Press Send photo to desktop.';
            await report('upload-tested');
        });

        previewSlot?.addEventListener('click', (event) => handlePreviewClick(event, 'loose'));

        previewModalClose?.addEventListener('click', closePreviewModal);
        previewModalDelete?.addEventListener('click', () => {
            if (!previewModalScope) return;
            deletePreviewPhoto(previewModalScope, previewModalIndex ?? 0);
        });
        previewModal?.addEventListener('click', (event) => {
            if (event.target === previewModal) closePreviewModal();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && previewModal && !previewModal.hidden) {
                closePreviewModal();
            }
        });

        const optimizePhotoIfNeeded = async (file, statusElement = sendStatus) => {
            if (file.size <= maxUploadBytes) return file;

            if (statusElement) {
                statusElement.textContent = `Optimizing ${formatBytes(file.size)} photo before sending...`;
            }

            const imageUrl = URL.createObjectURL(file);
            const image = new Image();
            image.decoding = 'async';

            try {
                await new Promise((resolve, reject) => {
                    image.onload = resolve;
                    image.onerror = () => reject(new Error('This photo could not be optimized on the phone.'));
                    image.src = imageUrl;
                });

                const maxDimension = 3000;
                const scale = Math.min(1, maxDimension / Math.max(image.naturalWidth, image.naturalHeight));
                const width = Math.max(1, Math.round(image.naturalWidth * scale));
                const height = Math.max(1, Math.round(image.naturalHeight * scale));
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const context = canvas.getContext('2d', { alpha: false });
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, width, height);
                context.drawImage(image, 0, 0, width, height);

                const blobFromCanvas = (quality) => new Promise((resolve) => {
                    canvas.toBlob(resolve, 'image/jpeg', quality);
                });

                let blob = null;
                for (const quality of [0.92, 0.88, 0.84, 0.8, 0.76, 0.72]) {
                    blob = await blobFromCanvas(quality);
                    if (blob && blob.size <= maxUploadBytes) break;
                }

                if (!blob) {
                    throw new Error('The phone could not prepare this photo for upload.');
                }

                const filename = (file.name || 'mobile-photo').replace(/\.[^.]+$/, '') + '.jpg';
                const optimized = new File([blob], filename, { type: 'image/jpeg', lastModified: Date.now() });
                if (statusElement) {
                    statusElement.textContent = `Optimized from ${formatBytes(file.size)} to ${formatBytes(optimized.size)}. Sending now...`;
                }

                return optimized;
            } finally {
                URL.revokeObjectURL(imageUrl);
            }
        };

        const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        })[character]);

        const jobMeta = (job) => {
            const parts = [
                job.image_role ? `Purpose: ${job.image_role}` : null,
                job.usage_context ? `Use: ${job.usage_context}` : null,
                job.is_primary ? 'Primary image' : null,
            ].filter(Boolean);

            return parts.join(' / ');
        };

        const setSelectedJob = (job) => {
            selectedJob = job;
            resetSelectedJobFiles();
            if (jobStatus) {
                jobStatus.hidden = true;
                jobStatus.textContent = '';
                jobStatus.classList.remove('is-error', 'is-good');
            }
            if (activeJobTitle) activeJobTitle.textContent = job.target_label || 'Product image';
            if (activeJobMeta) activeJobMeta.textContent = jobMeta(job);
            if (activeJobPanel) activeJobPanel.hidden = false;
        };

        const renderJobs = (jobs) => {
            if (!jobList) return;
            if (!jobs.length) {
                jobList.hidden = false;
                jobList.innerHTML = `
                    <div class="station-idle">
                        <div>
                            <strong>Waiting for PC request</strong>
                        </div>
                    </div>
                `;
                if (activeJobPanel) activeJobPanel.hidden = true;
                if (selectedJob && !jobs.some(job => job.token === selectedJob.token)) {
                    selectedJob = null;
                }
                return;
            }

            const preferred = preferredJobToken ? jobs.find(job => job.token === preferredJobToken) : null;
            if (!selectedJob) {
                setSelectedJob(preferred || jobs[0]);
            } else if (!jobs.some(job => job.token === selectedJob.token)) {
                selectedJob = null;
                setSelectedJob(preferred || jobs[0]);
            }

            jobList.hidden = jobs.length <= 1;
            jobList.innerHTML = jobs.length > 1
                ? jobs.map(job => `
                    <button type="button" class="job-card" data-phone-select-job="${escapeHtml(job.token)}">
                        <strong>${escapeHtml(job.target_label || 'Product image')}</strong>
                        <span>${escapeHtml(jobMeta(job) || 'Phone camera upload')}</span>
                    </button>
                `).join('')
                : '';
        };

        const refreshJobs = async () => {
            if (!jobsUrl) return;
            const response = await fetch(jobsUrl, { headers: { Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            renderJobs(Array.isArray(data.jobs) ? data.jobs : []);
        };

        jobList?.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-phone-select-job]');
            if (!button || !jobsUrl) return;
            const response = await fetch(jobsUrl, { headers: { Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            const job = (Array.isArray(data.jobs) ? data.jobs : []).find(item => item.token === button.dataset.phoneSelectJob);
            if (job) setSelectedJob(job);
        });

        const handleJobPhotoFile = (file) => {
            if (!file || !selectedJob) return;

            if (jobAllowsMultiple()) {
                selectedJobFiles.push(file);
                selectedJobPreviewUrls.push(URL.createObjectURL(file));
            } else {
                resetSelectedJobFiles();
                selectedJobFiles = [file];
                selectedJobPreviewUrls = [URL.createObjectURL(file)];
            }

            if (jobPhotoInput) jobPhotoInput.value = '';
            if (jobPhotoAddInput) jobPhotoAddInput.value = '';
            renderJobPreviews();
            updateJobPhotoControls();
        };

        jobPhotoInput?.addEventListener('change', () => {
            handleJobPhotoFile(jobPhotoInput.files?.[0]);
        });

        jobPhotoAddInput?.addEventListener('change', () => {
            handleJobPhotoFile(jobPhotoAddInput.files?.[0]);
        });

        jobPreviewSlot?.addEventListener('click', (event) => handlePreviewClick(event, 'job'));

        jobSendButton?.addEventListener('click', async () => {
            if (!selectedJob || selectedJobFiles.length === 0 || !selectedJob.upload_url) return;

            jobSendButton.disabled = true;
            jobSendButton.textContent = 'Sending...';
            jobStatus.hidden = false;
            jobStatus.textContent = selectedJobFiles.length > 1 ? `Uploading ${selectedJobFiles.length} photos...` : 'Uploading...';
            jobStatus.classList.remove('is-error', 'is-good');

            try {
                const filesToSend = [];
                for (const file of selectedJobFiles) {
                    filesToSend.push(await optimizePhotoIfNeeded(file, jobStatus));
                }
                const formData = new FormData();
                filesToSend.forEach((file) => formData.append('photos[]', file, file.name));

                const response = await fetch(selectedJob.upload_url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });
                const data = await response.json().catch(() => ({}));

                if (!response.ok || !data.ok) {
                    throw new Error(data.message || 'Unable to save photo to product.');
                }

                jobStatus.textContent = 'Saved.';
                jobStatus.classList.add('is-good');
                setCamera('uploaded', 'Sent', true);
                if (message) message.textContent = 'Photo was saved to the selected product.';
                await report('uploaded');
                selectedJob = null;
                resetSelectedJobFiles();
                if (activeJobPanel) activeJobPanel.hidden = true;
                await refreshJobs();
            } catch (error) {
                const msg = error?.message || 'Unable to save phone photo.';
                jobStatus.textContent = msg;
                jobStatus.classList.add('is-error');
                jobSendButton.disabled = false;
                jobSendButton.classList.remove('disabled');
                updateJobPhotoControls();
                await report('error', msg);
            }
        });

        jobCancelButton?.addEventListener('click', async () => {
            if (!selectedJob?.cancel_url) return;

            jobCancelButton.disabled = true;
            if (jobStatus) {
                jobStatus.hidden = false;
                jobStatus.textContent = 'Closing...';
                jobStatus.classList.remove('is-error', 'is-good');
            }

            try {
                const response = await fetch(selectedJob.cancel_url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await response.json().catch(() => ({}));

                if (!response.ok || !data.ok) {
                    throw new Error(data.message || 'Unable to close request.');
                }

                selectedJob = null;
                resetSelectedJobFiles();
                if (activeJobPanel) activeJobPanel.hidden = true;
                await refreshJobs();
            } catch (error) {
                if (jobStatus) {
                    jobStatus.textContent = error?.message || 'Unable to close request.';
                    jobStatus.classList.add('is-error');
                }
            } finally {
                jobCancelButton.disabled = false;
            }
        });

        refreshJobs().catch(() => {});
        setInterval(() => refreshJobs().catch(() => {}), 4000);

        sendButton?.addEventListener('click', async () => {
            if (!selectedFile || !uploadUrl) return;

            sendButton.disabled = true;
            sendButton.textContent = 'Sending...';
            sendStatus.hidden = false;
            sendStatus.textContent = 'Uploading photo to desktop...';
            sendStatus.classList.remove('is-error', 'is-good');

            try {
                const fileToSend = await optimizePhotoIfNeeded(selectedFile, sendStatus);
                const formData = new FormData();
                formData.append('photo', fileToSend);

                const response = await fetch(uploadUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });
                const data = await response.json().catch(() => ({}));

                if (!response.ok || !data.ok) {
                    throw new Error(data.message || 'Photo upload failed.');
                }

                sendStatus.textContent = 'Sent to desktop. Check the PC Mobile Capture page.';
                sendStatus.classList.add('is-good');
                setCamera('uploaded', 'Sent', true);
                if (message) message.textContent = 'Photo was sent to the desktop.';
                await report('uploaded');
                upload.value = '';
                selectedFile = null;
            } catch (error) {
                const msg = error?.message || 'Photo upload failed.';
                sendStatus.textContent = msg;
                sendStatus.classList.add('is-error');
                setCamera('error', 'Upload failed', false);
                if (message) message.textContent = msg;
                sendButton.disabled = false;
                sendButton.classList.remove('disabled');
                await report('error', msg);
            } finally {
                if (!selectedFile) {
                    sendButton.textContent = 'Send another photo to desktop';
                    sendButton.disabled = true;
                    sendButton.classList.add('disabled');
                } else {
                    sendButton.textContent = 'Send photo to desktop';
                }
            }
        });
    })();
</script>
@endif
</body>
</html>
